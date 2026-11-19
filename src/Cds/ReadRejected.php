<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Cds;

/** Expected CDS rejection only. Internal faults are never classified as invalid definitions. */
final class ReadRejected extends \RuntimeException
{
    public function __construct(public readonly array $issue)
    {
        parent::__construct($issue['code']);
    }
}
