# ARP-DICT-001-PHP-001 acceptance evidence

Date: 2026-11-14. Implementation branch: `feature/arp-dict-001-php`.
Contract: `alxarafe/arp-contract` main after merged PR #4, exact commit
`0172ab64742218b9f64a6ae96c43ebdfe690f4c6`; format `arp.dictionary/v1`.
This candidate is unpublished: results are against the pinned candidate, not a
claim of adoption of a published ARP release. The implementation commit is the
Git commit containing this report; its SHA is reported in the delivery.

## Functional results

| Group | Passed | Failed |
| --- | ---: | ---: |
| Baseline 17-type catalog | 1 | 0 |
| Portable values and normalization | 132 | 0 |
| Definition ingestion | 34 | 0 |
| Source intake and encoding | 21 | 0 |
| Portable pattern grammar | 45 | 0 |
| Pattern matching samples | 21 | 0 |
| Catalog scenario steps (13 scenarios) | 32 | 0 |
| Complete descriptor projections | 12 | 0 |
| **Shared fixture assertions** | **298** | **0** |
| Additional implementation runtime tests | 20 | 0 |

All manifest sets execute. No shared cases were skipped. The runner compares
actual results with original expectations, including issue order, absence of
normalization on failures, object/list distinction and full descriptors.
Diagnostic provenance fields are excluded only where the contract excludes them.

Runtime tests instrument detached descriptors, nested constraints/ENUM arrays,
readonly sources, provenance, failed rebuilds, isolated contexts, sorted IDs,
1 MiB/2048-element boundaries, duplicate escaped JSON keys, YAML duplicate-null
keys at multiple depths, safe YAML restrictions, model equivalence, surrogate and
UTF-8 rejection, all ASCII controls, bounded ambiguous patterns, exact 4096-digit
remainder, metadata beyond native integer range and integral JSON numbers.
A final test disables filesystem/network stream wrappers during bare validation.
These assertions extend implementation coverage without duplicating fixture
expectations.

## Structural checks, separately

The original contract checker passes Draft 2020-12 schema and fixture integrity
checks; its 12 regression tests pass. Its printed `NOT EXECUTED: semantic
conformance` line describes that checker's scope, not the PHP runner's results.
These checks do not substitute for PHP semantic execution.

## Contract feedback and exclusions

No contradiction was found in the supplied expected semantic outcomes. Suggested
common-contract additions (not changes made in this task):

1. Define the minimum accepted YAML 1.2 subset and add richer equivalence fixtures
   for quoted/plain scalars, numeric kinds, duplicate-null and escaped mapping
   keys. Today the contract deliberately permits a restricted subset but only
   supplies one simple positive YAML sample. This library documents its subset.
2. Add vectors for large integer length metadata and mathematically integral JSON
   numeric spellings, guarding against native parser precision/type changes.
3. Clarify numeric exponent/depth resource budgets for source metadata in addition
   to byte/element limits. This parser discloses its exponent expansion ceiling.
4. Expand shared temporal upper/lower UTC boundaries and parser security vectors;
   current passing fixtures remain representative rather than exhaustive proof.

No ERP integration, CRUD, SQL, UI, persistence, published-release adoption or
cross-language comparison was run: those are outside this task. No Java/.NET
support is inferred. No PHP 8.2/8.3/8.4 acceptance claim is made; PHP 8.5 is the
requested execution environment.

## Final execution environment and commands

PHP 8.5.12 CLI, Zend Engine 4.5.12, Composer 2.11.2 in the already-installed
`arp-php-test-app:latest` Docker image (isolated process; no application startup).
The final runtime run used no network and read-only mounts for both library and
contract. The host PHP 8.5.11 also passed the functional suites during development.
Composer dependency installation completed under PHP 8.5; PHP 8.4 was not used.
The initial system Composer/PHP 8.3 attempt failed and did not produce the final
lockfile or acceptance results.

```sh
# In the PHP 8.5 container, with the pinned main checkout mounted at /contract:
ARP_CONTRACT_PATH=/contract composer test
composer validate --strict
composer check-platform-reqs
find src tests -name '*.php' -exec php -l {} \;

# On the host, using the same pinned main checkout:
ARP_CONTRACT_PATH=/tmp/arp-contract-main bash tests/structure.sh
git diff --check
git check-ignore .openidea/test
```

All commands passed. Locked runtime packages: `symfony/yaml` 7.4.23,
`symfony/polyfill-ctype` 1.37.0, `symfony/deprecation-contracts` 3.8.1.
Original contract checkout and `arp-php-test` remain unmodified. The independent
`/tmp/arp-contract-main` checkout was obtained because the original checkout's
local main ref predates PR #4, while its checked-out feature tree matches the
merged main candidate. Final acceptance used the exact merged main SHA.
