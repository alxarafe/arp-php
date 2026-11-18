<?php
declare(strict_types=1);
namespace Alxarafe\Arp\MariaDb;

/** MariaDB 12.3 specialization, retaining the existing public class and constructor. */
class MariaDbRecordReader extends \Alxarafe\Arp\MySql\MySqlRecordReaderBase
{
    protected bool $mariaDb = true;
}