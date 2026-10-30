<?php
/**
 * Clean-install WordPress.org review smoke.
 *
 * This file is development tooling and is excluded from the release ZIP.
 *
 * @package EmpiricalResponsiveImages
 */

use EmpiricalResponsiveImages\Asset_Manager;
use EmpiricalResponsiveImages\Background_Optimizer;
use EmpiricalResponsiveImages\Format_Generator;
use EmpiricalResponsiveImages\Image_Sizes;
use EmpiricalResponsiveImages\Observation_Store;
use EmpiricalResponsiveImages\Plugin;
use EmpiricalResponsiveImages\Renderer;
use EmpiricalResponsiveImages\Settings;
use EmpiricalResponsiveImages\Visitor_Gap_Store;
use EmpiricalResponsiveImages\Visitor_Gap_Target;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$fail = static function ( string $message ): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI-only failure output.
	fwrite( STDERR, 'Smoke failed: ' . $message . PHP_EOL );
	exit( 1 );
};

// Multiline block output can acquire duplicate responsive attributes in core filters.
$duplicate_renderer = new Renderer( new Settings(), new Observation_Store(), new Asset_Manager( new Settings() ) );
$duplicate_markup   = '<img class="eri-review-responsive-fixture" src="https://example.invalid/image.webp" srcset="https://example.invalid/672.webp 672w" sizes="92vw" width="672" height="672" SRCSET="https://example.invalid/768.webp 768w" SIZES="100vw" width="768" height="768">';
$normalized_markup  = $duplicate_renderer->filter_final_html( $duplicate_markup, true );
$normalized_image   = new WP_HTML_Tag_Processor( $normalized_markup );
$normalized_image->next_tag( 'IMG' );
foreach ( array(
	'srcset' => 'https://example.invalid/672.webp 672w',
	'sizes'  => '92vw',
	'width'  => '672',
	'height' => '672',
) as $attribute => $expected ) {
	if ( $expected !== $normalized_image->get_attribute( $attribute ) || 1 !== preg_match_all( '/\s' . $attribute . '=/i', $normalized_markup ) ) {
		$fail( 'responsive attribute normalization did not preserve the browser-first value: ' . $attribute );
	}
}
$repeated_markup = $duplicate_renderer->filter_final_html( $normalized_markup, true );
$repeated_image  = new WP_HTML_Tag_Processor( $repeated_markup );
$repeated_image->next_tag( 'IMG' );
foreach ( array( 'srcset', 'sizes', 'width', 'height' ) as $attribute ) {
	if ( $normalized_image->get_attribute( $attribute ) !== $repeated_image->get_attribute( $attribute ) || 1 !== preg_match_all( '/\s' . $attribute . '=/i', $repeated_markup ) ) {
		$fail( 'repeated rendering changed responsive attribute semantics: ' . $attribute );
	}
}

$preload_image   = '<img class="eri-review-preload-fixture" fetchpriority="high" src="https://example.invalid/image.webp" srcset="https://example.invalid/672.webp 672w, https://example.invalid/768.webp 768w" sizes="92vw">';
$stale_preload   = '<link rel="preload" as="image" href="https://example.invalid/image.webp" imagesrcset="https://example.invalid/768.webp 768w" imagesizes="92vw">';
$updated_preload = $duplicate_renderer->filter_image_preloads( '<!DOCTYPE html><html><head>' . $stale_preload . '</head><body>' . $preload_image . '</body></html>' );
$preload_tag     = new WP_HTML_Tag_Processor( $updated_preload );
$preload_tag->next_tag( 'LINK' );
if ( false === strpos( (string) $preload_tag->get_attribute( 'imagesrcset' ), '672w' ) ) {
	$fail( 'stale responsive preload did not include the learned candidate' );
}
foreach ( array( ' media="(min-width: 1000px)"', ' type="image/avif"', ' crossorigin="anonymous"' ) as $excluded_attribute ) {
	$excluded_preload = str_replace( '<link ', '<link' . $excluded_attribute . ' ', $stale_preload );
	if ( $excluded_preload . $preload_image !== $duplicate_renderer->filter_image_preloads( $excluded_preload . $preload_image ) ) {
		$fail( 'preload synchronization changed an excluded art-direction/format/credentials hint' );
	}
}
$conflicting_image = str_replace( 'sizes="92vw"', 'sizes="50vw"', $preload_image );
foreach ( array( '<picture>' . $preload_image . '</picture>', $preload_image . $conflicting_image, str_replace( 'sizes="92vw"', 'sizes="100vw"', $preload_image ) ) as $excluded_image ) {
	if ( $stale_preload . $excluded_image !== $duplicate_renderer->filter_image_preloads( $stale_preload . $excluded_image ) ) {
		$fail( 'preload synchronization changed an ambiguous or mismatched layout hint' );
	}
}

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

