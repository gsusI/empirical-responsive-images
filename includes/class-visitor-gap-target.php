<?php
/**
 * Signed visitor gap targets.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues and verifies bounded target handles for anonymous gap reports.
 */
final class Visitor_Gap_Target {
	public const MAX_AUTOMATIC_WIDTH = 5120;

	private const TOKEN_VERSION      = '1';
	private const MAX_ENCODED_LENGTH = 1024;
	private const MIN_COVERAGE_NUM   = 49;
	private const MIN_COVERAGE_DEN   = 50;
	private const MAX_COVERAGE_NUM   = 6;
	private const MAX_COVERAGE_DEN   = 5;
	private const MAX_SOURCE_PIXELS  = 24000000;
	private const MAX_SOURCE_BYTES   = 20971520;

	/**
	 * Request-local attachment token cache.
	 *
	 * @var array
	 */
	private static $attachment_tokens = array();

	/**
	 * Request-local asset token cache.
	 *
	 * @var array
	 */
	private static $asset_tokens = array();

	/**
	 * Request-local verified attachment dimension cache.
	 *
	 * @var array
	 */
	private static $attachment_dimensions = array();

	/**
	 * Create a signed target for one image attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function token_for_attachment( int $attachment_id ): string {
		$attachment_id = absint( $attachment_id );

		if ( $attachment_id < 1 ) {
			return '';
		}

		if ( isset( self::$attachment_tokens[ $attachment_id ] ) ) {
			return self::$attachment_tokens[ $attachment_id ];
		}

		$dimensions = self::attachment_source_dimensions( $attachment_id );

		if ( ! wp_attachment_is_image( $attachment_id ) || ! self::dimensions_are_safe( $dimensions ) ) {
			self::$attachment_tokens[ $attachment_id ] = '';
			return '';
		}

		$token                                     = self::sign_payload( 'attachment:' . $attachment_id );
		self::$attachment_tokens[ $attachment_id ] = $token;

		return $token;
	}

	/**
	 * Create a signed target for one manageable local asset.
	 *
	 * @param string        $url           Asset URL.
	 * @param Asset_Manager $asset_manager Asset manager.
	 * @return string
	 */
	public static function token_for_asset( string $url, Asset_Manager $asset_manager ): string {
		$normalized_url = $asset_manager->normalize_url( $url );

		if ( '' === $normalized_url ) {
			return '';
		}

		if ( isset( self::$asset_tokens[ $normalized_url ] ) ) {
			return self::$asset_tokens[ $normalized_url ];
		}

		$canonical_url = $normalized_url;

		if ( ! self::dimensions_are_safe( $asset_manager->source_dimensions_for_url( $canonical_url ) ) ) {
			self::$asset_tokens[ $normalized_url ] = '';
			return '';
		}

		$token                                 = self::sign_payload( 'asset:' . $normalized_url );
		self::$asset_tokens[ $normalized_url ] = $token;

		return $token;
	}

	/**
	 * Resolve a signed target against current local WordPress state.
	 *
	 * @param string        $token         Signed target.
	 * @param Asset_Manager $asset_manager Asset manager.
	 * @return array
	 */
	public static function resolve( string $token, Asset_Manager $asset_manager ): array {
		$payload = self::verify_token( $token );

		if ( '' === $payload ) {
			return array();
		}

		if ( 0 === strpos( $payload, 'attachment:' ) ) {
			return self::resolve_attachment( substr( $payload, strlen( 'attachment:' ) ) );
		}

		if ( 0 === strpos( $payload, 'asset:' ) ) {
			return self::resolve_asset( substr( $payload, strlen( 'asset:' ) ), $asset_manager );
		}

		return array();
	}

	/**
	 * Check whether current files already cover a requested physical width.
	 *
	 * Coverage accepts candidates from 2% below through 20% above target.
	 *
	 * @param array         $target        Resolved target.
	 * @param int           $target_width  Requested physical width.
	 * @param Asset_Manager $asset_manager Asset manager.
	 * @return bool
	 */
	public static function is_width_covered( array $target, int $target_width, Asset_Manager $asset_manager ): bool {
		return self::covering_width( $target, $target_width, $asset_manager ) > 0;
	}

