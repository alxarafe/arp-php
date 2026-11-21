# ARP-DICT-001 PHP acceptance evidence

**Nota de alcance:** este documento registra la implementación y evidencia anteriores a INTEGER.
[ARP-INTEGER-002](integer-002.md) y su [matriz](integer-conformance.json) describen el candidato activo;
las cifras, tipos y revisiones originales que siguen se conservan como evidencia histórica.

## PHP runtime support policy (2026-11-15)

The **supported minimum PHP version is 8.5** (`composer.json`: `>=8.5`); there is no upper-version constraint. PHP 8.4 and earlier are **not supported**, even though the earlier PHP 8.4.26 execution results remain recorded below as historical test evidence. Passing those historical tests does not extend the support policy. PHP 8.6 or newer is permitted by the Composer constraint but must be tested separately before any conformance claim.

This policy correction does not change the public API, scalar behavior, normative fixtures, or the RC1 contract pin.

## Candidate v0.1.0-rc.1 validation — 2026-11-15

Candidate: annotated remote tag `v0.1.0-rc.1` in `alxarafe/arp-contract`.
Exact contract commit: `4c0f4bd0337c01c15a036910b6462e6f0e674927`.
Remote tag object: `192dc1ce0ceab1a2b2f4545a4e327cde2e0fc5d5`.
`git ls-remote` returned that tag object and the required peeled commit;
`git cat-file -t/-p` independently confirmed an annotated tag targeting a commit.
This records candidate validation, not a final release or package publication.

PHP `main` was clean, updated from origin with `git pull --ff-only origin main`
to `ca097104c1ce188f19410af74b2d65d9b9e939fe`, and the new branch
`release/arp-dict-001-v0.1.0-rc1` was created there. That implementation commit
contains the tested dictionary code; the RC validation commit only updates
`contract.ref`, `README.md` and this evidence. Its exact identity is available via
`git log -1 --format=%H release/arp-dict-001-v0.1.0-rc1` and the joint delivery report.
The strict runners already read `contract.ref`; no runner-code update was needed.
No production code, API, tests, fixtures or expectations were changed.

The candidate was checked out detached in `/tmp/arp-contract-rc1-php`, cloned
from the available contract object store. The original `arp-contract` repository
was not modified. The PHP runner checks the clone's exact HEAD and clean inputs.

| Execution | Shared | PHP-specific | Failures / omissions |
| --- | ---: | ---: | ---: |
| PHP 8.4.26 / Zend 4.4.26 CLI NTS | 327/327 | 25/25 | 0 / 0 |
| PHP 8.5.11 / Zend 4.5.11 CLI NTS | 327/327 | 25/25 | 0 / 0 |

All nine shared groups ran, including all four source descriptor projections.
The unchanged specific tests cover concurrency/interleaving, isolation,
immutability, resource boundaries, exact arithmetic, Unicode and security.
Contract structural/schema/fixture checks passed (inventory 327) and all **27/27**
contract-tool regression methods passed against the exact candidate checkout.
Composer validation and installed platform checks also passed.

Environment: Linux 7.0.0-43-generic x86_64 GNU/Linux; Python 3.14.4;
installed locked dependencies `symfony/yaml` 7.4.23,
`symfony/polyfill-ctype` 1.37.0 and `symfony/deprecation-contracts` 3.8.1.
The installed Composer was executed with PHP 8.3.35, as previously required by
its `Normalizer` dependency; semantic acceptance used PHP 8.4 and 8.5 directly.
No dependencies were changed and no test failures or contract inconsistencies arose.

Commands executed (from the PHP repository unless `git -C` is shown):

```sh
git switch main
git pull --ff-only origin main
test -z "$(git status --porcelain)"
git switch -c release/arp-dict-001-v0.1.0-rc1
git ls-remote git@github.com:alxarafe/arp-contract.git refs/tags/v0.1.0-rc.1 'refs/tags/v0.1.0-rc.1^{}'
git clone --no-checkout --shared /home/rsanjose/Desarrollo/Alxarafe/arp-contract /tmp/arp-contract-rc1-php
git -C /tmp/arp-contract-rc1-php checkout --detach 4c0f4bd0337c01c15a036910b6462e6f0e674927
git -C /tmp/arp-contract-rc1-php cat-file -t 192dc1ce0ceab1a2b2f4545a4e327cde2e0fc5d5
git -C /tmp/arp-contract-rc1-php cat-file -p 192dc1ce0ceab1a2b2f4545a4e327cde2e0fc5d5
ARP_CONTRACT_PATH=/tmp/arp-contract-rc1-php php8.4 tests/conformance.php
php8.4 tests/runtime.php
ARP_CONTRACT_PATH=/tmp/arp-contract-rc1-php php8.5 tests/conformance.php
php8.5 tests/runtime.php
ARP_CONTRACT_PATH=/tmp/arp-contract-rc1-php bash tests/structure.sh
php8.3 /usr/bin/composer validate --strict
php8.3 /usr/bin/composer check-platform-reqs
git diff --check
```

No push, merge, tag creation, package publication or consumer application change
was performed. Earlier evidence below is historical and uses its stated older pin.

## Revalidation and compact-block correction — 2026-11-15

