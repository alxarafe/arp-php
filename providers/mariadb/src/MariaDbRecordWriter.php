<?php

declare(strict_types=1);

namespace Alxarafe\Arp\MariaDb;

use Alxarafe\Arp\Dictionary\Cds\{RecordWriter,ReadResult,WriteFailure,ReadFailure,ListGetHost};
use Alxarafe\Arp\Dictionary\Internal\Number;
use Alxarafe\Arp\Sql\SqlWriteTransaction;
use PDO;

/** Same PDO factory, quoting, observation and physical protection as MariaDbRecordReader. */
class MariaDbRecordWriter extends MariaDbRecordReader implements RecordWriter
{
    public function transaction(
        ReadResult $snapshot,
        string $projection,
        object $equalities,
        \Closure $action,
        \Closure $current
    ): mixed {
        $data = null;
        $state = 'not-started';
        $persistencePossible = false;
        try {
            $prepared = $this->prepare($snapshot, $projection, $equalities);
            $physical = $prepared->physical();
            $selected = ListGetHost::find($snapshot->projection()->projections, $projection);
            $model = ListGetHost::find($snapshot->projection()->models, $selected->entity);
            $columns = [];
            foreach ($model->fields as $field) {
                $column = $field->physical;
                WriteFailure::need(($column->scalarType ?? null) === 'INTEGER'
                    || in_array($column->scalarType ?? '', ['STRING','TEXT'], true), 'CAPABILITY_UNAVAILABLE');
                WriteFailure::need(is_bool($column->nullable ?? null)
                    && !($column->generated ?? false) && !($column->identity ?? false), 'CAPABILITY_UNAVAILABLE');
                if ($field->type === 'STRING') {
                    WriteFailure::need(
                        ($column->characterLength->unit ?? '') === 'CHARACTERS',
                        'CAPABILITY_UNAVAILABLE'
                    );
                    WriteFailure::need(
                        !isset($field->constraints->maximumLength)
                        || $field->constraints->maximumLength <= $column->characterLength->value,
                        'INCOMPATIBLE_STORAGE',
                        'physical-incompatible'
                    );
                }
                $columns[$column->name] = $column;
            }
            $current();
            $data = $this->connection();
            $version = $this->execute($data, 'SELECT VERSION() AS version')[0]['version'];
            WriteFailure::need(preg_match('/\A12\.3\./', $version) === 1
                && str_contains($version, 'MariaDB'), 'CAPABILITY_UNAVAILABLE');
            $this->execute($data, 'SET SESSION lock_wait_timeout = ' . $this->timeoutSeconds);
            $this->execute($data, 'SET SESSION innodb_lock_wait_timeout = ' . $this->timeoutSeconds);
            $this->execute($data, 'SET SESSION max_statement_time = ' . $this->timeoutSeconds);
            $this->execute($data, "SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE'");
            $this->execute($data, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->execute($data, 'SET TRANSACTION READ WRITE');
            WriteFailure::need($data->beginTransaction(), 'PERSISTENCE_FAILED');
            $state = 'active';
            $this->protect($data, $physical);
            WriteFailure::need($this->observe($physical, $data) == $physical, 'GENERATION_EXPIRED');
            $schema = $physical->source->schema;
            $table = $physical->source->name;
            $account = $this->execute($data, 'SELECT CURRENT_USER() AS account')[0]['account'];
            $split = strrpos($account, '@');
            WriteFailure::need($split !== false, 'CAPABILITY_UNAVAILABLE');
            $grantee = "'" . substr($account, 0, $split) . "'@'" . substr($account, $split + 1) . "'";
            $privileges = $this->execute(
                $data,
                'SELECT COUNT(*) AS n FROM ('
                . "SELECT GRANTEE FROM information_schema.USER_PRIVILEGES WHERE PRIVILEGE_TYPE='TRIGGER'"
                . " UNION ALL SELECT GRANTEE FROM information_schema.SCHEMA_PRIVILEGES WHERE PRIVILEGE_TYPE='TRIGGER'"
                . ' AND TABLE_SCHEMA=? UNION ALL SELECT GRANTEE FROM information_schema.TABLE_PRIVILEGES'
                . " WHERE PRIVILEGE_TYPE='TRIGGER' AND TABLE_SCHEMA=? AND TABLE_NAME=?) grants_trigger WHERE GRANTEE=?",
                [$schema,$schema,$table,$grantee]
            );
            WriteFailure::need($privileges[0]['n'] !== '0', 'CAPABILITY_UNAVAILABLE');
            $sizes = $this->execute($data, 'SELECT COALESCE(CHARACTER_OCTET_LENGTH,32) AS bytes'
                . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$schema,$table]);
            WriteFailure::need(count($sizes) === count($columns), 'GENERATION_EXPIRED');
            $rowBudget = 0;
            foreach ($sizes as $size) {
                WriteFailure::need(Number::compare($size['bytes'], '1048576') <= 0, 'CAPABILITY_UNAVAILABLE');
                $rowBudget += (int)$size['bytes'];
                WriteFailure::need($rowBudget <= 1048576, 'CAPABILITY_UNAVAILABLE');
            }
            $encoding = $this->execute($data, 'SELECT COUNT(*) AS n FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CHARACTER_SET_NAME IS NOT NULL'
                . " AND CHARACTER_SET_NAME <> 'utf8mb4'", [$schema,$table]);
            WriteFailure::need($encoding[0]['n'] === '0', 'CAPABILITY_UNAVAILABLE');
            $triggers = $this->execute($data, 'SELECT COUNT(*) AS n FROM information_schema.TRIGGERS'
                . ' WHERE EVENT_OBJECT_SCHEMA=? AND EVENT_OBJECT_TABLE=?', [$schema,$table]);
            $cascades = $this->execute(
                $data,
                'SELECT COUNT(*) AS n FROM information_schema.REFERENTIAL_CONSTRAINTS'
                . ' WHERE UNIQUE_CONSTRAINT_SCHEMA=? AND REFERENCED_TABLE_NAME=?'
                . " AND (DELETE_RULE NOT IN ('RESTRICT','NO ACTION') OR UPDATE_RULE NOT IN ('RESTRICT','NO ACTION'))",
                [$schema,$table]
            );
            WriteFailure::need($triggers[0]['n'] === '0' && $cascades[0]['n'] === '0', 'CAPABILITY_UNAVAILABLE');
            $transaction = new SqlWriteTransaction(
                $data,
                $this->quote($schema) . '.' . $this->quote($table),
                $columns,
                array_column($prepared->keys, 'name'),
                $prepared->equalities,
                $this->quote(...)
            );
            $persistencePossible = true;
            $result = $action($transaction);
            $current();
            if ($transaction->writes === 0) {
                WriteFailure::need($data->rollBack(), 'PERSISTENCE_FAILED');
                $state = 'finished-without-writes';
                $persistencePossible = false;
            } else {
                $state = 'commit-uncertain';
                if (!$data->commit()) {
                    throw new \RuntimeException('Commit unacknowledged');
                }
                $state = 'commit-confirmed';
            }
            $current();
            return $result;
        } catch (\Throwable $error) {
            if ($state === 'commit-confirmed') {
                throw new WriteFailure('RESPONSE_UNAVAILABLE', 'execution-failure', 'failed');
            }
            // Connection existence is not evidence that a mutation could have reached storage.
            $noPersistence = !$persistencePossible
                || in_array($state, ['not-started','finished-without-writes'], true);
            $reverted = $noPersistence;
            try {
                if ($data?->inTransaction()) {
                    $reverted = $data->rollBack() || $noPersistence;
                }
            } catch (\Throwable) {
                $reverted = $noPersistence;
            }
            if (!$reverted) {
                throw new WriteFailure('OUTCOME_UNKNOWN', 'execution-failure', 'failed');
            }
            if ($error instanceof WriteFailure) {
                throw $error;
            }
            if ($error instanceof ReadFailure) {
                if ($error->errorCode === 'CAPABILITY_UNAVAILABLE') {
                    throw new WriteFailure('CAPABILITY_UNAVAILABLE');
                }
                throw new WriteFailure('PERSISTENCE_FAILED', 'execution-failure', 'failed');
            }
            if (
                $error instanceof \PDOException && (str_starts_with((string)$error->getCode(), '23')
                || ($error->errorInfo[1] ?? null) === 4025)
            ) {
                throw new WriteFailure('CONSTRAINT_REJECTED', 'invalid-data');
            }
            throw new WriteFailure(
                $state === 'commit-uncertain' ? 'COMMIT_FAILED' : 'PERSISTENCE_FAILED',
                'execution-failure',
                'failed'
            );
        }
    }
}
