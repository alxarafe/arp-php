<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Cds;

use Alxarafe\Arp\Dictionary\{Catalog,Source};
use Alxarafe\Arp\Dictionary\Internal\{Parser,Number,Projection,Failure};

/** Pure, bounded CDS metadata compiler. The dictionary must already be validated. */
final class ReadResolver
{
    private const KINDS = ['physical','logical','projections'];
    private array $schemas;

    public function __construct(private readonly bool $writeOperations = false)
    {
        $this->schemas = [];
        foreach (['physical','logical','projection'] as $i => $name) {
            $this->schemas[self::KINDS[$i]] = json_decode(
                file_get_contents(__DIR__ . '/schemas/' . $name . '.schema.json'),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        }
    }

    private static function detach(mixed $v): mixed
    {
        if ($v instanceof \stdClass) {
            $out = new \stdClass();
            foreach ($v as $key => $value) {
                $out->$key = self::detach($value);
            }
            return $out;
        }
        if (is_array($v)) {
            return array_map(self::detach(...), $v);
        }
        if (
            is_object($v) && !$v instanceof \Alxarafe\Arp\Dictionary\ExactInteger
            && !$v instanceof \Alxarafe\Arp\Dictionary\Internal\ExactNumber
        ) {
            throw new \InvalidArgumentException('Decoded sources must use portable values');
        }
        return $v;
    }

    public static function pointer(string ...$parts): string
    {
        return '/' . implode('/', array_map(static fn($p) => str_replace(['~','/'], ['~0','~1'], $p), $parts));
    }

    private static function need(bool $ok, string $code, string $at, string $family = 'definition-invalid'): void
    {
        if (!$ok) {
            throw new ReadRejected(['family' => $family,'code' => 'CDS_READ_' . $code,'at' => $at]);
        }
    }

    /** Independent intake stage. Success contains document, failure contains one contractual issue. */
    public function parse(Source $source): array
    {
        try {
            if (strlen($source->content) > 1048576) {
                throw new Failure('DICTIONARY_LIMIT_EXCEEDED');
            }
            return ['valid' => true,'issues' => [],'document' => Parser::parse($source->content, $source->encoding)];
        } catch (Failure $e) {
            return ['valid' => false,'issues' => [['family' => 'definition-invalid',
                'code' => $e->errorCode === 'DICTIONARY_LIMIT_EXCEEDED' ? 'CDS_READ_LIMIT' : 'CDS_READ_SOURCE_INVALID',
                'at' => self::pointer('sources', $source->sourceId)]]];
        }
    }

    /** @param array<string,array<string,Source>> $sources Explicit trusted handles, not discovery. */
    public function resolveSources(array $sources, Catalog $dictionary): ReadResult
    {
        if (array_sum(array_map('count', $sources)) > 256) {
            return ReadResult::failure('LIMIT', '/');
        }
        $bundle = new \stdClass();
        foreach (self::KINDS as $kind) {
            $bundle->$kind = new \stdClass();
            $entries = $sources[$kind] ?? [];
            ksort($entries, SORT_STRING);
            foreach ($entries as $handle => $source) {
                $parsed = $this->parse(new Source(
                    $source->providerId,
                    (string)$handle,
                    $source->encoding,
                    $source->content
                ));
                if (!$parsed['valid']) {
                    return new ReadResult($parsed);
                }
                $bundle->$kind->$handle = $parsed['document'];
            }
        }
        return $this->resolve($bundle, $dictionary);
    }

    public function resolve(object $bundle, Catalog $dictionary): ReadResult
    {
        try {
            $total = 0;
            foreach (self::KINDS as $kind) {
                $total += count((array)$bundle->$kind);
            }
            self::need($total <= 256, 'LIMIT', '/');
            // Count first, then detach caller-owned graphs before indexing or compiling.
            $bundle = self::detach($bundle);
            $indexes = [];
            foreach (self::KINDS as $kind) {
                $entries = (array)$bundle->$kind;
                ksort($entries, SORT_STRING);
                $indexes[$kind] = [];
                foreach ($entries as $handle => $doc) {
                    $at = self::pointer($kind, (string)$handle);
                    self::need(
                        $this->schema($doc, $this->schemas[$kind], $this->schemas[$kind]),
                        'SCHEMA_INVALID',
                        $at
                    );
                    self::need(!isset($indexes[$kind][$doc->id]), 'DUPLICATE_ID', $at);
                    $indexes[$kind][$doc->id] = [$doc,$at];
                }
            }
            foreach ($indexes['physical'] as [$doc,$at]) {
                $this->physical($doc, $at);
            }
            $models = [];
            ksort($indexes['logical'], SORT_STRING);
            foreach ($indexes['logical'] as $id => [$doc,$at]) {
                self::need(isset($indexes['physical'][$doc->physical]), 'REFERENCE_NOT_FOUND', $at . '/physical');
                $physical = $indexes['physical'][$doc->physical][0];
                $columns = [];
                foreach ($physical->columns as $col) {
                    $columns[$col->name] = $col;
                }
                self::need(
                    !array_diff(array_keys((array)$doc->fields), array_keys($columns)),
                    'UNKNOWN_FIELD',
                    $at . '/fields'
                );
                ksort($columns, SORT_STRING);
                $fields = [];
                foreach ($columns as $name => $col) {
                    $fields[] = $this->field(
                        $col,
                        $doc->fields->$name ?? new \stdClass(),
                        $dictionary,
                        $at . self::pointer('fields', (string)$name)
                    );
                }
                $models[$id] = ['id' => $id,'physical' => $physical,'fields' => $fields];
            }
            $projected = [];
            ksort($indexes['projections'], SORT_STRING);
            foreach (array_keys($indexes['projections']) as $id) {
                $this->projection($id, [], $indexes['projections'], $models, $projected);
            }
            ksort($projected, SORT_STRING);
            return new ReadResult(['valid' => true,'issues' => [],'models' => array_values($models),
                'projections' => array_values($projected)]);
        } catch (ReadRejected $e) {
            return new ReadResult(['valid' => false,'issues' => [$e->issue]]);
        }
    }

    private static function bound(mixed $value, string $at): string
    {
        self::need(is_string($value) && strlen($value) <= 4096, 'LIMIT', $at);
        self::need(preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $value) === 1, 'PROFILE_UNSUPPORTED', $at);
        return $value === '-0' ? '0' : $value;
    }

