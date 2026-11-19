<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Cds\Physical;

final class ObservationException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Physical observation unavailable');
    }
}
