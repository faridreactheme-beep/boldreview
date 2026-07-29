<?php
/**
 * Plugin Name: BoldReview
 * Description: A modern, review system for posts, pages and products with a beautiful admin dashboard.
 * Plugin URI:  https://themewant.com/
 * Author:      Themewant
 * Author URI:  http://themewant.com/
 * Version:     1.0.2
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: boldreview
 * Domain Path: /languages
 *
 * @package BoldReview
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants.
 */
define( 'BDRVW_VERSION', '1.0.2' );
define( 'BDRVW_FILE', __FILE__ );
define( 'BDRVW_DIR', plugin_dir_path( __FILE__ ) );
define( 'BDRVW_URL', plugin_dir_url( __FILE__ ) );
define( 'BDRVW_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Composer autoload (preferred) with PSR-4 fallback.
 */
if ( file_exists( BDRVW_DIR . 'vendor/autoload.php' ) ) {
	require_once BDRVW_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( $class ) {
			$prefix   = 'BoldReview\\Plugin\\';
			$base_dir = BDRVW_DIR . 'src/';

			$len = strlen( $prefix );
			if ( strncmp( $prefix, $class, $len ) !== 0 ) {
				return;
			}

			$relative = substr( $class, $len );
			$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);

	require_once BDRVW_DIR . 'src/helper-functions.php';
}

/**
 * Activation / deactivation hooks.
 */
register_activation_hook(
	__FILE__,
	static function () {
		( new \BoldReview\Plugin\Core\Bdrvw_Installer() )->activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		( new \BoldReview\Plugin\Core\Bdrvw_Installer() )->deactivate();
	}
);

/**
 * Bootstrap.
 */
add_action(
	'plugins_loaded',
	static function () {
		\BoldReview\Plugin\Bdrvw_Plugin::instance()->boot();
	}
);