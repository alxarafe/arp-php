<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Alxarafe\Arp\Cds\Physical\{MariaDbObservationProvider,PhysicalCatalog};
use Alxarafe\Arp\Dictionary\{CatalogBuilder,Source};
use Alxarafe\Arp\Dictionary\Cds\CrudHost;
use Alxarafe\Arp\Dictionary\Cds\Seed\{CsvSeedLoader,SeedFailure};
use Alxarafe\Arp\Dictionary\Internal\Parser;
use Alxarafe\Arp\MariaDb\{MariaDbRecordReader,MariaDbRecordWriter};

const SEED_OWNER = 'arp.seed.demo/1';

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(2);
}

$options = getopt('', ['database:', 'csv:', 'host::', 'port::', 'user::', 'help']);
if ($options === false) {
    fail('Invalid command line');
}
if (isset($options['help'])) {
    echo "Usage: php demo/seed/prepare.php --database=arp_seed_demo "
        . "[--csv=demo/seed/demo.items.csv] [--host=127.0.0.1] [--port=3306] [--user=root]\n";
    exit(0);
}
$database = is_string($options['database'] ?? null)
    ? $options['database']
    : (getenv('ARP_SEED_DATABASE') ?: '');
$csvPath = is_string($options['csv'] ?? null) ? $options['csv'] : __DIR__ . '/demo.items.csv';
$host = is_string($options['host'] ?? null) ? $options['host'] : (getenv('ARP_DB_HOST') ?: '127.0.0.1');
$port = is_string($options['port'] ?? null) ? $options['port'] : (getenv('ARP_DB_PORT') ?: '3306');
$user = is_string($options['user'] ?? null) ? $options['user'] : (getenv('ARP_DB_USER') ?: 'root');
$password = getenv('ARP_DB_PASSWORD') ?: '';
if ($password === '') {
    fail('ARP_DB_PASSWORD is required');
}
if (preg_match('/\Aarp_seed_[A-Za-z0-9_]{1,54}\z/D', $database) !== 1) {
    fail('The database must be explicitly identified with the arp_seed_ prefix');
}
if (!is_file($csvPath) || !is_readable($csvPath)) {
    fail('CSV file is not readable');
}
$csv = file_get_contents($csvPath);
if ($csv === false) {
    fail('CSV file is not readable');
}
$contract = getenv('ARP_WRITE_CONTRACT_PATH') ?: getenv('ARP_CONTRACT_PATH') ?: '';
if ($contract === '' || !is_dir($contract . '/examples/demo')) {
    fail('ARP_WRITE_CONTRACT_PATH or ARP_CONTRACT_PATH is required');
}
$root = dirname(__DIR__, 2);
$pinPath = $root . '/write-contract.ref';
if (file_exists($contract . '/.git') && is_file($pinPath)) {
    $pin = trim((string)file_get_contents($pinPath));
    $actual = trim((string)shell_exec('git -C ' . escapeshellarg($contract) . ' rev-parse HEAD 2>/dev/null'));
    if ($actual !== '' && $actual !== $pin) {
        fail('The contract checkout does not match write-contract.ref');
    }
}

