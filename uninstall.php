<?php
/**
 * Uninstall cleanup.
 *
 * @package EmpiricalResponsiveImages
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'empirical_responsive_images_settings' );
delete_option( 'empirical_responsive_images_observations' );
delete_option( 'empirical_responsive_images_version' );

$empirical_responsive_images_uploads    = wp_get_upload_dir();
$empirical_responsive_images_target_dir = trailingslashit( $empirical_responsive_images_uploads['basedir'] ) . 'empirical-responsive-images';
$empirical_responsive_images_real_base  = realpath( $empirical_responsive_images_uploads['basedir'] );
$empirical_responsive_images_real_dir   = realpath( $empirical_responsive_images_target_dir );

if (
	is_string( $empirical_responsive_images_real_base )
	&& is_string( $empirical_responsive_images_real_dir )
	&& 0 === strpos( $empirical_responsive_images_real_dir, trailingslashit( $empirical_responsive_images_real_base ) )
) {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	WP_Filesystem();

	global $wp_filesystem;

	if ( is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'delete' ) ) {
		$wp_filesystem->delete( $empirical_responsive_images_real_dir, true );
	}
}
