<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
use Alxarafe\Arp\Dictionary\Cds\{ListGetHost,ReadCatalog,ReadResult,ReadPublication,
    RecordReader,PreparedRead,ReadPlan,ReadAttempt,ReadFailure};
use Alxarafe\Arp\Sql\SqlReadAttempt;

function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
}
final class MemoryCatalog implements ReadCatalog
{
    public ReadPublication $publication;
    public function __construct()
    {
        $this->publication = new ReadPublication();
        $this->resolve();
    }
    public function resolve(): ReadResult
    {
        if ($current = $this->current()) {
            return $current;
        }
        $ticket = $this->publication->beginGeneration();
        return $this->publication->publish($ticket, new ReadResult(['valid' => true,'issues' => [],
            'projections' => [(object)['id' => 'p','operations' => ['LIST','GET'],'fields' => [(object)['name' => 'name']]]]]));
    }
    public function current(): ?ReadResult
    {
        return $this->publication->current();
    }
    public function generation(): int
    {
        return $this->publication->generation();
    }
    public function publishRead(ReadResult $expected, Closure $publish): mixed
    {
        return $this->publication->publishRead($expected, $publish);
    }
}
final readonly class MemoryPrepared extends PreparedRead
{
    public function __construct()
    {
        parent::__construct(
            [['name' => 'name','type' => 'STRING','nullable' => false]],
            [['name' => 'id','type' => 'INTEGER','nullable' => false,
                'constraints' => ['bits' => 64,'signed' => true]]],
            []
        );
    }
}
final class MemoryReader implements RecordReader
{
    public ?Closure $onComplete = null;
    public ?Closure $onClose = null;
    public array $events = [];
    public function prepare(ReadResult $snapshot, string $projection, object $equalities): PreparedRead
    {
        return new MemoryPrepared();
    }
    public function open(ReadPlan $plan, Closure $current): ReadAttempt
    {
        check($current(), 'current');
        $this->events[] = 'open';
        $rows = [['key' => ['-9223372036854775808'],'values' => (object)['name' => 'min']],
            ['key' => ['9223372036854775807'],'values' => (object)['name' => 'max']]];
        if ($plan->get) {
            $rows = array_values(array_filter($rows, fn($r) => $r['key'] === $plan->position));
        }
        return new class ($this, $rows) implements ReadAttempt {
            public function __construct(private MemoryReader $owner, private array $batch)
            {
            }
            public function rows(): array
            {
                return $this->batch;
            }
            public function complete(): void
            {
                $this->owner->events[] = 'commit';
                ($this->owner->onComplete ?? static fn() => null)();
            }
            public function close(): void
            {
                $this->owner->events[] = 'close';
                ($this->owner->onClose ?? static fn() => null)();
            }
        };
    }
}
$catalog = new MemoryCatalog();
$reader = new MemoryReader();
$targets = ['t' => ['projection' => 'p','defaultLimit' => 1,'maxLimit' => 3,'fields' => ['name']]];
$allowed = true;
foreach ([[0,3],[4,3],[1,101]] as [$default,$maximum]) {
    $invalid = $targets;
    $invalid['t']['defaultLimit'] = $default;
    $invalid['t']['maxLimit'] = $maximum;
    $rejected = false;
    try {
        new ListGetHost($catalog, $reader, $invalid, static fn() => []);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    check($rejected, 'invalid server limits');
}
$restriction = (object)['tenant' => '7'];
$host = new ListGetHost(
    $catalog,
    $reader,
    $targets,
    static function () use (&$allowed, &$restriction): array {
        return ['allowed' => $allowed,'fields' => ['name'],'equalities' => $restriction];
    }
);
$context = ['identity' => 'principal/tenant','policyRevision' => '1'];
$invoke = fn($r, $c = null) => $host->invoke(is_string($r) ? $r : json_encode($r), $c ?? $context);
$list = ['target' => 't','operation' => 'LIST','inputs' => new stdClass()];
$first = $invoke($list);
check($first['status'] === 'success', 'LIST');
check($reader->events === ['open','commit','close'], 'mandatory commit and close');
$reference = $first['output']['records'][0]['key']['reference'];
check(strlen($reference) <= 128 && !str_contains($reference, '922337'), 'opaque token');
$get = ['target' => 't','operation' => 'GET','inputs' => ['key' => ['reference' => $reference]]];
check($invoke($get)['output']['record']['values']->name === 'min', 'signed INTEGER token roundtrip');
check($invoke($get, ['identity' => 'other','policyRevision' => '1'])['output'] === ['found' => false], 'context binding');
$wrong = $get;
$wrong['inputs']['key']['reference'] = $first['output']['page']['next'];
check($invoke($wrong)['output'] === ['found' => false], 'purpose binding');
foreach (
    ['{"target":"t","operation":"LIST","inputs":{"limit":true}}',
    '{"target":"t","operation":"LIST","inputs":null}',
    '{"target":"t","operation":"LIST","inputs":{"limit":0}}',
    '{"target":"t","operation":"LIST","inputs":{},"target":"t"}',
    '{"target":"t","operation":"LIST","inputs":{"fields":[]}}'] as $bad
) {
    check($invoke($bad)['diagnostics'][0]['code'] === 'CDS_LISTGET_INPUT_SHAPE', 'strict input');
}
check(
    $invoke('{"target":"t","operation":"LIST","inputs":{"limit":2e0}}')['status'] === 'success',
    'mathematical JSON integer'
);
$catalog->publication->invalidate();
check($invoke($get)['diagnostics'][0]['code'] === 'CDS_LISTGET_GENERATION_EXPIRED', 'expired reference');
$reader->onComplete = static function () use ($catalog): void {
    $catalog->publication->invalidate();
};
check(!isset($invoke($list)['output']), 'commit invalidation discards output');
$reader->onComplete = null;
$reader->onClose = static fn() => throw new ReadFailure('READ_FAILED');
check(!isset($invoke($list)['output']), 'cleanup failure discards output');
$reader->onClose = static function () use (&$allowed): void {
    $allowed = false;
};
check(!isset($invoke($list)['output']), 'cleanup callback revocation discards output');
$reader->onClose = null;
$allowed = true;
$reader->onComplete = static function () use ($host, $targets): void {
    $host->changeAccess($targets, static fn() => null);
};
check($invoke($list)['diagnostics'][0]['code'] === 'CDS_LISTGET_GENERATION_CHANGED', 'policy ABA epoch');

$restriction = (object)['z' => '1','tenant' => '7'];
$reader->onComplete = static function () use (&$restriction): void {
    $restriction = (object)['tenant' => '7','z' => '1'];
};
check($invoke($list)['status'] === 'success', 'policy map order is not semantic');
$restriction = (object)['tenant' => '7'];
$reader->onComplete = static function () use (&$restriction): void {
    $restriction = (object)['tenant' => 7];
};
check($invoke($list)['diagnostics'][0]['code'] === 'CDS_LISTGET_UNAVAILABLE', 'strict policy value types');
$restriction = (object)['tenant' => '7'];

final class TransactionDouble extends PDO
{
    public bool $transaction = true;
    public bool $failCommit = false;
    public bool $failRollback = false;
    public array $events = [];
    public function __construct()
    {
    }
    public function inTransaction(): bool
    {
        return $this->transaction;
    }
    public function commit(): bool
    {
        $this->events[] = 'commit';
        if ($this->failCommit) {
            return false;
        }
        $this->transaction = false;
        return true;
    }
    public function rollBack(): bool
    {
        $this->events[] = 'rollback';
        $this->transaction = false;
        return !$this->failRollback;
    }
}
foreach (['success','commit','guard'] as $mode) {
    $data = new TransactionDouble();
    $guard = new TransactionDouble();
    $data->failCommit = $mode === 'commit';
    $guard->failRollback = $mode === 'guard';
    $attempt = new SqlReadAttempt($data, $guard, []);
    $failed = false;
    try {
        $attempt->complete();
    } catch (ReadFailure) {
        $failed = true;
    }
    check($guard->transaction, 'GUARD survives DATA commit');
    try {
        $attempt->close();
    } catch (ReadFailure) {
        $failed = true;
    }
    check($failed === ($mode !== 'success'), 'checked PDO bool failures');
    check(!$data->transaction && !$guard->transaction, 'both transactions finalized');
}
echo "PASS neutral LIST/GET, exact tokens, authorization, generation, cleanup and PDO bool failures\n";
