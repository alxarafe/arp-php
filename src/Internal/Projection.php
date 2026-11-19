<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Internal;

use Alxarafe\Arp\Dictionary\ExactInteger;

/** JSON exporter that preserves arbitrary-size integer metadata as numbers. */
final class Projection
{
    public static function encode(mixed $value): string
    {
        if ($value instanceof ExactInteger || $value instanceof ExactNumber) {
            return (string)$value;
        }
        if (is_object($value)) {
            $parts = [];
            foreach ($value as $k => $v) {
                $parts[] = json_encode((string)$k, JSON_THROW_ON_ERROR) . ':' . self::encode($v);
            }
            return '{' . implode(',', $parts) . '}';
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(self::encode(...), $value)) . ']';
            }
            return self::encode((object)$value);
        }
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
