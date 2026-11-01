const fs = require( 'fs' );
const ignoreParse = require( 'parse-gitignore' ); // Keep at v0 since v1 is a breaking change.

module.exports = function( grunt ) {
	'use strict';

	require( 'load-grunt-tasks' )( grunt );

	const distignore = ignoreParse( '.distignore', [], {
		invert: true
	} );

	grunt.initConfig( {
		pkg: grunt.file.readJSON( 'package.json' ),

		dist_dir: 'dist',

		clean: {
			build: [ '<%= dist_dir %>' ]
		},

		copy: {
			dist: {
				files: [
					{
						src: [ '**' ].concat( distignore ),
						dest: '<%= dist_dir %>',
						expand: true
					},
					{
						cwd: 'node_modules/',
						src: 'qrcode-generator/dist/qrcode.js',
						dest: '<%= dist_dir %>/includes',
						expand: true,
						rename: ( dest ) => dest + '/qrcode-generator/qrcode.js'
					}
				]
			}
		}
	} );

	grunt.registerTask( 'build', [ 'clean', 'copy' ] );

	grunt.registerTask( 'blueprint-url', function() {
		const blueprintJson = JSON.parse(
			fs.readFileSync(
				'.wordpress-org/blueprints/blueprint.json',
				'utf8'
			)
		);
		grunt.log.write(
			`Blueprint URL: https://playground.wordpress.net/#${ encodeURI( JSON.stringify( blueprintJson ) ) }`
		);
	} );
};
