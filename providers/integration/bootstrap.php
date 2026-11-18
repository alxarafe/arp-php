<?php
declare(strict_types=1);
require __DIR__.'/connect.php';
$engine = getenv('ARP_SQL_ENGINE'); $token = getenv('ARP_CDS_RUN_TOKEN');
if (!preg_match('/\A[a-f0-9]{16}\z/D', $token)) throw new RuntimeException('Owned nonce required');
[$dsn,$user,$password,$driver] = endpoint($engine, true);
if (!in_array($driver, PDO::getAvailableDrivers(), true)) throw new RuntimeException('Missing PDO driver '.$driver);
$pdo = null;
for ($i = 0; $i < 100; ++$i) {
    try { $pdo = testConnection(PDO::class, true); break; }
    catch (PDOException $e) {
        if (($e->errorInfo[0] ?? '') === 'IMSSP') throw new RuntimeException('Unsupported PDO connection option');
        sleep(1);
    }
}
if (!$pdo) throw new RuntimeException('Owned database readiness timeout');
if ($engine === 'sqlserver') { $pdo->exec('CREATE DATABASE arpcds'); $pdo = testConnection(); }
$pdo->exec('CREATE TABLE __arp_cds_test_owner(token varchar(32))');
$statement = $pdo->prepare('INSERT INTO __arp_cds_test_owner VALUES(?)');
$statement->execute([$token]); $statement->closeCursor();
echo json_encode(['engine'=>$engine,'php'=>PHP_VERSION,'drivers'=>PDO::getAvailableDrivers()])."\n";