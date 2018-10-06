/* jshint es3:false, node:true */

const fs = require( 'fs' );
const ignoreParse = require( 'parse-gitignore' );

module.exports = function( grunt ) {
	'use strict';

	require( 'load-grunt-tasks' )( grunt );

	let distignore = ignoreParse( fs.readFileSync( '.distignore' ) ).map( path => `!${path}` );

	grunt.initConfig( {
		pkg: grunt.file.readJSON( 'package.json' ),

		dist_dir: 'dist',

		clean: {
			build: [ '<%= dist_dir %>' ],
		},

		copy: {
			dist: {
				src: [ '**' ].concat( distignore ),
				dest: '<%= dist_dir %>',
				expand: true,
			}
		},

		wp_deploy: {
			options: {
				plugin_slug: 'two-factor',
				build_dir: '<%= dist_dir %>',
				assets_dir: 'assets',
				deploy_tag: false,
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
