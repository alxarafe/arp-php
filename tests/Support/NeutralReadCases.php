<?php

declare(strict_types=1);

namespace Arp\Tests;

/** One integrated replay body for every SQL reader; no engine branches or copied fixtures. */
final class NeutralReadCases
{
    private const IDS = ['get-unknown-reference','get-simple-typed-key','get-composite-typed-key',
        'get-missing-typed-key','get-raw-hidden-key-rejected','key-null','key-incomplete','key-reordered',
        'key-extra','key-number-not-string','key-wrong-type','key-out-of-range','list-limit-zero',
        'list-limit-negative','list-limit-bool','list-limit-fraction','list-limit-too-large','list-limit-null',
        'list-arbitrary-filter','list-arbitrary-sort','list-fields-injection','list-offset-rejected',
        'cursor-unknown','cursor-null','client-context-injection','input-null'];
    public static function replay(object $vectors, string $layout, \Closure $invoke): array
    {
        $responses = [];
        $ids = [];
        foreach ($vectors->cases as $vector) {
            if ($vector->layout !== $layout || !in_array($vector->id, self::IDS, true)) {
                continue;
            }
            $response = $invoke($vector);
            Contract::same($response, $vector->expected->response, 'integrated vector ' . $vector->id);
            $responses[] = ['vector' => $vector->id,'response' => $response];
            $ids[] = $vector->id;
        }
        return ['responses' => $responses,'ids' => $ids];
    }
}
