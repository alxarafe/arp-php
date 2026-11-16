<?php
declare(strict_types=1);

use Alxarafe\Arp\Dictionary\CatalogBuilder;
use Alxarafe\Arp\Dictionary\Source;

require dirname(__DIR__) . '/vendor/autoload.php';

// Demo transport only. The library and its input/normalization rules remain unchanged.
function output(int $status, mixed $body, bool $raw = false): void
{
    $json = $raw ? $body : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo $json;
}

function problem(int $status, string $code): void
{
    output($status, ['valid' => false, 'issues' => [['code' => $code]]]);
}

// json_decode rejects invalid UTF-8 and unpaired surrogate escapes. Count root members separately
// because PHP's decoded objects collapse duplicates; .NET rejects duplicate root fields as shape errors.
function rootMembers(string $json): int
{
    $depth = 0;
    $quoted = false;
    $escaped = false;
    $count = 0;
    for ($i = 0, $size = strlen($json); $i < $size; $i++) {
        $char = $json[$i];
        if ($quoted) {
            if ($escaped) {
                $escaped = false;
            } else if ($char === '\\') {
                $escaped = true;
            } else if ($char === '"') {
                $quoted = false;
            }
        } else if ($char === '"') {
            $quoted = true;
        } else if ($char === '{' || $char === '[') {
            $depth++;
        } else if ($char === '}' || $char === ']') {
            $depth--;
        } else if ($char === ':' && $depth === 1) {
            $count++;
        }
    }
    return $count;
}

try {
    $origin = getenv('ARP_DEMO_ORIGIN') ?: 'http://127.0.0.1:4173';
    $parts = parse_url($origin);
    if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('ARP_DEMO_ORIGIN must be one exact http(s) origin');
    }
    $root = getenv('ARP_CONTRACT_PATH') ?: '/contract';
    $paths = ['fixtures/dictionary/v1/catalog.json', 'fixtures/dictionary/v1/sources/portable-equivalent.json'];
    $sources = [];
    $hashes = [];
    foreach ($paths as $path) {
        $bytes = file_get_contents($root . '/' . $path);
        if ($bytes === false) {
            throw new RuntimeException('Cannot read demo dictionary source');
        }
        $sources[] = new Source('arp-demo', $path, 'json', $bytes);
        $hashes[] = ['path' => $path, 'sha256' => hash('sha256', $bytes)];
    }
    $build = (new CatalogBuilder())->buildCatalog($sources);
    if (!$build->isValid()) {
        throw new RuntimeException('Demo dictionary sources are invalid');
    }
    $catalog = $build->catalog;
    if (PHP_SAPI === 'cli') {
        exit(0); // Startup preflight; fail before opening the HTTP port if sources/configuration are invalid.
    }
    $supplied = $_SERVER['HTTP_ORIGIN'] ?? null;
    $allowed = $origin === $supplied;
    if ($allowed) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'OPTIONS' && $supplied !== null && isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        if ($allowed) {
            header('Access-Control-Allow-Methods: GET,POST');
            header('Access-Control-Allow-Headers: Content-Type');
        }
        http_response_code(204);
        return;
    }
    if ($supplied !== null && !$allowed) {
        problem(403, 'DEMO_ORIGIN_NOT_ALLOWED');
        return;
    }
    $path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'], 2)[0]);
    $known = $path === '/demo/info' || $path === '/demo/dictionary/elements';
    $match = [];
    $element = preg_match('~^/demo/dictionary/elements/([^/]+)(/validate)?$~D', $path, $match) === 1;
    if (!$known && !$element) {
        problem(404, 'DEMO_HTTP_ERROR');
        return;
    }
    $validation = $element && isset($match[2]);
    if ($method !== ($validation ? 'POST' : 'GET')) {
        problem(405, 'DEMO_HTTP_ERROR');
        return;
    }
    if ($path === '/demo/info') {
        output(200, ['demoProtocol' => 'arp-demo-php/1', 'runtime' => 'php',
            'libraryContractRef' => trim(file_get_contents(dirname(__DIR__) . '/contract.ref')),
            'capabilities' => ['dictionary.list', 'dictionary.describe', 'dictionary.validate'], 'sources' => $hashes]);
    } else if ($path === '/demo/dictionary/elements') {
        $elements = [];
        foreach ($catalog->ids() as $id) {
            $descriptor = $catalog->resolve($id)['descriptor'];
            $elements[] = ['id' => $id, 'type' => $descriptor->type];
        }
        output(200, ['elements' => $elements]);
    } else if (!$validation) {
        $descriptor = $catalog->descriptorJson($match[1]);
        if ($descriptor === null) {
            output(404, $catalog->resolve($match[1]));
        } else {
            output(200, $descriptor, true); // Preserve exact numeric metadata, never json_encode ExactInteger.
        }
    } else {
        $media = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
        if ($media !== 'application/json' && !preg_match('~^application/[^/]+\+json$~D', $media)) {
            problem(415, 'DEMO_JSON_REQUIRED');
            return;
        }
        $stream = fopen('php://input', 'rb');
        $body = stream_get_contents($stream, 16_385);
        fclose($stream);
        if (strlen($body) > 16_384) {
            problem(413, 'DEMO_REQUEST_REJECTED');
            return;
        }
        try {
            $document = json_decode($body, false, 65, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            problem(400, 'DEMO_INVALID_JSON');
            return;
        }
        if (!$document instanceof stdClass || rootMembers($body) !== 1 || !property_exists($document, 'value')) {
            problem(400, 'DEMO_INPUT_SHAPE');
            return;
        }
        // JSON numeric kinds cannot succeed as scalar values; pass them unchanged for real type rejection.
        output($catalog->descriptorJson($match[1]) === null ? 404 : 200,
            $catalog->validate($match[1], $document->value));
    }
} catch (Throwable $failure) {
    error_log((string) $failure);
    if (PHP_SAPI === 'cli') {
        exit(1);
    }
    problem(500, 'DEMO_EXECUTION_FAILURE');
}