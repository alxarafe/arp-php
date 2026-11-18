<?php
declare(strict_types=1);
namespace Alxarafe\Arp\SqlServer;

use Alxarafe\Arp\Sql\SqlMetadata;
use Alxarafe\Arp\Dictionary\Cds\ReadFailure;
use PDO;

final class SqlServerMetadata
{
    public static function quote(string $name): string { return '['.str_replace(']', ']]', $name).']'; }
    public static function begin(PDO $pdo, int $timeout): void
    {
        ReadFailure::need(SqlMetadata::query($pdo,
            "SELECT CAST(SERVERPROPERTY('ProductMajorVersion') AS varchar(8)) AS n")[0]['n'] === '16',
            'CAPABILITY_UNAVAILABLE');
        SqlMetadata::query($pdo, 'SET XACT_ABORT ON');
        SqlMetadata::query($pdo, 'SET LOCK_TIMEOUT '.($timeout*1000));
        SqlMetadata::query($pdo, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        ReadFailure::need($pdo->beginTransaction(), 'READ_FAILED');
    }
    public static function protect(PDO $pdo, string $schema, string $table): void
    {
        $relation = self::quote($schema).'.'.self::quote($table);
        // Transaction-held table S locks survive statement completion and may block writers.
        SqlMetadata::query($pdo, 'SELECT TOP (1) 1 AS marker FROM '.$relation.' WITH (TABLOCK,HOLDLOCK)');
        $rows = SqlMetadata::query($pdo, 'SELECT t.is_memory_optimized,t.temporal_type,'
            ."HAS_PERMS_BY_NAME(?,'OBJECT','SELECT') AS can_read,"
            ."HAS_PERMS_BY_NAME(DB_NAME(),'DATABASE','VIEW DEFINITION') AS can_observe "
            .'FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id '
            .'WHERE s.name=? AND t.name=? AND t.is_ms_shipped=0 '
            .'AND NOT EXISTS(SELECT 1 FROM sys.partitions p WHERE p.object_id=t.object_id AND p.partition_number>1) '
            .'AND NOT EXISTS(SELECT 1 FROM sys.security_predicates sp WHERE sp.target_object_id=t.object_id)',
            [$relation,$schema,$table]);
        ReadFailure::need(count($rows) === 1 && $rows[0]['is_memory_optimized'] === '0'
            && $rows[0]['temporal_type'] === '0' && $rows[0]['can_read'] === '1'
            && $rows[0]['can_observe'] === '1', 'CAPABILITY_UNAVAILABLE');
    }
}