    private static function interval(object $constraints): bool
    {
        foreach ($constraints as $key => $value) {
            if (
                !in_array($key, ['minimum','maximum'], true) || !is_string($value) || strlen($value) > 4096
                || preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $value) !== 1
            ) {
                return false;
            }
        }
        return true;
    }

    private function physical(object $doc, string $at): void
    {
        self::need($doc->kind === 'table', 'PROFILE_UNSUPPORTED', $at . '/kind');
        self::need(count($doc->columns) <= 2048, 'LIMIT', $at . '/columns');
        $names = array_column($doc->columns, 'name');
        $ordinals = array_map(
            static fn($c) => (string)$c->ordinal,
            array_filter($doc->columns, static fn($c) => isset($c->ordinal))
        );
        self::need(count(array_unique($names)) === count($names)
            && count(array_unique($ordinals)) === count($ordinals), 'PHYSICAL_INVALID', $at . '/columns');
        foreach ($doc->columns as $i => $col) {
            $point = $at . '/columns/' . $i;
            $values = [];
            foreach (['minimum','maximum'] as $key) {
                if (isset($col->integerRange->$key)) {
                    $values[$key] = self::bound($col->integerRange->$key, $point . '/integerRange/' . $key);
                }
            }
            self::need(!isset($values['minimum'], $values['maximum'])
                || Number::compare($values['minimum'], $values['maximum']) <= 0, 'PHYSICAL_INVALID', $point);
            if (isset(Number::RANGES[$col->scalarType ?? ''])) {
                [$lo,$hi] = Number::RANGES[$col->scalarType];
                foreach ($values as $v) {
                    self::need(Number::compare($lo, $v) <= 0
                    && Number::compare($v, $hi) <= 0, 'PHYSICAL_INVALID', $point);
                }
            }
            if (isset($col->decimalGeometry)) {
                self::need(Number::compare(
                    (string)$col->decimalGeometry->scale,
                    (string)$col->decimalGeometry->precision
                ) <= 0, 'PHYSICAL_INVALID', $point);
            }
        }
        self::need(!array_diff($doc->primaryKey ?? [], $names), 'PHYSICAL_INVALID', $at);
        foreach ($doc->indexes ?? [] as $index) {
            self::need(!array_diff($index->columns, $names), 'PHYSICAL_INVALID', $at);
        }
        foreach ($doc->foreignKeys ?? [] as $key) {
            self::need(!array_diff($key->columns, $names)
            && count($key->columns) === count($key->target->columns), 'PHYSICAL_INVALID', $at);
        }
        foreach (['indexes','foreignKeys'] as $key) {
            $ids = array_column($doc->$key ?? [], 'name');
            self::need(count(array_unique($ids)) === count($ids), 'PHYSICAL_INVALID', $at . '/' . $key);
        }
    }

    private function field(object $col, object $overlay, Catalog $dictionary, string $at): array
    {
        $result = ['name' => $col->name,'physical' => $col,'compatibility' => 'not-verified'];
        $type = $col->scalarType ?? null;
        $intrinsic = new \stdClass();
        if (isset($overlay->dictionary)) {
            $resolved = $dictionary->resolve($overlay->dictionary);
            self::need($resolved['valid'], 'REFERENCE_NOT_FOUND', $at . '/dictionary');
            $element = $resolved['descriptor'];
            $type = $element->type;
            $intrinsic = clone ($element->constraints ?? new \stdClass());
            $result['dictionary'] = $overlay->dictionary;
            if (isset($element->enumValues)) {
                $result['enumValues'] = $element->enumValues;
            }
        } elseif (isset(Number::RANGES[$type ?? ''])) {
            [$lo,$hi] = Number::RANGES[$type];
            foreach ($col->integerRange ?? new \stdClass() as $key => $value) {
                if (Number::compare($value, $key === 'minimum' ? $lo : $hi) !== 0) {
                    $intrinsic->$key = $value === '-0' ? '0' : $value;
                }
            }
        }
        if ($type !== null) {
            $result += ['type' => $type,'intrinsicConstraints' => $intrinsic,
            'constraints' => clone $intrinsic];
        }
        $context = $overlay->constraints ?? new \stdClass();
        if (isset($overlay->constraints)) {
            $result['contextualConstraints'] = $context;
        }
        if ((array)$context) {
            self::need(
                isset(Number::RANGES[$type ?? '']) && self::interval($intrinsic),
                'PROFILE_UNSUPPORTED',
                $at . '/constraints'
            );
            self::need(
                !array_diff(array_keys((array)$context), ['minimum','maximum']),
                'PROFILE_UNSUPPORTED',
                $at . '/constraints'
            );
        }
        if (isset(Number::RANGES[$type ?? '']) && self::interval($intrinsic)) {
            [$lo,$hi] = Number::RANGES[$type];
            if (isset($intrinsic->minimum) && Number::compare($intrinsic->minimum, $lo) > 0) {
                $lo = $intrinsic->minimum;
            }
            if (isset($intrinsic->maximum) && Number::compare($intrinsic->maximum, $hi) < 0) {
                $hi = $intrinsic->maximum;
            }
            foreach (['minimum','maximum'] as $key) {
                if (isset($context->$key)) {
                    $value = self::bound($context->$key, $at . '/constraints/' . $key);
                    self::need($key === 'minimum' ? Number::compare($value, $lo) >= 0
                    : Number::compare($value, $hi) <= 0, 'CONSTRAINT_WEAKENING', $at . '/constraints/' . $key);
                    if ($key === 'minimum') {
                        $lo = $value;
                    } else {
                        $hi = $value;
                    }
                                    $result['constraints']->$key = $value;
                }
            }
            self::need(Number::compare($lo, $hi) <= 0, 'CONSTRAINT_CONTRADICTION', $at . '/constraints');
            if (isset(Number::RANGES[$col->scalarType ?? ''])) {
                [$plo,$phi] = Number::RANGES[$col->scalarType];
                if (
                    isset($col->integerRange->minimum)
                    && Number::compare($col->integerRange->minimum, $plo) > 0
                ) {
                    $plo = $col->integerRange->minimum;
                }
                if (
                    isset($col->integerRange->maximum)
                    && Number::compare($col->integerRange->maximum, $phi) < 0
                ) {
                    $phi = $col->integerRange->maximum;
                }
                self::need(
                    Number::compare($plo, $lo) <= 0 && Number::compare($hi, $phi) <= 0,
                    'DOMAIN_NOT_REPRESENTABLE',
                    $at,
                    'physical-incompatible'
                );
                $result['compatibility'] = 'compatible';
            }
        }
        foreach (['label','description','hint'] as $key) {
            if (isset($overlay->$key)) {
                $result[$key] = $overlay->$key;
            }
        }
        return $result;
    }

    private function projection(string $id, array $stack, array $index, array $models, array &$out): array
    {
        [$doc,$at] = $index[$id];
        self::need(!in_array($id, $stack, true), 'REFERENCE_CYCLE', $at . '/source');
        self::need(count($stack) < 64, 'LIMIT', $at . '/source');
        self::need(
            !array_diff($doc->operations, $this->writeOperations
                ? ['LIST','GET','CREATE','UPDATE','DELETE'] : ['LIST','GET']),
            'OPERATION_UNSUPPORTED',
            $at . '/operations',
            'operation-not-allowed'
        );
        if (isset($doc->source->entity)) {
            $entity = $doc->source->entity;
            self::need(isset($models[$entity]), 'REFERENCE_NOT_FOUND', $at . '/source');
            $fields = $models[$entity]['fields'];
        } else {
            $parentId = $doc->source->projection;
            self::need(isset($index[$parentId]), 'REFERENCE_NOT_FOUND', $at . '/source');
            $parent = $this->projection($parentId, [...$stack,$id], $index, $models, $out);
            $entity = $parent['entity'];
            $fields = $parent['fields'];
            self::need(!array_diff($doc->operations, $parent['operations']), 'OPERATION_EXPANSION', $at . '/operations');
        }
        $available = array_column($fields, null, 'name');
        self::need(!array_diff($doc->fields, array_keys($available)), 'FIELD_NOT_EXPOSED', $at . '/fields');
        $selected = [];
        foreach ($doc->fields as $name) {
            $field = $available[$name];
            $public = array_diff_key($field, array_flip(['physical','intrinsicConstraints','contextualConstraints']));
            if (isset($field['physical']) && property_exists($field['physical'], 'nullable')) {
                $public['nullable'] = $field['physical']->nullable;
            }
            $selected[] = $public;
        }
        return $out[$id] = ['id' => $id,'source' => $doc->source,'entity' => $entity,
            'fields' => $selected,'operations' => $doc->operations];
    }

    /** Interpreter for the keywords used by the three pinned authoring schemas only. */
    private function schema(mixed $v, object $s, object $root): bool
    {
        if (isset($s->{'$ref'})) {
            $ref = $root;
            foreach (explode('/', substr($s->{'$ref'}, 2)) as $part) {
                $ref = $ref->$part;
            }
            return $this->schema($v, $ref, $root);
        }
        if (isset($s->oneOf)) {
            $n = 0;
            foreach ($s->oneOf as $option) {
                if ($this->schema($v, $option, $root)) {
                    ++$n;
                }
            }
            if ($n !== 1) {
                return false;
            }
        }
        if (isset($s->const) && $v !== $s->const) {
            return false;
        }
        if (isset($s->enum) && !in_array($v, $s->enum, true)) {
            return false;
        }
        if (isset($s->type)) {
            $ok = match ($s->type) {
                'object' => $v instanceof \stdClass,
                'array' => is_array($v),
                'string' => is_string($v),
                'boolean' => is_bool($v),
                'integer' => is_int($v) || $v instanceof \Alxarafe\Arp\Dictionary\ExactInteger,
                default => throw new \LogicException('Unsupported schema keyword type')
            };
            if (!$ok) {
                return false;
            }
        }
        if (is_string($v)) {
            $length = preg_match_all('/./us', $v);
            if (isset($s->minLength) && $length < $s->minLength) {
                return false;
            }
            if (isset($s->maxLength) && $length > $s->maxLength) {
                return false;
            }
            if (isset($s->pattern) && preg_match('~' . str_replace('~', '\\~', $s->pattern) . '~u', $v) !== 1) {
                return false;
            }
        }
        if (is_int($v) || $v instanceof \Alxarafe\Arp\Dictionary\ExactInteger) {
            if (isset($s->minimum) && Number::compare((string)$v, (string)$s->minimum) < 0) {
                return false;
            }
        }
        if ($v instanceof \stdClass) {
            foreach ($s->required ?? [] as $key) {
                if (!property_exists($v, $key)) {
                    return false;
                }
            }
            if (isset($s->minProperties) && count((array)$v) < $s->minProperties) {
                return false;
            }
            foreach ($v as $key => $value) {
                if (isset($s->propertyNames) && !$this->schema((string)$key, $s->propertyNames, $root)) {
                    return false;
                }
                $child = $s->properties->$key ?? $s->additionalProperties ?? null;
                if ($child === false) {
                    return false;
                }
                if (is_object($child) && !$this->schema($value, $child, $root)) {
                    return false;
                }
            }
        }
        if (is_array($v)) {
            if (isset($s->minItems) && count($v) < $s->minItems) {
                return false;
            }
            if (
                ($s->uniqueItems ?? false)
                && count(array_unique(array_map(Projection::encode(...), $v))) !== count($v)
            ) {
                return false;
            }
            foreach ($v as $item) {
                if (isset($s->items) && !$this->schema($item, $s->items, $root)) {
                    return false;
                }
            }
        }
        return true;
    }
}
