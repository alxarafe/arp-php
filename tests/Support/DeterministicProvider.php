<?php

declare(strict_types=1);

namespace Arp\Tests;

use Alxarafe\Arp\Dictionary\Cds\{RecordReader,RecordWriter,WriteTransaction,ReadResult,PreparedRead,
    ReadPlan,ReadAttempt,ReadFailure,WriteFailure,ListGetHost};
use Alxarafe\Arp\Dictionary\Internal\{Number,Integer};

final readonly class MemoryPrepared extends PreparedRead
{
    public function __construct(public object $model, array $columns, array $keys, array $equalities)
    {
        parent::__construct($columns, $keys, $equalities);
    }
}

/** Test-only finite store, not a SQL emulator. All events/decisions precede expected-output comparison. */
final class DeterministicProvider implements RecordReader, RecordWriter
{
    public array $rows = [];
    public array $events = [];
    public array $written = [];
    public ?\Closure $boundary = null;
    public ?\Closure $publish = null;
    public bool $capable = true;
    public bool $hideRecords = false;
    public bool $incompatible = false;
    public bool $constraintRejected = false;
    public bool $readFailure = false;
    public array $decodeFailures = [];
    public string $fault = '';
    public bool $unknownCommitted = false;
    public int $reads = 0;
    public int $writes = 0;
    public function prepare(ReadResult $snapshot, string $projection, object $equalities): PreparedRead
    {
        ReadFailure::need($this->capable, 'CAPABILITY_UNAVAILABLE');
        $result = $snapshot->projection();
        $selected = ListGetHost::find($result->projections, $projection);
        $model = ListGetHost::find($result->models, $selected->entity);
        $fields = array_column($model->fields, null, 'name');
        $keys = [];
        foreach ($model->physical->primaryKey ?? [] as $name) {
            $field = $fields[$name];
            ReadFailure::need(($field->type ?? '') === 'INTEGER'
                && ($field->physical->nullable ?? null) === false, 'CAPABILITY_UNAVAILABLE');
            $keys[] = ['name' => $name,'type' => 'INTEGER','nullable' => false,
                'constraints' => (array)Integer::capacity($field->constraints)];
        }
        ReadFailure::need((bool)$keys, 'CAPABILITY_UNAVAILABLE');
        $columns = [];
        foreach ($selected->fields as $field) {
            $stored = $fields[$field->name];
            $columns[] = ['name' => $field->name,'type' => $field->type,
                'nullable' => $stored->physical->nullable ?? false,
                'constraints' => $field->type === 'INTEGER' ? (array)Integer::capacity($field->constraints) : []];
        }
        return new MemoryPrepared($model, $columns, $keys, (array)$equalities);
    }
    private static function matches(array $row, array $equalities): bool
    {
        foreach ($equalities as $name => $value) {
            if (($row[$name] ?? null) !== $value) {
                return false;
            }
        }
        return true;
    }
    public static function compareKeys(array $a, array $b): int
    {
        foreach ($a as $i => $value) {
            $cmp = Number::compare($value, $b[$i]);
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        return 0;
    }
    public function open(ReadPlan $plan, \Closure $current): ReadAttempt
    {
        ReadFailure::need($current(), 'GENERATION_CHANGED');
        ++$this->reads;
        $this->events[] = 'read';
        ReadFailure::need(!$this->readFailure, 'READ_FAILED');
        $prepared = $plan->source;
        $ordered = [];
        foreach ($this->rows as $index => $row) {
            if (!self::matches($row, $prepared->equalities)) {
                continue;
            }
            $key = [];
            foreach ($prepared->keys as $column) {
                $decoded = ListGetHost::scalar($column['type'], $row[$column['name']] ?? null, $column['constraints']);
                ReadFailure::need($decoded['valid'], 'ROW_IDENTITY_INVALID');
                $key[] = $decoded['normalizedValue'];
            }
            $ordered[] = ['key' => $key,'row' => $row,'index' => $index];
        }
        usort($ordered, static fn($a, $b) => self::compareKeys($a['key'], $b['key']));
        for ($i = 1; $i < count($ordered); ++$i) {
            ReadFailure::need($ordered[$i - 1]['key'] !== $ordered[$i]['key'], 'ROW_IDENTITY_INVALID');
        }
        $selected = array_filter($ordered, static fn($row) => $plan->position === null
            || ($plan->get ? $row['key'] === $plan->position : self::compareKeys($row['key'], $plan->position) > 0));
        $selected = array_slice(array_values($selected), 0, $plan->get ? 2 : $plan->limit + 1);
        $rows = [];
        foreach ($selected as $item) {
            ReadFailure::need(!in_array($item['index'], $this->decodeFailures, true), 'ROW_DECODE_FAILED');
            $values = new \stdClass();
            foreach ($prepared->columns as $column) {
                ReadFailure::need(array_key_exists($column['name'], $item['row']), 'ROW_DECODE_FAILED');
                $raw = $item['row'][$column['name']];
                if ($raw === null) {
                    ReadFailure::need($column['nullable'], 'ROW_DECODE_FAILED');
                    $values->{$column['name']} = null;
                } else {
                    $decoded = ListGetHost::scalar($column['type'], $raw, $column['constraints']);
                    ReadFailure::need($decoded['valid'], 'ROW_DECODE_FAILED');
                    $values->{$column['name']} = $decoded['normalizedValue'];
                }
            }
            $rows[] = ['key' => $item['key'],'values' => $values];
        }
        return new class ($this, $rows) implements ReadAttempt {
            public function __construct(private DeterministicProvider $store, private array $rows)
            {
            }
            public function rows(): array
            {
                return $this->rows;
            }
            public function complete(): void
            {
                $this->store->events[] = 'complete';
                ($this->store->publish ?? static fn() => null)();
            }
            public function close(): void
            {
                $this->store->events[] = 'close';
            }
        };
    }
    public function transaction(
        ReadResult $snapshot,
        string $projection,
        object $equalities,
        \Closure $action,
        \Closure $current
    ): mixed {
        WriteFailure::need($this->capable, 'CAPABILITY_UNAVAILABLE');
        WriteFailure::need(!$this->incompatible, 'INCOMPATIBLE_STORAGE', 'physical-incompatible');
        ($this->boundary ?? static fn() => null)();
        $current();
        $this->events[] = 'begin-simulated';
        $result = $snapshot->projection();
        $selected = ListGetHost::find($result->projections, $projection);
        $model = ListGetHost::find($result->models, $selected->entity);
        foreach ($model->fields as $field) {
            if (
                $field->type === 'STRING' && isset($field->constraints->maximumLength)
                && $field->constraints->maximumLength > ($field->physical->characterLength->value ?? PHP_INT_MAX)
            ) {
                throw new WriteFailure('INCOMPATIBLE_STORAGE', 'physical-incompatible');
            }
        }
        $transaction = new MemoryTransaction(
            $this->rows,
            $model->physical->primaryKey,
            (array)$equalities,
            $this->hideRecords
        );
        try {
            $output = $action($transaction);
            $current();
            if ($transaction->written && $this->constraintRejected) {
                throw new WriteFailure('CONSTRAINT_REJECTED', 'invalid-data');
            }
            if ($transaction->written && $this->fault !== '') {
                $unknown = in_array($this->fault, ['commit-unknown','rollback-unproven'], true);
                if ($unknown && $this->unknownCommitted) {
                    $this->rows = $transaction->rows;
                    ++$this->writes;
                    $this->written = $transaction->written;
                }
                throw new WriteFailure(
                    $unknown ? 'OUTCOME_UNKNOWN'
                    : ($this->fault === 'commit-rolled-back' ? 'COMMIT_FAILED' : 'PERSISTENCE_FAILED'),
                    'execution-failure',
                    'failed'
                );
            }
        } catch (\Throwable $error) {
            $this->events[] = 'rollback-simulated';
            if ($error instanceof WriteFailure) {
                throw $error;
            }
            throw new WriteFailure('PERSISTENCE_FAILED', 'execution-failure', 'failed');
        }
        $this->rows = $transaction->rows;
        if ($transaction->written) {
            ++$this->writes;
            $this->written = $transaction->written;
        }
        $this->events[] = 'commit-simulated';
        ($this->publish ?? static fn() => null)();
        try {
            $current();
        } catch (WriteFailure $error) {
            if ($transaction->written) {
                throw new WriteFailure('RESPONSE_UNAVAILABLE', 'execution-failure', 'failed');
            }
            throw $error;
        }
        return $output;
    }
}

final class MemoryTransaction implements WriteTransaction
{
    public array $written = [];
    public function __construct(
        public array $rows,
        private array $keys,
        private array $scope,
        private bool $hidden = false
    ) {
    }
    private function index(array $key, bool $scoped = true): ?int
    {
        foreach ($this->rows as $i => $row) {
            if (array_map(static fn($name) => $row[$name], $this->keys) === $key) {
                foreach ($scoped ? $this->scope : [] as $name => $value) {
                    if (($row[$name] ?? null) !== $value) {
                        continue 2;
                    }
                }
                return $i;
            }
        }
        return null;
    }
    public function read(array $key): ?array
    {
        $index = $this->index($key);
        if ($index === null || $this->hidden) {
            return null;
        }
        $row = $this->rows[$index];
        ksort($row, SORT_STRING);
        return $row;
    }
    public function insert(array $values): void
    {
        $key = array_map(static fn($name) => $values[$name], $this->keys);
        WriteFailure::need($this->index($key, false) === null, 'CONSTRAINT_REJECTED', 'invalid-data');
        $this->rows[] = $values;
        $this->written = array_keys($values);
    }
    public function update(array $key, array $changes): void
    {
        if (!$changes) {
            return;
        }
        $index = $this->index($key);
        WriteFailure::need($index !== null, 'PERSISTENCE_FAILED', 'execution-failure');
        $this->rows[$index] = array_replace($this->rows[$index], $changes);
        $this->written = array_keys($changes);
    }
    public function delete(array $key): void
    {
        $index = $this->index($key);
        WriteFailure::need($index !== null, 'PERSISTENCE_FAILED', 'execution-failure');
        array_splice($this->rows, $index, 1);
        $this->written = ['__delete'];
    }
}
