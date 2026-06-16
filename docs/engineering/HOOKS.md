# Hooks Reference

Filters and actions exposed by WooCommerce AI Storefront for extending plugins.

The plugin exposes a deliberately small surface — seventeen filters and two actions. Each was chosen because it intercepts a specific extension point that's hard or impossible to reach from outside (e.g. the merchant's `/llms.txt` content, the UCP manifest body, the JSON-LD product markup). Where WP/WC core filters already exist for the same surface, we don't duplicate them.

## Filters

### `wc_ai_storefront_jsonld_product`

Filter the enhanced JSON-LD `Product` markup before it's emitted on a product page.

```php
apply_filters( 'wc_ai_storefront_jsonld_product', array $markup, WC_Product $product, array $settings );
```

| Param | Type | Description |
|-------|------|-------------|
| `$markup` | `array` | The Schema.org `Product` structure with our enhancements (BuyAction, inventory, attributes, return policy). |
| `$product` | `WC_Product` | The product being rendered. |
| `$settings` | `array` | A minimal 3-key subset of the plugin's settings: `enabled`, `product_selection_mode`, `return_policy`. Security-sensitive fields (`rate_limit_rpm`, `allowed_crawlers`, `allow_unknown_ucp_agents`, per-product selection arrays) are intentionally excluded. |

**Returns:** the (possibly modified) `array` to encode and emit.

**When to use:** add or override product-level structured-data fields without subclassing the JsonLd class. Common cases: extension-specific properties (Subscriptions billing period, Bookings availability), brand/manufacturer overrides, regional schema variants.

**Example — surface a custom attribute as `Product.brand`:**

```php
add_filter( 'wc_ai_storefront_jsonld_product', function( $markup, $product, $settings ) {
    $brand = $product->get_attribute( 'pa_brand' );
    if ( $brand ) {
        $markup['brand'] = [ '@type' => 'Brand', 'name' => $brand ];
    }
    return $markup;
}, 10, 3 );
```

### `wc_ai_storefront_jsonld_store`

Filter the store-level JSON-LD emitted on the homepage and shop page (Schema.org `OnlineBusiness`, an `Organization` subtype). The filter receives the fully-assembled structure including auto-sourced identity fields (`logo`, `address`, `contactPoint.email`), `knowsAbout` (top category names), and Org-level `hasMerchantReturnPolicy` (when configured) — so plugins can layer extra identity claims without re-implementing the WP/WC reads.

```php
apply_filters( 'wc_ai_storefront_jsonld_store', array $store_data, array $settings );
```

| Param | Type | Description |
|-------|------|-------------|
| `$store_data` | `array` | The Schema.org `OnlineStore` structure (`@type`, `@context`, `name`, `description`, `url`, `currenciesAccepted`, `potentialAction`, `hasOfferCatalog`, plus optionally `logo`, `address`, `contactPoint`). |
| `$settings` | `array` | A minimal 3-key subset of the plugin's settings: `enabled`, `product_selection_mode`, `return_policy`. Security-sensitive fields are intentionally excluded (same subset as `wc_ai_storefront_jsonld_product`). |

**Returns:** modified `array`.

**When to use:** add organization-level metadata that AI agents read once per crawl. `sameAs` (social profiles) is **auto-sourced** by the plugin from the providers a Woo merchant most likely already has (Jetpack Publicize connections, Yoast `wpseo_social`, RankMath social settings); the filter is the **override** seam on top of that. `contactPoint.telephone` is still not captured (WC has no canonical phone source) and remains a pure filter-injection field.

**Auto-sourced `sameAs`.** The plugin gathers profile URLs in `collect_same_as()` (each provider independently guarded, absent/odd-shaped provider skipped silently), sanitizes via `esc_url_raw`, keeps only `http`/`https` URLs, and de-duplicates. The result is merged into `$store_data['sameAs']` **before** this filter fires, so a merchant filter can replace, extend, or clear it. To **add** a profile without dropping the auto-sourced ones:

