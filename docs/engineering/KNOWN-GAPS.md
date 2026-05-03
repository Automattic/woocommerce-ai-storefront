# Known Gaps

This document tracks known limitations of the plugin that are explicit design choices or known measurement gaps — not bugs to fix imminently. The goal is to keep merchant-facing accuracy expectations honest and to give engineers a single place to find "yes we know about this" before re-investigating from scratch.

Each entry follows the same shape:
- **What** — the gap in one paragraph
- **Why it exists** — the structural reason
- **Impact** — what merchants and the plugin admin UI actually show
- **Mitigations available today** — what helps despite the gap
- **Future work** — the realistic options if/when this becomes a release priority

---

## Attribution: Chromium-based agentic browser shoppers

### What

A growing class of AI shoppers — ChatGPT Operator, ChatGPT Atlas, Perplexity Comet, Brave Leo, Google Project Mariner, Microsoft Copilot Vision, Arc/Dia, Manus, Multion, and the long tail of agents built on Browserbase / Anchor Browser / Steel.dev — drives a real Chromium or Edge browser on the user's behalf. Their HTTP `User-Agent` header is the parent browser's UA. There is no AI-specific token in the string for our [`detect_crawler_from_ua()`](../../includes/ai-storefront/class-wc-ai-storefront-robots.php) or the [`brand_names` map in `class-wc-ai-storefront-crawl-logger.php`](../../includes/ai-storefront/class-wc-ai-storefront-crawl-logger.php) to match.

When one of these agents hits our UCP REST endpoints (`catalog/search`, `catalog/lookup`, checkout), the attribution path resolves into one of two distinct buckets depending on what the request carries:

1. **No `UCP-Agent` header** (or unparseable header) → the canonical name becomes [`FALLBACK_SOURCE`](../../includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-agent-header.php) (`'ucp_unknown'`). The crawl logger explicitly skips recording rows whose name equals the fallback sentinel (see the `if ( FALLBACK_SOURCE !== $agent_data['name'] )` guards in `class-wc-ai-storefront-ucp-rest-controller.php`), so these requests are **invisible in Discovery stats entirely**. Orders are not stamped with an AI source.

2. **Header parses, but the host isn't in `KNOWN_AGENT_HOSTS`** → the canonical name becomes [`OTHER_AI_BUCKET`](../../includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-agent-header.php) (`'Other AI'`). The crawl logger DOES record these rows; orders DO get stamped. So they appear in Discovery stats under the literal `Other AI` brand, distinguishable from "no AI activity at all" but not from each other.

The two cohorts represent very different states. Cohort 1 is "we know nothing" — the request is indistinguishable from any other anonymous browser visit. Cohort 2 is "we know it's an AI but not which one" — the agent identified itself enough to claim AI status but not enough to claim a vendor.

### Why it exists

Two structural reasons:

1. **Vendor convention is not yet uniform.** OpenAI, Anthropic, Perplexity, Microsoft, and Google publish UA tokens for their crawlers (`GPTBot`, `ChatGPT-User`, `ClaudeBot`, `Claude-User`, `PerplexityBot`, `Storebot-Google`, `AdIdxBot`) but explicitly do *not* set distinguishing UA tokens for their agentic-browser products — those drive a real browser and inherit its UA. A few products (Atlas, Comet, Manus) do append a custom suffix, but they're early and the suffixes haven't settled.

2. **The plugin's identification path is UA-based.** [`detect_crawler_from_ua()`](../../includes/ai-storefront/class-wc-ai-storefront-robots.php) does case-insensitive substring matching against the `AI_CRAWLERS` constant. If the UA contains no listed token, the plugin falls through to whatever signals the request itself carries — primarily the optional `UCP-Agent` header on UCP REST calls, and nothing on Store API calls.

The UCP-Agent header is the protocol's intended attribution channel: a well-behaved agentic shopper sends `UCP-Agent: vendor=openai.com; product=operator` and gets correctly attributed. The real-world gap is that not every agent sets it, especially during the current proliferation phase.

### Impact

| Surface | Cohort 1 (no/unparseable header → `ucp_unknown`) | Cohort 2 (parseable, unknown host → `Other AI`) |
|---|---|---|
| Discovery tab → "AI agent activity" → "By AI Agent" breakdown | Not present — crawl logger skips fallback-sentinel rows. | Appears under the literal `Other AI` brand. |
| Discovery tab → Top searches | Search query is not recorded in the raw log (the same `FALLBACK_SOURCE` guard fires before the record call). | Search query is recorded; `By AI Agent` column shows `Other AI`. |
| Recent AI Orders → Customer / Source columns | Order is not stamped as AI-attributed — looks like an organic browser purchase. | Order is stamped with `_wc_ai_storefront_agent: Other AI` and appears in Recent AI Orders, distinguishable from organic. |
| Crawler-stats charts | Volume is invisible — the gap is silent under-counting. | Volume rolls into `Other AI` totals; merchant sees AI activity but can't slice by vendor. |

