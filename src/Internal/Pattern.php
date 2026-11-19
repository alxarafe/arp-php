<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Internal;

/** Formal ARP parser and bounded position-set matcher, independent of host regex dialects. */
final class Pattern
{
    private array $atoms = [];
    public function __construct(string $pattern)
    {
        if (preg_match_all('/./us', $pattern) > 512) {
            throw new Failure('DICTIONARY_LIMIT_EXCEEDED');
        }
        if (!preg_match('~\A[\x20-\x7e]+\z~D', $pattern)) {
            $this->invalid();
        }
        $i = ($pattern[0] === '^') ? 1 : 0;
        $end = strlen($pattern);
        $slashes = 0;
        for ($j = $end - 2; $j >= 0 && $pattern[$j] === '\\'; --$j) {
            ++$slashes;
        }
        if ($pattern[$end - 1] === '$' && $slashes % 2 === 0) {
            --$end;
        }
        while ($i < $end) {
            $c = $pattern[$i];
            $chars = [];
            if ($c === '\\') {
                $chars[$this->escape($pattern, $i, $end)] = true;
            } elseif ($c === '[') {
                ++$i;
                $tokens = [];
                while ($i < $end && $pattern[$i] !== ']') {
                    if ($pattern[$i] === '\\') {
                        $tokens[] = [$this->escape($pattern, $i, $end),false];
                    } else {
                        $c = $pattern[$i++];
                        if ($c === '[' || $c === '^') {
                            $this->invalid();
                        }
                        $tokens[] = [$c,$c === '-'];
                    }
                }
                if ($i === $end || !$tokens) {
                    $this->invalid();
                } ++$i;
                if ($tokens[0][1]) {
                    $chars[array_shift($tokens)[0]] = true;
                }
                if ($tokens && $tokens[count($tokens) - 1][1]) {
                    $chars[array_pop($tokens)[0]] = true;
                }
                for ($j = 0; $j < count($tokens);) {
                    [$lo,$raw] = $tokens[$j];
                    if ($raw) {
                        $this->invalid();
                    }
                    if (isset($tokens[$j + 1]) && $tokens[$j + 1][1]) {
                        if (!isset($tokens[$j + 2]) || $tokens[$j + 2][1] || ord($lo) > ord($tokens[$j + 2][0])) {
                            $this->invalid();
                        }
                        for ($k = ord($lo); $k <= ord($tokens[$j + 2][0]); ++$k) {
                            $chars[chr($k)] = true;
                        }
                        $j += 3;
                    } else {
                        $chars[$lo] = true;
                        ++$j;
                    }
                }
            } elseif (str_contains('.^$*+?{}[]\\()|', $c)) {
                $this->invalid();
            } else {
                $chars[$c] = true;
                ++$i;
            }
            $min = $max = 1;
            if ($i < $end && str_contains('?*+', $pattern[$i])) {
                [$min,$max] = match ($pattern[$i++]) {
                    '?'=>[0,1], '*'=>[0,4096], '+'=>[1,4096]
                };
            } elseif ($i < $end && $pattern[$i] === '{') {
                $close = strpos($pattern, '}', $i);
                if ($close === false || $close >= $end) {
                    $this->invalid();
                }
                $body = substr($pattern, $i + 1, $close - $i - 1);
                if (!preg_match('~\A(0|[1-9][0-9]*)(?:,(0|[1-9][0-9]*))?\z~D', $body, $m)) {
                    $this->invalid();
                }
                if (strlen($m[1]) > 4 || (isset($m[2]) && strlen($m[2]) > 4)) {
                    $this->invalid();
                }
                $min = (int)$m[1];
                $max = (int)($m[2] ?? $m[1]);
                if ($max > 1024 || $min > $max) {
                    $this->invalid();
                }
                $i = $close + 1;
            }
            $this->atoms[] = [$chars,$min,$max];
        }
        if (!$this->atoms) {
            $this->invalid();
        }
    }
    private function invalid(): never
    {
        throw new Failure('DICTIONARY_ELEMENT_INVALID');
    }
    private function escape(string $p, int &$i, int $end): string
    {
        ++$i;
        if ($i >= $end || !preg_match('~[\x21-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e]~', $p[$i])) {
            $this->invalid();
        }
        return $p[$i++];
    }
    public function matches(string $value): bool
    {
        $length = preg_match_all('/./us', $value);
        if ($length === false) {
            throw new Failure('DICTIONARY_VALUE_TYPE');
        }
        if ($length > 4096) {
            throw new Failure('DICTIONARY_LIMIT_EXCEEDED');
        }
        // Every atom is ASCII: a non-ASCII input can never match any consumed atom.
        $n = strlen($value);
        $positions = [0 => true];
        foreach ($this->atoms as [$chars,$min,$max]) {
            // Prefix sums over reachable starts give O(atoms * input) matching.
            $prefix = [0]; for ($j = 0; $j <= $n; ++$j) {
                $prefix[$j + 1] = $prefix[$j] + (isset($positions[$j]) ? 1 : 0);
            }
            $next = [];
            $runStart = 0;
            for ($end = 0; $end <= $n; ++$end) {
                if ($end > 0 && !isset($chars[$value[$end - 1]])) {
                    $runStart = $end;
                }
                $lo = max($runStart, $end - $max);
                $hi = $end - $min;
                if ($hi >= $lo && $prefix[$hi + 1] > $prefix[$lo]) {
                    $next[$end] = true;
                }
            }
            $positions = $next;
        }
        return isset($positions[$n]);
    }
}
