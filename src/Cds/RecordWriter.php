<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Cds;

interface RecordWriter
{
    public function transaction(
        ReadResult $snapshot,
        string $projection,
        object $equalities,
        \Closure $action,
        \Closure $current
    ): mixed;
}
