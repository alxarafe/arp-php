<?php

declare(strict_types=1);

use Alxarafe\Arp\Dictionary\{CatalogBuilder,Source};
use Alxarafe\Arp\Dictionary\Cds\{ReadResolver,ReadResult,ReadPublication};
use Alxarafe\Arp\Dictionary\Internal\{Parser,Projection};

require __DIR__ . '/../vendor/autoload.php';
$root = $argv[1] ?? (getenv('ARP_CONTRACT_PATH') ?: dirname(__DIR__, 2) . '/arp-contract');
function fixture(string $file): mixed
{
    return Parser::parse(file_get_contents($file), 'json');
}
function canonical(mixed $v): mixed
{
    if (
        is_int($v) || $v instanceof \Alxarafe\Arp\Dictionary\ExactInteger
        || $v instanceof \Alxarafe\Arp\Dictionary\Internal\ExactNumber
    ) {
        preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?$/D', (string)$v, $m);
        $fraction = $m[3] ?? '';
        $digits = ltrim($m[2] . $fraction, '0');
        $scale = strlen($fraction) - (int)($m[4] ?? 0);
        if ($digits === '') {
            return ['number','0'];
        }
        $trimmed = rtrim($digits, '0');
        $scale -= strlen($digits) - strlen($trimmed);
        return ['number',$m[1] . $trimmed . 'e' . (-$scale)];
    }
    if (is_object($v)) {
        $a = (array)$v;
        ksort($a);
        return ['object',array_map(canonical(...), $a)];
    }
    if (is_array($v)) {
        if (!array_is_list($v)) {
            ksort($v);
            return ['object',array_map(canonical(...), $v)];
        }
        return ['array',array_map(canonical(...), $v)];
    }
    return $v;
}
$resolver = new ReadResolver();
function same(mixed $a, mixed $b, string $id): void
{
    if (canonical($a) !== canonical($b)) {
        throw new RuntimeException($id . ' mismatch: ' . Projection::encode($a));
    }
}
function reverseMembers(mixed $v): mixed
{
    if ($v instanceof stdClass) {
        $out = new stdClass();
        foreach (array_reverse((array)$v, true) as $k => $x) {
            $out->$k = reverseMembers($x);
        }
        return $out;
    }
    return is_array($v) ? array_map(reverseMembers(...), $v) : $v;
}
$count = 0;
foreach (fixture($root . '/fixtures/cds/read/v1/cases.json')->cases as $case) {
    $path = $root . '/' . $case->dictionary;
    $built = (new CatalogBuilder())->buildCatalog([new Source(
        'fixture',
        'dictionary',
        str_ends_with($path, '.yaml') ? 'yaml' : 'json',
        file_get_contents($path)
    )]);
    if (!$built->isValid()) {
        throw new RuntimeException('Invalid scalar snapshot');
    }
    $input = $case->input;
    $before = Projection::encode($input);
    $result = $resolver->resolve($input, $built->catalog);
    same($result->projection(), $case->expected, $case->id);
    same(
        $resolver->resolve(reverseMembers($input), $built->catalog)->projection(),
        $case->expected,
        $case->id . ' order'
    );
    if (Projection::encode($input) !== $before) {
        throw new RuntimeException('Input mutated');
    }
    $projection = $result->projection();
    $projection->valid = !$projection->valid;
    same($result->projection(), $case->expected, $case->id . ' immutable');
    ++$count;
}
$intake = 0;
foreach (Parser::parse(file_get_contents($root . '/fixtures/cds/read/v1/source-cases.json'), 'json')->cases as $case) {
    $bytes = isset($case->bytesHex) ? hex2bin($case->bytesHex) : $case->text;
    $parsed = $resolver->parse(new Source('fixture', 's', $case->encoding, $bytes));
    if (isset($case->expectedError)) {
        same((object)$parsed['issues'][0], $case->expectedError, $case->id);
    } else {
        same($parsed['document'], $case->expected, $case->id);
    }
    ++$intake;
}
$success = new ReadResult(['valid' => true,'issues' => [],'models' => [],'projections' => []]);
$failure = ReadResult::failure('OBSERVATION_FAILED', '/snapshot', 'execution-failure');
$store = new ReadPublication();
$other = new ReadPublication();
$ticket = $store->beginGeneration();
$store->publish($ticket, $success);
if ($store->current() !== $success || $other->current() !== null) {
    throw new RuntimeException('Isolation');
}
$store->invalidate();
if ($store->current() !== null) {
    throw new RuntimeException('Stale publication');
}
same(
    $store->publish($ticket, $success)->projection(),
    ReadResult::failure('SNAPSHOT_CHANGED', '/snapshot', 'execution-failure')->projection(),
    'generation'
);
$store->publish($store->beginGeneration(), $failure);
if ($store->current() !== null) {
    throw new RuntimeException('Partial publication');
}
// Intake budgets precede syntax and raw batches precede schema resolution.
$limit = $resolver->parse(new Source('fixture', 's', 'json', str_repeat(' ', 1048577)));
if ($limit['issues'][0]['code'] !== 'CDS_READ_LIMIT') {
    throw new RuntimeException('Bytes budget');
}
echo "PASS CDS read: $count resolution, $intake intake; order, immutability, generations and scalar regressions\n";
// Additional admission, schema-provenance and budget regressions beyond the shared fixtures.
foreach (['physical','logical','projection'] as $name) {
    if (
        file_get_contents(__DIR__ . '/../src/Cds/schemas/' . $name . '.schema.json')
        !== file_get_contents($root . '/schemas/cds/v1/' . $name . '.schema.json')
    ) {
        throw new RuntimeException('Schema drift');
    }
}
$dictionary = (new CatalogBuilder())->buildCatalog([])->catalog;
$doc = (object)['apiVersion' => 'arp.physical/v1','id' => 'storage.table','kind' => 'table',
    'source' => (object)['provider' => 'host','dialect' => 'test','name' => 't'],
    'columns' => [(object)['name' => 'id','nativeType' => 'opaque']]];
