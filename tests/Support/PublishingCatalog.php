<?php

declare(strict_types=1);

namespace Arp\Tests;

use Alxarafe\Arp\Dictionary\Cds\{ReadCatalog,ReadResult};

/** Public port decorator: inject a phase even when GET returns no provider batch. */
final class PublishingCatalog implements ReadCatalog
{
    private int $publications = 0;
    public function __construct(private ReadCatalog $inner, private \Closure $beforeResponse)
    {
    }
    public function resolve(): ReadResult
    {
        return $this->inner->resolve();
    }
    public function current(): ?ReadResult
    {
        return $this->inner->current();
    }
    public function generation(): int
    {
        return $this->inner->generation();
    }
    public function publishRead(ReadResult $expected, \Closure $publish): mixed
    {
        $response = ++$this->publications > 1;
        return $this->inner->publishRead($expected, function () use ($response, $publish): mixed {
            if ($response) {
                ($this->beforeResponse)();
            }
            return $publish();
        });
    }
}