```php
add_filter( 'wc_ai_storefront_jsonld_store', function ( array $store_data, array $settings ): array {
    $profiles   = $store_data['sameAs'] ?? [];           // keep what the plugin found
    $profiles[] = 'https://mastodon.example/@mystore';   // add your own
    $store_data['sameAs'] = array_values( array_unique( $profiles ) );
    return $store_data;
}, 10, 2 );
```

To **override** the auto-sourced set entirely, assign `$store_data['sameAs']` directly (the plugin's value is discarded):

```php
add_filter( 'wc_ai_storefront_jsonld_store', function ( array $store_data, array $settings ): array {
    $store_data['sameAs'] = array_values( array_filter( [
        get_option( 'my_plugin_twitter_url' ),
        get_option( 'my_plugin_instagram_url' ),
    ] ) );
    return $store_data;
}, 10, 2 );
```

**Common pattern: injecting a phone number.** Schema.org allows partial `ContactPoint` blocks, so plugins can extend an existing `contactPoint` (added by the plugin from the WC reply-to/from email resolver) with a `telephone` key — or add a fresh `contactPoint` if none exists:

```php
add_filter( 'wc_ai_storefront_jsonld_store', function ( array $store_data, array $settings ): array {
    $phone = get_option( 'my_plugin_support_phone' );
    if ( empty( $phone ) ) {
        return $store_data;
    }

    if ( isset( $store_data['contactPoint'] ) ) {
        // Plugin already emitted contactPoint.email from WC settings;
        // we add telephone alongside it.
        $store_data['contactPoint']['telephone'] = $phone;
    } else {
        $store_data['contactPoint'] = [
            '@type'       => 'ContactPoint',
            'contactType' => 'Customer Service',
            'telephone'   => $phone,
        ];
    }

    return $store_data;
}, 10, 2 );
```

**What you should NOT add via this filter.** `streetAddress` is deliberately suppressed by the plugin — for an `OnlineStore` (vs. a `LocalBusiness`) the field has low signal value but real privacy risk (many merchants populate WC Settings with their home address for tax purposes and don't expect it published). Re-injecting `streetAddress` via this filter undoes that privacy guard. If a merchant actually wants their street address published, they should configure that through a plugin that explicitly asks them to opt in.

### `wc_ai_storefront_jsonld_website`

Filter the global `WebSite` + `SearchAction` JSON-LD block emitted on every page (see [`JSON-LD-SCHEMA.md`](JSON-LD-SCHEMA.md#every-page-website--searchaction-schema)). The block advertises two search entry points: the native WP product search and the public `GET /catalog/search` REST endpoint.

```php
apply_filters( 'wc_ai_storefront_jsonld_website', array $data );
```

| Param | Type | Description |
|-------|------|-------------|
| `$data` | `array` | The Schema.org `WebSite` structure (`@type`, `@context`, `url`, and a two-entry `potentialAction` array of `SearchAction`s). |

**Returns:** modified `array` to customize, or `false`/`null` to **suppress the block entirely**. The filter runs before the result is cached in the `WEBSITE_JSONLD_CACHE_KEY` transient, so a suppressed block is never cached.

**When to use — avoiding a duplicate `WebSite` block.** Yoast SEO and Rank Math both emit their own site-level `WebSite` node with a sitelinks `SearchAction`. If one of those is active and already covers search, return falsy here so the page doesn't carry two `WebSite` nodes:

```php
add_filter( 'wc_ai_storefront_jsonld_website', function ( $data ) {
    // Defer to Yoast's WebSite/SearchAction when Yoast is active.
    if ( defined( 'WPSEO_VERSION' ) ) {
        return null; // suppress this plugin's block
    }
    return $data;
} );
```

**When to use — adding the REST search action to your own block instead.** If you'd rather keep your SEO plugin's `WebSite` node but still advertise the catalog API, suppress this block and append the second `SearchAction` to your own node via your SEO plugin's schema filter. The REST `urlTemplate` is `<rest_url>wc/ucp/v1/catalog/search?q={search_term}`.

### `wc_ai_storefront_ucp_manifest`

Filter the `/.well-known/ucp` manifest data before it's encoded and served.

```php
apply_filters( 'wc_ai_storefront_ucp_manifest', array $manifest, array $settings );
```

| Param | Type | Description |
|-------|------|-------------|
| `$manifest` | `array` | The UCP manifest with `name`, `version`, `capabilities`, `payment_handlers`, `services`, `config.store_context`, and the `com.woocommerce.ai_storefront` extension block. |
| `$settings` | `array` | The plugin's resolved settings. |

**Returns:** modified `array`.

**When to use:** advertise additional capabilities your plugin implements, override `config.store_context` (e.g. when running a multi-currency setup that should declare a different default), or add custom extension blocks under reverse-domain keys.

> **Caution:** changes here must remain UCP-spec-compliant. Adding a capability ID without a corresponding handler will cause well-behaved agents to call an endpoint that doesn't exist.

### `wc_ai_storefront_llms_txt_lines`

Filter the array of Markdown lines before they're joined into the `/llms.txt` body.

```php
apply_filters( 'wc_ai_storefront_llms_txt_lines', array $lines, array $settings );
```

| Param | Type | Description |
|-------|------|-------------|
| `$lines` | `string[]` | One element per line of the rendered `/llms.txt` document. Order matters. |
| `$settings` | `array` | The plugin's resolved settings. |

**Returns:** modified `string[]`.

**When to use:** prepend a brand intro, append store-specific instructions for AI agents, splice in a featured-collections section, or strip lines you don't want crawlers to see. Modifications survive cache invalidation — the filter runs at generation time and the result is cached as the `wc_ai_storefront_llms_txt` transient.

### `wc_ai_storefront_discovery_cache_max_age`

Tune the `max-age` (seconds) on the `Cache-Control: public` header for the discovery surfaces `/.well-known/ucp` and `/llms.txt`.

```php
apply_filters( 'wc_ai_storefront_discovery_cache_max_age', int $seconds );
```

| Param | Type | Description |
|-------|------|-------------|
| `$seconds` | `int` | The `max-age` in seconds. Default `300`. |

**Returns:** the effective `max-age` in seconds; negative returns are clamped to `0`.

**When to use:** Because there is no edge-purge hook, this value is the freshness SLA — a settings or syndication-state change takes up to this many seconds to propagate through the CDN edge. Lower it on stores that toggle syndication often; raise it for maximum burst absorption.

### `wc_ai_storefront_robots_txt`

Filter the AI-crawler block this plugin appends to `/robots.txt`.

```php
apply_filters( 'wc_ai_storefront_robots_txt', string $output, array $settings );
```

| Param | Type | Description |
|-------|------|-------------|
| `$output` | `string` | The full block of `User-agent: ... Allow: /` (or `Disallow: /`) lines for the configured AI crawlers. |
| `$settings` | `array` | The plugin's resolved settings. |

**Returns:** modified `string`.

**When to use:** add directives WordPress core's `do_robots` hook can't reach, or override per-bot rules generated from `allowed_crawlers`. WordPress core's `robots_txt` filter still runs after this — most extensions are easier on that filter, not this one. Use this only when you need access to the AI-Storefront-specific portion.

### `wc_ai_storefront_debug`

Toggle the plugin's debug logger for the current request.

```php
apply_filters( 'wc_ai_storefront_debug', bool $enabled );
```

| Param | Type | Description |
|-------|------|-------------|
| `$enabled` | `bool` | `false` by default. Return `true` to enable `error_log()` output from the plugin's instrumentation points (cache hit/miss, rate-limit fingerprints, attribution captures). |

**Returns:** `bool`.

**When to use:** during incident triage. Output goes to `error_log()` (usually `wp-content/debug.log` when `WP_DEBUG_LOG` is on) prefixed with `[wc-ai-storefront]`. The filter is evaluated once per request and cached, so call sites pay only a static-property check when logging is off.

**Example — enable for a specific user agent:**

```php
add_filter( 'wc_ai_storefront_debug', function( $enabled ) {
    if ( isset( $_SERVER['HTTP_USER_AGENT'] ) && str_contains( $_SERVER['HTTP_USER_AGENT'], 'PerplexityBot' ) ) {
        return true;
    }
    return $enabled;
} );
```

**Example — turn on globally during an incident:**

```php
add_filter( 'wc_ai_storefront_debug', '__return_true' );
```

### `wc_ai_storefront_github_token`

Provide a GitHub personal-access token to the self-updater so its rate limit goes from 60 req/hour (anonymous) to 5,000 req/hour (authenticated).

```php
apply_filters( 'wc_ai_storefront_github_token', string $token );
```

| Param | Type | Description |
|-------|------|-------------|
| `$token` | `string` | Empty by default (anonymous). Return a GitHub PAT with `repo:read` scope. |

**Returns:** `string`.

**When to use:** only when you're hitting GitHub's anonymous rate limit on update-check polls. Most stores don't need this. Keep the token in a secret store (env var, Bedrock, 1Password Secrets Automation, etc.) — never hardcode it in `wp-config.php`.

```php
add_filter( 'wc_ai_storefront_github_token', function() {
    return defined( 'GITHUB_PAT' ) ? GITHUB_PAT : '';
} );
```

### `wc_ai_storefront_ucp_product`

Filter a translated UCP product shape before it is added to a catalog/search or catalog/lookup response.

```php
apply_filters( 'wc_ai_storefront_ucp_product', array $product, array $wc_product );
```

| Param | Type | Description |
|-------|------|-------------|
| `$product` | `array` | The translated UCP product shape. Required keys: `id`, `title`, `description`, `price_range`, `variants`. Optional: `url` (UTM-stamped permalink), `handle`, `status`, `seller`, `categories`, `tags`, `media`, `options`, `metadata`, `rating`, `published_at`, `updated_at`. |
| `$wc_product` | `array` | The raw decoded Store API product response. Use this to read WC-native fields (e.g. custom meta surfaced via a Store API extension) that the translator did not map. |

**Returns:** the (possibly modified) `array` UCP product shape.

**When to use:** add custom product fields from a Store API extension (e.g. subscription billing period, pre-order date, rental availability), override price display for multi-currency setups, or inject an additional `categories` entry from a custom taxonomy.

Fires in both `catalog/search` and `catalog/lookup` responses, once per product, after UTM attribution params have been stamped onto `$product['url']`. The translator is upstream of this filter and runs first; modifications made here are not visible to the translator.

**Example — surface a subscription billing period:**

```php
add_filter( 'wc_ai_storefront_ucp_product', function( $product, $wc_product ) {
    $ext = $wc_product['extensions']['com-woocommerce-subscriptions'] ?? array();
    if ( ! empty( $ext['billing_period'] ) ) {
        $product['metadata']['subscription'] = array(
            'billing_period'    => $ext['billing_period'],
            'billing_interval'  => (int) ( $ext['billing_interval'] ?? 1 ),
        );
    }
    return $product;
}, 10, 2 );
```

---

### `wc_ai_storefront_products_feed_product`

Filter a single mapped Shopify-shaped product before it enters the **non-UCP `/products.json` feed** (and its `/collections/all/products.json` alias). Mirrors `wc_ai_storefront_ucp_product`, but for the Shopify-compat surface rather than the UCP REST adapter.

```php
apply_filters( 'wc_ai_storefront_products_feed_product', array $data, WC_Product $product );
```

| Param | Type | Description |
|-------|------|-------------|
| `$data` | `array` | The mapped product in Shopify product shape. Keys: `id`, `title`, `handle`, `body_html`, `vendor` (string or `null`), `product_type` (string, may be `""`), `tags` (comma-joined string), `variants` (each with `id`, `title`, `option1/2/3`, `sku`, `price`, `compare_at_price`, `available`, `requires_shipping`), `images` (each `{ id, src }`), and — **for variable products only** — `options` (each `{ name, position, values }`). Note: `options` is absent on simple/bundle/grouped products. |
| `$product` | `WC_Product` | The source WooCommerce product object (not a Store API array — this feed maps directly from WC product objects, unlike the UCP translator). Use it to read WC-native fields (meta, attributes, taxonomy terms) the mapper didn't surface. |

**Returns:** the (possibly modified) `array`. A non-array return is ignored and the pre-filter `$data` is used (so a buggy callback can't blank a product out of the feed).

**When to use:** add a Shopify field the mapper omits (e.g. a `barcode` on each variant from `global_unique_id`), override `vendor` / `product_type` from a custom taxonomy, or strip a field a particular agent integration mis-parses. Because this is a Shopify-shape contract, keep additions to fields real Shopify feeds emit — agents parse this shape zero-shot and unexpected keys add no value.

Fires once per product, inside `WC_AI_Storefront_Products_Feed::map_product()`, after the WC→Shopify mapping completes. The mapper is upstream of this filter and runs first; modifications made here are not visible to the mapper.

**Example — add a per-variant barcode from WC core's `global_unique_id` (WooCommerce 9.2+):**

`WC_Product::get_global_unique_id()` was added in WooCommerce 9.2; guard with `method_exists( $product, 'get_global_unique_id' )` if your store may run an older version.

```php
add_filter( 'wc_ai_storefront_products_feed_product', function( $data, $product ) {
    $gtin = $product->get_global_unique_id();
    if ( $gtin ) {
        foreach ( $data['variants'] as &$variant ) {
            $variant['barcode'] = $gtin;
        }
        unset( $variant );
    }
    return $data;
}, 10, 2 );
```

---

### `wc_ai_storefront_ucp_variant`

Filter a translated UCP variant shape before it is added to a product's `variants` array.

```php
apply_filters( 'wc_ai_storefront_ucp_variant', array $variant, array $wc_variation );
```

| Param | Type | Description |
|-------|------|-------------|
| `$variant` | `array` | The translated UCP variant shape. Required keys: `id`, `title`, `description`, `list_price`, `availability`. Optional: `options`, `compare_at_price`, `sku`, `barcodes`, `media`, `metadata`. |
| `$wc_variation` | `array` | The raw decoded Store API variation response (or, for a synthesized default variant on a simple product, the product response). Use this to read WC-native fields that the translator did not map. |

**Returns:** the (possibly modified) `array` UCP variant shape.

**When to use:** add a custom availability signal (e.g. a pre-order date), surface a per-variation custom attribute, or override `list_price` with a subscription-adjusted amount.

Fires once per variation for variable products, and once on the synthesized default variant for simple products.

**Example — add a pre-order release date from a custom Store API extension:**

```php
add_filter( 'wc_ai_storefront_ucp_variant', function( $variant, $wc_variation ) {
    $ext = $wc_variation['extensions']['com-acme-preorder'] ?? array();
    if ( ! empty( $ext['release_date'] ) ) {
        $variant['metadata']['preorder_release_date'] = $ext['release_date'];
    }
    return $variant;
}, 10, 2 );
```

---

### `wc_ai_storefront_ucp_continue_url`

Filter the continue_url returned in a `POST /checkout-sessions` response before it is sent to the agent.

```php
apply_filters( 'wc_ai_storefront_ucp_continue_url', string $url, array $processed );
```

| Param | Type | Description |
|-------|------|-------------|
| `$url` | `string` | The fully-constructed continue URL including the `?products={id:qty,...}` payload and UTM attribution params (`utm_source`, `utm_medium`, `utm_id`, `ai_agent_host_raw`). |
| `$processed` | `array` | The successfully-processed line items. Each entry contains at least `wc_id` (int), `ucp_id` (string), `quantity` (int), and `unit_price_minor` (int). Useful for conditionally redirecting based on cart contents. |

**Returns:** the (possibly modified) continue URL string. Non-string returns are silently ignored and the pre-filter URL is used.

**When to use:** redirect buyers to an alternative checkout entry point (e.g. a subscription sign-up page, a gift-purchase flow, a custom membership checkout) based on the cart contents, without changing the checkout-sessions handler itself.

**Example — redirect subscription products to a dedicated checkout:**

```php
add_filter( 'wc_ai_storefront_ucp_continue_url', function( $url, $processed ) {
    $wc_ids = array_column( $processed, 'wc_id' );
    foreach ( $wc_ids as $id ) {
        $product = wc_get_product( $id );
        if ( $product && 'subscription' === $product->get_type() ) {
            return home_url( '/subscribe-checkout/?products=' . implode( ',', $wc_ids ) );
        }
    }
    return $url;
}, 10, 2 );
```

---

### `wc_ai_storefront_ucp_store_api_args`

Filter the Store API query parameters before a `catalog/search` dispatch.

```php
apply_filters( 'wc_ai_storefront_ucp_store_api_args', array $store_params, string $endpoint );
```

| Param | Type | Description |
|-------|------|-------------|
| `$store_params` | `array` | Associative array of Store API query parameters mapped from the UCP request. Keys include `search`, `per_page`, `page`, `category`, `on_sale`, `min_price`, `max_price`, `orderby`, `order`, and any others produced by `map_ucp_search_to_store_api()`. |
| `$endpoint` | `string` | The Store API endpoint being dispatched (e.g. `'/wc/store/v1/products'`). Included for future-proofing so a single callback can branch by endpoint if new dispatch paths are added. |

**Returns:** the (possibly modified) `array` of Store API params. Non-array returns are silently discarded and treated as an empty params array.

**When to use:** inject a hidden catalog constraint (e.g. `category` filter for members-only products), add a custom `orderby` value registered by another Store API extension, or suppress a UCP-mapped parameter your plugin handles through a different filter.

**Example — restrict catalog to a members-only category:**

```php
add_filter( 'wc_ai_storefront_ucp_store_api_args', function( $store_params, $endpoint ) {
    if ( '/wc/store/v1/products' === $endpoint && current_user_can( 'member' ) ) {
        $store_params['category'] = 'members-only';
    }
    return $store_params;
}, 10, 2 );
```

---

### `wc_ai_storefront_rollup_interval`

Filter the WP-Cron schedule used for the crawl-log rollup event.

```php
apply_filters( 'wc_ai_storefront_rollup_interval', string $interval );
```

| Param | Type | Description |
|-------|------|-------------|
| `$interval` | `string` | WP-Cron recurrence slug. Default `'hourly'`. |

**Returns:** one of `'hourly'`, `'twicedaily'`, or `'daily'` — the only intervals up to a 24-hour cadence are accepted because `rollup()` always covers a fixed yesterday+today window. Anything slower (e.g. `'weekly'`) would leave gaps of unsummarized days; values outside the allowlist are silently rejected and the schedule falls back to `'hourly'`.

**When to use:** high-traffic stores that want to reduce DB load (`'twicedaily'` or `'daily'`), or to keep the default `'hourly'` cadence on low-traffic stores.

**Applying a change:** `schedule_crons()` runs unconditionally during plugin bootstrap (via `WC_AI_Storefront::init_components()`), so it executes on every WordPress request — admin or front-end. On each call it compares the existing event's recurrence to the filtered value and automatically clears and re-registers the event when they differ. Filter changes therefore take effect on the very next request without any manual `wp cron` commands. The `/crawl-stats` REST response also surfaces the live `rollup_interval` value (never cached in the transient), so the Discovery tab subtitle reflects the current cadence on the next refresh.

**Example — switch to twice-daily rollup:**

```php
add_filter( 'wc_ai_storefront_rollup_interval', function() {
    return 'twicedaily';
} );
```

---

### `wc_ai_storefront_search_impression_cap`

Cap the number of per-result impression rows recorded for a single `catalog/search` response.

```php
apply_filters( 'wc_ai_storefront_search_impression_cap', int $cap );
```

| Param | Type | Description |
|-------|------|-------------|
| `$cap` | `int` | Maximum impression rows to record per search. Default `50` (the value of `WC_AI_Storefront_Crawl_Logger::SEARCH_IMPRESSION_CAP`). |

**Returns:** an integer used to bound the per-search write volume. Values are cast via `(int)` and clamped with `max( 0, $cap )`, so non-numeric returns become `0`. A returned value of `0` disables impression recording entirely (the search-request row is unaffected); negative returns are clamped to `0`. Values larger than the actual result count are a no-op (`array_slice` doesn't pad).

**Background.** The plugin records two distinct kinds of crawl-log row for each `catalog/search` response: one **request** row (`endpoint = store_api_search`, `product_id = 0`, `query = <keyword>`) that feeds Catalog queries / Top searches, and **N impression rows** (`endpoint = store_api_search_hit`, `product_id = N`, `query = ''`) that feed the "Products seen" tile via `COUNT(DISTINCT product_id)`. The cap controls only the impression rows.

**When to use:** high-traffic stores returning large result pages may want to dial down the cap to bound row growth in the raw log (e.g. a store doing 10,000 searches/day with `per_page=100` would write 1,000,000 impression rows/day at the default cap). Stores doing typical merchant traffic (`per_page` 10–20) won't exceed the default cap. Returning `0` disables impression recording entirely while leaving the request row + Top searches data path intact.

**Example — cap at 10 to reduce write volume on a high-traffic store:**

```php
add_filter( 'wc_ai_storefront_search_impression_cap', function() {
    return 10;
} );
```

**Example — disable impression recording entirely while preserving Catalog queries / Top searches:**

```php
add_filter( 'wc_ai_storefront_search_impression_cap', '__return_zero' );
```

---

### `wc_ai_storefront_accepted_currencies`

Override the auto-detected list of ISO-4217 currency codes the store accepts. Since 0.17.0.

```php
apply_filters( 'wc_ai_storefront_accepted_currencies', array $list );
```

| Param | Type | Description |
|-------|------|-------------|
| `$list` | `array<int, string>` | Ordered, deduplicated list of ISO-4217 codes. Base currency first. Auto-sourced from WooPayments' multi-currency enabled set when the soft dependency is present; otherwise `[ base_currency ]`. |

**Returns:** the (possibly modified) `array<int, string>`. Falls back to `[ base_currency ]` if the returned value is not an array, is empty, or contains no valid ISO-4217 codes (uppercase A–Z, three characters). Order is preserved; duplicates are removed.

**When to use:** integrate with non-WooPayments multi-currency plugins (e.g. WPML Multilingual CMS, CURCY, Aelia Currency Switcher) by surfacing their enabled set to UCP / JSON-LD / llms.txt without forking the helper. The filter result drives `store_context.accepted_currencies` in the UCP manifest, the `currenciesAccepted` space-separated list on the homepage `OnlineBusiness` JSON-LD, the `**Accepted currencies**` line in `/llms.txt`, and the `?currency=XXX` URL-stamping gate on UCP `continue_url` and per-product `url` fields.

**Example — bridge CURCY's enabled currencies:**

```php
add_filter( 'wc_ai_storefront_accepted_currencies', function ( $list ) {
    if ( ! function_exists( 'wmc_get_enabled_currencies' ) ) {
        return $list;
    }
    $base = get_woocommerce_currency();
    $codes = array_merge( [ $base ], array_keys( wmc_get_enabled_currencies() ) );
    return array_values( array_unique( $codes ) );
} );
```

---

## Actions

### `wc_ai_storefront_attribution_captured`

Fires after the plugin captures an AI agent's attribution data onto an order.

```php
do_action( 'wc_ai_storefront_attribution_captured', WC_Order $order, string $canonical_agent, string $session_id );
```

| Param | Type | Description |
|-------|------|-------------|
| `$order` | `WC_Order` | The order whose meta was just updated. Already saved. |
| `$canonical_agent` | `string` | The canonical agent identifier — same value stored in `_wc_ai_storefront_agent` meta. Brand name (`ChatGPT`, `Gemini`, …) for known hosts; `Other AI` for unknown hosts captured under the STRICT gate. |
| `$session_id` | `string` | The `chk_…` correlation token, or empty string if no session ID was on the URL. |

**When to use:** wire AI orders into a downstream system (CRM, BI pipeline, Slack notification) without polling order meta. The action fires inside the `woocommerce_checkout_order_processed` hook chain, so `$order` is fully populated and the line items are persisted.

**Example — Slack notification on every AI order:**

```php
add_action( 'wc_ai_storefront_attribution_captured', function( $order, $agent, $session_id ) {
    $total = $order->get_formatted_order_total();
    wp_remote_post( SLACK_WEBHOOK, [
        'body' => wp_json_encode( [
            'text' => sprintf( 'New AI order #%d via %s — %s', $order->get_id(), $agent, wp_strip_all_tags( $total ) ),
        ] ),
        'headers' => [ 'Content-Type' => 'application/json' ],
    ] );
}, 10, 3 );
```

> **Don't** rely on `$canonical_agent` to switch business logic by gate — the rule is path-independent. A STRICT-gate capture whose `utm_source` happens to be a known hostname gets canonicalized to the brand name same as a LENIENT-gate capture would. Listeners that need the raw host should read `_wc_ai_storefront_agent_host_raw` from the order directly.

### `wc_ai_storefront_ucp_access_denied`

Fires whenever a UCP REST request is rejected by the agent-access gate.

```php
do_action( 'wc_ai_storefront_ucp_access_denied', string $raw_id, string $reason, WP_REST_Request $request );
```

| Param | Type | Description |
|-------|------|-------------|
| `$raw_id` | `string` | The raw agent identifier extracted from the `UCP-Agent` header (hostname for profile-URL form, lowercased product token for Product/Version form). Empty string when no header was present. |
| `$reason` | `string` | Why access was denied: `'unknown_agent'` (host not recognized and `allow_unknown_ucp_agents=no`) or `'brand_blocked'` (agent's brand is absent from the merchant's `allowed_crawlers` list). |
| `$request` | `WP_REST_Request` | The denied request, for listeners that need route or header data. |

**When to use:** security plugins and analytics pipelines that need to track gate denials without polling logs. Fires on every 403 from the `check_agent_access()` permission callback, regardless of denial reason — subscribe once to cover both the unknown-agent and brand-blocked paths.

**Example — record denied agents to a custom audit log:**

```php
add_action( 'wc_ai_storefront_ucp_access_denied', function( $raw_id, $reason, $request ) {
    error_log( sprintf( '[audit] UCP access denied — reason=%s raw_id=%s route=%s', $reason, $raw_id, $request->get_route() ) );
}, 10, 3 );
```

## Hooks we consume (not exposed)

For reference, the plugin hooks into these WP and WC extension points but does not re-expose them:

- `woocommerce_checkout_order_created`, `woocommerce_store_api_checkout_order_processed` — capture attribution on order creation.
- `woocommerce_admin_order_data_after_billing_address` — render the AI Agent Attribution section in the admin order edit screen.
- `woocommerce_structured_data_product`, `wp_head` — emit enhanced JSON-LD.
- `woocommerce_init` — replace WC's `wp_footer` structured-data callback with the plugin's own `output_wc_structured_data()` (see "WooCommerce serializer interception" in [`JSON-LD-SCHEMA.md`](JSON-LD-SCHEMA.md)). Must defer to `woocommerce_init` because `WC()->structured_data` is not instantiated until `WC::init()` completes.
- `wp_footer` at priority 10 (replacing WC's default) — emit WC's structured data via `output_wc_structured_data()` using `JSON_HEX_AMP`-flagged `wp_json_encode`, bypassing `wc_esc_json`.
- `woocommerce_store_api_rate_limit_options`, `woocommerce_store_api_rate_limit_id` — apply rate limits to AI-bot user-agents.
- `woocommerce_update_product`, `_new_product`, `_trash_product`, `_delete_product`, `_product_set_stock_status` — invalidate the `/llms.txt` and UCP-manifest caches.
- `woocommerce_blocks_loaded` — register the `barcodes` Store API extension.

If you need to extend behavior in those areas, hook the underlying WP/WC filter directly. Adding a new pass-through filter on our side would just hide a layer that's already canonical.

## See also

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — what each component does and where the filters fire
- [`API-REFERENCE.md`](API-REFERENCE.md) — REST endpoints whose responses these filters can shape
- [`DATA-MODEL.md`](DATA-MODEL.md) — the `$settings` array passed to most filters
- [`TESTING.md`](TESTING.md) — how to assert filter and action invocations under Brain Monkey
