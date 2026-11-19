<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Cds\Physical;

use Alxarafe\Arp\Sql\SqlMetadata;
use Alxarafe\Arp\SqlServer\SqlServerMetadata;
use PDO;

final readonly class SqlServerObservationProvider implements PhysicalObservationProvider
{
    public function __construct(
        private \Closure $connect,
        private string $provider,
        private string $physicalId,
        private string $schema,
        private string $table,
        private int $timeoutSeconds = 5
    ) {
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('Positive timeout required');
        }
        foreach ([$provider,$physicalId,$schema,$table] as $name) {
            if ($name === '' || str_contains($name, "\0")) {
                throw new \InvalidArgumentException('Invalid registration');
            }
        }
    }
    public function providerId(): string
    {
        return $this->provider;
    }
    public function observe(): object
    {
        return SqlMetadata::observation($this->connect, 'sqlsrv', function (PDO $pdo): object {
            SqlServerMetadata::begin($pdo, $this->timeoutSeconds);
            SqlServerMetadata::protect($pdo, $this->schema, $this->table);
            $version = SqlMetadata::query($pdo, 'SELECT @@VERSION AS version')[0]['version'];
            $target = ' FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id ';
            $parameters = [$this->schema,$this->table];
            $checks = SqlMetadata::query($pdo, 'SELECT COUNT(*) AS n FROM sys.check_constraints ck '
                . 'JOIN sys.tables t ON t.object_id=ck.parent_object_id JOIN sys.schemas s ON s.schema_id=t.schema_id '
                . 'WHERE s.name=? AND t.name=?', $parameters)[0]['n'] !== '0';
            $facts = SqlMetadata::query($pdo, 'SELECT c.name,ty.name AS type_name,c.max_length,c.precision,c.scale,'
                . 'c.is_nullable,c.column_id,c.is_identity,c.is_computed,ty.is_user_defined,c.is_hidden' . $target
                . 'JOIN sys.columns c ON c.object_id=t.object_id JOIN sys.types ty ON ty.user_type_id=c.user_type_id '
                . 'WHERE s.name=? AND t.name=? ORDER BY c.column_id', $parameters);
            if (!$facts || count($facts) > 2048) {
                throw new ObservationException();
            }
            $columns = [];
            foreach ($facts as $fact) {
                if ($fact['is_hidden'] !== '0') {
                    throw new ObservationException();
                }
                $type = $fact['type_name'];
                $native = $type;
                $length = (int)$fact['max_length'];
                if (in_array($type, ['nvarchar','nchar','varchar','char'], true)) {
                    $native .= '(' . ($length === -1 ? 'max' : (string)intdiv(
                        $length,
                        str_starts_with($type, 'n') ? 2 : 1
                    )) . ')';
                }
                if (in_array($type, ['decimal','numeric'], true)) {
                    $native .= '(' . $fact['precision'] . ',' . $fact['scale'] . ')';
                }
                $scalar = $checks || $fact['is_user_defined'] !== '0' ? null : match ($type) {
                    'tinyint'=>'UINT8','smallint'=>'INT16','int'=>'INT32','bigint'=>'INT64','bit'=>'BOOLEAN',
                    'date'=>'DATE','decimal','numeric'=>'DECIMAL',
                    'varchar','nvarchar'=>$length === -1 ? 'TEXT' : 'STRING','char','nchar'=>'STRING',
                    'text','ntext'=>'TEXT',default=>null
                };
                $column = SqlMetadata::column(['name' => $fact['name'],'nativeType' => $native,
                    'ordinal' => $fact['column_id'],'nullable' => $fact['is_nullable'] === '1',
                    'identity' => $fact['is_identity'] === '1','generated' => $fact['is_computed'] === '1'], $scalar);
                if ($checks && $column->nullable) {
                    unset($column->nullable);
                }
                if (in_array($type, ['decimal','numeric'], true)) {
                    $column->decimalGeometry = (object)[
                    'precision' => (int)$fact['precision'],'scale' => (int)$fact['scale']];
                }
                $columns[] = $column;
            }
            $keys = SqlMetadata::query($pdo, 'SELECT c.name' . $target
                . 'JOIN sys.indexes i ON i.object_id=t.object_id AND i.is_primary_key=1 AND i.is_disabled=0 '
                . 'JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id '
                . 'JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id '
                . 'WHERE s.name=? AND t.name=? AND ic.key_ordinal>0 ORDER BY ic.key_ordinal', $parameters);
            $indexFacts = SqlMetadata::query($pdo, 'SELECT i.name,c.name AS column_name,i.is_unique,i.is_disabled,'
                . 'i.is_hypothetical,i.has_filter,i.type,ic.key_ordinal' . $target
                . 'JOIN sys.indexes i ON i.object_id=t.object_id AND i.index_id>0 '
                . 'JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id '
                . 'JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id '
                . 'WHERE s.name=? AND t.name=? AND ic.key_ordinal>0 ORDER BY i.index_id,ic.key_ordinal', $parameters);
            $unsupported = SqlMetadata::query($pdo, 'SELECT COUNT(*) AS n' . $target
                . 'JOIN sys.indexes i ON i.object_id=t.object_id AND i.index_id>0 '
                . 'WHERE s.name=? AND t.name=? AND (i.type NOT IN (1,2) OR i.is_disabled=1 '
                . 'OR i.is_hypothetical=1 OR i.has_filter=1)', $parameters)[0]['n'] !== '0';
            $indexes = [];
            foreach ($indexFacts as $fact) {
                if (
                    $fact['is_disabled'] !== '0' || $fact['is_hypothetical'] !== '0' || $fact['has_filter'] !== '0'
                    || !in_array($fact['type'], ['1','2'], true)
                ) {
                    $unsupported = true;
                    continue;
                }
                $indexes[$fact['name']] ??= (object)['name' => $fact['name'],'columns' => [],
                    'unique' => $fact['is_unique'] === '1'];
                $indexes[$fact['name']]->columns[] = $fact['column_name'];
            }
            $result = (object)['apiVersion' => 'arp.physical/v1','id' => $this->physicalId,'kind' => 'table',
                'source' => (object)['provider' => $this->provider,'dialect' => 'sqlserver','schema' => $this->schema,
                    'name' => $this->table,'engineVersion' => $version], 'columns' => $columns,
                'primaryKey' => array_column($keys, 'name')];
            if (!$unsupported) {
                $result->indexes = array_values($indexes);
            }
            return $result;
        });
    }
}