	/**
	 * Find the closest current native candidate inside the coverage band.
	 *
	 * @param array         $target         Resolved target.
	 * @param int           $target_width   Requested physical width.
	 * @param Asset_Manager $asset_manager  Asset manager.
	 * @param int           $maximum_width  Maximum usable candidate width.
	 * @return int Covering native width, or zero.
	 */
	public static function covering_width( array $target, int $target_width, Asset_Manager $asset_manager, int $maximum_width = PHP_INT_MAX ): int {
		$target_width = absint( $target_width );
		$source_width = absint( $target['source_width'] ?? 0 );

		if ( $target_width < 1 || $source_width < 1 ) {
			return 0;
		}

		if ( 'attachment' === (string) ( $target['type'] ?? '' ) ) {
			$attachment_id = absint( $target['attachment_id'] ?? 0 );
			$metadata      = $attachment_id > 0 ? wp_get_attachment_metadata( $attachment_id ) : false;
			$widths        = array();

			if ( is_array( $metadata ) ) {
				foreach ( self::attachment_sources( $metadata ) as $source ) {
					if ( ! empty( $source['current'] ) && absint( $source['width'] ?? 0 ) <= $maximum_width ) {
						$widths[] = absint( $source['width'] ?? 0 );
					}
				}
			}
		} elseif ( 'asset' === (string) ( $target['type'] ?? '' ) && ! empty( $target['url'] ) && is_string( $target['url'] ) ) {
			$widths = array_filter(
				$asset_manager->get_existing_asset_widths( $target['url'] ),
				static function ( int $width ) use ( $maximum_width ): bool {
					return $width <= $maximum_width;
				}
			);
		} else {
			return 0;
		}

		$candidates = array_values(
			array_filter(
				array_unique( array_map( 'absint', $widths ) ),
				static function ( int $width ) use ( $target_width ): bool {
					return self::width_covers_target( $width, $target_width );
				}
			)
		);

		usort(
			$candidates,
			static function ( int $a, int $b ) use ( $target_width ): int {
				$distance = abs( $a - $target_width ) <=> abs( $b - $target_width );

				return 0 !== $distance ? $distance : $a <=> $b;
			}
		);

		return absint( $candidates[0] ?? 0 );
	}

	/**
	 * Check whether an exact same-format candidate exists for a target width.
	 *
	 * @param array         $target        Resolved target.
	 * @param int           $target_width  Requested physical width.
	 * @param Asset_Manager $asset_manager Asset manager.
	 * @return bool
	 */
	public static function has_exact_width( array $target, int $target_width, Asset_Manager $asset_manager ): bool {
		$target_width = absint( $target_width );

		if ( $target_width < 1 ) {
			return false;
		}

		if ( 'attachment' === (string) ( $target['type'] ?? '' ) ) {
			$attachment_id = absint( $target['attachment_id'] ?? 0 );
			$metadata      = $attachment_id > 0 ? wp_get_attachment_metadata( $attachment_id ) : false;

			if ( ! is_array( $metadata ) ) {
				return false;
			}

			foreach ( self::attachment_sources( $metadata ) as $source ) {
				if ( absint( $source['width'] ?? 0 ) === $target_width && ! empty( $source['current'] ) ) {
					return true;
				}
			}

			return false;
		}

		if ( 'asset' !== (string) ( $target['type'] ?? '' ) || empty( $target['url'] ) || ! is_string( $target['url'] ) ) {
			return false;
		}

		return in_array( $target_width, $asset_manager->get_existing_asset_widths( $target['url'] ), true );
	}

