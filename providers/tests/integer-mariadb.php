<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Alxarafe\Arp\Dictionary\{CatalogBuilder,Source};
use Alxarafe\Arp\Dictionary\Internal\{Parser,Projection,Number,Integer};
use Alxarafe\Arp\Dictionary\Cds\{CrudHost,ListGetHost};
use Alxarafe\Arp\Cds\Physical\{PhysicalCatalog,MariaDbObservationProvider};
use Alxarafe\Arp\MariaDb\{MariaDbRecordReader,MariaDbRecordWriter};

function canonical(mixed $value): mixed
{
    if (is_object($value)) {
        $value = (array)$value;
    }
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    return array_map(canonical(...), $value);
}
$evidence = [];
function check(bool $ok, string $name): void
{
    global $evidence;
    if (!$ok) {
        throw new RuntimeException($name);
    }
    $evidence[] = $name;
}
function same(mixed $actual, mixed $expected, string $name): void
{
    check(canonical($actual) === canonical($expected), $name . ': ' . Projection::encode([$actual,$expected]));
}
final class IntegerStatement extends PDOStatement
{
    public static int $dml = 0;
    public function execute(?array $params = null): bool
    {
        if (preg_match('/\A(?:INSERT|UPDATE|DELETE) /', $this->queryString)) {
            ++self::$dml;
        }
        return parent::execute($params);
    }
}
$root = getenv('ARP_WRITE_CONTRACT_PATH') ?: throw new RuntimeException('Pinned INTEGER contract required');
$password = getenv('ARP_DB_PASSWORD') ?: throw new RuntimeException('Owned database required');
$connect = static function () use ($password): PDO {
    $pdo = new PDO('mysql:host=db;dbname=arpcds;charset=utf8mb4', 'root', $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => true
    ]);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [IntegerStatement::class]);
    return $pdo;
};
$admin = $connect();
check(hash_equals(
    getenv('ARP_CRUD_RUN_TOKEN') ?: '',
    $admin->query('SELECT token FROM __arp_crud_test_owner')->fetchColumn()
), 'owned database before INTEGER DDL');
$dictionary = (new CatalogBuilder())->buildCatalog([new Source(
    'integer',
    'dictionary',
    'yaml',
    file_get_contents($root . '/examples/demo/cds/dictionary.yaml')
)])->catalog;
$logical = Parser::parse(file_get_contents($root . '/examples/demo/cds/logical.yaml'), 'yaml');
$public = Parser::parse(file_get_contents($root . '/examples/demo/cds/public.yaml'), 'yaml');
$summary = Parser::parse(file_get_contents($root . '/examples/demo/cds/summary.yaml'), 'yaml');
$definitions = (object)['logical' => (object)['items' => $logical],
    'projections' => (object)['public' => $public,'summary' => $summary]];
$context = ['identity' => 'integer-run','policyRevision' => 'p1'];
$targets = ['demo.items' => ['projection' => 'demo.items.public','fields' => ['id','code','rating'],
    'operations' => ['LIST','GET','CREATE','UPDATE','DELETE'],'defaultLimit' => 1,'maxLimit' => 100]];
$allowed = true;
$policy = static function () use (&$allowed): array {
    return ['allowed' => $allowed,'fields' => ['id','code','rating'],'writableFields' => ['id','code','rating'],
        'equalities' => new stdClass()];
};
$make = static function (
    string $idSql,
    string $ratingSql = 'TINYINT'
) use (
    $admin,
    $connect,
    $definitions,
    $dictionary,
    $targets,
    $policy
): array {
    // Types come exclusively from the finite test table below, never from a request.
    $admin->exec('DROP TABLE IF EXISTS integer_items');
    $admin->exec('CREATE TABLE integer_items(id ' . $idSql . ' PRIMARY KEY,code VARCHAR(12) NOT NULL,rating '
        . $ratingSql . ' NOT NULL) ENGINE=InnoDB');
    $catalog = new PhysicalCatalog($dictionary, $definitions, ['items' => new MariaDbObservationProvider(
        $connect,
        'primary',
        'demo.items',
        'arpcds',
        'integer_items',
        2
    )], true);
    $reader = new MariaDbRecordReader($connect, 2);
    $host = new CrudHost($catalog, $reader, new MariaDbRecordWriter($connect, 2), $targets, $policy);
    return [$host,$catalog,$reader];
};
$key = static fn(string $value): object => (object)['components' => [
    (object)['field' => 'id','type' => 'INTEGER','value' => $value]
]];
$call = static fn($host, $operation, $inputs) => $host->invoke(Projection::encode((object)[
    'target' => 'demo.items','operation' => $operation,'inputs' => (object)$inputs
]), $context);
$rows = static fn(): array => $admin->query('SELECT CAST(id AS CHAR) id,code,CAST(rating AS CHAR) rating'
    . ' FROM integer_items ORDER BY integer_items.id')->fetchAll(PDO::FETCH_ASSOC);
