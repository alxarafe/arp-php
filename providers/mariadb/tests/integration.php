<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';
use Alxarafe\Arp\Dictionary\{CatalogBuilder,Source};
use Alxarafe\Arp\Dictionary\Internal\{Parser,Projection};
use Alxarafe\Arp\Cds\Physical\{MariaDbObservationProvider,ObservationException,PhysicalCatalog,
    PhysicalObservationProvider};

final class CountingProvider implements PhysicalObservationProvider
{
    public int $count = 0;
    public ?Closure $after = null;
    public function __construct(private PhysicalObservationProvider $inner)
    {
    }
    public function providerId(): string
    {
        return $this->inner->providerId();
    }
    public function observe(): object
    {
        ++$this->count;
        $result = $this->inner->observe();
        ($this->after ?? static fn() => null)();
        return $result;
    }
}


$root = getenv('ARP_CONTRACT_PATH') ?: throw new RuntimeException('Contract required');
$password = getenv('ARP_DB_PASSWORD') ?: throw new RuntimeException('Owned database required');
$host = getenv('ARP_DB_HOST') ?: 'db';
$dsn = 'mysql:host=' . $host . ';port=3306;dbname=arpcds;charset=utf8mb4';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3, PDO::ATTR_EMULATE_PREPARES => false];
$admin = new PDO($dsn, 'root', $password, $options);
function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException('Assertion: ' . $label);
    }
}
function canonical(mixed $v): mixed
{
    if ($v instanceof stdClass) {
        $values = (array)$v;
        ksort($values, SORT_STRING);
        return ['object',array_map(canonical(...), $values)];
    }
    if (is_array($v)) {
        return ['array',array_map(canonical(...), $v)];
    }
    if (is_int($v) || $v instanceof \Alxarafe\Arp\Dictionary\ExactInteger) {
        return ['number',(string)$v];
    }
    return $v;
}
function same(mixed $a, mixed $b, string $label): void
{
    check(canonical($a) === canonical($b), $label);
}
function fixture(string $path): mixed
{
    global $root;
    return Parser::parse(file_get_contents($root . '/' . $path), 'json');
}
function names(array $fields): array
{
    return array_map(static fn($f) => $f->name, $fields);
}
check($admin->query('SELECT DATABASE()')->fetchColumn() === 'arpcds', 'owned database guard');
check(
    $admin->query('SELECT token FROM __arp_cds_test_owner')->fetchColumn() === getenv('ARP_CDS_RUN_TOKEN'),
    'owned run nonce required before DDL'
);
$admin->exec('CREATE TABLE sentinel(marker TEXT)');
$admin->exec("INSERT INTO sentinel VALUES ('unchanged')");
$setup = file_get_contents($root . '/examples/demo/sql/mariadb.sql');
$admin->exec($setup);
$engine = array_values(array_filter(
    fixture('fixtures/cds/read/v1/database-cases.json')->engines,
    static fn($e) => $e->engine === 'mariadb'
))[0];
$vector = array_values(array_filter(
    fixture('fixtures/cds/read/v1/cases.json')->cases,
    static fn($c) => $c->id === $engine->resolutionCase
))[0];
$definitions = $vector->input;
$original = Projection::encode($definitions);
$dictionary = (new CatalogBuilder())->buildCatalog([new Source(
    'fixture',
    'dictionary',
    'json',
    file_get_contents($root . '/fixtures/dictionary/v1/catalog.json')
)])->catalog;
$offline = false;
$connect = static function () use (&$offline, $dsn, $password, $options): PDO {
    return new PDO($offline ? str_replace('port=3306', 'port=1', $dsn) : $dsn, 'root', $password, $options);
};
$physicalId = $definitions->physical->storage->id;
$observer = static fn($name) => new CountingProvider(new MariaDbObservationProvider(
    $connect,
    'primary',
    $physicalId,
    'arpcds',
    $name
));
$new = static fn($p) => new PhysicalCatalog($dictionary, $definitions, ['storage' => $p]);
$provider = $observer('items');
$catalog = $new($provider);
$first = null;
$contract = [];
$additional = [];
$assertions = static function (object $result, object $spec) use ($definitions, $original): void {
    if (isset($spec->logicalFieldNames)) {
        same(
            names($result->models[0]->fields),
            $spec->logicalFieldNames,
            'logical names'
        );
    }
    foreach (['public','summary'] as $key) {
        $wanted = $key . 'FieldNames';
        if (isset($spec->$wanted)) {
            $id = $definitions->projections->$key->id;
            $projection = array_values(array_filter($result->projections, static fn($p) => $p->id === $id))[0];
            same(names($projection->fields), $spec->$wanted, $key . ' selection');
        }
    }
    check(Projection::encode($definitions) === $original, 'overlay bytes unchanged');
};
foreach ($engine->cases as $scenario) {
    switch ($scenario->id) {
        case 'lazy-generation':
            check($provider->count === 0, 'no eager observation');
            $first = $catalog->resolve();
            check($first->valid && $provider->count === 1, 'first observation');
            check($first === $catalog->resolve() && $provider->count === 1, 'cached generation');
            $result = $first->projection();
            $assertions($result, $scenario->assert);
            same($result->projections, $vector->expected->projections, 'exact projections');
            $physical = $result->models[0]->physical;
            $facts = $engine->observedFacts;
            foreach (['provider','dialect','name'] as $key) {
                same(
                    $physical->source->$key,
                    $facts->{'source.' . $key},
                    $key
                );
            }
            same($physical->source->schema, 'arpcds', 'scope');
            same($physical->source->engineVersion, $admin->query('SELECT VERSION()')->fetchColumn(), 'engine version');
            same(names($physical->columns), $facts->columnOrder, 'column order');
            foreach ($physical->columns as $column) {
                same($column->scalarType, $facts->scalarTypes->{$column->name}, 'scalar type');
                same($column->nullable, $facts->nullable->{$column->name}, 'nullable');
                check($column->nativeType !== '', 'observed native type');
            }
            same($physical->primaryKey, $facts->primaryKey, 'PK');
            same($physical->columns[2]->integerRange, $facts->{'rating.integerRange'}, 'range');
            same($physical->columns[1]->characterLength, $facts->{'code.characterLength'}, 'character length');
            same($result->models[0]->fields[2]->compatibility, 'compatible', 'proof');
            break;
        case 'add-column-and-invalidate':
            $admin->exec($scenario->ddl);
            check($first === $catalog->resolve() && $provider->count === 1, 'explicit invalidation required');
            $catalog->invalidate();
            check($catalog->current() === null && $provider->count === 1, 'lazy invalidation');
            $result = $catalog->resolve();
            check($result->valid && $provider->count === 2, 'regeneration');
            $assertions($result->projection(), $scenario->assert);
            same($result->projection()->projections, $first->projection()->projections, 'no exposure expansion');
            break;
        case 'remove-specialized-column-and-invalidate':
            $admin->exec($scenario->ddl);
            $catalog->invalidate();
            same($catalog->resolve()->projection(), $scenario->expected, 'removed bound column');
            check(
                $catalog->current() === null && Projection::encode($definitions) === $original,
                'no partial generation'
            );
            break;
        case 'observation-fails-after-invalidation':
            $offline = true;
            $catalog->invalidate();
            same($catalog->resolve()->projection(), $scenario->expected, 'real refused connection');
            check($catalog->current() === null, 'no stale generation');
            $offline = false;
            break;
        case 'invalidation-during-generation':
            $admin->exec('DROP TABLE items');
            $admin->exec($setup);
            $changing = null;
            $delayed = $observer('items');
            $delayed->after = static function () use (&$changing): void {
                $changing->invalidate();
            };
            $changing = $new($delayed);
            same($changing->resolve()->projection(), $scenario->expected, 'invalidation during real observation');
            check($changing->current() === null && $delayed->count === 1, 'no stale publication');
            break;
        default:
            throw new RuntimeException('Unexecuted contractual scenario: ' . $scenario->id);
    }
    $contract[] = $scenario->id;
}
$admin->exec('CREATE TABLE parent(a INT,b INT,PRIMARY KEY(a,b))');
$admin->exec('CREATE TABLE keys_table(a INT,b INT,note VARCHAR(12),PRIMARY KEY(a,b),
    CONSTRAINT fk_pair FOREIGN KEY(a,b) REFERENCES parent(a,b))');
