<?php
/**
 * Seeds WooCommerce global product attributes on activation and upgrade.
 *
 * A fresh WooCommerce store ships with no product attributes. Merchants
 * who need them create them ad hoc, name them freely, and type values
 * freely — which leaves the plugin nothing predictable to read and
 * leaves Google values it often cannot use.
 *
 * Creating them ourselves fixes both ends: the merchant picks from the
 * normal attributes dropdown instead of a blank page, and we know the
 * taxonomy names exactly, so JSON-LD emission is an exact lookup rather
 * than guesswork against whatever the merchant typed.
 *
 * The seven split into two groups:
 *
 *   Closed lists (gender, age_group, condition) — Google defines these
 *   exhaustively. Our terms are the complete correct set.
 *
 *   Open vocabularies (color, size, material, pattern) — free text in
 *   Google's spec, which tells merchants to match their own landing
 *   page ("if you use 'Toasted Walnut' on your landing page, then
 *   submit that value"). Our terms are a starting point, kept small so
 *   an unused one is not clutter.
 *
 * @package WooCommerce_AI_Storefront
 * @since 0.35.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates the plugin's recommended global product attributes.
 */
class WC_AI_Storefront_Attribute_Seeder {

	/**
	 * Filter name controlling whether seeding runs at all.
	 *
	 * @var string
	 */
	const SEED_FILTER = 'wc_ai_storefront_seed_attributes';

	/**
	 * Version of the ATTRIBUTE SET, not of the plugin.
	 *
	 * Bump this only when `get_definitions()` changes — a new attribute, a
	 * renamed label, a changed term list. A plugin release that leaves the
	 * set alone leaves this alone, and no store attempts seeding on upgrade.
	 *
	 * Keying on the attribute set rather than the plugin version is what
	 * makes seeding a rare event instead of a per-release one. See #629.
	 *
	 * Bumping it is what reopens the concurrency window described on
	 * needs_seeding() and seed() below: every store's first
	 * post-upgrade request races to seed again, with no activation hook
	 * available anywhere in the fleet to serialise them. Treat a bump as
	 * a deliberate, occasional act, not a routine one.
	 *
	 * Bumped to '2' in #646, adding Condition. Safe because
	 * create_attribute() returns early when taxonomy_exists() or
	 * wc_attribute_taxonomy_id_by_name() already resolves, so a re-seed
	 * skips the six existing attributes and creates only the new one —
	 * the guard #630 added after the duplicate-Gender incident in #628.
	 * AttributeSeederTest::test_reseed_creates_only_the_new_attribute()
	 * asserts exactly that; verify it still holds before bumping again.
	 *
	 * @var string
	 */
	const SEED_VERSION = '2';

	/**
	 * Option recording the SEED_VERSION last successfully applied.
	 *
	 * @var string
	 */
	const SEEDED_OPTION = 'wc_ai_storefront_attributes_seeded';

	/**
	 * Version of the duplicate-row REPAIR, independent of the attribute set.
	 *
	 * Separate from SEED_VERSION on purpose. A store hit by #649 already
	 * holds the current SEED_VERSION — the duplicates were created by a run
	 * that succeeded — so anything gated on needs_seeding() would never run
	 * on exactly the stores that need it.
	 *
	 * @var string
	 */
	const REPAIR_VERSION = '1';

	/**
	 * Option recording the REPAIR_VERSION last applied.
	 *
	 * @var string
	 */
	const REPAIRED_OPTION = 'wc_ai_storefront_attributes_repaired';

	/**
	 * Whether this store still needs the duplicate-row repair.
	 *
	 * @return bool
	 */
	public static function needs_repair(): bool {
		return get_option( self::REPAIRED_OPTION, '' ) !== self::REPAIR_VERSION;
	}

	/**
	 * Option used as a mutex around seeding.
	 *
	 * `add_option()` is the only atomic primitive available here: the
	 * options table has a unique index on `option_name`, so exactly one
	 * concurrent caller can create this row. Everything else the seeder
	 * could ask — `taxonomy_exists()`, `wc_attribute_taxonomy_id_by_name()`
	 * — reads a cache, and on a host with a shared persistent object cache
	 * two requests can both be told the attribute is absent. That is how
	 * #649 put two `condition` rows in the table.
	 *
	 * @var string
	 */
	const LOCK_OPTION = 'wc_ai_storefront_attributes_seeding_lock';

