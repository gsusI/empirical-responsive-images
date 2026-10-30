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
define( 'EMPIRICAL_RESPONSIVE_IMAGES_DIR', dirname( __DIR__ ) . '/' );

$GLOBALS['eri_test_options']                     = array();
$GLOBALS['eri_test_current_user_can_manage']     = false;
$GLOBALS['eri_test_current_user_can_edit_posts'] = false;
$GLOBALS['eri_test_enqueued_scripts']            = array();
$GLOBALS['eri_test_localized_scripts']           = array();
$GLOBALS['eri_test_registered_scripts']          = array();
$GLOBALS['eri_test_inline_scripts']              = array();
$GLOBALS['eri_test_registered_routes']           = array();
$GLOBALS['eri_test_actions']                     = array();
$GLOBALS['eri_test_filters']                     = array();
$GLOBALS['eri_test_cron_events']                 = array();
$GLOBALS['eri_test_is_ssl']                      = false;
$GLOBALS['eri_test_attachment_metadata']         = array();
$GLOBALS['eri_test_attachment_files']            = array();
$GLOBALS['eri_test_image_attachments']           = array();
$GLOBALS['eri_test_added_image_sizes']           = array();
$GLOBALS['eri_test_supported_formats']           = array();
$GLOBALS['eri_test_siteground_purge_urls']       = array();
$GLOBALS['eri_test_siteground_purge_all']        = 0;
$GLOBALS['eri_test_update_option_failures']      = array();
$GLOBALS['eri_test_getimagesize_calls']          = array();
$GLOBALS['eri_test_posts']                       = array();
$GLOBALS['eri_test_uploads']                     = array(
	'basedir' => __DIR__ . '/runtime/uploads',
	'baseurl' => 'https://example.test/uploads',
);

function eri_test_reset_state(): void {
	$GLOBALS['eri_test_options']                     = array();
	$GLOBALS['eri_test_current_user_can_manage']     = false;
	$GLOBALS['eri_test_current_user_can_edit_posts'] = false;
	$GLOBALS['eri_test_enqueued_scripts']            = array();
	$GLOBALS['eri_test_localized_scripts']           = array();
	$GLOBALS['eri_test_registered_scripts']          = array();
	$GLOBALS['eri_test_inline_scripts']              = array();
	$GLOBALS['eri_test_registered_routes']           = array();
	$GLOBALS['eri_test_actions']                     = array();
	$GLOBALS['eri_test_filters']                     = array();
	$GLOBALS['eri_test_cron_events']                 = array();
	$GLOBALS['eri_test_is_ssl']                      = false;
	$GLOBALS['eri_test_attachment_metadata']         = array();
	$GLOBALS['eri_test_attachment_files']            = array();
	$GLOBALS['eri_test_image_attachments']           = array();
	$GLOBALS['eri_test_added_image_sizes']           = array();
	$GLOBALS['eri_test_supported_formats']           = array();
	$GLOBALS['eri_test_siteground_purge_urls']       = array();
	$GLOBALS['eri_test_siteground_purge_all']        = 0;
	$GLOBALS['eri_test_update_option_failures']      = array();
	$GLOBALS['eri_test_getimagesize_calls']          = array();
	$GLOBALS['eri_test_posts']                       = array();
	$GLOBALS['eri_test_uploads']                     = array(
		'basedir' => __DIR__ . '/runtime/uploads',
		'baseurl' => 'https://example.test/uploads',
	);
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
	if ( ! empty( $GLOBALS['eri_test_update_option_failures'][ $name ] ) ) {
		--$GLOBALS['eri_test_update_option_failures'][ $name ];
		return false;
	}

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

function get_current_blog_id(): int {
	return 1;
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

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

function wp_generate_uuid4(): string {
	return sprintf( '00000000-0000-4000-8000-%012d', count( $GLOBALS['eri_test_options'] ) + 1 );
}

function wp_salt( string $scheme = 'auth' ): string {
	return 'test-salt-' . sanitize_key( $scheme );
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
	$GLOBALS['eri_test_getimagesize_calls'][ $path ] = ( $GLOBALS['eri_test_getimagesize_calls'][ $path ] ?? 0 ) + 1;

	return getimagesize( $path );
}

function wp_image_editor_supports( array $args ): bool {
	$mime_type = sanitize_text_field( $args['mime_type'] ?? '' );

	return ! empty( $GLOBALS['eri_test_supported_formats'][ $mime_type ] );
}

function wp_json_encode( $value ): string {
	$encoded = json_encode( $value );

	return is_string( $encoded ) ? $encoded : '';
}

function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['eri_test_filters'][ $hook_name ][ $priority ][] = array(
		'callback'      => $callback,
		'accepted_args' => $accepted_args,
	);

	return true;
}

function remove_filter( string $hook_name, callable $callback, int $priority = 10 ): bool {
	if ( empty( $GLOBALS['eri_test_filters'][ $hook_name ][ $priority ] ) ) {
		return false;
	}

	foreach ( $GLOBALS['eri_test_filters'][ $hook_name ][ $priority ] as $index => $registered ) {
		if ( $registered['callback'] === $callback ) {
			unset( $GLOBALS['eri_test_filters'][ $hook_name ][ $priority ][ $index ] );
			return true;
		}
	}

	return false;
}

function apply_filters( string $hook_name, $value, ...$args ) {
	if ( empty( $GLOBALS['eri_test_filters'][ $hook_name ] ) ) {
		return $value;
	}

	ksort( $GLOBALS['eri_test_filters'][ $hook_name ] );

	foreach ( $GLOBALS['eri_test_filters'][ $hook_name ] as $callbacks ) {
		foreach ( $callbacks as $registered ) {
			$arguments = array_slice( array_merge( array( $value ), $args ), 0, $registered['accepted_args'] );
			$value     = $registered['callback']( ...$arguments );
		}
	}

	return $value;
}

function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return add_filter( $hook_name, $callback, $priority, $accepted_args );
}