$gap_route_path = '/empirical-responsive-images/v1/visitor-gap-hints';
$gap_endpoints  = $routes[ $gap_route_path ] ?? array();
$gap_endpoint   = null;

foreach ( $gap_endpoints as $candidate ) {
	if ( ! empty( $candidate['methods']['POST'] ) ) {
		$gap_endpoint = $candidate;
		break;
	}
}

if ( ! is_array( $gap_endpoint ) || '__return_true' !== ( $gap_endpoint['permission_callback'] ?? null ) ) {
	$fail( 'visitor gap route is not explicitly public' );
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

$settings_option                                 = ( new Settings() )->all();
$settings_option['visitor_gap_learning_enabled'] = true;
update_option( Settings::OPTION_NAME, $settings_option, false );

$gap_store = new Visitor_Gap_Store();
update_option( Visitor_Gap_Store::OPTION_NAME, $gap_store->empty_data(), false );
wp_clear_scheduled_hook( Background_Optimizer::BATCH_HOOK );

if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagejpeg' ) ) {
	$fail( 'GD JPEG support is required for the automatic optimization smoke' );
}

$uploads = wp_upload_dir();

if ( ! empty( $uploads['error'] ) || ! wp_mkdir_p( $uploads['path'] ) ) {
	$fail( 'could not prepare the uploads directory for the automatic optimization smoke' );
}

$attachment_filename = wp_unique_filename( $uploads['path'], 'empirical-responsive-images-automatic-smoke.jpg' );
$attachment_file     = trailingslashit( $uploads['path'] ) . $attachment_filename;
$source_image        = imagecreatetruecolor( 1600, 1000 );

if ( false === $source_image ) {
	$fail( 'GD could not allocate the automatic optimization smoke image' );
}

$image_background = imagecolorallocate( $source_image, 38, 74, 112 );
imagefilledrectangle( $source_image, 0, 0, 1599, 999, $image_background );

if ( ! imagejpeg( $source_image, $attachment_file, 90 ) ) {
	$fail( 'GD could not write the automatic optimization smoke JPEG' );
}

// Background work only purges the published pages known to show an image.
$attachment_parent_id = wp_insert_post(
	array(
		'post_title'  => 'Empirical Responsive Images automatic smoke parent',
		'post_status' => 'publish',
	)
);

if ( is_wp_error( $attachment_parent_id ) || $attachment_parent_id < 1 ) {
	$fail( 'could not create the automatic optimization smoke parent post' );
}

$attachment_id = wp_insert_attachment(
	array(
		'post_mime_type' => 'image/jpeg',
		'post_title'     => 'Empirical Responsive Images automatic smoke',
		'post_status'    => 'inherit',
	),
	$attachment_file,
	$attachment_parent_id
);

if ( is_wp_error( $attachment_id ) || $attachment_id < 1 ) {
	$fail( 'could not create the automatic optimization smoke attachment' );
}

require_once ABSPATH . 'wp-admin/includes/image.php';

$attachment_metadata = wp_generate_attachment_metadata( $attachment_id, $attachment_file );

if ( ! is_array( $attachment_metadata ) || ! wp_update_attachment_metadata( $attachment_id, $attachment_metadata ) ) {
	$fail( 'could not generate core metadata for the automatic optimization smoke attachment' );
}

$attachment_target = Visitor_Gap_Target::token_for_attachment( $attachment_id );

if ( '' === $attachment_target ) {
	$fail( 'could not create a signed target for the automatic optimization smoke attachment' );
}

