<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../integration/connect.php';

use Alxarafe\Arp\Dictionary\{CatalogBuilder,Source};
use Alxarafe\Arp\Dictionary\Internal\{Parser,Projection};
use Alxarafe\Arp\Dictionary\Cds\{ListGetHost,ReadFailure};
use Alxarafe\Arp\Cds\Physical\{PhysicalCatalog,MariaDbObservationProvider,MySqlObservationProvider,
    PostgreSqlObservationProvider,SqlServerObservationProvider,ObservationException};
use Alxarafe\Arp\MariaDb\MariaDbRecordReader;
use Alxarafe\Arp\MySql\MySqlRecordReader;
use Alxarafe\Arp\PostgreSql\PostgreSqlRecordReader;
use Alxarafe\Arp\SqlServer\SqlServerRecordReader;

function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
function same(mixed $a, mixed $b, string $label): void
{
    $canonical = function($v) use (&$canonical) {
        if ($v instanceof stdClass) { $v = (array)$v; ksort($v); return ['object',array_map($canonical, $v)]; }
        return is_array($v) ? array_map($canonical, $v) : $v;
    };
    check($canonical($a) === $canonical($b), $label.' '.json_encode([$a,$b], JSON_UNESCAPED_UNICODE));
}
function rowSql(string $sql): bool
{
    return str_contains($sql, ' ORDER BY ') && (str_ends_with($sql, ' LIMIT ?')
        || str_ends_with($sql, ' OFFSET 0 ROWS FETCH NEXT ? ROWS ONLY'));
}
final class TrackedStatement extends PDOStatement
{
    public static array $queries = [];
    public function closeCursor(): bool
    {
        $ok = parent::closeCursor();
        if (rowSql($this->queryString)) {
            self::$queries[] = $this->queryString;
            if (TestPdo::$mode === 'cursor-false') return false;
            if (TestPdo::$mode === 'cursor-throw') throw new PDOException('private cursor failure');
        }
        return $ok;
    }
}
class TestPdo extends PDO
{
    public static string $mode = '';
    public static ?Closure $afterCommit = null;
    public string $role = 'normal';
    public function beginTransaction(): bool
    {
        if (self::$mode === 'begin-'.$this->role.'-false') return false;
        if (self::$mode === 'begin-'.$this->role.'-throw') throw new PDOException('private begin failure');
        return parent::beginTransaction();
    }
    public function commit(): bool
    {
        if ($this->role === 'data' && self::$mode === 'commit-false') return false;
        if ($this->role === 'data' && self::$mode === 'commit-throw') throw new PDOException('private commit failure');
        $ok = parent::commit();
        if ($this->role === 'data') (self::$afterCommit ?? static fn() => null)();
        return $ok;
    }
    public function rollBack(): bool
    {
        $ok = parent::rollBack();
        if (self::$mode === 'rollback-throw') throw new PDOException('private rollback failure');
        return self::$mode === 'rollback-false' ? false : $ok;
    }
}
trait ReadHooks
{
    public ?Closure $afterRows = null;
    public ?Closure $afterGuard = null;
    public ?Closure $mutateRows = null;
    private int $protections = 0;
    protected function execute(PDO $connection, string $sql, array $parameters = []): array
    {
        $rows = parent::execute($connection, $sql, $parameters);
        if (rowSql($sql)) {
            ($this->afterRows ?? static fn() => null)($connection);
            if ($this->mutateRows) $rows = ($this->mutateRows)($rows);
        }
        return $rows;
    }
    protected function protect(PDO $connection, object $physical): void
    {
        parent::protect($connection, $physical);
        if (++$this->protections % 2 === 0) ($this->afterGuard ?? static fn() => null)();
    }
}
class PgTestReader extends PostgreSqlRecordReader
{
    use ReadHooks;
}
class MyTestReader extends MySqlRecordReader
{
    use ReadHooks;
}
class MariaTestReader extends MariaDbRecordReader
{
    use ReadHooks;
}
class ServerTestReader extends SqlServerRecordReader
{
    use ReadHooks;
}

