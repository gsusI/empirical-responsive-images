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

$uploads    = wp_get_upload_dir();
$target_dir = trailingslashit( $uploads['basedir'] ) . 'empirical-responsive-images';
$real_base  = realpath( $uploads['basedir'] );
$real_dir   = realpath( $target_dir );

if ( is_string( $real_base ) && is_string( $real_dir ) && 0 === strpos( $real_dir, trailingslashit( $real_base ) ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $real_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $file_info ) {
		if ( $file_info->isDir() ) {
			rmdir( $file_info->getPathname() );
		} else {
			unlink( $file_info->getPathname() );
		}
	}

	rmdir( $real_dir );
}
