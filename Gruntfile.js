/* eslint-env node,es6 */

const fs = require( 'fs' );
const ignoreParse = require( 'parse-gitignore' );

module.exports = function( grunt ) {
	'use strict';

	require( 'load-grunt-tasks' )( grunt );

	const distignore = ignoreParse( '.distignore', [], {
		invert: true,
	} );

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

	grunt.registerTask(
		'blueprint-url',
		function() {
			const blueprintJson = JSON.parse( fs.readFileSync( 'assets/blueprints/blueprint.json', 'utf8' ) );
			grunt.log.write( `Blueprint URL: https://playground.wordpress.net/#${ encodeURI( JSON.stringify( blueprintJson ) ) }` );
		}
	);
};
