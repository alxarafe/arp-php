<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Alxarafe\Arp\Dictionary\{CatalogBuilder,Source};
use Alxarafe\Arp\Dictionary\Internal\Parser;
use Alxarafe\Arp\Dictionary\Cds\{CrudHost,WriteFailure};
use Alxarafe\Arp\Cds\Physical\{PhysicalCatalog,MariaDbObservationProvider};
use Alxarafe\Arp\MariaDb\{MariaDbRecordReader,MariaDbRecordWriter};

function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
}
function encode(mixed $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
function canonical(mixed $value): mixed
{
    if (is_object($value)) {
        $value = (array)$value;
    }
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value);
    }
    return array_map(canonical(...), $value);
}
$root = getenv('ARP_WRITE_CONTRACT_PATH') ?: throw new RuntimeException('Pinned write contract required');
$password = getenv('ARP_DB_PASSWORD') ?: throw new RuntimeException('Owned database required');
$connect = static fn() => new PDO(
    'mysql:host=db;dbname=arpcds;charset=utf8mb4',
    'root',
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES => false]
);
$admin = $connect();
$owner = getenv('ARP_CRUD_RUN_TOKEN') ?: throw new RuntimeException('Owned run token required');
check(
    hash_equals($owner, (string)$admin->query('SELECT token FROM __arp_crud_test_owner')->fetchColumn()),
    'owned database marker before any test DDL'
);
$other = $connect();
check(
    $admin->query('SELECT CONNECTION_ID()')->fetchColumn() !== $other->query('SELECT CONNECTION_ID()')->fetchColumn(),
    'independent sessions'
);
$admin->exec('DROP TABLE IF EXISTS item_links');
$admin->exec('DROP TABLE IF EXISTS items');
$admin->exec(file_get_contents($root . '/examples/demo/sql/mariadb.sql'));
$dictionary = (new CatalogBuilder())->buildCatalog([new Source(
    'demo',
    'dictionary',
    'yaml',
    file_get_contents($root . '/examples/demo/cds/dictionary.yaml')
)])->catalog;
$definitions = (object)['logical' => (object)['items' => Parser::parse(
    file_get_contents($root . '/examples/demo/cds/logical.yaml'),
    'yaml'
)], 'projections' => (object)[
    'public' => Parser::parse(file_get_contents($root . '/examples/demo/cds/public.yaml'), 'yaml'),
    'summary' => Parser::parse(file_get_contents($root . '/examples/demo/cds/summary.yaml'), 'yaml')]];
$catalog = new PhysicalCatalog($dictionary, $definitions, ['items' => new MariaDbObservationProvider(
    $connect,
    'primary',
    'demo.items',
    'arpcds',
    'items',
    2
)], true);
check($catalog->resolve()->valid, 'actual demo resolution: ' . $catalog->resolve()->toJson());
$targets = ['demo.items' => ['projection' => 'demo.items.public','fields' => ['id','code','rating'],
    'operations' => ['LIST','GET','CREATE','UPDATE','DELETE'],'defaultLimit' => 10,'maxLimit' => 100],
    'demo.summary' => ['projection' => 'demo.items.summary','fields' => ['id','code'],
    'operations' => ['LIST','GET'],'defaultLimit' => 10,'maxLimit' => 100]];
$allowed = true;
$writeFields = ['id','code','rating'];
$scope = new stdClass();
$ruleReject = false;
$readScope = null;
$hook = null;
$clock = 1000.0;
$authorizer = static function (
    $target,
    $operation,
    $context
) use (
    &$allowed,
    &$writeFields,
    &$scope,
    &$readScope
): array {
    return ['allowed' => $allowed,'fields' => ['id','code','rating'],
        'writableFields' => $writeFields,
        'equalities' => $operation === 'GET' && $readScope !== null ? $readScope : $scope];
};
$validator = static function ($operation, $candidate, $context, $changes) use (&$ruleReject, &$hook): bool {
    if ($hook !== null) {
        $call = $hook;
        $hook = null;
        $call();
    }
    return !$ruleReject && ($candidate->code ?? '') !== 'RESERVED';
};
$host = new CrudHost(
    $catalog,
    new MariaDbRecordReader($connect, 2),
    new MariaDbRecordWriter($connect, 2),
    $targets,
    $authorizer,
    $validator,
    300,
    static function () use (&$clock): float {
        return $clock;
    }
);
$context = ['identity' => 'demo-public','policyRevision' => 'p1'];
$key = (object)['components' => [(object)['field' => 'id','type' => 'INTEGER','value' => '7']]];
$invoke = static fn($operation, $inputs, $target = 'demo.items') => $host->invoke(encode(
    ['target' => $target,'operation' => $operation,'inputs' => $inputs]
), $context);
check($invoke('LIST', new stdClass())['output']['records'] === [], 'initial actual LIST');
$created = $invoke('CREATE', ['values' => ['id' => '7','code' => 'A-007','rating' => '5']]);
check($created['status'] === 'success', 'create: ' . encode($created));
$listed = $invoke('LIST', new stdClass());
check(count($listed['output']['records']) === 1, 'LIST after CREATE');
$summary = $invoke('LIST', new stdClass(), 'demo.summary');
check($summary['status'] === 'success'
    && !property_exists($summary['output']['records'][0]['values'], 'rating'), 'actual read-only derived LIST');
