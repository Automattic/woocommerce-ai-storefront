# Vendored UCP 2026-04-08 schemas

These JSON Schema files are vendored from the Universal Commerce
Protocol specification at a pinned release, NOT live-fetched. CI
runs offline and must not depend on network for schema validation.

## Source

Canonical published spec:
```
https://ucp.dev/<release-date>/schemas/<path>
```

For the current pin (`2026-04-08`), example URLs:

- `https://ucp.dev/2026-04-08/schemas/ucp.json`
- `https://ucp.dev/2026-04-08/schemas/shopping/types/option_value.json`
- `https://ucp.dev/2026-04-08/schemas/shopping/catalog_lookup.json`

The published-spec URL is the most reliable source. If you need to
pin to a specific commit instead, the underlying repo lives at
[Universal-Commerce-Protocol/ucp](https://github.com/Universal-Commerce-Protocol/ucp);
its layout has shifted across releases, so trust the dated
`ucp.dev/<date>/schemas/<path>` URL when in doubt.

## When to update

Bump the vendored copies only when:

1. We bump our `WC_AI_Storefront_Ucp::PROTOCOL_VERSION` constant to
   a newer spec revision, **or**
2. The current spec revision is updated in-place with non-breaking
   schema refinements (rare — UCP releases new dated revisions for
   breaking changes).

## To re-vendor (manual)

There is no automation script today; if the cadence ever increases
enough to warrant one, drop a thin wrapper at
`bin/refresh-ucp-schemas.sh` that automates the steps below.

1. Pick the target release date on
   [ucp.dev](https://ucp.dev/) (or a successor revision when we
   bump `WC_AI_Storefront_Ucp::PROTOCOL_VERSION`).
2. For each file under `tests/fixtures/ucp-schemas/`, fetch the
   corresponding source via the URL pattern above. Both top-level
   schemas (`ucp.json`, `service.json`, etc.) and shopping-scoped
   schemas (`shopping/catalog_*.json`, `shopping/types/*.json`) are
   served from the same root.
3. **Rewrite `$id` values** to drop the date prefix so the schema
   resolver registered in `UcpShapeTest` (which uses
   `https://ucp.dev/schemas/shopping/` and
   `https://ucp.dev/schemas/`) can resolve them. Example:
   `"$id": "https://ucp.dev/2026-04-08/schemas/shopping/foo.json"`
   → `"$id": "https://ucp.dev/schemas/shopping/foo.json"`.
4. **Rewrite absolute `$ref`s** the same way — drop the date
   prefix and prefer relative paths for in-tree refs. Example:
   `"$ref": "https://ucp.dev/2026-04-08/schemas/ucp.json#/$defs/entity"`
   → `"$ref": "ucp.json#/$defs/entity"`. Refs that already use
   relative paths (`option_value.json`, `../ucp.json`) don't need
   rewriting.
5. Run `composer test -- --filter=UcpShapeTest` to confirm the
   resolver still resolves every reference. The resolver registers
   both `https://ucp.dev/schemas/` and
   `https://ucp.dev/schemas/shopping/` to the same fixtures
   directory, so files can be referenced via either prefix.

## Layout

The fixtures directory mirrors what the resolver sees, NOT the
published-spec URL hierarchy. Both `https://ucp.dev/schemas/` and
`https://ucp.dev/schemas/shopping/` map to the SAME directory, so
`shopping/types/foo.json` resolves to `types/foo.json` here.

- `catalog_search.json` — `/catalog/search` request + response
  shapes (referenced via `https://ucp.dev/schemas/shopping/catalog_search.json`)
- `catalog_lookup.json` — `/catalog/lookup` request + response
  shapes, including the `lookup_variant` allOf merge that requires
  per-variant `inputs[]`
- `ucp.json` — top-level UCP envelope. `response_catalog_schema`
  is what catalog response envelopes `$ref`; transitively pulls
  in `capability.json` for response-schema references.
- `service.json`, `capability.json`, `payment_handler.json` —
  UCP entity schemas referenced from `ucp.json#/$defs/manifest`.
  Vendored to keep the schema set self-consistent even though
  `UcpShapeTest` doesn't currently validate full manifest payloads.
- `transports/embedded_config.json` — embedded-transport config
  block referenced from `service.json` for the `embedded`
  transport variant.
- `types/*.json` — sub-types referenced from the higher-level
  schemas (option_value, selected_option, message_*, price,
  rating, available_payment_instrument, reverse_domain_name,
  context, etc.).

`availability.json` was intentionally excluded — the spec defines
it inline on `variant.json`, not as a standalone file.
