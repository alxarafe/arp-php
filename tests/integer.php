<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Alxarafe\Arp\Dictionary\{CatalogBuilder,Source};
use Alxarafe\Arp\Dictionary\Internal\{Integer,Number,Failure};

$count = 0;
function check(bool $ok, string $name): void
{
    global $count;
    if (!$ok) {
        throw new RuntimeException($name);
    }
    ++$count;
}
foreach (['INT8','UINT8','INT16','UINT16','INT32','UINT32','INT64','UINT64'] as $removed) {
    $result = (new CatalogBuilder())->buildCatalog([new Source('integer', 'removed', 'json', json_encode([
        'apiVersion' => 'arp.dictionary/v1','elements' => [['id' => 'test.removed','type' => $removed]]
    ], JSON_THROW_ON_ERROR))]);
    check(!$result->isValid() && $result->catalog === null
        && $result->issues[0]['code'] === 'DICTIONARY_ELEMENT_INVALID', 'no alias ' . $removed);
}
for ($a = -20; $a <= 20; ++$a) {
    for ($b = -20; $b <= 20; ++$b) {
        check(Number::add((string)$a, (string)$b) === (string)($a + $b), 'independent bounded signed addition');
    }
}
for ($minimum = -8; $minimum <= 8; ++$minimum) {
    for ($maximum = $minimum; $maximum <= 8; ++$maximum) {
        foreach ([1,2,3,5,17] as $multiple) {
            $possible = false;
            for ($value = $minimum; $value <= $maximum; ++$value) {
                $possible = $possible || $value % $multiple === 0;
            }
            try {
                Integer::derive(['minimum' => (string)$minimum,'maximum' => (string)$maximum,
                    'multipleOf' => (string)$multiple]);
                check($possible, 'nonempty multiple interval');
            } catch (Failure $error) {
                check(!$possible && $error->errorCode === 'DICTIONARY_ELEMENT_INVALID', 'empty multiple interval');
            }
        }
    }
}
foreach ([true,false] as $signed) {
    for ($value = $signed ? -128 : 0; $value <= ($signed ? 127 : 255); ++$value) {
        check(
            Integer::unpackKey(Integer::packKey((string)$value), $signed) === (string)$value,
            'exhaustive 8-bit token encoding'
        );
    }
}
foreach (
    [
    ['-9223372036854775808',true],['-1',true],['0',false],['9007199254740993',true],
    ['9007199254740994',true],['9223372036854775807',true],['18446744073709551614',false],
    ['18446744073709551615',false]
    ] as [$value,$signed]
) {
    check(Integer::unpackKey(Integer::packKey($value), $signed) === $value, 'exact 64-bit token ' . $value);
}
foreach (['-9223372036854775809','18446744073709551616'] as $value) {
    try {
        Integer::packKey($value);
        throw new RuntimeException('token overflow accepted');
    } catch (InvalidArgumentException) {
        check(true, 'token overflow rejected');
    }
}
$descriptor = (new CatalogBuilder())->buildCatalog([new Source(
    'integer',
    'inference',
    'json',
    '{"apiVersion":"arp.dictionary/v1","elements":[{"id":"test.inferred","type":"INTEGER",'
    . '"constraints":{"minimum":"0","maximum":"18446744073709551615"}}]}'
)])->catalog
    ->resolve('test.inferred')['descriptor'];
check(
    $descriptor->constraints->bits === 64 && $descriptor->constraints->signed === false,
    'catalog publishes inferred unsigned capacity'
);
check(Number::multiple('18446744073709551615', '3')
    && !Number::multiple('18446744073709551614', '3'), 'exact unsigned64 remainder');
foreach ([str_repeat('9', 4097), '-' . str_repeat('9', 4096), str_repeat('x', 4097)] as $value) {
    try {
        Number::parse($value, 'INTEGER');
        throw new RuntimeException('INTEGER resource budget bypassed');
    } catch (Failure $error) {
        check($error->errorCode === 'DICTIONARY_LIMIT_EXCEEDED', 'INTEGER character budget priority');
    }
}
echo "PASS INTEGER: $count independent arithmetic, definition and binary identity assertions\n";
