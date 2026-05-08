# Vendored UCP 2026-04-08 schemas

These JSON Schema files are vendored from the Universal Commerce Protocol
repository at a pinned commit, NOT live-fetched. CI runs offline and must
not depend on network for schema validation.

## Pinned commit

`63737330e46a08ff47e63c68379ee95043c76079` (branch: `release/2026-04-08`).

Source URL pattern:
```
https://raw.githubusercontent.com/Universal-Commerce-Protocol/ucp/<sha>/source/schemas/shopping/{path}
```

## When to update

Bump the pin only when:

1. We bump our `WC_AI_Storefront_Ucp::PROTOCOL_VERSION` constant to a newer
   spec revision, **or**
2. The current spec revision is updated in-place with non-breaking schema
   refinements (rare — UCP releases new dated revisions for breaking
   changes).

To re-vendor: see `bin/refresh-ucp-schemas.sh` (TODO if cadence becomes
frequent enough to warrant automation).

## Files

- `catalog_search.json` — `/catalog/search` request + response shapes
- `catalog_lookup.json` — `/catalog/lookup` request + response shapes,
  including the `lookup_variant` allOf merge that requires per-variant
  `inputs[]`
- `ucp.json` — top-level UCP envelope (`response_catalog_schema` referenced
  by both endpoint responses)
- `types/*.json` — referenced sub-types

`availability.json` was intentionally excluded — the spec defines it
inline on `variant.json`, not as a standalone file.
