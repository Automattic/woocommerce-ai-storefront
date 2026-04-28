# Hooks Reference

Filters and actions exposed by WooCommerce AI Storefront for extending plugins.

The plugin exposes a deliberately small surface — seven filters and one action. Each was chosen because it intercepts a specific extension point that's hard or impossible to reach from outside (e.g. the merchant's `/llms.txt` content, the UCP manifest body, the JSON-LD product markup). Where WP/WC core filters already exist for the same surface, we don't duplicate them.

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
| `$settings` | `array` | The plugin's resolved settings (see [`DATA-MODEL.md`](DATA-MODEL.md)). |

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

Filter the store-level JSON-LD emitted on the homepage and shop page.

```php
apply_filters( 'wc_ai_storefront_jsonld_store', array $store_data, array $settings );
```

| Param | Type | Description |
|-------|------|-------------|
| `$store_data` | `array` | The Schema.org `OnlineStore` structure (name, url, sameAs, etc.). |
| `$settings` | `array` | The plugin's resolved settings. |

**Returns:** modified `array`.

**When to use:** add organization-level metadata that AI agents read once per crawl — `sameAs` social profiles, `contactPoint`, `address`, regional `inLanguage`, etc.

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

## Hooks we consume (not exposed)

For reference, the plugin hooks into these WP and WC extension points but does not re-expose them:

- `woocommerce_checkout_order_created`, `woocommerce_store_api_checkout_order_processed` — capture attribution on order creation.
- `woocommerce_admin_order_data_after_billing_address` — render the AI Agent Attribution section in the admin order edit screen.
- `woocommerce_structured_data_product`, `wp_head` — emit enhanced JSON-LD.
- `woocommerce_store_api_rate_limit_options`, `woocommerce_store_api_rate_limit_id` — apply rate limits to AI-bot user-agents.
- `woocommerce_update_product`, `_new_product`, `_trash_product`, `_delete_product`, `_product_set_stock_status` — invalidate the `/llms.txt` and UCP-manifest caches.
- `woocommerce_blocks_loaded` — register the `barcodes` Store API extension.

If you need to extend behavior in those areas, hook the underlying WP/WC filter directly. Adding a new pass-through filter on our side would just hide a layer that's already canonical.

## See also

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — what each component does and where the filters fire
- [`API-REFERENCE.md`](API-REFERENCE.md) — REST endpoints whose responses these filters can shape
- [`DATA-MODEL.md`](DATA-MODEL.md) — the `$settings` array passed to most filters
- [`TESTING.md`](TESTING.md) — how to assert filter and action invocations under Brain Monkey
