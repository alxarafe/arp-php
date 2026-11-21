<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Cds;

interface WriteTransaction
{
    public function read(array $key): ?array;
    public function insert(array $values): void;
    public function update(array $key, array $changes): void;
    public function delete(array $key): void;
}
