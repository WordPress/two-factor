/* jshint node:true */
module.exports = function( grunt ) {
	'use strict';

	grunt.initConfig( {

		pkg: grunt.file.readJSON( 'package.json' ),

		// JavaScript linting with JSHint.
		jshint: {
			options: {
				jshintrc: '.jshintrc'
			},
			all: [
				'Gruntfile.js',
				'js/*.js',
				'!js/*.min.js'
			]
		},

		// VVV (Varying Vagrant Vagrants) Paths
		vvv: {
			'plugin': '/srv/www/wordpress-develop/src/wp-content/plugins/<%= pkg.name %>',
			'coverage': '/srv/www/default/coverage/<%= pkg.name %>'
		},

		// Shell actions
		shell: {
			options: {
				stdout: true,
				stderr: true
			},
			readme: {
				command: 'cd ./dev-lib && ./generate-markdown-readme' // Genrate the readme.md
			},
			phpunit: {
				command: 'vagrant ssh -c "cd <%= vvv.plugin %> && phpunit"' 
			},
			phpunit_c: {
				command: 'vagrant ssh -c "cd <%= vvv.plugin %> && phpunit --coverage-html <%= vvv.coverage %>"'
			}
		}

	} );

	// Load tasks
	grunt.loadNpmTasks( 'grunt-contrib-jshint' );
	grunt.loadNpmTasks( 'grunt-shell' );

	// Register tasks
	grunt.registerTask( 'default', [
		'jshint'
	] );

	grunt.registerTask( 'readme', [
		'shell:readme'
	] );

	grunt.registerTask( 'phpunit', [
		'shell:phpunit'
	] );

	grunt.registerTask( 'phpunit_c', [
		'shell:phpunit_c'
	] );

};
