<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Sql;

use Alxarafe\Arp\Dictionary\Cds\{WriteTransaction,WriteFailure,ListGetHost};
use PDO;

/** @internal Only constructed after the provider has locked and verified registered physical metadata. */
final class SqlWriteTransaction implements WriteTransaction
{
    public int $writes = 0;
    public function __construct(
        private readonly PDO $connection,
        private readonly string $relation,
        private readonly array $columns,
        private readonly array $keys,
        private readonly array $scope,
        private readonly \Closure $quote
    ) {
    }
    private function execute(string $sql, array $values): \PDOStatement
    {
        $statement = $this->connection->prepare($sql);
        foreach (array_values($values) as $i => $value) {
            $statement->bindValue($i + 1, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
        $statement->execute();
        return $statement;
    }
    private function where(array $key, array &$values): string
    {
        WriteFailure::need(count($key) === count($this->keys), 'INVALID_KEY', 'invalid-data');
        $predicates = [];
        foreach (array_combine($this->keys, $key) as $name => $value) {
            $predicates[] = ($this->quote)($name) . ' = ?';
            $values[] = $value;
        }
        // A scope on a PK must not be lost through array union.
        foreach ($this->scope as $name => $value) {
            $predicates[] = ($this->quote)($name) . ' = ?';
            $values[] = $value;
        }
        return implode(' AND ', $predicates);
    }
    public function read(array $key): ?array
    {
        $parameters = [];
        $columns = array_map(fn($name) => 'CAST(' . ($this->quote)($name)
            . ' AS CHAR CHARACTER SET utf8mb4) AS ' . ($this->quote)($name), array_keys($this->columns));
        $statement = $this->execute('SELECT ' . implode(',', $columns) . ' FROM ' . $this->relation
            . ' WHERE ' . $this->where($key, $parameters) . ' FOR UPDATE', $parameters);
        try {
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 1) {
                throw new WriteFailure('ROW_IDENTITY_INVALID', 'execution-failure', 'failed');
            }
            if (!$rows) {
                return null;
            }
            $result = [];
            foreach ($this->columns as $name => $column) {
                $raw = $rows[0][$name];
                if ($raw === null) {
                    if (!$column->nullable) {
                        throw new WriteFailure('ROW_DECODE_FAILED', 'execution-failure', 'failed');
                    }
                    $result[$name] = null;
                } else {
                    $decoded = ListGetHost::scalar(
                        $column->scalarType,
                        $raw,
                        $column->scalarType === 'INTEGER' ? (object)[
                            'minimum' => $column->integerRange->minimum,
                            'maximum' => $column->integerRange->maximum
                        ] : []
                    );
                    if (!$decoded['valid']) {
                        throw new WriteFailure('ROW_DECODE_FAILED', 'execution-failure', 'failed');
                    }
                    $result[$name] = $decoded['normalizedValue'];
                }
            }
            return $result;
        } finally {
            $statement->closeCursor();
        }
    }
    private function mutate(string $sql, array $values): void
    {
        $statement = $this->execute($sql, $values);
        try {
            if ($statement->rowCount() !== 1) {
                throw new WriteFailure('PERSISTENCE_FAILED', 'execution-failure', 'failed');
            }
            ++$this->writes;
        } finally {
            $statement->closeCursor();
        }
    }
    private function names(array $values): array
    {
        WriteFailure::need(
            !array_diff(array_keys($values), array_keys($this->columns)),
            'INVALID_VALUE',
            'invalid-data'
        );
        return array_map($this->quote, array_keys($values));
    }
    public function insert(array $values): void
    {
        $names = $this->names($values);
        $this->mutate('INSERT INTO ' . $this->relation . ' (' . implode(',', $names) . ') VALUES ('
            . implode(',', array_fill(0, count($values), '?')) . ')', $values);
    }
    public function update(array $key, array $changes): void
    {
        if (!$changes) {
            return;
        }
        $names = $this->names($changes);
        $parameters = array_values($changes);
        $where = $this->where($key, $parameters);
        $this->mutate('UPDATE ' . $this->relation . ' SET '
            . implode(',', array_map(static fn($name) => $name . ' = ?', $names)) . ' WHERE ' . $where, $parameters);
    }
    public function delete(array $key): void
    {
        $parameters = [];
        $where = $this->where($key, $parameters);
        $this->mutate('DELETE FROM ' . $this->relation . ' WHERE ' . $where, $parameters);
    }
}
