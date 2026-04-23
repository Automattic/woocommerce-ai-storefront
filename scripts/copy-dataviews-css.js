#!/usr/bin/env node
/**
 * Copy @wordpress/dataviews' stylesheet into our build output.
 *
 * Runs as a `postbuild` npm script. Takes `node_modules/@wordpress/dataviews/build-style/style.css`
 * and writes it verbatim to `build/ai-syndication-settings.css`, where
 * WC_AI_Storefront::admin_scripts() picks it up via `file_exists()` and
 * registers it with `wp-components` as a dependency. The plugin's existing
 * enqueue path already handles the rest.
 *
 * Why this is a separate copy step, not a webpack CSS import:
 *   1. The WP dep-extraction plugin's `defaultRequestToExternal` matches
 *      any `@wordpress/*` request — including `@wordpress/dataviews/build-style/style.css`
 *      — and rewrites it into a `window.wp.*` external reference. The CSS
 *      never reaches css-loader, nothing is emitted.
 *   2. Bypassing via a relative path (`../../../node_modules/...`) lets
 *      css-loader run, but wp-scripts' `splitChunks.cacheGroups.style`
 *      rule ended up marking the extracted CSS as an orphan module and
 *      no `.css` file was emitted either.
 *   3. The plugin's other styling is all inline — there's no pre-existing
 *      CSS pipeline to model this after, and adding one just for DataViews
 *      would be a disproportionate build-system change.
 *
 * This script is the pragmatic alternative: a 10-line copy that works
 * reliably, survives wp-scripts upgrades, and keeps DataViews' CSS
 * identical to what Woo's own Site Editor ships.
 *
 * TODO: this approach scales to one bundled package. When a second
 * bundled-package CSS import arrives (e.g. `@wordpress/admin-ui` or
 * `@wordpress/fields` — both are in the extractor's BUNDLED_PACKAGES
 * list), replace this one-off copy with a proper webpack config that
 * uses `MiniCssExtractPlugin` directly for relative-path CSS imports
 * OR a CopyWebpackPlugin pass over the known bundled stylesheets.
 * Half-day of work; not justified for a single source today. See
 * AGENTS.md §Styling "Deferred UX" subsection for the broader scaling
 * note on the DataViews-first styling pipeline.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const source = path.resolve(
	__dirname,
	'..',
	'node_modules',
	'@wordpress',
	'dataviews',
	'build-style',
	'style.css'
);

const dest = path.resolve(
	__dirname,
	'..',
	'build',
	'ai-syndication-settings.css'
);

if ( ! fs.existsSync( source ) ) {
	console.error(
		`copy-dataviews-css: source not found at ${ source }. ` +
			`Did npm install fail, or is @wordpress/dataviews missing from dependencies?`
	);
	process.exit( 1 );
}

// Ensure the build directory exists. The main webpack build creates it,
// but running this script standalone before the first build would fail
// without this guard.
const buildDir = path.dirname( dest );
if ( ! fs.existsSync( buildDir ) ) {
	fs.mkdirSync( buildDir, { recursive: true } );
}

fs.copyFileSync( source, dest );

const stats = fs.statSync( dest );
console.log(
	`copy-dataviews-css: wrote ${ dest } (${ Math.round(
		stats.size / 1024
	) } KB)`
);
