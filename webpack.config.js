const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const WooCommerceDependencyExtractionWebpackPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );
const CopyPlugin = require( 'copy-webpack-plugin' );

// The WooCommerce dependency-extraction plugin is a superset of WP's: it
// handles both `@wordpress/*` AND `@woocommerce/*` imports, replacing them
// with runtime references to `window.wp.*` / `window.wc.*`. This keeps
// Woo components (SummaryNumber, Table, Pill, currency helpers) out of our
// bundle entirely — the merchant's WooCommerce install supplies them at
// runtime. Without this swap, `import { SummaryNumber } from
// '@woocommerce/components'` would pull ~500 KB of Woo UI into our bundle.
//
// We filter out WP's default extractor (which only handles @wordpress/*)
// and replace it with the Woo one, since running both would double-process
// @wordpress/* imports.
const plugins = ( defaultConfig.plugins || [] ).filter(
	( plugin ) =>
		plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
);
plugins.push( new WooCommerceDependencyExtractionWebpackPlugin() );

// Copy @wordpress/dataviews' pre-built stylesheet into our output on every
// webpack compilation (build AND watch). This ensures the CSS is always
// present regardless of which script runs — `npm run build` or `npm start`.
plugins.push(
	new CopyPlugin( {
		patterns: [
			{
				from: path.resolve(
					__dirname,
					'node_modules/@wordpress/dataviews/build-style/style.css'
				),
				to: 'ai-storefront-settings.css',
			},
		],
	} )
);

module.exports = {
	...defaultConfig,
	entry: {
		'ai-storefront-settings': './client/settings/ai-storefront/index.js',
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
	resolve: {
		...defaultConfig.resolve,
		extensions: [ '.json', '.js', '.jsx' ],
		modules: [ path.join( __dirname, 'client' ), 'node_modules' ],
	},
	plugins,
};
