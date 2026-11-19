<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Internal;

/** Exact base-ten operations. Never passes whole numeric strings to PHP arithmetic. */
final class Number
{
    public const RANGES = ['INT8' => ['-128','127'], 'UINT8' => ['0','255'], 'INT16' => ['-32768','32767'], 'UINT16' => ['0','65535'], 'INT32' => ['-2147483648','2147483647'], 'UINT32' => ['0','4294967295'], 'INT64' => ['-9223372036854775808','9223372036854775807']];
    public static function parse(mixed $value, string $type): string
    {
        $grammar = $type === 'DECIMAL' ? '-?(0|[1-9][0-9]*)(\.[0-9]+)?' : (str_starts_with($type, 'UINT') ? '(0|[1-9][0-9]*)' : '-?(0|[1-9][0-9]*)');
        if (!is_string($value) || !preg_match('~\A' . $grammar . '\z~D', $value)) {
            throw new Failure('DICTIONARY_VALUE_TYPE');
        }
        if (strlen(str_replace(['-','.'], '', $value)) > 4096) {
            throw new Failure('DICTIONARY_LIMIT_EXCEEDED');
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        return $value === '-0' ? '0' : $value;
    }
    public static function parts(string $value): array
    {
        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'));
        return [$negative, $parts[0], $parts[1] ?? ''];
    }
    private static function unsignedCompare(string $a, string $b): int
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        return (strlen($a) <=> strlen($b)) ?: (strcmp($a, $b) <=> 0);
    }
    public static function compare(string $a, string $b): int
    {
        [$an,$ai,$af] = self::parts($a);
        [$bn,$bi,$bf] = self::parts($b);
        if (self::unsignedCompare($ai . $af, '0') === 0) {
            $an = false;
        }
        if (self::unsignedCompare($bi . $bf, '0') === 0) {
            $bn = false;
        }
        if ($an !== $bn) {
            return $an ? -1 : 1;
        }
        $n = max(strlen($af), strlen($bf));
        $cmp = self::unsignedCompare($ai, $bi) ?: (strcmp(str_pad($af, $n, '0'), str_pad($bf, $n, '0')) <=> 0);
        return $an ? -$cmp : $cmp;
    }
    private static function subtract(string $a, string $b): string
    {
        $borrow = 0;
        $out = '';
        $j = strlen($b) - 1;
        for ($i = strlen($a) - 1; $i >= 0; --$i,--$j) {
            $digit = ord($a[$i]) - 48 - $borrow - ($j >= 0 ? ord($b[$j]) - 48 : 0);
            $borrow = $digit < 0 ? 1 : 0;
            $out = chr(48 + ($digit + 10) % 10) . $out;
        }
        return ltrim($out, '0') ?: '0';
    }
    public static function multiple(string $value, string $divisor): bool
    {
        [, $vi,$vf] = self::parts($value);
        [, $di,$df] = self::parts($divisor);
        $scale = max(strlen($vf), strlen($df));
        $v = ltrim($vi . str_pad($vf, $scale, '0'), '0') ?: '0';
        $d = ltrim($di . str_pad($df, $scale, '0'), '0') ?: '0';
        $remainder = '0';
        for ($i = 0,$n = strlen($v); $i < $n; ++$i) {
            $remainder = ltrim($remainder . $v[$i], '0') ?: '0';
            while (self::unsignedCompare($remainder, $d) >= 0) {
                $remainder = self::subtract($remainder, $d);
            }
        }
        return $remainder === '0';
    }
    public static function inRange(string $value, string $type): bool
    {
        return !isset(self::RANGES[$type]) || (self::compare($value, self::RANGES[$type][0]) >= 0 && self::compare($value, self::RANGES[$type][1]) <= 0);
    }
}
