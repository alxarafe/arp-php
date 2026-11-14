# Internal architecture

Public namespace: `Alxarafe\Arp\Dictionary` (Composer PSR-4).

- `Source`: immutable caller-provided bytes and diagnostic identities.
- `CatalogBuilder`: explicit source selection, parser dispatch, structural and
  semantic definition checks, duplicate registration, all-or-nothing construction.
- `BuildResult`: immutable catalog-or-issues envelope with portable JSON outcome.
- `Catalog`: immutable sorted snapshots; lookup, exact descriptor export and scalar
  validation. Accessors return detached data. No global cache or active catalog.
- `ExactInteger`: exact metadata integers beyond native PHP integer limits.

Internal helpers are implementation details, not supported extension APIs:

- `Parser` validates JSON syntax/Unicode, walks JSON independently to reject
  duplicate keys and preserve large integer kinds, and guards restricted YAML
  before using Symfony YAML with object construction disabled. Extra flow-map
  checks and scoped deprecation handling close Symfony 7 duplicate-null-key gaps.
- `Definition` enforces recognized properties, identity, applicability, numeric
  domains, bounds, length consistency, geometry and ENUM literal consistency.
- `Number` normalizes decimal strings and compares aligned digits/signs. Exact
  divisibility uses decimal long remainder with single-digit arithmetic; no float,
  platform integer range, database or external arithmetic extension is involved.
- `Scalar` checks kind/lexeme before constraints, validates temporal components
  explicitly, applies exact UTC offset arithmetic and preserves seven fractional
  digits outside PHP's six-digit datetime fraction storage. Unicode length counts
  scalar values; Base64 must round-trip exactly.
- `Pattern` implements the formal ASCII grammar and a prefix-sum position-set
  matcher in O(atoms × input bytes), with no host-engine backtracking semantics.
- `Projection` exports exact JSON numbers for integer metadata.
- `Failure` carries expected contract rejection codes only. Unrelated exceptions
  are not converted into validation errors.

The library deliberately contains no context manager, discovery convention, ERP
adapter, filesystem provider, field binder, persistence layer, CRUD or resources.
Applications own those choices and publish successful catalog generations.
