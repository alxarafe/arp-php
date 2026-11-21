<?php

declare(strict_types=1);

namespace Arp\Tests;

use Alxarafe\Arp\Dictionary\Cds\{ReadCatalog,RecordReader,RecordWriter,ReadPlan,WriteFailure};

/** Reusable port obligations. A caller must supply real reset/observation to claim SQL evidence. */
final class AdapterObligations
{
    public static function run(
        ReadCatalog $catalog,
        RecordReader $reader,
        RecordWriter $writer,
        string $projection,
        \Closure $reset,
        \Closure $observe,
        array $seed,
        array $created
    ): array {
        $passed = [];
        $snapshot = $catalog->resolve();
        Contract::need($snapshot->valid, 'Obligations require a compiled snapshot');
        $current = static fn(): bool => $catalog->current() === $snapshot;
        $writeCurrent = static function () use ($current): void {
            Contract::need($current(), 'Obligation snapshot changed');
        };
        $prepared = $reader->prepare($snapshot, $projection, new \stdClass());
        $keyNames = array_column($prepared->keys, 'name');
        $key = static fn($row) => array_map(static fn($name) => $row[$name], $keyNames);
        $initialKey = $key($seed[0]);
        $createdKey = $key($created);
        // Port-specific adversarial parameter; neutral documents and vector expectations stay untouched.
        $created['code'] = "Q'--";
        $read = static function (?array $position, bool $get, int $limit) use ($reader, $prepared, $current): array {
            $attempt = $reader->open(new ReadPlan($prepared, $position, $get, $limit), $current);
            try {
                $rows = $attempt->rows();
                $attempt->complete();
                return $rows;
            } finally {
                $attempt->close();
            }
        };
        $tx = static fn($action) => $writer->transaction(
            $snapshot,
            $projection,
            new \stdClass(),
            $action,
            $writeCurrent
        );
        $reset($seed);
        $batch = $read($initialKey, true, 1);
        Contract::same($batch[0]['key'] ?? null, $initialKey, 'adapter GET exact identity');
        $passed[] = 'get-exact-key';
        $before = $observe();
        $read(null, false, 1);
        Contract::same($observe(), $before, 'adapter reads do not persist');
        $passed[] = 'read-only';
        $tx(static fn($t) => $t->insert($created));
        Contract::same(count($observe()), count($seed) + 1, 'adapter INSERT persisted');
        Contract::same($tx(static fn($t) => $t->read($createdKey)), $created, 'adapter quoted value exact roundtrip');
        $passed[] = 'parameterized-insert';
        $ordered = $read(null, false, 1);
        Contract::same(array_column($ordered, 'key'), [$initialKey,$createdKey], 'adapter ordered anticipation');
        $after = $read($initialKey, false, 1);
        Contract::same(array_column($after, 'key'), [$createdKey], 'adapter keyset boundary');
        $passed[] = 'ordered-keyset-and-lookahead';
        $scope = (object)[$keyNames[0] => $initialKey[0]];
        $restricted = $reader->prepare($snapshot, $projection, $scope);
        $attempt = $reader->open(new ReadPlan($restricted, null, false, 1), $current);
        try {
            Contract::same(array_column($attempt->rows(), 'key'), [$initialKey], 'adapter scope before limit');
            $attempt->complete();
        } finally {
            $attempt->close();
        }
        $passed[] = 'scope-before-limit';
        $before = $observe();
        try {
            $tx(static fn($t) => $t->insert($created));
            throw new \LogicException('duplicate key was accepted');
        } catch (WriteFailure $error) {
            Contract::same($error->errorCode, 'CONSTRAINT_REJECTED', 'adapter duplicate integrity code');
        }
        Contract::same($observe(), $before, 'adapter duplicate is atomic');
        $passed[] = 'unique-key-integrity';
        $reset($seed);
        try {
            $tx(static function ($t) use ($created, $initialKey): void {
                $t->insert($created);
                $t->update($initialKey, ['code' => $created['code']]);
                throw new \RuntimeException('Deliberate callback failure after DML');
            });
            throw new \LogicException('rollback obligation did not fail');
        } catch (WriteFailure $error) {
            Contract::same($error->errorCode, 'PERSISTENCE_FAILED', 'adapter callback failure');
        }
        Contract::same($observe(), $seed, 'adapter rollback restores persisted state');
        $passed[] = 'rollback-after-callback-failure';
        $tx(static fn($t) => $t->update($initialKey, []));
        Contract::same($observe(), $seed, 'adapter empty update state');
        $passed[] = 'empty-update-preserves-state';
        $tx(static fn($t) => $t->update($initialKey, ['code' => $created['code']]));
        $row = $tx(static fn($t) => $t->read($initialKey));
        Contract::same(
            $row,
            array_replace($seed[0], ['code' => $created['code']]),
            'adapter partial SET preserves unsent fields'
        );
        $passed[] = 'partial-update';
        $tx(static fn($t) => $t->delete($initialKey));
        Contract::same($observe(), [], 'adapter DELETE persisted');
        $passed[] = 'delete-by-exact-key';
        $reset($seed);
        return ['obligations' => $passed,'count' => count($passed)];
    }
}