$domains = [];
foreach ([8 => 'TINYINT',16 => 'SMALLINT',32 => 'INT',64 => 'BIGINT'] as $bits => $sql) {
    foreach ([true,false] as $signed) {
        [$host,$catalog] = $make($sql . ($signed ? '' : ' UNSIGNED'));
        [$low,$high] = Integer::domain($bits, $signed);
        $idField = null;
        foreach ($catalog->resolve()->projection()->models[0]->fields as $field) {
            if ($field->name === 'id') {
                $idField = $field;
            }
        }
        same($idField->physical->scalarType, 'INTEGER', 'physical INTEGER ' . $bits . '/' . (int)$signed);
        same($idField->physical->integerRange, (object)['minimum' => $low,'maximum' => $high], 'observed exact domain');
        same([$idField->constraints->bits,$idField->constraints->signed], [$bits,$signed], 'resolved capacity');
        foreach ([$low,$high] as $value) {
            $created = $call($host, 'CREATE', ['values' => ['id' => $value,'code' => 'EDGE','rating' => '5']]);
            same($created['status'], 'success', 'CREATE exact endpoint ' . $value);
            same($created['output']['key']['components'][0]['value'], $value, 'returned typed endpoint');
            $read = $call($host, 'GET', ['key' => $key($value)]);
            same($read['output']['record']['values']->id, $value, 'GET endpoint');
        }
        foreach ([Number::add($low, '-1'),Number::add($high, '1')] as $value) {
            $before = IntegerStatement::$dml;
            same(
                $call($host, 'CREATE', ['values' => ['id' => $value,'code' => 'OUT','rating' => '5']])['status'],
                'rejected',
                'reject out-of-domain ' . $value
            );
            same(IntegerStatement::$dml, $before, 'zero DML for rejected capacity');
        }
        $changed = $call($host, 'UPDATE', ['key' => $key($high),'changes' => ['rating' => '6'],
            'baseline' => ['rating' => '5']]);
        same($changed['status'], 'success', 'UPDATE endpoint');
        $proof = $host->readForDelete('demo.items', $key($high), $context);
        same(
            $call($host, 'DELETE', ['key' => $key($high),'precondition' => $proof['output']['precondition']])['status'],
            'success',
            'DELETE endpoint with exact evidence'
        );
        same(count($rows()), 1, 'one row retained after endpoint delete');
        $domains[] = ['bits' => $bits,'signed' => $signed,'minimum' => $low,'maximum' => $high];
    }
}

// Replay each neutral unsigned-64 write vector against real observations, transactions and protected read evidence.
$vectors = json_decode(file_get_contents($root . '/fixtures/crud/write/v1/cases.json'), true, 512, JSON_THROW_ON_ERROR);
$shared = [];
foreach ($vectors['cases'] as $case) {
    if (!str_contains($case['id'], 'uint64')) {
        continue;
    }
    [$host,$catalog] = $make(
        'BIGINT UNSIGNED',
        isset($case['host']['columnOverrides']['rating']) ? 'BIGINT UNSIGNED' : 'TINYINT'
    );
    $initial = $case['host']['rows'] ?? $vectors['defaults']['rows'];
    $insert = $admin->prepare('INSERT INTO integer_items(id,code,rating) VALUES (?,?,?)');
    foreach ($initial as $row) {
        $insert->execute([$row['id'],$row['code'],$row['rating']]);
    }
    $request = $case['request'];
    $inputs = $request['inputs'];
    if ($request['operation'] === 'DELETE') {
        $state = $case['host']['preconditions']['delete-read-7']['state'];
        $admin->prepare('UPDATE integer_items SET code=?,rating=? WHERE id=?')
            ->execute([$state['code'],$state['rating'],$initial[0]['id']]);
        $evidenceKey = $case['host']['preconditions']['delete-read-7']['binding']['key'];
        // A real proof for the adjacent key cannot authorize deletion of this key.
        if (str_contains($case['id'], 'key-binding')) {
            $otherId = $evidenceKey['components'][0]['value'];
            $insert->execute([$otherId,$state['code'],$state['rating']]);
            $proof = $host->readForDelete('demo.items', (object)['components' => array_map(
                static fn($c) => (object)$c,
                $evidenceKey['components']
            )], $context);
            $admin->prepare('DELETE FROM integer_items WHERE id=?')->execute([$otherId]);
        } else {
            $proof = $host->readForDelete('demo.items', $key($initial[0]['id']), $context);
        }
        check(isset($proof['output']['precondition']), 'real unsigned delete proof');
        $inputs['precondition'] = $proof['output']['precondition'];
        $admin->prepare('UPDATE integer_items SET code=?,rating=? WHERE id=?')
            ->execute([$initial[0]['code'],$initial[0]['rating'],$initial[0]['id']]);
    }
    $response = $call($host, $request['operation'], $inputs);
    same($response, $case['expected']['response'], $case['id'] . ' response');
    $expectedRows = $case['expected']['observed']['persisted'];
    usort($expectedRows, static fn($a, $b) => Number::compare($a['id'], $b['id']));
    same($rows(), $expectedRows, $case['id'] . ' persistence');
    $shared[$case['id']] = ['executed' => true,'response' => $response,'persisted' => $rows()];
}

