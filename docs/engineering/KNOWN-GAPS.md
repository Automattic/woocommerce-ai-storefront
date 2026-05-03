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

When one of these agents hits our UCP REST endpoints (`catalog/search`, `catalog/lookup`, checkout) without setting the `UCP-Agent` header, we know an AI is using the UCP surface — but we cannot identify *which* AI. The request is bucketed as **`Other AI`** via the [`OTHER_AI_BUCKET` fallback in `class-wc-ai-storefront-ucp-agent-header.php`](../../includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-agent-header.php) on the order-attribution side. On the crawler-stats side it doesn't show up at all if the UA matches no entry in `AI_CRAWLERS` and no UCP-Agent header is sent.

### Why it exists

Two structural reasons:

1. **Vendor convention is not yet uniform.** OpenAI, Anthropic, Perplexity, Microsoft, and Google publish UA tokens for their crawlers (`GPTBot`, `ChatGPT-User`, `ClaudeBot`, `Claude-User`, `PerplexityBot`, `Storebot-Google`, `AdIdxBot`) but explicitly do *not* set distinguishing UA tokens for their agentic-browser products — those drive a real browser and inherit its UA. A few products (Atlas, Comet, Manus) do append a custom suffix, but they're early and the suffixes haven't settled.

2. **The plugin's identification path is UA-based.** [`detect_crawler_from_ua()`](../../includes/ai-storefront/class-wc-ai-storefront-robots.php) does case-insensitive substring matching against the `AI_CRAWLERS` constant. If the UA contains no listed token, the plugin falls through to whatever signals the request itself carries — primarily the optional `UCP-Agent` header on UCP REST calls, and nothing on Store API calls.

The UCP-Agent header is the protocol's intended attribution channel: a well-behaved agentic shopper sends `UCP-Agent: vendor=openai.com; product=operator` and gets correctly attributed. The real-world gap is that not every agent sets it, especially during the current proliferation phase.

### Impact

| Surface | What you'll see today |
|---|---|
| Discovery tab → "AI agent activity" → "By AI Agent" breakdown | Unrecognized Chromium agents do not appear at all (no UA token match, no UCP-Agent header). Recognized ones appear under their canonical brand. Anything that *does* hit UCP REST with no UCP-Agent header rolls up as `Other AI`. |
| Discovery tab → Top searches | Search queries from unrecognized agents are present in the raw log under whatever endpoint they hit, but the `By AI Agent` column is `Other AI` or empty. |
| Recent AI Orders → Customer / Source columns | An order placed by an unrecognized agentic browser is captured if the request flows through UCP checkout with any UCP-Agent header (even a partial one). If the agent skips UCP entirely and uses Store API directly with a generic Chromium UA, the order is not flagged as AI-attributed at all — it looks like an organic browser purchase. |
| Crawler-stats charts | Volume-driven charts under-count AI activity by the share of agents that present as plain Chromium without identifying themselves. |

The most common shape of this gap, in production: a merchant looking at the Discovery tab sees `4 catalog queries, 0 UCP manifest hits, "Other AI: 4"`. The 4 are real AI-driven queries. We just can't say *which* AI without a header.

### Mitigations available today

- **UCP-Agent header is the intended channel.** Agents that follow the UCP spec send it. We document it in the manifest at `/.well-known/ucp` and in [`API-REFERENCE.md`](API-REFERENCE.md). Each conforming agent gets correctly attributed without a UA-list update.
- **`Other AI` is a real bucket, not a leak.** Orders placed via UCP with an unrecognized header still get the `_wc_ai_storefront_agent: Other AI` order meta and appear in Recent AI Orders. The `Other AI` bucket distinguishes "AI-driven via UCP" from "human via Store API" — that distinction itself has value, even when the specific vendor is unknown.
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
