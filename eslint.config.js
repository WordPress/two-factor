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
			// Preset requires dangling commas in multiline literals.
			'comma-dangle': 'off',
			// The legacy provider scripts use multi-variable declarations
			// where only some variables are ever reassigned; prefer-const
			// flags those but its fixer cannot split the declaration.
			'prefer-const': 'off',
		},
	},
];