[$host] = $make('BIGINT UNSIGNED', 'BIGINT UNSIGNED');
$admin->prepare('INSERT INTO integer_items VALUES (?,?,?)')->execute([
    '18446744073709551615','HISTORY','18446744073709551615'
]);
$before = IntegerStatement::$dml;
$conflict = $call($host, 'UPDATE', ['key' => $key('18446744073709551615'),
    'changes' => ['rating' => '5'],'baseline' => ['rating' => '18446744073709551614']]);
same($conflict['status'], 'conflict', 'UPDATE adjacent uint64 baseline conflicts');
same(IntegerStatement::$dml, $before, 'uint64 conflict zero DML');
same(
    $call($host, 'UPDATE', ['key' => $key('18446744073709551615'),
    'changes' => ['rating' => '18446744073709551615'],
    'baseline' => ['rating' => '18446744073709551615']])['status'],
    'rejected',
    'historical uint64 explicitly resent is invalid even unchanged'
);
same(IntegerStatement::$dml, $before, 'historical invalid resend zero DML');
same(
    $call($host, 'UPDATE', ['key' => $key('18446744073709551615'),
    'changes' => ['rating' => '5'],'baseline' => ['rating' => '18446744073709551615']])['status'],
    'success',
    'exact historical uint64 baseline correction'
);
same(
    $rows()[0],
    ['id' => '18446744073709551615','code' => 'HISTORY','rating' => '5'],
    'partial UPDATE preserves unsent fields'
);

[$host,$catalog,$reader] = $make('BIGINT UNSIGNED');
$maximum = '18446744073709551615';
$values = ['9007199254740993','9007199254740994','18446744073709551614',$maximum];
foreach ($values as $value) {
    same(
        $call($host, 'CREATE', ['values' => ['id' => $value,'code' => 'EXACT','rating' => '5']])['status'],
        'success',
        'CREATE adjacent ' . $value
    );
}
$positions = [];
$page = [];
do {
    $result = $call($host, 'LIST', $page);
    same($result['status'], 'success', 'real keyset page');
    foreach ($result['output']['records'] as $record) {
        $positions[] = $record['key']['components'][0]['value'];
    }
    $next = $result['output']['page']['next'];
    $page = ['continuation' => $next];
} while ($next !== null);
same($positions, $values, 'no duplicate or skipped adjacent key beyond int/float ranges');
$hiddenTargets = ['hidden' => ['projection' => 'demo.items.summary','fields' => ['id','code'],
    'defaultLimit' => 1,'maxLimit' => 100]];
