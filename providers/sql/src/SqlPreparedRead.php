<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Sql;

use Alxarafe\Arp\Dictionary\Cds\PreparedRead;

/** @internal Bound to the reader which proved these capabilities. */
final readonly class SqlPreparedRead extends PreparedRead
{
    private string $physicalJson;
    public function __construct(
        public SqlRecordReader $owner,
        object $physical,
        array $columns,
        array $keys,
        array $equalities
    ) {
        parent::__construct($columns, $keys, $equalities);
        $this->physicalJson = \Alxarafe\Arp\Dictionary\Internal\Projection::encode($physical);
    }
    public function physical(): object
    {
        return \Alxarafe\Arp\Dictionary\Internal\Parser::parse($this->physicalJson, 'json');
    }
}