$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_STRINGIFY_FETCHES => true,
    PDO::ATTR_PERSISTENT => false
];
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, $pdoOptions);
$connect = static fn(): PDO => new PDO(
    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
    $user,
    $password,
    $pdoOptions
);
$created = false;
$result = null;
try {
    $statement = $server->prepare(
        'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
    );
    $statement->execute([$database]);
    $exists = $statement->fetchColumn() !== false;
    if ($exists) {
        $pdo = $connect();
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
        );
        $statement->execute([$database, '__arp_seed_owner']);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new SeedFailure('SEED_DATABASE_NOT_OWNED');
        }
        $owner = $pdo->query('SELECT token FROM __arp_seed_owner LIMIT 1')->fetchColumn();
        if ($owner !== SEED_OWNER) {
            throw new SeedFailure('SEED_DATABASE_NOT_OWNED');
        }
        $statement->execute([$database, 'items']);
        $hasItems = (int)$statement->fetchColumn() === 1;
        $existing = $hasItems ? (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn() : 0;
        if ($existing > 0) {
            $parsed = \Alxarafe\Arp\Dictionary\Cds\Seed\CsvTable::parse($csv);
            echo json_encode([
                'database' => $database,
                'status' => 'already-initialized',
                'processed' => count($parsed->rows),
                'inserted' => 0,
                'rejected' => 0,
                'existing' => $existing
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
            exit(0);
        }
        throw new SeedFailure('SEED_DATABASE_EMPTY');
    }

    $server->exec(
        'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $created = true;
    $pdo = $connect();
    $pdo->exec('CREATE TABLE __arp_seed_owner(token varchar(64) NOT NULL)');
    $statement = $pdo->prepare('INSERT INTO __arp_seed_owner(token) VALUES(?)');
    $statement->execute([SEED_OWNER]);
    $schema = file_get_contents($contract . '/examples/demo/sql/mariadb.sql');
    if ($schema === false) {
        throw new SeedFailure('SEED_SCHEMA_UNAVAILABLE');
    }
    $pdo->exec($schema);

    $dictionary = (new CatalogBuilder())->buildCatalog([new Source(
        'demo',
        'dictionary',
        'yaml',
        file_get_contents($contract . '/examples/demo/cds/dictionary.yaml')
    )])->catalog;
    if ($dictionary === null) {
        throw new SeedFailure('SEED_DICTIONARY_INVALID');
    }
    $definitions = (object)[
        'logical' => (object)['items' => Parser::parse(
            file_get_contents($contract . '/examples/demo/cds/logical.yaml'),
            'yaml'
        )],
        'projections' => (object)[
            'public' => Parser::parse(file_get_contents($contract . '/examples/demo/cds/public.yaml'), 'yaml'),
            'summary' => Parser::parse(file_get_contents($contract . '/examples/demo/cds/summary.yaml'), 'yaml')
        ]
    ];
    $catalog = new PhysicalCatalog($dictionary, $definitions, ['items' => new MariaDbObservationProvider(
        $connect,
        'primary',
        'demo.items',
        $database,
        'items',
        2
    )], true);
    $snapshot = $catalog->resolve();
    if (!$snapshot->valid) {
        throw new SeedFailure('SEED_CATALOG_INVALID');
    }
    $targets = ['demo.items' => [
        'projection' => 'demo.items.public',
        'fields' => ['id','code','rating'],
        'operations' => ['LIST','GET','CREATE','UPDATE','DELETE'],
        'defaultLimit' => 10,
        'maxLimit' => 100
    ]];
    $authorizer = static fn(): array => [
        'allowed' => true,
        'fields' => ['id','code','rating'],
        'writableFields' => ['id','code','rating'],
        'equalities' => new stdClass()
    ];
    $host = new CrudHost(
        $catalog,
        new MariaDbRecordReader($connect, 2),
        new MariaDbRecordWriter($connect, 2),
        $targets,
        $authorizer
    );
    $context = ['identity' => 'arp-seed-demo','policyRevision' => 'seed-001'];
    $loader = new CsvSeedLoader($host, $catalog, 'demo.items', 'demo.items.public', $context);
    $result = $loader->load($csv);
    if ($result['errors'] !== []) {
        throw new SeedFailure('SEED_VALIDATION_FAILED', null, null, ['result' => $result]);
    }
    $result += ['database' => $database,'status' => 'initialized'];
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
} catch (SeedFailure $error) {
    if ($created) {
        try {
            $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
        } catch (Throwable) {
            // The original failure is the useful diagnosis; cleanup is best effort.
        }
    }
    $diagnostic = $error->diagnostic();
    if (isset($result) && is_array($result)) {
        $diagnostic += $result;
    }
    fwrite(STDERR, json_encode([
        'database' => $database,
        'status' => 'failed',
        'error' => $diagnostic
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
} catch (Throwable $error) {
    if ($created) {
        try {
            $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
        } catch (Throwable) {
            // The original failure is the useful diagnosis; cleanup is best effort.
        }
    }
    $message = str_replace($password, '[redacted]', $error->getMessage());
    fwrite(STDERR, json_encode([
        'database' => $database,
        'status' => 'failed',
        'error' => ['code' => 'SEED_FAILED','message' => $message]
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}
