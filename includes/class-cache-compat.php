<?php
/**
 * Cache plugin compatibility helpers.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses common cache plugin protocols without hard dependencies.
 */
final class Cache_Compat {
	/**
	 * Define known constants respected by major cache/optimization plugins.
	 *
	 * @return void
	 */
	public static function define_no_cache_constants(): void {
		$constants = array(
			'DONOTCACHEPAGE'        => true,
			'DONOTCACHEOBJECT'      => true,
			'DONOTCACHEDB'          => true,
			'DONOTMINIFY'           => true,
			'LITESPEED_NO_OPTM'     => true,
			'LITESPEED_NO_PAGEOPTM' => true,
			'LITESPEED_NO_LAZY'     => true,
		);

		foreach ( $constants as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}
	}

	/**
	 * Purge page cache for a URL where APIs are present.
	 *
	 * @param string $url URL.
	 * @return void
	 */
	public static function purge_url( string $url ): void {
		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			return;
		}

		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( array( $url ) );
		}

		if ( function_exists( 'w3tc_flush_url' ) ) {
			w3tc_flush_url( $url );
		}

		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( 0 );
		}

		do_action( 'litespeed_purge_url', $url );
		do_action( 'sg_cachepress_purge_cache' );
		do_action( 'breeze_clear_all_cache' );
		do_action( 'empirical_responsive_images_purge_url', $url );
	}

	/**
	 * Purge all known page caches once, used on activation.
	 *
	 * @return void
	 */
	public static function purge_all(): void {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			\autoptimizeCache::clearall();
		}

		do_action( 'litespeed_purge_all' );
		do_action( 'sg_cachepress_purge_cache' );
		do_action( 'breeze_clear_all_cache' );
		do_action( 'empirical_responsive_images_purge_all' );
	}
}
