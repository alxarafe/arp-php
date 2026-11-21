<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Alxarafe\Arp\Dictionary\Internal\{Parser,Scalar};

function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
}
$root = getenv('ARP_WRITE_CONTRACT_PATH') ?: throw new RuntimeException('ARP_WRITE_CONTRACT_PATH required');
$manifest = json_decode(
    file_get_contents($root . '/fixtures/crud/write/v1/manifest.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$pin = trim(file_get_contents(__DIR__ . '/../write-contract.ref'));
check($pin === 'e3c2c08c93add63faebbe011a7e8e1ec3eccff2c', 'reviewed neutral write contract pin');
foreach ($manifest['reuse'] as $reference) {
    check(hash_file('sha256', $root . '/' . $reference['path']) === $reference['sha256'], 'unchanged shared fixtures');
    $source = json_decode(file_get_contents($root . '/' . $reference['path']), true, 512, JSON_THROW_ON_ERROR);
    check($reference['cases'] === array_column($source['cases'], 'id'), 'no shared UPDATE cases dropped');
}
$codecs = json_decode(
    file_get_contents($root . '/fixtures/crud/write/v1/codec-cases.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
foreach ($codecs as $case) {
    $result = ['valid' => false];
    try {
        $decoded = Parser::parse($case['raw'], 'json');
        if ($decoded instanceof stdClass && array_keys((array)$decoded) === ['value']) {
            if ($decoded->value === null && ($case['nullable'] ?? false)) {
                $result = ['valid' => true,'value' => null];
            } else {
                $value = Scalar::validate(['id' => 'codec','type' => $case['type'],
                    'constraints' => (object)($case['constraints'] ?? [])], $decoded->value);
                if ($value['valid']) {
                    $result = ['valid' => true,'value' => $value['normalizedValue']];
                }
            }
        }
    } catch (Throwable) {
        $result = ['valid' => false];
    }
    check($result === $case['expected'], $case['id']);
}
echo 'PASS ' . count($codecs) . " original shared codec vectors and 76 unchanged UPDATE references\n";
