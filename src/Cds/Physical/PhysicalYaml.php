<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Cds\Physical;

use Alxarafe\Arp\Dictionary\{CatalogBuilder,Source};
use Alxarafe\Arp\Dictionary\Cds\ReadResolver;
use Alxarafe\Arp\Dictionary\Internal\Projection;

/** Deterministic YAML 1.2 flow document; no new YAML grammar or implicit scalar conversions. */
final class PhysicalYaml
{
    public static function encode(object $document, PhysicalStoreKey $key): string
    {
        self::validate($document, $key);
        $canonical = Projection::encode(self::ordered($document));
        $yaml = "---\n" . $canonical . "\n";
        if ($canonical !== Projection::encode(self::ordered(self::decode($yaml, $key)))) {
            throw new ObservationException();
        }
        return $yaml;
    }

    public static function decode(string $yaml, PhysicalStoreKey $key): object
    {
        $parsed = (new ReadResolver())->parse(new Source('physical-store', 'stored', 'yaml', $yaml));
        if (!$parsed['valid'] || !$parsed['document'] instanceof \stdClass) {
            throw new ObservationException();
        }
        $document = $parsed['document'];
        self::validate($document, $key);
        return $document;
    }

    private static function validate(object $document, PhysicalStoreKey $key): void
    {
        $resolver = new ReadResolver();
        $parsed = $resolver->parse(new Source('physical-store', 'observed', 'json', Projection::encode($document)));
        $bundle = (object)['physical' => (object)['stored' => $document], 'logical' => new \stdClass(),
            'projections' => new \stdClass()];
        if (
            !$parsed['valid']
            || !$resolver->resolve($bundle, (new CatalogBuilder())->buildCatalog([])->catalog)->valid
        ) {
            throw new ObservationException();
        }
        $source = $document->source;
        if (
            $document->id !== $key->physicalId || $source->provider !== $key->provider
            || $source->dialect !== $key->dialect || $source->schema !== $key->schema
            || $source->name !== $key->table
        ) {
            throw new ObservationException();
        }
    }

    private static function ordered(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $entries = (array)$value;
            ksort($entries, SORT_STRING);
            $result = new \stdClass();
            foreach ($entries as $k => $v) {
                $result->$k = self::ordered($v);
            }
            return $result;
        }
        return is_array($value) ? array_map(self::ordered(...), $value) : $value;
    }
}