$read = $invoke('GET', ['key' => $key]);
check(($read['output']['record']['values']->rating ?? null) === '5', 'GET after create: ' . encode($read));
$updated = $invoke('UPDATE', ['key' => $key,'changes' => ['rating' => '6'],'baseline' => ['rating' => '5']]);
check($updated['status'] === 'success', 'update: ' . encode($updated));
$proof = $host->readForDelete('demo.items', $key, $context);
check($proof['status'] === 'success', 'read evidence: ' . encode($proof));
$deleted = $invoke('DELETE', ['key' => $key,'precondition' => $proof['output']['precondition']]);
check($deleted['status'] === 'success' && $deleted['output']['deleted'], 'delete: ' . encode($deleted));
check($invoke('GET', ['key' => $key])['output']['found'] === false, 'GET after delete');
check($invoke('LIST', new stdClass())['output']['records'] === [], 'LIST after DELETE');
echo "PASS vertical LIST/CREATE/GET/summary/UPDATE/read-evidence/DELETE/GET/LIST on actual MariaDB\n";

$seed = static function (array $rows = []) use ($admin): void {
    $admin->exec('DELETE FROM items');
    $statement = $admin->prepare('INSERT INTO items(id,code,rating) VALUES (?,?,?)');
    foreach ($rows as $row) {
        $statement->execute([$row['id'], $row['code'], $row['rating']]);
    }
};
$rows = static fn() => $admin->query('SELECT CAST(id AS CHAR) id,code,CAST(rating AS CHAR) rating'
    . ' FROM items ORDER BY items.id')->fetchAll(PDO::FETCH_ASSOC);
