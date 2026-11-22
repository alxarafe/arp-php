<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Alxarafe\Arp\Cds\Physical\{MariaDbObservationProvider,PhysicalCatalog};
use Alxarafe\Arp\Dictionary\{CatalogBuilder,Source};
use Alxarafe\Arp\Dictionary\Cds\CrudHost;
use Alxarafe\Arp\Dictionary\Cds\Seed\{CsvSeedLoader,SeedFailure};
use Alxarafe\Arp\Dictionary\Internal\Parser;
use Alxarafe\Arp\MariaDb\{MariaDbRecordReader,MariaDbRecordWriter};

function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
}

function same(mixed $actual, mixed $expected, string $label): void
{
    check($actual === $expected, $label . ': ' . json_encode([$actual,$expected]));
}

function lastJson(string $output): array
{
    $lines = array_values(array_filter(explode("\n", trim($output)), static fn($line) => $line !== ''));
    check($lines !== [], 'prepare produced no output');
    $decoded = json_decode((string)end($lines), true, 512, JSON_THROW_ON_ERROR);
    check(is_array($decoded), 'prepare output is not a JSON object');
    return $decoded;
}

$contract = getenv('ARP_WRITE_CONTRACT_PATH') ?: throw new RuntimeException('Pinned write contract required');
$password = getenv('ARP_DB_PASSWORD') ?: throw new RuntimeException('Owned database required');
$token = getenv('ARP_CRUD_RUN_TOKEN') ?: throw new RuntimeException('Owned run token required');
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_STRINGIFY_FETCHES => true,
    PDO::ATTR_PERSISTENT => false
];
$connect = static fn() => new PDO(
    'mysql:host=db;dbname=arpcds;charset=utf8mb4',
    'root',
    $password,
    $options
);
$admin = $connect();
check(
    hash_equals($token, (string)$admin->query('SELECT token FROM __arp_crud_test_owner')->fetchColumn()),
    'owned database marker before seed DDL'
);
$admin->exec('DROP TABLE IF EXISTS seed_items');
$admin->exec('CREATE TABLE seed_items(id BIGINT NOT NULL PRIMARY KEY,code VARCHAR(12) NOT NULL,'
    . 'rating TINYINT SIGNED NOT NULL) ENGINE=InnoDB');
