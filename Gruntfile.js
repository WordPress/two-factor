/* eslint-env node,es6 */

const ignoreParse = require( 'parse-gitignore' );

module.exports = function( grunt ) {
	'use strict';

	require( 'load-grunt-tasks' )( grunt );

	const distignore = ignoreParse( '.distignore', [], {
		invert: true,
	} );

	/**
	 * Check if CLI input appears to indicate a truthy value.
	 *
	 * @param {string} input Value to check.
	 * @return {boolean} If value appears to be truthy.
	 */
	function isTruthy( input ) {
		return ( '1' === input || 'true' === input );
	}

	grunt.initConfig( {
		pkg: grunt.file.readJSON( 'package.json' ),

		dist_dir: 'dist',

		clean: {
			build: [ '<%= dist_dir %>' ],
		},

		copy: {
			dist: {
				files: [
					{
						src: [ '**' ].concat( distignore ),
						dest: '<%= dist_dir %>',
						expand: true,
					},
					{
						cwd: 'node_modules/',
						src: 'qrcode-generator/qrcode.js',
						dest: '<%= dist_dir %>/includes',
						expand: true,
					}
				],
			},
		},
	} );

	grunt.registerTask(
		'build', [
			'clean',
			'copy',
		]
	);
};
