<?php

declare(strict_types=1);

namespace Arp\Tests;

/** Two pre-existing core differences, not alternative neutral expectations and never counted as passed. */
final class KnownGaps
{
    public const CASES = [
        'delete-blind-baseline' => [
            'code' => 'INPUT_SHAPE','family' => 'invalid-data',
            'reason' => 'CrudHost closed input shape rejects blind baseline before checking DELETE precondition.'
        ],
        'create-physical-range-incompatible' => [
            'code' => 'INVALID_DEFINITION','family' => 'definition-invalid',
            'reason' => 'ReadResolver classifies an unbound observed range narrowed below context '
                . 'as constraint weakening.'
        ]
    ];
    public static function recognize(string $id, array $response): ?string
    {
        $gap = self::CASES[$id] ?? null;
        if ($gap === null) {
            return null;
        }
        $established = ['status' => 'rejected','diagnostics' => [
            ['family' => $gap['family'],'code' => $gap['code']]
        ]];
        return Contract::canonical($response) === Contract::canonical($established) ? $gap['reason'] : null;
    }
}
