<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/Contract.php';
require __DIR__ . '/Support/DeterministicProvider.php';
require __DIR__ . '/Support/CoreSuite.php';
require __DIR__ . '/Support/AdapterObligations.php';

use Arp\Tests\{Contract,CoreSuite,DeterministicProvider,AdapterObligations};

$contract = new Contract();
$suite = new CoreSuite($contract);
$dictionary = $contract->load('examples/demo/cds/dictionary.yaml');
$bundle = (object)['physical' => (object)['demo' => $contract->load('fixtures/cds/v1/observations/mariadb.yaml')],
    'logical' => (object)['demo' => $contract->load('examples/demo/cds/logical.yaml')],
    'projections' => (object)['demo' => $contract->load('examples/demo/cds/public.yaml')]];
$catalog = $suite->catalog($bundle, $dictionary, true);
$vectors = $contract->load('fixtures/crud/write/v1/cases.json');
$seed = array_map(static fn($r) => (array)$r, $vectors->defaults->rows);
$created = null;
foreach ($vectors->cases as $case) {
    if ($case->id === 'create-valid') {
        $created = (array)$case->request->inputs->values;
    }
}
$provider = new DeterministicProvider();
$result = AdapterObligations::run(
    $catalog,
    $provider,
    $provider,
    'demo.items.public',
    static function ($rows) use ($provider): void {
        $provider->rows = $rows;
    },
    static fn() => $provider->rows,
    $seed,
    $created
);
if ($path = getenv('ARP_ADAPTER_REPORT')) {
    file_put_contents($path, json_encode($result + ['layer' => 'generic-test-double-validation',
        'persistenceEvidence' => false], JSON_PRETTY_PRINT));
}
echo 'PASS deterministic port self-check: ' . $result['count'] . " shared obligations (NO SQL)\n";
