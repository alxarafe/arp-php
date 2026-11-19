<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Internal;

/** Exact non-integral source number; never coerced through floating point. */
final readonly class ExactNumber implements \Stringable
{
    public function __construct(private string $value)
    {
    }
    public function __toString(): string
    {
        return $this->value;
    }
}
