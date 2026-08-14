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
 * The six split into two groups:
 *
 *   Closed lists (gender, age_group) — Google defines these
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
	 * Attribute definitions, in creation order.
	 *
	 * Keys are the bare slug WITHOUT the `pa_` prefix — `wc_create_attribute()`
	 * strips a leading `pa_` from whatever slug it is given, so passing
	 * `gender` yields the `pa_gender` taxonomy.
	 *
	 * @return array<string, array{label: string, terms: string[]}>
	 */
	public static function get_definitions(): array {
		return array(
			// Closed list. Google's complete accepted set.
			'gender'    => array(
				'label' => 'Gender',
				'terms' => array( 'male', 'female', 'unisex' ),
			),
			// Closed list. Google's complete accepted set.
			'age_group' => array(
				'label' => 'Age group',
				'terms' => array( 'newborn', 'infant', 'toddler', 'kids', 'adult' ),
			),
			// Open vocabulary. Google's "standard names" plus obvious gaps.
			'color'     => array(
				'label' => 'Color',
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
				'label' => 'Size',
				'terms' => array( 'XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', 'One size' ),
			),
			// Open vocabulary. Apparel-weighted; composites use a slash.
			'material'  => array(
				'label' => 'Material',
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
				'label' => 'Pattern',
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

		// Second, DB-backed guard — see the method docblock above for why
		// taxonomy_exists() alone is not enough to prevent a duplicate
		// row under concurrent requests. function_exists() keeps this
		// file's existing per-call defensive posture even though, in
		// practice, this function is always defined alongside
		// wc_create_attribute() and wc_attribute_taxonomy_name() (all
		// three live in WooCommerce's wc-attribute-functions.php).
		if ( function_exists( 'wc_attribute_taxonomy_id_by_name' ) && wc_attribute_taxonomy_id_by_name( $slug ) ) {
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
	 * The decision is per attribute, so a store that already has Color
	 * but not Size gets Size created and Color left alone.
	 *
	 * @return int Number of attributes created.
	 */
	public static function seed(): int {
		/**
		 * Filters whether the plugin seeds its recommended product attributes.
		 *
		 * Return false to skip entirely — useful for a store that will
		 * never sell apparel and does not want six unused taxonomies.
		 *
		 * @since 0.35.0
		 *
		 * @param bool $should_seed Whether to create missing attributes.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- SEED_FILTER is the literal 'wc_ai_storefront_seed_attributes'; the sniff can't resolve the constant to see the prefix.
		if ( ! apply_filters( self::SEED_FILTER, true ) ) {
			return 0;
		}

		$created = 0;
		foreach ( self::get_definitions() as $slug => $definition ) {
			if ( self::create_attribute( $slug, $definition ) ) {
				++$created;
			}
		}

		return $created;
	}
}
