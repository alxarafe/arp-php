<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary;

final readonly class BuildResult implements \JsonSerializable
{
    /** @internal */
    public function __construct(public ?Catalog $catalog, public array $issues)
    {
    }
    public function isValid(): bool
    {
        return $this->catalog !== null;
    }
    public function jsonSerialize(): array
    {
        return ['valid' => $this->isValid(),'issues' => $this->issues];
    }
}
