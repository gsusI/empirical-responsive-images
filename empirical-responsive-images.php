<?php
/**
 * Plugin Name: Empirical Responsive Images
 * Plugin URI: https://jesusiniesta.es/about
 * Description: Measures rendered image slots, registers matching WordPress image sizes, regenerates thumbnails, and serves modern image formats.
 * Version: 0.1.7
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Jesús Iniesta
 * Author URI: https://jesusiniesta.es/about
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: empirical-responsive-images
 * Domain Path: /languages
 *
 * @package EmpiricalResponsiveImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EMPIRICAL_RESPONSIVE_IMAGES_VERSION', '0.1.7' );
define( 'EMPIRICAL_RESPONSIVE_IMAGES_FILE', __FILE__ );
define( 'EMPIRICAL_RESPONSIVE_IMAGES_DIR', plugin_dir_path( __FILE__ ) );
define( 'EMPIRICAL_RESPONSIVE_IMAGES_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'EmpiricalResponsiveImages\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$file_slug      = strtolower( str_replace( '_', '-', $relative_class ) );
		$file_slug      = str_replace( '\\', '/', $file_slug );
		$file_path      = EMPIRICAL_RESPONSIVE_IMAGES_DIR . 'includes/class-' . $file_slug . '.php';

		if ( file_exists( $file_path ) ) {
			require $file_path;
		}
	}
);

register_activation_hook( __FILE__, array( 'EmpiricalResponsiveImages\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EmpiricalResponsiveImages\\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		EmpiricalResponsiveImages\Plugin::instance()->init();
	}
);
