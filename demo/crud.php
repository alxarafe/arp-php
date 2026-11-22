<?php

declare(strict_types=1);

use Alxarafe\Arp\Cds\Physical\{MariaDbObservationProvider,PhysicalCatalog};
use Alxarafe\Arp\Dictionary\{CatalogBuilder,Source};
use Alxarafe\Arp\Dictionary\Cds\{CrudHost,ListGetHost};
use Alxarafe\Arp\Dictionary\Internal\{Parser,Projection};
use Alxarafe\Arp\MariaDb\{MariaDbRecordReader,MariaDbRecordWriter};

/** Server-owned composition; no client supplied connection, identifier or file path is used here. */
function crudComposition(): array
{
    $database = getenv('ARP_SEED_DATABASE') ?: '';
    if (preg_match('/\Aarp_seed_[A-Za-z0-9_]{1,54}\z/D', $database) !== 1) {
        throw new RuntimeException('A dedicated seed database is required');
    }
    $host = getenv('ARP_DB_HOST') ?: '127.0.0.1';
    $port = getenv('ARP_DB_PORT') ?: '3306';
    $user = getenv('ARP_DB_USER') ?: 'root';
    $password = getenv('ARP_DB_PASSWORD') ?: '';
    $connect = static fn(): PDO => new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => true, PDO::ATTR_PERSISTENT => false]
    );
    $pdo = $connect();
    if ($pdo->query('SELECT token FROM __arp_seed_owner LIMIT 1')->fetchColumn() !== 'arp.seed.demo/1') {
        throw new RuntimeException('Not a demo seed database');
    }
    $root = getenv('ARP_WRITE_CONTRACT_PATH') ?: getenv('ARP_CONTRACT_PATH');
    $base = $root . '/examples/demo/cds/';
    $dictionary = (new CatalogBuilder())->buildCatalog([
        new Source('demo', 'dictionary', 'yaml', file_get_contents($base . 'dictionary.yaml'))
    ])->catalog;
    if ($dictionary === null) {
        throw new RuntimeException('Invalid demo dictionary');
    }
    $definitions = (object)[
        'logical' => (object)['items' => Parser::parse(file_get_contents($base . 'logical.yaml'), 'yaml')],
        'projections' => (object)['public' => Parser::parse(file_get_contents($base . 'public.yaml'), 'yaml')]
    ];
    $catalog = new PhysicalCatalog($dictionary, $definitions, ['items' => new MariaDbObservationProvider(
        $connect,
        'primary',
        'demo.items',
        $database,
        'items',
        2
    )], true);
    $target = ['projection' => 'demo.items.public', 'fields' => ['id','code','rating'],
        'operations' => ['LIST','GET','CREATE','UPDATE','DELETE'], 'defaultLimit' => 10, 'maxLimit' => 100];
    $authorizer = static fn($target, $operation, $context): array => [
        'allowed' => $target === 'demo.items' && ($context['identity'] ?? '') === 'demo-local',
        'fields' => ['id','code','rating'], 'writableFields' => ['id','code','rating'],
        'equalities' => new stdClass()
    ];
    return [$catalog, new CrudHost(
        $catalog,
        new MariaDbRecordReader($connect, 2),
        new MariaDbRecordWriter($connect, 2),
        ['demo.items' => $target],
        $authorizer
    ), $target];
}

function crudResult(array $result): void
{
    output(match ($result['status']) {
        'success' => 200, 'conflict' => 409, 'failed' => 500, default => 422
    }, $result);
}

