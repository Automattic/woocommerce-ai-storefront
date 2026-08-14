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
}
