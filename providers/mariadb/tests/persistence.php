<?php
declare(strict_types=1);
require __DIR__.'/../../../vendor/autoload.php';
use Alxarafe\Arp\Dictionary\{CatalogBuilder,Source,ExactInteger};
use Alxarafe\Arp\Dictionary\Internal\{Parser,Projection};
use Alxarafe\Arp\Cds\Physical\{MariaDbObservationProvider,ObservationException,PhysicalCatalog,
    PhysicalObservationProvider,PhysicalStoreKey,PhysicalYaml,FilePhysicalStore,PersistedPhysicalProvider};

final class PersistCounted implements PhysicalObservationProvider
{
    public int $count = 0;
    public ?Closure $after = null;
    public function __construct(private PhysicalObservationProvider $inner) {}
    public function providerId(): string { return $this->inner->providerId(); }
    public function observe(): object {
        ++$this->count; $value = $this->inner->observe(); ($this->after ?? static fn() => null)(); return $value;
    }
}
function check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('Persistence assertion: '.$label);
}
function reject(callable $action, string $label): void {
    $rejected = false; try { $action(); } catch (ObservationException) { $rejected = true; } check($rejected, $label);
}
$phase = $argv[1] ?? throw new RuntimeException('Explicit seed/recover phase required');
$root = getenv('ARP_CONTRACT_PATH'); $directory = getenv('ARP_PHYSICAL_STORE'); $password = getenv('ARP_DB_PASSWORD');
$dsn = 'mysql:host=db;port=3306;dbname=arpcds;charset=utf8mb4';
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>3,PDO::ATTR_EMULATE_PREPARES=>false];
$offline = false;
$connect = static function() use (&$offline,$dsn,$password,$options): PDO {
    return new PDO($offline ? str_replace('port=3306', 'port=1', $dsn) : $dsn, 'root', $password, $options);
};
$admin = $connect();
check($admin->query('SELECT token FROM __arp_cds_test_owner')->fetchColumn() === getenv('ARP_CDS_RUN_TOKEN'),
    'owned database');
if ($phase === 'seed') $admin->exec('CREATE TABLE persisted_items '
    .'(id INTEGER PRIMARY KEY, code VARCHAR(12) NOT NULL, rating SMALLINT NOT NULL)');
$key = new PhysicalStoreKey('tests', 'db-main', 'primary', 'storage.items', 'mariadb', 'arpcds', 'persisted_items');
$store = new FilePhysicalStore($directory, ['/work',$root]);
$counted = new PersistCounted(new MariaDbObservationProvider($connect, 'primary', $key->physicalId,
    $key->schema, $key->table));
$cases = Parser::parse(file_get_contents($root.'/fixtures/cds/read/v1/cases.json'), 'json')->cases;
$vector = array_values(array_filter($cases, static fn($c) => $c->id === 'mariadb-independent-identities'))[0];
$definitions = $vector->input; $original = Projection::encode($definitions);
$dictionary = (new CatalogBuilder())->buildCatalog([new Source('fixture', 'dictionary', 'json',
    file_get_contents($root.'/fixtures/dictionary/v1/catalog.json'))])->catalog;
$new = static fn($s, $k) => new PhysicalCatalog($dictionary, $definitions,
    ['storage'=>new PersistedPhysicalProvider($counted, $s, $k)]);
$catalog = $new($store, $key); $path = $store->fileFor($key);
check($counted->count === 0, 'lazy construction');
$result = $catalog->resolve(); check($result->valid, 'initial resolve');
if ($phase === 'recover') {
    check($counted->count === 0, 'new process zero observations');
    check(file_get_contents($directory.'/proof.json') === hash_file('sha256', $path), 'same volume checksum');
    echo "PERSISTENCE_RECOVERED: process/container restart, same YAML, zero observations\n"; exit(0);
}
$groups = [];
check($counted->count === 1 && is_file($path), 'first access generates');
check($result === $catalog->resolve() && $counted->count === 1, 'memory reuse');
check($new(new FilePhysicalStore($directory, ['/work',$root]), $key)->resolve()->valid
    && $counted->count === 1, 'new catalog disk reuse'); $groups[] = 'lazy-cache-and-restart';