The most common shape of this gap, in production: a merchant looking at the Discovery tab sees `4 catalog queries, "Other AI: 4"`. The 4 are real AI-driven queries from agents that sent a parseable but non-canonical `UCP-Agent` header. The harder-to-see version of the gap is the cohort whose absence shows up nowhere — they were never logged.

### Mitigations available today

- **UCP-Agent header is the intended channel.** Agents that follow the UCP spec send it. We document it in the manifest at `/.well-known/ucp` and in [`API-REFERENCE.md`](API-REFERENCE.md). Each conforming agent gets correctly attributed without a UA-list update — and even agents that send a partial/unknown header land in cohort 2 (`Other AI`) rather than cohort 1 (silent), which preserves *some* signal.
- **`Other AI` is a real bucket, not a leak.** Orders placed via UCP with a parseable-but-unknown `UCP-Agent` header still get the `_wc_ai_storefront_agent: Other AI` order meta and appear in Recent AI Orders. The bucket distinguishes "AI-driven via UCP" from "human via Store API" — that distinction itself has value, even when the specific vendor is unknown.
- **Store API rate-limit logs.** When unidentified agents trigger our Store API rate limiter, the throttled requests are logged with whatever bot token did match (or empty). The throttle counts on the Discovery tab include those events even when the agent is unattributed.

### Future work

Three options in ascending complexity:

1. **Track new vendor UA suffixes as they appear.** When agentic products start carrying identifying tokens in their UA (e.g. `Comet/1.0`, `Atlas/1.0`, `Manus/1.0`), we add them to `AI_CRAWLERS` and the brand map. This catches up over time but cannot get ahead of vendors that choose not to identify.

2. **Treat `Other AI` as a first-class identity in the UI.** Surface it deliberately on the Discovery tab and in Recent AI Orders rather than implicitly. Today an `Other AI` order looks slightly different from a `ChatGPT` or `Claude` order; making the bucket explicit (with a tooltip explaining the limitation) is cheap and improves merchant trust.

3. **Behavioral fingerprinting.** Distinguish agentic browsers from humans via signals that don't depend on UA: webdriver/Playwright/Puppeteer flags, viewport heuristics, request patterns (no mouse movement, instant form fills, accessibility-tree fetches). This gets us a binary "agent vs human" signal but not vendor identity. Implementation is non-trivial and creates false-positive risk on accessibility tools — out of scope until it becomes load-bearing.

The realistic 2026 trajectory is option 1 plus option 2: keep the UA list current, and make the `Other AI` bucket honest in the merchant UI. Option 3 is a bigger product decision (do we want to identify, throttle, or block agentic shoppers differently from humans?) that crosses into broader strategic territory and is out of scope for an incremental measurement fix.

---

## Crawler stats: `ENDPOINT_PRODUCT_PAGE` is declared but unused

### What

The constant `ENDPOINT_PRODUCT_PAGE = 'product_page'` is declared in [`class-wc-ai-storefront-crawl-logger.php`](../../includes/ai-storefront/class-wc-ai-storefront-crawl-logger.php) but no call site uses it. Front-end product page hits by AI agents are not currently recorded under any endpoint.

### Impact

If an AI crawls product pages directly (reading the rendered HTML and the embedded JSON-LD Schema.org Product markup) instead of going through the UCP or Store API, the visit doesn't appear anywhere in the Discovery tab.

### Future work

Wire `ENDPOINT_PRODUCT_PAGE` recording into a `template_redirect` hook on single-product templates with the existing `detect_crawler_from_ua()` gate. Add a "Product page hits" tile on the Discovery tab. Probably worth doing alongside the bulk-catalog `feed.json` work, since both surfaces represent "ingestion-shaped" traffic distinct from search/lookup.

---

## References (external)

- [OpenAI bot docs](https://platform.openai.com/docs/bots) — current GPTBot / ChatGPT-User / OAI-SearchBot UA tokens and behavior.
- [Anthropic crawler docs](https://support.anthropic.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler) — current ClaudeBot / Claude-User / Claude-SearchBot tokens.
- [Perplexity bot docs](https://docs.perplexity.ai/guides/bots) — PerplexityBot / Perplexity-User behavior, including the documented robots.txt non-compliance edge cases.
- [Dark Visitors registry](https://darkvisitors.com/) — third-party curated list of AI agents with robots.txt generators; useful when sanity-checking whether a new UA token has been seen elsewhere.
