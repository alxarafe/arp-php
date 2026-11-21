<?php

declare(strict_types=1);

namespace Arp\Tests;

use Alxarafe\Arp\Dictionary\Internal\{Parser,Projection};

/** Read original vectors and guard their pin; this harness never writes contract inputs. */
final class Contract
{
    public readonly string $root;
    public readonly string $sha;
    public function __construct(?string $root = null)
    {
        $this->root = $root ?? (getenv('ARP_CONTRACT_PATH')
            ?: throw new \RuntimeException('ARP_CONTRACT_PATH required'));
        $this->sha = trim(file_get_contents(__DIR__ . '/../../contract.ref'));
        $actual = trim((string)shell_exec('git -C ' . escapeshellarg($this->root) . ' rev-parse HEAD'));
        self::need($actual === $this->sha, 'Wrong contract revision');
        exec('git -C ' . escapeshellarg($this->root) . ' diff --quiet HEAD -- fixtures schemas', $ignored, $status);
        self::need($status === 0, 'Contract inputs changed');
    }
    public function load(string $path): mixed
    {
        $encoding = str_ends_with($path, '.yaml') ? 'yaml' : 'json';
        return Parser::parse(file_get_contents($this->root . '/' . $path), $encoding);
    }
    public static function copy(mixed $value): mixed
    {
        return Parser::parse(Projection::encode($value), 'json');
    }
    public static function canonical(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $entries = (array)$value;
            ksort($entries, SORT_STRING);
            return ['object',array_map(self::canonical(...), $entries)];
        }
        if (is_array($value)) {
            if (!array_is_list($value)) {
                return self::canonical((object)$value);
            }
            return ['array',array_map(self::canonical(...), $value)];
        }
        return $value;
    }
    public static function same(mixed $actual, mixed $expected, string $label): void
    {
        self::need(self::canonical($actual) === self::canonical($expected), $label . ': '
            . Projection::encode(['actual' => $actual,'expected' => $expected]));
    }
    public static function need(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new \RuntimeException($label);
        }
    }
}
