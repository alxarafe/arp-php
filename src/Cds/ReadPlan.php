<?php
declare(strict_types=1);
namespace Alxarafe\Arp\Dictionary\Cds;

final readonly class ReadPlan
{
    public function __construct(public PreparedRead $source, public ?array $position,
        public bool $get, public int $limit) {}
}