<?php

declare(strict_types=1);

namespace Alxarafe\Arp\PostgreSql;

use Alxarafe\Arp\Sql\{SqlRecordReader,SqlMetadata};
use Alxarafe\Arp\Cds\Physical\PostgreSqlObservationProvider;
use Alxarafe\Arp\Dictionary\Cds\ReadFailure;
use PDO;

class PostgreSqlRecordReader extends SqlRecordReader
{
    protected function connection(): PDO
    {
        return SqlMetadata::connection($this->connect, 'pgsql');
    }
    protected function begin(PDO $connection): void
    {
        PostgreSqlMetadata::begin($connection, $this->timeoutSeconds);
    }
    protected function protect(PDO $connection, object $physical): void
    {
        self::need($physical->source->dialect === 'postgresql');
        PostgreSqlMetadata::protect($connection, $physical->source->schema, $physical->source->name);
    }
    protected function independent(PDO $data, PDO $guard): bool
    {
        return $this->execute($data, 'SELECT pg_backend_pid() AS id')[0]['id']
            !== $this->execute($guard, 'SELECT pg_backend_pid() AS id')[0]['id'];
    }
    protected function observe(object $physical, PDO $data): object
    {
        $connect = function () use ($data): PDO {
            $connection = $this->connection();
            self::need($connection !== $data && $this->independent($data, $connection), 'READ_FAILED');
            return $connection;
        };
        return (new PostgreSqlObservationProvider(
            $connect,
            $physical->source->provider,
            $physical->id,
            $physical->source->schema,
            $physical->source->name,
            $this->timeoutSeconds
        ))->observe();
    }
    protected function execute(PDO $connection, string $sql, array $parameters = []): array
    {
        // The inherited algorithm's bounded row SELECT is the only statement ending in this clause.
        // Skip escaped identifiers, including identifiers containing the word FROM, when adding ONLY.
        if (str_ends_with($sql, ' LIMIT ?')) {
            $sql = preg_replace('/"(?:[^"]|"")*"(*SKIP)(*F)| FROM /', ' FROM ONLY ', $sql, 1);
        }
        return parent::execute($connection, $sql, $parameters);
    }
    protected function valueExpression(string $column, string $type): string
    {
        return 'CAST(' . $column . ' AS text) AS ' . $column;
    }
    protected function limitClause(): string
    {
        return ' LIMIT ?';
    }
    protected function portableValue(array $row, array $column): mixed
    {
        $value = $row[$column['name']];
        if ($value === null || $column['type'] !== 'BOOLEAN') {
            return $value;
        }
        return match ($value) {
            'true' => true, 'false' => false, default => throw new ReadFailure('ROW_DECODE_FAILED')
        };
    }
    protected function supportsColumn(array $column, object $physical): bool
    {
        return !str_starts_with($physical->nativeType, 'character(') && parent::supportsColumn($column, $physical);
    }
}
