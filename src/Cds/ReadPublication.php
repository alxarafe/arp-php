<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Cds;

/** Local request/process publication; PHP hosts must serialize access to this instance. */
final class ReadPublication
{
    private int $generation = 0;
    private ?ReadResult $current = null;
    public function beginGeneration(): int
    {
        $this->current = null;
        return ++$this->generation;
    }
    public function invalidate(): void
    {
        $this->beginGeneration();
    }
    public function generation(): int
    {
        return $this->generation;
    }
    public function publishRead(ReadResult $expected, \Closure $publish): mixed
    {
        ReadFailure::need($expected === $this->current, 'GENERATION_CHANGED');
        $generation = $this->generation;
        $result = $publish();
        ReadFailure::need($generation === $this->generation && $expected === $this->current, 'GENERATION_CHANGED');
        return $result;
    }
    public function current(): ?ReadResult
    {
        return $this->current;
    }
    public function publish(int $generation, ReadResult $result): ReadResult
    {
        if ($generation !== $this->generation) {
            return ReadResult::failure('SNAPSHOT_CHANGED', '/snapshot', 'execution-failure');
        }
        $this->current = $result->valid ? $result : null;
        return $result;
    }
}
