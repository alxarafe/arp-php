<?php
declare(strict_types=1);
require __DIR__.'/../../../vendor/autoload.php';
use Alxarafe\Arp\Dictionary\{CatalogBuilder,Source};
use Alxarafe\Arp\Dictionary\Cds\{ListGetHost,ReadFailure};
use Alxarafe\Arp\Dictionary\Internal\{Parser,Projection};
use Alxarafe\Arp\Cds\Physical\{PhysicalCatalog,MariaDbObservationProvider};
use Alxarafe\Arp\MariaDb\MariaDbRecordReader;

function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
function same(mixed $a, mixed $b, string $label): void {
    $canon = function($v) use (&$canon) {
        if ($v instanceof stdClass) { $v = (array)$v; ksort($v); return ['object',array_map($canon, $v)]; }
        return is_array($v) ? array_map($canon, $v) : $v;
    };
    check($canon($a) === $canon($b), $label.' '.json_encode([$a,$b], JSON_UNESCAPED_UNICODE));
}
$root = getenv('ARP_LISTGET_CONTRACT_PATH') ?: throw new RuntimeException('Pinned contract required');
$password = getenv('ARP_DB_PASSWORD') ?: throw new RuntimeException('Owned DB required');
$dsn = 'mysql:host=db;dbname=arpcds;charset=utf8mb4';
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
    PDO::ATTR_PERSISTENT=>false,Pdo\Mysql::ATTR_USE_BUFFERED_QUERY=>false];
$connect = static fn() => new PDO($dsn, 'root', $password, $options);
$admin = $connect();
check($admin->query('SELECT token FROM __arp_cds_test_owner')->fetchColumn() === getenv('ARP_CDS_RUN_TOKEN'),
    'owned nonce before DDL');
$products = Parser::parse(file_get_contents($root.'/fixtures/cds/listget/v1/products.json'), 'json');
$vectors = Parser::parse(file_get_contents($root.'/fixtures/cds/listget/v1/cases.json'), 'json');
$dictionary = (new CatalogBuilder())->buildCatalog([new Source('fixture','products','json',
    Projection::encode($products->dictionary))])->catalog;
