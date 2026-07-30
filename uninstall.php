<?php
/**
 * Uninstall cleanup.
 *
 * @package EmpiricalResponsiveImages
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'empirical_responsive_images_optimizer_batch' );
wp_clear_scheduled_hook( 'empirical_responsive_images_optimizer_watchdog' );
delete_option( 'empirical_responsive_images_background_optimizer_lock' );

$empirical_responsive_images_settings = get_option( 'empirical_responsive_images_settings', array() );

if ( ! is_array( $empirical_responsive_images_settings ) || empty( $empirical_responsive_images_settings['delete_data_on_uninstall'] ) ) {
	return;
}

delete_option( 'empirical_responsive_images_settings' );
delete_option( 'empirical_responsive_images_observations' );
delete_option( 'empirical_responsive_images_version' );
delete_option( 'empirical_responsive_images_observations_lock' );
delete_option( 'empirical_responsive_images_visitor_gap_hints' );
delete_option( 'empirical_responsive_images_visitor_gap_hints_lock' );

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
