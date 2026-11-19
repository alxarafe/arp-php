<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Cds;

final class ReadFailure extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
    public static function need(bool $condition, string $code): void
    {
        if (!$condition) {
            throw new self($code);
        }
    }
}
