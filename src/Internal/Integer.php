<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Internal;

use Alxarafe\Arp\Dictionary\ExactInteger;

/** INTEGER capacity and logical interval, using decimal strings for every scalar operation. */
final class Integer
{
    private const SIGNED = [8 => ['-128','127'],16 => ['-32768','32767'],
        32 => ['-2147483648','2147483647'],64 => ['-9223372036854775808','9223372036854775807']];
    private const UNSIGNED = [8 => ['0','255'],16 => ['0','65535'],
        32 => ['0','4294967295'],64 => ['0','18446744073709551615']];
    public static function domain(int $bits, bool $signed): array
    {
        return ($signed ? self::SIGNED : self::UNSIGNED)[$bits];
    }
    private static function need(bool $ok): void
    {
        if (!$ok) {
            throw new Failure('DICTIONARY_ELEMENT_INVALID');
        }
    }
    public static function derive(array|object $constraints): array
    {
        $c = (array)$constraints;
        self::need(!array_diff(array_keys($c), [
            'bits','signed','minimum','maximum','multipleOf','exclusiveMinimum','exclusiveMaximum'
        ]));
        $bits = null;
        if (array_key_exists('bits', $c)) {
            self::need((is_int($c['bits']) || $c['bits'] instanceof ExactInteger)
                && in_array((string)$c['bits'], ['8','16','32','64'], true));
            $bits = (int)(string)$c['bits'];
        }
        if (array_key_exists('signed', $c)) {
            self::need(is_bool($c['signed']));
        }
        foreach (['exclusiveMinimum','exclusiveMaximum'] as $flag) {
            if (array_key_exists($flag, $c)) {
                self::need(is_bool($c[$flag]));
            }
        }
        foreach (['minimum','maximum','multipleOf'] as $key) {
            if (array_key_exists($key, $c)) {
                try {
                    $c[$key] = Number::parse($c[$key], 'INTEGER');
                } catch (Failure $error) {
                    if ($error->errorCode === 'DICTIONARY_LIMIT_EXCEEDED') {
                        throw $error;
                    }
                    self::need(false);
                }
            }
        }
        self::need(!($c['exclusiveMinimum'] ?? false) || isset($c['minimum']));
        self::need(!($c['exclusiveMaximum'] ?? false) || isset($c['maximum']));
        self::need(!isset($c['multipleOf']) || Number::compare($c['multipleOf'], '0') > 0);
        self::need(!isset($c['minimum'], $c['maximum']) || Number::compare($c['minimum'], $c['maximum']) <= 0);
        $signed = $c['signed'] ?? !(isset($c['minimum']) && Number::compare($c['minimum'], '0') >= 0);
        $bounds = array_intersect_key($c, array_flip(['minimum','maximum']));
        $candidates = $bits !== null ? [$bits] : (count($bounds) === 2 ? [8,16,32,64] : [32,64]);
        $found = false;
        foreach ($candidates as $candidate) {
            [$lo,$hi] = self::domain($candidate, $signed);
            $fits = true;
            foreach ($bounds as $bound) {
                $fits = $fits && Number::compare($lo, $bound) <= 0 && Number::compare($bound, $hi) <= 0;
            }
            if ($fits) {
                $bits = $candidate;
                $found = true;
                break;
            }
        }
        self::need($found);
        $minimum = $c['minimum'] ?? $lo;
        $maximum = $c['maximum'] ?? $hi;
        if ($c['exclusiveMinimum'] ?? false) {
            $minimum = Number::add($minimum, '1');
        }
        if ($c['exclusiveMaximum'] ?? false) {
            $maximum = Number::add($maximum, '-1');
        }
        self::need(Number::compare($minimum, $maximum) <= 0);
        if (isset($c['multipleOf'])) {
            // Reflect entirely negative intervals. An interval containing zero always contains a multiple.
            $lower = $minimum;
            $upper = $maximum;
            if (Number::compare($upper, '0') < 0) {
                [$lower,$upper] = [substr($upper, 1), substr($lower, 1)];
            }
            if (Number::compare($lower, '0') > 0) {
                $remainder = Number::remainder($lower, $c['multipleOf']);
                $next = $remainder === '0' ? $lower
                    : Number::add($lower, Number::add($c['multipleOf'], '-' . $remainder));
                self::need(Number::compare($next, $upper) <= 0);
            }
        }
        return ['bits' => $bits,'signed' => $signed,'domainMinimum' => $lo,'domainMaximum' => $hi,
            'minimum' => $minimum,'maximum' => $maximum];
    }
    /** Historical identities retain capacity while ignoring current business restrictions. */
    public static function capacity(array|object $constraints): object
    {
        $d = self::derive($constraints);
        return (object)['bits' => $d['bits'],'signed' => $d['signed']];
    }
    /** Fixed-width two's-complement bits; no scalar is ever cast to a PHP int. */
    public static function packKey(string $number): string
    {
        $number = Number::parse($number, 'INTEGER');
        if (
            Number::compare($number, '-9223372036854775808') < 0
            || Number::compare($number, '18446744073709551615') > 0
        ) {
            throw new \InvalidArgumentException('Key exceeds portable capacity');
        }
        if (str_starts_with($number, '-')) {
            $number = Number::add($number, '18446744073709551616');
        }
        $bytes = '';
        for ($byte = 0; $byte < 8; ++$byte) {
            $quotient = '';
            $remainder = 0;
            for ($i = 0; $i < strlen($number); ++$i) {
                $digit = $remainder * 10 + ord($number[$i]) - 48;
                $quotient .= (string)intdiv($digit, 256);
                $remainder = $digit % 256;
            }
            $bytes = chr($remainder) . $bytes;
            $number = ltrim($quotient, '0') ?: '0';
        }
        if ($number !== '0') {
            throw new \InvalidArgumentException('Key exceeds 64 bits');
        }
        return $bytes;
    }
    public static function unpackKey(string $bytes, bool $signed): string
    {
        if (strlen($bytes) !== 8) {
            throw new \InvalidArgumentException('Eight key bytes required');
        }
        $number = '0';
        for ($i = 0; $i < 8; ++$i) {
            // Multiplication by 256 expressed as eight exact doublings.
            for ($bit = 0; $bit < 8; ++$bit) {
                $number = Number::add($number, $number);
            }
            $number = Number::add($number, (string)ord($bytes[$i]));
        }
        if ($signed && ord($bytes[0]) >= 128) {
            $number = Number::add($number, '-18446744073709551616');
        }
        return $number;
    }
}