$admin->exec('CREATE INDEX ix_note ON keys_table(note)');
$keys = $observer('keys_table')->observe();
same($keys->primaryKey, ['a','b'], 'composite PK order');
same($keys->foreignKeys[0]->columns, ['a','b'], 'composite FK local order');
same($keys->foreignKeys[0]->target->columns, ['a','b'], 'composite FK remote order');
check(in_array('ix_note', array_map(static fn($i) => $i->name, $keys->indexes), true), 'real index');
$additional[] = 'composite-keys-indexes-foreign-keys';
$admin->exec('CREATE INDEX ix_prefix ON keys_table(note(3))');
check(!isset($observer('keys_table')->observe()->indexes), 'unknown index description is absent, not empty');
$additional[] = 'unrepresentable-index-presence';
$admin->exec('CREATE TABLE types_test(wide BIGINT UNSIGNED, tiny TINYINT(1), amount DECIMAL(20,4),
    generated INT AS (1) STORED, ident INT PRIMARY KEY AUTO_INCREMENT)');
$types = $observer('types_test')->observe();
same($types->columns[0]->scalarType, 'INTEGER', 'observed unsigned 64 INTEGER');
same($types->columns[0]->integerRange->maximum, '18446744073709551615', 'exact unsigned extent');
same($types->columns[1]->scalarType, 'INTEGER', 'tinyint width is not boolean');
same($types->columns[2]->scalarType, 'DECIMAL', 'decimal scalar codec fact; editing domain remains unverified');
same($types->columns[2]->decimalGeometry, (object)['precision' => 20,'scale' => 4], 'observed geometry');
check($types->columns[3]->generated && $types->columns[4]->identity, 'generated/identity flags');
$additional[] = 'unsigned-int64-no-false-boolean-decimal-generated-identity';
$admin->exec('CREATE TABLE checked(value SMALLINT CHECK(value > 0))');
$checked = $observer('checked')->observe();
check(!isset($checked->columns[0]->scalarType) && !isset($checked->columns[0]->nullable), 'CHECK domain unknown');
$checkedDefinitions = (object)['physical' => new stdClass(), 'logical' => (object)['model' => (object)[
    'apiVersion' => 'arp.logical/v1','id' => 'demo.checked','physical' => $physicalId,
    'fields' => (object)['value' => (object)['dictionary' => 'test.int16']]]], 'projections' => new stdClass()];
