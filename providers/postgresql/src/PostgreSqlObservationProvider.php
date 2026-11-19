<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Cds\Physical;

use Alxarafe\Arp\Sql\SqlMetadata;
use Alxarafe\Arp\PostgreSql\PostgreSqlMetadata;
use Alxarafe\Arp\Dictionary\Internal\Parser;
use PDO;

final readonly class PostgreSqlObservationProvider implements PhysicalObservationProvider
{
    public function __construct(
        private \Closure $connect,
        private string $provider,
        private string $physicalId,
        private string $schema,
        private string $table,
        private int $timeoutSeconds = 5
    ) {
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('Positive timeout required');
        }
        foreach ([$provider,$physicalId,$schema,$table] as $name) {
            if ($name === '' || str_contains($name, "\0")) {
                throw new \InvalidArgumentException('Invalid registration');
            }
        }
    }
    public function providerId(): string
    {
        return $this->provider;
    }
    public function observe(): object
    {
        return SqlMetadata::observation($this->connect, 'pgsql', function (PDO $pdo): object {
            PostgreSqlMetadata::begin($pdo, $this->timeoutSeconds);
            PostgreSqlMetadata::protect($pdo, $this->schema, $this->table);
            $rows = SqlMetadata::query(
                $pdo,
                file_get_contents(__DIR__ . '/postgresql.sql'),
                [$this->provider,$this->physicalId,$this->schema,$this->table]
            );
            if (count($rows) !== 1) {
                throw new ObservationException();
            }
            $result = Parser::parse(array_values($rows[0])[0], 'json');
            if (count($result->columns ?? []) < 1 || count($result->columns) > 2048) {
                throw new ObservationException();
            }
            return $result;
        });
    }
}