$vectors = json_decode(file_get_contents($root . '/fixtures/crud/write/v1/cases.json'), true, 512, JSON_THROW_ON_ERROR);
$shared = [];
$supported = [
    'create-valid','create-duplicate-key','create-required-id','create-required-code','create-required-rating',
    'create-null-id','create-null-code','create-null-rating','create-rating-range','create-id-overflow',
    'create-id-json-number','create-rating-boolean','create-code-too-long','create-code-empty',
    'create-unknown-field','create-hidden-field','create-field-not-authorized','create-denied','create-inactive',
    'create-summary-readonly',
    'create-rule-rejected','create-unicode-preserved','create-int32-exact-upper-bound','create-missing-inputs',
    'create-null-inputs','create-client-trust-context','create-unknown-target','create-negative-zero-canonical',
    'delete-valid','delete-absent','delete-no-precondition','delete-unknown-evidence',
    'delete-expired-evidence','delete-stale-precondition','delete-concurrent-change','delete-historical-value',
    'delete-rounded-key','delete-legacy-type-rejected','update-demo-partial','update-demo-independent-change',
    'update-demo-noop','update-demo-historical-omitted','update-demo-historical-resent','update-primary-key-immutable',
    'update-conflict-after-interleave'
];
foreach ($vectors['cases'] as $case) {
    if (!in_array($case['id'], $supported, true)) {
        $id = $case['id'];
        $reason = match (true) {
            str_contains($id, 'postgresql') => 'Another engine observation; first native runtime is PHP/MariaDB',
            str_starts_with($id, 'delegated-') => 'Application-owned specialized handler, outside managed host',
            str_contains($id, 'default') => 'Defaults not characterized by this provider profile',
            str_contains($id, 'opaque') || str_contains($id, 'hidden-key') || str_contains($id, 'get-key')
                => 'Mutation keys must be public typed PK components in this first runtime',
            str_contains($id, 'commit') || str_contains($id, 'uncertain') || str_contains($id, 'rollback')
                => 'Private fault instrumentation replaced by independent native fault evidence groups',
            default => 'Synthetic host/metadata phase not directly mapped; inspect native groups and limitations'
        };
        $shared[$id] = ['executed' => false,'reason' => $reason];
        continue;
    }
    $allowed = true;
    $writeFields = ['id','code','rating'];
    $scope = new stdClass();
    $ruleReject = false;
    $clock = 1000.0;
    $facts = $case['host'];
    $initialRows = $facts['rows'] ?? $vectors['defaults']['rows'];
    $seed($initialRows);
    $request = $case['request'];
    if (($request['operation'] ?? '') === 'DELETE' && isset($request['inputs']['precondition'])) {
        if (isset($initialRows[0])) {
            $before = $facts['preconditions']['delete-read-7']['state'] ?? $vectors['defaults']['preconditions']
                ['delete-read-7']['state'];
            $statement = $admin->prepare('UPDATE items SET code=?,rating=? WHERE id=7');
            $statement->execute([$before['code'], $before['rating']]);
        }
        $proof = $host->readForDelete('demo.items', $key, $context);
        // An absent row is represented using still-valid evidence from the prior authorized read.
        if (!isset($proof['output']['precondition'])) {
            $seed($vectors['defaults']['rows']);
            $proof = $host->readForDelete('demo.items', $key, $context);
        }
        if ($case['id'] !== 'delete-unknown-evidence') {
            $request['inputs']['precondition'] = $proof['output']['precondition'];
        }
        if ($case['id'] === 'delete-expired-evidence') {
            $clock += 301;
        }
        $seed($initialRows);
    }
    if (isset($facts['rowsAtBoundary'])) {
        // The other session commits after the baseline/read and before the managed operation.
        $other->beginTransaction();
        $statement = $other->prepare('UPDATE items SET code=?,rating=? WHERE id=7');
        $statement->execute([$facts['rowsAtBoundary'][0]['code'], $facts['rowsAtBoundary'][0]['rating']]);
        $other->commit();
    }
    $allowed = $facts['authorized'] ?? !isset($facts['active']);
    if (isset($facts['writableFields'])) {
        $writeFields = $facts['writableFields'];
    }
    $ruleReject = $facts['ruleReject'] ?? false;
    $caseHost = $host;
    if (($facts['projection'] ?? null) === 'demo.items.summary') {
        $restricted = $targets;
        $restricted['demo.items'] = $targets['demo.summary'];
        $caseHost = new CrudHost(
            $catalog,
            new MariaDbRecordReader($connect, 2),
            new MariaDbRecordWriter($connect, 2),
            $restricted,
            $authorizer,
            $validator
        );
    }
    $response = $caseHost->invoke(encode($request), $context);
    check(
        canonical($response) === canonical($case['expected']['response']),
        $case['id'] . ' response ' . encode([$response, $case['expected']['response']])
    );
    $rowSet = static function (array $values): array {
        $indexed = [];
        foreach ($values as $row) {
            $indexed[$row['id']] = $row;
        }
        check(count($indexed) === count($values), 'duplicate identity in persisted observation');
        ksort($indexed);
        return canonical($indexed);
    };
    check(
        $rowSet($rows()) === $rowSet($case['expected']['observed']['persisted']),
        $case['id'] . ' persisted rows ' . encode($rows())
    );
    $shared[$case['id']] = ['executed' => true,'passed' => true];
}
$allowed = true;
$scope = new stdClass();
$writeFields = ['id','code','rating'];
$ruleReject = false;
$clock = 1000.0;
echo 'PASS ' . count($supported) . " shared native cases, expected responses and actual persisted rows\n";

$seed([['id' => '7','code' => 'A-007','rating' => '5']]);
$proof = $host->readForDelete('demo.items', $key, $context);
$forged = $proof['output']['precondition'];
$forged['reference'][0] = $forged['reference'][0] === 'a' ? 'b' : 'a';
check($invoke('DELETE', ['key' => $key,'precondition' => $forged])['status'] === 'rejected', 'forged evidence');
$foreignContext = $context + ['scope' => 'other'];
check($host->invoke(encode(['target' => 'demo.items','operation' => 'DELETE',
    'inputs' => ['key' => $key,'precondition' => $proof['output']['precondition']]]), $foreignContext)['status']
    === 'rejected', 'cross-context evidence');