$engine = getenv('ARP_SQL_ENGINE');
$root = getenv('ARP_LISTGET_CONTRACT_PATH') ?: throw new RuntimeException('Pinned contract required');
[$dsn,$user,$password,$driver,$schema] = endpoint($engine);
[$readerClass,$observerClass] = match ($engine) {
    'postgresql'=>[PgTestReader::class,PostgreSqlObservationProvider::class],
    'mysql'=>[MyTestReader::class,MySqlObservationProvider::class],
    'mariadb'=>[MariaTestReader::class,MariaDbObservationProvider::class],
    'sqlserver'=>[ServerTestReader::class,SqlServerObservationProvider::class]
};
$connect = static function(): PDO {
    $pdo = testConnection(TestPdo::class); $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [TrackedStatement::class]);
    return $pdo;
};
$admin = testConnection();
check($admin->query('SELECT token FROM __arp_cds_test_owner')->fetchColumn() === getenv('ARP_CDS_RUN_TOKEN'),
    'owned nonce before fixture DDL');
$products = Parser::parse(file_get_contents($root.'/fixtures/cds/listget/v1/products.json'), 'json');
$vectors = Parser::parse(file_get_contents($root.'/fixtures/cds/listget/v1/cases.json'), 'json');
$dictionary = (new CatalogBuilder())->buildCatalog([new Source('fixture','products','json',
    Projection::encode($products->dictionary))])->catalog;
