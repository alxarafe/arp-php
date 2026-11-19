<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary;

/** Exact JSON integer metadata outside the native PHP integer range. */
final readonly class ExactInteger implements \Stringable
{
    public function __construct(private string $digits)
    {
        if (!preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $digits)) {
            throw new \InvalidArgumentException('Integer literal required');
        }
    }
    public function __toString(): string
    {
        return $this->digits;
    }
}
