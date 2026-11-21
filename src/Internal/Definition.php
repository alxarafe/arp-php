<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Internal;

use Alxarafe\Arp\Dictionary\ExactInteger;

final class Definition
{
    public static function check(\stdClass $node): array
    {
        $e = (array)$node;
        if (array_diff(array_keys($e), ['id','type','constraints','enumValues','label','description'])) {
            self::invalid();
        }
        if (!isset($e['id']) || !is_string($e['id']) || strlen($e['id']) > 128 || !preg_match('/\A[A-Za-z][A-Za-z0-9_-]*(?:\.[A-Za-z][A-Za-z0-9_-]*)+\z/D', $e['id'])) {
            self::invalid();
        }
        if (!isset($e['type']) || !in_array($e['type'], Scalar::TYPES, true)) {
            self::invalid();
        }
        $type = $e['type'];
        foreach (['label','description'] as $key) {
            if (array_key_exists($key, $e) && (!is_string($e[$key]) || $e[$key] === '' || preg_match('//u', $e[$key]) !== 1)) {
                self::invalid();
            }
        }
        if (array_key_exists('constraints', $e) && !$e['constraints'] instanceof \stdClass) {
            self::invalid();
        }
        $c = (array)($e['constraints'] ?? new \stdClass());
        if ($type === 'INTEGER') {
            $d = Integer::derive($c);
            if (array_key_exists('enumValues', $e)) {
                self::invalid();
            }
            $e['constraints'] = (object)array_replace($c, ['bits' => $d['bits'],'signed' => $d['signed']]);
            return $e;
        }
        $numeric = $type === 'DECIMAL';
        $allowed = $numeric ? ['minimum','maximum','multipleOf','exclusiveMinimum','exclusiveMaximum'] : [];
        if ($type === 'DECIMAL') {
            $allowed = array_merge($allowed, ['precision','scale']);
        }
        if (in_array($type, ['STRING','TEXT','ENUM','BINARY'], true)) {
            $allowed = array_merge($allowed, ['minimumLength','maximumLength']);
        }
        if (in_array($type, ['STRING','TEXT','ENUM'], true)) {
            $allowed[] = 'pattern';
        }
        if (array_diff(array_keys($c), $allowed)) {
            self::invalid();
        }
        foreach ($c as $key => $v) {
            if (in_array($key, ['minimum','maximum','multipleOf'], true)) {
                try {
                    $n = Number::parse($v, $type);
                } catch (Failure $f) {
                    if ($f->errorCode === 'DICTIONARY_LIMIT_EXCEEDED') {
                        throw $f;
                    }
                    self::invalid();
                }
                if (!Number::inRange($n, $type) || ($key === 'multipleOf' && Number::compare($n, '0') <= 0)) {
                    self::invalid();
                }
            } elseif (in_array($key, ['exclusiveMinimum','exclusiveMaximum'], true)) {
                if (!is_bool($v)) {
                    self::invalid();
                }
            } elseif ($key === 'pattern') {
                if (!is_string($v)) {
                    self::invalid();
                }
                new Pattern($v);
            } elseif ($key === 'minimumLength' || $key === 'maximumLength') {
                if ((!is_int($v) && !$v instanceof ExactInteger) || Number::compare((string)$v, '0') < 0) {
                    self::invalid();
                }
            } elseif (!is_int($v) || $v < 0) {
                self::invalid();
            }
        }
        if (array_key_exists('precision', $c) !== array_key_exists('scale', $c)) {
            self::invalid();
        }
        if (isset($c['precision']) && ($c['precision'] < 1 || $c['precision'] > 4096 || $c['scale'] > $c['precision'])) {
            self::invalid();
        }
        if (isset($c['minimumLength'], $c['maximumLength']) && Number::compare((string)$c['minimumLength'], (string)$c['maximumLength']) > 0) {
            self::invalid();
        }
        foreach (['Minimum' => 'minimum','Maximum' => 'maximum'] as $suffix => $bound) {
            if (($c['exclusive' . $suffix] ?? false) && !isset($c[$bound])) {
                self::invalid();
            }
        }
        if (isset($c['minimum'], $c['maximum'])) {
            $cmp = Number::compare($c['minimum'], $c['maximum']);
            if ($cmp > 0 || ($cmp === 0 && (($c['exclusiveMinimum'] ?? false) || ($c['exclusiveMaximum'] ?? false)))) {
                self::invalid();
            }
        }
        $e['constraints'] = (object)$c;
        if ($type === 'ENUM') {
            if (!isset($e['enumValues']) || !is_array($e['enumValues']) || !$e['enumValues'] || !array_is_list($e['enumValues'])) {
                self::invalid();
            }
            $seen = [];
            foreach ($e['enumValues'] as $v) {
                if (!is_string($v) || $v === '' || preg_match('//u', $v) !== 1 || in_array($v, $seen, true)) {
                    self::invalid();
                }
                $seen[] = $v;
                $result = Scalar::validate($e, $v);
                if (!$result['valid']) {
                    foreach ($result['issues'] as $issue) {
                        if ($issue['code'] === 'DICTIONARY_LIMIT_EXCEEDED') {
                            throw new Failure($issue['code']);
                        }
                    }
                    self::invalid();
                }
            }
        } elseif (array_key_exists('enumValues', $e)) {
            self::invalid();
        }
        return $e;
    }
    private static function invalid(): never
    {
        throw new Failure('DICTIONARY_ELEMENT_INVALID');
    }
}
