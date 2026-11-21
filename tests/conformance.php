<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
use Alxarafe\Arp\Dictionary\{CatalogBuilder,Source};
$root = $argv[1] ?? getenv('ARP_CONTRACT_PATH') ?: dirname(__DIR__, 2) . '/arp-contract';
$pin = trim(file_get_contents(__DIR__ . '/../contract.ref'));
$contractActual = trim((string)shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD'));
if ($contractActual !== $pin) {
    fwrite(STDERR, "Contract SHA mismatch: expected $pin, found $contractActual\n");
    exit(2);
}
exec('git -C ' . escapeshellarg($root) . ' diff --quiet HEAD -- fixtures schemas docs/features/arp-dict-001.md', $ignored, $dirty);
if ($dirty !== 0) {
    fwrite(STDERR, "Contract input has local changes\n");
    exit(2);
}
$dir = $root . '/fixtures/dictionary/v1/';
/** Preserve deliberately unpaired surrogate test values as invalid UTF-8 PHP strings. */
function fixture(string $file): mixed
{
    $raw = file_get_contents($file);
    $numbers = [];
    $raw = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"|(-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/s', function ($m) use (&$numbers) {
        if (!isset($m[1]) || $m[1] === '') {
            return $m[0];
        }
        $key = '__fixture_number_' . count($numbers);
        $numbers[$key] = $m[1];
        return json_encode($key);
    }, $raw);
    $numericRestore = function ($v) use (&$numericRestore, $numbers) {
        if (is_string($v) && isset($numbers[$v])) {
            $raw = $numbers[$v];
            if (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $raw)) {
                return new \Alxarafe\Arp\Dictionary\ExactInteger($raw);
            }
            return new \Alxarafe\Arp\Dictionary\Internal\ExactNumber($raw);
        }
        if (is_array($v)) {
            return array_map($numericRestore, $v);
        }
        if (is_object($v)) {
            foreach ($v as $k => $x) {
                $v->$k = $numericRestore($x);
            }
        }
        return $v;
    };
    if (str_contains($raw, '\\ud800')) {
        if (str_contains($raw, '\\ue000') || str_contains($raw, "\xee\x80\x80")) {
            throw new RuntimeException('Sentinel collision');
        }
        $raw = str_replace('\\ud800', '\\ue000', $raw);
        $restore = function ($v) use (&$restore) {
            if (is_string($v)) {
                return str_replace("\xee\x80\x80", "\xed\xa0\x80", $v);
            }
            if (is_array($v)) {
                return array_map($restore, $v);
            }
            if (is_object($v)) {
                foreach ($v as $k => $x) {
                    $v->$k = $restore($x);
                }
            }
            return $v;
        };
        return $numericRestore($restore(json_decode($raw, false, 512, JSON_THROW_ON_ERROR)));
    }
    return $numericRestore(json_decode($raw, false, 512, JSON_THROW_ON_ERROR));
}
function canonical(mixed $v): mixed
{
    if (is_int($v) || is_float($v) || $v instanceof \Alxarafe\Arp\Dictionary\ExactInteger || $v instanceof \Alxarafe\Arp\Dictionary\Internal\ExactNumber) {
        $raw = (string)$v;
        preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?$/D', $raw, $m);
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
        return ['object',array_map('canonical', $a)];
    }
    if (is_array($v)) {
        if (!array_is_list($v)) {
            ksort($v);
            return ['object',array_map('canonical', $v)];
        } return ['array',array_map('canonical', $v)];
    }
    return $v;
}
$counts = [];
$failures = [];
function check(string $group, string $name, mixed $actual, mixed $expected): void
{
    global $counts,$failures;
    $ok = canonical($actual) === canonical($expected);
    $counts[$group][$ok ? 'passed' : 'failed'] = ($counts[$group][$ok ? 'passed' : 'failed'] ?? 0) + 1;
    if (!$ok) {
        $failures[] = ['group' => $group,'id' => $name,'actual' => $actual,'expected' => $expected];
    }
}
function outcome(array $actual, object $expected): array
{
    unset($actual['descriptor']);
    // Definition/source diagnostics are excluded; value issue fields compare in full.
    foreach ($actual['issues'] ?? [] as $i => $issue) {
        foreach (['providerId','sourceId','path'] as $key) {
            unset($issue[$key]);
        }
        if (!isset($expected->issues[$i]->elementId)) {
            unset($issue['elementId']);
        }
        $actual['issues'][$i] = $issue;
    }
    return $actual;
}
$builder = new CatalogBuilder();
function build(object $document): \Alxarafe\Arp\Dictionary\BuildResult
{
    global $builder;
    return $builder->buildCatalog([new Source('fixture', 'memory', 'json', \Alxarafe\Arp\Dictionary\Internal\Projection::encode($document))]);
}
$manifest = fixture($dir . 'manifest.json');
$base = build(fixture($dir . $manifest->sets->catalog));
check('catalog', 'INTEGER-catalog', $base->jsonSerialize(), (object)['valid' => true,'issues' => []]);
foreach (fixture($dir . $manifest->sets->values) as $v) {
    $result = build((object)['apiVersion' => $manifest->apiVersion,'elements' => [$v->element]]);
    if (!$result->isValid()) {
        throw new RuntimeException('Invalid fixture definition ' . $v->id);
    }
    check('values', $v->id, $result->catalog->validate($v->element->id, $v->value), $v->expected);
}
foreach (fixture($dir . $manifest->sets->definitions) as $v) {
    $r = build($v->document)->jsonSerialize();
    check('definitions', $v->id, outcome($r, $v->expected), $v->expected);
}
foreach (fixture($dir . $manifest->sets->sources) as $v) {
    $r = $builder->buildCatalog([new Source('fixture', $v->id, $v->encoding, file_get_contents($root . '/' . $v->path))]);
    check('sources', $v->id, outcome($r->jsonSerialize(), $v->expected), $v->expected);
    if (isset($v->descriptorProjection)) {
        check('source-descriptors', $v->id, $r->catalog?->resolve($v->descriptorProjection->id)['descriptor'] ?? null, $v->descriptorProjection);
    }
}
foreach (fixture($dir . $manifest->sets->patterns) as $v) {
    $r = build((object)['apiVersion' => $manifest->apiVersion,'elements' => [(object)['id' => 'fixture.pattern','type' => 'STRING','constraints' => (object)['pattern' => $v->pattern]]]]);
    $actual = ['valid' => $r->isValid()];
    if (!$r->isValid()) {
        $actual['code'] = $r->issues[0]['code'];
    }
    check('patterns', $v->id, $actual, $v->expected);
    foreach ($v->samples as $i => $sample) {
        check('pattern-samples', $v->id . '/' . $i, $r->catalog?->validate('fixture.pattern', $sample->value)['valid'], $sample->matches);
    }
}
foreach (fixture($dir . $manifest->sets->catalogs) as $scenario) {
    $contexts = [];
    $last = [];
    foreach ($scenario->steps as $i => $step) {
        $ctx = $step->context;
        $name = $scenario->id . '/' . $i;
        if ($step->operation === 'buildCatalog') {
            $sources = array_map(fn($s)=>new Source($s->providerId, $s->sourceId, 'json', \Alxarafe\Arp\Dictionary\Internal\Projection::encode($s->document)), $step->sources);
            $last[$ctx] = $builder->buildCatalog($sources);
            if ($last[$ctx]->isValid()) {
                $contexts[$ctx] = $last[$ctx]->catalog;
            }
            check('catalog-steps', $name, outcome($last[$ctx]->jsonSerialize(), $step->expected), $step->expected);
        } elseif ($step->operation === 'resolve') {
            $r = $contexts[$ctx]->resolve($step->elementId);
            check('catalog-steps', $name, outcome($r, $step->expected), $step->expected);
            if (isset($step->descriptorProjection)) {
                check('descriptors', $name, $r['descriptor'], $step->descriptorProjection);
            }
        } elseif ($step->operation === 'assertRejectedGeneration') {
            check('catalog-steps', $name, ['published' => $last[$ctx]->catalog !== null], $step->expected);
        } else {
            throw new RuntimeException('Unimplemented fixture operation ' . $step->operation);
        }
    }
}
foreach (fixture($dir . $manifest->sets->integer)->definitions as $case) {
    $result = build((object)['apiVersion' => $manifest->apiVersion,'elements' => [(object)[
        'id' => 'test.integer','type' => 'INTEGER','constraints' => $case->constraints
    ]]]);
    $actual = ['valid' => $result->isValid()];
    if ($result->isValid()) {
        $descriptor = $result->catalog->resolve('test.integer')['descriptor'];
        $d = \Alxarafe\Arp\Dictionary\Internal\Integer::derive($descriptor->constraints);
        $actual += ['signed' => $descriptor->constraints->signed,'bits' => $descriptor->constraints->bits,
            'minimum' => $d['minimum'],'maximum' => $d['maximum']];
    } elseif ($result->catalog !== null || $result->issues[0]['code'] !== 'DICTIONARY_ELEMENT_INVALID') {
        throw new RuntimeException('Definition did not reject atomically: ' . $case->id);
    }
    check('integer-definitions', $case->id, $actual, $case->expected);
}
foreach (fixture($dir . $manifest->sets->integer)->values as $case) {
    $result = build((object)['apiVersion' => $manifest->apiVersion,'elements' => [(object)[
        'id' => 'test.integer','type' => 'INTEGER','constraints' => $case->constraints
    ]]]);
    if (!$result->isValid()) {
        throw new RuntimeException('Invalid INTEGER value definition: ' . $case->id);
    }
    check('integer-values', $case->id, $result->catalog->validate('test.integer', $case->value), $case->expected);
}
foreach ($counts as $group => $c) {
    echo "$group: " . ($c['passed'] ?? 0) . " passed, " . ($c['failed'] ?? 0) . " failed\n";
}
echo json_encode(['contractSha' => $pin,'counts' => $counts,'failures' => $failures], JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
exit($failures ? 1 : 0);
