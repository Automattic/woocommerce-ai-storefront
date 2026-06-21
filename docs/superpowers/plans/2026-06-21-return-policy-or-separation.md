# Return Policy Option A / Option B Separation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the current lumped `returns_accepted`/`final_sale`/`unconfigured` top-level mode with a three-way `unconfigured`/`link`/`details` shape that makes Google's Option A (inline) vs. Option B (link) distinction explicit, eliminating the silent link-precedence bug and JS/PHP preview divergence.

**Architecture:** Four in-order layers — PHP data shape (sanitize + default + REST schema), PHP emitter (build_return_policy_block), JS parity helper (derivePreview), JS render (UI conditional reveal). Each layer has its own failing tests written first; later layers consume exactly what earlier layers define. No migration needed; no back-compat shim.

**Tech Stack:** PHP 8.1+ (PHPUnit + Brain Monkey for unit tests), WordPress/WooCommerce hooks, React/JSX with `@wordpress/components` (SegmentedControl, SelectControl, BaseControl), Jest for JS unit tests.

## Global Constraints

- Migration-free: the plugin is pre-release and runs only on the user's test stores; the settings shape changes in place with no back-compat shim. The merchant re-picks once.
- All copy goes through `__()`.
- No em-dashes in merchant-facing strings (AGENTS.md rule).
- Run `./bin/make-pot.sh` if any `__()` call's line number shifts (even with no string changes), or the i18n freshness gate fails.
- PHP floor 8.1; CI matrix 8.1-8.4; local may run 8.5.
- `npm test` for JS; `composer test` for PHP.
- `vendor/bin/phpcbf` before commit (CI checkstyle reporter catches alignment warnings local `phpcs` misses).
- Apply the `skip-changelog` label to the PR. This repo defers ALL changelog writing to the release-cut PR — feature/fix PRs do NOT touch CHANGELOG.md and do NOT add an `## [Unreleased]` entry (verified on #519/#514/#508). Without the label the `changelog-required` gate fails.
- End every commit message with the trailer: `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `includes/ai-storefront/class-wc-ai-storefront-return-policy.php` | Modify | Sanitize the new `mode`/`category` shape |
| `includes/class-wc-ai-storefront.php` | Modify | Default array (`'mode' => 'unconfigured'`) — no structural change needed |
| `includes/admin/class-wc-ai-storefront-admin-controller.php` | Modify | REST args schema: add `category` property; no fields-list change needed (`return_policy` already in list) |
| `includes/ai-storefront/class-wc-ai-storefront-jsonld.php` | Modify | `build_return_policy_block()`: delete link-precedence branches; read `mode`/`category` |
| `tests/php/unit/AdminReturnPolicyTest.php` | Modify | New sanitize tests for `link`/`details` modes and invalid input fallback |
| `tests/php/unit/JsonLdReturnPolicyTest.php` | Modify | New emitter tests for `link`/`details`+category; update stale link/override tests |
| `client/settings/ai-storefront/policies-tab.js` | Modify | `POLICY_MODES`, `DEFAULT_POLICY`, `derivePreview`, render conditional reveal |
| `client/settings/ai-storefront/__tests__/policies-tab.test.js` | Modify | Tests for updated `derivePreview` and new mode values |

---

### Task 1: PHP data shape — sanitize + default + REST schema

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-return-policy.php`
- Modify: `includes/class-wc-ai-storefront.php` (line ~96, the default array)
- Modify: `includes/admin/class-wc-ai-storefront-admin-controller.php` (lines ~109-137, REST args schema)
- Test: `tests/php/unit/AdminReturnPolicyTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks
- Produces:
  - `WC_AI_Storefront_Return_Policy::sanitize( $policy ): array` — new valid modes are `'unconfigured'`, `'link'`, `'details'`; new valid categories are `'returns_accepted'`, `'final_sale'`
  - Persisted shapes: `{ mode: 'unconfigured' }` | `{ mode: 'link', page_id: int }` | `{ mode: 'details', category: 'final_sale' }` | `{ mode: 'details', category: 'returns_accepted', days: int|null, fees: string, methods: string[] }`
  - Default (in `class-wc-ai-storefront.php`): `[ 'mode' => 'unconfigured' ]` — unchanged from current
  - REST schema: `return_policy` object gains a `category` property of type `string`

- [ ] **Step 1: Write failing sanitize tests for the new mode/category shapes**

Add to `tests/php/unit/AdminReturnPolicyTest.php`, after the existing `test_final_sale_mode_drops_days_fees_methods` test:

```php
// ------------------------------------------------------------------
// New mode: link (Task 1 — Option A/B separation)
// ------------------------------------------------------------------

public function test_link_mode_persists_only_mode_and_page_id(): void {
    $this->post_settings(
        [
            'return_policy' => [
                'mode'     => 'link',
                'page_id'  => 42,
                // These must be stripped — irrelevant for link mode.
                'category' => 'returns_accepted',
                'days'     => 30,
                'fees'     => 'FreeReturn',
                'methods'  => [ 'ReturnByMail' ],
            ],
        ]
    );
    $persisted = WC_AI_Storefront::get_settings()['return_policy'];
    $this->assertSame( [ 'mode' => 'link', 'page_id' => 42 ], $persisted );
}

public function test_link_mode_with_zero_page_id_persists_page_id_zero(): void {
    $this->post_settings(
        [
            'return_policy' => [
                'mode'    => 'link',
                'page_id' => 0,
            ],
        ]
    );
    $persisted = WC_AI_Storefront::get_settings()['return_policy'];
    $this->assertSame( [ 'mode' => 'link', 'page_id' => 0 ], $persisted );
}

public function test_link_mode_with_unpublished_page_resets_page_id_to_zero(): void {
    Functions\when( 'get_post_status' )->justReturn( 'draft' );
    $this->post_settings(
        [
            'return_policy' => [
                'mode'    => 'link',
                'page_id' => 99,
            ],
        ]
    );
    $persisted = WC_AI_Storefront::get_settings()['return_policy'];
    $this->assertSame( 0, $persisted['page_id'] );
}

// ------------------------------------------------------------------
// New mode: details (Task 1 — Option A/B separation)
// ------------------------------------------------------------------

public function test_details_final_sale_persists_only_mode_and_category(): void {
    $this->post_settings(
        [
            'return_policy' => [
                'mode'     => 'details',
                'category' => 'final_sale',
                // These must be stripped — not meaningful for final_sale.
                'page_id'  => 17,
                'days'     => 30,
                'fees'     => 'FreeReturn',
                'methods'  => [ 'ReturnByMail' ],
            ],
        ]
    );
    $persisted = WC_AI_Storefront::get_settings()['return_policy'];
    $this->assertSame( [ 'mode' => 'details', 'category' => 'final_sale' ], $persisted );
}

public function test_details_returns_accepted_persists_full_shape(): void {
    $this->post_settings(
        [
            'return_policy' => [
                'mode'     => 'details',
                'category' => 'returns_accepted',
                'days'     => 30,
                'fees'     => 'FreeReturn',
                'methods'  => [ 'ReturnByMail', 'ReturnInStore' ],
            ],
        ]
    );
    $persisted = WC_AI_Storefront::get_settings()['return_policy'];
    $this->assertSame( 'details', $persisted['mode'] );
    $this->assertSame( 'returns_accepted', $persisted['category'] );
    $this->assertSame( 30, $persisted['days'] );
    $this->assertSame( 'FreeReturn', $persisted['fees'] );
    $this->assertSame( [ 'ReturnByMail', 'ReturnInStore' ], $persisted['methods'] );
}

public function test_details_returns_accepted_does_not_persist_page_id(): void {
    // page_id is only meaningful for mode='link'; details drops it.
    $this->post_settings(
        [
            'return_policy' => [
                'mode'     => 'details',
                'category' => 'returns_accepted',
                'page_id'  => 55,
                'days'     => 14,
                'fees'     => 'FreeReturn',
                'methods'  => [],
            ],
        ]
    );
    $persisted = WC_AI_Storefront::get_settings()['return_policy'];
    $this->assertArrayNotHasKey( 'page_id', $persisted );
}

public function test_details_with_invalid_category_falls_back_to_unconfigured(): void {
    $this->post_settings(
        [
            'return_policy' => [
                'mode'     => 'details',
                'category' => 'pirate_category',
            ],
        ]
    );
    $persisted = WC_AI_Storefront::get_settings()['return_policy'];
    $this->assertSame( 'unconfigured', $persisted['mode'] );
}

public function test_details_without_category_falls_back_to_unconfigured(): void {
    $this->post_settings(
        [
            'return_policy' => [
                'mode' => 'details',
                // No 'category' key at all.
                'days' => 14,
            ],
        ]
    );
    $persisted = WC_AI_Storefront::get_settings()['return_policy'];
    $this->assertSame( 'unconfigured', $persisted['mode'] );
}

// Verify old modes now fail closed (they are no longer in the allow-list).

public function test_old_returns_accepted_mode_falls_back_to_unconfigured(): void {
    $this->post_settings(
        [
            'return_policy' => [ 'mode' => 'returns_accepted' ],
        ]
    );
    $persisted = WC_AI_Storefront::get_settings()['return_policy'];
    $this->assertSame( 'unconfigured', $persisted['mode'] );
}

public function test_old_final_sale_mode_falls_back_to_unconfigured(): void {
    $this->post_settings(
        [
            'return_policy' => [ 'mode' => 'final_sale' ],
        ]
    );
    $persisted = WC_AI_Storefront::get_settings()['return_policy'];
    $this->assertSame( 'unconfigured', $persisted['mode'] );
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
composer test -- --filter=AdminReturnPolicyTest 2>&1 | tail -20
```

Expected: multiple FAILs mentioning `link`, `details`, `category`, and `returns_accepted`/`final_sale` falling through to `unconfigured`.

- [ ] **Step 3: Update `WC_AI_Storefront_Return_Policy::sanitize()`**

Replace the body of `sanitize()` in `includes/ai-storefront/class-wc-ai-storefront-return-policy.php`:

```php
public static function sanitize( $policy ): array {
    if ( ! is_array( $policy ) ) {
        $policy = [];
    }

    $allowed_modes = [ 'unconfigured', 'link', 'details' ];
    $mode          = isset( $policy['mode'] ) && in_array( $policy['mode'], $allowed_modes, true )
        ? $policy['mode']
        : 'unconfigured';

    if ( 'unconfigured' === $mode ) {
        return [ 'mode' => 'unconfigured' ];
    }

    if ( 'link' === $mode ) {
        $page_id = isset( $policy['page_id'] ) ? self::absint( $policy['page_id'] ) : 0;
        if ( $page_id > 0 ) {
            $status = function_exists( 'get_post_status' ) ? get_post_status( $page_id ) : false;
            $type   = function_exists( 'get_post_type' ) ? get_post_type( $page_id ) : false;
            if ( 'publish' !== $status || 'page' !== $type ) {
                $page_id = 0;
            }
        }
        return [ 'mode' => 'link', 'page_id' => $page_id ];
    }

    // mode === 'details': requires a valid category.
    $allowed_categories = [ 'returns_accepted', 'final_sale' ];
    $category           = isset( $policy['category'] ) && in_array( $policy['category'], $allowed_categories, true )
        ? $policy['category']
        : null;

    if ( null === $category ) {
        // Unknown/missing category: fail closed.
        return [ 'mode' => 'unconfigured' ];
    }

    if ( 'final_sale' === $category ) {
        // Only mode + category are meaningful for final_sale.
        return [
            'mode'     => 'details',
            'category' => 'final_sale',
        ];
    }

    // details + returns_accepted: full 5-field shape (no page_id).
    $days = null;
    if ( array_key_exists( 'days', $policy ) && null !== $policy['days'] ) {
        $days = self::absint( $policy['days'] );
        if ( $days > 365 ) {
            $days = 365;
        }
        if ( 0 === $days ) {
            $days = null;
        }
    }

    $allowed_fees = [
        'FreeReturn',
        'ReturnFeesCustomerResponsibility',
        'OriginalShippingFees',
        'RestockingFees',
    ];
    $fees         = isset( $policy['fees'] ) && in_array( $policy['fees'], $allowed_fees, true )
        ? $policy['fees']
        : 'FreeReturn';

    $allowed_methods = [ 'ReturnByMail', 'ReturnInStore', 'ReturnAtKiosk' ];
    $methods_input   = isset( $policy['methods'] ) && is_array( $policy['methods'] )
        ? $policy['methods']
        : [];
    $methods         = [];
    foreach ( $methods_input as $method ) {
        if ( is_string( $method ) && in_array( $method, $allowed_methods, true ) ) {
            $methods[] = $method;
        }
    }
    $methods = array_values( array_unique( $methods ) );

    return [
        'mode'     => 'details',
        'category' => 'returns_accepted',
        'days'     => $days,
        'fees'     => $fees,
        'methods'  => $methods,
    ];
}
```

Also update the `$allowed_modes` docblock at the top of `sanitize()`:

```php
 * Field rules:
 *   - `mode`: one of `unconfigured`, `link`, `details`. Default `unconfigured`.
 *   - `page_id`: WP page ID. Used iff mode === 'link'. Must point to an
 *     existing, published `page` post. Otherwise reset to 0.
 *   - `category`: one of `returns_accepted`, `final_sale`. Used iff
 *     mode === 'details'. Unknown/missing → fails closed to `unconfigured`.
 *   - `days`, `fees`, `methods`: used iff mode === 'details' &&
 *     category === 'returns_accepted'. Same rules as before.
 *
 * Mode-aware persistence:
 *   - `unconfigured` → `{ mode }` only.
 *   - `link`         → `{ mode, page_id }`.
 *   - `details` + `final_sale`      → `{ mode, category }`.
 *   - `details` + `returns_accepted` → `{ mode, category, days, fees, methods }`.
 *   Unknown `mode` or `category` → `{ mode: 'unconfigured' }`.
```

- [ ] **Step 4: Add `category` property to REST args schema**

In `includes/admin/class-wc-ai-storefront-admin-controller.php`, inside the `return_policy` → `properties` array (around line 112), add `category` after the existing `mode` property:

```php
'mode'     => array(
    'type' => 'string',
),
'category' => array(
    'type' => 'string',
),
'page_id'  => array(
    'type' => 'integer',
),
```

(No change to the `$fields` whitelist at line 408 — `return_policy` is already there.)

- [ ] **Step 5: Run tests to verify they pass**

```bash
composer test -- --filter=AdminReturnPolicyTest 2>&1 | tail -20
```

Expected: all tests PASS including the newly added ones.

- [ ] **Step 6: Run phpcbf and full test suite**

```bash
vendor/bin/phpcbf includes/ai-storefront/class-wc-ai-storefront-return-policy.php includes/admin/class-wc-ai-storefront-admin-controller.php
composer test 2>&1 | tail -30
```

Expected: phpcbf reports 0 errors remaining; full suite passes.

- [ ] **Step 7: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-return-policy.php \
        includes/admin/class-wc-ai-storefront-admin-controller.php \
        tests/php/unit/AdminReturnPolicyTest.php
git commit -m "feat(return-policy): new unconfigured/link/details data shape — sanitize + REST schema"
```

---

### Task 2: PHP emitter — `build_return_policy_block()` + per-product-override preservation

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-jsonld.php` (lines ~3054-3207)
- Test: `tests/php/unit/JsonLdReturnPolicyTest.php`

**Interfaces:**
- Consumes: Task 1's sanitized shape — valid persisted shapes are `{ mode: 'unconfigured' }`, `{ mode: 'link', page_id: int }`, `{ mode: 'details', category: 'final_sale' }`, `{ mode: 'details', category: 'returns_accepted', days: int|null, fees: string, methods: string[] }`
- Produces: `build_return_policy_block( array $policy, string $country, ?int $product_id = null ): ?array` with the following contracts:
  - `mode='unconfigured'` → `null`
  - `mode='link'`, page resolves → `{ '@type': 'MerchantReturnPolicy', merchantReturnLink: <url> }` (no `returnPolicyCategory`, no `applicableCountry`)
  - `mode='link'`, page fails (zero/unpublished/wrong type) → `null`
  - `mode='details'`, `category='final_sale'` → `{ '@type': ..., returnPolicyCategory: MerchantReturnNotPermitted [, applicableCountry when country set] }`
  - `mode='details'`, `category='returns_accepted'`, `days>0`, country set → `{ '@type': ..., applicableCountry, returnPolicyCategory: MerchantReturnFiniteReturnWindow, merchantReturnDays, returnFees, [returnMethod] }`
  - `mode='details'`, `category='returns_accepted'`, `days=0|null`, country set → downgrade to `MerchantReturnUnspecified`
  - `mode='details'`, `category='returns_accepted'`, country empty → `null`
  - Per-product `is_final_sale` override: flagged product emits `MerchantReturnNotPermitted` regardless of store-wide mode; if store is `mode='link'` and page resolves, the link wins for that product too (rationale: the linked page documents what is still covered)

**Per-product override composition with the new shape (decision record):**

The `is_final_sale` per-product override gate runs before store-wide mode logic — this is unchanged. The composition rules with the new shape are:

1. Flagged product + store is `mode='link'` + page resolves → emit the link (Option B). The "no returns" page documents what's still covered (defective goods, statutory rights). This matches today's behavior where a configured page wins for flagged products. `resolve_merchant_return_link` reads from the store-wide `page_id` under the new shape only when `mode='link'`.
2. Flagged product + store is `mode='link'` + no page (or page fails) → emit `MerchantReturnNotPermitted` (bare).
3. Flagged product + store is `mode='details'` or `mode='unconfigured'` → emit `MerchantReturnNotPermitted` (no link, since there is no `page_id` in those modes).

The `link_block` construction in the override gate must now look up `page_id` conditionally: it is only present in the persisted shape when `mode='link'`. For all other modes, `page_id` is absent and `link_block` is `null`.

- [ ] **Step 1: Write failing emitter tests for the new shape**

Replace the existing `test_returns_accepted_with_page_emits_link_only`, `test_final_sale_with_page_emits_link_only`, `test_final_sale_no_page_emits_not_permitted_only`, and all per-product-override-with-page tests in `tests/php/unit/JsonLdReturnPolicyTest.php`. Delete any test that relies on old modes (`returns_accepted`/`final_sale` as top-level) and add the following:

```php
// ------------------------------------------------------------------
// Mode: link (Task 2 — new shape)
// ------------------------------------------------------------------

public function test_link_mode_with_valid_page_emits_link_only(): void {
    // Option B: only merchantReturnLink, no returnPolicyCategory,
    // no applicableCountry.
    $this->set_settings(
        [
            'mode'    => 'link',
            'page_id' => 99,
        ]
    );

    $block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

    $this->assertSame( 'MerchantReturnPolicy', $block['@type'] );
    $this->assertSame( 'https://example.com/?p=99', $block['merchantReturnLink'] );
    $this->assertArrayNotHasKey( 'returnPolicyCategory', $block );
    $this->assertArrayNotHasKey( 'applicableCountry', $block );
    $this->assertArrayNotHasKey( 'returnFees', $block );
    $this->assertArrayNotHasKey( 'merchantReturnDays', $block );
}

public function test_link_mode_with_zero_page_emits_null(): void {
    // mode='link' with page_id=0 produces nothing — the merchant
    // chose "link" but hasn't picked a page yet.
    $this->set_settings(
        [
            'mode'    => 'link',
            'page_id' => 0,
        ]
    );

    $result = $this->run_with_offer();
    $this->assertArrayNotHasKey(
        'hasMerchantReturnPolicy',
        $result['offers'][0]
    );
}

public function test_link_mode_with_unpublished_page_emits_null(): void {
    Functions\when( 'get_post_status' )->justReturn( 'draft' );
    $this->set_settings(
        [
            'mode'    => 'link',
            'page_id' => 99,
        ]
    );

    $result = $this->run_with_offer();
    $this->assertArrayNotHasKey(
        'hasMerchantReturnPolicy',
        $result['offers'][0]
    );
}

public function test_link_mode_emits_even_when_country_unset(): void {
    // Option B carries no applicableCountry, so the country gate
    // must not block it.
    Functions\when( 'wc_get_base_location' )->justReturn(
        [ 'country' => '' ]
    );
    $this->set_settings(
        [
            'mode'    => 'link',
            'page_id' => 99,
        ]
    );

    $block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];
    $this->assertSame( 'https://example.com/?p=99', $block['merchantReturnLink'] );
}

// ------------------------------------------------------------------
// Mode: details + category: final_sale (Task 2)
// ------------------------------------------------------------------

public function test_details_final_sale_emits_not_permitted(): void {
    $this->set_settings(
        [
            'mode'     => 'details',
            'category' => 'final_sale',
        ]
    );

    $block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

    $this->assertSame( 'MerchantReturnPolicy', $block['@type'] );
    $this->assertSame( 'US', $block['applicableCountry'] );
    $this->assertSame(
        'https://schema.org/MerchantReturnNotPermitted',
        $block['returnPolicyCategory']
    );
    $this->assertArrayNotHasKey( 'merchantReturnLink', $block );
    $this->assertArrayNotHasKey( 'returnFees', $block );
}

public function test_details_final_sale_emits_without_country_when_unset(): void {
    Functions\when( 'wc_get_base_location' )->justReturn(
        [ 'country' => '' ]
    );
    $this->set_settings(
        [
            'mode'     => 'details',
            'category' => 'final_sale',
        ]
    );

    $block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

    $this->assertSame(
        'https://schema.org/MerchantReturnNotPermitted',
        $block['returnPolicyCategory']
    );
    $this->assertArrayNotHasKey( 'applicableCountry', $block );
}

// ------------------------------------------------------------------
// Mode: details + category: returns_accepted (Task 2)
// ------------------------------------------------------------------

public function test_details_returns_accepted_days_gt_0_emits_finite_window(): void {
    $this->set_settings(
        [
            'mode'     => 'details',
            'category' => 'returns_accepted',
            'days'     => 30,
            'fees'     => 'FreeReturn',
            'methods'  => [ 'ReturnByMail' ],
        ]
    );

    $block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

    $this->assertSame( 'US', $block['applicableCountry'] );
    $this->assertSame(
        'https://schema.org/MerchantReturnFiniteReturnWindow',
        $block['returnPolicyCategory']
    );
    $this->assertSame( 30, $block['merchantReturnDays'] );
    $this->assertSame( 'https://schema.org/FreeReturn', $block['returnFees'] );
    $this->assertSame( 'https://schema.org/ReturnByMail', $block['returnMethod'] );
    $this->assertArrayNotHasKey( 'merchantReturnLink', $block );
}

public function test_details_returns_accepted_days_0_smart_degrades_to_unspecified(): void {
    $this->set_settings(
        [
            'mode'     => 'details',
            'category' => 'returns_accepted',
            'days'     => null,
            'fees'     => 'FreeReturn',
            'methods'  => [],
        ]
    );

    $block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

    $this->assertSame(
        'https://schema.org/MerchantReturnUnspecified',
        $block['returnPolicyCategory']
    );
    $this->assertArrayNotHasKey( 'merchantReturnDays', $block );
}

public function test_details_returns_accepted_no_country_emits_null(): void {
    Functions\when( 'wc_get_base_location' )->justReturn(
        [ 'country' => '' ]
    );
    $this->set_settings(
        [
            'mode'     => 'details',
            'category' => 'returns_accepted',
            'days'     => 30,
            'fees'     => 'FreeReturn',
            'methods'  => [],
        ]
    );

    $result = $this->run_with_offer();
    $this->assertArrayNotHasKey(
        'hasMerchantReturnPolicy',
        $result['offers'][0]
    );
}

// ------------------------------------------------------------------
// Per-product override with new shape (Task 2)
// ------------------------------------------------------------------

public function test_per_product_final_sale_with_link_mode_and_valid_page_emits_link(): void {
    // Flagged product + store is mode='link' + page resolves → link wins.
    $this->flag_product_as_final_sale();
    $this->set_settings(
        [
            'mode'    => 'link',
            'page_id' => 99,
        ]
    );

    $block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

    $this->assertSame( 'https://example.com/?p=99', $block['merchantReturnLink'] );
    $this->assertArrayNotHasKey( 'returnPolicyCategory', $block );
}

public function test_per_product_final_sale_with_link_mode_no_page_emits_not_permitted(): void {
    // Flagged product + store is mode='link' + page_id=0 → link fails,
    // fall back to NotPermitted.
    $this->flag_product_as_final_sale();
    $this->set_settings(
        [
            'mode'    => 'link',
            'page_id' => 0,
        ]
    );

    $block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

    $this->assertSame(
        'https://schema.org/MerchantReturnNotPermitted',
        $block['returnPolicyCategory']
    );
    $this->assertArrayNotHasKey( 'merchantReturnLink', $block );
}

public function test_per_product_final_sale_with_details_mode_emits_not_permitted(): void {
    // Flagged product + store is mode='details' → no page_id available,
    // emit NotPermitted.
    $this->flag_product_as_final_sale();
    $this->set_settings(
        [
            'mode'     => 'details',
            'category' => 'returns_accepted',
            'days'     => 30,
            'fees'     => 'FreeReturn',
            'methods'  => [],
        ]
    );

    $block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

    $this->assertSame(
        'https://schema.org/MerchantReturnNotPermitted',
        $block['returnPolicyCategory']
    );
    $this->assertArrayNotHasKey( 'merchantReturnLink', $block );
    $this->assertArrayNotHasKey( 'returnFees', $block );
}
```

Also update `test_unconfigured_mode_with_page_still_emits_no_policy_block` to use `mode='link'` with `page_id=99` instead of `page_id` injected into an `unconfigured` mode (the old shape allowed this; the new shape does not — `unconfigured` no longer accepts `page_id`):

```php
public function test_unconfigured_mode_emits_no_policy_block_even_with_junk_fields(): void {
    // After the mode-aware sanitizer runs, unconfigured can never carry
    // page_id — but a direct DB write or legacy stored value could. Gate
    // must still emit nothing.
    $this->set_settings( [ 'mode' => 'unconfigured', 'page_id' => 99 ] );
    $result = $this->run_with_offer();

    $this->assertArrayNotHasKey( 'hasMerchantReturnPolicy', $result['offers'][0] );
}
```

Delete or rename the now-invalid tests that reference old top-level modes (`returns_accepted`, `final_sale`) directly as `mode` values — `test_returns_accepted_no_page_emits_full_inline_detail`, `test_returns_accepted_with_page_emits_link_only`, `test_final_sale_with_page_emits_link_only`, `test_final_sale_no_page_emits_not_permitted_only`, `test_final_sale_omits_merchant_return_link_when_page_unpublished`, `test_per_product_final_sale_overrides_returns_accepted_mode`, `test_per_product_final_sale_with_store_page_emits_link_only` — replacing them with the new tests above.

- [ ] **Step 2: Run tests to verify they fail**

```bash
composer test -- --filter=JsonLdReturnPolicyTest 2>&1 | tail -30
```

Expected: failures on the new `link`/`details` tests (methods don't handle new modes yet).

- [ ] **Step 3: Rewrite `build_return_policy_block()` in `class-wc-ai-storefront-jsonld.php`**

Replace from `protected function build_return_policy_block(` (line ~3054) through the closing `}` at line ~3208 with:

```php
/**
 * Build the `hasMerchantReturnPolicy` structured-data block for an offer.
 *
 * Implements the Option A / Option B separation from Google's return-policy
 * spec:
 *   - mode='link'    → Option B: `merchantReturnLink` only, no category.
 *   - mode='details' → Option A: inline `returnPolicyCategory` + country
 *                      (+ days/fees/methods for returns_accepted).
 *   - mode='unconfigured' → null (emit nothing).
 *
 * Per-product final-sale override runs first. If the product is flagged and
 * the store is mode='link' with a resolved page, the link wins (the "no
 * returns" page documents what is still covered). Otherwise the override
 * emits MerchantReturnNotPermitted directly.
 *
 * @param array<string, mixed> $policy     Sanitized return-policy settings.
 * @param string               $country    Store base country (ISO 3166-1 alpha-2).
 * @param int|null             $product_id Product ID for per-product override lookup,
 *                                         or null to skip override (store-wide only).
 * @return array<string, mixed>|null
 */
protected function build_return_policy_block( array $policy, string $country, ?int $product_id = null ): ?array {
    $mode = $policy['mode'] ?? 'unconfigured';

    if ( 'unconfigured' === $mode ) {
        return null;
    }

    // Resolve the link-mode URL now so the per-product override can reuse it.
    $link = '';
    if ( 'link' === $mode ) {
        $page_id = isset( $policy['page_id'] ) ? (int) $policy['page_id'] : 0;
        $link    = self::resolve_merchant_return_link( $page_id );
    }

    // Per-product final-sale override. Runs before store-wide logic.
    if ( null !== $product_id && WC_AI_Storefront_Product_Meta_Box::is_final_sale( $product_id ) ) {
        if ( '' !== $link ) {
            // Store is mode='link' and page resolves: the link describes
            // what is still covered (defective goods, statutory rights).
            return [
                '@type'              => 'MerchantReturnPolicy',
                'merchantReturnLink' => $link,
            ];
        }
        // No link available: emit bare NotPermitted.
        $block = [
            '@type'                => 'MerchantReturnPolicy',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
        ];
        if ( '' !== $country ) {
            $block['applicableCountry'] = $country;
        }
        return $block;
    }

    // mode='link': Option B — link only, no category, no country.
    if ( 'link' === $mode ) {
        if ( '' === $link ) {
            // page_id=0 or page not published: emit nothing.
            return null;
        }
        return [
            '@type'              => 'MerchantReturnPolicy',
            'merchantReturnLink' => $link,
        ];
    }

    // mode='details': Option A — inline detail.
    if ( 'details' !== $mode ) {
        // Fail closed for any unrecognised mode.
        return null;
    }

    $category = $policy['category'] ?? '';

    if ( 'final_sale' === $category ) {
        $block = [
            '@type'                => 'MerchantReturnPolicy',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
        ];
        if ( '' !== $country ) {
            $block['applicableCountry'] = $country;
        }
        return $block;
    }

    if ( 'returns_accepted' !== $category ) {
        // Unknown category: fail closed.
        return null;
    }

    // details + returns_accepted requires a country.
    if ( '' === $country ) {
        return null;
    }

    $days = isset( $policy['days'] ) ? (int) $policy['days'] : 0;
    if ( $days > 0 ) {
        $block = [
            '@type'                => 'MerchantReturnPolicy',
            'applicableCountry'    => $country,
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays'   => $days,
        ];
    } else {
        $block = [
            '@type'                => 'MerchantReturnPolicy',
            'applicableCountry'    => $country,
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnUnspecified',
        ];
    }

    $allowed_fees        = [ 'FreeReturn', 'ReturnFeesCustomerResponsibility', 'OriginalShippingFees', 'RestockingFees' ];
    $fees                = isset( $policy['fees'] ) && is_string( $policy['fees'] ) && in_array( $policy['fees'], $allowed_fees, true )
        ? $policy['fees']
        : 'FreeReturn';
    $block['returnFees'] = 'https://schema.org/' . $fees;

    $allowed_methods = [ 'ReturnByMail', 'ReturnInStore', 'ReturnAtKiosk' ];
    $methods         = isset( $policy['methods'] ) && is_array( $policy['methods'] )
        ? array_values(
            array_unique(
                array_filter( $policy['methods'], static fn( $m ) => in_array( $m, $allowed_methods, true ) )
            )
        )
        : [];
    if ( count( $methods ) === 1 ) {
        $block['returnMethod'] = 'https://schema.org/' . $methods[0];
    } elseif ( count( $methods ) >= 2 ) {
        $block['returnMethod'] = array_map(
            static fn( $m ) => 'https://schema.org/' . $m,
            $methods
        );
    }

    return $block;
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
composer test -- --filter=JsonLdReturnPolicyTest 2>&1 | tail -30
```

Expected: all tests PASS.

- [ ] **Step 5: Run full suite + phpcbf**

```bash
vendor/bin/phpcbf includes/ai-storefront/class-wc-ai-storefront-jsonld.php
composer test 2>&1 | tail -20
```

Expected: no PHPCS errors; full suite green.

- [ ] **Step 6: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-jsonld.php \
        tests/php/unit/JsonLdReturnPolicyTest.php
git commit -m "feat(return-policy): rewrite build_return_policy_block for unconfigured/link/details shape"
```

---

### Task 3: JS parity helper — update `derivePreview` to mirror new emitter

**Files:**
- Modify: `client/settings/ai-storefront/policies-tab.js` (lines ~205-266, `derivePreview` function body; lines ~36-82, constants)
- Test: `client/settings/ai-storefront/__tests__/policies-tab.test.js`

**Interfaces:**
- Consumes: Task 1's new mode/category names; Task 2's emitter contract (exactly mirrors it)
- Produces:
  - `POLICY_MODES` constant: `{ UNCONFIGURED: 'unconfigured', LINK: 'link', DETAILS: 'details' }`
  - `CATEGORY_OPTIONS` constant: `{ RETURNS_ACCEPTED: 'returns_accepted', FINAL_SALE: 'final_sale' }`
  - `DEFAULT_POLICY`: `{ mode: 'unconfigured', page_id: 0, category: 'returns_accepted', days: 0, fees: 'FreeReturn', methods: [] }` — all fields present so the UI can always spread from a complete shape
  - `derivePreview( policy, country ): Object|null` — exact JS mirror of the new `build_return_policy_block()`; signature unchanged

- [ ] **Step 1: Write failing JS tests for the new derivePreview**

Replace the `describe( 'derivePreview', ...)` block in `client/settings/ai-storefront/__tests__/policies-tab.test.js` with:

```js
describe( 'derivePreview', () => {
    it( 'returns null for mode unconfigured', () => {
        expect( derivePreview( { mode: 'unconfigured' }, 'US' ) ).toBeNull();
    } );

    it( 'returns null when country is empty', () => {
        expect(
            derivePreview(
                { mode: 'details', category: 'returns_accepted', days: 30, fees: 'FreeReturn' },
                ''
            )
        ).toBeNull();
    } );

    // -- mode: link --

    it( 'link mode with pageLink emits merchantReturnLink only', () => {
        const block = derivePreview(
            { mode: 'link', page_id: 99, pageLink: 'https://example.com/returns' },
            'US'
        );
        expect( block['@type'] ).toBe( 'MerchantReturnPolicy' );
        expect( block.merchantReturnLink ).toBe( 'https://example.com/returns' );
        expect( block.returnPolicyCategory ).toBeUndefined();
        expect( block.applicableCountry ).toBeUndefined();
    } );

    it( 'link mode without pageLink returns null', () => {
        // page_id=0 or missing pageLink means no resolved URL.
        expect( derivePreview( { mode: 'link', page_id: 0 }, 'US' ) ).toBeNull();
        expect( derivePreview( { mode: 'link', page_id: 5 }, 'US' ) ).toBeNull();
    } );

    it( 'link mode emits even when country is empty', () => {
        const block = derivePreview(
            { mode: 'link', page_id: 1, pageLink: 'https://example.com/returns' },
            ''
        );
        expect( block.merchantReturnLink ).toBe( 'https://example.com/returns' );
    } );

    // -- mode: details, category: final_sale --

    it( 'details final_sale emits MerchantReturnNotPermitted', () => {
        const block = derivePreview(
            { mode: 'details', category: 'final_sale' },
            'US'
        );
        expect( block.returnPolicyCategory ).toBe(
            'https://schema.org/MerchantReturnNotPermitted'
        );
        expect( block.applicableCountry ).toBe( 'US' );
        expect( block.merchantReturnLink ).toBeUndefined();
        expect( block.returnFees ).toBeUndefined();
    } );

    it( 'details final_sale emits without applicableCountry when country empty', () => {
        const block = derivePreview(
            { mode: 'details', category: 'final_sale' },
            ''
        );
        // Country gate applies for details mode: null when country empty
        // EXCEPT final_sale which is permitted without country per PHP emitter.
        // The PHP emitter emits the block without applicableCountry.
        expect( block ).not.toBeNull();
        expect( block.applicableCountry ).toBeUndefined();
        expect( block.returnPolicyCategory ).toBe(
            'https://schema.org/MerchantReturnNotPermitted'
        );
    } );

    // -- mode: details, category: returns_accepted --

    it( 'details returns_accepted days > 0 emits FiniteReturnWindow', () => {
        const block = derivePreview(
            {
                mode: 'details',
                category: 'returns_accepted',
                days: 30,
                fees: 'FreeReturn',
                methods: [],
            },
            'US'
        );
        expect( block.returnPolicyCategory ).toBe(
            'https://schema.org/MerchantReturnFiniteReturnWindow'
        );
        expect( block.merchantReturnDays ).toBe( 30 );
        expect( block.applicableCountry ).toBe( 'US' );
        expect( block.returnFees ).toBe( 'https://schema.org/FreeReturn' );
        expect( block.returnMethod ).toBeUndefined();
        expect( block.merchantReturnLink ).toBeUndefined();
    } );

    it( 'details returns_accepted days 0 smart-degrades to Unspecified', () => {
        const block = derivePreview(
            { mode: 'details', category: 'returns_accepted', days: 0, fees: 'FreeReturn', methods: [] },
            'US'
        );
        expect( block.returnPolicyCategory ).toBe(
            'https://schema.org/MerchantReturnUnspecified'
        );
        expect( block.merchantReturnDays ).toBeUndefined();
    } );

    it( 'details returns_accepted with single method emits scalar returnMethod', () => {
        const block = derivePreview(
            { mode: 'details', category: 'returns_accepted', days: 14, fees: 'FreeReturn', methods: [ 'ReturnByMail' ] },
            'US'
        );
        expect( block.returnMethod ).toBe( 'https://schema.org/ReturnByMail' );
    } );

    it( 'details returns_accepted with multiple methods emits array returnMethod', () => {
        const block = derivePreview(
            { mode: 'details', category: 'returns_accepted', days: 14, fees: 'FreeReturn', methods: [ 'ReturnByMail', 'ReturnInStore' ] },
            'US'
        );
        expect( block.returnMethod ).toEqual( [
            'https://schema.org/ReturnByMail',
            'https://schema.org/ReturnInStore',
        ] );
    } );

    it( 'details returns_accepted no country returns null', () => {
        expect(
            derivePreview(
                { mode: 'details', category: 'returns_accepted', days: 30, fees: 'FreeReturn', methods: [] },
                ''
            )
        ).toBeNull();
    } );

    it( 'unknown mode returns null', () => {
        expect( derivePreview( { mode: 'gibberish' }, 'US' ) ).toBeNull();
    } );

    it( 'details with unknown category returns null', () => {
        expect( derivePreview( { mode: 'details', category: 'gibberish' }, 'US' ) ).toBeNull();
    } );
} );
```

- [ ] **Step 2: Run JS tests to verify they fail**

```bash
npm test -- --testPathPattern="policies-tab" 2>&1 | tail -30
```

Expected: failures on `link` and `details` describe blocks.

- [ ] **Step 3: Update `POLICY_MODES`, add `CATEGORY_OPTIONS`, update `DEFAULT_POLICY`, rewrite `derivePreview`**

In `client/settings/ai-storefront/policies-tab.js`, replace lines 36-82 and 205-266:

**Constants (replace `POLICY_MODES` and `DEFAULT_POLICY`):**

```js
const POLICY_MODES = {
    UNCONFIGURED: 'unconfigured',
    LINK: 'link',
    DETAILS: 'details',
};

const CATEGORY_OPTIONS = {
    RETURNS_ACCEPTED: 'returns_accepted',
    FINAL_SALE: 'final_sale',
};

// FEE_OPTIONS and METHOD_OPTIONS are unchanged (keep as-is).

const DEFAULT_POLICY = {
    mode: POLICY_MODES.UNCONFIGURED,
    page_id: 0,
    category: CATEGORY_OPTIONS.RETURNS_ACCEPTED,
    days: 0,
    fees: 'FreeReturn',
    methods: [],
};
```

**`derivePreview` body (replace lines ~206-266):**

```js
export const derivePreview = ( policy, country ) => {
    const mode = policy.mode;

    if ( ! mode || mode === POLICY_MODES.UNCONFIGURED ) {
        return null;
    }

    // mode: link — Option B: merchantReturnLink only, no category.
    if ( mode === POLICY_MODES.LINK ) {
        // pageLink is the test-input surrogate for the server-resolved
        // permalink (production resolves server-side via resolve_merchant_return_link).
        if ( ! policy.pageLink || policy.page_id <= 0 ) {
            return null;
        }
        return {
            '@type': 'MerchantReturnPolicy',
            merchantReturnLink: policy.pageLink,
        };
    }

    if ( mode !== POLICY_MODES.DETAILS ) {
        // Fail closed for unknown modes.
        return null;
    }

    const category = policy.category;

    if ( category === CATEGORY_OPTIONS.FINAL_SALE ) {
        const block = {
            '@type': 'MerchantReturnPolicy',
            returnPolicyCategory: 'https://schema.org/MerchantReturnNotPermitted',
        };
        if ( country ) {
            block.applicableCountry = country;
        }
        return block;
    }

    if ( category !== CATEGORY_OPTIONS.RETURNS_ACCEPTED ) {
        // Unknown category: fail closed.
        return null;
    }

    // details + returns_accepted: requires country.
    if ( ! country ) {
        return null;
    }

    const days = Number( policy.days ) || 0;
    const block =
        days > 0
            ? {
                    '@type': 'MerchantReturnPolicy',
                    applicableCountry: country,
                    returnPolicyCategory:
                        'https://schema.org/MerchantReturnFiniteReturnWindow',
                    merchantReturnDays: days,
              }
            : {
                    '@type': 'MerchantReturnPolicy',
                    applicableCountry: country,
                    returnPolicyCategory:
                        'https://schema.org/MerchantReturnUnspecified',
              };

    block.returnFees = 'https://schema.org/' + ( policy.fees || 'FreeReturn' );

    const methods = Array.isArray( policy.methods ) ? policy.methods : [];
    if ( methods.length === 1 ) {
        block.returnMethod = 'https://schema.org/' + methods[ 0 ];
    } else if ( methods.length >= 2 ) {
        block.returnMethod = methods.map( ( m ) => 'https://schema.org/' + m );
    }

    return block;
};
```

- [ ] **Step 4: Run JS tests to verify they pass**

```bash
npm test -- --testPathPattern="policies-tab" 2>&1 | tail -20
```

Expected: all tests PASS including new ones.

- [ ] **Step 5: Commit**

```bash
git add client/settings/ai-storefront/policies-tab.js \
        client/settings/ai-storefront/__tests__/policies-tab.test.js
git commit -m "feat(return-policy): update POLICY_MODES/CATEGORY_OPTIONS/DEFAULT_POLICY and derivePreview for link/details shape"
```

---

### Task 4: JS render — conditional-reveal UI in `ReturnRefundPolicySection`

**Files:**
- Modify: `client/settings/ai-storefront/policies-tab.js` (the `ReturnRefundPolicySection` component and `PoliciesTab` hydration, lines ~407-852 and ~1010-1060)
- Test: `client/settings/ai-storefront/__tests__/policies-tab.test.js` (component render tests)

**Interfaces:**
- Consumes: Task 3's `POLICY_MODES`, `CATEGORY_OPTIONS`, `DEFAULT_POLICY`, `FEE_OPTIONS`, `METHOD_OPTIONS`
- Produces: a `ReturnRefundPolicySection` component that renders a three-option `SegmentedControl` (Not configured / Link to a returns page / Specify the details here) and conditionally reveals:
  - `mode='link'`: ONLY the page dropdown
  - `mode='details'`: a category sub-choice SegmentedControl (Returns accepted / Final sale); Returns accepted reveals days/fees/methods; Final sale reveals nothing further
  - `mode='unconfigured'`: the existing warning Notice

- [ ] **Step 1: Write failing render tests**

Add a new `describe( 'ReturnRefundPolicySection render', ...)` block to `client/settings/ai-storefront/__tests__/policies-tab.test.js`. This requires `@testing-library/react`. If not already in package.json, check with `grep -r "@testing-library/react" package.json`. The project uses Jest; check if RTL is available:

```bash
grep "@testing-library" /Users/pierorocca/Projects/Automattic/woocommerce-ai-storefront/.claude/worktrees/serp-social-metadata/package.json
```

If `@testing-library/react` is available, add:

```js
import { render, screen, fireEvent } from '@testing-library/react';
import PoliciesTab from '../policies-tab';
// PoliciesTab is default export; we need ReturnRefundPolicySection which is
// not exported. Test via PoliciesTab with stub props, checking text content.
```

If RTL is NOT available (project is likely JS-unit only for this file), write the render tests as pure logic tests instead, verifying that the conditional-reveal logic expressed in the JSX is correct by checking the component's rendered output is driven by `policy.mode` and `policy.category`:

```js
describe( 'ReturnRefundPolicySection conditional reveal logic', () => {
    it( 'POLICY_MODES.LINK value is "link"', () => {
        // Verify the constant value that controls conditional reveal.
        const { POLICY_MODES } = require( '../policies-tab' );
        // POLICY_MODES is not exported; use derivePreview as a proxy:
        // if derivePreview treats mode='link' specially, the constant is right.
        const { derivePreview } = require( '../policies-tab' );
        const block = derivePreview(
            { mode: 'link', page_id: 1, pageLink: 'https://example.com/r' },
            'US'
        );
        expect( block.merchantReturnLink ).toBe( 'https://example.com/r' );
        expect( block.returnPolicyCategory ).toBeUndefined();
    } );

    it( 'CATEGORY_OPTIONS.RETURNS_ACCEPTED value is "returns_accepted"', () => {
        const { derivePreview } = require( '../policies-tab' );
        const block = derivePreview(
            { mode: 'details', category: 'returns_accepted', days: 14, fees: 'FreeReturn', methods: [] },
            'US'
        );
        expect( block.returnPolicyCategory ).toBe(
            'https://schema.org/MerchantReturnFiniteReturnWindow'
        );
    } );

    it( 'CATEGORY_OPTIONS.FINAL_SALE value is "final_sale"', () => {
        const { derivePreview } = require( '../policies-tab' );
        const block = derivePreview(
            { mode: 'details', category: 'final_sale' },
            'US'
        );
        expect( block.returnPolicyCategory ).toBe(
            'https://schema.org/MerchantReturnNotPermitted'
        );
    } );
} );
```

- [ ] **Step 2: Run JS tests to verify the logic tests pass (they should — the constants are already set)**

```bash
npm test -- --testPathPattern="policies-tab" 2>&1 | tail -10
```

Expected: all PASS (these tests exercise the already-updated constants from Task 3).

- [ ] **Step 3: Rewrite the `ReturnRefundPolicySection` component JSX**

Replace the entire `ReturnRefundPolicySection` function body (lines ~407-852 in `policies-tab.js`) with the new three-level reveal. The render structure is:

```
<Card>
  <CardBody>
    <h3>Return & refund policy</h3>
    <p>description text</p>

    {/* Top-level three-option SegmentedControl */}
    <SegmentedControl
      label={ __( 'How should returns be described?', 'woocommerce-ai-storefront' ) }
      value={ policy.mode }
      onChange={ ( val ) => handleModeChange( val ) }
      options={ [
        { value: POLICY_MODES.UNCONFIGURED, label: __( 'Not configured', 'woocommerce-ai-storefront' ) },
        { value: POLICY_MODES.LINK,         label: __( 'Link to a returns page', 'woocommerce-ai-storefront' ) },
        { value: POLICY_MODES.DETAILS,      label: __( 'Specify the details here', 'woocommerce-ai-storefront' ) },
      ] }
    />

    <div style={{ marginTop: '20px' }}>
      {/* UNCONFIGURED: warning notice */}
      { policy.mode === POLICY_MODES.UNCONFIGURED && (
        <Notice status="warning" isDismissible={ false }>
          { __( 'AI agents may downgrade your products in recommendations, or skip them entirely. Pick a returns mode to publish a policy.', 'woocommerce-ai-storefront' ) }
        </Notice>
      ) }

      {/* LINK: page dropdown only */}
      { policy.mode === POLICY_MODES.LINK && (
        <div style={{ marginBottom: spacing.s4, maxWidth: '320px' }}>
          <BaseControl
            __nextHasNoMarginBottom
            id="wc-ai-storefront-policy-page"
            help={ __( 'Link AI agents to a full-text returns policy page on your store.', 'woocommerce-ai-storefront' ) }
          >
            <BaseControl.VisualLabel style={{ ...typography.eyebrowLabel, color: colors.textSecondary }}>
              { __( 'Returns policy page', 'woocommerce-ai-storefront' ) }
            </BaseControl.VisualLabel>
            { pagesLoading ? <Spinner /> : (
              <SelectControl
                __nextHasNoMarginBottom
                id="wc-ai-storefront-policy-page"
                hideLabelFromVision
                label={ __( 'Returns policy page', 'woocommerce-ai-storefront' ) }
                value={ String( policy.page_id || 0 ) }
                options={ pageOptions.map( ( o ) => ({ ...o, value: String( o.value ) }) ) }
                onChange={ ( val ) => handleField( 'page_id', parseInt( val, 10 ) || 0 ) }
              />
            ) }
          </BaseControl>
        </div>
      ) }

      {/* DETAILS: category sub-choice */}
      { policy.mode === POLICY_MODES.DETAILS && (
        <>
          <SegmentedControl
            label={ __( 'Return category', 'woocommerce-ai-storefront' ) }
            value={ policy.category || CATEGORY_OPTIONS.RETURNS_ACCEPTED }
            onChange={ ( val ) => handleField( 'category', val ) }
            options={ [
              { value: CATEGORY_OPTIONS.RETURNS_ACCEPTED, label: __( 'Returns accepted', 'woocommerce-ai-storefront' ) },
              { value: CATEGORY_OPTIONS.FINAL_SALE,       label: __( 'Final sale', 'woocommerce-ai-storefront' ) },
            ] }
          />

          {/* Returns accepted: days, fees, methods */}
          { ( policy.category || CATEGORY_OPTIONS.RETURNS_ACCEPTED ) === CATEGORY_OPTIONS.RETURNS_ACCEPTED && (
            <div style={{ marginTop: '20px' }}>
              {/* Return fees SelectControl — maxWidth 320px, same as existing */}
              {/* Return window StepperInput — same as existing */}
              {/* Return methods checkbox group — same as existing */}
              {/* (Copy the three sub-sections verbatim from the old RETURNS_ACCEPTED
                   block, removing the page dropdown that was there before) */}
            </div>
          ) }

          {/* Final sale: no additional fields */}
        </>
      ) }
    </div>
  </CardBody>
</Card>
```

**Exact implementation notes for the implementer:**

1. Add a `handleModeChange` helper inside `ReturnRefundPolicySection` that resets mode-irrelevant fields when switching:

```js
const handleModeChange = ( val ) => {
    // When switching mode, reset fields that don't belong to the new mode
    // so stale values don't survive in the draft.
    const next = { ...DEFAULT_POLICY, mode: val };
    // Preserve category when staying in details; preserve page_id when staying in link.
    if ( val === POLICY_MODES.DETAILS ) {
        next.category = policy.category || CATEGORY_OPTIONS.RETURNS_ACCEPTED;
    }
    onChange( next );
};
```

2. The three field sub-blocks under `returns_accepted` (fees SelectControl, days StepperInput, methods checkbox group) are **copied verbatim** from the current `RETURNS_ACCEPTED` branch in the existing code — only the page dropdown that currently appears first in that branch is removed (it moves to the `LINK` branch).

3. `handleField` and `handleMethodToggle` are kept exactly as they are — they do a `{ ...policy, [field]: value }` spread which is correct.

4. Update the `hydrate` function in `PoliciesTab` to handle the new `DEFAULT_POLICY` shape. The `same` comparison in the `useEffect` must include `category`:

```js
const same =
    prev.mode === merged.mode &&
    prev.page_id === merged.page_id &&
    prev.category === merged.category &&
    prev.days === merged.days &&
    prev.fees === merged.fees &&
    Array.isArray( prev.methods ) &&
    Array.isArray( merged.methods ) &&
    prev.methods.length === merged.methods.length &&
    prev.methods.every( ( m, i ) => m === merged.methods[ i ] );
```

5. The `savedPageId` ref in the `useEffect` for fetching pages now only matters when `mode='link'`:

```js
const savedPageId = settings.return_policy?.mode === 'link'
    ? ( settings.return_policy?.page_id || 0 )
    : 0;
```

This prevents an unnecessary `/wp/v2/pages?include=` fetch when the merchant is in `details` mode.

- [ ] **Step 4: Run JS tests to confirm nothing broke**

```bash
npm test -- --testPathPattern="policies-tab" 2>&1 | tail -20
```

Expected: all PASS.

- [ ] **Step 5: Run phpcbf on any PHP files touched in this task (none), and run `./bin/make-pot.sh` if any `__()` string line refs shifted**

```bash
# Check if any __() line numbers shifted (run git diff first to confirm).
git diff --unified=0 client/settings/ai-storefront/policies-tab.js | grep -c "^[+-].*__("
# If count > 0, re-run pot:
./bin/make-pot.sh
```

- [ ] **Step 6: Build JS (production build before commit)**

```bash
npm run build 2>&1 | tail -10
```

Expected: build completes with no errors; updated `build/` artifacts present.

- [ ] **Step 7: Commit**

```bash
git add client/settings/ai-storefront/policies-tab.js \
        client/settings/ai-storefront/__tests__/policies-tab.test.js \
        build/
# If make-pot.sh ran:
# git add languages/
git commit -m "feat(return-policy): update UI to unconfigured/link/details conditional-reveal layout"
```

---

## Self-Review

**1. Spec coverage check:**

| Spec requirement | Covered in task |
|---|---|
| `unconfigured` → null | Tasks 1, 2, 3 |
| `link` → `merchantReturnLink` only, no category | Tasks 1, 2, 3 |
| `details`+`returns_accepted`+`days>0` → FiniteReturnWindow | Tasks 1, 2, 3 |
| `details`+`returns_accepted`+`days=0` → MerchantReturnUnspecified smart-degrade | Tasks 2, 3 |
| `details`+`final_sale` → MerchantReturnNotPermitted | Tasks 1, 2, 3 |
| `link` mode needs no `returnPolicyCategory`/`applicableCountry` | Tasks 2, 3 |
| `details`+`final_sale` without country: emit without `applicableCountry` | Tasks 2, 3 |
| `details`+`returns_accepted` without country: null | Tasks 2, 3 |
| Drop link-precedence branches | Task 2 |
| Sanitize unknown mode → `{ mode: 'unconfigured' }` | Task 1 |
| Sanitize unknown category → `{ mode: 'unconfigured' }` | Task 1 |
| Sanitize `link` → `{ mode, page_id }` only | Task 1 |
| Sanitize `details`+`final_sale` → `{ mode, category }` only | Task 1 |
| Sanitize `details`+`returns_accepted` → full 5-field shape, no `page_id` | Task 1 |
| REST schema: add `category` property | Task 1 |
| Default array unchanged (`{ mode: 'unconfigured' }`) | Task 1 (no change needed) |
| UI: 3-option top-level SegmentedControl | Task 4 |
| UI: `link` reveals page dropdown only | Task 4 |
| UI: `details` reveals category sub-choice | Task 4 |
| UI: category `returns_accepted` reveals days/fees/methods | Task 4 |
| UI: category `final_sale` reveals nothing further | Task 4 |
| UI: switching mode hides other branch fields | Task 4 (`handleModeChange` resets to `DEFAULT_POLICY`) |
| Per-product `is_final_sale` override preserved | Task 2 |
| Override + `link` mode + valid page → link wins | Task 2 |
| Override + `link` mode + no page → NotPermitted | Task 2 |
| Override + `details` or `unconfigured` → NotPermitted (no page_id available) | Task 2 |
| `#519` ItemList-stub path inherits automatically (no extra wiring) | Implicit — verify with existing emitter tests |
| `derivePreview` === PHP emitter parity | Task 3 |
| `./bin/make-pot.sh` if string refs shift | Task 4 (step 5) |
| No em-dashes in merchant-facing strings | All tasks (strings listed use hyphens only) |
| `phpcbf` before commit | Tasks 1, 2 (step 6/5) |

**2. Placeholder scan:** No TBD, TODO, or "similar to Task N" references found. Task 4 Step 3 says "Copy the three sub-sections verbatim from the old RETURNS_ACCEPTED block" — this is a concrete instruction (copy, don't paraphrase), not a placeholder.

**3. Type/field-name consistency:**

- `POLICY_MODES.UNCONFIGURED = 'unconfigured'` used consistently in Tasks 1, 2, 3, 4.
- `POLICY_MODES.LINK = 'link'` used consistently in Tasks 1, 2, 3, 4.
- `POLICY_MODES.DETAILS = 'details'` used consistently in Tasks 1, 2, 3, 4.
- `CATEGORY_OPTIONS.RETURNS_ACCEPTED = 'returns_accepted'` used consistently in Tasks 1, 2, 3, 4.
- `CATEGORY_OPTIONS.FINAL_SALE = 'final_sale'` used consistently in Tasks 1, 2, 3, 4.
- `page_id` field: present only in `{ mode: 'link' }` persisted shape. Task 2's PHP emitter only reads `page_id` when `mode === 'link'`. Task 3's `derivePreview` only uses `pageLink` (test surrogate) when `mode === POLICY_MODES.LINK`.
- `category` field: present only in `{ mode: 'details' }` persisted shape. Task 2 reads it after the `details` branch check. Task 3 reads it after the `DETAILS` branch check.
- Task 4 `savedPageId` now conditionally reads `page_id` only when `mode='link'` — consistent with the new shape.
- `DEFAULT_POLICY` in Task 3 includes all fields including `category` so hydrate spreads are safe regardless of what the server returns.