$first = file_get_contents($path); $doc = PhysicalYaml::decode($first, $key);
check($first === PhysicalYaml::encode($doc, $key), 'deterministic YAML roundtrip');
check($first === PhysicalYaml::encode($counted->observe(), $key), 'same real observation');
$groups[] = 'deterministic-semantic-roundtrip';
foreach ([new PhysicalStoreKey('other','db-main','primary',$key->physicalId,$key->dialect,$key->schema,$key->table),
    new PhysicalStoreKey('tests','other-db','primary',$key->physicalId,$key->dialect,$key->schema,$key->table)]
    as $isolated) {
    check($store->fileFor($isolated) !== $path && $new($store,$isolated)->resolve()->valid, 'isolated identity');
}
check((new PhysicalStoreKey('tests','db-main','primary',$key->physicalId,$key->dialect,
    'other_schema',$key->table))->hash
    !== $key->hash, 'schema isolation');
check((new PhysicalStoreKey('tests','db-main','primary',$key->physicalId,$key->dialect,
    $key->schema,'other_table'))->hash
    !== $key->hash, 'table isolation'); $groups[] = 'identity-isolation';
$admin->exec('ALTER TABLE persisted_items ADD COLUMN new_column BIGINT UNSIGNED');
check(!str_contains(Projection::encode($catalog->resolve()->projection()), 'new_column'), 'explicit invalidation only');
$catalog->invalidate(); check(!is_file($path) && $catalog->current() === null, 'invalidate disk and memory');
$result = $catalog->resolve(); check($result->valid, 'regeneration'); $projection = $result->projection();
check(str_contains(Projection::encode($projection->models), 'new_column'), 'new effective field');
check(!str_contains(Projection::encode($projection->projections), 'new_column'), 'no public exposure');
check(Projection::encode($definitions) === $original, 'authored definitions untouched'); $groups[] = 'ddl-invalidation';
file_put_contents($path, "---\n{broken"); $corrupt = $new($store,$key);
check(!$corrupt->resolve()->valid && $corrupt->current() === null, 'corruption fail closed');
check($corrupt->resolve()->valid, 'corruption recovery');
$edited = PhysicalYaml::decode(file_get_contents($path), $key); $edited->source->engineVersion = 'tampered';
file_put_contents($path, PhysicalYaml::encode($edited, $key)); $tampered = $new($store,$key);
check(!$tampered->resolve()->valid && $tampered->resolve()->valid, 'checksum rejects valid edited YAML');
$groups[] = 'corruption-and-checksum';
chmod($path, 0000); $unreadable = $new($store,$key);
check(!$unreadable->resolve()->valid && $unreadable->current() === null, 'read failure no publication');
chmod($path, 0600); check($unreadable->resolve()->valid, 'read access recovery');
$groups[] = 'read-permission-recovery';
$catalog->invalidate(); $offline = true;
check(!$catalog->resolve()->valid && $catalog->current() === null && !is_file($path), 'no invalidated fallback');
$offline = false; check($catalog->resolve()->valid, 'native recovery');
$groups[] = 'failed-regeneration-no-stale-cache';
$catalog->invalidate(); $permissions = fileperms($directory) & 0777; chmod($directory, 0500);
check(!$catalog->resolve()->valid && $catalog->current() === null, 'temporary file creation denied');
chmod($directory, $permissions); check($catalog->resolve()->valid, 'write access recovery');
$groups[] = 'write-permission-recovery';
$state = $directory.'/'.$key->hash.'.lock'; file_put_contents($state, 'broken-state'); $badState = $new($store,$key);
check(!$badState->resolve()->valid && $badState->resolve()->valid, 'state recovery'); $groups[] = 'state-recovery';
$catalog->invalidate(); mkdir($path);
check(!$catalog->resolve()->valid && $catalog->current() === null, 'failed write no publication');
rmdir($path); check($catalog->resolve()->valid, 'write retry'); $groups[] = 'atomic-write-failure';
chmod($state, 0400); reject($catalog->invalidate(...), 'invalidation failure signalled');
check($catalog->current() === null && !$catalog->resolve()->valid, 'failed invalidation blocks reuse');
chmod($state, 0600); $catalog->invalidate(); check($catalog->resolve()->valid, 'durable invalidation retry');
$groups[] = 'failed-invalidation-blocks-reuse';
$catalog->invalidate(); $counted->after = $catalog->invalidate(...);
check(!$catalog->resolve()->valid && $catalog->current() === null && !is_file($path), 'in-flight invalidation');
$counted->after = null; check($catalog->resolve()->valid, 'new generation after race');
$groups[] = 'in-flight-invalidation';
reject(static fn() => new FilePhysicalStore('/work', ['/work',$root]), 'source root rejected');
$link = $directory.'/source-link'; symlink('/work', $link);
reject(static fn() => new FilePhysicalStore($link, ['/work',$root]), 'symlink root rejected'); unlink($link);
$catalog->invalidate(); symlink($root.'/examples/demo/cds/items.physical.yaml', $path);
check(!$catalog->resolve()->valid, 'symlink file rejected'); unlink($path);
check($catalog->resolve()->valid, 'symlink recovery'); $groups[] = 'source-and-symlink-protection';
$rejected = false;
try { new PhysicalStoreKey('tests',$dsn.$password,'primary',$key->physicalId,$key->dialect,$key->schema,$key->table); }
catch (InvalidArgumentException) { $rejected = true; }
check($rejected, 'DSN identity rejected');
foreach (glob($directory.'/*') as $file) {
    check(!str_contains(basename($file), $password)
        && !str_contains(file_get_contents($file), $password), 'no secrets');
    check(!str_ends_with($file, '.tmp'), 'temporary cleanup');
}
$groups[] = 'secret-integrity';
$exact = PhysicalYaml::decode(file_get_contents($path), $key);
$exact->columns[0]->nativeType = 'bigint'; $exact->columns[0]->scalarType = 'INT64';
$exact->columns[0]->integerRange = (object)['minimum'=>'-9223372036854775808','maximum'=>'9223372036854775807'];
$exact->columns[1]->characterLength->value = new ExactInteger('9223372036854775807');
check(str_contains(PhysicalYaml::encode($exact, $key), '9223372036854775807'), 'exact integer codec');
$groups[] = 'exact-integer-codec';
$application = sys_get_temp_dir().'/arp-cache-app-'.bin2hex(random_bytes(8));
mkdir($application); mkdir($application.'/cache/arp/physical', 0700, true);
mkdir($application.'/arp/models', 0700, true); mkdir($application.'/custom-cache');
$git = static function(array $args) use ($application): void {
    $process = proc_open(['git','--literal-pathspecs','-C',$application,...$args],
        [['file','/dev/null','r'],['file','/dev/null','w'],['file','/dev/null','w']], $pipes);
    check(is_resource($process) && proc_close($process) === 0, 'test application Git');
};
$git(['init','--quiet']); $local = $application.'/cache/arp/physical';
reject(static fn() => new FilePhysicalStore($local, [$application]), 'unignored application cache rejected');
file_put_contents($application.'/.gitignore', "cache/\narp/models/\ncustom-cache/\n");
$localStore = new FilePhysicalStore($local, [$application]); $observations = 0;
$observe = static function() use (&$observations,$doc): object { ++$observations; return $doc; };
$localStore->get($key, $observe);
(new FilePhysicalStore($local, [$application]))->get($key, $observe);
check($observations === 1, 'ignored application cache persisted');
$localStore->invalidate($key); $localStore->get($key, $observe);
check($observations === 2, 'application cache invalidation and regeneration');
reject(static fn() => new FilePhysicalStore($application.'/arp/models', [$application]),
    'ignored definitions are not authorized cache');
reject(static fn() => new FilePhysicalStore($application.'/custom-cache', [$application]),
    'custom cache requires authorization');
$custom = new FilePhysicalStore($application.'/custom-cache', [$application], [$application.'/custom-cache']);
$custom->get($key, $observe);
reject(static fn() => new FilePhysicalStore($local, [$application], [$application.'/custom-cache']),
    'cache outside authorized directory rejected');
$link = $application.'/linked-cache'; symlink($local, $link);
reject(static fn() => new FilePhysicalStore($link, [$application]), 'symlink cache rejected'); unlink($link);
file_put_contents($application.'/.gitignore', '');
reject(static fn() => $localStore->get($key, $observe), 'revoked ignore fails closed');
file_put_contents($application.'/.gitignore', "cache/\n");
$git(['add','-f','--',$localStore->fileFor($key)]);
reject(static fn() => $localStore->get($key, $observe), 'force-staged cache rejected');
$groups[] = 'git-ignored-application-cache';
// Remove only this test's disposable application, never a user checkout.
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($application,
    FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
rmdir($application);
file_put_contents($directory.'/proof.json', hash_file('sha256', $path));
echo 'PERSISTENCE_SEED: '.json_encode($groups, JSON_THROW_ON_ERROR)."\n";