$checkedCatalog = new PhysicalCatalog($dictionary, $checkedDefinitions, ['storage' => $observer('checked')]);
same(
    $checkedCatalog->resolve()->projection()->models[0]->fields[0]->compatibility,
    'not-verified',
    'CHECK cannot produce a false proof'
);
$additional[] = 'native-check-no-false-compatibility';

$admin->exec('CREATE TABLE `Odd.Table`(id INT)');
check(count($observer('Odd.Table')->observe()->columns) === 1, 'literal physical identifier');
$additional[] = 'literal-physical-identifiers';
$admin->exec("CREATE USER 'limited'@'%' IDENTIFIED BY '" . $password . "'");
$admin->exec("GRANT SELECT(id) ON arpcds.items TO 'limited'@'%'");
$limited = new PDO($dsn, 'limited', $password, $options);
check((int)$limited->query("SELECT count(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='arpcds'
    AND TABLE_NAME='items'")->fetchColumn() === 1, 'real partial column visibility');
$denied = new CountingProvider(new MariaDbObservationProvider(
    static fn() => new PDO($dsn, 'limited', $password, $options),
    'primary',
    $physicalId,
    'arpcds',
    'items'
));
same(
    $new($denied)->resolve()->projection(),
    (object)['valid' => false,'issues' => [(object)[
    'family' => 'execution-failure','code' => 'CDS_READ_OBSERVATION_FAILED','at' => '/providers/primary']]],
    'permission failure'
);
$admin->exec('CREATE TABLE visibility_test(id INT, hidden INT INVISIBLE)');
$admin->exec("GRANT SELECT(id) ON arpcds.visibility_test TO 'limited'@'%'");
check(count($observer('visibility_test')->observe()->columns) === 2, 'owner observes invisible physical columns');
try {
    $limited->query('SELECT * FROM visibility_test LIMIT 0');
    throw new RuntimeException('Expected whole-table privilege denial, including invisible columns');
} catch (PDOException) {
}
$hiddenDenied = new CountingProvider(new MariaDbObservationProvider(
    static fn() => new PDO($dsn, 'limited', $password, $options),
    'primary',
    $physicalId,
    'arpcds',
    'visibility_test'
));
check(!$new($hiddenDenied)->resolve()->valid, 'invisible columns cannot cause a falsely complete observation');
$additional[] = 'permissions-incomplete-metadata-no-partial-publication';
$admin->exec('CREATE VIEW view_test AS SELECT id FROM items');
foreach (['missing_table','view_test'] as $name) {
    try {
        $observer($name)->observe();
        throw new RuntimeException('Expected unavailable observation');
    } catch (ObservationException) {
    }
}
same($admin->query('SELECT marker FROM sentinel')->fetchColumn(), 'unchanged', 'unregistered object preserved');
check((int)$admin->query('SELECT count(*) FROM items')->fetchColumn() === 0, 'provider wrote no rows');
$additional[] = 'missing-view-readonly-and-sentinel-preservation';
$goodObserver = $observer('items');
$atomic = new PhysicalCatalog(
    $dictionary,
    $definitions,
    ['storage' => $goodObserver,'zz' => $observer('missing_table')]
);
same(
    $atomic->resolve()->projection(),
    (object)['valid' => false,'issues' => [(object)[
    'family' => 'execution-failure','code' => 'CDS_READ_OBSERVATION_FAILED','at' => '/providers/primary']]],
    'no partial result after a second provider fails'
);
check($atomic->current() === null && $goodObserver->count === 1, 'multi-provider atomic publication');
$additional[] = 'multi-provider-no-partial-publication';
$narrowInput = Parser::parse($original, 'json');
$narrowInput->logical->model->fields->id = (object)['dictionary' => 'test.int32'];
$narrowAuthored = Projection::encode($narrowInput);
$narrowed = new PhysicalCatalog($dictionary, $narrowInput, ['storage' => $observer('items')]);
$beforeNarrowing = $narrowed->resolve();
check($beforeNarrowing->valid, 'explicit 32-bit INTEGER before narrowing');
$admin->exec('DROP VIEW view_test');
$admin->exec('ALTER TABLE items MODIFY COLUMN id SMALLINT NOT NULL');
$narrowed->invalidate();
same(
    $narrowed->resolve()->projection(),
    (object)['valid' => false,'issues' => [(object)[
    'family' => 'physical-incompatible','code' => 'CDS_READ_DOMAIN_NOT_REPRESENTABLE','at' => '/logical/model/fields/id']]],
    'real narrowing is incompatible'
);
check($narrowed->current() === null && Projection::encode($narrowInput) === $narrowAuthored
    && Projection::encode($definitions) === $original, 'authored definitions preserved');
same(
    $beforeNarrowing->projection()->models[0]->fields[1]->physical->scalarType,
    'INTEGER',
    'old snapshot immutable after DDL'
);
$additional[] = 'real-narrowing-incompatible';
check(count($contract) === 5, 'all shared database cases executed');
echo json_encode(['stack' => 'php','contractCases' => $contract,'additionalCases' => $additional,
    'engineVersion' => $admin->query('SELECT VERSION()')->fetchColumn(),
    'contractSha' => trim(file_get_contents('contract.ref'))], JSON_THROW_ON_ERROR) . "\n";