$other->exec("UPDATE items SET code='B' WHERE id=7");
$conflict = $invoke('DELETE', ['key' => $key,'precondition' => $proof['output']['precondition']]);
check($conflict['status'] === 'conflict' && count($rows()) === 1, 'independent DELETE conflict');
$update = ['key' => $key,'changes' => ['rating' => '6'],'baseline' => ['rating' => '5']];
check($invoke('UPDATE', $update)['status'] === 'success' && $rows()[0]['code'] === 'B', 'independent field preserved');
$other->exec('UPDATE items SET rating=7 WHERE id=7');
$confirmRequest = encode(['target' => 'demo.items','operation' => 'UPDATE','inputs' => $update]);
$display = $host->readForUpdateConfirmation($confirmRequest, $context);
check(
    $display['status'] === 'success' && $display['output']['record']['values']->rating === '7',
    'state shown before confirmation'
);
check($invoke('UPDATE', $update + ['confirmation' => $display['output']['challenge']['reference']])['status']
    === 'rejected', 'unconfirmed challenge cannot authorize overwrite');
$other->exec('UPDATE items SET rating=8 WHERE id=7');
$confirmation = $host->confirmUpdate($confirmRequest, $context, (object)$display['output']['challenge']);
check($confirmation['status'] === 'success', 'confirmation activates displayed state, never rereads it');
$confirmed = $invoke('UPDATE', $update + ['confirmation' => $confirmation['output']['confirmation']]);
check($confirmed['status'] === 'conflict', 'confirmation stale after other session commits');
$display = $host->readForUpdateConfirmation($confirmRequest, $context);
$other->exec("UPDATE items SET code='C' WHERE id=7");
$confirmation = $host->confirmUpdate($confirmRequest, $context, (object)$display['output']['challenge']);
check(
    $invoke('UPDATE', $update + ['confirmation' => $confirmation['output']['confirmation']])['status'] === 'success',
    'confirmed overwrite'
);
check($rows()[0]['code'] === 'C', 'confirmed field update preserves an independent modification');
$other->exec("UPDATE items SET code='B' WHERE id=7");
$hook = static function () use ($other): void {
    $other->exec('SET SESSION innodb_lock_wait_timeout=1');
    try {
        $other->exec('UPDATE items SET rating=9 WHERE id=7');
        throw new RuntimeException('Row lock was not held');
    } catch (PDOException $e) {
        check(($e->errorInfo[1] ?? null) === 1205, 'second session blocked by actual row lock');
    }
};
check($invoke('UPDATE', ['key' => $key,'changes' => ['rating' => '7'],'baseline' => ['rating' => '6']])['status']
    === 'success', 'two simultaneous sessions prove protected compare/write');
$hook = static function () use ($other): void {
    $other->exec('SET SESSION lock_wait_timeout=1');
    try {
        $other->exec('ALTER TABLE items ADD blocked_marker INT NULL');
        throw new RuntimeException('DDL escaped the managed transaction metadata lock');
    } catch (PDOException $e) {
        check(($e->errorInfo[1] ?? null) === 1205, 'independent DDL waits on real metadata protection');
    }
};
check($invoke('UPDATE', ['key' => $key,'changes' => ['rating' => '7'],'baseline' => ['rating' => '7']])['status']
    === 'success', 'metadata protection also covers no-op validation');
$hook = static function () use ($catalog): void {
    $catalog->invalidate();
};
$invalidated = $invoke('UPDATE', ['key' => $key,'changes' => ['rating' => '8'],'baseline' => ['rating' => '7']]);
check($invalidated['status'] === 'rejected' && $invalidated['diagnostics'][0]['code'] === 'GENERATION_EXPIRED'
    && $rows()[0]['rating'] === '7', 'catalog invalidation before DML is atomic and has no effects');
check($catalog->resolve()->valid, 'rebuild after controlled invalidation');
$hook = static function () use (&$allowed): void {
    $allowed = false;
};
check($invoke('UPDATE', ['key' => $key,'changes' => ['rating' => '8'],'baseline' => ['rating' => '7']])['status']
    === 'rejected', 'revocation inside transaction');
$allowed = true;
check($rows()[0]['rating'] === '7', 'revocation no persistence');
$writeFields = ['id','code'];
check($invoke('UPDATE', ['key' => $key,'changes' => ['rating' => '7'],'baseline' => ['rating' => '7']])['status']
    === 'rejected', 'no-op rechecks field permission');