$dictionary = (new CatalogBuilder())->buildCatalog([new Source(
    'demo',
    'dictionary',
    'yaml',
    file_get_contents($contract . '/examples/demo/cds/dictionary.yaml')
)])->catalog;
$definitions = (object)[
    'logical' => (object)['items' => Parser::parse(
        file_get_contents($contract . '/examples/demo/cds/logical.yaml'),
        'yaml'
    )],
    'projections' => (object)[
        'public' => Parser::parse(file_get_contents($contract . '/examples/demo/cds/public.yaml'), 'yaml')
    ]
];
$catalog = new PhysicalCatalog($dictionary, $definitions, ['items' => new MariaDbObservationProvider(
    $connect,
    'primary',
    'demo.items',
    'arpcds',
    'seed_items',
    2
)], true);
check($catalog->resolve()->valid, 'seed catalog resolves');
$fields = ['id','code','rating'];
$host = new CrudHost(
    $catalog,
    new MariaDbRecordReader($connect, 2),
    new MariaDbRecordWriter($connect, 2),
    ['demo.items' => ['projection' => 'demo.items.public','fields' => $fields,
        'operations' => ['LIST','GET','CREATE','UPDATE','DELETE'],'defaultLimit' => 10,'maxLimit' => 100]],
    static fn(): array => ['allowed' => true,'fields' => $fields,'writableFields' => $fields,
        'equalities' => new stdClass()]
);
$context = ['identity' => 'seed-mariadb','policyRevision' => 'p1'];
$loader = new CsvSeedLoader($host, $catalog, 'demo.items', 'demo.items.public', $context);
$reset = static function () use ($admin): void {
    $admin->exec('DELETE FROM seed_items');
};
$rows = static fn(): array => $admin->query(
    'SELECT CAST(id AS CHAR) id,code,CAST(rating AS CHAR) rating FROM seed_items ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);

$result = $loader->load("id;code;rating\n1;A-001;5\n2;\"A;002\";7\n3;ÁREA-003;9\n");
same($result, ['processed' => 3,'inserted' => 3,'rejected' => 0,'errors' => []], 'real MariaDB load');
same($rows(), [
    ['id' => '1','code' => 'A-001','rating' => '5'],
    ['id' => '2','code' => 'A;002','rating' => '7'],
    ['id' => '3','code' => 'ÁREA-003','rating' => '9']
], 'real MariaDB persisted values');
echo "PASS real MariaDB default separator, quoted field and UTF-8 persistence\n";

$reset();
$comma = new CsvSeedLoader($host, $catalog, 'demo.items', 'demo.items.public', $context, ',');
same($comma->load("id,code,rating\n1,\"A,B\",5\n")['inserted'], 1, 'real alternative separator');
same($rows()[0]['code'], 'A,B', 'real alternative separator value');
echo "PASS real MariaDB alternative separator\n";

$reset();
$special = "id;code;rating\n1;\"D \"\"X\"\" 04\";5\n2;\"L1\nL2\";6\n";
same($loader->load($special)['inserted'], 2, 'real quoted and multiline CSV');
same($rows(), [
    ['id' => '1','code' => 'D "X" 04','rating' => '5'],
    ['id' => '2','code' => "L1\nL2",'rating' => '6']
], 'real escaped quotes and multiline persistence');
echo "PASS real MariaDB escaped quotes and embedded newline\n";

$reset();
$invalid = $loader->load("id;code;rating\n1;ABCDEFGHIJKLM;5\n2;A-002;11\n3;;6\n");
same($invalid['inserted'], 0, 'real constraints reject whole load');
same(count($invalid['errors']), 3, 'real constraint diagnostics');
same($rows(), [], 'real invalid CSV persists nothing');
$duplicate = $loader->load("id;code;rating\n1;A-001;5\n1;A-002;6\n");
same($duplicate['errors'][0]['code'], 'SEED_DUPLICATE_KEY', 'real duplicate key diagnostic');
same($rows(), [], 'real duplicate key persists nothing');
echo "PASS real MariaDB dictionary constraints and duplicate CSV keys without writes\n";

$reset();
$exact = $loader->load(
    "id;code;rating\n9223372036854775807;MAX-64;5\n-9223372036854775808;MIN-64;6\n"
);
same($exact['inserted'], 2, 'real exact 64-bit load');
same($rows(), [
    ['id' => '-9223372036854775808','code' => 'MIN-64','rating' => '6'],
    ['id' => '9223372036854775807','code' => 'MAX-64','rating' => '5']
], 'real exact signed 64-bit persistence');
$overflow = $loader->load("id;code;rating\n9223372036854775808;OVER;5\n");
same($overflow['errors'][0]['code'], 'SEED_INVALID_VALUE', 'real 64-bit overflow rejected');
echo "PASS real MariaDB exact signed 64-bit values\n";

$reset();
$admin->exec("INSERT INTO seed_items(id,code,rating) VALUES(1,'KEEP',5)");
try {
    $loader->load("id;code;rating\n2;NEW;6\n1;DUP;7\n");
    throw new RuntimeException('Database duplicate key was accepted');
} catch (SeedFailure $error) {
    same($error->errorCode, 'SEED_INSERT_REJECTED', 'database duplicate rejection');
}
same($admin->query('SELECT COUNT(*) FROM seed_items WHERE id=1')->fetchColumn(), '1', 'existing row preserved');
echo "PASS real MariaDB database duplicate rejection\n";

$root = dirname(__DIR__, 2);
$runId = substr(hash('sha256', $token . microtime(true)), 0, 16);
$demoDatabase = 'arp_seed_test_' . $runId;
$foreignDatabase = 'arp_seed_foreign_' . $runId;
$badCsv = sys_get_temp_dir() . '/arp-seed-bad-' . $runId . '.csv';
file_put_contents($badCsv, "id;code;rating\n1;BAD;11\n");
$runPrepare = static function (
    string $database,
    ?string $csv = null
) use (
    $root,
    $password
): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/demo/seed/prepare.php')
        . ' --database=' . escapeshellarg($database) . ' --host=db --port=3306 --user=root';
    if ($csv !== null) {
        $command .= ' --csv=' . escapeshellarg($csv);
    }
    $output = [];
    $status = 0;
    exec($command . ' 2>&1', $output, $status);
    return ['status' => $status,'output' => implode("\n", $output)];
};
$exists = static function (string $database) use ($admin): bool {
    $statement = $admin->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $statement->execute([$database]);
    return (int)$statement->fetchColumn() === 1;
};
$drop = static function (string $database) use ($admin): void {
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
};
try {
    $first = $runPrepare($demoDatabase);
    same($first['status'], 0, 'demo preparation first run');
    $firstJson = lastJson($first['output']);
    same($firstJson['status'], 'initialized', 'demo preparation status');
    same($firstJson['processed'], 30, 'demo preparation processed');
    same($firstJson['inserted'], 30, 'demo preparation inserted');
    same($firstJson['rejected'], 0, 'demo preparation rejected');
    $demo = new PDO('mysql:host=db;dbname=' . $demoDatabase . ';charset=utf8mb4', 'root', $password, $options);
    same($demo->query('SELECT COUNT(*) FROM items')->fetchColumn(), '30', 'demo rows persisted');
    $demo->exec("INSERT INTO items(id,code,rating) VALUES(31,'KEEP-31',5)");

    $second = $runPrepare($demoDatabase);
    same($second['status'], 0, 'demo preparation second run');
    $secondJson = lastJson($second['output']);
    same($secondJson['status'], 'already-initialized', 'second run is non-destructive');
    same($secondJson['inserted'], 0, 'second run inserts nothing');
    same($secondJson['existing'], 31, 'second run observes existing rows');
    same($demo->query('SELECT COUNT(*) FROM items')->fetchColumn(), '31', 'second run preserves rows');
    echo "PASS demo preparation repeatability, no duplicate inserts and no implicit delete\n";

    $admin->exec('CREATE DATABASE `' . $foreignDatabase . '` CHARACTER SET utf8mb4');
    $foreign = new PDO(
        'mysql:host=db;dbname=' . $foreignDatabase . ';charset=utf8mb4',
        'root',
        $password,
        $options
    );
    $foreign->exec('CREATE TABLE items(id INT NOT NULL PRIMARY KEY,code VARCHAR(12) NOT NULL,'
        . 'rating TINYINT SIGNED NOT NULL)');
    $foreign->exec("INSERT INTO items(id,code,rating) VALUES(1,'FOREIGN',5)");
    $refused = $runPrepare($foreignDatabase);
    same($refused['status'], 1, 'foreign database refused');
    same(lastJson($refused['output'])['error']['code'], 'SEED_DATABASE_NOT_OWNED', 'foreign database diagnostic');
    same(
        $foreign->query('SELECT COUNT(*) FROM items')->fetchColumn(),
        '1',
        'foreign database preserved'
    );
    echo "PASS demo preparation refuses and preserves a foreign database\n";

    $failed = $runPrepare('arp_seed_failed_' . $runId, $badCsv);
    same($failed['status'], 1, 'invalid demo preparation fails');
    same($exists('arp_seed_failed_' . $runId), false, 'failed new environment is discarded');
    echo "PASS failed demo preparation discards only its new environment\n";
} finally {
    $drop($demoDatabase);
    $drop($foreignDatabase);
    $drop('arp_seed_failed_' . $runId);
    @unlink($badCsv);
    $admin->exec('DROP TABLE IF EXISTS seed_items');
}
