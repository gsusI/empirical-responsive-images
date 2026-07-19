<?php
/**
 * Static-analysis declarations not supplied by WordPress core stubs.
 *
 * @package EmpiricalResponsiveImages
 */


namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/runtime/' );
	}

	if ( ! defined( 'EMPIRICAL_RESPONSIVE_IMAGES_URL' ) ) {
		define( 'EMPIRICAL_RESPONSIVE_IMAGES_URL', 'https://example.test/wp-content/plugins/empirical-responsive-images/' );
	}

	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {
			/**
			 * @param string $name Command name.
			 * @param mixed  $callable Command implementation.
			 * @return void
			 */
			public static function add_command( string $name, $callable ): void {}

			/**
			 * @param string $message Message.
			 * @return void
			 */
			public static function log( string $message ): void {}

			/**
			 * @param string $message Message.
			 * @return void
			 */
			public static function success( string $message ): void {}
		}
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param string $format Output format.
	 * @param array  $items  Rows.
	 * @param array  $fields Fields.
	 * @return void
	 */
	function format_items( string $format, array $items, array $fields ): void {}
}