$attachment_stem = pathinfo( $attachment_filename, PATHINFO_FILENAME );
$snapshot_files  = static function () use ( $attachment_file, $attachment_stem, $uploads ): array {
	$matches  = glob( dirname( $attachment_file ) . '/' . $attachment_stem . '*' );
	$matches  = is_array( $matches ) ? $matches : array();
	$snapshot = array();
	$base     = trailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) );
	$sidecars = $base . 'empirical-responsive-images/attachments';

	if ( is_dir( $sidecars ) ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $sidecars, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && ! $file->isLink() ) {
				$matches[] = $file->getPathname();
			}
		}
	}

	foreach ( $matches as $path ) {
		if ( ! is_file( $path ) ) {
			continue;
		}

		$digest = hash_file( 'sha256', $path );

		if ( false !== $digest ) {
			$normalized       = wp_normalize_path( $path );
			$key              = 0 === strpos( $normalized, $base )
				? ltrim( substr( $normalized, strlen( $base ) ), '/' )
				: basename( $path );
			$snapshot[ $key ] = $digest;
		}
	}

	ksort( $snapshot );

	return $snapshot;
};

$gap_purge_count = 0;
$count_gap_purge = static function () use ( &$gap_purge_count ): void {
	++$gap_purge_count;
};

add_action( 'empirical_responsive_images_purge_url', $count_gap_purge );
add_action( 'empirical_responsive_images_purge_all', $count_gap_purge );

$new_gap_request = static function ( int $reported_width = 1200, int $native_width = 0 ) use ( $gap_route_path, $attachment_target ): WP_REST_Request {
	$request = new WP_REST_Request( 'POST', $gap_route_path );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_header( 'X-Empirical-Responsive-Images-Token', Visitor_Gap_Store::report_token() );
	$request->set_header( 'X-Empirical-Responsive-Images-Bucket-Version', (string) Visitor_Gap_Store::BUCKET_PROTOCOL_VERSION );
	$gap = array(
		'target'   => $attachment_target,
		'width'    => $reported_width,
		'viewport' => 1440,
		'slot'     => 600,
	);

	if ( $native_width > 0 ) {
		$gap['native_width'] = $native_width;
	}

	$request->set_body(
		wp_json_encode(
			array(
				'gaps' => array( $gap ),
			)
		)
	);

	return $request;
};

$before_public_observations = get_option( Observation_Store::OPTION_NAME, array() );
$before_public_metadata     = wp_get_attachment_metadata( $attachment_id );
$before_public_files        = $snapshot_files();
wp_set_current_user( 0 );

for ( $confirmation = 1; $confirmation <= 3; ++$confirmation ) {
	$gap_response = rest_do_request( $new_gap_request() );
	$gap_data     = $gap_response->get_data();

	if ( 200 !== $gap_response->get_status() || ! is_array( $gap_data ) || 1 !== (int) ( $gap_data['accepted'] ?? 0 ) ) {
		$fail( 'target-bound anonymous visitor gap evidence was not accepted' );
	}

	if ( $confirmation < 3 && ! empty( $gap_data['queued'] ) ) {
		$fail( 'visitor gap job queued before reaching its confirmation threshold' );
	}

	if ( get_option( Observation_Store::OPTION_NAME, array() ) !== $before_public_observations ) {
		$fail( 'anonymous visitor gap evidence changed canonical observations' );
	}

	if ( wp_get_attachment_metadata( $attachment_id ) !== $before_public_metadata ) {
		$fail( 'anonymous visitor gap evidence changed attachment metadata' );
	}

	if ( $snapshot_files() !== $before_public_files ) {
		$fail( 'anonymous visitor gap evidence changed attachment files' );
	}

	if ( $gap_purge_count > 0 ) {
		$fail( 'anonymous visitor gap evidence purged cache during its REST request' );
	}

	if ( $confirmation < 3 && false !== wp_next_scheduled( Background_Optimizer::BATCH_HOOK ) ) {
		$fail( 'background optimization was scheduled before a gap qualified' );
	}

	if ( $confirmation < 3 ) {
		$stored_gap_data = get_option( Visitor_Gap_Store::OPTION_NAME, array() );

		foreach ( array_keys( is_array( $stored_gap_data['jobs'] ?? null ) ? $stored_gap_data['jobs'] : array() ) as $job_id ) {
			$stored_gap_data['jobs'][ $job_id ]['last_window'] = (int) ( $stored_gap_data['jobs'][ $job_id ]['last_window'] ?? 0 ) - 1;
		}

		update_option( Visitor_Gap_Store::OPTION_NAME, $stored_gap_data, false );
	}
}

