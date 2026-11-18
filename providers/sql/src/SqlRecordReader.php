<?php
declare(strict_types=1);
namespace Alxarafe\Arp\Sql;

use Alxarafe\Arp\Dictionary\Cds\{RecordReader,ReadResult,PreparedRead,ReadPlan,ReadAttempt,ReadFailure,ListGetHost};
use Alxarafe\Arp\Dictionary\Internal\Number;
use PDO;

/** One PDO algorithm; providers override protected SQL, codec and physical-protection details only. */
abstract class SqlRecordReader implements RecordReader
{
    protected string $identifier = '"';
    public function __construct(protected readonly \Closure $connect, protected readonly int $timeoutSeconds = 5)
    {
        if ($timeoutSeconds < 1) throw new \InvalidArgumentException('Positive timeout required');
    }
    protected static function need(bool $condition, string $code = 'CAPABILITY_UNAVAILABLE'): void
    {
        ReadFailure::need($condition, $code);
    }
    protected function quote(string $name): string
    {
        return $this->identifier.str_replace($this->identifier, $this->identifier.$this->identifier, $name)
            .$this->identifier;
    }
    final public function prepare(ReadResult $snapshot, string $projection, object $equalities): PreparedRead
    {
        $resolved = $snapshot->projection();
        $selected = ListGetHost::find($resolved->projections, $projection);
        $model = ListGetHost::find($resolved->models, $selected->entity);
        $physical = $model->physical;
        self::need($physical->kind === 'table' && !empty($physical->primaryKey) && isset($physical->indexes));
        $primary = array_values(array_filter($physical->indexes, static fn($index) =>
            $index->columns === $physical->primaryKey && $index->unique === true));
        self::need(count($primary) > 0);
        $fields = [];
        foreach ($model->fields as $field) $fields[$field->name] = $field;
        $keys = [];
        foreach ($physical->primaryKey as $name) {
            $field = $fields[$name] ?? null;
            self::need($field !== null && isset(Number::RANGES[$field->type ?? ''])
                && isset(Number::RANGES[$field->physical->scalarType ?? ''])
                && ($field->physical->nullable ?? null) === false);
            $keys[] = ['name'=>$name,'type'=>$field->type,'nullable'=>false];
        }
        $columns = [];
        foreach ($selected->fields as $field) {
            $stored = $fields[$field->name]->physical;
            $type = $field->type ?? '';
            self::need(isset(Number::RANGES[$type]) || in_array($type,
                ['DECIMAL','STRING','TEXT','BOOLEAN','DATE'], true));
            self::need(is_bool($stored->nullable ?? null));
            $column = ['name'=>$field->name,'type'=>$type,'nullable'=>$stored->nullable];
            self::need($this->supportsColumn($column, $stored));
            $columns[] = $column;
        }
        $restrictions = [];
        foreach ($equalities as $name => $value) {
            $field = $fields[$name] ?? null;
            self::need($field !== null && isset(Number::RANGES[$field->type ?? ''])
                && isset(Number::RANGES[$field->physical->scalarType ?? '']));
            $decoded = ListGetHost::scalar($field->type, $value);
            self::need($decoded['valid']);
            $restrictions[$name] = $decoded['normalizedValue'];
        }
        return new SqlPreparedRead($this, $physical, $columns, $keys, $restrictions);
    }
    protected function supportsColumn(array $column, object $physical): bool
    {
        return ($physical->scalarType ?? null) === $column['type']
            || isset(Number::RANGES[$physical->scalarType ?? ''], Number::RANGES[$column['type']]);
    }
    /** Factory must return a newly owned nonpersistent connection, never an application transaction. */
    protected function connection(): PDO
    {
        $connection = ($this->connect)();
        self::need($connection instanceof PDO && !$connection->inTransaction(), 'READ_FAILED');
        self::need(!$connection->getAttribute(PDO::ATTR_PERSISTENT)
            && (bool)$connection->getAttribute(PDO::ATTR_AUTOCOMMIT), 'READ_FAILED');
        self::need($connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION), 'READ_FAILED');
        self::need($connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false), 'READ_FAILED');
        self::need($connection->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true), 'READ_FAILED');
        return $connection;
    }
    protected function execute(PDO $connection, string $sql, array $parameters = []): array
    {
        $statement = $connection->prepare($sql);
        self::need($statement !== false, 'READ_FAILED');
        try {
            foreach ($parameters as $i => $value) self::need($statement->bindValue($i+1, $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR), 'READ_FAILED');
            self::need($statement->execute(), 'READ_FAILED');
            return $statement->columnCount() ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
        } finally { self::need($statement->closeCursor(), 'READ_FAILED'); }
    }
    protected abstract function begin(PDO $connection): void;
    protected abstract function protect(PDO $connection, object $physical): void;
    protected abstract function observe(object $physical, PDO $data): object;
    protected abstract function independent(PDO $data, PDO $guard): bool;
    protected function valueExpression(string $column): string { return $column; }
    protected function limitClause(): string { return ' FETCH FIRST ? ROWS ONLY'; }
    protected function portableValue(array $row, array $column): mixed { return $row[$column['name']]; }

    final public function open(ReadPlan $plan, \Closure $current): ReadAttempt
    {
        $prepared = $plan->source;
        self::need($prepared instanceof SqlPreparedRead && $prepared->owner === $this);
        self::need($plan->limit >= 1 && $plan->limit <= 100, 'INPUT_SHAPE');
        self::need($plan->position === null || count($plan->position) === count($prepared->keys), 'INVALID_KEY');
        $data = null; $guard = null;
        try {
            self::need($current(), 'GENERATION_CHANGED');
            $data = $this->connection();
            $this->begin($data);
            $physical = $prepared->physical();
            $this->protect($data, $physical);
            self::need($this->observe($physical, $data) == $physical, 'GENERATION_CHANGED');
            $rows = $this->readRows($data, $prepared, $plan, $current);
            $guard = $this->connection();
            self::need($data !== $guard && $this->independent($data, $guard), 'READ_FAILED');
            $this->begin($guard);
            $this->protect($guard, $physical);
            return new SqlReadAttempt($data, $guard, $rows);
        } catch (\Throwable $error) {
            $clean = true;
            foreach ([$data,$guard] as $connection) {
                try {
                    if ($connection?->inTransaction() && !$connection->rollBack()) $clean = false;
                } catch (\Throwable) { $clean = false; }
            }
            $connection = $data = $guard = null;
            if ($clean && $error instanceof ReadFailure) throw $error;
            throw new ReadFailure('READ_FAILED');
        }
    }
    private function readRows(PDO $data, SqlPreparedRead $prepared, ReadPlan $plan, \Closure $current): array
    {
        $columns = [];
        foreach ([...$prepared->columns,...$prepared->keys] as $column) $columns[$column['name']] = $column;
        $parameters = []; $predicates = [];
        foreach ($prepared->equalities as $name => $value) {
            $predicates[] = $this->quote((string)$name).' = ?'; $parameters[] = $value;
        }
        $keyNames = array_map(fn($c) => $this->quote($c['name']), $prepared->keys);
        if ($plan->position !== null) {
            $predicates[] = '('.implode(',', $keyNames).')'.($plan->get ? ' = (' : ' > (')
                .implode(',', array_fill(0, count($keyNames), '?')).')';
            array_push($parameters, ...$plan->position);
        }
        $physical = $prepared->physical();
        $relation = $this->quote($physical->source->schema).'.'.$this->quote($physical->source->name);
        $sql = 'SELECT '.implode(',', array_map(fn($c) => $this->valueExpression($this->quote($c['name'])),
            array_values($columns))).' FROM '.$relation
            .($predicates ? ' WHERE '.implode(' AND ', $predicates) : '')
            .' ORDER BY '.implode(',', array_map(static fn($k) => $relation.'.'.$k.' ASC', $keyNames))
            .$this->limitClause();
        $take = $plan->get ? 2 : $plan->limit+1; $parameters[] = $take;
        self::need($current(), 'GENERATION_CHANGED');
        $raw = $this->execute($data, $sql, $parameters);
        self::need(count($raw) <= $take, 'READ_FAILED');
        $result = [];
        foreach ($raw as $row) {
            $values = new \stdClass();
            foreach ($prepared->columns as $column) {
                self::need(array_key_exists($column['name'], $row), 'ROW_DECODE_FAILED');
                $value = $this->portableValue($row, $column);
                if ($value === null) self::need($column['nullable'], 'ROW_DECODE_FAILED');
                else {
                    $decoded = ListGetHost::scalar($column['type'], $value);
                    self::need($decoded['valid'], 'ROW_DECODE_FAILED');
                    $value = $decoded['normalizedValue'];
                }
                $values->{$column['name']} = $value;
            }
            $key = [];
            foreach ($prepared->keys as $column) {
                $decoded = ListGetHost::scalar($column['type'], $row[$column['name']] ?? null);
                self::need($decoded['valid'], 'ROW_IDENTITY_INVALID');
                $key[] = $decoded['normalizedValue'];
            }
            self::need(!$result || $result[count($result)-1]['key'] !== $key, 'ROW_IDENTITY_INVALID');
            $result[] = ['key'=>$key,'values'=>$values];
        }
        return $result;
    }
}