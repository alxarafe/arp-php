<?php
declare(strict_types=1);
namespace Alxarafe\Arp\Dictionary\Cds;

/** An unpublished complete batch. Both completion and checked cleanup are mandatory. */
interface ReadAttempt
{
    /** @return array<array{key:array<string>,values:object}> */
    public function rows(): array;
    public function complete(): void;
    public function close(): void;
}