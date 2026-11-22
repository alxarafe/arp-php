<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/Contract.php';
require __DIR__ . '/Support/DeterministicProvider.php';
require __DIR__ . '/Support/CoreSuite.php';

use Alxarafe\Arp\Dictionary\Cds\CrudHost;
use Alxarafe\Arp\Dictionary\Cds\Seed\{CsvSeedLoader,SeedFailure};
use Alxarafe\Arp\Dictionary\Internal\Projection;
use Arp\Tests\{Contract,CoreSuite,DeterministicProvider};

$contract = new Contract();
$suite = new CoreSuite($contract);
$dictionary = $contract->load('examples/demo/cds/dictionary.yaml');
$bundle = (object)['physical' => (object)['demo' => $contract->load('fixtures/cds/v1/observations/mariadb.yaml')],
    'logical' => (object)['demo' => $contract->load('examples/demo/cds/logical.yaml')],
    'projections' => (object)['demo' => $contract->load('examples/demo/cds/public.yaml')]];
$checks = 0;
function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
}
function same(mixed $actual, mixed $expected, string $label): void
{
    check(Projection::encode($actual) === Projection::encode($expected), $label . ': '
        . Projection::encode(['actual' => $actual,'expected' => $expected]));
}
function make(object $bundle, object $dictionary, ?DeterministicProvider $provider = null): array
{
    global $suite;
    $catalog = $suite->catalog($bundle, $dictionary, true);
    $provider ??= new DeterministicProvider();
    $fields = ['id','code','rating'];
    $host = new CrudHost($catalog, $provider, $provider, ['demo.items' => [
        'projection' => 'demo.items.public','fields' => $fields,
        'operations' => ['LIST','GET','CREATE','UPDATE','DELETE'],
        'defaultLimit' => 10,'maxLimit' => 100
    ]], static fn(): array => ['allowed' => true,'fields' => $fields,'writableFields' => $fields,
        'equalities' => new stdClass()]);
    $context = ['identity' => 'seed-test','policyRevision' => 'p1'];
    return [$catalog,$provider,$host,$context];
}
function expectSeed(callable $run, string $code, ?int $row = null, ?string $column = null): void
{
    try {
        $run();
        throw new RuntimeException('Expected ' . $code);
    } catch (SeedFailure $error) {
        same($error->errorCode, $code, 'seed failure code');
        if ($row !== null) {
            same($error->row, $row, 'seed failure row');
        }
        if ($column !== null) {
            same($error->column, $column, 'seed failure column');
        }
    }
}

[$catalog,$provider,$host,$context] = make($bundle, $dictionary);
$loader = new CsvSeedLoader($host, $catalog, 'demo.items', 'demo.items.public', $context);

$inspect = $loader->inspect("id;code;rating\n1;A-001;5\n2;\"A;002\";7\n");
same($inspect['processed'], 2, 'inspect processes complete CSV');
same($inspect['valid'], 2, 'inspect validates complete CSV');
same($inspect['rejected'], 0, 'inspect has no rejection');
same($provider->rows, [], 'preflight performs no DML');
++$checks;

$result = $loader->load("id;code;rating\n1;A-001;5\n2;\"A;002\";7\n");
same($result, ['processed' => 2,'inserted' => 2,'rejected' => 0,'errors' => []], 'default separator load');
same(array_column($provider->rows, 'code'), ['A-001','A;002'], 'separator inside quoted field');
same(array_column($provider->rows, 'rating'), ['5','7'], 'numeric values remain strings');
++$checks;

$provider->rows = [];
$empty = $loader->load("id;code;rating\n");
same($empty, ['processed' => 0,'inserted' => 0,'rejected' => 0,'errors' => []], 'header-only CSV');
same($provider->rows, [], 'empty CSV writes nothing');
++$checks;
expectSeed(static fn() => $loader->load(''), 'SEED_CSV_HEADER_MISSING');
++$checks;

$provider->rows = [];
$comma = new CsvSeedLoader($host, $catalog, 'demo.items', 'demo.items.public', $context, ',');
same($comma->load("id,code,rating\n1,\"A,B\",5\n")['inserted'], 1, 'alternative separator');
same($provider->rows[0]['code'], 'A,B', 'alternative separator inside field');
++$checks;

