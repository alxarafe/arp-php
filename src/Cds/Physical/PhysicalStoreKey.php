<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Cds\Physical;

/** Host-owned non-secret aliases, never connection strings or credentials. */
final readonly class PhysicalStoreKey
{
    public string $hash;
    public function __construct(
        public string $application,
        public string $connection,
        public string $provider,
        public string $physicalId,
        public string $dialect,
        public string $schema,
        public string $table
    ) {
        foreach ([$application,$connection,$provider,$physicalId] as $alias) {
            if (!preg_match('/\A[A-Za-z][A-Za-z0-9_.-]{0,127}\z/D', $alias)) {
                throw new \InvalidArgumentException('Use non-secret registered aliases, not connection strings');
            }
        }
        if (
            !in_array($dialect, ['postgresql','mariadb'], true) || $schema === '' || $table === ''
            || str_contains($schema, "\0") || str_contains($table, "\0")
        ) {
            throw new \InvalidArgumentException('Invalid object identity');
        }
        $this->hash = hash('sha256', json_encode([$application,$connection,$provider,$physicalId,$dialect,
            $schema,$table], JSON_THROW_ON_ERROR));
    }
}
