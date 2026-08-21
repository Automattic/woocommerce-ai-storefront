<?php
/**
 * SEOPress Open Graph coexistence: remove its social tags, keep the rest.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stand SEOPress's social metadata down on commerce pages.
 *
 * SEOPress offers no filter to correct or extend its Open Graph, so the only
 * way to end up with one set of tags is to take its. What it does offer is
 * granularity: sixteen separate `wp_head` actions, one per tag, entirely
 * separate from its title, canonical, robots, description and JSON-LD. So the
 * removal is surgical, and everything SEOPress is handling well stays.
 *
 * Verified across all five commerce page types (#676 spike,
 * `captures/seopress-strip/`): 16 callbacks removed each, 0 SEOPress social
 * tags remaining, title, canonical, robots, description and schema intact.
 *
 * Description is untouched here on purpose. WC_AI_Storefront_Rival_Seo_Description
 * already decides who writes that one, per request, and #669 settled that it
 * is SEOPress's when SEOPress writes one.
 */
class WC_AI_Storefront_Og_Strategy_Seopress implements WC_AI_Storefront_Og_Strategy {

	/**
	 * The sixteen `wp_head` callbacks SEOPress renders social tags from.
	 *
	 * Enumerated rather than pattern-matched: `remove_action()` needs the
	 * exact callback, and a prefix sweep over $wp_filter would also catch
	 * whatever SEOPress adds under this prefix in a future release, which is
	 * not a decision to make on a merchant's behalf ahead of measuring it.
	 *
	 * @var string[]
	 */
	private const SOCIAL_CALLBACKS = array(
		'seopress_social_facebook_og_url_hook',
		'seopress_social_facebook_og_site_name_hook',
		'seopress_social_facebook_og_locale_hook',
		'seopress_social_facebook_og_type_hook',
		'seopress_social_facebook_og_author_hook',
		'seopress_social_fb_title_hook',
		'seopress_social_fb_desc_hook',
		'seopress_social_fb_img_hook',
		'seopress_social_facebook_link_ownership_id_hook',
		'seopress_social_facebook_app_id_hook',
		'seopress_social_twitter_card_summary_hook',
		'seopress_social_twitter_card_site_hook',
		'seopress_social_twitter_card_creator_hook',
		'seopress_social_twitter_title_hook',
		'seopress_social_twitter_desc_hook',
		'seopress_social_twitter_img_hook',
	);

	/**
	 * The priority SEOPress registers all sixteen at.
	 */
	private const SOCIAL_PRIORITY = 1;

	/**
	 * Whether we are emitting our own tags this request.
	 *
	 * @var callable
	 */
	private $on_commerce_page;

	/**
	 * The detector slug this strategy answers for.
	 */
	public static function slug(): string {
		return 'seopress';
	}

	/**
	 * Register the removal, at the one timing that works.
	 *
	 * SEOPress does NOT register the sixteen callbacks at plugin load.
	 * `seopress_load_social_options` runs at `wp_head:0` and `require_once`s
	 * `inc/functions/options-social.php`, and that require is what registers
	 * them (`inc/functions/options.php:139`). Two obvious timings therefore
	 * fail, and one of them fails silently while reporting success:
	 *
	 * - A remover at `wp_head:0` added at file scope runs BEFORE SEOPress's
	 *   loader. It finds nothing, removes nothing, and says nothing.
	 * - A remover at `wp_head:1` added at file scope runs first in the
	 *   priority-1 bucket and reports removing all sixteen, and they emit
	 *   anyway. `WP_Hook::apply_filters()` iterates
	 *   `foreach ( $this->callbacks[ $priority ] as $the_ )`, which copies the
	 *   bucket at loop entry, so a same-priority removal made mid-bucket has
	 *   no effect. This one is the trap: the log says removed, the page says
	 *   otherwise.
	 *
	 * Registering the `wp_head:0` remover from `template_redirect` puts it
	 * last in the priority-0 bucket, after SEOPress's loader and a full
	 * priority before anything emits. Confirmed on all five page types; the
	 * two alternatives above were each measured failing.
	 *
	 * @param callable $on_commerce_page Resolved at removal time, not here.
	 */
	public function init( callable $on_commerce_page ): void {
		$this->on_commerce_page = $on_commerce_page;
		add_action( 'template_redirect', array( $this, 'register_removal' ) );
	}

	/**
	 * Add the remover, now that SEOPress's loader is guaranteed to precede it.
	 */
	public function register_removal(): void {
		add_action( 'wp_head', array( $this, 'remove_social_tags' ), 0 );
	}

	/**
	 * Drop SEOPress's social callbacks for this request.
	 */
	public function remove_social_tags(): void {
		if ( ! ( $this->on_commerce_page )() ) {
			return;
		}

		foreach ( self::SOCIAL_CALLBACKS as $callback ) {
			remove_action( 'wp_head', $callback, self::SOCIAL_PRIORITY );
		}
	}
}
