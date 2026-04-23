import { createRoot } from '@wordpress/element';
import AISyndicationSettings from './settings-page';
import '../../data/ai-syndication';

// Note on DataViews' stylesheet:
// The CSS isn't bundled into the JS here — we tried that and ran
// into wp-scripts' CSS-extraction config quirks (the
// BUNDLED_PACKAGES extractor intercepts `@wordpress/*` CSS paths,
// and the `splitChunks.cacheGroups.style` test pattern didn't pick
// up our single CSS import cleanly). Instead, `scripts/copy-dataviews-css.js`
// copies `node_modules/@wordpress/dataviews/build-style/style.css`
// verbatim into `build/ai-syndication-settings.css` as a postbuild
// step. The existing `WC_AI_Storefront::admin_scripts()` handler
// already detects the file's presence and enqueues it with
// `wp-components` as a dependency — zero new server-side plumbing.
// Single source of truth: the file we copy IS the file Woo's own
// DataViews uses, so visual drift is impossible.

const container = document.getElementById( 'wc-ai-storefront-settings' );

if ( container ) {
	createRoot( container ).render( <AISyndicationSettings /> );
}
