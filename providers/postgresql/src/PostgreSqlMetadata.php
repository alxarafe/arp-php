<?php

declare(strict_types=1);

namespace Alxarafe\Arp\PostgreSql;

use Alxarafe\Arp\Sql\SqlMetadata;
use Alxarafe\Arp\Dictionary\Cds\ReadFailure;
use PDO;

final class PostgreSqlMetadata
{
    public static function quote(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
    public static function begin(PDO $pdo, int $timeout): void
    {
        ReadFailure::need(SqlMetadata::query($pdo, "SELECT current_setting('server_version_num') AS n")[0]['n']
            === '180006', 'CAPABILITY_UNAVAILABLE');
        ReadFailure::need($pdo->beginTransaction(), 'READ_FAILED');
        SqlMetadata::query($pdo, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED READ ONLY');
        SqlMetadata::query($pdo, "SET LOCAL lock_timeout = '" . $timeout . "s'");
        SqlMetadata::query($pdo, "SET LOCAL statement_timeout = '" . $timeout . "s'");
        SqlMetadata::query($pdo, "SET LOCAL DateStyle = 'ISO, YMD'");
    }
    public static function protect(PDO $pdo, string $schema, string $table): void
    {
        SqlMetadata::query($pdo, 'LOCK TABLE ONLY ' . self::quote($schema) . '.' . self::quote($table)
            . ' IN ACCESS SHARE MODE');
        $rows = SqlMetadata::query($pdo, "SELECT CASE WHEN c.relkind='r' AND NOT c.relrowsecurity "
            . "AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_inherits i WHERE i.inhparent=c.oid OR i.inhrelid=c.oid) "
            . "AND has_table_privilege(c.oid,'SELECT') THEN '1' ELSE '0' END AS capable "
            . 'FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace '
            . 'WHERE n.nspname=? AND c.relname=?', [$schema,$table]);
        ReadFailure::need(count($rows) === 1 && $rows[0]['capable'] === '1', 'CAPABILITY_UNAVAILABLE');
    }
}
