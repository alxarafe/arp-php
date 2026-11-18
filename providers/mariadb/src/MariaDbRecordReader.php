<?php
declare(strict_types=1);
namespace Alxarafe\Arp\MariaDb;

use Alxarafe\Arp\Sql\SqlRecordReader;
use Alxarafe\Arp\Cds\Physical\MariaDbObservationProvider;
use PDO;

/** MariaDB 12.3 only. One inherited algorithm and existing metadata authority. */
class MariaDbRecordReader extends SqlRecordReader
{
    protected string $identifier = '`';
    protected function begin(PDO $connection): void
    {
        $version = $this->execute($connection, 'SELECT VERSION() AS version')[0]['version'];
        self::need(preg_match('/\A12\.3\./', $version) === 1 && str_contains($version, 'MariaDB'));
        foreach (['lock_wait_timeout','innodb_lock_wait_timeout','max_statement_time'] as $setting) {
            $this->execute($connection, 'SET SESSION '.$setting.' = '.$this->timeoutSeconds);
        }
        $this->execute($connection, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->execute($connection, 'SET TRANSACTION READ ONLY');
        self::need($connection->beginTransaction() && $connection->inTransaction(), 'READ_FAILED');
    }
    protected function independent(PDO $data, PDO $guard): bool
    {
        return $this->execute($data, 'SELECT CONNECTION_ID() AS id')[0]['id']
            !== $this->execute($guard, 'SELECT CONNECTION_ID() AS id')[0]['id'];
    }
    protected function protect(PDO $connection, object $physical): void
    {
        $schema = $physical->source->schema; $table = $physical->source->name;
        self::need($physical->source->dialect === 'mariadb');
        $relation = $this->quote($schema).'.'.$this->quote($table);
        $this->execute($connection, 'SELECT * FROM '.$relation.' LIMIT 0');
        $this->execute($connection, 'SHOW CREATE TABLE '.$relation);
        $tables = $this->execute($connection,
            'SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',
            [$schema,$table]);
        $partitions = $this->execute($connection, 'SELECT COUNT(*) AS n FROM information_schema.PARTITIONS'
            .' WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND PARTITION_NAME IS NOT NULL', [$schema,$table]);
        $account = $this->execute($connection, 'SELECT CURRENT_USER() AS account')[0]['account'];
        $separator = strrpos($account, '@');
        self::need($separator !== false && $separator > 0);
        $grantee = "'".substr($account, 0, $separator)."'@'".substr($account, $separator+1)."'";
        $grants = $this->execute($connection, 'SELECT COUNT(*) AS n FROM ('
            ."SELECT GRANTEE FROM information_schema.USER_PRIVILEGES WHERE PRIVILEGE_TYPE='SELECT' "
            ."UNION ALL SELECT GRANTEE FROM information_schema.SCHEMA_PRIVILEGES WHERE PRIVILEGE_TYPE='SELECT' "
            .'AND TABLE_SCHEMA=? UNION ALL SELECT GRANTEE FROM information_schema.TABLE_PRIVILEGES '
            ."WHERE PRIVILEGE_TYPE='SELECT' AND TABLE_SCHEMA=? AND TABLE_NAME=?) grants_read WHERE GRANTEE=?",
            [$schema,$schema,$table,$grantee]);
        self::need(count($tables) === 1 && $tables[0]['TABLE_TYPE'] === 'BASE TABLE'
            && $tables[0]['ENGINE'] === 'InnoDB' && $partitions[0]['n'] === '0' && $grants[0]['n'] !== '0');
    }
    protected function observe(object $physical, PDO $data): object
    {
        $connect = function() use ($data): PDO {
            $connection = $this->connection();
            self::need($connection !== $data && $this->independent($data, $connection), 'READ_FAILED');
            return $connection;
        };
        return (new MariaDbObservationProvider($connect, $physical->source->provider, $physical->id,
            $physical->source->schema, $physical->source->name, $this->timeoutSeconds))->observe();
    }
    protected function limitClause(): string { return ' LIMIT ?'; }
    protected function valueExpression(string $column): string
    {
        return 'CAST('.$column.' AS CHAR CHARACTER SET utf8mb4) AS '.$column;
    }
    protected function portableValue(array $row, array $column): mixed
    {
        $value = $row[$column['name']];
        if ($value === null || $column['type'] !== 'BOOLEAN') return $value;
        return match ($value) {
            '0' => false, '1' => true,
            default => throw new \Alxarafe\Arp\Dictionary\Cds\ReadFailure('ROW_DECODE_FAILED')
        };
    }
    protected function supportsColumn(array $column, object $physical): bool
    {
        if (str_starts_with($physical->nativeType, 'char(')) return false;
        return $column['type'] === 'BOOLEAN'
            ? ($physical->scalarType ?? null) === 'INT8' && str_starts_with($physical->nativeType, 'tinyint')
            : parent::supportsColumn($column, $physical);
    }
}