	/**
	 * Check native width and every applicable alternate-format width.
	 *
	 * @param array         $target           Resolved target.
	 * @param int           $target_width     Requested physical width.
	 * @param Asset_Manager $asset_manager    Asset manager.
	 * @param array         $required_formats Globally supported enabled formats.
	 * @return bool
	 */
	public static function is_optimization_covered( array $target, int $target_width, Asset_Manager $asset_manager, array $required_formats ): bool {
		if ( ! self::is_width_covered( $target, $target_width, $asset_manager ) ) {
			return false;
		}

		$required_formats = array_values(
			array_intersect(
				array( 'webp', 'avif' ),
				array_unique( array_map( 'sanitize_key', $required_formats ) )
			)
		);

		if ( empty( $required_formats ) ) {
			return true;
		}

		if ( 'attachment' === (string) ( $target['type'] ?? '' ) ) {
			return self::attachment_formats_cover( $target, $target_width, $required_formats );
		}

		if ( 'asset' !== (string) ( $target['type'] ?? '' ) || empty( $target['url'] ) || ! is_string( $target['url'] ) ) {
			return false;
		}

		$applicable_formats = $asset_manager->get_applicable_formats_for_url( $target['url'], $required_formats );

		if ( empty( $applicable_formats ) ) {
			return true;
		}

		$widths  = $asset_manager->get_existing_asset_widths( $target['url'] );
		$sources = $asset_manager->get_existing_asset_sources( $target['url'], $widths, array() );

		foreach ( $applicable_formats as $format ) {
			$format_widths = self::parse_srcset_widths( (string) ( $sources['formats'][ $format ] ?? '' ) );

			if ( ! self::width_list_covers( $format_widths, $target_width ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Keep only formats applicable to one resolved source.
	 *
	 * @param array         $target        Resolved target.
	 * @param Asset_Manager $asset_manager Asset manager.
	 * @param array         $formats       Requested formats.
	 * @return array
	 */
	public static function get_applicable_formats( array $target, Asset_Manager $asset_manager, array $formats ): array {
		$formats = array_values(
			array_intersect(
				array( 'webp', 'avif' ),
				array_unique( array_map( 'sanitize_key', $formats ) )
			)
		);

		if ( empty( $formats ) ) {
			return array();
		}

		if ( 'asset' === (string) ( $target['type'] ?? '' ) && ! empty( $target['url'] ) && is_string( $target['url'] ) ) {
			return $asset_manager->get_applicable_formats_for_url( $target['url'], $formats );
		}

		if ( 'attachment' !== (string) ( $target['type'] ?? '' ) ) {
			return array();
		}

		$file = get_attached_file( absint( $target['attachment_id'] ?? 0 ) );

		if ( ! is_string( $file ) || '' === $file || ! is_readable( $file ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$formats,
				static function ( string $format ) use ( $file ): bool {
					return Alpha_Compat::can_use_format_for_source( $file, $format );
				}
			)
		);
	}

	/**
	 * Get durable plugin-managed native widths for one target.
	 *
	 * @param array         $target        Resolved target.
	 * @param Asset_Manager $asset_manager Asset manager.
	 * @return array
	 */
	public static function get_managed_widths( array $target, Asset_Manager $asset_manager ): array {
		if ( 'asset' === (string) ( $target['type'] ?? '' ) && ! empty( $target['url'] ) && is_string( $target['url'] ) ) {
			$source_width = absint( $target['source_width'] ?? 0 );
			$widths       = array_filter(
				$asset_manager->get_existing_asset_widths( $target['url'] ),
				static function ( int $width ) use ( $source_width ): bool {
					return $width > 0 && $width !== $source_width;
				}
			);

			return array_values( array_unique( array_map( 'absint', $widths ) ) );
		}

		if ( 'attachment' !== (string) ( $target['type'] ?? '' ) ) {
			return array();
		}

		$attachment_id = absint( $target['attachment_id'] ?? 0 );
		$metadata      = $attachment_id > 0 ? wp_get_attachment_metadata( $attachment_id ) : false;

		if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return array();
		}

		$source_by_relative = array();

		foreach ( self::attachment_sources( $metadata ) as $source ) {
			$source_by_relative[ (string) ( $source['relative'] ?? '' ) ] = $source;
		}

		$directory = dirname( (string) ( $metadata['file'] ?? '' ) );
		$directory = '.' === $directory ? '' : trailingslashit( $directory );
		$widths    = array();

		foreach ( $metadata['sizes'] as $name => $size ) {
			if ( 0 !== strpos( (string) $name, 'empirical-responsive-images-' ) || ! is_array( $size ) || empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
				continue;
			}

			$relative = wp_normalize_path( $directory . $size['file'] );
			$source   = $source_by_relative[ $relative ] ?? array();

			if ( ! empty( $source ) && ! empty( $source['current'] ) ) {
				$widths[] = absint( $source['width'] ?? 0 );
			}
		}

		$widths = array_values( array_filter( array_unique( $widths ) ) );
		sort( $widths );

		return $widths;
	}

	/**
	 * Get the maximum automatic output width attainable for a target.
	 *
	 * @param array    $target   Resolved target.
	 * @param Settings $settings Settings.
	 * @return int
	 */
	public static function maximum_width( array $target, Settings $settings ): int {
		$source_width = absint( $target['source_width'] ?? 0 );
		$maximum      = min(
			self::MAX_AUTOMATIC_WIDTH,
			$settings->get_int( 'max_registered_width' ),
			$source_width
		);

		if ( 'attachment' === (string) ( $target['type'] ?? '' ) ) {
			$core_srcset_limit = (int) apply_filters(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Uses the WordPress core responsive-image filter.
				'max_srcset_image_width',
				2048,
				array(
					$source_width,
					absint( $target['source_height'] ?? 0 ),
				)
			);

			if ( $core_srcset_limit > 0 ) {
				$maximum = min( $maximum, $core_srcset_limit );
			}
		}

		return max( 0, $maximum );
	}

	/**
	 * Sign one internal target payload.
	 *
	 * @param string $payload Payload.
	 * @return string
	 */
	private static function sign_payload( string $payload ): string {
		$encoded   = self::base64url_encode( $payload );
		$protected = self::TOKEN_VERSION . '.' . $encoded;
		$signature = hash_hmac( 'sha256', $protected . '|' . self::site_context(), wp_salt( 'nonce' ) );

		return $protected . '.' . $signature;
	}

	/**
	 * Verify token signature and decode payload.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function verify_token( string $token ): string {
		if ( strlen( $token ) > self::MAX_ENCODED_LENGTH ) {
			return '';
		}

		$parts = explode( '.', $token );

		if ( 3 !== count( $parts ) || self::TOKEN_VERSION !== $parts[0] || ! preg_match( '/^[A-Za-z0-9_-]+$/', $parts[1] ) || ! preg_match( '/^[a-f0-9]{64}$/', $parts[2] ) ) {
			return '';
		}

		$protected = $parts[0] . '.' . $parts[1];
		$expected  = hash_hmac( 'sha256', $protected . '|' . self::site_context(), wp_salt( 'nonce' ) );

		if ( ! hash_equals( $expected, $parts[2] ) ) {
			return '';
		}

		return self::base64url_decode( $parts[1] );
	}

	/**
	 * Bind signed handles to the current WordPress site.
	 *
	 * @return string
	 */
	private static function site_context(): string {
		$site_id = function_exists( 'get_current_blog_id' ) ? absint( get_current_blog_id() ) : 0;
		$home    = strtolower( untrailingslashit( home_url( '/' ) ) );

		return $site_id . '|' . $home;
	}

	/**
	 * Resolve attachment target.
	 *
	 * @param string $raw_id Attachment ID payload.
	 * @return array
	 */
	private static function resolve_attachment( string $raw_id ): array {
		if ( ! preg_match( '/^[1-9][0-9]*$/', $raw_id ) ) {
			return array();
		}

		$attachment_id = absint( $raw_id );

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return array();
		}

		$metadata   = wp_get_attachment_metadata( $attachment_id );
		$dimensions = self::attachment_source_dimensions( $attachment_id );

		if ( ! self::dimensions_are_safe( $dimensions ) ) {
			return array();
		}

		return array(
			'type'             => 'attachment',
			'key'              => 'attachment:' . $attachment_id,
			'attachment_id'    => $attachment_id,
			'url'              => '',
			'source_width'     => absint( $dimensions['width'] ?? 0 ),
			'source_height'    => absint( $dimensions['height'] ?? 0 ),
			'candidate_widths' => self::attachment_candidate_widths( $metadata ),
		);
	}

	/**
	 * Resolve local asset target.
	 *
	 * @param string        $normalized_url Normalized signed URL path.
	 * @param Asset_Manager $asset_manager  Asset manager.
	 * @return array
	 */
	private static function resolve_asset( string $normalized_url, Asset_Manager $asset_manager ): array {
		if ( '' === $normalized_url || $normalized_url !== $asset_manager->normalize_url( $normalized_url ) ) {
			return array();
		}

		$url        = $normalized_url;
		$key        = $asset_manager->key_for_url( $url );
		$dimensions = $asset_manager->source_dimensions_for_url( $url );

		if ( '' === $key || ! self::dimensions_are_safe( $dimensions ) ) {
			return array();
		}

		return array(
			'type'             => 'asset',
			'key'              => 'asset:' . $key,
			'attachment_id'    => 0,
			'url'              => esc_url_raw( $url ),
			'source_width'     => absint( $dimensions['width'] ?? 0 ),
			'source_height'    => absint( $dimensions['height'] ?? 0 ),
			'candidate_widths' => array( absint( $dimensions['width'] ?? 0 ) ),
		);
	}

	/**
	 * Get verified attachment source dimensions.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private static function attachment_source_dimensions( int $attachment_id ): array {
		$file = get_attached_file( $attachment_id );

		if ( ! is_string( $file ) || '' === $file || ! is_readable( $file ) ) {
			return array();
		}

		$type = wp_check_filetype( $file );

		if ( in_array( (string) ( $type['type'] ?? '' ), array( 'image/gif', 'image/webp', 'image/avif' ), true ) ) {
			return array();
		}

		$bytes = filesize( $file );
		$mtime = filemtime( $file );
		$key   = $attachment_id
			. '|'
			. wp_normalize_path( $file )
			. '|'
			. (string) ( false === $mtime ? 0 : $mtime )
			. '|'
			. (string) ( false === $bytes ? 0 : $bytes );

		if ( array_key_exists( $key, self::$attachment_dimensions ) ) {
			return self::$attachment_dimensions[ $key ];
		}

		$limit = (int) apply_filters( 'empirical_responsive_images_max_attachment_source_bytes', self::MAX_SOURCE_BYTES, $file );

		if ( false === $bytes || $limit < 1 || $bytes > $limit ) {
			return self::cache_attachment_dimensions( $key, array() );
		}

		$size = wp_getimagesize( $file );

		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			return self::cache_attachment_dimensions( $key, array() );
		}

		return self::cache_attachment_dimensions(
			$key,
			array(
				'width'  => absint( $size[0] ?? 0 ),
				'height' => absint( $size[1] ?? 0 ),
				'path'   => $file,
			)
		);
	}

	/**
	 * Store one bounded request-local attachment dimension lookup.
	 *
	 * @param string $key        Source fingerprint.
	 * @param array  $dimensions Verified dimensions or empty result.
	 * @return array
	 */
	private static function cache_attachment_dimensions( string $key, array $dimensions ): array {
		if ( count( self::$attachment_dimensions ) >= 128 ) {
			array_shift( self::$attachment_dimensions );
		}

		self::$attachment_dimensions[ $key ] = $dimensions;

		return $dimensions;
	}

	/**
	 * Check source dimensions before any image editor can decode the file.
	 *
	 * @param array $dimensions Width and height.
	 * @return bool
	 */
	private static function dimensions_are_safe( array $dimensions ): bool {
		$width  = absint( $dimensions['width'] ?? 0 );
		$height = absint( $dimensions['height'] ?? 0 );
		$path   = isset( $dimensions['path'] ) && is_string( $dimensions['path'] ) ? $dimensions['path'] : '';
		$limit  = (int) apply_filters( 'empirical_responsive_images_max_source_pixels', self::MAX_SOURCE_PIXELS, $path );

		return $width > 0 && $height > 0 && $limit > 0 && ( $width * $height ) <= $limit;
	}

	/**
	 * Get uncropped attachment source widths from metadata.
	 *
	 * @param mixed $metadata Attachment metadata.
	 * @return array
	 */
	private static function attachment_candidate_widths( $metadata ): array {
		if ( ! is_array( $metadata ) ) {
			return array();
		}

		$source_width  = absint( $metadata['width'] ?? 0 );
		$source_height = absint( $metadata['height'] ?? 0 );
		$widths        = array( $source_width );
		$sizes         = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();

		foreach ( $sizes as $size ) {
			if ( ! is_array( $size ) ) {
				continue;
			}

			$width  = absint( $size['width'] ?? 0 );
			$height = absint( $size['height'] ?? 0 );

			if ( ! self::preserves_aspect_ratio( $width, $height, $source_width, $source_height ) ) {
				continue;
			}

			$relative = wp_normalize_path( ( '.' === dirname( (string) ( $metadata['file'] ?? '' ) ) ? '' : trailingslashit( dirname( (string) $metadata['file'] ) ) ) . (string) ( $size['file'] ?? '' ) );
			$uploads  = wp_get_upload_dir();
			$path     = trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) . ltrim( $relative, '/' );

			$main_relative = wp_normalize_path( (string) ( $metadata['file'] ?? '' ) );
			$main_path     = trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) . ltrim( $main_relative, '/' );

			if ( self::is_current_file( $main_path, $path ) ) {
				$widths[] = $width;
			}
		}

		$widths = array_values( array_filter( array_unique( array_map( 'absint', $widths ) ) ) );
		sort( $widths );

		return $widths;
	}

