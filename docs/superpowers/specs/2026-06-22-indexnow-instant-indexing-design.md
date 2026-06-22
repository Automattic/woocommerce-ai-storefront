# IndexNow Instant Indexing — Design

**Status:** Draft for review
**Date:** 2026-06-22
**Author:** Brainstormed with Claude
**Related:** discovery surfaces ([`class-wc-ai-storefront-llms-txt.php`](../../../includes/ai-storefront/class-wc-ai-storefront-llms-txt.php)), cache invalidation ([`class-wc-ai-storefront-cache-invalidator.php`](../../../includes/ai-storefront/class-wc-ai-storefront-cache-invalidator.php))

## Goal

When a merchant's catalog changes, immediately notify IndexNow-participating search engines so they re-crawl the affected pages and AI-discovery surfaces — closing the gap between "catalog changed" and "the index (and the AI assistants built on it) knows."

## Why this fits WooCommerce AI Storefront

IndexNow is a push protocol: submit a changed URL to one participating endpoint and it is shared with all participants. **Google does not consume IndexNow** — so this is explicitly *not* a Google-SEO feature. It is valuable here because the participants include **Bing** (which powers **ChatGPT Search** and **Microsoft Copilot**) and **Amazonbot**. IndexNow is therefore a freshness pipe into the AI shopping channel this plugin exists to serve.

The differentiator over generic SEO-plugin IndexNow support (Yoast / Rank Math / AIOSEO all have it): we submit not just product HTML pages but our **AI-discovery surfaces** (`llms.txt`, `products.json`, homepage, `/shop/`) on catalog change. No generic IndexNow plugin does that, and bundling it keeps the "hand AI discoverability to AI Storefront" positioning coherent — merchants shouldn't need a second plugin.

Participants (from the live `https://www.indexnow.org/searchengines.json`): Bing, Yandex, Seznam, Naver, Yep, Internet Archive, Amazonbot.

## Decisions (locked during brainstorming)

1. **Submit scope:** products + AI-discovery surfaces (not just product HTML; not per-product JSON feeds).
2. **Enablement:** a dedicated "Notify search engines instantly (IndexNow)" toggle, **default ON but only active when AI syndication is already enabled**. Key auto-generated, shown read-only with a regenerate action.
3. **Submission mechanism:** debounced WP-Cron bulk POST — reuse the existing WP-Cron pattern (`wp_next_scheduled`) that `cache-invalidator` already uses, not Action Scheduler (not used anywhere in this codebase today).
4. **Key-file hosting:** a virtual `{key}.txt` route served through the same rewrite-rule + `template_redirect` mechanism as `llms.txt` (works on Atomic / WordPress.com, where we cannot write a file at the docroot).
5. **Endpoint:** POST to `https://api.indexnow.org/indexnow` (the shared endpoint that propagates to all participants).

## Architecture

A single new class, `WC_AI_Storefront_IndexNow` (`includes/ai-storefront/class-wc-ai-storefront-indexnow.php`), owns the whole feature. It mirrors the structure and responsibility-size of `llms-txt.php` and `cache-invalidator.php`. Responsibilities, each independently testable:

| Unit | Responsibility | Depends on |
|------|----------------|------------|
| Key lifecycle | Generate / read / regenerate the 32-char hex key under `SETTINGS_OPTION` | options |
| Key-file route | `add_rewrite_rule` + query var + `template_redirect` handler serving `{key}.txt` | WP rewrite |
| Change subscriber | Hook catalog-change events; resolve affected URLs; enqueue; schedule flush | WC hooks |
| Pending queue | Append-and-dedupe a pending-URL set; read-and-clear on flush | options/transient |
| Flush handler | On cron: read+clear queue, apply exclusions, build payload, POST | `wp_remote_post` |
| Gate | Is syndication on AND IndexNow toggle on? | settings |

### Data flow

```
product/category/shop change
   → change subscriber resolves URL(s) + the AI-surface URLs
   → enqueue() appends to pending set (deduped)
   → schedule_flush() ensures one cron event is scheduled (wp_next_scheduled guard)
        … (debounce window: multiple changes collapse into one batch) …
   → cron fires → flush():
        gate check → read+clear pending set → exclusion filter
        → build { host, key, urlList } → POST api.indexnow.org/indexnow
        → log outcome
```

A 1,000-product bulk import enqueues thousands of `enqueue()` calls but schedules **one** flush; the flush dedupes and POSTs a single batch (≤ 10,000 URLs per the spec). This respects both IndexNow's `429` spam guard and the documented edge burst-throttling behaviour.

## Components in detail

### 1. Key lifecycle