check($dictionary !== null, 'original fixture dictionary');
$context = ['identity'=>'commercial-reader/company-7','policyRevision'=>'policy-1'];
$responses = []; $evidence = ['engine'=>$engine,'layouts'=>[],'direct'=>[],'features'=>[]];
$failure = static function(array $result, string $code): void {
    check(!isset($result['output']) && ($result['diagnostics'][0]['code'] ?? null) === 'CDS_LISTGET_'.$code,
        'expected '.$code.' '.json_encode($result));
};
foreach (['composite','simple'] as $layoutName) {
    $layout = $products->layouts->$layoutName;
    $admin->exec('DROP TABLE IF EXISTS products');
    $pk = $layoutName === 'composite' ? 'tenant_id,product_id' : 'product_id';
    $types = match ($engine) {
        'postgresql'=>'sku varchar(80),name varchar(200),price numeric(65,18),rating smallint,description text,'
            .'available boolean,released date,cost numeric(65,18),internal_note text',
        'mysql','mariadb'=>'sku varchar(80),name varchar(200),price decimal(65,18),rating smallint,description text,'
            .'available tinyint(1),released date,cost decimal(65,18),internal_note text',
        'sqlserver'=>'sku nvarchar(80),name nvarchar(200),price sql_variant,rating smallint,description nvarchar(max),'
            .'available bit,released date,cost sql_variant,internal_note nvarchar(max)'
    };
    $types = str_replace([',rating',',available',',released',',cost',',internal_note'],
        [' NOT NULL,rating',' NOT NULL,available',' NOT NULL,released',' NOT NULL,cost',' NOT NULL,internal_note'],
        $types);
    // Explicitly nullable description/internal_note; all remaining published fields are NOT NULL.
    $types = str_replace(['sku varchar(80),','sku nvarchar(80),','name varchar(200),','name nvarchar(200),',
        'description text NOT NULL,','description nvarchar(max) NOT NULL,'],
        ['sku varchar(80) NOT NULL,','sku nvarchar(80) NOT NULL,','name varchar(200) NOT NULL,',
        'name nvarchar(200) NOT NULL,','description text NULL,','description nvarchar(max) NULL,'], $types);
    $admin->exec('CREATE TABLE products(tenant_id int NOT NULL,product_id bigint NOT NULL,'.$types
        .',PRIMARY KEY('.$pk.'))'.(in_array($engine, ['mysql','mariadb'], true) ? ' ENGINE=InnoDB' : ''));
    $insertRow = static function(object $row) use ($admin,$engine): void {
        $values = array_values((array)$row->values); $values[7] = $values[7] ? '1' : '0';
        if ($engine === 'postgresql') $values[7] = $values[7] === '1' ? 'true' : 'false';
        $places = array_fill(0, 11, '?');
        if ($engine === 'sqlserver') foreach ([4,9] as $i) {
            // sql_variant preserves each original decimal within SQL Server's 38-digit per-value precision.
            $fraction = explode('.', $values[$i])[1] ?? '';
            $places[$i] = 'CONVERT(sql_variant,CONVERT(decimal(38,'.strlen($fraction).'),?))';
        }
        $statement = $admin->prepare('INSERT INTO products VALUES('.implode(',', $places).')');
        $statement->execute($values); check($statement->closeCursor(), 'fixture cursor closed');
    };
    foreach ($layout->rows as $row) $insertRow($row);
    $definitions = clone $layout->definitions; $definitions->physical = new stdClass();
    $original = Projection::encode($definitions);
    $observer = new $observerClass($connect, 'commercial-db', 'storage.products', $schema, 'products', 3);
    $catalog = new PhysicalCatalog($dictionary, $definitions, ['observed'=>$observer]);
    check($catalog->resolve()->valid, 'physical resolution '.$catalog->resolve()->toJson());
    $evidence['version'] = $catalog->resolve()->projection()->models[0]->physical->source->engineVersion;
    $fields = [];
    foreach ($catalog->resolve()->projection()->projections as $projection) {
        $fields[$projection->id] = array_map(static fn($f) => $f->name, $projection->fields);
    }
    $targets = [];
    foreach ($fields as $id => $names) $targets[$id] = [
        'projection'=>$id,'fields'=>$names,'defaultLimit'=>2,'maxLimit'=>3];
    $allowed = true; $operations = ['LIST','GET'];
    $readable = array_keys((array)$layout->rows[0]->values); $equalities = (object)['tenant_id'=>'7'];
    $authorizer = static function($target, $operation) use (&$allowed,&$operations,&$readable,&$equalities): array {
        return ['allowed'=>$allowed && in_array($operation, $operations, true),
            'fields'=>$readable,'equalities'=>$equalities];
    };
    $reader = new $readerClass($connect, 3);
    $host = new ListGetHost($catalog, $reader, $targets, $authorizer);
    $call = function(string $operation, object|array $inputs, string $target = 'products.catalog', ?array $ctx = null)
        use ($host,$context,&$responses,$layoutName): array {
        $response = $host->invoke(json_encode(['target'=>$target,'operation'=>$operation,'inputs'=>$inputs]),
            $ctx ?? $context);
        $responses[] = ['layout'=>$layoutName,'response'=>$response]; return $response;
    };
    $first = $call('LIST', new stdClass()); check($first['status'] === 'success', 'LIST '.json_encode($first));
    $all = []; $page = $first;
    do {
        foreach ($page['output']['records'] as $record) $all[] = $record;
        $next = $page['output']['page']['next'];
        if ($next !== null) $page = $call('LIST', ['continuation'=>$next]);
    } while ($next !== null);
    check(count($all) === 5, 'keyset restricted before limit');
    $expected = array_values(array_filter($layout->rows, static fn($r) => $r->id !== 'other-scope'));
    foreach ($all as $i => $record) {
        $wanted = new stdClass();
        foreach ($fields['products.catalog'] as $name) $wanted->$name = $expected[$i]->values->$name;
        same($record['values'], $wanted, 'original exact values and physical ordering');
        same($call('GET', ['key'=>$record['key']])['output']['record']['values'], $wanted, 'opaque GET');
        check(strlen($record['key']['reference']) <= 128, 'token budget');
    }
    $evidence['features']['fixture-values-keyset'] = true;
    $failure($call('LIST', ['continuation'=>$first['output']['page']['next'],'limit'=>3]), 'INVALID_CURSOR');
    $failure($call('LIST', ['continuation'=>$first['output']['page']['next']], 'products.catalog',
        ['identity'=>'different-scope','policyRevision'=>'policy-1']), 'INVALID_CURSOR');
    foreach ([['identity'=>'different-principal','policyRevision'=>'policy-1'],
        ['identity'=>$context['identity'],'policyRevision'=>'policy-2']] as $ctx) {
        same($call('GET', ['key'=>$all[0]['key']], 'products.catalog', $ctx)['output'], ['found'=>false], 'binding');
    }
    same($call('GET', ['key'=>['reference'=>$first['output']['page']['next']]])['output'], ['found'=>false], 'purpose');
    $public = $call('LIST', new stdClass(), 'products.identified');
    $typed = $public['output']['records'][0]['key'];
    check($call('GET', ['key'=>$typed], 'products.identified')['output']['found'], 'public typed GET');
    $other = array_values(array_filter($layout->rows, static fn($r) => $r->id === 'other-scope'))[0];
    $denied = $typed;
    foreach ($denied['components'] as &$component) $component['value'] = $other->values->{$component['field']};
    unset($component);
    same($call('GET', ['key'=>$denied], 'products.identified')['output'], ['found'=>false], 'existing row denied');
    $missing = $typed;
    $missing['components'][count($missing['components'])-1]['value'] = '123456';
    same($call('GET', ['key'=>$missing], 'products.identified')['output'], ['found'=>false], 'missing row');
    $summary = $call('LIST', new stdClass(), 'products.summary');
    check(array_keys((array)$summary['output']['records'][0]['values']) === ['sku','name'], 'derived projection');
    $failure($call('GET', ['key'=>$all[0]['key']], 'products.summary'), 'UNAVAILABLE');
    check($call('LIST', ['limit'=>100])['output']['page']['limit'] === 3, 'server clamp');
    $failure($call('LIST', new stdClass(), 'unknown-target'), 'UNAVAILABLE');
    check($call('LIST', new stdClass(), 'products.catalog', ['identity'=>'','policyRevision'=>'public'])['status']
        === 'success', 'public application');
    $direct = ['get-unknown-reference','get-simple-typed-key','get-composite-typed-key','get-missing-typed-key',
        'get-raw-hidden-key-rejected','key-null','key-incomplete','key-reordered','key-extra','key-number-not-string',
        'key-wrong-type','key-out-of-range','list-limit-zero','list-limit-negative','list-limit-bool',
        'list-limit-fraction','list-limit-too-large','list-limit-null','list-arbitrary-filter','list-arbitrary-sort',
        'list-fields-injection','list-offset-rejected','cursor-unknown','cursor-null','client-context-injection',
        'input-null'];
    foreach ($vectors->cases as $vector) {
        if ($vector->layout !== $layoutName || !in_array($vector->id, $direct, true)) continue;
        $projection = $vector->host->projection ?? 'products.catalog';
        $directHost = new ListGetHost($catalog, $reader, ['demo.products'=>$targets[$projection]], $authorizer);
        $response = $directHost->invoke(Projection::encode($vector->request), $context);
        same(json_decode(json_encode($response)), $vector->expected->response, 'direct '.$vector->id);
        $responses[] = ['vector'=>$vector->id,'response'=>$response]; $evidence['direct'][] = $vector->id;
    }
    $operations = ['LIST']; $failure($call('GET', ['key'=>$all[0]['key']]), 'UNAVAILABLE');
    check($call('LIST', new stdClass())['status'] === 'success', 'independent LIST'); $operations = ['LIST','GET'];
    $replacement = $targets; unset($replacement['products.summary']);
    $host->changeAccess($replacement, static fn() => null);
    $failure($call('LIST', new stdClass(), 'products.summary'), 'UNAVAILABLE');
    check($call('LIST', new stdClass())['status'] === 'success', 'catalog survives reports withdrawal');
    $host->changeAccess($targets, static fn() => null);
    $fresh = $call('LIST', new stdClass()); $catalog->invalidate();
    $failure($call('GET', ['key'=>$fresh['output']['records'][0]['key']]), 'GENERATION_EXPIRED');
    $failure($call('LIST', ['continuation'=>$fresh['output']['page']['next']]), 'GENERATION_EXPIRED');
    $allowed = false; $failure($call('LIST', new stdClass()), 'UNAVAILABLE'); $allowed = true;
    $saved = $readable; $readable = ['sku']; $failure($call('LIST', new stdClass()), 'UNAVAILABLE'); $readable = $saved;
    $equalities = null; $failure($call('LIST', new stdClass()), 'CAPABILITY_UNAVAILABLE');
    $equalities = (object)['tenant_id'=>'9']; check($call('LIST', new stdClass())['output']['records'] === [], 'empty');
    $equalities = (object)['tenant_id'=>'7'];
    $reader->afterRows = static function() use ($catalog): void { $catalog->invalidate(); };
    $failure($call('LIST', new stdClass()), 'GENERATION_CHANGED');
    $failure($call('GET', ['key'=>$missing], 'products.identified'), 'GENERATION_CHANGED'); $reader->afterRows = null;
    $reader->afterGuard = static function() use (&$allowed): void { $allowed = false; };
    $failure($call('LIST', new stdClass()), 'UNAVAILABLE'); $allowed = true; $reader->afterGuard = null;
    $reader->afterRows = static function() use ($host,$targets): void {
        $host->changeAccess($targets, static fn() => null);
    };
    $failure($call('LIST', new stdClass()), 'GENERATION_CHANGED'); $reader->afterRows = null;
    $sequence = 0;
    $faultConnect = static function() use (&$sequence): PDO {
        $role = ++$sequence % 3;
        $pdo = $role === 2 ? testConnection() : testConnection(TestPdo::class);
        if ($pdo instanceof TestPdo) $pdo->role = $role === 1 ? 'data' : 'guard';
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [TrackedStatement::class]); return $pdo;
    };
    foreach (['begin-data-false','begin-data-throw','begin-guard-false','begin-guard-throw',
        'commit-false','commit-throw','rollback-false','rollback-throw','cursor-false','cursor-throw'] as $mode) {
        $sequence = 0; TestPdo::$mode = $mode;
        $faultHost = new ListGetHost($catalog, new $readerClass($faultConnect, 3), $targets, $authorizer);
        $response = $faultHost->invoke('{"target":"products.catalog","operation":"LIST","inputs":{}}', $context);
        $failure($response, 'READ_FAILED'); $responses[] = ['fault'=>$mode,'response'=>$response];
    }
    TestPdo::$mode = ''; $sequence = 0;
    $faultHost = new ListGetHost($catalog, new $readerClass($faultConnect, 3), $targets, $authorizer);
    TestPdo::$afterCommit = static function() use ($catalog): void { $catalog->invalidate(); };
    $response = $faultHost->invoke('{"target":"products.catalog","operation":"LIST","inputs":{}}', $context);
    $failure($response, 'GENERATION_CHANGED'); $responses[] = ['response'=>$response]; TestPdo::$afterCommit = null;
    $catalog->resolve();
    foreach (['null','missing','duplicate'] as $malformation) {
        $reader->mutateRows = static function(array $rows) use ($malformation): array {
            if ($malformation === 'null') $rows[1]['sku'] = null;
            if ($malformation === 'missing') unset($rows[1]['sku']);
            if ($malformation === 'duplicate') $rows[1]['product_id'] = $rows[0]['product_id'];
            return $rows;
        };
        $failure($call('LIST', new stdClass()), $malformation === 'duplicate'
            ? 'ROW_IDENTITY_INVALID' : 'ROW_DECODE_FAILED');
    }
    $reader->mutateRows = null;
    $reader->afterRows = static fn() => throw new PDOException('private provider detail');
    $failure($call('LIST', new stdClass()), 'READ_FAILED'); $reader->afterRows = null;
    $bad = match ($engine) {
        'postgresql'=>"UPDATE products SET price='NaN' WHERE product_id=10",
        'sqlserver'=>"UPDATE products SET price=CONVERT(sql_variant,N'bad-decimal') WHERE product_id=10",
        default=>'UPDATE products SET available=2 WHERE product_id=10'
    };
    $admin->exec($bad); $failure($call('LIST', new stdClass()), 'ROW_DECODE_FAILED');
    $admin->exec('DELETE FROM products WHERE product_id=10'); $insertRow($expected[1]);
    $blocked = 0;
    $ddl = static function() use ($engine,&$blocked): void {
        $pdo = testConnection();
        $pdo->exec(match ($engine) {
            'postgresql'=>"SET lock_timeout='1s'",'sqlserver'=>'SET LOCK_TIMEOUT 1000',
            default=>'SET SESSION lock_wait_timeout=1'
        });
        try { $pdo->exec('ALTER TABLE products DROP COLUMN sku'); throw new RuntimeException('DDL escaped guard'); }
        catch (PDOException $e) {
            check($engine === 'postgresql' ? ($e->errorInfo[0] ?? '') === '55P03'
                : in_array((int)($e->errorInfo[1] ?? 0), [1205,1222], true), 'actual DDL timeout'); ++$blocked;
        }
    };
    $reader->afterRows = $ddl; check($call('LIST', new stdClass())['status'] === 'success', 'DATA DDL');
    $reader->afterRows = null; $sequence = 0; TestPdo::$afterCommit = $ddl;
    $response = $faultHost->invoke('{"target":"products.catalog","operation":"LIST","inputs":{}}', $context);
    check($response['status'] === 'success', 'GUARD DDL '.json_encode($response));
    $responses[] = ['response'=>$response]; TestPdo::$afterCommit = null;
    check($blocked === 2, 'both DATA and independent GUARD block incompatible DDL');
    $evidence['features']['DATA-GUARD-DDL'] = true;
    if ($engine === 'sqlserver') {
        $sequence = 0;
        TestPdo::$afterCommit = static function(): void {
            $pdo = testConnection(); $pdo->exec('SET LOCK_TIMEOUT 1000');
            try { $pdo->exec('UPDATE products SET rating=rating WHERE product_id=10');
                throw new RuntimeException('Expected table S writer block'); }
            catch (PDOException $e) { check((int)$e->errorInfo[1] === 1222, 'SQL Server writer lock timeout'); }
        };
        $response = $faultHost->invoke('{"target":"products.catalog","operation":"LIST","inputs":{}}', $context);
        check($response['status'] === 'success', 'writer blocked while GUARD held'); TestPdo::$afterCommit = null;
        $evidence['features']['writes-blocked-by-table-S'] = true;
    }
    $indexSql = match ($engine) {
        'postgresql'=>'CREATE INDEX unsupported_index ON products((lower(name)))',
        'sqlserver'=>'CREATE INDEX unsupported_index ON products(name) WHERE name IS NOT NULL',
        default=>'CREATE INDEX unsupported_index ON products(name(3))'
    };
    $admin->exec($indexSql); $catalog->invalidate();
    $failure($call('LIST', new stdClass()), 'CAPABILITY_UNAVAILABLE');
    $admin->exec($engine === 'sqlserver' || in_array($engine, ['mysql','mariadb'], true)
        ? 'DROP INDEX unsupported_index ON products' : 'DROP INDEX unsupported_index'); $catalog->invalidate();
    $dropPrimary = static function() use ($admin,$engine): void {
        if (in_array($engine, ['mysql','mariadb'], true)) {
            $admin->exec('ALTER TABLE products DROP PRIMARY KEY'); return;
        }
        $statement = $admin->query($engine === 'sqlserver'
            ? "SELECT kc.name FROM sys.key_constraints kc WHERE kc.parent_object_id=OBJECT_ID('dbo.products') "
                ."AND kc.type='PK'"
            : "SELECT conname FROM pg_catalog.pg_constraint "
                ."WHERE conrelid='public.products'::regclass AND contype='p'");
        $name = $statement->fetchColumn(); $statement->closeCursor();
        $quoted = $engine === 'sqlserver' ? '['.str_replace(']', ']]', $name).']'
            : '"'.str_replace('"', '""', $name).'"';
        $admin->exec('ALTER TABLE products DROP CONSTRAINT '.$quoted);
    };
    $dropPrimary(); $catalog->invalidate();
    $failure($call('LIST', new stdClass()), 'CAPABILITY_UNAVAILABLE');
    $admin->exec(match ($engine) {
        'postgresql'=>'ALTER TABLE products ALTER COLUMN tenant_id TYPE smallint',
        'sqlserver'=>'ALTER TABLE products ALTER COLUMN tenant_id smallint NOT NULL',
        default=>'ALTER TABLE products MODIFY tenant_id smallint NOT NULL'
    });
    $admin->exec('ALTER TABLE products ADD CONSTRAINT products_pk PRIMARY KEY('.$pk.')'); $catalog->invalidate();
    $failure($call('LIST', new stdClass()), 'INCOMPATIBLE_STORAGE');
    $dropPrimary();
    $admin->exec(match ($engine) {
        'postgresql'=>'ALTER TABLE products ALTER COLUMN tenant_id TYPE int',
        'sqlserver'=>'ALTER TABLE products ALTER COLUMN tenant_id int NOT NULL',
        default=>'ALTER TABLE products MODIFY tenant_id int NOT NULL'
    });
    $admin->exec('ALTER TABLE products ADD CONSTRAINT products_pk PRIMARY KEY('.$pk.')'); $catalog->invalidate();
    $evidence['features']['missing-PK-incomplete-index-incompatible-storage'] = true;
    // Verify the PostgreSQL ONLY addition and engine delimiters with genuine quoted table identifiers.
    $odd = $engine === 'postgresql' ? 'products FROM "edge' : ($engine === 'sqlserver'
        ? 'products] FROM edge' : 'products` FROM edge');
    $quote = static fn($name) => match ($engine) {
        'postgresql'=>'"'.str_replace('"', '""', $name).'"',
        'sqlserver'=>'['.str_replace(']', ']]', $name).']',
        default=>'`'.str_replace('`', '``', $name).'`'
    };
    if ($engine === 'sqlserver') $admin->exec("EXEC sp_rename N'dbo.products', N'".$odd."'");
    else $admin->exec('ALTER TABLE products RENAME TO '.$quote($odd));
    $oddObserver = new $observerClass($connect, 'commercial-db', 'storage.products', $schema, $odd, 3);
    $oddCatalog = new PhysicalCatalog($dictionary, $definitions, ['observed'=>$oddObserver]);
    $oddHost = new ListGetHost($oddCatalog, new $readerClass($connect, 3), $targets, $authorizer);
    $response = $oddHost->invoke('{"target":"products.catalog","operation":"LIST","inputs":{}}', $context);
    check($response['status'] === 'success', 'escaped table identifier '.json_encode($response));
    $responses[] = ['response'=>$response];
    if ($engine === 'sqlserver') $admin->exec("EXEC sp_rename N'dbo.".$quote($odd)."', N'products'");
    else $admin->exec('ALTER TABLE '.$quote($odd).' RENAME TO products');
    $catalog->invalidate(); $catalog->resolve();
    $evidence['features']['escaped-table-identifiers'] = true;
    $admin->exec('ALTER TABLE products ADD added int NULL');
    $failure($call('LIST', new stdClass()), 'GENERATION_CHANGED'); $catalog->invalidate();
    $rebuilt = $call('LIST', new stdClass()); check($rebuilt['status'] === 'success', 'rebuild');
    check(!property_exists($rebuilt['output']['records'][0]['values'], 'added'), 'added column stays hidden');
    $admin->exec('ALTER TABLE products DROP COLUMN sku'); $catalog->invalidate();
    $failure($call('LIST', new stdClass()), 'INVALID_DEFINITION');
    $allowed = false; $failure($call('LIST', new stdClass()), 'UNAVAILABLE'); $allowed = true;
    check(Projection::encode($definitions) === $original, 'logical sources not mutated');
    $evidence['layouts'][] = $layoutName;
    echo 'PASS '.$engine.' '.$layoutName." exact fixtures, keyset, authorization, failures, DATA/GUARD DDL\n";
}
// Explicit incomplete authority, using real restricted credentials on the final table.
$quotedPassword = "'".str_replace("'", "''", $password)."'";
foreach (match ($engine) {
    'postgresql'=>['CREATE ROLE limited LOGIN PASSWORD '.$quotedPassword,
        'GRANT USAGE ON SCHEMA public TO limited','GRANT SELECT(name) ON products TO limited'],
    'sqlserver'=>['CREATE LOGIN limited WITH PASSWORD='.$quotedPassword,'CREATE USER limited FOR LOGIN limited',
        'GRANT SELECT ON OBJECT::dbo.products TO limited'],
    default=>["CREATE USER 'limited'@'%' IDENTIFIED BY ".$quotedPassword,
        "GRANT SELECT(name) ON arpcds.products TO 'limited'@'%'"]
} as $sql) $admin->exec($sql);
$limitedConnect = static function() use ($dsn,$password): PDO {
    return new PDO($dsn, 'limited', $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
};
$limitedObserver = new $observerClass($limitedConnect, 'commercial-db', 'storage.products', $schema, 'products', 3);
$rejected = false;
try { $limitedObserver->observe(); } catch (ObservationException) { $rejected = true; }
check($rejected, 'incomplete metadata authority rejected');
$evidence['features']['incomplete-authority-rejected'] = true;
$evidence['features']['transaction-and-cursor-failures'] = true;
$evidence['features']['revocation-invalidation-no-partial-output'] = true;
$evidence['features']['derived-projection-and-independent-operations'] = true;
$evidence['direct'] = array_values(array_unique($evidence['direct']));
file_put_contents('/reports/responses.json', json_encode($responses, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
file_put_contents('/reports/evidence.json', json_encode($evidence, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
file_put_contents('/reports/queries.json', json_encode(TrackedStatement::$queries, JSON_PRETTY_PRINT));
echo 'PASS '.$engine." incomplete authority rejected; responses and queries recorded\n";