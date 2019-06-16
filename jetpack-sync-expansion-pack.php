<?php
/**
 * Plugin Name:     Jetpack Sync Expansion Pack
 * Plugin URI:      https://github.com/alleyinteractive/jetpack-sync-expansion-pack
 * Description:     Helpful tools for working with Jetpack Sync
 * Author:          Matthew Boynes
 * Author URI:      https://www.alley.co/
 * Text Domain:     jpsep
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         JPSEP
 */

namespace JPSEP;

add_action(
	'after_setup_theme',
	function() {
		if ( class_exists( '\Jetpack_Search' ) ) {
			require_once __DIR__ . '/inc/sync-status.php';
			require_once __DIR__ . '/inc/dispatchers.php';
			require_once __DIR__ . '/inc/es.php';

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				require_once __DIR__ . '/inc/class-cli.php';
				\WP_CLI::add_command( 'jetpack sync-ep', __NAMESPACE__ . '\CLI' );
			}
		}
	}
);

add_action(
	'admin_enqueue_scripts',
	function() {
		wp_register_script( 'jpsep-tools', plugins_url( 'js/tools.js', __FILE__ ), [ 'jquery' ], '1.0', true );
	}
);