function do_action( string $hook_name, ...$args ): void {
	$GLOBALS['eri_test_actions'][] = array( $hook_name, $args );
	apply_filters( $hook_name, null, ...$args );
}

function current_user_can( string $capability ): bool {
	if ( 'manage_options' === $capability ) {
		return $GLOBALS['eri_test_current_user_can_manage'];
	}

	if ( 'edit_posts' === $capability ) {
		return $GLOBALS['eri_test_current_user_can_edit_posts'] || $GLOBALS['eri_test_current_user_can_manage'];
	}

	return false;
}

function is_admin(): bool {
	return false;
}

function is_ssl(): bool {
	return $GLOBALS['eri_test_is_ssl'];
}

function is_feed(): bool {
	return false;
}

function wp_register_script( string $handle, $src, array $dependencies = array(), $version = false, $args = array() ): bool {
	$GLOBALS['eri_test_registered_scripts'][ $handle ] = compact( 'src', 'dependencies', 'version', 'args' );

	return true;
}

function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
	$GLOBALS['eri_test_inline_scripts'][ $handle ][ $position ][] = $data;

	return true;
}

function wp_enqueue_script( string $handle, string $src = '', array $dependencies = array(), $version = false, $args = array() ): void {
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

function wp_verify_nonce( string $nonce, string $action = '-1' ) {
	return hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false;
}

function wp_attachment_is_image( int $attachment_id ): bool {
	return ! empty( $GLOBALS['eri_test_image_attachments'][ $attachment_id ] );
}

function wp_get_attachment_metadata( int $attachment_id ) {
	return $GLOBALS['eri_test_attachment_metadata'][ $attachment_id ] ?? false;
}

function wp_update_attachment_metadata( int $attachment_id, array $metadata ): bool {
	$GLOBALS['eri_test_attachment_metadata'][ $attachment_id ] = $metadata;

	return true;
}

function get_attached_file( int $attachment_id ) {
	return $GLOBALS['eri_test_attachment_files'][ $attachment_id ] ?? false;
}

function add_image_size( string $name, int $width = 0, int $height = 0, $crop = false ): void {
	$GLOBALS['eri_test_added_image_sizes'][ $name ] = compact( 'width', 'height', 'crop' );
}

function wp_next_scheduled( string $hook ) {
	$timestamps = array();

	foreach ( $GLOBALS['eri_test_cron_events'] as $event ) {
		if ( $hook === $event['hook'] ) {
			$timestamps[] = $event['timestamp'];
		}
	}

	return empty( $timestamps ) ? false : min( $timestamps );
}

function wp_schedule_single_event( int $timestamp, string $hook, array $args = array(), bool $wp_error = false ) {
	$GLOBALS['eri_test_cron_events'][] = compact( 'timestamp', 'hook', 'args' ) + array(
		'recurrence' => '',
	);

	return true;
}

function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array(), bool $wp_error = false ) {
	$GLOBALS['eri_test_cron_events'][] = compact( 'timestamp', 'recurrence', 'hook', 'args' );

	return true;
}

