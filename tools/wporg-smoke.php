<?php
/**
 * Clean-install WordPress.org review smoke.
 *
 * This file is development tooling and is excluded from the release ZIP.
 *
 * @package EmpiricalResponsiveImages
 */

use EmpiricalResponsiveImages\Image_Sizes;
use EmpiricalResponsiveImages\Observation_Store;
use EmpiricalResponsiveImages\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$fail = static function ( string $message ): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI-only failure output.
	fwrite( STDERR, 'Smoke failed: ' . $message . PHP_EOL );
	exit( 1 );
};

$route_path = '/empirical-responsive-images/v1/observations';
$routes     = rest_get_server()->get_routes();
$endpoints  = $routes[ $route_path ] ?? array();
$endpoint   = null;

foreach ( $endpoints as $candidate ) {
	if ( ! empty( $candidate['methods']['POST'] ) ) {
		$endpoint = $candidate;
		break;
	}
}

if ( ! is_array( $endpoint ) ) {
	$fail( 'observation POST route is not registered' );
}

$permission_callback = $endpoint['permission_callback'] ?? null;

if ( ! is_array( $permission_callback ) || 'can_manage' !== ( $permission_callback[1] ?? '' ) ) {
	$fail( 'observation route does not use the capability callback' );
}

$new_request = static function () use ( $route_path ): WP_REST_Request {
	$request = new WP_REST_Request( 'POST', $route_path );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body(
		wp_json_encode(
			array(
				'page_url' => home_url( '/review-smoke/' ),
				'viewport' => array(
					'width'  => 1280,
					'height' => 720,
					'dpr'    => 1,
				),
				'images'   => array(
					array(
						'rendered_width'  => 320,
						'rendered_height' => 180,
						'target_width'    => 320,
						'target_height'   => 180,
						'dpr'             => 1,
					),
				),
			)
		)
	);

	return $request;
};

$before_observations = get_option( Observation_Store::OPTION_NAME, array() );

wp_set_current_user( 0 );
$anonymous_response = rest_do_request( $new_request() );

if ( $anonymous_response->get_status() < 400 ) {
	$fail( 'anonymous observation write was accepted' );
}

if ( get_option( Observation_Store::OPTION_NAME, array() ) !== $before_observations ) {
	$fail( 'anonymous observation write changed stored data' );
}

$subscriber_id = wp_insert_user(
	array(
		'user_login' => 'empirical-responsive-images-review-subscriber',
		'user_pass'  => wp_generate_password( 24, true, true ),
		'user_email' => 'subscriber@example.test',
		'role'       => 'subscriber',
	)
);

if ( is_wp_error( $subscriber_id ) ) {
	$fail( 'could not create subscriber test user' );
}

wp_set_current_user( (int) $subscriber_id );
$subscriber_response = rest_do_request( $new_request() );

if ( $subscriber_response->get_status() < 400 ) {
	$fail( 'subscriber observation write was accepted' );
}

if ( get_option( Observation_Store::OPTION_NAME, array() ) !== $before_observations ) {
	$fail( 'subscriber observation write changed stored data' );
}

$administrator = get_user_by( 'login', 'admin' );

if ( ! $administrator instanceof WP_User ) {
	$fail( 'clean-install administrator was not found' );
}

wp_set_current_user( $administrator->ID );
$administrator_response = rest_do_request( $new_request() );
$administrator_data     = $administrator_response->get_data();

if ( 200 !== $administrator_response->get_status() || ! is_array( $administrator_data ) || 1 !== (int) ( $administrator_data['accepted'] ?? 0 ) ) {
	$fail( 'administrator observation write was not accepted' );
}

if ( get_option( Observation_Store::OPTION_NAME, array() ) === $before_observations ) {
	$fail( 'administrator observation write did not change stored data' );
}

$observer_handle = 'empirical-responsive-images-observer';
$image_sizes     = new Image_Sizes( new Settings(), new Observation_Store() );

wp_dequeue_script( $observer_handle );
wp_set_current_user( 0 );
$image_sizes->enqueue_observer();

if ( wp_script_is( $observer_handle, 'enqueued' ) ) {
	$fail( 'observer script was enqueued for an anonymous visitor' );
}

wp_set_current_user( $administrator->ID );
$image_sizes->enqueue_observer();

if ( ! wp_script_is( $observer_handle, 'enqueued' ) ) {
	$fail( 'observer script was not enqueued for an administrator' );
}

$localized_data = wp_scripts()->get_data( $observer_handle, 'data' );

if ( ! is_string( $localized_data ) || false === strpos( $localized_data, '"nonce"' ) ) {
	$fail( 'observer script did not receive a REST nonce' );
}

$script_tag = $image_sizes->filter_observer_script_tag(
	// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Fixture passed to the script-tag filter.
	'<script src="observer.js"></script>',
	$observer_handle,
	'observer.js'
);

if ( false === strpos( $script_tag, 'data-empirical-responsive-images-nonce=' ) ) {
	$fail( 'observer fallback attributes did not include a REST nonce' );
}

wp_set_current_user( 0 );

WP_CLI::success( 'Authorization, observer nonce, and stored-state smoke passed.' );
