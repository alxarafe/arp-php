<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary;

/** Caller-owned provenance and immutable bytes; no implicit discovery or I/O. */
final readonly class Source
{
    public function __construct(public string $providerId, public string $sourceId, public string $encoding, public string $content)
    {
    }
}
