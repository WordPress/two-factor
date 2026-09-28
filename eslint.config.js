/**
 * Project ESLint config.
 *
 * Uses the plugin's `recommended-with-formatting` preset, which enforces
 * the WordPress formatting style with native ESLint rules — instead of
 * Prettier, whose stock build does not support the WordPress `foo( bar )`
 * paren-spacing style.
 */
const wordpress = require( '@wordpress/eslint-plugin' );

module.exports = [
	{
		ignores: [ '**/node_modules/**', '**/build/**', '**/vendor/**' ],
	},
	...wordpress.configs[ 'recommended-with-formatting' ],
	{
		rules: {
			// Preset requires dangling commas in multiline literals; the
			// legacy code style omits them.
			'comma-dangle': [ 'error', 'never' ],
			// The legacy provider scripts stay ES5 for old-browser support.
			'no-var': 'off',
			// Same: the legacy code style uses explicit ES5 property
			// names (`key: key`) rather than object shorthand.
			'object-shorthand': 'off',
			'prefer-const': 'off',
		},
	},
];