$queued_gap_data = get_option( Visitor_Gap_Store::OPTION_NAME, array() );
$queued_jobs     = is_array( $queued_gap_data['jobs'] ?? null ) ? $queued_gap_data['jobs'] : array();
$queued_job      = reset( $queued_jobs );

if ( 1 !== count( $queued_jobs ) || ! is_array( $queued_job ) || 'queued' !== (string) ( $queued_job['status'] ?? '' ) || 1194 !== absint( $queued_job['width'] ?? 0 ) ) {
	$fail( 'third confirmation did not create one canonical 1194-pixel background job' );
}

if ( false === wp_next_scheduled( Background_Optimizer::BATCH_HOOK ) ) {
	$fail( 'qualified visitor gap did not schedule the background optimizer' );
}

do_action( Background_Optimizer::BATCH_HOOK );

$optimized_metadata = wp_get_attachment_metadata( $attachment_id );
$optimized_sizes    = is_array( $optimized_metadata ) && is_array( $optimized_metadata['sizes'] ?? null ) ? $optimized_metadata['sizes'] : array();
$empirical_sizes    = array();

foreach ( $optimized_sizes as $size_name => $size_data ) {
	if ( 0 === strpos( (string) $size_name, 'empirical-responsive-images-' ) ) {
		$empirical_sizes[ $size_name ] = $size_data;
	}
}

$expected_size_name = Image_Sizes::name_for_width( 1194 );

if ( array( $expected_size_name ) !== array_keys( $empirical_sizes ) || 1194 !== absint( $empirical_sizes[ $expected_size_name ]['width'] ?? 0 ) ) {
	$fail( 'background optimizer did not create only the target-specific 1194-pixel attachment size' );
}

$after_optimization_files = $snapshot_files();
$changed_existing_files   = array();

foreach ( $before_public_files as $filename => $digest ) {
	if ( ! isset( $after_optimization_files[ $filename ] ) || $after_optimization_files[ $filename ] !== $digest ) {
		$changed_existing_files[] = $filename;
	}
}

if ( ! empty( $changed_existing_files ) ) {
	$fail( 'target-specific optimization rewrote existing attachment files' );
}

$metadata_directory = dirname( (string) ( $optimized_metadata['file'] ?? '' ) );
$metadata_directory = '.' === $metadata_directory ? '' : trailingslashit( $metadata_directory );
$expected_new_files = array(
	wp_normalize_path( $metadata_directory . (string) ( $empirical_sizes[ $expected_size_name ]['file'] ?? '' ) ),
);
$relative_source    = wp_normalize_path( $metadata_directory . (string) ( $empirical_sizes[ $expected_size_name ]['file'] ?? '' ) );
$format_files       = is_array( $optimized_metadata[ Format_Generator::METADATA_KEY ]['files'][ $relative_source ] ?? null )
	? $optimized_metadata[ Format_Generator::METADATA_KEY ]['files'][ $relative_source ]
	: array();
$required_formats   = ( new Format_Generator( new Settings() ) )->get_required_frontend_formats();

foreach ( $required_formats as $format ) {
	if ( empty( $format_files[ $format ] ) || ! is_string( $format_files[ $format ] ) ) {
		$fail( 'target-specific optimization omitted a supported required format' );
	}

	$expected_new_files[] = ltrim( wp_normalize_path( $format_files[ $format ] ), '/' );
}

$actual_new_files   = array_keys( array_diff_key( $after_optimization_files, $before_public_files ) );
$expected_new_files = array_values( array_filter( array_unique( $expected_new_files ) ) );
sort( $actual_new_files );
sort( $expected_new_files );

if ( $expected_new_files !== $actual_new_files ) {
	$fail( 'target-specific optimization created files beyond the requested width and formats' );
}

$completed_gap_data = get_option( Visitor_Gap_Store::OPTION_NAME, array() );
$completed_targets  = is_array( $completed_gap_data['targets'] ?? null ) ? $completed_gap_data['targets'] : array();
$completed_target   = reset( $completed_targets );

if ( ! empty( $completed_gap_data['jobs'] ) || 1 !== count( $completed_targets ) || ! is_array( $completed_target ) || array( 1194 ) !== array_values( $completed_target['widths'] ?? array() ) ) {
	$fail( 'completed automatic optimization did not move into bounded target history' );
}

if ( 1 !== $gap_purge_count || ! empty( $completed_gap_data['cache']['dirty'] ) ) {
	$fail( 'completed automatic optimization did not purge stale page markup exactly once' );
}