check($dictionary !== null, 'fixture dictionary');
$context = ['identity'=>'commercial-reader/company-7','policyRevision'=>'policy-1'];
$responses = []; $evidence = [];
class HookReader extends MariaDbRecordReader
{
    public ?Closure $afterRows = null;
    public ?Closure $mutateRows = null;
    public ?Closure $afterGuard = null;
    private int $protections = 0;
    protected function execute(PDO $connection, string $sql, array $parameters = []): array {
        $rows = parent::execute($connection, $sql, $parameters);
        if (str_starts_with($sql, 'SELECT CAST(')) {
            ($this->afterRows ?? static fn() => null)();
            if ($this->mutateRows !== null) $rows = ($this->mutateRows)($rows);
        }
        return $rows;
    }
    protected function protect(PDO $connection, object $physical): void {
        parent::protect($connection, $physical);
        if (++$this->protections % 2 === 0) ($this->afterGuard ?? static fn() => null)();
    }
}
class CursorFault extends PDOStatement
{
    public function closeCursor(): bool {
        $ok = parent::closeCursor();
        if (str_starts_with($this->queryString, 'SELECT CAST(')) {
            if (FaultPdo::$mode === 'cursor-throw') throw new PDOException('private cursor detail');
            if (FaultPdo::$mode === 'cursor-false') return false;
        }
        return $ok;
    }
}
class FaultPdo extends PDO
{
    public static string $mode = '';
    public static ?Closure $afterCommit = null;
    public string $role = '';
    public function beginTransaction(): bool {
        if (self::$mode === 'begin-'.$this->role.'-false') return false;
        if (self::$mode === 'begin-'.$this->role.'-throw') throw new PDOException('private begin detail');
        return parent::beginTransaction();
    }
    public function commit(): bool {
        if (self::$mode === 'commit-false') return false;
        if (self::$mode === 'commit-throw') throw new PDOException('private commit detail');
        $ok = parent::commit(); (self::$afterCommit ?? static fn() => null)(); return $ok;
    }
    public function rollBack(): bool {
        $ok = parent::rollBack();
        if (self::$mode === 'rollback-throw') throw new PDOException('private rollback detail');
        return self::$mode === 'rollback-false' ? false : $ok;
    }
}
foreach (['composite','simple'] as $layoutName) {
    $layout = $products->layouts->$layoutName;
    $admin->exec('DROP TABLE IF EXISTS products');
    $pk = $layoutName === 'composite' ? 'tenant_id,product_id' : 'product_id';
    $admin->exec('CREATE TABLE products(tenant_id INT NOT NULL, product_id BIGINT NOT NULL, sku VARCHAR(80) NOT NULL,'
        .'name VARCHAR(200) NOT NULL,price DECIMAL(65,18) NOT NULL,rating SMALLINT NOT NULL,description TEXT NULL,'
        .'available TINYINT(1) NOT NULL,released DATE NOT NULL,cost DECIMAL(65,18) NOT NULL,internal_note TEXT NULL,'
        .'PRIMARY KEY('.$pk.')) ENGINE=InnoDB');
    $insert = $admin->prepare('INSERT INTO products VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($layout->rows as $row) {
        $values = array_values((array)$row->values); $values[7] = $values[7] ? '1' : '0'; $insert->execute($values);
    }
    $insert->closeCursor(); $insert = null;
    $definitions = clone $layout->definitions; $definitions->physical = new stdClass();
    $observer = new MariaDbObservationProvider($connect, 'commercial-db', 'storage.products', 'arpcds', 'products', 2);
    $catalog = new PhysicalCatalog($dictionary, $definitions, ['observed'=>$observer]);
    check($catalog->resolve()->valid, 'resolved actual physical catalog');
    $fields = [];
    foreach ($catalog->resolve()->projection()->projections as $projection) {
        $fields[$projection->id] = array_map(static fn($f) => $f->name, $projection->fields);
    }
    $targets = [];
    foreach ($fields as $id => $names) $targets[$id] = [
        'projection'=>$id,'defaultLimit'=>2,'maxLimit'=>3,'fields'=>$names];
    $operations = ['LIST','GET']; $allowed = true;
    $readable = array_keys((array)$layout->rows[0]->values); $equalities = (object)['tenant_id'=>'7'];
    $authorizer = static function($target, $operation) use (&$allowed,&$readable,&$equalities,&$operations): array {
        return ['allowed'=>$allowed && in_array($operation, $operations, true),
            'fields'=>$readable,'equalities'=>$equalities];
    };
    $reader = new HookReader($connect, 2);
    $host = new ListGetHost($catalog, $reader, $targets, $authorizer);
    $invoke = function(string $operation, object|array $inputs, string $target = 'products.catalog',
        ?array $ctx = null) use ($host,$context,&$responses,$layoutName): array {
        $request = json_encode(['target'=>$target,'operation'=>$operation,'inputs'=>$inputs]);
        $result = $host->invoke($request, $ctx ?? $context);
        $responses[] = ['layout'=>$layoutName,'response'=>$result]; return $result;
    };
    $expectFailure = static function(array $result, string $code): void {
        check(!isset($result['output']) && $result['diagnostics'][0]['code'] === 'CDS_LISTGET_'.$code,
            'failure '.$code.' '.json_encode($result));
    };
    $first = $invoke('LIST', new stdClass()); check($first['status'] === 'success', 'native LIST '.json_encode($first));
    $all = []; $page = $first;
    do {
        foreach ($page['output']['records'] as $record) $all[] = $record;
        $next = $page['output']['page']['next'];
        if ($next !== null) $page = $invoke('LIST', ['continuation'=>$next]);
    } while ($next !== null);
    check(count($all) === 5, 'exact keyset traversal and SQL tenant restriction');
    $expectedRows = array_values(array_filter($layout->rows, static fn($r) => $r->id !== 'other-scope'));
    foreach ($all as $i => $record) {
        $wanted = new stdClass();
        foreach ($fields['products.catalog'] as $name) $wanted->$name = $expectedRows[$i]->values->$name;
        same($record['values'], $wanted, 'exact original fixture values');
        same($invoke('GET', ['key'=>$record['key']])['output']['record']['values'], $wanted, 'opaque GET roundtrip');
        check(strlen($record['key']['reference']) <= 128, 'schema token length');
    }
    $admin->exec('INSERT INTO products SELECT tenant_id,-9223372036854775808,sku,name,price,rating,description,'
        .'available,released,cost,internal_note FROM products WHERE product_id=2');
    $minimum = $invoke('LIST', ['limit'=>1]);
    check($invoke('GET', ['key'=>$minimum['output']['records'][0]['key']])['output']['found'],
        'native signed min token');
    $admin->exec('DELETE FROM products WHERE product_id=-9223372036854775808');
    $evidence[$layoutName] = ['keyset'=>5,'exactOriginalValues'=>true,'hiddenKeys'=>true];
    $expectFailure($invoke('LIST', ['continuation'=>$first['output']['page']['next'],'limit'=>3]), 'INVALID_CURSOR');
    $expectFailure($invoke('LIST', ['continuation'=>$first['output']['page']['next']], 'products.catalog',
        ['identity'=>'different-scope','policyRevision'=>'policy-1']), 'INVALID_CURSOR');
    same($invoke('GET', ['key'=>$all[0]['key']], 'products.catalog',
        ['identity'=>'other-principal','policyRevision'=>'policy-1'])['output'], ['found'=>false], 'reference binding');
    same($invoke('GET', ['key'=>['reference'=>$first['output']['page']['next']]])['output'],
        ['found'=>false], 'purpose');
    $identified = $invoke('LIST', new stdClass(), 'products.identified');
    check(isset($identified['output']['records'][0]['key']['components']), 'public typed key');
    $typed = $identified['output']['records'][0]['key'];
    check($invoke('GET', ['key'=>$typed], 'products.identified')['output']['found'], 'typed GET');
    $missing = $typed; $missing['components'][count($missing['components'])-1]['value'] = '99';
    same($invoke('GET', ['key'=>$missing], 'products.identified')['output'], ['found'=>false], 'missing key');
    $other = array_values(array_filter($layout->rows, static fn($r) => $r->id === 'other-scope'))[0];
    $denied = $typed;
    foreach ($denied['components'] as &$component) $component['value'] = $other->values->{$component['field']};
    unset($component);
    same($invoke('GET', ['key'=>$denied], 'products.identified')['output'], ['found'=>false], 'existing row denied');
    $clamped = $invoke('LIST', ['limit'=>100]);
    check($clamped['output']['page']['limit'] === 3 && count($clamped['output']['records']) === 3,
        'effective server limit');
    $expectFailure($invoke('LIST', new stdClass(), 'unknown-target'), 'UNAVAILABLE');
    check($invoke('LIST', new stdClass(), 'products.catalog', ['identity'=>'','policyRevision'=>'public'])['status']
        === 'success', 'public application context');
    $summary = $invoke('LIST', new stdClass(), 'products.summary');
    check(array_keys((array)$summary['output']['records'][0]['values']) === ['sku','name'], 'derived projection');
    $expectFailure($invoke('GET', ['key'=>$all[0]['key']], 'products.summary'), 'UNAVAILABLE');
    // Direct original requests/expectations; opaque fixture placeholders deliberately excluded.
    $directIds = ['get-unknown-reference','get-simple-typed-key','get-composite-typed-key',
        'get-missing-typed-key','get-raw-hidden-key-rejected',
        'key-null','key-incomplete','key-reordered','key-extra','key-number-not-string','key-wrong-type',
        'key-out-of-range','list-limit-zero','list-limit-negative','list-limit-bool','list-limit-fraction',
        'list-limit-too-large','list-limit-null','list-arbitrary-filter','list-arbitrary-sort','list-fields-injection',
        'list-offset-rejected','cursor-unknown','cursor-null','client-context-injection','input-null'];
    foreach ($vectors->cases as $vector) {
        if ($vector->layout !== $layoutName || !in_array($vector->id, $directIds, true)) continue;
        $projection = $vector->host->projection ?? 'products.catalog';
        $registration = $targets[$projection];
        $directHost = new ListGetHost($catalog, $reader, ['demo.products'=>$registration], $authorizer);
        $response = $directHost->invoke(Projection::encode($vector->request), $context);
        $responses[] = ['vector'=>$vector->id,'response'=>$response];
        // Array/object representation of the envelope is immaterial after JSON serialization.
        same(json_decode(json_encode($response)), $vector->expected->response, 'direct '.$vector->id);
        $evidence['direct'][] = $vector->id;
    }
    $operations = ['LIST'];
    $expectFailure($invoke('GET', ['key'=>$all[0]['key']]), 'UNAVAILABLE');
    check($invoke('LIST', new stdClass())['status'] === 'success', 'LIST permission independent of GET');
    $operations = ['LIST','GET'];
    $replacement = $targets; unset($replacement['products.summary']);
    $host->changeAccess($replacement, static fn() => null);
    $expectFailure($invoke('LIST', new stdClass(), 'products.summary'), 'UNAVAILABLE');
    check($invoke('LIST', new stdClass())['status'] === 'success', 'catalog survives reports removal');
    $host->changeAccess($targets, static fn() => null);
    $fresh = $invoke('LIST', new stdClass());
    same($invoke('GET', ['key'=>$fresh['output']['records'][0]['key']], 'products.catalog',
        ['identity'=>$context['identity'],'policyRevision'=>'policy-2'])['output'], ['found'=>false], 'policy binding');
    $catalog->invalidate();
    $expectFailure($invoke('GET', ['key'=>$fresh['output']['records'][0]['key']]), 'GENERATION_EXPIRED');
    $expectFailure($invoke('LIST', ['continuation'=>$fresh['output']['page']['next']]), 'GENERATION_EXPIRED');
    $allowed = false; $expectFailure($invoke('LIST', new stdClass()), 'UNAVAILABLE'); $allowed = true;
    $saved = $readable; $readable = ['sku'];
    $expectFailure($invoke('LIST', new stdClass()), 'UNAVAILABLE'); $readable = $saved;
    $equalities = null; $expectFailure($invoke('LIST', new stdClass()), 'CAPABILITY_UNAVAILABLE');
    $equalities = (object)['tenant_id'=>'9'];
    check($invoke('LIST', new stdClass())['output']['records'] === [], 'empty scope');
    $equalities = (object)['tenant_id'=>'7'];
    $reader->afterRows = static function() use ($catalog): void { $catalog->invalidate(); };
    $expectFailure($invoke('LIST', new stdClass()), 'GENERATION_CHANGED');
    $expectFailure($invoke('GET', ['key'=>$missing], 'products.identified'), 'GENERATION_CHANGED');
    $reader->afterRows = null;
    $reader->afterGuard = static function() use (&$allowed): void { $allowed = false; };
    $expectFailure($invoke('LIST', new stdClass()), 'UNAVAILABLE'); $allowed = true; $reader->afterGuard = null;
    $reader->afterRows = static function() use ($host,$targets): void {
        $host->changeAccess($targets, static fn() => null);
    };
    $expectFailure($invoke('LIST', new stdClass()), 'GENERATION_CHANGED'); $reader->afterRows = null;
    $sequence = 0;
    $faultConnect = static function() use ($dsn,$password,$options,&$sequence): PDO {
        // DATA, independent metadata observation, GUARD. Inject completion failures only in DATA/GUARD.
        $role = ++$sequence % 3;
        if ($role === 2) return new PDO($dsn, 'root', $password, $options);
        $pdo = new FaultPdo($dsn, 'root', $password, $options);
        $pdo->role = $role === 1 ? 'data' : 'guard';
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CursorFault::class]);
        return $pdo;
    };
    foreach (['commit-false','commit-throw','rollback-false','rollback-throw','begin-data-false',
        'begin-data-throw','begin-guard-false','begin-guard-throw','cursor-false','cursor-throw'] as $mode) {
        $sequence = 0;
        $faultHost = new ListGetHost($catalog, new MariaDbRecordReader($faultConnect, 2), $targets, $authorizer);
        FaultPdo::$mode = $mode;
        $result = $faultHost->invoke('{"target":"products.catalog","operation":"LIST","inputs":{}}', $context);
        $expectFailure($result, 'READ_FAILED'); $responses[] = ['fault'=>$mode,'response'=>$result];
    }
    FaultPdo::$mode = ''; $sequence = 0;
    $faultHost = new ListGetHost($catalog, new MariaDbRecordReader($faultConnect, 2), $targets, $authorizer);
    FaultPdo::$afterCommit = static function() use ($catalog): void { $catalog->invalidate(); };
    $expectFailure($faultHost->invoke('{"target":"products.catalog","operation":"LIST","inputs":{}}', $context),
        'GENERATION_CHANGED'); FaultPdo::$afterCommit = null;
    $catalog->resolve();
    foreach (['null','missing','duplicate'] as $malformation) {
        $reader->mutateRows = static function(array $rows) use ($malformation): array {
            if ($malformation === 'null') $rows[1]['sku'] = null;
            if ($malformation === 'missing') unset($rows[1]['sku']);
            if ($malformation === 'duplicate') $rows[1]['product_id'] = $rows[0]['product_id'];
            return $rows;
        };
        $expectFailure($invoke('LIST', new stdClass()), $malformation === 'duplicate'
            ? 'ROW_IDENTITY_INVALID' : 'ROW_DECODE_FAILED');
    }
    $reader->mutateRows = null;
    $reader->afterRows = static fn() => throw new PDOException('private SQL provider detail');
    $expectFailure($invoke('LIST', new stdClass()), 'READ_FAILED'); $reader->afterRows = null;
    $admin->exec('ALTER TABLE products ENGINE=MyISAM');
    $expectFailure($invoke('LIST', new stdClass()), 'CAPABILITY_UNAVAILABLE');
    $admin->exec('ALTER TABLE products ENGINE=InnoDB');
    $admin->exec('CREATE INDEX incomplete_prefix ON products(sku(3))'); $catalog->invalidate();
    $expectFailure($invoke('LIST', new stdClass()), 'CAPABILITY_UNAVAILABLE');
    $admin->exec('DROP INDEX incomplete_prefix ON products'); $catalog->invalidate();
    $admin->exec('UPDATE products SET available=2 WHERE product_id=10');
    $expectFailure($invoke('LIST', new stdClass()), 'ROW_DECODE_FAILED');
    $admin->exec('UPDATE products SET available=1 WHERE product_id=10');
    // Prove DATA and GUARD independently retain MDL against actual incompatible DDL.
    $blocked = 0;
    $ddl = static function() use ($connect,&$blocked): void {
        $connection = $connect(); $connection->exec('SET SESSION lock_wait_timeout=1');
        try {
            $connection->exec('ALTER TABLE products DROP COLUMN sku');
            throw new RuntimeException('DDL escaped MDL');
        }
        catch (PDOException $e) { check(($e->errorInfo[1] ?? null) === 1205, 'DDL lock timeout'); ++$blocked; }
    };
    $reader->afterRows = $ddl;
    check($invoke('LIST', new stdClass())['status'] === 'success', 'DATA MDL'); $reader->afterRows = null;
    FaultPdo::$afterCommit = $ddl;
    check($faultHost->invoke('{"target":"products.catalog","operation":"LIST","inputs":{}}', $context)['status']
        === 'success', 'GUARD holds MDL after DATA commit'); FaultPdo::$afterCommit = null;
    check($blocked === 2, 'two independent MDL witnesses');
    $beforeKeyChange = $invoke('LIST', new stdClass());
    $otherKey = $layoutName === 'composite' ? 'tenant_id,product_id,rating' : 'tenant_id,product_id';
    $admin->exec('ALTER TABLE products DROP PRIMARY KEY, ADD PRIMARY KEY('.$otherKey.')'); $catalog->invalidate();
    $expectFailure($invoke('GET', ['key'=>$beforeKeyChange['output']['records'][0]['key']]), 'GENERATION_EXPIRED');
    $expectFailure($invoke('LIST', ['continuation'=>$beforeKeyChange['output']['page']['next']]), 'GENERATION_EXPIRED');
    $admin->exec('ALTER TABLE products DROP PRIMARY KEY, ADD PRIMARY KEY('.$pk.')'); $catalog->invalidate();
    $admin->exec('ALTER TABLE products DROP PRIMARY KEY'); $catalog->invalidate();
    $expectFailure($invoke('LIST', new stdClass()), 'CAPABILITY_UNAVAILABLE');
    $admin->exec('ALTER TABLE products ADD PRIMARY KEY('.$pk.')'); $catalog->invalidate();
    $catalog->resolve();
    $admin->exec('ALTER TABLE products MODIFY tenant_id SMALLINT NOT NULL'); $catalog->invalidate();
    $expectFailure($invoke('LIST', new stdClass()), 'INCOMPATIBLE_STORAGE');
    $admin->exec('ALTER TABLE products MODIFY tenant_id INT NOT NULL'); $catalog->invalidate();
    $catalog->resolve();
    $admin->exec('ALTER TABLE products ADD COLUMN added INT NULL');
    $expectFailure($invoke('LIST', new stdClass()), 'GENERATION_CHANGED'); $catalog->invalidate();
    $rebuilt = $invoke('LIST', new stdClass()); check($rebuilt['status'] === 'success', 'rebuild added column');
    check(!property_exists($rebuilt['output']['records'][0]['values'], 'added'), 'new column stays hidden');
    $admin->exec('ALTER TABLE products DROP COLUMN sku'); $catalog->invalidate();
    $expectFailure($invoke('LIST', new stdClass()), 'INVALID_DEFINITION');
    $allowed = false; $expectFailure($invoke('LIST', new stdClass()), 'UNAVAILABLE'); $allowed = true;
    echo 'PASS MariaDB '.$layoutName
        ." fixture values, keyset, direct vectors, revocation, failures and DATA/GUARD DDL\n";
}
file_put_contents('/reports/listget-responses.json', json_encode($responses, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
file_put_contents('/reports/listget-evidence.json', json_encode($evidence, JSON_PRETTY_PRINT));