	/**
	 * Check attachment sidecar files for every applicable required format.
	 *
	 * @param array $target           Resolved attachment target.
	 * @param int   $target_width     Requested width.
	 * @param array $required_formats Required formats.
	 * @return bool
	 */
	private static function attachment_formats_cover( array $target, int $target_width, array $required_formats ): bool {
		$attachment_id = absint( $target['attachment_id'] ?? 0 );
		$metadata      = $attachment_id > 0 ? wp_get_attachment_metadata( $attachment_id ) : false;

		if ( ! is_array( $metadata ) ) {
			return false;
		}

		$sources      = self::attachment_sources( $metadata );
		$format_files = isset( $metadata[ Format_Generator::METADATA_KEY ]['files'] ) && is_array( $metadata[ Format_Generator::METADATA_KEY ]['files'] )
			? $metadata[ Format_Generator::METADATA_KEY ]['files']
			: array();
		$uploads      = wp_get_upload_dir();
		$base         = trailingslashit( (string) ( $uploads['basedir'] ?? '' ) );

		foreach ( $required_formats as $format ) {
			$applicable = false;
			$widths     = array();

			foreach ( $sources as $source ) {
				$width = absint( $source['width'] ?? 0 );

				if ( ! self::width_list_covers( array( $width ), $target_width ) || empty( $source['current'] ) || ! Alpha_Compat::can_use_format_for_source( (string) $source['path'], $format ) ) {
					continue;
				}

				$applicable = true;
				$sidecar    = $format_files[ $source['relative'] ][ $format ] ?? '';

				$sidecar_path = is_string( $sidecar ) ? $base . ltrim( wp_normalize_path( $sidecar ), '/' ) : '';

				if ( '' === $sidecar || ! Format_Generator::is_plugin_sidecar_path( $sidecar ) || ! self::is_current_file( (string) $source['path'], $sidecar_path ) ) {
					continue;
				}

				$widths[] = $width;
			}

			if ( $applicable && ! self::width_list_covers( $widths, $target_width ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build eligible attachment source paths and widths from metadata.
	 *
	 * @param array $metadata Attachment metadata.
	 * @return array
	 */
	private static function attachment_sources( array $metadata ): array {
		if ( empty( $metadata['file'] ) || ! is_string( $metadata['file'] ) ) {
			return array();
		}

		$uploads         = wp_get_upload_dir();
		$base            = trailingslashit( (string) ( $uploads['basedir'] ?? '' ) );
		$directory       = dirname( $metadata['file'] );
		$directory       = '.' === $directory ? '' : trailingslashit( $directory );
		$source_width    = absint( $metadata['width'] ?? 0 );
		$source_height   = absint( $metadata['height'] ?? 0 );
			$source_path = $base . ltrim( wp_normalize_path( $metadata['file'] ), '/' );
			$sources     = array(
				array(
					'relative' => wp_normalize_path( $metadata['file'] ),
					'path'     => $source_path,
					'width'    => $source_width,
					'current'  => is_readable( $source_path ),
				),
			);
			$sizes       = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();

			foreach ( $sizes as $size ) {
				if ( ! is_array( $size ) || empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
					continue;
				}

				$width  = absint( $size['width'] ?? 0 );
				$height = absint( $size['height'] ?? 0 );

				if ( ! self::preserves_aspect_ratio( $width, $height, $source_width, $source_height ) ) {
					continue;
				}

				$relative  = wp_normalize_path( $directory . $size['file'] );
				$sources[] = array(
					'relative' => $relative,
					'path'     => $base . ltrim( $relative, '/' ),
					'width'    => $width,
					'current'  => self::is_current_file( $source_path, $base . ltrim( $relative, '/' ) ),
				);
			}

			return $sources;
	}

	/**
	 * Check whether a generated file is readable and at least as new as its source.
	 *
	 * @param string $source_path    Native source path.
	 * @param string $generated_path Generated path.
	 * @return bool
	 */
	public static function is_current_file( string $source_path, string $generated_path ): bool {
		if ( ! is_readable( $source_path ) || ! is_readable( $generated_path ) ) {
			return false;
		}

		$source_mtime    = filemtime( $source_path );
		$generated_mtime = filemtime( $generated_path );

		return false !== $source_mtime && false !== $generated_mtime && $generated_mtime >= $source_mtime;
	}

	/**
	 * Check metadata size aspect ratio.
	 *
	 * @param int $width         Candidate width.
	 * @param int $height        Candidate height.
	 * @param int $source_width  Source width.
	 * @param int $source_height Source height.
	 * @return bool
	 */
	private static function preserves_aspect_ratio( int $width, int $height, int $source_width, int $source_height ): bool {
		if ( $width < 1 || $height < 1 || $source_width < 1 || $source_height < 1 ) {
			return false;
		}

		return abs( ( $width * $source_height ) - ( $height * $source_width ) ) <= max( 2, (int) round( $source_width * $source_height * 0.002 ) );
	}

	/**
	 * Check width list against coverage band.
	 *
	 * @param array $widths       Candidate widths.
	 * @param int   $target_width Requested width.
	 * @return bool
	 */
	private static function width_list_covers( array $widths, int $target_width ): bool {
		foreach ( $widths as $width ) {
			$width = absint( $width );

			if ( self::width_covers_target( $width, $target_width ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check strict integer coverage without floating-point boundary drift.
	 *
	 * @param int $candidate_width Candidate physical width.
	 * @param int $target_width    Requested physical width.
	 * @return bool
	 */
	private static function width_covers_target( int $candidate_width, int $target_width ): bool {
		if ( $candidate_width < 1 || $target_width < 1 ) {
			return false;
		}

		return ( self::MIN_COVERAGE_DEN * $candidate_width ) >= ( self::MIN_COVERAGE_NUM * $target_width )
			&& ( self::MAX_COVERAGE_DEN * $candidate_width ) <= ( self::MAX_COVERAGE_NUM * $target_width );
	}

	/**
	 * Parse width descriptors from a generated srcset.
	 *
	 * @param string $srcset Srcset.
	 * @return array
	 */
	private static function parse_srcset_widths( string $srcset ): array {
		$widths = array();

		$candidates = preg_split( '/,\s*/', $srcset );
		$candidates = is_array( $candidates ) ? $candidates : array();

		foreach ( $candidates as $candidate ) {
			if ( preg_match( '/\s([0-9]+)w(?:\s|$)/', trim( $candidate ), $matches ) ) {
				$widths[] = absint( $matches[1] );
			}
		}

		return array_values( array_unique( array_filter( $widths ) ) );
	}

	/**
	 * Base64url encode.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function base64url_encode( string $value ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Reversible transport encoding for an HMAC-signed target, not executable code.
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Strict base64url decode.
	 *
	 * @param string $value Encoded value.
	 * @return string
	 */
	private static function base64url_decode( string $value ): string {
		$padding = strlen( $value ) % 4;

		if ( $padding > 0 ) {
			$value .= str_repeat( '=', 4 - $padding );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes an authenticated data handle after strict syntax and HMAC verification.
		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true );

		return is_string( $decoded ) ? $decoded : '';
	}
}
