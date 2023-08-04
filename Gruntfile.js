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

		wp_deploy: {
			options: {
				plugin_slug: 'two-factor',
				build_dir: '<%= dist_dir %>',
				assets_dir: 'assets',
			},
			wporg: {
				options: {
					skip_confirmation: isTruthy( process.env.DEPLOY_SKIP_CONFIRMATION ),
					svn_user: process.env.DEPLOY_SVN_USERNAME,
					deploy_tag: isTruthy( process.env.DEPLOY_TAG ),
					deploy_trunk: isTruthy( process.env.DEPLOY_TRUNK ),
					assets_dir: ( isTruthy( process.env.DEPLOY_TAG ) || isTruthy( process.env.DEPLOY_TRUNK ) ) ? 'assets' : null,
				},
			},
		},
	} );

	grunt.registerTask(
		'build', [
			'clean',
			'copy',
		]
	);

	grunt.registerTask(
		'deploy', [
			'build',
			'wp_deploy',
		]
	);
};