$automatic_slots = ( new Observation_Store() )->get_attachment_slot_widths( $attachment_id );

if ( array( '1536' => 608 ) !== $automatic_slots ) {
	$fail( 'background optimizer did not persist bounded attachment layout evidence' );
}

$attachment_html = wp_get_attachment_image(
	$attachment_id,
	'large',
	false,
	array(
		'loading' => 'lazy',
	)
);

if (
	! is_string( $attachment_html )
	|| false === strpos( $attachment_html, 'empirical-responsive-image--attachment' )
	|| false === strpos( $attachment_html, 'data-empirical-responsive-images-attachment-id="' . $attachment_id . '"' )
	|| false === strpos( $attachment_html, 'data-empirical-responsive-images-target-kind="attachment"' )
	|| false === strpos( $attachment_html, 'data-empirical-responsive-images-sizes="empirical"' )
	|| false === strpos( $attachment_html, 'sizes="auto, ' )
) {
	$fail( 'direct attachment rendering did not apply automatic target and empirical sizes markup' );
}

$renderer_settings = new Settings();
$renderer_assets   = new Asset_Manager( $renderer_settings );
$renderer          = new Renderer(
	$renderer_settings,
	new Observation_Store( $renderer_assets ),
	$renderer_assets,
	new Format_Generator( $renderer_settings )
);
$attachment_url    = wp_get_attachment_url( $attachment_id );
$author_srcset     = is_string( $attachment_url ) ? $attachment_url . ' 1600w' : '';
$author_asset      = '<img class="theme-logo-image" src="' . esc_url( (string) $attachment_url ) . '" srcset="' . esc_attr( $author_srcset ) . '" sizes="50vw" alt="">';
$marked_asset      = $renderer->filter_content_image_html( $author_asset, 'the_content', 0 );
$rendered_asset    = $renderer->filter_content_fragment( $marked_asset );

if (
	'' === $author_srcset
	|| false === strpos( $rendered_asset, 'srcset="' . esc_attr( $author_srcset ) . '"' )
	|| false !== strpos( $rendered_asset, 'data-empirical-responsive-images-target=' )
) {
	$fail( 'author-owned asset srcset was changed or left in an unusable learning loop' );
}