function crudRoute(string $path, string $method, bool $allowedOrigin): void
{
    header('Cache-Control: no-store');
    $token = getenv('ARP_DEMO_CRUD_TOKEN') ?: '';
    if (strlen($token) < 32 || !hash_equals('Bearer ' . $token, $_SERVER['HTTP_AUTHORIZATION'] ?? '')) {
        problem(403, 'DEMO_UNAUTHORIZED');
        return;
    }
    if (!in_array($path, ['/demo/crud/metadata','/demo/crud/invoke'], true)) {
        problem(404, 'DEMO_HTTP_ERROR');
        return;
    }
    if ($method !== ($path === '/demo/crud/metadata' ? 'GET' : 'POST')) {
        problem(405, 'DEMO_HTTP_ERROR');
        return;
    }
    if ($method === 'POST' && (!$allowedOrigin || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site')) {
        problem(403, 'DEMO_ORIGIN_REQUIRED');
        return;
    }
    $context = ['identity' => 'demo-local', 'policyRevision' => 'demo-http-1'];
    if ($method === 'GET') {
        [$catalog, , $registration] = crudComposition();
        $snapshot = $catalog->resolve();
        if (!$snapshot->valid) {
            output(503, $snapshot->projection());
            return;
        }
        $resolved = $snapshot->projection();
        $projection = ListGetHost::find($resolved->projections, $registration['projection']);
        $model = ListGetHost::find($resolved->models, $projection->entity);
        // Publish logical form data only, never physical database/connection metadata.
        $fields = [];
        foreach ($model->fields as $field) {
            if (in_array($field->name, $registration['fields'], true)) {
                $fields[] = ['name' => $field->name, 'type' => $field->type,
                    'constraints' => $field->constraints, 'label' => $field->label ?? $field->name,
                    'nullable' => $field->physical->nullable ?? false,
                    'key' => in_array($field->name, $model->physical->primaryKey, true)];
            }
        }
        output(200, ['demoProtocol' => 'arp-demo-php-crud/1', 'target' => 'demo.items',
            'projection' => $projection->id, 'operations' => $registration['operations'], 'fields' => $fields,
            'pagination' => ['defaultLimit' => 10, 'maxLimit' => 100, 'maxPage' => 100],
            'delete' => ['confirmation' => 'complete displayed values', 'privateEvidence' => false],
            'update' => ['baseline' => 'changed fields', 'onConflict' => 'reload']]);
        return;
    }
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0])) !== 'application/json') {
        problem(415, 'DEMO_JSON_REQUIRED');
        return;
    }
    $body = file_get_contents('php://input', false, null, 0, 65537);
    if ($body === false || strlen($body) > 65536) {
        problem(413, 'DEMO_REQUEST_REJECTED');
        return;
    }
    try {
        $request = Parser::parse($body, 'json');
        if (
            !$request instanceof stdClass || !isset($request->target, $request->operation, $request->inputs)
            || array_diff(array_keys((array)$request), ['target','operation','inputs'])
            || $request->target !== 'demo.items' || !is_string($request->operation)
            || !$request->inputs instanceof stdClass
        ) {
            throw new InvalidArgumentException();
        }
    } catch (Throwable) {
        problem(400, 'DEMO_INPUT_SHAPE');
        return;
    }
    [, $host] = crudComposition();
    $inputs = $request->inputs;
    if ($request->operation === 'DELETE') {
        if (
            array_diff(array_keys((array)$inputs), ['key','confirmedValues'])
            || !($inputs->key ?? null) instanceof stdClass
            || !($inputs->confirmedValues ?? null) instanceof stdClass
        ) {
            problem(400, 'DEMO_DELETE_CONFIRMATION_REQUIRED');
            return;
        }
        $read = $host->readForDelete('demo.items', $inputs->key, $context);
        if ($read['status'] !== 'success') {
            crudResult($read);
            return;
        }
        $actual = $read['output']['record']['values'] ?? null;
        $expected = (array)$inputs->confirmedValues;
        $actual = $actual === null ? null : (array)$actual;
        ksort($expected);
        if ($actual !== null) {
            ksort($actual);
        }
        // Exact parsed scalar kinds and all visible fields must match; no normalization of consent.
        if ($actual === null || Projection::encode((object)$actual) !== Projection::encode((object)$expected)) {
            output(409, ['status' => 'conflict', 'diagnostics' => [
                ['code' => 'DEMO_DELETE_CONCURRENT_CHANGE', 'family' => 'invalid-data']]]);
            return;
        }
        $request->inputs = (object)['key' => $inputs->key,
            'precondition' => (object)$read['output']['precondition']];
    } elseif ($request->operation === 'UPDATE' && property_exists($inputs, 'confirmation')) {
        problem(400, 'DEMO_UPDATE_RELOAD_REQUIRED');
        return;
    } elseif ($request->operation === 'LIST') {
        // Core cursors belong to one ListGetHost. Replay bounded pages in this request instead of exporting them.
        $page = $inputs->page ?? 1;
        if (
            !is_int($page) || $page < 1 || $page > 100
            || array_diff(array_keys((array)$inputs), ['limit','page'])
        ) {
            problem(400, 'DEMO_INPUT_SHAPE');
            return;
        }
        unset($inputs->page);
        for ($number = 1; $number <= $page; ++$number) {
            $result = $host->invoke(Projection::encode($request), $context);
            if ($result['status'] !== 'success') {
                crudResult($result);
                return;
            }
            $next = $result['output']['page']['next'];
            if ($number === $page || $next === null) {
                if ($number < $page) {
                    $result['output']['records'] = [];
                }
                $result['output']['page']['next'] = $next === null ? null : $page + 1;
                crudResult($result);
                return;
            }
            $inputs->continuation = $next;
        }
    }
    crudResult($host->invoke(Projection::encode($request), $context));
}