- Key: 32 lowercase hex chars (within the spec's 8–128 `[a-zA-Z0-9-]` range), generated with `wp_generate_password( 32, false, false )`-style hex or `bin2hex( random_bytes( 16 ) )`.
- Stored as `indexnow_key` inside the existing `SETTINGS_OPTION` array. Generated lazily on first enable if absent.
- Regenerate action clears the stored key, generates a new one, and triggers a rewrite flush (the old `{key}.txt` route stops resolving; the new one starts).

### 2. Key-file route

- Rewrite rule: `^([a-fA-F0-9-]{8,128})\.txt$` → `index.php?wc_ai_storefront_indexnow_key=$matches[1]`.
- Query var registered alongside the existing llms.txt/agents.md vars.
- Handler on `template_redirect`: if `get_query_var('wc_ai_storefront_indexnow_key')` equals the stored key exactly → `status_header(200)`, `Content-Type: text/plain; charset=utf-8`, body is the key, `exit`. Any mismatch → fall through (404). This scoping prevents the route from shadowing legitimate `*.txt` requests other than the active key.
- The rule is registered unconditionally so it survives even while syndication is off (parity with how `cache-invalidator` registers hooks unconditionally); the *submission* path is what the gate controls.

### 3. Change subscriber

Subscribe to the same catalog-change events `cache-invalidator` already trusts:

- `woocommerce_update_product`, `woocommerce_new_product`, `woocommerce_trash_product`, `woocommerce_delete_product`
- `woocommerce_product_set_stock_status`
- `created_product_cat`, `edited_product_cat`, `delete_product_cat`

For each event, resolve the changed URL(s):

- product → `get_permalink( $product_id )`
- category → `get_term_link( $term_id, 'product_cat' )`

…and **always** add the AI-surface URL set to the same batch:

- homepage (`home_url('/')`)
- shop archive (`get_permalink( wc_get_page_id('shop') )`)
- `home_url('/llms.txt')`
- the `/products.json` feed URL

Because the surfaces are added to a deduped set and flushed once per debounce window, they are submitted at most once per batch regardless of how many products changed.

### 4. Pending queue

- Stored as an array under a single option/transient key (e.g. `wc_ai_storefront_indexnow_pending`).
- `enqueue( array $urls )`: merge + `array_unique`, cap defensively (e.g. 10,000) and `log()` if the cap truncates (no silent truncation).
- `take()`: read then delete (clear) in one step for the flush.

### 5. Flush handler

- Hooked to a dedicated cron action (e.g. `wc_ai_storefront_indexnow_flush`).
- `schedule_flush()`: if `! wp_next_scheduled( HOOK )`, schedule a single one-off event a short delay out (debounce window; constant, e.g. 60s — tune during implementation).
- On fire:
  1. Gate check (syndication on AND `indexnow_enabled`). If off → clear queue, return.
  2. `take()` the pending set.
  3. Exclusion filter — drop URLs for products that are draft, `catalog_visibility=hidden`, password-protected, or otherwise non-public, reusing the same eligibility logic the feed / JSON-LD already apply (single source of truth — extract a shared predicate if one doesn't already exist).
  4. If the set is empty after filtering → return.
  5. Build body: `{ "host": <site host>, "key": <key>, "urlList": [...] }`.
  6. `wp_remote_post( 'https://api.indexnow.org/indexnow', [...] )` with `Content-Type: application/json; charset=utf-8`.
  7. Log the outcome (status code + count).

### 6. Settings

- New `indexnow_enabled` boolean (default `true`) in `SETTINGS_OPTION`, presented as a toggle that is visibly disabled / inert when syndication is off.
- Read-only key display + "Regenerate key" action.
- Writing the setting already fires `update_option_<SETTINGS_OPTION>`; on enable, ensure a key exists and flush rewrite rules.

## Error handling

- **`429` (spam / rate limit):** log; re-enqueue the batch and reschedule the flush with a longer backoff. Never tight-loop.
- **`403` (key not found / not in file):** log a clear diagnostic ("IndexNow key file not served — rewrite rules may need flushing"). This is the most likely misconfiguration.
- **`422` (URL not owned by host / schema mismatch):** log; drop the offending batch (don't retry a structurally invalid payload).
- **Transport error (`is_wp_error`):** log; re-enqueue with backoff.
- Never fatal, never block a product save, never surface anything to the shopper. All outcomes are observable through the existing logger.

## Testing

Unit tests (PHPUnit, HTTP mocked via the existing test patterns):

- URL resolution per event type (product / category produce the right permalinks).
- AI-surface URLs are always added to a batch.
- Dedupe: enqueueing overlapping sets yields a unique list.
- Exclusion filter removes draft / hidden / password-protected / non-public products.
- Payload shape: `host`, `key`, `urlList`; ≤ 10,000 cap honoured and logged when truncating.
- Key-file route: matching key → 200 + `text/plain` + body == key; mismatched key → 404.
- Gate: with the toggle or syndication off, flush performs **no** HTTP call and clears the queue.
- Debounce: many `enqueue()` calls schedule exactly one flush event.
- Error handling: `429` / transport error re-enqueue; `422` does not.

## Out of scope (YAGNI)

- Retry dashboards or per-URL submission-history UI.
- Multi-key rotation / multiple key files.
- Submitting order, account, or cart URLs.
- Per-product JSON-endpoint submission (the "everything" scope option was declined).
- Any Google-facing behaviour (Google does not consume IndexNow; we keep relying on JSON-LD + sitemaps there).

## Open implementation-time questions

- Exact debounce window (start at 60s; validate it survives a bulk import without tripping `429`).
- Whether a shared "is this product publicly discoverable" predicate already exists to reuse for the exclusion filter, or one should be extracted from the feed / JSON-LD code.

## References

- IndexNow protocol overview: <https://www.indexnow.org/index>
- IndexNow documentation (request format, key file, bulk submission): <https://www.indexnow.org/documentation>
- Participating search engines (live registry): <https://www.indexnow.org/searchengines.json>
- Shared submission endpoint: `https://api.indexnow.org/indexnow` (propagates to all participants).