if ( ! empty( $required_formats ) ) {
	$missing_format    = reset( $required_formats );
	$backfill_metadata = wp_get_attachment_metadata( $attachment_id );
	$backfill_sizes    = is_array( $backfill_metadata ) && is_array( $backfill_metadata['sizes'] ?? null ) ? $backfill_metadata['sizes'] : array();
	$backfill_size     = null;

	foreach ( $backfill_sizes as $size_data ) {
		if ( ! is_array( $size_data ) || 1024 !== absint( $size_data['width'] ?? 0 ) ) {
			continue;
		}

		$backfill_size = $size_data;
		break;
	}

	if ( ! is_array( $backfill_size ) || ! is_string( $missing_format ) ) {
		$fail( 'could not select an existing native width for format-only backfill' );
	}

	$format_metadata      = is_array( $backfill_metadata[ Format_Generator::METADATA_KEY ]['files'] ?? null )
		? $backfill_metadata[ Format_Generator::METADATA_KEY ]['files']
		: array();
	$removed_format_files = 0;

	foreach ( $format_metadata as $relative_path => &$source_formats ) {
		if ( ! is_array( $source_formats ) || empty( $source_formats[ $missing_format ] ) || ! is_string( $source_formats[ $missing_format ] ) ) {
			continue;
		}

		$sidecar_path = trailingslashit( $uploads['basedir'] ) . ltrim( $source_formats[ $missing_format ], '/' );

		if ( is_file( $sidecar_path ) ) {
			wp_delete_file( $sidecar_path );
		}

		unset( $source_formats[ $missing_format ] );
		++$removed_format_files;
	}
	unset( $source_formats );

	if ( $removed_format_files < 1 ) {
		$fail( 'automatic upload processing did not create the format selected for backfill' );
	}

	$backfill_metadata[ Format_Generator::METADATA_KEY ]['files'] = $format_metadata;
	wp_update_attachment_metadata( $attachment_id, $backfill_metadata );

	$before_backfill_files = $snapshot_files();
	$gap_purge_count       = 0;

	for ( $confirmation = 1; $confirmation <= 3; ++$confirmation ) {
			$gap_response = rest_do_request( $new_gap_request( 1024, 1024 ) );
		$gap_data         = $gap_response->get_data();

		if ( 200 !== $gap_response->get_status() || ! is_array( $gap_data ) || 1 !== (int) ( $gap_data['accepted'] ?? 0 ) ) {
			$fail( 'missing modern format on a covered native width was not accepted automatically' );
		}

		if ( $confirmation < 3 ) {
			$stored_gap_data = get_option( Visitor_Gap_Store::OPTION_NAME, array() );

			foreach ( array_keys( is_array( $stored_gap_data['jobs'] ?? null ) ? $stored_gap_data['jobs'] : array() ) as $job_id ) {
				$stored_gap_data['jobs'][ $job_id ]['last_window'] = (int) ( $stored_gap_data['jobs'][ $job_id ]['last_window'] ?? 0 ) - 1;
			}

			update_option( Visitor_Gap_Store::OPTION_NAME, $stored_gap_data, false );
		}
	}

	$stored_gap_data = get_option( Visitor_Gap_Store::OPTION_NAME, array() );

	$stored_gap_data['cache']['last_purge_at'] = 0;
	update_option( Visitor_Gap_Store::OPTION_NAME, $stored_gap_data, false );

	do_action( Background_Optimizer::BATCH_HOOK );

	$after_backfill_metadata = wp_get_attachment_metadata( $attachment_id );
	$backfill_directory      = dirname( (string) ( $after_backfill_metadata['file'] ?? '' ) );
	$backfill_directory      = '.' === $backfill_directory ? '' : trailingslashit( $backfill_directory );
	$backfill_relative       = wp_normalize_path( $backfill_directory . (string) ( $backfill_size['file'] ?? '' ) );
	$backfill_sidecar        = $after_backfill_metadata[ Format_Generator::METADATA_KEY ]['files'][ $backfill_relative ][ $missing_format ] ?? '';
	$backfill_sidecar_path   = is_string( $backfill_sidecar )
		? trailingslashit( $uploads['basedir'] ) . ltrim( $backfill_sidecar, '/' )
		: '';

	if ( '' === $backfill_sidecar_path || ! is_readable( $backfill_sidecar_path ) ) {
		$fail( 'background optimizer did not restore the missing format for an existing native width' );
	}

	$after_backfill_files = $snapshot_files();
	$backfill_new_files   = array_keys( array_diff_key( $after_backfill_files, $before_backfill_files ) );
	sort( $backfill_new_files );

	if ( array( ltrim( wp_normalize_path( (string) $backfill_sidecar ), '/' ) ) !== $backfill_new_files ) {
		$fail( 'format-only backfill created files beyond the missing sidecar' );
	}

	foreach ( $before_backfill_files as $filename => $digest ) {
		if ( ! isset( $after_backfill_files[ $filename ] ) || $after_backfill_files[ $filename ] !== $digest ) {
			$fail( 'format-only backfill rewrote an existing attachment file' );
		}
	}

	$completed_gap_data = get_option( Visitor_Gap_Store::OPTION_NAME, array() );

	if ( ! empty( $completed_gap_data['jobs'] ) || ! empty( $completed_gap_data['cache']['dirty'] ) || 1 !== $gap_purge_count ) {
		$fail( 'format-only automatic backfill did not complete and refresh cache' );
	}
}

$observer_handle     = 'empirical-responsive-images-observer';
$gap_observer_handle = 'empirical-responsive-images-visitor-gap-observer';
$image_sizes         = new Image_Sizes( new Settings(), new Observation_Store() );

wp_dequeue_script( $observer_handle );
wp_dequeue_script( $gap_observer_handle );
wp_set_current_user( 0 );
$image_sizes->enqueue_observer();

if ( wp_script_is( $observer_handle, 'enqueued' ) ) {
	$fail( 'observer script was enqueued for an anonymous visitor' );
}

if ( ! wp_script_is( $gap_observer_handle, 'enqueued' ) ) {
	$fail( 'opted-in visitor gap detector was not enqueued for an anonymous visitor' );
}

wp_dequeue_script( $gap_observer_handle );
wp_set_current_user( $administrator->ID );
$image_sizes->enqueue_observer();

if ( ! wp_script_is( $observer_handle, 'enqueued' ) ) {
	$fail( 'observer script was not enqueued for an administrator' );
}

