<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/Contract.php';
require __DIR__ . '/Support/DeterministicProvider.php';
require __DIR__ . '/Support/PublishingCatalog.php';
require __DIR__ . '/Support/CoreSuite.php';
require __DIR__ . '/Support/KnownGaps.php';

use Arp\Tests\{Contract,CoreSuite,KnownGaps};
use Alxarafe\Arp\Dictionary\Internal\Number;

$contract = new Contract();
$suite = new CoreSuite($contract);
$report = ['feature' => 'ARP-TEST-001','layer' => 'generic-core','persistenceEvidence' => false,
    'contract' => $contract->sha,'comparisons' => [
        'original-response','write-final-state','write-count','partial-write-fields','read-count','read-only'
    ],'write' => [],'listGet' => []];
$failures = [];
$knownGaps = [];
$sets = ['write' => 'fixtures/crud/write/v1/cases.json','listGet' => 'fixtures/cds/listget/v1/cases.json'];
foreach ($sets as $kind => $path) {
    $vectors = $contract->load($path);
    $products = $kind === 'listGet' ? $contract->load('fixtures/cds/listget/v1/products.json') : null;
    foreach ($vectors->cases as $caseIndex => $case) {
        $before = hash('sha256', \Alxarafe\Arp\Dictionary\Internal\Projection::encode($case));
        $reason = $kind === 'write' ? $suite->writeSkip($case) : $suite->readSkip($case, $vectors->defaults);
        if ($reason !== null) {
            $report[$kind][$case->id] = ['executed' => false,'reason' => $reason];
            continue;
        }
        try {
            $actual = $kind === 'write' ? $suite->write($case, $vectors->defaults)
                : $suite->read($case, $vectors->defaults, $products);
            $repeat = $kind === 'write' ? $suite->write($case, $vectors->defaults)
                : $suite->read($case, $vectors->defaults, $products);
            Contract::same($repeat, $actual, $case->id . ' deterministic independent replay');
            $matches = Contract::canonical($actual['response']) === Contract::canonical($case->expected->response);
            $gap = !$matches ? KnownGaps::recognize($case->id, $actual['response']) : null;
            if (!$matches && $gap === null) {
                Contract::same($actual['response'], $case->expected->response, $case->id . ' response');
            }
            if ($matches && isset(KnownGaps::CASES[$case->id])) {
                throw new RuntimeException('Known gap resolved; remove its quarantine entry explicitly');
            }
            if ($kind === 'write') {
                $rows = array_map(static fn($row) => (object)$row, $actual['rows']);
                usort($rows, static fn($a, $b) => Number::compare($a->id, $b->id));
                $expectedRows = $case->expected->observed->persisted;
                usort($expectedRows, static fn($a, $b) => Number::compare($a->id, $b->id));
                Contract::same($rows, $expectedRows, $case->id . ' rows');
                Contract::same($actual['writes'], $case->expected->observed->writes, $case->id . ' writes');
                $written = $actual['writtenFields'];
                sort($written, SORT_STRING);
                Contract::same($written, $case->expected->observed->writtenFields, $case->id . ' partial fields');
            } else {
                Contract::same($actual['reads'], $case->expected->observed->reads, $case->id . ' reads');
                Contract::same($actual['writes'], 0, $case->id . ' read-only');
            }
            Contract::same(
                hash('sha256', \Alxarafe\Arp\Dictionary\Internal\Projection::encode($case)),
                $before,
                $case->id . ' original vector immutable'
            );
            $report[$kind][$case->id] = ['executed' => true,'passed' => $matches,
                'observationsPassed' => true,'portEvents' => $actual['events']];
            if ($gap !== null) {
                $knownGaps[$case->id] = $gap;
                $report[$kind][$case->id] += ['knownGap' => $gap,'actualResponse' => $actual['response'],
                    'expectation' => $path . '#/cases/' . $caseIndex . '/expected/response'];
            }
        } catch (\Throwable $error) {
            $failures[$case->id] = $error->getMessage();
            $report[$kind][$case->id] = ['executed' => true,'passed' => false,'failure' => $error->getMessage()];
        }
    }
}
if ($path = getenv('ARP_CORE_REPORT')) {
    Contract::need(file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
        !== false, 'Core report unavailable');
}
foreach (['write','listGet'] as $kind) {
    $passed = count(array_filter($report[$kind], static fn($c) => ($c['passed'] ?? false)));
    echo "$kind: $passed/" . count($report[$kind]) . " generic vectors passed (NO SQL)\n";
}
foreach ($failures as $id => $failure) {
    fwrite(STDERR, "$id: $failure\n");
}
foreach ($knownGaps as $id => $reason) {
    fwrite(STDERR, "KNOWN CONTRACT GAP (not passed) $id: $reason\n");
}
$allow = in_array('--allow-known-gaps', $argv, true);
exit($failures || ($knownGaps && !$allow) ? 1 : 0);
