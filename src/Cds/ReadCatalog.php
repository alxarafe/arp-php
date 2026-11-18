<?php
declare(strict_types=1);
namespace Alxarafe\Arp\Dictionary\Cds;

interface ReadCatalog
{
    public function resolve(): ReadResult;
    public function current(): ?ReadResult;
    public function generation(): int;
    public function publishRead(ReadResult $expected, \Closure $publish): mixed;
}