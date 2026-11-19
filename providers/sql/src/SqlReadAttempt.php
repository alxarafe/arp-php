<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Sql;

use Alxarafe\Arp\Dictionary\Cds\{ReadAttempt,ReadFailure};
use PDO;

/** @internal Owns connections until mandatory checked cleanup; no destructor-based success path. */
final class SqlReadAttempt implements ReadAttempt
{
    private bool $completed = false;
    private bool $closed = false;
    public function __construct(private ?PDO $data, private ?PDO $guard, private readonly array $batch)
    {
    }
    public function rows(): array
    {
        // Detach mutable value maps on every access.
        return array_map(static fn($r) => ['key' => $r['key'],'values' => clone $r['values']], $this->batch);
    }
    public function complete(): void
    {
        ReadFailure::need(!$this->completed && !$this->closed, 'READ_FAILED');
        try {
            ReadFailure::need($this->data->inTransaction() && $this->guard->inTransaction(), 'READ_FAILED');
            ReadFailure::need($this->data->commit(), 'READ_FAILED');
            $this->completed = true;
            ReadFailure::need(!$this->data->inTransaction() && $this->guard->inTransaction(), 'READ_FAILED');
        } catch (\Throwable) {
            throw new ReadFailure('READ_FAILED');
        }
    }
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $clean = true;
        foreach (['data','guard'] as $name) {
            $connection = $this->$name;
            try {
                $active = $connection?->inTransaction() ?? false;
                if ($connection !== null && ($name === 'guard' || !$this->completed) && !$active) {
                    $clean = false;
                }
                if ($active && !$connection->rollBack()) {
                    $clean = false;
                }
                if ($connection?->inTransaction()) {
                    $clean = false;
                }
            } catch (\Throwable) {
                $clean = false;
            } finally {
                $this->$name = null;
                $connection = null;
            }
        }
        ReadFailure::need($clean, 'READ_FAILED');
    }
}
