<?php

declare(strict_types=1);

/** Integration-only endpoint configuration; supplied exclusively by the owned Docker runner. */
function endpoint(string $engine, bool $bootstrap = false): array
{
    $password = getenv('ARP_DB_PASSWORD') ?: throw new RuntimeException('Owned password required');
    $database = $bootstrap && $engine === 'sqlserver' ? 'master' : 'arpcds';
    return match ($engine) {
        'postgresql'=>['pgsql:host=db;dbname=arpcds','arp',$password,'pgsql','public'],
        'mysql','mariadb'=>['mysql:host=db;dbname=arpcds;charset=utf8mb4','root',$password,'mysql','arpcds'],
        'sqlserver'=>['sqlsrv:Server=db;Database=' . $database . ';Encrypt=yes;TrustServerCertificate=yes',
            'sa',$password,'sqlsrv','dbo'],
        default=>throw new RuntimeException('Unknown engine')
    };
}
function testConnection(string $class = PDO::class, bool $bootstrap = false): PDO
{
    [$dsn,$user,$password,$driver] = endpoint(getenv('ARP_SQL_ENGINE'), $bootstrap);
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    if ($driver !== 'sqlsrv') {
        $options[PDO::ATTR_PERSISTENT] = false;
    }
    if ($driver === 'mysql') {
        $options += [PDO::ATTR_EMULATE_PREPARES => false,
        Pdo\Mysql::ATTR_USE_BUFFERED_QUERY => false];
    } elseif ($driver === 'pgsql') {
        $options[PDO::ATTR_EMULATE_PREPARES] = false;
    } else {
        $options += [PDO::SQLSRV_ATTR_DIRECT_QUERY => false,PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8];
    }
    return new $class($dsn, $user, $password, $options);
}