	/**
	 * Seconds after which a held lock is treated as abandoned.
	 *
	 * A request that dies mid-seed leaves the row behind. Without a timeout
	 * the store never seeds again and nothing says why. Five minutes is far
	 * longer than a seed run (seven inserts) and short enough that a genuine
	 * crash self-heals on the next request.
	 *
	 * @var int
	 */
	const LOCK_TIMEOUT = 300;

	/**
	 * Whether the current attribute set still needs applying to this store.
	 *
	 * Public so callers can skip scheduling work entirely rather than
	 * scheduling a no-op — the difference that stops several concurrent
	 * requests from all deciding to seed. See #629.
	 *
	 * @return bool
	 */
	public static function needs_seeding(): bool {
		return get_option( self::SEEDED_OPTION, '' ) !== self::SEED_VERSION;
	}

	/**
	 * Take the seeding lock, or report that someone else holds it.
	 *
	 * Returns true only for the caller that created the option row. A
	 * held-but-stale lock is reclaimed; a held-and-fresh one is obeyed.
	 *
	 * The reclaim path deletes and re-adds rather than updating, so the
	 * re-add is still the atomic step — two requests racing to reclaim the
	 * same stale lock cannot both win.
	 *
	 * The value stored is `<timestamp>:<random>`. The random half is an
	 * ownership token: {@see release_lock()} deletes only a lock whose
	 * value still matches, so a run that overran LOCK_TIMEOUT and had its
	 * lock reclaimed cannot free the NEW owner's lock on its way out and
	 * let a third request seed alongside it.
	 *
	 * @return string The token held, or '' when another request holds it.
	 */
	private static function acquire_lock(): string {
		$now   = time();
		$token = $now . ':' . wp_generate_password( 12, false );

		// Assigned rather than inlined into the `if`: PHPStan otherwise
		// treats the second add_option() below as the same call and reports
		// its result as always false, missing that delete_option() changed
		// the state in between.
		$took_lock = add_option( self::LOCK_OPTION, $token, '', false );
		if ( $took_lock ) {
			return $token;
		}

		$held       = (string) get_option( self::LOCK_OPTION, '' );
		$held_since = (int) strtok( $held, ':' );
		if ( $held_since > 0 && ( $now - $held_since ) < self::LOCK_TIMEOUT ) {
			// Someone is mid-seed. Do nothing — this is the whole point.
			return '';
		}

		// Abandoned, or an unreadable value we should not trust. Clear it
		// and contend for it again on equal terms.
		delete_option( self::LOCK_OPTION );

		$reclaimed = add_option( self::LOCK_OPTION, $token, '', false );

		return $reclaimed ? $token : '';
	}

	/**
	 * Release the seeding lock, but only if we still own it.
	 *
	 * Always call this on the way out, including when nothing was created —
	 * a run that creates nothing still held the lock.
	 *
	 * Conditional on the token, and done as a single DELETE with both
	 * columns in the WHERE. A read-then-delete would reintroduce the race
	 * this whole change exists to remove, and `get_option()` can serve a
	 * value another request has already replaced.
	 *
	 * @param string $token The token returned by {@see acquire_lock()}.
	 */
	private static function release_lock( string $token ): void {
		if ( '' === $token ) {
			return;
		}

		global $wpdb;

		if ( isset( $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete; the option cache is what we are avoiding.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					self::LOCK_OPTION,
					$token
				)
			);
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			return;
		}

