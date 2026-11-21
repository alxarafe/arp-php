<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Sql;

use Alxarafe\Arp\Cds\Physical\ObservationException;
use Alxarafe\Arp\Dictionary\Internal\Integer;
use PDO;

/** Bounded metadata plumbing, as in the reference implementations; no record-reading algorithm. */
final class SqlMetadata
{
    public static function connection(\Closure $connect, string $driver): PDO
    {
        $pdo = $connect();
        if (
            !$pdo instanceof PDO || $pdo->inTransaction() || $pdo->getAttribute(PDO::ATTR_PERSISTENT)
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== $driver
        ) {
            throw new ObservationException();
        }
        foreach ([PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,PDO::ATTR_STRINGIFY_FETCHES => true] as $k => $v) {
            if (!$pdo->setAttribute($k, $v)) {
                throw new ObservationException();
            }
        }
        if ($driver === 'sqlsrv') {
            if (
                !$pdo->setAttribute(PDO::SQLSRV_ATTR_DIRECT_QUERY, false)
                || !$pdo->setAttribute(PDO::SQLSRV_ATTR_ENCODING, PDO::SQLSRV_ENCODING_UTF8)
            ) {
                throw new ObservationException();
            }
            if (self::query($pdo, 'SELECT @@TRANCOUNT AS n')[0]['n'] !== '0') {
                throw new ObservationException();
            }
        } elseif (!$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false)) {
            throw new ObservationException();
        }
        // pgsql/sqlsrv do not implement MySQL's PDO ATTR_AUTOCOMMIT; freshly owned connections are required.
        return $pdo;
    }
    public static function query(PDO $pdo, string $sql, array $parameters = []): array
    {
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            throw new ObservationException();
        }
        try {
            foreach ($parameters as $i => $value) {
                if (!$statement->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR)) {
                    throw new ObservationException();
                }
            }
            if (!$statement->execute()) {
                throw new ObservationException();
            }
            $rows = [];
            if ($statement->columnCount()) {
                while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                    if (count($rows) >= 4096) {
                        throw new ObservationException();
                    }
                    $rows[] = $row;
                }
            }
            return $rows;
        } finally {
            if (!$statement->closeCursor()) {
                throw new ObservationException();
            }
        }
    }
    public static function observation(\Closure $connect, string $driver, \Closure $read): object
    {
        $pdo = null;
        try {
            $pdo = self::connection($connect, $driver);
            $result = $read($pdo);
            if (!$pdo->inTransaction() || !$pdo->commit() || $pdo->inTransaction()) {
                throw new ObservationException();
            }
            return $result;
        } catch (\Throwable) {
            throw new ObservationException();
        } finally {
            if ($pdo?->inTransaction()) {
                try {
                    if (!$pdo->rollBack()) {
                        throw new ObservationException();
                    }
                } catch (\Throwable) {
                    throw new ObservationException();
                }
            }
            $pdo = null;
        }
    }
    public static function column(array $fact, ?string $type): object
    {
        $column = (object)['name' => $fact['name'],'nativeType' => $fact['nativeType'],'ordinal' => (int)$fact['ordinal'],
            'nullable' => $fact['nullable'],'generated' => $fact['generated'] ?? false,
            'identity' => $fact['identity'] ?? false];
        if ($type !== null) {
            $column->scalarType = $type;
        }
        if ($type === 'INTEGER') {
            [$lo,$hi] = Integer::domain($fact['integerBits'], $fact['integerSigned']);
            $column->integerRange = (object)['minimum' => $lo,'maximum' => $hi];
        }
        return $column;
    }
}
