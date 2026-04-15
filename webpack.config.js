const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'ai-syndication-settings': './client/settings/ai-syndication/index.js',
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
};