		// No $wpdb to speak of. Check-then-act is weaker, but this path is
		// unreachable in WordPress and the alternative is leaking the lock.
		if ( (string) get_option( self::LOCK_OPTION, '' ) === $token ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Whether a row for this slug already exists in the attributes table.
	 *
	 * Deliberately bypasses `wc_get_attribute_taxonomies()` and every other
	 * cached accessor — see the call site in {@see create_attribute()}.
	 *
	 * @param string $slug Bare attribute slug, without the `pa_` prefix.
	 * @return bool
	 */
	private static function attribute_row_exists( string $slug ): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the cache is the bug this guards against.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s LIMIT 1",
				$slug
			)
		);

		return null !== $found && '' !== $found;
	}

	/**
	 * Remove duplicate rows this plugin's seeding created.
	 *
	 * Repairs stores hit by #649, where two concurrent requests each passed
	 * a cache-backed existence check and both inserted. Keeps the lowest
	 * `attribute_id` for each affected slug and deletes the rest.
	 *
	 * **Deletes the row directly, and must keep doing so.**
	 * `wc_delete_attribute()` deletes every term in the taxonomy, and
	 * duplicate rows share one taxonomy — calling it here would wipe the
	 * terms the surviving row still needs. That is also why a merchant
	 * cannot fix this from Products → Attributes.
	 *
	 * Scoped to slugs in {@see get_definitions()}. A merchant with two of
	 * their own attributes sharing a name is not ours to change.
	 *
	 * Products are unaffected by which row survives: `_product_attributes`
	 * stores `name => pa_<slug>` with no attribute id, so the link is by
	 * taxonomy name.
	 *
	 * Public so it can be tested directly and invoked from a recovery path.
	 *
	 * @return int Rows deleted.
	 */
	public static function repair_duplicates(): int {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return 0;
		}

		$ours = array_keys( self::get_definitions() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repairing the table the cache misreports.
		$rows = $wpdb->get_results(
			"SELECT attribute_id, attribute_name FROM {$wpdb->prefix}woocommerce_attribute_taxonomies ORDER BY attribute_name ASC, attribute_id ASC"
		);
		if ( empty( $rows ) ) {
			return 0;
		}

		$seen    = array();
		$deleted = 0;
		foreach ( $rows as $row ) {
			$name = isset( $row->attribute_name ) ? (string) $row->attribute_name : '';
			if ( '' === $name || ! in_array( $name, $ours, true ) ) {
				continue;
			}
			if ( ! isset( $seen[ $name ] ) ) {
				// Lowest id wins — the ordering above guarantees we meet it
				// first, and it is the one any earlier cache warm-up is most
				// likely to have resolved to.
				$seen[ $name ] = true;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See the docblock: wc_delete_attribute() would take the terms with it.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_id = %d",
					(int) $row->attribute_id
				)
			);
			++$deleted;
		}

		if ( $deleted > 0 ) {
			// The cached list still holds the row we just removed, and every
			// admin surface reads it. Exactly what wc_create_attribute()
			// does after it writes — NOT wp_cache_flush(), which empties
			// every group on the site and is expensive where a persistent
			// object cache is in play, which is precisely the kind of host
			// that produces these duplicates.
			delete_transient( 'wc_attribute_taxonomies' );
			if ( class_exists( 'WC_Cache_Helper' ) ) {
				WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
			}
		}

		return $deleted;
	}

	/**
	 * Attribute definitions, in creation order.
	 *
	 * Keys are the bare slug WITHOUT the `pa_` prefix — `wc_create_attribute()`
	 * strips a leading `pa_` from whatever slug it is given, so passing
	 * `gender` yields the `pa_gender` taxonomy.
	 *
	 * Labels are translated because they are merchant-facing: they become
	 * the attribute name shown in the WooCommerce admin. Translating them
	 * cannot affect matching, because the slug is passed explicitly and is
	 * never derived from the label.
	 *
	 * Terms are deliberately NOT translated, for two different reasons:
	 *
	 *   Closed lists (gender, age_group, condition) must be Google's exact
	 *   English values. Google requires them "submitted in English" regardless of
	 *   store language, and a localised value is simply rejected.
	 *
	 *   Open vocabularies (color, size, material, pattern) are a starting
	 *   point the merchant edits, and Google asks that the submitted value
	 *   match the merchant's own product page. Shipping a translated guess
	 *   would put a value in the catalog that contradicts the storefront.
	 *
	 * @return array<string, array{label: string, terms: string[]}>
	 */
	public static function get_definitions(): array {
		return array(
			// Closed list. Google's complete accepted set.
			'gender'    => array(
				'label' => __( 'Gender', 'woocommerce-ai-storefront' ),
				'terms' => array( 'male', 'female', 'unisex' ),
			),
			// Closed list. Google's complete accepted set.
			'age_group' => array(
				'label' => __( 'Age group', 'woocommerce-ai-storefront' ),
				'terms' => array( 'newborn', 'infant', 'toddler', 'kids', 'adult' ),
			),
			// Closed list. Google's complete accepted set — and complete
			// is the operative word. schema.org's OfferItemCondition also
			// has DamagedCondition, which Google does not accept; a
			// merchant who picked it would believe they had declared a
			// condition and would have declared nothing.
			'condition' => array(
				'label' => __( 'Condition', 'woocommerce-ai-storefront' ),
				'terms' => array( 'new', 'refurbished', 'used' ),
			),
			// Open vocabulary. Google's "standard names" plus obvious gaps.
			'color'     => array(
				'label' => __( 'Color', 'woocommerce-ai-storefront' ),
				'terms' => array(
					'Black',
					'White',
					'Gray',
					'Beige',
					'Brown',
					'Red',
					'Orange',
					'Yellow',
					'Green',
					'Blue',
					'Purple',
					'Pink',
				),
			),
			// Open vocabulary. Abbreviations per Google's consistency
			// guidance, NOT WooCommerce sample data's Small/Medium/Large.
			'size'      => array(
				'label' => __( 'Size', 'woocommerce-ai-storefront' ),
				'terms' => array( 'XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', 'One size' ),
			),
			// Open vocabulary. Apparel-weighted; composites use a slash.
			'material'  => array(
				'label' => __( 'Material', 'woocommerce-ai-storefront' ),
				'terms' => array(
					'Cotton',
					'Polyester',
					'Wool',
					'Leather',
					'Silk',
					'Linen',
					'Denim',
					'Nylon',
					'Rayon',
					'Cashmere',
				),
			),
			// Open vocabulary. "Solid" is a deliberate inclusion — Google
			// warns off "none"/"n/a"/"multi"/"other", but Solid is a real
			// descriptor and the honest answer for an unpatterned garment.
			'pattern'   => array(
				'label' => __( 'Pattern', 'woocommerce-ai-storefront' ),
				'terms' => array(
					'Solid',
					'Striped',
					'Plaid',
					'Floral',
					'Polka dot',
					'Herringbone',
					'Camouflage',
					'Animal print',
				),
			),
		);
	}

	/**
	 * Creates one global attribute and its terms.
	 *
	 * Skips entirely when the taxonomy already exists. An existing
	 * attribute belongs to the merchant: its terms may be variation axes,
	 * so renaming or adding to them would break variations and orphan
	 * product data. Leaving a merchant with a dropdown containing both
	 * "Grown-up" and "adult" is worse than either alone.
	 *
	 * `wc_create_attribute()` does NOT register the taxonomy in the
	 * current request — WooCommerce registers attribute taxonomies on
	 * `init` via `WC_Post_Types::register_taxonomies()`. Inserting terms
	 * before registering therefore fails with an invalid-taxonomy error.
	 * WooCommerce hits this in its own CSV importer and solves it the
	 * same way, in `abstract-wc-product-importer.php`, commented
	 * "Register as taxonomy while importing".
	 *
	 * Two existence guards, not one. `taxonomy_exists()` reflects the
	 * in-memory taxonomy registry WooCommerce builds once per request (at
	 * `init` priority 5); it does not see an attribute a concurrent
	 * request creates AFTER this request's registry was already built.
	 * `wc_create_attribute()`'s own duplicate check is that exact same
	 * `taxonomy_exists()` call, so it adds no protection against that
	 * race. `wc_attribute_taxonomy_id_by_name()` closes most of the
	 * window instead: it reads the `wc_attribute_taxonomies`
	 * transient/object-cache, which `wc_create_attribute()` explicitly
	 * busts on every insert — so a sibling request's freshly-created row
	 * is visible here even when this request's taxonomy registry is
	 * stale. Two closely-timed requests can still both pass both checks
	 * (an airtight fix needs a DB unique constraint or lock), but the
	 * window shrinks from "this request's entire runtime up to this
	 * point" — which `WC_AI_Storefront_Crawl_Logger::create_tables()`'s
	 * dbDelta call, running earlier in the same version-mismatch branch,
	 * can stretch to a noticeable duration — down to the DB read/write
	 * itself. This matters beyond a cosmetic duplicate row:
	 * `wc_delete_attribute()` deletes every term in a taxonomy, so a
	 * merchant who tidies up a duplicate "Gender" attribute in the admin
	 * would empty the one they meant to keep.
	 *
	 * @param string                                $slug       Bare slug, no `pa_` prefix.
	 * @param array{label: string, terms: string[]} $definition Label and terms.
	 * @return bool True when the attribute was created.
	 */
	public static function create_attribute( string $slug, array $definition ): bool {
		if ( ! function_exists( 'wc_create_attribute' ) || ! function_exists( 'wc_attribute_taxonomy_name' ) ) {
			return false;
		}

		$taxonomy = wc_attribute_taxonomy_name( $slug );
		if ( taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		// Second guard, and it reads the TABLE rather than a cache.
		//
		// wc_attribute_taxonomy_id_by_name() used to sit here. It resolves
		// through wc_get_attribute_taxonomies(), which serves from the
		// `wc_attribute_taxonomies` transient — so on a host with a shared
		// persistent object cache it can report "absent" for a row that
		// exists, exactly as taxonomy_exists() does. Two guards, one
		// answer, both wrong: #649 put two `condition` rows in the table
		// that way.
		//
		// This does NOT make concurrent creation safe on its own — two
		// requests can both read "no row" and both insert. seed() holds an
		// add_option() lock for that. This makes the method correct for any
		// caller that does not.
		if ( self::attribute_row_exists( $slug ) ) {
			return false;
		}

		$attribute_id = wc_create_attribute(
			array(
				'name'         => $definition['label'],
				'slug'         => $slug,
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			)
		);

		if ( is_wp_error( $attribute_id ) ) {
			return false;
		}

		register_taxonomy(
			$taxonomy,
			array( 'product' ),
			array(
				'labels'       => array( 'name' => $definition['label'] ),
				'hierarchical' => true,
				'show_ui'      => false,
				'query_var'    => true,
				'rewrite'      => false,
			)
		);

		// A failure partway through this loop is permanent, not
		// self-healing: register_taxonomy() above has already run, so on
		// the next seed() call (next request or activation) the
		// taxonomy_exists() guard at the top of this method short-circuits
		// before ever reaching this loop again, leaving whichever terms
		// failed to insert missing indefinitely. Real-world risk is low —
		// these terms are hardcoded plugin data (see get_definitions()),
		// not user input — but this does not self-heal, so don't assume
		// a retry will fill in the gap.
		foreach ( $definition['terms'] as $term ) {
			if ( term_exists( $term, $taxonomy ) ) {
				continue;
			}
			wp_insert_term( $term, $taxonomy );
		}

		return true;
	}

	/**
	 * Creates every missing attribute.
	 *
	 * Idempotent: safe to call on every activation and every upgrade.
	 * That is not the same as running every time it is called, though —
	 * see needs_seeding() above. Once the SEED_VERSION flag matches,
	 * this returns 0 immediately and the loop below never runs, for any
	 * store.
	 *
	 * The per-attribute decision applies WITHIN a run that actually
	 * reaches the loop: while a run is happening, a store that already
	 * has Color but not Size gets Size created and Color left alone.
	 * Once the flag matches, no run happens at all, so a store missing
	 * an attribute stays missing it — this does not re-check or
	 * self-heal on every call, only on the runs needs_seeding() allows.
	 *
	 * @return int Number of attributes created.
	 */
	public static function seed(): int {
		$needs_seeding = self::needs_seeding();
		$needs_repair  = self::needs_repair();
		if ( ! $needs_seeding && ! $needs_repair ) {
			return 0;
		}

		/**
		 * Filters whether the plugin seeds its recommended product attributes.
		 *
		 * Return false to skip entirely — useful for a store that will
		 * never sell apparel and does not want seven unused taxonomies.
		 *
		 * @since 0.35.0
		 *
		 * @param bool $should_seed Whether to create missing attributes.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- SEED_FILTER is the literal 'wc_ai_storefront_seed_attributes'; the sniff can't resolve the constant to see the prefix.
		if ( ! apply_filters( self::SEED_FILTER, true ) ) {
			return 0;
		}

		// Everything above this line is cheap and cache-safe. Past this
		// point we are about to INSERT, and the existence checks inside
		// create_attribute() cannot be trusted against a concurrent
		// request — see LOCK_OPTION. Nothing may create an attribute
		// without holding this.
		$lock_token = self::acquire_lock();
		if ( '' === $lock_token ) {
			return 0;
		}

		try {
			$created = 0;

			// Repair BEFORE creating. A store carrying duplicates from #649
			// should be cleaned up whether or not it also needs new
			// attributes, and cleaning up first means create_attribute()'s
			// table check reads a table with one row per slug.
			if ( $needs_repair ) {
				self::repair_duplicates();
				update_option( self::REPAIRED_OPTION, self::REPAIR_VERSION );
			}

			if ( $needs_seeding ) {
				foreach ( self::get_definitions() as $slug => $definition ) {
					if ( self::create_attribute( $slug, $definition ) ) {
						++$created;
					}
				}

				// Recorded even when $created is 0: every attribute already
				// existing is a successful outcome, not a reason to retry on
				// the next request.
				update_option( self::SEEDED_OPTION, self::SEED_VERSION );
			}
		} finally {
			// finally, not a trailing call: an exception partway through
			// would otherwise leave the lock held for LOCK_TIMEOUT and
			// silently block every other request from seeding.
			self::release_lock( $lock_token );
		}

		return $created;
	}
}
