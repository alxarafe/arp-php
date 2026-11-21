# ARP PHP dictionary and CDS read metadata

Standalone Composer library implementing the approved, unpublished ARP-DICT-001
candidate (`arp.dictionary/v1`), with all 11 scalar types and exact INTEGER capacities. No ERP, web framework,
HTTP, SQL, ORM or persistence is required.

**PHP 8.5 is the minimum supported version**, with no upper-version restriction in Composer. PHP 8.4 and earlier are unsupported. Development and acceptance target PHP 8.5; later versions are permitted but are not automatically certified without tests. The only direct
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

YAML implements the mandatory ARP portable profile: block/flow maps and sequences,
one-line plain/single/double quoted scalars, JSON-compatible double-quote escapes,
and literal/folded blocks (`|`, `>`, clip/strip/keep, inferred indentation).
Only lowercase `null`, `true`, `false` and JSON numeric lexemes resolve implicitly;
dates, `yes/no/on/off`, `012`, `+1` and other plain spellings stay text. Tags,
directives, aliases, anchors, merges, duplicate keys, multiline plain/quoted scalars,
YAML-only escapes and explicit block indentation indicators are rejected.

Default limits: **1 MiB per source, 2048 elements per source, collection depth128**
(root collection is level1). Byte/element limits can be customized with
`new CatalogBuilder(maxDocumentBytes: ..., maxElementsPerSource: ...)`.
Source number tokens are limited to256 ASCII characters, absolute exponent128 and
absolute magnitude strictly below10^128, before native numeric conversion.
Admitted numbers retain mathematical precision and JSON Number kind. Numeric
constraint strings retain their independent4096-digit limit, as do the existing
512-character pattern and4096-code-point pattern-input ceilings.
Resource violations return `DICTIONARY_LIMIT_EXCEEDED`; unsupported syntax returns
`DICTIONARY_DOCUMENT_INVALID`.

## Reproduce acceptance tests

`contract.ref`, `listget-contract.ref` and `write-contract.ref` pin the merged INTEGER contract
`e3c2c08c93add63faebbe011a7e8e1ec3eccff2c` (arp-contract PR #20).
[ARP-INTEGER-002](docs/integer-002.md) documents the incompatible type replacement, verified capabilities
and remaining limitations. Earlier candidate/tag results remain historical evidence; no release is created here.
Tests read its original manifest and fixtures directly; expected outcomes are
not copied into this repository. The runner rejects a different SHA or edited
contract inputs. A deliberately unpaired surrogate fixture is represented as
invalid UTF-8 bytes at the PHP value boundary, without changing its expectation.

Use an independent checkout of that contract commit and set its path:

```sh
composer install
ARP_CONTRACT_PATH=/path/to/pinned/arp-contract \
  ARP_WRITE_CONTRACT_PATH=/path/to/pinned/arp-contract composer test
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
  -e ARP_CONTRACT_PATH=/contract -e ARP_WRITE_CONTRACT_PATH=/contract \
  -w /app arp-php-test-app:latest composer test
```

See [architecture](docs/architecture.md) and [acceptance evidence](docs/conformance.md).


## CDS read metadata

The pure resolver, generation publication API, exact shared scenarios and limits are documented in
[ARP-CDS-READ-002](docs/cds-read-002.md). This adds no HTTP, SQL, ORM or persistence dependency.

## Optional physical metadata provider

[ARP-CDS-READ-003](docs/cds-read-003.md) documents the independent real-database adapter, lazy generation,
explicit invalidation, optional physical YAML persistence/recovery, isolated Docker tests and the minimal
LIST/GET decisions still pending.
The pure dictionary/CDS resolver remains usable without SQL, HTTP, ORM or the adapter driver.

## LIST/GET con PDO y MariaDB

La lectura neutral y el proveedor opcional MariaDB 12.3 se describen en
[docs/sql-php-001.md](docs/sql-php-001.md), incluida la ejecución de pruebas sin BD y la integración real opcional.
Ese informe conserva la evidencia anterior. La [matriz INTEGER](docs/integer-conformance.json)
registra la cobertura actual contra los 93 vectores fijados por `listget-contract.ref`.

## Estilo de código

El código PHP sigue PSR-12. La configuración está en `phpcs.xml.dist` y las reglas de editor en `.editorconfig`:

```sh
composer lint   # comprueba PSR-12
composer fix    # aplica las correcciones automáticas
```

## Candidate managed writes on MariaDB

[ARP-CRUD-001B](docs/crud-001b.md) implements a real CREATE/UPDATE/DELETE path over the existing `demo.items`
definitions, catalog and LIST/GET providers. Writes use parameters, owned transactions, per-field UPDATE checks
and read-bound DELETE evidence. The [current matrix](docs/integer-conformance.json) distinguishes
52 native shared write cases from 65 unexecuted vectors. The
[earlier matrix](docs/crud-conformance.json) remains historical evidence.
INTEGER supports signed/unsigned 8/16/32/64-bit capacities without aliases; complete CRUD conformance is not claimed.
Run `providers/crud-docker.py` against the clean revision in `write-contract.ref` for reproducible isolated tests.

## Separación de pruebas CRUD y SQL

[ARP-TEST-001](docs/test-001.md) separa el replay genérico del núcleo, las obligaciones de puertos SQL y la
integración real. La [matriz por nivel](docs/test-001-conformance.json) conserva los 52/29 pases integrados
MariaDB y registra 94/49 pases genéricos, dos brechas conocidas y las omisiones, sin contar simulaciones como SQL.
`composer test:core` es estricto y falla por las dos brechas documentadas; `composer test:core:baseline`,
utilizado explícitamente por la suite y CI, comprueba la línea base sin marcarlas conformes.