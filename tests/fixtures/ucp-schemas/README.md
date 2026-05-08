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

## To re-vendor (manual)

There is no automation script today; if the cadence ever increases
enough to warrant one, drop a thin wrapper at
`bin/refresh-ucp-schemas.sh` that automates the steps below.

1. Pick a target commit on the
   [UCP repo](https://github.com/Universal-Commerce-Protocol/ucp)
   on the `release/2026-04-08` branch (or its successor when we
   bump `WC_AI_Storefront_Ucp::PROTOCOL_VERSION`).
2. Update the pinned SHA above.
3. For each file under `tests/fixtures/ucp-schemas/`, fetch the
   corresponding source via the URL pattern at the top of this
   document.
4. **Rewrite `$id` values** to drop the date prefix so the schema
   resolver registered in `UcpShapeTest` (which uses
   `https://ucp.dev/schemas/shopping/` and
   `https://ucp.dev/schemas/`) can resolve them. Example:
   `"$id": "https://ucp.dev/2026-04-08/schemas/shopping/foo.json"`
   → `"$id": "https://ucp.dev/schemas/shopping/foo.json"`.
   Cross-file `$ref` paths use relative names already
   (`option_value.json`, `../ucp.json`) and don't need rewriting.
5. Run `composer test -- --filter=UcpShapeTest` to confirm
   the resolver still resolves every reference.

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
