<?php
declare(strict_types=1);
namespace Alxarafe\Arp\Cds\Physical;

/** Existing public MariaDB observer; all metadata mapping remains in the shared family implementation. */
final readonly class MariaDbObservationProvider extends MySqlObservationProviderBase
{
    protected function mariaDb(): bool { return true; }
}