function wp_clear_scheduled_hook( string $hook, array $args = array(), bool $wp_error = false ) {
	$removed = 0;

	foreach ( $GLOBALS['eri_test_cron_events'] as $index => $event ) {
		if ( $hook === $event['hook'] ) {
			unset( $GLOBALS['eri_test_cron_events'][ $index ] );
			++$removed;
		}
	}

	$GLOBALS['eri_test_cron_events'] = array_values( $GLOBALS['eri_test_cron_events'] );

	return $removed;
}

function esc_attr( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

function register_rest_route( string $namespace, string $route, array $args = array(), bool $override = false ): bool {
	$GLOBALS['eri_test_registered_routes'][ $namespace . $route ] = compact( 'namespace', 'route', 'args', 'override' );

	return true;
}

function rest_ensure_response( $data ): WP_REST_Response {
	return $data instanceof WP_REST_Response ? $data : new WP_REST_Response( $data );
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function sg_cachepress_purge_cache( $url = false ): bool {
	$GLOBALS['eri_test_siteground_purge_urls'][] = $url;

	return true;
}

function get_posts( array $args ): array {
	$ids = array();

	foreach ( $GLOBALS['eri_test_posts'] as $post_id => $post ) {
		if ( ( $args['post_status'] ?? 'publish' ) === ( $post['status'] ?? '' ) && (string) ( $post['thumbnail_id'] ?? '' ) === (string) ( $args['meta_value'] ?? '' ) ) {
			$ids[] = $post_id;
		}
	}

	return array_slice( $ids, 0, (int) ( $args['posts_per_page'] ?? 5 ) );
}

function wp_get_post_parent_id( $post ): int {
	return (int) ( $GLOBALS['eri_test_posts'][ (int) $post ]['parent'] ?? 0 );
}

function get_post_type( $post ) {
	return $GLOBALS['eri_test_posts'][ (int) $post ]['type'] ?? false;
}

function get_post_status( $post ) {
	return $GLOBALS['eri_test_posts'][ (int) $post ]['status'] ?? false;
}

function is_post_type_viewable( $post_type ): bool {
	return ! in_array( $post_type, array( 'attachment', 'wp_block' ), true );
}

function get_permalink( $post ) {
	return 'https://example.test/?p=' . (int) $post;
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

class WP_Error {
	/**
	 * Error code.
	 *
	 * @var string
	 */
	private $code;

	/**
	 * Error data.
	 *
	 * @var mixed
	 */
	private $data;

	public function __construct( string $code = '', string $message = '', $data = null ) {
		$this->code = $code;
		$this->data = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data() {
		return $this->data;
	}

	public function get_error_message(): string {
		return $this->code;
	}
}

class WP_REST_Response {
	/**
	 * Response data.
	 *
	 * @var mixed
	 */
	private $data;

	public function __construct( $data = null ) {
		$this->data = $data;
	}

	public function get_data() {
		return $this->data;
	}
}

class WP_REST_Request {
	/**
	 * Raw body.
	 *
	 * @var string
	 */
	private $body = '';

	/**
	 * Request headers.
	 *
	 * @var array
	 */
	private $headers = array();

	/**
	 * JSON parameters.
	 *
	 * @var array
	 */
	private $json_params = array();

	public function set_body( string $body ): void {
		$this->body        = $body;
		$decoded           = json_decode( $body, true );
		$this->json_params = is_array( $decoded ) ? $decoded : array();
	}

	public function get_body(): string {
		return $this->body;
	}

	public function set_header( string $name, string $value ): void {
		$this->headers[ strtolower( $name ) ] = $value;
	}

	public function get_header( string $name ): string {
		return (string) ( $this->headers[ strtolower( $name ) ] ?? '' );
	}

	public function get_json_params(): array {
		return $this->json_params;
	}
}

class WP_REST_Server {
	public const READABLE  = 'GET';
	public const CREATABLE = 'POST';
}

require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-option-lock.php';
require_once dirname( __DIR__ ) . '/includes/class-alpha-compat.php';
require_once dirname( __DIR__ ) . '/includes/class-asset-manager.php';
require_once dirname( __DIR__ ) . '/includes/class-observation-store.php';
require_once dirname( __DIR__ ) . '/includes/class-visitor-gap-target.php';
require_once dirname( __DIR__ ) . '/includes/class-visitor-gap-store.php';
require_once dirname( __DIR__ ) . '/includes/class-format-generator.php';
require_once dirname( __DIR__ ) . '/includes/class-image-sizes.php';
require_once dirname( __DIR__ ) . '/includes/class-renderer.php';
require_once dirname( __DIR__ ) . '/includes/class-cache-compat.php';
require_once dirname( __DIR__ ) . '/includes/class-regenerator.php';
require_once dirname( __DIR__ ) . '/includes/class-background-optimizer.php';
require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';