if ( ! wp_script_is( $gap_observer_handle, 'enqueued' ) ) {
	$fail( 'administrator visit did not participate in automatic learning' );
}

$gap_script_tag = $image_sizes->filter_observer_script_tag(
	// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Fixture passed to the script-tag filter.
	'<script src="visitor-gap-observer.js"></script>',
	$gap_observer_handle,
	'visitor-gap-observer.js'
);

if ( false === strpos( $gap_script_tag, 'data-nowprocket="1"' ) ) {
	$fail( 'visitor gap observer was not excluded from WP Rocket delay' );
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

if ( false === strpos( $script_tag, 'data-nowprocket="1"' ) ) {
	$fail( 'diagnostic observer was not excluded from WP Rocket delay' );
}

// Exercise the critical-width protocol through REST and the real background worker.
$critical_request                       = $new_gap_request( 672 );
$critical_params                        = $critical_request->get_json_params();
$critical_params['gaps'][0]['critical'] = true;
$critical_request->set_body( wp_json_encode( $critical_params ) );
wp_set_current_user( $administrator->ID );
$critical_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$critical_response = rest_do_request( $critical_request );
$critical_result   = $critical_response->get_data();
if ( 200 !== $critical_response->get_status() || 1 !== (int) ( $critical_result['queued'] ?? 0 ) ) {
	$fail( 'critical-width REST report did not queue the requested work' );
}
do_action( Background_Optimizer::BATCH_HOOK );
$critical_metadata = wp_get_attachment_metadata( $attachment_id );
$critical_size     = $critical_metadata['sizes'][ Image_Sizes::name_for_width( 672 ) ] ?? array();
$critical_srcset   = wp_get_attachment_image_srcset( $attachment_id, 'full' );
if ( 672 !== (int) ( $critical_size['width'] ?? 0 ) || ! is_string( $critical_srcset ) || false === strpos( $critical_srcset, ' 672w' ) ) {
	$fail( 'critical-width background job did not produce a selectable 672px image' );
}
echo "Critical-width REST, background generation and responsive delivery passed.\n";

$gap_purge_count = 0;
wp_set_current_user( $administrator->ID );
$regeneration = Plugin::instance()->get_regenerator()->regenerate_batch( 1, 5 );

if ( empty( $regeneration['done'] ) || 1 !== $gap_purge_count ) {
	$fail( 'completed administrator regeneration did not purge stale page markup' );
}

$final_metadata = wp_get_attachment_metadata( $attachment_id );
$sidecar_files  = is_array( $final_metadata ) && is_array( $final_metadata[ Format_Generator::METADATA_KEY ]['files'] ?? null )
	? $final_metadata[ Format_Generator::METADATA_KEY ]['files']
	: array();
$owned_sidecars = array();

foreach ( $sidecar_files as $source_formats ) {
	if ( ! is_array( $source_formats ) ) {
		continue;
	}

	foreach ( $source_formats as $sidecar_relative ) {
		if ( is_string( $sidecar_relative ) && Format_Generator::is_plugin_sidecar_path( $sidecar_relative ) ) {
			$owned_sidecars[] = trailingslashit( $uploads['basedir'] ) . $sidecar_relative;
		}
	}
}

wp_clear_scheduled_hook( Background_Optimizer::BATCH_HOOK );
wp_delete_attachment( $attachment_id, true );
wp_delete_post( $attachment_parent_id, true );

if ( array() !== ( new Observation_Store() )->get_attachment_slot_widths( $attachment_id ) ) {
	$fail( 'deleting an attachment left visitor-learned layout data behind' );
}

foreach ( array_unique( $owned_sidecars ) as $owned_sidecar ) {
	if ( is_file( $owned_sidecar ) ) {
		$fail( 'deleting an attachment left plugin-owned sidecars on disk' );
	}
}

$remaining_attachment_files = glob( dirname( $attachment_file ) . '/' . $attachment_stem . '*' );

foreach ( is_array( $remaining_attachment_files ) ? $remaining_attachment_files : array() as $remaining_attachment_file ) {
	if ( is_file( $remaining_attachment_file ) ) {
		wp_delete_file( $remaining_attachment_file );
	}
}

wp_set_current_user( 0 );

WP_CLI::success( 'Authorization, target-bound automatic optimization, all-visitor learning, regeneration cache refresh, and stored-state smoke passed.' );
