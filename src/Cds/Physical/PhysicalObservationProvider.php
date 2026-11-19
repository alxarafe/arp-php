<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Cds\Physical;

/** Host-registered table observation; implementations must own fresh isolated connections. */
interface PhysicalObservationProvider
{
    public function providerId(): string;
    public function observe(): object;
}
