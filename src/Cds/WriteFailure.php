<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Cds;

final class WriteFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $family = 'operation-not-allowed',
        public readonly string $status = 'rejected',
        public readonly array $details = []
    ) {
        parent::__construct($errorCode);
    }
    public static function need(bool $ok, string $code, string $family = 'operation-not-allowed'): void
    {
        if (!$ok) {
            throw new self($code, $family);
        }
    }
    public function response(): array
    {
        return $this->status === 'conflict'
            ? ['status' => 'conflict','diagnostics' => [],'conflictCode' => 'CONCURRENT_CHANGE'] + $this->details
            : ['status' => $this->status,'diagnostics' => [
                ['family' => $this->family,'code' => $this->errorCode]]];
    }
}
