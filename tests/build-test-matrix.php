<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/Contract.php';

use Arp\Tests\Contract;

$options = getopt('', ['core:','crud:','integer:','reads:','output:']);
foreach (['core','crud','integer','reads','output'] as $key) {
    Contract::need(isset($options[$key]), '--' . $key . ' required');
}
$load = static fn($path) => json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$core = $load($options['core']);
$crud = $load($options['crud']);
$integer = $load($options['integer']);
$reads = $load($options['reads']);
$contract = new Contract();
$baseline = $load(__DIR__ . '/../docs/integer-conformance.json');
$coreBaseline = $load(__DIR__ . '/../docs/test-001-conformance.json');
Contract::need($core['persistenceEvidence'] === false, 'Core simulation mislabeled');
foreach ([$core,$crud,$integer,$baseline] as $input) {
    Contract::same($input['contract'], $contract->sha, 'matrix contract revision');
}
$matrix = ['feature' => 'ARP-TEST-002','contract' => $contract->sha,'baseline' => '3b7d55c',
    'environment' => array_intersect_key($crud, array_flip(['engine','php','client'])),
    'inventory' => 'docs/test-001-inventory.json','coreBaseline' => 'docs/test-001-conformance.json',
    'sourceArtifacts' => [],'beforeAfter' => [],
    'layers' => ['generic' => ['persistenceEvidence' => false,'write' => [],'listGet' => []],
        'sqlAdapter' => ['mariadb' => $crud['adapterObligations']],
        'integrated' => ['persistenceEvidence' => true,'engine' => 'mariadb','write' => [],'listGet' => []]],
    'nativeRegressionEvidence' => ['groups' => $crud['nativeGroups'],
        'certaintyRegressions' => count($crud['certaintyStatesEvidence']),
        'integerAssertions' => $integer['checks'],'integerDomains' => $integer['domains'],
        'coreClassification' => $crud['coreClassificationEvidence'],
        'readLayouts' => array_values(array_filter(array_keys($reads), static fn($k) => $k !== 'direct'))],
    'summary' => [],'limitations' => [
        'Generic port events, rollback and injected faults are simulations, never SQL persistence evidence.',
        'Opaque literal tokens, partial protected receipt state, delegation, defaults and module phases '
            . 'not supported by existing ports are not counted as replay passes.',
        'The common adapter battery ran on MariaDB only. '
            . 'Other existing adapters may reuse it but have no new execution claim.'
    ]];
$sets = ['write' => 'fixtures/crud/write/v1/cases.json','listGet' => 'fixtures/cds/listget/v1/cases.json'];
foreach ($sets as $kind => $path) {
    $cases = $contract->load($path)->cases;
    $ids = array_map(static fn($c) => $c->id, $cases);
    Contract::same(array_keys($core[$kind]), $ids, 'complete generic inventory');
    $matrix['sourceArtifacts'][$path] = hash_file('sha256', $contract->root . '/' . $path);
    foreach ($cases as $case) {
        $id = $case->id;
        $generic = $core[$kind][$id];
        Contract::need(!isset($generic['knownGap']), 'Core exceptions are forbidden');
        if ($coreBaseline['layers']['generic'][$kind][$id]['executed']) {
            Contract::need(($generic['passed'] ?? false) === true, 'Lost or nonconformant generic vector ' . $id);
        }
        unset($generic['portEvents']);
        $matrix['layers']['generic'][$kind][$id] = $generic;
        if ($kind === 'write') {
            $native = $crud['sharedCases'][$id];
            if (($integer['sharedWriteCases'][$id]['executed'] ?? false) === true) {
                $native = ['executed' => true,'passed' => true,'evidence' => 'providers/tests/integer-mariadb.php'];
            } elseif ($native['executed']) {
                $native['evidence'] = 'providers/tests/crud-mariadb.php';
            }
        } else {
            $pass = in_array($id, $reads['direct'], true)
                || ($integer['sharedReadCases'][$id]['executed'] ?? false) === true;
            $native = ['executed' => $pass];
            if ($pass) {
                $native += ['passed' => true,'evidence' => isset($integer['sharedReadCases'][$id])
                    ? 'providers/tests/integer-mariadb.php' : 'providers/mariadb/tests/listget.php'];
            } else {
                $native['reason'] = 'No exact native replay; independent native guarantees are listed separately.';
            }
        }
        $matrix['layers']['integrated'][$kind][$id] = $native;
        // Any earlier native pass must still be proven by actual native reports, not rescued by a fake pass.
        if ($baseline['native'][$kind]['cases'][$id]['executed']) {
            Contract::need(($native['passed'] ?? false) === true, 'Lost native vector ' . $id);
        }
    }
    foreach (['generic','integrated'] as $layer) {
        $entries = $matrix['layers'][$layer][$kind];
        $executed = count(array_filter($entries, static fn($c) => $c['executed']));
        $passed = count(array_filter($entries, static fn($c) => $c['passed'] ?? false));
        $matrix['summary'][$layer][$kind] = ['inventoried' => count($entries),'executed' => $executed,
            'passed' => $passed,'failures' => $executed - $passed,'notExecuted' => count($entries) - $executed];
        Contract::need($executed === $passed, 'Nonconformant vector in strict matrix');
        $before = $coreBaseline['summary'][$layer][$kind];
        $matrix['beforeAfter'][$layer][$kind] = ['beforePassed' => $before['passed'],'afterPassed' => $passed];
    }
}
Contract::same(count($crud['coreClassificationEvidence']), 4, 'Native classification regressions complete');
foreach ($crud['coreClassificationEvidence'] as $probe) {
    Contract::need($probe['dmlCount'] === 0 && $probe['persistedUnchanged'], 'Classification probe mutated storage');
}
Contract::same($crud['adapterObligations']['count'], 10, 'Common adapter battery complete');
Contract::need($crud['adapterObligations']['persistenceEvidence'], 'Adapter obligations lack SQL evidence');
Contract::same($crud['nativeGroups'], $baseline['native']['regressionGroups'], 'No native group dropped');
Contract::same(
    count($crud['certaintyStatesEvidence']),
    $baseline['native']['transactionCertaintyRegressions'],
    'No certainty regression dropped'
);
Contract::same($integer['checks'], $baseline['native']['integer']['checks'], 'No numeric assertion dropped');
Contract::need(file_put_contents(
    $options['output'],
    json_encode($matrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
) !== false, 'Matrix report unavailable');
echo json_encode($matrix['summary'], JSON_PRETTY_PRINT) . "\nPASS: no earlier native guarantee lost\n";
