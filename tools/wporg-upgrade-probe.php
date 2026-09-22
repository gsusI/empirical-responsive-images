<?php
/**
 * Cache-plugin lifecycle probe for the disposable review site only.
 *
 * @package EmpiricalResponsiveImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model a cache API that exists before its boot dependencies are ready.
 *
 * @throws RuntimeException When the purge runs before dependencies are ready.
 * @return void
 */
function rocket_clean_domain(): void {
	if ( ! did_action( 'wp_loaded' ) ) {
		throw new RuntimeException( 'Upgrade purged cache before dependencies loaded.' );
	}

	update_option( 'eri_review_upgrade_purge', get_option( 'empirical_responsive_images_version' ), false );
}