Updated `main` from origin at `3799020` before this work. The requested branch
already existed at `0fef047` with identical files but a different history after
the upstream squash. It was preserved as
`archive/arp-dict-001-portability-002-0fef047`; the requested feature branch was
created from updated `main`. No merge, publication or history deletion was used.

The existing consolidated implementation already passed all **327 shared checks**.
Review found one additional YAML defect: when a compact sequence mapping begins
with a block scalar (`- description: |`), its sibling fields were treated as block
content by the lexical guard. Symfony then resolved those fields natively; for
example a following plain date label no longer remained text. The block boundary
now uses the mapping's indentation rather than the sequence dash's indentation.
The new regression covers literal/folded blocks, two compact spacing variants,
exact text projections, boolean kinds, forbidden anchors and numeric limits.
Public APIs, dependencies and exact arithmetic are unchanged.

Current verification supersedes the historical environment/counts below:

- PHP 8.5.11: **327/327 shared**, **25/25 runtime groups**, zero failures/skips.
- PHP 8.4.26: **327/327 shared**, **25/25 runtime groups**, zero failures/skips.
- `bash tests/structure.sh`: schema/fixture integrity PASS, inventory 327;
  independent checker regressions **27/27 PASS** at the pinned contract commit.
- PHP lint of every source/test file and `git diff --check`: PASS.
- `php8.3 /usr/bin/composer validate --strict` and `check-platform-reqs`: PASS.
  The system Composer under default PHP 8.5 fails because that CLI lacks
  `Normalizer`; running the same installed Composer under PHP 8.3 resolves the
  tooling issue without changing the library or its dependencies.

Files changed in this follow-up: `src/Internal/Parser.php`, `tests/runtime.php`,
`docs/conformance.md`. The contract remains pinned to
`2dde1a09780e29c7385606c5e018fa75a0808b68`; its inputs and expectations were not
modified. No other runtime implementation was consulted.

## Historical consolidated implementation evidence

Date: 2026-11-14. Branch: `feature/arp-dict-001-portability-002`.
Authoritative contract: `2dde1a09780e29c7385606c5e018fa75a0808b68`, recorded in
`contract.ref`. No release or publication is claimed.

| Shared group | Passed | Failed | Not executed |
| --- | ---: | ---: | ---: |
| Base catalog | 1 | 0 | 0 |
| Values | 136 | 0 | 0 |
| Definitions | 36 | 0 | 0 |
| Sources | 40 | 0 | 0 |
| Source descriptor projections | 4 | 0 | 0 |
| Patterns | 45 | 0 | 0 |
| Pattern samples | 21 | 0 | 0 |
| Catalog steps | 32 | 0 | 0 |
| Catalog descriptor projections | 12 | 0 | 0 |
| **Total** | **327** | **0** | **0** |

The runner reads the original manifest and fixtures directly, including all four
source descriptor projections. Its independent fixture-number reader preserves
JSON Number kind and compares canonical coefficient/exponent pairs mathematically;
booleans remain distinct. Expectations are neither copied nor generated by a
runtime implementation. The existing invalid-surrogate adaptation is retained.

## PHP-specific tests

**24 passed, zero failed, zero skipped**. All20 previous test groups remain,
with former rejection expectations for plain dates/legacy spellings and block text
updated because the consolidated contract explicitly requires accepting them.
Existing coverage includes defensive copies, atomicity, context isolation,
readonly sources, byte/element limits, JSON/YAML duplicate-null/escaped keys,
Unicode, bounded patterns, exact4096-digit arithmetic, numeric descriptor export,
and validation with all filesystem/network stream wrappers disabled.
New groups cover numeric token/exponent/magnitude boundaries including padded and
zero exponents, exact small fractions, YAML JSON escapes and clip/strip/keep blocks,
eight interleaved independent Fiber builds/validations, typed-key rejection, plain
text punctuation, compact-sequence indentation and preparse block-depth128/129.

## Environment and reproduction

Acceptance: PHP8.5.12 CLI/Zend4.5.12 and Composer2.11.2, existing image
`arp-php-test-app:latest`, isolated container, no network, read-only library and
contract mounts. Development also passed PHP8.5.11. No PHP8.4 execution.
Dependencies unchanged: symfony/yaml7.4.23, polyfill-ctype1.37.0,
deprecation-contracts3.8.1; no framework or infrastructure dependency added.

```sh
ARP_CONTRACT_PATH=/contract composer test
composer validate --strict
composer check-platform-reqs
find src tests -name '*.php' -exec php -l {} \;
git diff --check
```

Separately, the coordinating task ran the contract's `check_contract.py` and its
27 checker regression methods at the same exact commit: all passed, inventory327.
Those structural results are distinct from the actual PHP semantic run above.

## Contract observations and scope

No blocking contradiction or changed public API was required. YAML's native
Symfony implicit resolver is bypassed for scalar values: dates/legacy booleans
and numeric spellings otherwise differ from the common profile. Double quoted
strings are decoded using JSON escapes before Symfony sees marker strings; block
text is delegated to Symfony's mature indentation/folding/chomping implementation.
Exact source numbers are bounded before expansion, retaining the prior scalar
4096-digit arithmetic capability. Integer-number spellings1/1.00/0.1e1 compare
mathematically. No contract, Java, .NET, arp-php-test or application repository was
modified. No push, PR, tags, packages, database or application startup occurred.