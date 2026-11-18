<?php
declare(strict_types=1);
namespace Alxarafe\Arp\Cds\Physical;

use Alxarafe\Arp\Dictionary\Internal\Number;
use PDO;
use PDOException;

/** Optional PDO MySQL adapter. pdo_mysql is needed only by consumers that instantiate this provider. */
abstract readonly class MySqlObservationProviderBase implements PhysicalObservationProvider
{
    public function __construct(private \Closure $connect, private string $provider,
        private string $physicalId, private string $schema, private string $table, private int $timeoutSeconds = 10)
    {
        if ($timeoutSeconds < 1) throw new \InvalidArgumentException('Positive timeout required');
        foreach ([$provider,$physicalId,$schema,$table] as $value) {
            if ($value === '' || str_contains($value,
                "\0")) throw new \InvalidArgumentException('Invalid registration');
        }
    }

    protected function mariaDb(): bool { return false; }
    public function providerId(): string { return $this->provider; }
    private static function quote(string $name): string { return '`'.str_replace('`', '``', $name).'`'; }

    public function observe(): object
    {
        $connection = null;
        try {
            $candidate = ($this->connect)();
            if (!$candidate instanceof PDO || $candidate->inTransaction()
                || $candidate->getAttribute(PDO::ATTR_PERSISTENT)) throw new ObservationException();
            $connection = $candidate; $candidate = null;
            if (!$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)) throw new ObservationException();
            if (!$connection->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true)) throw new ObservationException();
            if (!$connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false)) throw new ObservationException();
            $version = $this->rows($connection, 'SELECT VERSION() AS version', [])[0]['version'];
            if (!is_string($version) || ($this->mariaDb() ? !str_contains($version, 'MariaDB')
                : str_contains($version, 'MariaDB') || !str_starts_with($version, '8.4.'))) {
                throw new ObservationException();
            }
            $this->rows($connection, 'SET SESSION lock_wait_timeout = '.$this->timeoutSeconds, []);
            $this->rows($connection, $this->mariaDb() ? 'SET SESSION max_statement_time = '.$this->timeoutSeconds
                : 'SET SESSION max_execution_time = '.($this->timeoutSeconds*1000), []);
            $this->rows($connection, 'SET TRANSACTION READ ONLY', []);
            if (!$connection->beginTransaction()) throw new ObservationException();
            // InnoDB metadata lock remains until commit; no application rows are read or returned.
            $this->rows($connection, 'SELECT * FROM '.self::quote($this->schema).'.'.self::quote($this->table)
                .' LIMIT 0', []);
            // Keep table metadata authority explicit, independently of wildcard expansion and invisible columns.
            $tableSql = self::quote($this->schema).'.'.self::quote($this->table);
            $this->rows($connection, 'SHOW CREATE TABLE '.$tableSql, []);
            $rows = $this->rows($connection, 'SELECT TABLE_TYPE, TABLE_SCHEMA, TABLE_NAME FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
            if (count($rows) !== 1 || $rows[0]['TABLE_TYPE'] !== 'BASE TABLE') throw new ObservationException();
            if (!$this->mariaDb()) {
                $account = $this->rows($connection, 'SELECT CURRENT_USER() AS account', [])[0]['account'];
                $separator = strrpos($account, '@');
                if ($separator === false) throw new ObservationException();
                $grantee = "'".substr($account, 0, $separator)."'@'".substr($account, $separator+1)."'";
                $grants = $this->rows($connection, 'SELECT COUNT(*) AS n FROM ('
                    ."SELECT GRANTEE FROM information_schema.USER_PRIVILEGES WHERE PRIVILEGE_TYPE='SELECT' "
                    ."UNION ALL SELECT GRANTEE FROM information_schema.SCHEMA_PRIVILEGES WHERE PRIVILEGE_TYPE='SELECT' "
                    .'AND TABLE_SCHEMA=? UNION ALL SELECT GRANTEE FROM information_schema.TABLE_PRIVILEGES '
                    ."WHERE PRIVILEGE_TYPE='SELECT' AND TABLE_SCHEMA=? AND TABLE_NAME=?) g WHERE GRANTEE=?",
                    [$this->schema,$this->schema,$this->table,$grantee]);
                if ($grants[0]['n'] === '0') throw new ObservationException();
            }
            $observedSchema = $rows[0]['TABLE_SCHEMA'];
            $observedName = $rows[0]['TABLE_NAME'];
            $rows = $this->rows($connection, 'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE,
                ORDINAL_POSITION, EXTRA, '.($this->mariaDb() ? 'IS_GENERATED'
                    : "CASE WHEN GENERATION_EXPRESSION <> '' THEN 'ALWAYS' ELSE 'NEVER' END AS IS_GENERATED").',
                CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE
                FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
            if (!$rows || count($rows) > 2048) throw new ObservationException();
            $constraints = $this->rows($connection, 'SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE
                FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
            $hasChecks = (bool)array_filter($constraints, static fn($c) => $c['CONSTRAINT_TYPE'] === 'CHECK');
            $columns = [];
            foreach ($rows as $row) {
                if (!in_array($row['IS_NULLABLE'], ['YES','NO'], true)
                    || !in_array($row['IS_GENERATED'], ['ALWAYS','NEVER'], true)
                    || $row['COLUMN_NAME'] === '' || $row['COLUMN_TYPE'] === '') throw new ObservationException();
                $column = (object)['name'=>$row['COLUMN_NAME'],'nativeType'=>$row['COLUMN_TYPE'],
                    'ordinal'=>(int)$row['ORDINAL_POSITION'],'nullable'=>$row['IS_NULLABLE'] === 'YES',
                    'identity'=>str_contains($row['EXTRA'], 'auto_increment'),
                    'generated'=>$row['IS_GENERATED'] === 'ALWAYS'];
                $unsigned = str_contains($row['COLUMN_TYPE'], 'unsigned');
                $type = match ($row['DATA_TYPE']) {
                    'tinyint' => $unsigned ? 'UINT8' : 'INT8',
                    'smallint' => $unsigned ? 'UINT16' : 'INT16',
                    'int' => $unsigned ? 'UINT32' : 'INT32',
                    'bigint' => $unsigned ? null : 'INT64',
                    'char','varchar' => 'STRING',
                    'decimal' => 'DECIMAL',
                    'date' => 'DATE',
                    'tinytext','text','mediumtext','longtext' => 'TEXT',
                    default => null
                };
                // Native CHECK expressions are not a new portable constraint grammar. Do not prove their domain.
                if ($hasChecks) {
                    $type = null;
                    if ($row['IS_NULLABLE'] === 'YES') unset($column->nullable);
                }
                if ($type !== null) $column->scalarType = $type;
                if ($type !== null && isset(Number::RANGES[$type])) {
                    [$lo,$hi] = Number::RANGES[$type];
                    $column->integerRange = (object)['minimum'=>$lo,'maximum'=>$hi];
                }
                if (!$hasChecks && $row['DATA_TYPE'] === 'bigint' && $unsigned) {
                    // UINT64 has no portable DICT-001 counterpart: preserve the observed extent without inventing one.
                    $column->integerRange = (object)['minimum'=>'0','maximum'=>'18446744073709551615'];
                }
                if ($type === 'STRING' && $row['CHARACTER_MAXIMUM_LENGTH'] !== null) {
                    $column->characterLength = (object)['value'=>(int)$row['CHARACTER_MAXIMUM_LENGTH'],
                        'unit'=>'CHARACTERS'];
                }
                if ($row['DATA_TYPE'] === 'decimal' && $row['NUMERIC_PRECISION'] !== null
                    && $row['NUMERIC_SCALE'] !== null) {
                    $column->decimalGeometry = (object)['precision'=>(int)$row['NUMERIC_PRECISION'],
                        'scale'=>(int)$row['NUMERIC_SCALE']];
                }
                $columns[] = $column;
            }
            $keys = $this->rows($connection, 'SELECT CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION,
                REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                ORDER BY BINARY CONSTRAINT_NAME, ORDINAL_POSITION');
            $primary = []; $foreign = [];
            foreach ($keys as $key) {
                if ($key['CONSTRAINT_NAME'] === 'PRIMARY') $primary[] = $key['COLUMN_NAME'];
                if ($key['REFERENCED_TABLE_NAME'] !== null) {
                    $name = $key['CONSTRAINT_NAME'];
                    $foreign[$name] ??= (object)['name'=>$name,'columns'=>[], 'target'=>(object)[
                        'schema'=>$key['REFERENCED_TABLE_SCHEMA'],'table'=>$key['REFERENCED_TABLE_NAME'],
                            'columns'=>[]]];
                    $foreign[$name]->columns[] = $key['COLUMN_NAME'];
                    $foreign[$name]->target->columns[] = $key['REFERENCED_COLUMN_NAME'];
                }
            }
            foreach ($constraints as $constraint) {
                if ($constraint['CONSTRAINT_TYPE'] === 'FOREIGN KEY'
                    && !isset($foreign[$constraint['CONSTRAINT_NAME']])) throw new ObservationException();
                if ($constraint['CONSTRAINT_TYPE'] === 'PRIMARY KEY' && !$primary) throw new ObservationException();
            }
            ksort($foreign, SORT_STRING);
            $indexes = []; $complete = true;
            $rows = $this->rows($connection, 'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX, SUB_PART,
                INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                ORDER BY BINARY INDEX_NAME, SEQ_IN_INDEX');
            foreach ($rows as $row) {
                if ($row['COLUMN_NAME'] === null || $row['SUB_PART'] !== null
                    || !in_array($row['INDEX_TYPE'], ['BTREE','HASH'], true)) $complete = false;
                $name = $row['INDEX_NAME'];
                $indexes[$name] ??= (object)['name'=>$name,'columns'=>[],'unique'=>$row['NON_UNIQUE'] === '0'];
                $indexes[$name]->columns[] = $row['COLUMN_NAME'];
            }
            ksort($indexes, SORT_STRING);
            $result = (object)['apiVersion'=>'arp.physical/v1','id'=>$this->physicalId,'kind'=>'table',
                'source'=>(object)['provider'=>$this->provider,'dialect'=>$this->mariaDb() ? 'mariadb' : 'mysql',
                    'schema'=>$observedSchema,
                    'name'=>$observedName,'engineVersion'=>$version],
                'columns'=>$columns,'primaryKey'=>$primary,'foreignKeys'=>array_values($foreign)];
            if ($complete) $result->indexes = array_values($indexes);
            if (!$connection->commit()) throw new ObservationException();
            return $result;
        } catch (PDOException) {
            throw new ObservationException();
        } finally {
            if ($connection instanceof PDO && $connection->inTransaction()) {
                // Cleanup is mandatory even after failed metadata decoding; failures remain sanitized.
                try { if (!$connection->rollBack()) throw new ObservationException(); }
                catch (PDOException) { throw new ObservationException(); }
            }
        }
    }

    private function rows(PDO $connection, string $sql, ?array $parameters = null): array
    {
        $statement = $connection->prepare($sql);
        if ($statement === false) throw new ObservationException();
        try {
            if (!$statement->execute($parameters ?? [$this->schema,$this->table])) throw new ObservationException();
            return $statement->columnCount() ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
        } finally { if (!$statement->closeCursor()) throw new ObservationException(); }
    }
}