$writeFields = ['id','code','rating'];
$readScope = (object)['rating' => '999'];
$privateConflict = $invoke('UPDATE', ['key' => $key,'changes' => ['rating' => '8'],
    'baseline' => ['rating' => '5']]);
check(
    $privateConflict['status'] === 'rejected' && !isset($privateConflict['conflicts']),
    'write permission does not grant current row read permission for conflict details'
);
$readScope = null;
$admin->exec('ALTER TABLE items ADD CONSTRAINT rating_checked CHECK(rating <= 8)');
$catalog->invalidate();
check(!$catalog->resolve()->valid, 'uncharacterized CHECK is not assumed compatible');
check(
    $invoke('UPDATE', $update)['status'] === 'rejected' && $rows()[0]['rating'] === '7',
    'CHECK capacity failure makes no writes'
);
$admin->exec('ALTER TABLE items DROP CONSTRAINT rating_checked');
$admin->exec('CREATE UNIQUE INDEX unique_item_code ON items(code)');
$admin->exec("INSERT INTO items VALUES(8,'TAKEN',5)");
$catalog->invalidate();
check($catalog->resolve()->valid, 'observed full unique index');
$bad = $invoke('UPDATE', ['key' => $key,'changes' => ['code' => 'TAKEN','rating' => '8'],
    'baseline' => ['code' => 'B','rating' => '7']]);
check($bad['diagnostics'][0]['code'] === 'CONSTRAINT_REJECTED' && $rows()[0]['code'] === 'B'
    && $rows()[0]['rating'] === '7', 'unique constraint rolls back the entire multi-column update');
$admin->exec('DELETE FROM items WHERE id=8');
$admin->exec('DROP INDEX unique_item_code ON items');
$catalog->invalidate();
$catalog->resolve();
$admin->exec('CREATE TABLE item_links(id INT PRIMARY KEY,item_id INT NOT NULL,
    FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE RESTRICT) ENGINE=InnoDB');