$provider->rows = [];
$special = "id;code;rating\n1;\"A;B\";5\n2;\"D \"\"X\"\" 04\";6\n3;\"L1\nL2\";7\n4;ÁREA-001;8\n";
same($loader->load($special)['inserted'], 4, 'quoted and UTF-8 CSV');
same(array_column($provider->rows, 'code'), ['A;B','D "X" 04',"L1\nL2",'ÁREA-001'], 'exact quoted values');
$multilineError = $loader->load("id;code;rating\n1;\"L1\nL2\";5\n2;A;11\n");
same($multilineError['errors'][0]['row'], 4, 'physical line after multiline field');
++$checks;

$provider->rows = [];
expectSeed(static fn() => $loader->load("id;id;rating\n1;2;5\n"), 'SEED_CSV_HEADER_DUPLICATE', 1, 'id');
expectSeed(static fn() => $loader->load("id;;rating\n1;2;5\n"), 'SEED_CSV_HEADER_EMPTY', 1, '2');
expectSeed(static fn() => $loader->load("id;code;rating\n1;A\n"), 'SEED_CSV_ROW_WIDTH', 2, 'rating');
expectSeed(static fn() => $loader->load("id;code;rating\n1;A;5;extra\n"), 'SEED_CSV_ROW_WIDTH', 2, null);
++$checks;

$unknown = $loader->load("id;code;rating;extra\n1;A-001;5;x\n");
same($unknown['inserted'], 0, 'unknown field rejects whole load');
same($unknown['errors'][0]['code'], 'SEED_UNKNOWN_FIELD', 'unknown field diagnostic');
same($unknown['errors'][0]['column'], 'extra', 'unknown field column');
$missing = $loader->load("id;code\n1;A-001\n");
same($missing['errors'][0]['code'], 'SEED_REQUIRED_FIELD', 'missing required header');
same($missing['errors'][0]['column'], 'rating', 'missing required column');
++$checks;

$provider->rows = [];
$constraint = $loader->load("id;code;rating\n1;ABCDEFGHIJKLM;5\n2;A-002;11\n3;;6\n");
same($constraint['inserted'], 0, 'constraint failures reject whole load');
same(array_column($constraint['errors'], 'column'), ['code','rating','code'], 'constraint columns');
same(array_column($constraint['errors'], 'code'), [
    'SEED_INVALID_VALUE','SEED_INVALID_VALUE','SEED_INVALID_VALUE'
], 'constraint codes');
same($provider->rows, [], 'invalid CSV writes nothing');
++$checks;

$provider->rows = [];
$duplicate = $loader->load("id;code;rating\n1;A-001;5\n1;A-002;6\n");
same($duplicate['errors'][0]['code'], 'SEED_DUPLICATE_KEY', 'duplicate key diagnostic');
same($duplicate['errors'][0]['row'], 3, 'duplicate key row');
same($duplicate['errors'][0]['column'], 'id', 'duplicate key column');
same($provider->rows, [], 'duplicate key writes nothing');
++$checks;

$wide = Contract::copy($bundle);
$wide->physical->demo->columns[0]->nativeType = 'bigint';
$wide->physical->demo->columns[0]->integerRange = (object)[
    'minimum' => '-9223372036854775808',
    'maximum' => '9223372036854775807'
];
[$wideCatalog,$wideProvider,$wideHost,$wideContext] = make($wide, $dictionary);
$wideLoader = new CsvSeedLoader($wideHost, $wideCatalog, 'demo.items', 'demo.items.public', $wideContext);
$exact = $wideLoader->load(
    "id;code;rating\n9223372036854775807;MAX-64;5\n-9223372036854775808;MIN-64;6\n"
);
same($exact['inserted'], 2, 'exact signed 64-bit endpoints');
same(array_column($wideProvider->rows, 'id'), ['9223372036854775807','-9223372036854775808'], 'exact ids');
$overflow = $wideLoader->load("id;code;rating\n9223372036854775808;OVER;5\n");
same($overflow['errors'][0]['code'], 'SEED_INVALID_VALUE', 'out-of-domain 64-bit value');
++$checks;

echo 'PASS ' . $checks . " CSV seed intake, mapping, validation and exact integer groups\n";
