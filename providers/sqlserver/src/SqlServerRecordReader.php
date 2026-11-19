<?php

declare(strict_types=1);

namespace Alxarafe\Arp\SqlServer;

use Alxarafe\Arp\Sql\{SqlRecordReader,SqlMetadata};
use Alxarafe\Arp\Cds\Physical\SqlServerObservationProvider;
use Alxarafe\Arp\Dictionary\Cds\ReadFailure;
use PDO;

class SqlServerRecordReader extends SqlRecordReader
{
    protected function connection(): PDO
    {
        return SqlMetadata::connection($this->connect, 'sqlsrv');
    }
    protected function quote(string $name): string
    {
        return SqlServerMetadata::quote($name);
    }
    protected function begin(PDO $connection): void
    {
        SqlServerMetadata::begin($connection, $this->timeoutSeconds);
    }
    protected function protect(PDO $connection, object $physical): void
    {
        self::need($physical->source->dialect === 'sqlserver');
        SqlServerMetadata::protect($connection, $physical->source->schema, $physical->source->name);
    }
    protected function independent(PDO $data, PDO $guard): bool
    {
        return $this->execute($data, 'SELECT @@SPID AS id')[0]['id']
            !== $this->execute($guard, 'SELECT @@SPID AS id')[0]['id'];
    }
    protected function observe(object $physical, PDO $data): object
    {
        $connect = function () use ($data): PDO {
            $connection = $this->connection();
            self::need($connection !== $data && $this->independent($data, $connection), 'READ_FAILED');
            return $connection;
        };
        return (new SqlServerObservationProvider(
            $connect,
            $physical->source->provider,
            $physical->id,
            $physical->source->schema,
            $physical->source->name,
            $this->timeoutSeconds
        ))->observe();
    }
    protected function limitClause(): string
    {
        return ' OFFSET 0 ROWS FETCH NEXT ? ROWS ONLY';
    }
    protected function keyComparison(array $keys, bool $get, array $position, array &$parameters): string
    {
        $terms = [];
        foreach ($keys as $i => $key) {
            if ($get) {
                $terms[] = $key . ' = ?';
                $parameters[] = $position[$i];
                continue;
            }
            $prefix = [];
            for ($j = 0; $j <= $i; ++$j) {
                $prefix[] = $keys[$j] . ($j === $i ? ' > ?' : ' = ?');
                $parameters[] = $position[$j];
            }
            $terms[] = '(' . implode(' AND ', $prefix) . ')';
        }
        return '(' . implode($get ? ' AND ' : ' OR ', $terms) . ')';
    }
    protected function valueExpression(string $column, string $type): string
    {
        $expression = match ($type) {
            'DATE'=>'CONVERT(nvarchar(max),' . $column . ',23)',
            'DECIMAL'=>'CASE WHEN ' . $column . ' IS NULL THEN NULL WHEN SQL_VARIANT_PROPERTY(' . $column
                . ",'BaseType') IN ('decimal','numeric') THEN CONVERT(nvarchar(max)," . $column
                . ") ELSE N'ARP_UNSUPPORTED_DECIMAL_STORAGE' END",
            default=>'CONVERT(nvarchar(max),' . $column . ')'
        };
        return $expression . ' AS ' . $column;
    }
    protected function portableValue(array $row, array $column): mixed
    {
        $value = $row[$column['name']];
        if ($value === null || $column['type'] !== 'BOOLEAN') {
            return $value;
        }
        return match ($value) {
            '0'=>false,'1'=>true,default=>throw new ReadFailure('ROW_DECODE_FAILED')
        };
    }
    protected function supportsColumn(array $column, object $physical): bool
    {
        return !str_starts_with($physical->nativeType, 'char(') && !str_starts_with($physical->nativeType, 'nchar(')
            && ($column['type'] === 'DECIMAL' && $physical->nativeType === 'sql_variant'
                || parent::supportsColumn($column, $physical));
    }
}