// Hide the PK through a reduced projection, not merely a policy hint.
$hiddenDefinitions = unserialize(serialize($definitions));
$hiddenDefinitions->projections->summary->fields = ['code'];
$hiddenCatalog = new PhysicalCatalog($dictionary, $hiddenDefinitions, ['items' => new MariaDbObservationProvider(
    $connect,
    'primary',
    'demo.items',
    'arpcds',
    'integer_items',
    2
)], true);
$hiddenTargets['hidden']['fields'] = ['code'];
$hidden = new ListGetHost($hiddenCatalog, $reader, $hiddenTargets, $policy);
$hiddenCall = static fn($operation, $inputs) => $hidden->invoke(Projection::encode((object)[
    'target' => 'hidden','operation' => $operation,'inputs' => (object)$inputs
]), $context);
$lastReference = null;
$hiddenPage = [];
do {
    $result = $hiddenCall('LIST', $hiddenPage);
    same($result['status'], 'success', 'hidden exact keyset page');
    $lastReference = $result['output']['records'][0]['key']['reference'];
    check(strlen($lastReference) <= 128, 'bounded opaque uint64 reference');
    $next = $result['output']['page']['next'];
    $hiddenPage = ['continuation' => $next];
} while ($next !== null);
$before = IntegerStatement::$dml;
$hiddenGet = $hiddenCall('GET', ['key' => ['reference' => $lastReference]]);
same($hiddenGet['output']['found'], true, 'opaque unsigned maximum roundtrip');
check(!property_exists($hiddenGet['output']['record']['values'], 'id'), 'hidden PK not exposed');
same(IntegerStatement::$dml, $before, 'LIST/GET never mutate');
$allowed = false;
same($call($host, 'UPDATE', ['key' => $key($maximum),'changes' => ['rating' => '6'],
    'baseline' => ['rating' => '5']])['status'], 'rejected', 'uint64 authorization');
same(IntegerStatement::$dml, $before, 'denied uint64 operation zero DML');
$allowed = true;
// The new unsigned layout is replayed with original neutral requests and expected public responses.
$products = Parser::parse(file_get_contents($root . '/fixtures/cds/listget/v1/products.json'), 'json');
$readVectors = Parser::parse(file_get_contents($root . '/fixtures/cds/listget/v1/cases.json'), 'json');
$layout = $products->layouts->unsigned64;
$productDictionary = (new CatalogBuilder())->buildCatalog([new Source(
    'integer',
    'products',
    'json',
    Projection::encode($products->dictionary)
)])->catalog;
$admin->exec('CREATE TABLE integer_products(tenant_id INT NOT NULL,product_id BIGINT UNSIGNED PRIMARY KEY,'
    . 'sku VARCHAR(80) NOT NULL,name VARCHAR(160) NOT NULL,price DECIMAL(20,8) NOT NULL,rating SMALLINT NOT NULL,'
    . 'description TEXT,available TINYINT(1) NOT NULL,released DATE NOT NULL,cost DECIMAL(20,9) NOT NULL,'
    . 'internal_note TEXT) ENGINE=InnoDB');
$insert = $admin->prepare('INSERT INTO integer_products VALUES (?,?,?,?,?,?,?,?,?,?,?)');
foreach ($layout->rows as $row) {
    $values = array_values((array)$row->values);
    $values[7] = $values[7] ? '1' : '0';
    $insert->execute($values);
}
$productCatalog = new PhysicalCatalog($productDictionary, (object)[
    'logical' => $layout->definitions->logical,'projections' => $layout->definitions->projections
], ['products' => new MariaDbObservationProvider(
    $connect,
    'commercial-db',
    'storage.products',
    'arpcds',
    'integer_products',
    2
)]);
$readHost = new ListGetHost($productCatalog, $reader, ['demo.products' => [
    'projection' => 'products.identified','fields' => ['tenant_id','product_id','sku','name'],
    'defaultLimit' => 2,'maxLimit' => 100
]], static fn() => ['allowed' => true,'fields' => ['tenant_id','product_id','sku','name'],
    'equalities' => new stdClass()]);
$sharedReads = [];
foreach ($readVectors->cases as $case) {
    if (($case->layout ?? null) !== 'unsigned64') {
        continue;
    }
    $response = $readHost->invoke(Projection::encode($case->request), $context);
    same($response, $case->expected->response, $case->id . ' actual response');
    $sharedReads[$case->id] = ['executed' => true,'response' => $response];
}
$report = ['contract' => trim(file_get_contents(__DIR__ . '/../../write-contract.ref')),
    'engine' => $admin->query('SELECT VERSION()')->fetchColumn(),'php' => PHP_VERSION,
    'client' => $admin->getAttribute(PDO::ATTR_CLIENT_VERSION),'domains' => $domains,
    'sharedWriteCases' => $shared,'sharedReadCases' => $sharedReads,'orderedExactKeys' => $positions,
    'checks' => count($evidence),
    'assertions' => $evidence,'executed' => true];
if ($destination = getenv('ARP_INTEGER_REPORT')) {
    file_put_contents($destination, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
echo 'PASS INTEGER MariaDB: ' . count($domains) . ' capacities, ' . count($shared)
    . ' shared uint64 write vectors, ' . count($sharedReads) . ' shared LIST/GET vectors; '
    . count($evidence) . " assertions\n";
