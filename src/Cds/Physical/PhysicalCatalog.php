<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Cds\Physical;

use Alxarafe\Arp\Dictionary\Catalog;
use Alxarafe\Arp\Dictionary\Cds\{ReadPublication,ReadResolver,ReadResult};
use Alxarafe\Arp\Dictionary\Internal\{Parser,Projection};

/** Local request/process catalog. Hosts sharing it across threads must serialize access externally. */
final class PhysicalCatalog implements \Alxarafe\Arp\Dictionary\Cds\ReadCatalog
{
    private ReadPublication $publication;
    private ReadResolver $resolver;
    private string $definitions;
    private array $providers;

    public function __construct(
        private readonly Catalog $dictionary,
        object $definitions,
        array $providers,
        bool $writeOperations = false
    ) {
        if (count($providers) > 256) {
            throw new \InvalidArgumentException('Too many registered providers');
        }
        foreach ($providers as $provider) {
            if (!$provider instanceof PhysicalObservationProvider) {
                throw new \InvalidArgumentException('Expected provider');
            }
        }
        $this->definitions = Projection::encode($definitions);
        ksort($providers, SORT_STRING);
        $this->providers = $providers;
        $this->publication = new ReadPublication();
        $this->resolver = new ReadResolver($writeOperations);
    }

    private ?string $blocked = null;
    public function invalidate(): void
    {
        $this->publication->invalidate();
        $this->blocked = null;
        foreach ($this->providers as $provider) {
            if (!$provider instanceof InvalidatablePhysicalProvider) {
                continue;
            }
            try {
                $provider->invalidate();
            } catch (ObservationException) {
                $this->blocked ??= $provider->providerId();
            }
        }
        if ($this->blocked !== null) {
            throw new ObservationException();
        }
    }
    public function generation(): int
    {
        return $this->publication->generation();
    }
    public function publishRead(ReadResult $expected, \Closure $publish): mixed
    {
        return $this->publication->publishRead($expected, $publish);
    }
    public function current(): ?ReadResult
    {
        return $this->publication->current();
    }

    public function resolve(): ReadResult
    {
        if (($current = $this->publication->current()) !== null) {
            return $current;
        }
        if ($this->blocked !== null) {
            return ReadResult::failure(
                'OBSERVATION_FAILED',
                '/providers/' . str_replace(['~','/'], ['~0','~1'], $this->blocked),
                'execution-failure'
            );
        }
        $ticket = $this->publication->beginGeneration();
        $bundle = Parser::parse($this->definitions, 'json');
        $bundle->physical = new \stdClass();
        foreach ($this->providers as $handle => $provider) {
            try {
                $bundle->physical->$handle = $provider->observe();
            } catch (ObservationException) {
                $at = '/providers/' . str_replace(['~','/'], ['~0','~1'], $provider->providerId());
                return $this->publication->publish(
                    $ticket,
                    ReadResult::failure('OBSERVATION_FAILED', $at, 'execution-failure')
                );
            }
        }
        return $this->publication->publish($ticket, $this->resolver->resolve($bundle, $this->dictionary));
    }
}
