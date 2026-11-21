<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Cds;

/** Immutable neutral plan metadata; subclasses retain their own private provider state. */
abstract readonly class PreparedRead
{
    /** Columns/keys contain name, type, nullable and trusted constraints; equalities are normalized integer strings. */
    protected function __construct(public array $columns, public array $keys, public array $equalities)
    {
    }
}
