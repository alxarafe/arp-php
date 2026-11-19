<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Cds;

interface RecordReader
{
    public function prepare(ReadResult $snapshot, string $projection, object $equalities): PreparedRead;
    public function open(ReadPlan $plan, \Closure $current): ReadAttempt;
}
