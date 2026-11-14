# ARP PHP scalar dictionary

Standalone Composer library implementing the approved, unpublished ARP-DICT-001
candidate (`arp.dictionary/v1`), with all 17 scalar types. No ERP, web framework,
HTTP, SQL, ORM or persistence is required.

PHP 8.2+ syntax; development and acceptance runs use **PHP 8.5**. The only direct
runtime dependency is the independent `symfony/yaml` component, not Symfony's
framework. Its transitive dependencies are `symfony/polyfill-ctype` and
`symfony/deprecation-contracts`. JSON, exact numeric arithmetic and portable
patterns are implemented in this library; no BCMath or GMP extension is required.

## Public API

```php
use Alxarafe\Arp\Dictionary\CatalogBuilder;
use Alxarafe\Arp\Dictionary\Source;

$source = new Source('module.catalog', 'package:dictionary.json', 'json', <<<'JSON'
{"apiVersion":"arp.dictionary/v1","elements":[
  {"id":"finance.amount","type":"DECIMAL","constraints":{"precision":18,"scale":2}}
]}
JSON);

$build = (new CatalogBuilder())->buildCatalog([$source]);
if (!$build->isValid()) {
    // $build->issues includes contract codes and trusted source provenance.
    // $build->catalog is null: no partially built catalog is exposed.
    return;
}
$catalog = $build->catalog;
$resolved = $catalog->resolve('finance.amount');
$descriptor = $resolved['descriptor']; // detached object; constraints is always an object
$result = $catalog->validate('finance.amount', '1.2300');
// ['valid' => true, 'normalizedValue' => '1.23', 'issues' => []]
```

`Source` holds immutable bytes and opaque provider/source identities. Applications
supply explicit snapshots, including bytes obtained by their own file/package
adapters. The library does not discover modules, read files or fetch URLs.

`CatalogBuilder::buildCatalog(iterable<Source>): BuildResult` validates the complete
selection and rejects duplicate IDs. It returns either one immutable `Catalog`
or issues with no catalog. Definition errors stop at the first defect; the
contract deliberately does not require global ordering or accumulation.

`Catalog::resolve(string): array` returns `valid`, `issues`, and `descriptor` on
success. Unknown IDs return `DICTIONARY_ELEMENT_NOT_FOUND`.
`Catalog::validate(string, mixed): array` accepts the contract's portable values:
booleans for BOOLEAN and strings for all other types, including integers and
DECIMAL. Failure never contains `normalizedValue`. Constraint issues follow the
contractual order. No truthiness, trimming, rounding or native numeric coercion.

`Catalog::ids()` enumerates IDs in ascending ASCII order.
`Catalog::provenance(id)` returns detached diagnostic metadata or null.
`Catalog::descriptorJson(id)` exports the exact portable descriptor, or null for
an unknown ID. For metadata integers outside PHP's native range, `resolve()`
exposes an immutable `ExactInteger` with decimal `__toString()`; use
`descriptorJson()` to preserve the JSON number kind when exporting descriptors.

Snapshots have no active global registry. A consumer publishes a successful build
by replacing its own catalog reference; on failure it can retain the previously
identified generation. Different application/tenant contexts hold independent
catalogs. Mutating a returned descriptor cannot affect validation.

## Intake and limits

Accepted encodings are explicitly `json` and `yaml`. UTF-8, a single root object,
closed document/element shapes and duplicate-key rejection are enforced before
publication. Numeric constraint strings must be quoted in either encoding.

YAML supports block and flow maps/sequences, plain, single-quoted and double-quoted
scalars and comments. It rejects tags, directives, anchors, aliases, merge keys,
multiple documents and invalid Unicode. Block scalar syntax (`|`, `>`) and implicit
timestamp/legacy numeric spellings are outside this restricted subset: quote
such textual values. YAML 1.1 boolean words `yes/no/on/off` remain strings.
The subset is intentionally disclosed; the contract does not prescribe a complete
YAML grammar. Unsupported syntax is rejected, never interpreted as executable code.

Default limits: **1 MiB per source, 2048 elements per source**. Override using
`new CatalogBuilder(maxDocumentBytes: ..., maxElementsPerSource: ...)`.
The contract's 4096 numeric digit, 512 pattern character and 4096 pattern input
code-point ceilings apply independently. Source numeric exponent expansion is
limited to magnitude 4096. Resource/programming exceptions propagate; only
recognized contract failures become dictionary issues.

## Reproduce acceptance tests

The exact main commit after PR #4 is recorded in `contract.ref`:
`0172ab64742218b9f64a6ae96c43ebdfe690f4c6`. No contract release tag exists yet.
Tests read its original manifest and fixtures directly; expected outcomes are
not copied into this repository. The runner rejects a different SHA or edited
contract inputs. A deliberately unpaired surrogate fixture is represented as
invalid UTF-8 bytes at the PHP value boundary, without changing its expectation.

Use an independent checkout of that contract commit and set its path:

```sh
composer install
ARP_CONTRACT_PATH=/path/to/pinned/arp-contract composer test
ARP_CONTRACT_PATH=/path/to/pinned/arp-contract composer test:structure
```

The structural command needs Python 3 with the contract's `tools/requirements.txt`.
It runs the contract's own schema/integrity checks and checker regression tests;
these are separate from PHP semantic conformance.

The existing local PHP 8.5 image can run Composer and PHP tests in an isolated
container, without launching or modifying `arp-php-test` or any database:

```sh
docker run --rm --user "$(id -u):$(id -g)" \
  -v "$PWD:/app" -w /app arp-php-test-app:latest composer install --no-interaction

docker run --rm --network none --user "$(id -u):$(id -g)" \
  -v "$PWD:/app:ro" -v /path/to/pinned/arp-contract:/contract:ro \
  -e ARP_CONTRACT_PATH=/contract -w /app arp-php-test-app:latest composer test
```

See [architecture](docs/architecture.md) and [acceptance evidence](docs/conformance.md).