$admin->exec('INSERT INTO item_links VALUES(1,7)');
$proof = $host->readForDelete('demo.items', $key, $context);
$failure = $invoke('DELETE', ['key' => $key,'precondition' => $proof['output']['precondition']]);
check($failure['diagnostics'][0]['code'] === 'CONSTRAINT_REJECTED' && count($rows()) === 1, 'real FK RESTRICT');
$admin->exec('DROP TABLE item_links');
$admin->exec('CREATE TABLE item_links(id INT PRIMARY KEY,item_id INT NOT NULL,
    FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB');
check(
    $host->readForDelete('demo.items', $key, $context)['diagnostics'][0]['code'] === 'CAPABILITY_UNAVAILABLE',
    'existing cascades not silently executed'
);
$admin->exec('DROP TABLE item_links');
$admin->exec('CREATE TRIGGER unsafe_write BEFORE UPDATE ON items FOR EACH ROW SET NEW.code=NEW.code');
check($invoke('UPDATE', $update)['diagnostics'][0]['code'] === 'CAPABILITY_UNAVAILABLE', 'triggers unavailable');
$admin->exec('DROP TRIGGER unsafe_write');
$admin->exec('ALTER TABLE items ADD internal_note TEXT NULL');
$catalog->invalidate();
$proof = $host->readForDelete('demo.items', $key, $context);
check(!isset($proof['output']['record']['values']->internal_note), 'read evidence does not expose hidden fields');
$other->exec("UPDATE items SET internal_note='hidden-other' WHERE id=7");
$conflict = $invoke('DELETE', ['key' => $key,'precondition' => $proof['output']['precondition']]);
check($conflict['status'] === 'conflict' && !str_contains(encode($conflict), 'hidden-other')
    && !str_contains(encode($conflict), 'internal_note'), 'hidden concurrent change protected');
$admin->exec('ALTER TABLE items DROP internal_note');
$catalog->invalidate();
$catalog->resolve();
echo "PASS actual concurrent sessions, confirmation, row lock, revocation, CHECK, FK, cascades and hidden fields\n";

final class WriteTrace extends PDOStatement
{
    public static array $sql = [];
    public function execute(?array $params = null): bool
    {
        if (preg_match('/\A(?:INSERT|UPDATE|DELETE) /', $this->queryString)) {
            self::$sql[] = $this->queryString;
        }
        return parent::execute($params);
    }
}
class CommitFault extends PDO
{
    public string $mode = '';
    public ?Closure $after = null;
    public int $beginCalls = 0;
    public int $commitCalls = 0;
    public int $rollbackCalls = 0;
    public bool $probeFails = false;
    public function beginTransaction(): bool
    {
        ++$this->beginCalls;
        if ($this->mode === 'begin-throw') {
            throw new PDOException('private failure before beginning transaction');
        }
        $result = parent::beginTransaction();
        if ($this->mode === 'begin-ack-lost') {
            throw new PDOException('private begin acknowledgement lost before any action');
        }
        return $result;
    }
    public function inTransaction(): bool
    {
        if ($this->probeFails) {
            throw new PDOException('private transaction probe unavailable');
        }
        return parent::inTransaction();
    }
    public function commit(): bool
    {
        ++$this->commitCalls;
        if (in_array($this->mode, ['before','rollback-unproven'], true)) {
            throw new PDOException('private commit exception');
        }
        $result = parent::commit();
        ($this->after ?? static fn() => null)();
        if ($this->mode === 'after') {
            throw new PDOException('private acknowledgement lost after actual commit');
        }
        return $result;
    }
    public function rollBack(): bool
    {
        ++$this->rollbackCalls;
        $result = parent::rollBack();
        if ($this->mode === 'probe-after-close') {
            $this->probeFails = true;
        }
        if (in_array($this->mode, ['rollback-unproven','metadata-rollback-unproven'], true)) {
            throw new PDOException('private rollback acknowledgement lost');
        }
        return $result;
    }
}
$mode = '';
$after = null;
$counter = 0;
$dataId = 0;
$faultConnect = static function () use ($password, &$mode, &$counter, &$after, &$dataId): PDO {
    $connection = new CommitFault('mysql:host=db;dbname=arpcds;charset=utf8mb4', 'root', $password);
    if (++$counter === 1) {
        $dataId = (int)$connection->query('SELECT CONNECTION_ID()')->fetchColumn();
        $connection->mode = $mode;
        $connection->after = $after;
    }
    $connection->setAttribute(PDO::ATTR_STATEMENT_CLASS, [WriteTrace::class]);
    return $connection;
};
$faultHost = new CrudHost(
    $catalog,
    new MariaDbRecordReader($connect, 2),
    new MariaDbRecordWriter($faultConnect, 2),
    $targets,
    $authorizer,
    $validator
);
$failureResults = [];
foreach (
    ['before' => 'COMMIT_FAILED','after' => 'OUTCOME_UNKNOWN',
    'rollback-unproven' => 'OUTCOME_UNKNOWN'] as $fault => $expected
) {
    $seed([['id' => '7','code' => 'B','rating' => '5']]);
    $mode = $fault;
    $counter = 0;
    $response = $faultHost->invoke(encode(['target' => 'demo.items','operation' => 'UPDATE','inputs' => [
        'key' => $key,'changes' => ['rating' => '6'],'baseline' => ['rating' => '5']]]), $context);
    check(
        $response['status'] === 'failed' && $response['diagnostics'][0]['code'] === $expected,
        $fault . ' result ' . encode($response)
    );
    check($rows()[0]['rating'] === ($fault === 'after' ? '6' : '5'), $fault . ' actual database state');
    $failureResults[$fault] = ['response' => $response,'storedRating' => $rows()[0]['rating']];
}
$mode = '';
$counter = 0;
$after = static function () use (&$allowed): void {
    $allowed = false;
};
$response = $faultHost->invoke(encode(['target' => 'demo.items','operation' => 'UPDATE','inputs' => [
    'key' => $key,'changes' => ['rating' => '6'],'baseline' => ['rating' => '5']]]), $context);
check($response['status'] === 'failed' && $response['diagnostics'][0]['code'] === 'RESPONSE_UNAVAILABLE'
    && $rows()[0]['rating'] === '6', 'postcommit revocation does not claim rollback: ' . encode($response));
$allowed = true;
$after = null;
$counter = 0;
WriteTrace::$sql = [];
$response = $faultHost->invoke(encode(['target' => 'demo.items','operation' => 'UPDATE','inputs' => [
    'key' => $key,'changes' => ['rating' => '6'],'baseline' => ['rating' => '6']]]), $context);
check($response['output']['changed'] === false && WriteTrace::$sql === [], 'no-op emits zero DML statements');
$counter = 0;
WriteTrace::$sql = [];
$response = $faultHost->invoke(encode(['target' => 'demo.items','operation' => 'UPDATE','inputs' => [
    'key' => $key,'changes' => ['rating' => '7'],'baseline' => ['rating' => '6']]]), $context);
check($response['status'] === 'success' && count(WriteTrace::$sql) === 1
    && str_contains(WriteTrace::$sql[0], 'SET `rating` = ?')
    && !str_contains(WriteTrace::$sql[0], 'SET `code`'), 'only supplied changed columns are in SQL SET');
$counter = 0;
$hook = static function () use ($other, &$dataId): void {
    check($dataId > 0, 'owned DATA connection identity');
    $other->exec('KILL CONNECTION ' . $dataId);
};
$response = $faultHost->invoke(encode(['target' => 'demo.items','operation' => 'UPDATE','inputs' => [
    'key' => $key,'changes' => ['rating' => '8'],'baseline' => ['rating' => '7']]]), $context);
check($response['status'] === 'failed' && $response['diagnostics'][0]['code'] === 'OUTCOME_UNKNOWN'
    && $rows()[0]['rating'] === '7', 'actual DATA connection loss cannot acknowledge rollback');
$scope = (object)['rating' => '7'];
$proof = $host->readForDelete('demo.items', $key, $context);
$other->exec('UPDATE items SET rating=8 WHERE id=7');
$result = $invoke('DELETE', ['key' => $key,'precondition' => $proof['output']['precondition']]);
check(
    $result['status'] === 'success' && $result['output']['deleted'] === false && $rows()[0]['rating'] === '8',
    'row outside unchanged policy is indistinguishable from absence'
);
$scope = new stdClass();
$result = $invoke('CREATE', ['values' => ['id' => '9','code' => "a'--",'rating' => '5']]);
check($result['status'] === 'success' && $rows()[1]['code'] === "a'--", 'parameterized text does not become SQL');
$result = $invoke('CREATE', ['values' => ['id' => '10','code' => 'RESERVED','rating' => '5']]);
check(
    $result['diagnostics'][0]['code'] === 'BUSINESS_REJECTED' && count($rows()) === 2,
    'optional application-owned validator'
);
echo "PASS real rollback, actual commit with lost acknowledgement, uncertain rollback, publication and no-op DML\n";
// Faults run against owned real PDO connections; no alternate production fault API is added.
final class SetupFaultWriter extends MariaDbRecordWriter
{
    public ?WriteFailure $setupFailure = null;
    public string $point = 'before-begin';
    protected function execute(PDO $connection, string $sql, array $parameters = []): array
    {
        $before = $sql === 'SET TRANSACTION READ WRITE';
        $active = str_contains($sql, 'FROM information_schema.TRIGGERS');
        if ($this->setupFailure !== null && ($this->point === 'before-begin' ? $before : $active)) {
            throw $this->setupFailure;
        }
        return parent::execute($connection, $sql, $parameters);
    }
}
$stateData = null;
$stateMode = '';
$stateConnections = 0;
$stateConnect = static function () use ($password, &$stateData, &$stateMode, &$stateConnections): PDO {
    $connection = new CommitFault('mysql:host=db;dbname=arpcds;charset=utf8mb4', 'root', $password);
    if (++$stateConnections === 1) {
        $stateData = $connection;
        $stateData->mode = $stateMode;
    }
    $connection->setAttribute(PDO::ATTR_STATEMENT_CLASS, [WriteTrace::class]);
    return $connection;
};
$certaintyEvidence = [];
$beforeStateTests = $rows();
foreach (
    [
    ['before-begin-capability', 'before-begin', '', 'CAPABILITY_UNAVAILABLE', 'operation-not-allowed', 0, 0],
    ['before-begin-validation', 'before-begin', '', 'INVALID_VALUE', 'invalid-data', 0, 0],
    ['begin-failed-before-start', 'none', 'begin-throw', 'PERSISTENCE_FAILED', 'execution-failure', 1, 0],
    ['begin-ack-lost-without-action', 'none', 'begin-ack-lost', 'PERSISTENCE_FAILED', 'execution-failure', 1, 1],
    ['active-metadata-cleanup-unproven', 'active', 'metadata-rollback-unproven',
        'CAPABILITY_UNAVAILABLE', 'operation-not-allowed', 1, 1],
    ] as [$name, $point, $stateMode, $code, $family, $begins, $rollbacks]
) {
    $stateConnections = 0;
    WriteTrace::$sql = [];
    $writer = new SetupFaultWriter($stateConnect, 2);
    $writer->point = $point;
    $original = null;
    if ($point !== 'none') {
        $original = new WriteFailure($code, $family);
        $writer->setupFailure = $original;
    }
    $actions = 0;
    try {
        $writer->transaction(
            $catalog->resolve(),
            'demo.items.public',
            new stdClass(),
            static function ($tx) use (&$actions): array {
                ++$actions;
                return [];
            },
            static fn() => null
        );
        throw new RuntimeException($name . ' should fail');
    } catch (WriteFailure $error) {
        check($error->errorCode === $code && $error->family === $family, $name . ' preserves known diagnosis');
        if ($original !== null) {
            check($error === $original, $name . ' original exception is retained');
        }
        check($actions === 0 && $stateData->beginCalls === $begins && $stateData->rollbackCalls === $rollbacks
            && $stateData->commitCalls === 0 && WriteTrace::$sql === [], $name . ' no action or DML');
        $certaintyEvidence[$name] = ['response' => $error->response(),'beginCalls' => $begins,
            'rollbackCalls' => $rollbacks,'actionCalls' => $actions,'dmlCount' => count(WriteTrace::$sql)];
    }
}
foreach (
    [
    ['after-noop-capability', '', new WriteFailure('CAPABILITY_UNAVAILABLE')],
    ['after-noop-validation', '', new WriteFailure('INVALID_VALUE', 'invalid-data')],
    ['after-noop-access-revoked', '', new WriteFailure('UNAVAILABLE')],
    ['after-noop-probe-failed', 'probe-after-close', new WriteFailure('INVALID_VALUE', 'invalid-data')],
    ['after-noop-generic-failure', '', new RuntimeException('private publication failure')],
    ] as [$name, $stateMode, $original]
) {
    $stateConnections = 0;
    WriteTrace::$sql = [];
    $checks = 0;
    $actions = 0;
    try {
        (new MariaDbRecordWriter($stateConnect, 2))->transaction(
            $catalog->resolve(),
            'demo.items.public',
            new stdClass(),
            static function ($tx) use (&$actions): array {
                ++$actions;
                return ['changed' => false];
            },
            static function () use (&$checks, $original): void {
                if (++$checks === 3) {
                    throw $original;
                }
            }
        );
        throw new RuntimeException($name . ' should fail');
    } catch (WriteFailure $error) {
        if ($original instanceof WriteFailure) {
            check($error === $original, $name . ' retains original capability/validation diagnosis');
        } else {
            check($error->errorCode === 'PERSISTENCE_FAILED' && $error->family === 'execution-failure'
                && $error->status === 'failed', $name . ' is a known nonmutation failure');
        }
        check(
            $actions === 1 && $checks === 3 && $stateData->beginCalls === 1
            && $stateData->rollbackCalls === 1 && $stateData->commitCalls === 0 && WriteTrace::$sql === [],
            $name . ' completed without DML and never attempted commit'
        );
        $certaintyEvidence[$name] = ['response' => $error->response(),'beginCalls' => 1,
            'rollbackCalls' => 1,'actionCalls' => $actions,'dmlCount' => count(WriteTrace::$sql)];
    }
}
check($rows() === $beforeStateTests, 'certainty regressions leave actual persistence unchanged');
echo 'PASS ' . count($certaintyEvidence)
    . " explicit transaction certainty regressions; original diagnoses preserved\n";
$report = ['contract' => trim(file_get_contents(__DIR__ . '/../../write-contract.ref')),
    'engine' => $admin->query('SELECT VERSION()')->fetchColumn(),'php' => PHP_VERSION,
    'client' => $admin->getAttribute(PDO::ATTR_CLIENT_VERSION),
    'sharedCases' => $shared,'sharedPassed' => count($supported),'sharedTotal' => count($vectors['cases']),
    'failureEvidence' => $failureResults,'certaintyStatesEvidence' => $certaintyEvidence,
    'nativeGroups' => ['vertical-crud','independent-connections','row-lock','field-concurrency','confirmation',
        'authorization','business-validation','check-restriction','foreign-key','cascade-rejection',
        'trigger-rejection','hidden-column-concurrency','protected-read-evidence','rollback','unknown-commit',
        'unknown-rollback','connection-loss','postcommit-revocation','no-op-zero-dml','partial-set',
        'parameterized-values','certainty-states']];
if ($destination = getenv('ARP_WRITE_REPORT')) {
    file_put_contents($destination, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
echo 'PASS native matrix: ' . count($supported) . '/' . count($vectors['cases'])
    . ' shared vectors; ' . count($report['nativeGroups']) . " independent native evidence groups\n";
