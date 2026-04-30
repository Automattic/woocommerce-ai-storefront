/**
 * ESLint flat config for the plugin.
 *
 * Extends the @wordpress/scripts default and adapts a few rule
 * defaults to behavior changes introduced in @wordpress/scripts 32 /
 * ESLint 9 / eslint-plugin-jsdoc latest:
 *
 *   - jsdoc/no-undefined-types: register `JSX` as a known JSDoc type
 *     so `@type {JSX.Element}` keeps validating without forcing every
 *     JSDoc comment to use `import('react').JSX.Element`.
 *   - no-unused-vars: honor the `_`-prefix convention for intentionally
 *     unused catch bindings and function args.
 */
const wpScriptsConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...wpScriptsConfig,
	{
		rules: {
			'jsdoc/no-undefined-types': [
				'error',
				{ definedTypes: [ 'JSX' ] },
			],
			'no-unused-vars': [
				'error',
				{
					argsIgnorePattern: '^_',
					caughtErrors: 'all',
					caughtErrorsIgnorePattern: '^_',
					varsIgnorePattern: '^_',
				},
			],
		},
	},
];
