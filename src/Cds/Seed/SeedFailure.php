<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Cds\Seed;

/** Expected CSV/seed rejection with optional record and column provenance. */
final class SeedFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?int $row = null,
        public readonly ?string $column = null,
        public readonly array $details = []
    ) {
        parent::__construct($errorCode);
    }

    public function diagnostic(): array
    {
        return ['code' => $this->errorCode,'row' => $this->row,'column' => $this->column] + $this->details;
    }
}