foreach ([256,257] as $size) {
    $bundle = (object)['physical' => new stdClass(),'logical' => new stdClass(),'projections' => new stdClass()];
    for ($i = 0; $i < $size; ++$i) {
        $entry = clone $doc;
        $entry->id = 'storage.t' . $i;
        $bundle->physical->{'s' . $i} = $entry;
    }
    $actual = $resolver->resolve($bundle, $dictionary);
    if ($actual->valid !== ($size === 256)) {
        throw new RuntimeException('Source budget');
    }
}
foreach ([2048,2049] as $size) {
    $entry = clone $doc;
    $entry->columns = [];
    for ($i = 0; $i < $size; ++$i) {
        $entry->columns[] = (object)['name' => 'c' . $i,'nativeType' => 'opaque'];
    }
    $bundle = (object)['physical' => (object)['s' => $entry],'logical' => new stdClass(),'projections' => new stdClass()];
    if ($resolver->resolve($bundle, $dictionary)->valid !== ($size === 2048)) {
        throw new RuntimeException('Column budget');
    }
}
$long = str_repeat('1', 4097);
foreach ([['minimum' => $long,'maximum' => $long],['maximum' => $long,'minimum' => $long]] as $extent) {
    $entry = clone $doc;
    $column = clone $doc->columns[0];
    $column->integerRange = (object)$extent;
    $entry->columns = [$column];
    $bundle = (object)['physical' => (object)['s' => $entry],'logical' => new stdClass(),'projections' => new stdClass()];
    same(
        $resolver->resolve($bundle, $dictionary)->projection(),
        ReadResult::failure('LIMIT', '/physical/s/columns/0/integerRange/minimum')->projection(),
        'bound order'
    );
}
$batch = ['physical' => ['first' => new Source('host', 'ignored', 'json', '{}')],
    'logical' => ['later' => new Source('host', 'ignored', 'json', '{')]];
same(
    $resolver->resolveSources($batch, $dictionary)->projection(),
    ReadResult::failure('SOURCE_INVALID', '/sources/later')->projection(),
    'batch intake before schema'
);
echo "PASS additional source/column budgets, bound priority, raw intake pipeline and pinned schemas\n";
foreach ([64,65] as $size) {
    $bundle = (object)['physical' => (object)['s' => $doc], 'logical' => (object)['s' => (object)[
        'apiVersion' => 'arp.logical/v1','id' => 'logical.table','physical' => 'storage.table','fields' => new stdClass()]],
        'projections' => new stdClass()];
    for ($i = 0; $i < $size; ++$i) {
        $bundle->projections->{'s' . $i} = (object)[
        'apiVersion' => 'arp.projection/v1','id' => 'projection.p' . $i,
        'source' => $i === 0 ? (object)['entity' => 'logical.table'] : (object)['projection' => 'projection.p' . ($i - 1)],
        'fields' => ['id'],'operations' => ['GET']];
    }
    if ($resolver->resolve($bundle, $dictionary)->valid !== ($size === 64)) {
        throw new RuntimeException('Chain budget');
    }
}
echo "PASS projection chain boundary (64/65)\n";
