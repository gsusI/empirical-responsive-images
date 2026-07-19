<?php
/**
 * Lightweight WordPress function doubles for unit tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/runtime/wordpress/' );
define( 'WP_CONTENT_DIR', dirname( __DIR__ ) );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'EMPIRICAL_RESPONSIVE_IMAGES_URL', 'https://example.test/wp-content/plugins/empirical-responsive-images/' );
define( 'EMPIRICAL_RESPONSIVE_IMAGES_VERSION', 'test' );

$GLOBALS['eri_test_options'] = array();
$GLOBALS['eri_test_current_user_can_manage'] = false;
$GLOBALS['eri_test_enqueued_scripts']        = array();
$GLOBALS['eri_test_localized_scripts']       = array();
$GLOBALS['eri_test_registered_routes']       = array();
$GLOBALS['eri_test_actions']                 = array();
$GLOBALS['eri_test_siteground_purge_urls']   = array();
$GLOBALS['eri_test_siteground_purge_all']    = 0;
$GLOBALS['eri_test_uploads'] = array(
	'basedir' => __DIR__ . '/runtime/uploads',
	'baseurl' => 'https://example.test/uploads',
);

function eri_test_reset_state(): void {
	$GLOBALS['eri_test_options']                 = array();
	$GLOBALS['eri_test_current_user_can_manage'] = false;
	$GLOBALS['eri_test_enqueued_scripts']        = array();
	$GLOBALS['eri_test_localized_scripts']       = array();
	$GLOBALS['eri_test_registered_routes']       = array();
	$GLOBALS['eri_test_actions']                 = array();
	$GLOBALS['eri_test_siteground_purge_urls']   = array();
	$GLOBALS['eri_test_siteground_purge_all']    = 0;
}

function absint( $value ): int {
	return abs( (int) $value );
}

function get_option( string $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['eri_test_options'] ) ? $GLOBALS['eri_test_options'][ $name ] : $default;
}

function add_option( string $name, $value, string $deprecated = '', bool $autoload = true ): bool {
	if ( array_key_exists( $name, $GLOBALS['eri_test_options'] ) ) {
		return false;
	}

	$GLOBALS['eri_test_options'][ $name ] = $value;
	return true;
}

function update_option( string $name, $value, $autoload = null ): bool {
	$GLOBALS['eri_test_options'][ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	if ( ! array_key_exists( $name, $GLOBALS['eri_test_options'] ) ) {
		return false;
	}

	unset( $GLOBALS['eri_test_options'][ $name ] );
	return true;
}

function wp_parse_args( $args, array $defaults = array() ): array {
	return array_merge( $defaults, is_array( $args ) ? $args : array() );
}

function esc_url_raw( string $url ): string {
	return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
}

function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}

function sanitize_text_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_key( string $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?: '';
}

function sanitize_file_name( string $value ): string {
	return preg_replace( '/[^A-Za-z0-9._\-]/', '-', $value ) ?: '';
}

function sanitize_html_class( string $value ): string {
	return preg_replace( '/[^A-Za-z0-9_\-]/', '', $value ) ?: '';
}

function home_url( string $path = '' ): string {
	return 'https://example.test' . ( '/' === $path ? '/' : '/' . ltrim( $path, '/' ) );
}

function site_url( string $path = '' ): string {
	return home_url( $path );
}

function content_url( string $path = '' ): string {
	return 'https://example.test/wp-content/' . ltrim( $path, '/' );
}

function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/';
}

function wp_generate_uuid4(): string {
	return sprintf( '00000000-0000-4000-8000-%012d', count( $GLOBALS['eri_test_options'] ) + 1 );
}

function wp_normalize_path( string $path ): string {
	return str_replace( '\\', '/', $path );
}

function get_bloginfo( string $show = '' ): string {
	return 'charset' === $show ? 'UTF-8' : '';
}

function wp_make_link_relative( string $url ): string {
	$path = parse_url( $url, PHP_URL_PATH );
	return is_string( $path ) ? $path : $url;
}

function wp_get_upload_dir(): array {
	return $GLOBALS['eri_test_uploads'];
}

function wp_check_filetype( string $path ): array {
	$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	$types     = array(
		'avif' => 'image/avif',
		'gif'  => 'image/gif',
		'jpeg' => 'image/jpeg',
		'jpg'  => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
	);

	return array( 'type' => $types[ $extension ] ?? '' );
}

function wp_getimagesize( string $path ) {
	return getimagesize( $path );
}

function apply_filters( string $hook_name, $value ) {
	return $value;
}

function do_action( string $hook_name, ...$args ): void {
	$GLOBALS['eri_test_actions'][] = array( $hook_name, $args );
}

function current_user_can( string $capability ): bool {
	return 'manage_options' === $capability && $GLOBALS['eri_test_current_user_can_manage'];
}

function is_admin(): bool {
	return false;
}

function is_feed(): bool {
	return false;
}

function wp_enqueue_script( string $handle, string $src, array $dependencies = array(), $version = false, $args = array() ): void {
	$GLOBALS['eri_test_enqueued_scripts'][ $handle ] = compact( 'src', 'dependencies', 'version', 'args' );
}

function wp_localize_script( string $handle, string $object_name, array $data ): bool {
	$GLOBALS['eri_test_localized_scripts'][ $handle ] = compact( 'object_name', 'data' );

	return true;
}

function rest_url( string $path = '' ): string {
	return 'https://example.test/wp-json/' . ltrim( $path, '/' );
}

function wp_create_nonce( string $action = '-1' ): string {
	return 'test-nonce-' . sanitize_key( $action );
}

function esc_attr( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

function register_rest_route( string $namespace, string $route, array $args = array(), bool $override = false ): bool {
	$GLOBALS['eri_test_registered_routes'][ $namespace . $route ] = compact( 'namespace', 'route', 'args', 'override' );

	return true;
}

function sg_cachepress_purge_cache( $url = false ): bool {
	$GLOBALS['eri_test_siteground_purge_urls'][] = $url;

	return true;
}

function sg_cachepress_purge_everything(): bool {
	++$GLOBALS['eri_test_siteground_purge_all'];

	return true;
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function set_url_scheme( string $url, ?string $scheme = null ): string {
	return preg_replace( '#^https?://#', ( $scheme ?: 'https' ) . '://', $url ) ?: $url;
}

class WP_Error {}

class WP_REST_Server {
	public const READABLE  = 'GET';
	public const CREATABLE = 'POST';
}

require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-alpha-compat.php';
require_once dirname( __DIR__ ) . '/includes/class-asset-manager.php';
require_once dirname( __DIR__ ) . '/includes/class-observation-store.php';
require_once dirname( __DIR__ ) . '/includes/class-format-generator.php';
require_once dirname( __DIR__ ) . '/includes/class-image-sizes.php';
require_once dirname( __DIR__ ) . '/includes/class-cache-compat.php';
require_once dirname( __DIR__ ) . '/includes/class-regenerator.php';
require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';
