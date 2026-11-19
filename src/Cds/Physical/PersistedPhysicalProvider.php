<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Cds\Physical;

/** Optional decorator; only the existing native provider performs introspection. */
final readonly class PersistedPhysicalProvider implements PhysicalObservationProvider, InvalidatablePhysicalProvider
{
    public function __construct(
        private PhysicalObservationProvider $inner,
        private FilePhysicalStore $store,
        private PhysicalStoreKey $key
    ) {
    }
    public function providerId(): string
    {
        return $this->inner->providerId();
    }
    public function observe(): object
    {
        if ($this->inner->providerId() !== $this->key->provider) {
            throw new ObservationException();
        }
        return $this->store->get($this->key, $this->inner->observe(...));
    }
    public function invalidate(): void
    {
        $this->store->invalidate($this->key);
    }
}
