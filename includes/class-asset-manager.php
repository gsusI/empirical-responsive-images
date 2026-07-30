<?php
/**
 * Local asset image handling.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates responsive variants for local non-attachment images.
 */
final class Asset_Manager {
	private const VARIANT_DIRECTORY = 'empirical-responsive-images/assets';
	private const MAX_SOURCE_BYTES  = 20971520;
	private const MAX_SOURCE_PIXELS = 24000000;
	private const MAX_VARIANT_FILES = 512;
	private const MAX_SOURCE_CACHE  = 320;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Request-local resolved source cache.
	 *
	 * @var array
	 */
	private $source_cache = array();

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Check whether an observed URL is a local image asset this plugin can manage.
	 *
	 * @param string $url Image URL.
	 * @return bool
	 */
	public function is_manageable_url( string $url ): bool {
		return ! empty( $this->resolve_source( $url ) );
	}

	/**
	 * Get stable key for an image URL.
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	public function key_for_url( string $url ): string {
		$source = $this->resolve_source( $url );

		if ( empty( $source ) ) {
			return '';
		}

		return $source['key'];
	}

	/**
	 * Get source pixel width for a manageable asset URL.
	 *
	 * @param string $url Image URL.
	 * @return int
	 */
	public function source_width_for_url( string $url ): int {
		$source = $this->resolve_source( $url );

		return ! empty( $source['width'] ) ? absint( $source['width'] ) : 0;
	}

	/**
	 * Get source pixel dimensions for a manageable asset URL.
	 *
	 * @param string $url Image URL.
	 * @return array
	 */
	public function source_dimensions_for_url( string $url ): array {
		$source = $this->resolve_source( $url );

		if ( empty( $source ) ) {
			return array();
		}

		return array(
			'width'  => absint( $source['width'] ?? 0 ),
			'height' => absint( $source['height'] ?? 0 ),
			'path'   => isset( $source['path'] ) && is_string( $source['path'] ) ? $source['path'] : '',
		);
	}

	/**
	 * Get current same-format generated widths for a manageable asset.
	 *
	 * The source width is always included. Directory enumeration is limited to
	 * the asset's dedicated keyed directory and a fixed number of entries.
	 *
	 * @param string $url Image URL.
	 * @return array
	 */
	public function get_existing_asset_widths( string $url ): array {
		$source = $this->resolve_source( $url );

		if ( empty( $source ) ) {
			return array();
		}

		$source_width = absint( $source['width'] );
		$widths       = array( $source_width );
		$sample       = $this->target_file_for_source( $source, 1, (string) $source['extension'] );
		$directory    = dirname( $sample['path'] );

		if ( ! is_dir( $directory ) || ! is_readable( $directory ) || is_link( $directory ) ) {
			return $widths;
		}

		$pattern = '/^' . preg_quote( (string) $source['filename'], '/' ) . '-([1-9][0-9]{0,9})w\.' . preg_quote( (string) $source['extension'], '/' ) . '$/D';
		$scanned = 0;

		try {
			$files = new \FilesystemIterator( $directory, \FilesystemIterator::SKIP_DOTS );
		} catch ( \UnexpectedValueException $error ) {
			return $widths;
		}

		foreach ( $files as $file ) {
			++$scanned;

			if ( $scanned > self::MAX_VARIANT_FILES ) {
				break;
			}

			if ( $file->isLink() || ! $file->isFile() || ! $file->isReadable() ) {
				continue;
			}

			if ( ! preg_match( $pattern, $file->getFilename(), $matches ) ) {
				continue;
			}

			$width = absint( $matches[1] );

			if ( $width < 1 || $width > $source_width || ! $this->is_current_variant( $file->getPathname(), absint( $source['mtime'] ) ) ) {
				continue;
			}

			$widths[] = $width;
		}

		$widths = array_values( array_unique( $widths ) );
		sort( $widths );

		return $widths;
	}

	/**
	 * Keep only requested formats that can be generated for one asset source.
	 *
	 * @param string $url     Image URL.
	 * @param array  $formats Requested formats.
	 * @return array
	 */
	public function get_applicable_formats_for_url( string $url, array $formats ): array {
		$source = $this->resolve_source( $url );

		if ( empty( $source ) ) {
			return array();
		}

		$applicable = array();

		foreach ( array_values( array_unique( array_map( 'sanitize_key', $formats ) ) ) as $format ) {
			if (
				in_array( $format, array( 'webp', 'avif' ), true )
				&& $this->supports_format( $format )
				&& Alpha_Compat::can_use_format_for_source( (string) $source['path'], $format )
			) {
				$applicable[] = $format;
			}
		}

		return $applicable;
	}

	/**
	 * Normalize a front-end image URL for storage.
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	public function normalize_url( string $url ): string {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES, get_bloginfo( 'charset' ) );

		if ( '' === $url || preg_match( '/^(data|blob|javascript):/i', $url ) ) {
			return '';
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( is_string( $host ) && '' !== $host && ! in_array( strtolower( $host ), $this->allowed_url_hosts(), true ) ) {
			return '';
		}

		$url = esc_url_raw( wp_make_link_relative( $url ) );

		if ( '' === $url ) {
			return '';
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		return $path;
	}

	/**
	 * Get URL hosts that may map to local files.
	 *
	 * @return array
	 */
	private function allowed_url_hosts(): array {
		$uploads = wp_get_upload_dir();
		$urls    = array(
			home_url( '/' ),
			site_url( '/' ),
			content_url( '/' ),
			$uploads['baseurl'] ?? '',
		);
		$hosts   = array();

		foreach ( $urls as $url ) {
			$host = wp_parse_url( (string) $url, PHP_URL_HOST );

			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = strtolower( $host );
			}
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Build srcset, sizes, and modern format sources for a local asset URL.
	 *
	 * @param string $url          Image URL.
	 * @param array  $target_widths Target physical widths.
	 * @param array  $slot_widths   Rendered CSS slot widths.
	 * @param bool   $force         Whether to rebuild existing files.
	 * @return array
	 */
	public function build_asset_sources( string $url, array $target_widths, array $slot_widths, bool $force = false ): array {
		return $this->build_sources( $url, $target_widths, $slot_widths, true, $force, true );
	}

	/**
	 * Build source attributes from files already generated on disk.
	 *
	 * Front-end rendering must stay read-only. Missing variants are generated by
	 * the explicit admin or WP-CLI regeneration workflow instead.
	 *
	 * @param string $url           Image URL.
	 * @param array  $target_widths Target physical widths.
	 * @param array  $slot_widths   Rendered CSS slot widths.
	 * @return array
	 */
	public function get_existing_asset_sources( string $url, array $target_widths, array $slot_widths ): array {
		return $this->build_sources( $url, $target_widths, $slot_widths, false, false, true );
	}

	/**
	 * Build source attributes, optionally generating missing files.
	 *
	 * @param string $url              Image URL.
	 * @param array  $target_widths    Target physical widths.
	 * @param array  $slot_widths      Rendered CSS slot widths.
	 * @param bool   $generate_missing Whether missing files may be generated.
	 * @param bool   $force            Whether existing files should be rebuilt.
	 * @param bool   $include_source   Whether to include the original source width.
	 * @return array
	 */
	private function build_sources( string $url, array $target_widths, array $slot_widths, bool $generate_missing, bool $force, bool $include_source ): array {
		$source = $this->resolve_source( $url );

		if ( empty( $source ) ) {
			return array();
		}

		$widths = $this->normalize_target_widths( $target_widths, absint( $source['width'] ), $include_source );

		if ( empty( $widths ) ) {
			return array();
		}

		$candidates = array();
		$formats    = array();
		$errors     = array();
		$generated  = 0;
		$skipped    = 0;

		foreach ( $widths as $width ) {
			$result = $generate_missing
				? $this->ensure_width_variant( $source, $width, $force )
				: $this->get_existing_width_variant( $source, $width );

			if ( empty( $result ) ) {
				++$skipped;
				continue;
			}

			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
				continue;
			}

			$candidates[] = array(
				'url'   => $result['url'],
				'path'  => $result['path'],
				'width' => absint( $result['width'] ),
			);

			if ( ! empty( $result['generated'] ) ) {
				++$generated;
			} else {
				++$skipped;
			}
		}

		foreach ( $this->settings->get_enabled_formats() as $format ) {
			if ( ! $this->supports_format( $format ) ) {
				continue;
			}

			if ( ! Alpha_Compat::can_use_format_for_source( $source['path'], $format ) ) {
				continue;
			}

			$format_candidates = array();

			foreach ( $candidates as $candidate ) {
				$result = $generate_missing
					? $this->ensure_format_variant( $candidate['path'], $source, absint( $candidate['width'] ), $format, $force )
					: $this->get_existing_format_variant( $candidate['path'], $source, absint( $candidate['width'] ), $format );

				if ( empty( $result ) ) {
					++$skipped;
					continue;
				}

				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
					continue;
				}

				$format_candidates[] = array(
					'url'   => $result['url'],
					'width' => absint( $candidate['width'] ),
				);

				if ( ! empty( $result['generated'] ) ) {
					++$generated;
				} else {
					++$skipped;
				}
			}

			if ( ! empty( $format_candidates ) ) {
				$formats[ $format ] = $this->build_srcset_from_candidates( $format_candidates );
			}
		}

		return array(
			'srcset'    => $this->build_srcset_from_candidates( $candidates ),
			'sizes'     => $this->build_empirical_sizes( $slot_widths ),
			'formats'   => $formats,
			'generated' => $generated,
			'skipped'   => $skipped,
			'errors'    => array_slice( array_unique( $errors ), 0, 10 ),
		);
	}

	/**
	 * Return an existing, current same-format variant.
	 *
	 * @param array $source Source info.
	 * @param int   $width  Width.
	 * @return array
	 */
	private function get_existing_width_variant( array $source, int $width ): array {
		if ( $width >= absint( $source['width'] ) ) {
			return array(
				'url'       => $source['url'],
				'path'      => $source['path'],
				'width'     => absint( $source['width'] ),
				'generated' => false,
			);
		}

		$target = $this->target_file_for_source( $source, $width, $source['extension'] );

		if ( ! $this->is_current_variant( $target['path'], absint( $source['mtime'] ) ) ) {
			return array();
		}

		return array(
			'url'       => $target['url'],
			'path'      => $target['path'],
			'width'     => $width,
			'generated' => false,
		);
	}

	/**
	 * Return an existing, current alternate-format variant.
	 *
	 * @param string $candidate_path Candidate source path.
	 * @param array  $source         Original source info.
	 * @param int    $width          Width.
	 * @param string $format         Format.
	 * @return array
	 */
	private function get_existing_format_variant( string $candidate_path, array $source, int $width, string $format ): array {
		$target       = $this->target_file_for_source( $source, $width, $format );
		$source_mtime = filemtime( $candidate_path );
		$source_mtime = false === $source_mtime ? absint( $source['mtime'] ) : (int) $source_mtime;

		if ( ! $this->is_current_variant( $target['path'], $source_mtime ) ) {
			return array();
		}

		return array(
			'url'       => $target['url'],
			'generated' => false,
		);
	}

	/**
	 * Check whether a generated file exists and is not older than its source.
	 *
	 * @param string $path         Generated file path.
	 * @param int    $source_mtime Source modification time.
	 * @return bool
	 */
	private function is_current_variant( string $path, int $source_mtime ): bool {
		if ( ! is_readable( $path ) ) {
			return false;
		}

		$variant_mtime = filemtime( $path );

		return false !== $variant_mtime && $variant_mtime >= $source_mtime;
	}

	/**
	 * Generate all observed variants for an asset.
	 *
	 * @param string $url          Image URL.
	 * @param array  $target_widths Target widths.
	 * @param array  $slot_widths   Slot widths.
	 * @param bool   $force         Force rebuild.
	 * @return array
	 */
	public function generate_for_asset( string $url, array $target_widths, array $slot_widths, bool $force = false ): array {
		$result = $this->build_asset_sources( $url, $target_widths, $slot_widths, $force );

		return $this->summarize_generation_result( $result );
	}

	/**
	 * Generate one requested asset width and its enabled alternate formats.
	 *
	 * Unlike full/manual asset generation, this does not add the original source
	 * width unless the requested width itself is capped to that width.
	 *
	 * @param string $url   Image URL.
	 * @param int    $width Target width.
	 * @param bool   $force Force rebuild.
	 * @return array
	 */
	public function generate_for_asset_width( string $url, int $width, bool $force = false ): array {
		$result = $this->build_sources( $url, array( $width ), array(), true, $force, false );

		return $this->summarize_generation_result( $result );
	}

	/**
	 * Reduce a source-building result to generation counters and errors.
	 *
	 * @param array $result Source-building result.
	 * @return array
	 */
	private function summarize_generation_result( array $result ): array {
		return array(
			'generated' => absint( $result['generated'] ?? 0 ),
			'skipped'   => absint( $result['skipped'] ?? 0 ),
			'errors'    => is_array( $result['errors'] ?? null ) ? $result['errors'] : array(),
		);
	}

	/**
	 * Resolve a local image URL to a readable source file.
	 *
	 * @param string $url Image URL.
	 * @return array
	 */
	private function resolve_source( string $url ): array {
		$normalized_url = $this->normalize_url( $url );

		if ( '' === $normalized_url ) {
			return array();
		}

		if ( array_key_exists( $normalized_url, $this->source_cache ) ) {
			return $this->source_cache[ $normalized_url ];
		}

		$path = rawurldecode( $normalized_url );

		if ( false !== strpos( $path, '..' ) ) {
			return $this->cache_source( $normalized_url, array() );
		}

		$candidates = $this->path_candidates_for_url_path( $path );

		foreach ( $candidates as $candidate ) {
			$real_path = realpath( $candidate );

			if ( ! is_string( $real_path ) || ! is_readable( $real_path ) || ! $this->is_allowed_source_path( $real_path ) ) {
				continue;
			}

			if ( filesize( $real_path ) > (int) apply_filters( 'empirical_responsive_images_max_asset_source_bytes', self::MAX_SOURCE_BYTES, $real_path ) ) {
				continue;
			}

			$type = wp_check_filetype( $real_path );
			$mime = is_string( $type['type'] ) ? $type['type'] : '';

			if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
				continue;
			}

			$size = wp_getimagesize( $real_path );

			if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
				continue;
			}

			$pixel_count = absint( $size[0] ) * absint( $size[1] );
			$pixel_limit = (int) apply_filters( 'empirical_responsive_images_max_source_pixels', self::MAX_SOURCE_PIXELS, $real_path );

			if ( $pixel_limit < 1 || $pixel_count > $pixel_limit ) {
				continue;
			}

			return $this->cache_source(
				$normalized_url,
				array(
					'key'       => substr( sha1( wp_normalize_path( $real_path ) . '|' . $normalized_url ), 0, 20 ),
					'url'       => home_url( $normalized_url ),
					'path'      => $real_path,
					'width'     => absint( $size[0] ),
					'height'    => absint( $size[1] ),
					'extension' => strtolower( (string) pathinfo( $real_path, PATHINFO_EXTENSION ) ),
					'filename'  => sanitize_file_name( (string) pathinfo( $real_path, PATHINFO_FILENAME ) ),
					'mtime'     => filemtime( $real_path ),
				)
			);
		}

		return $this->cache_source( $normalized_url, array() );
	}

	/**
	 * Store one bounded request-local source lookup.
	 *
	 * @param string $normalized_url Normalized source URL.
	 * @param array  $source         Resolved source or empty result.
	 * @return array
	 */
	private function cache_source( string $normalized_url, array $source ): array {
		if ( count( $this->source_cache ) >= self::MAX_SOURCE_CACHE ) {
			array_shift( $this->source_cache );
		}

		$this->source_cache[ $normalized_url ] = $source;

		return $source;
	}

	/**
	 * Get filesystem path candidates for a URL path.
	 *
	 * @param string $url_path URL path.
	 * @return array
	 */
	private function path_candidates_for_url_path( string $url_path ): array {
		$url_path  = '/' . ltrim( $url_path, '/' );
		$site_path = wp_parse_url( site_url( '/' ), PHP_URL_PATH );
		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$uploads   = wp_get_upload_dir();
		$root_maps = array(
			array( content_url( '/' ), WP_CONTENT_DIR ),
			array( (string) ( $uploads['baseurl'] ?? '' ), (string) ( $uploads['basedir'] ?? '' ) ),
		);
		$paths     = array();

		foreach ( $root_maps as $root_map ) {
			$base_path = wp_parse_url( $root_map[0], PHP_URL_PATH );
			$base_path = is_string( $base_path ) ? '/' . trim( $base_path, '/' ) : '';
			$base_path = '/' === $base_path ? '' : $base_path;

			if ( '' !== $base_path && '' !== $root_map[1] && ( $url_path === $base_path || 0 === strpos( $url_path, trailingslashit( $base_path ) ) ) ) {
				$paths[] = trailingslashit( $root_map[1] ) . ltrim( substr( $url_path, strlen( $base_path ) ), '/' );
			}
		}

		foreach ( array( $site_path, $home_path ) as $base_path ) {
			$base_path = is_string( $base_path ) ? '/' . trim( $base_path, '/' ) : '';
			$base_path = '/' === $base_path ? '' : $base_path;

			if ( '' !== $base_path && 0 === strpos( $url_path, trailingslashit( $base_path ) ) ) {
				$paths[] = ABSPATH . ltrim( substr( $url_path, strlen( $base_path ) ), '/' );
			}
		}

		$paths[] = ABSPATH . ltrim( $url_path, '/' );

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Check allowed source roots. Default: wp-content only.
	 *
	 * @param string $path Real source path.
	 * @return bool
	 */
	private function is_allowed_source_path( string $path ): bool {
		$roots = array_filter(
			(array) apply_filters(
				'empirical_responsive_images_asset_source_roots',
				array(
					WP_CONTENT_DIR,
				)
			)
		);

		foreach ( $roots as $root ) {
			$real_root = realpath( (string) $root );

			if ( is_string( $real_root ) && 0 === strpos( wp_normalize_path( $path ), trailingslashit( wp_normalize_path( $real_root ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize target physical widths.
	 *
	 * @param array $widths         Source widths.
	 * @param int   $source_width   Source width.
	 * @param bool  $include_source Whether to add the original source width.
	 * @return array
	 */
	private function normalize_target_widths( array $widths, int $source_width, bool $include_source ): array {
		$normalized = array();

		foreach ( $widths as $width ) {
			$width = absint( $width );

			if ( $width > 0 ) {
				$normalized[] = min( $width, $source_width );
			}
		}

		if ( $include_source ) {
			$normalized[] = $source_width;
		}

		$normalized = array_values( array_unique( array_filter( $normalized ) ) );
		sort( $normalized );

		return $normalized;
	}

	/**
	 * Ensure same-format width variant exists.
	 *
	 * @param array $source Source info.
	 * @param int   $width Width.
	 * @param bool  $force Force rebuild.
	 * @return array|\WP_Error
	 */
	private function ensure_width_variant( array $source, int $width, bool $force ) {
		if ( $width >= absint( $source['width'] ) ) {
			return array(
				'url'       => $source['url'],
				'path'      => $source['path'],
				'width'     => absint( $source['width'] ),
				'generated' => false,
			);
		}

		$target = $this->target_file_for_source( $source, $width, $source['extension'] );

		if ( ! $force && file_exists( $target['path'] ) && filemtime( $target['path'] ) >= absint( $source['mtime'] ) ) {
			return array(
				'url'       => $target['url'],
				'path'      => $target['path'],
				'width'     => $width,
				'generated' => false,
			);
		}

		if ( ! wp_mkdir_p( dirname( $target['path'] ) ) ) {
			return new \WP_Error( 'empirical_responsive_images_asset_mkdir_failed', __( 'Could not create asset image directory.', 'empirical-responsive-images' ) );
		}

		$editor = wp_get_image_editor( $source['path'] );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		if ( method_exists( $editor, 'set_quality' ) ) {
			$editor->set_quality( (int) apply_filters( 'empirical_responsive_images_output_quality', 82, $source['extension'], $source['path'] ) );
		}

		$resized = $editor->resize( $width, null, false );

		if ( is_wp_error( $resized ) ) {
			return $resized;
		}

		$saved = $editor->save( $target['path'] );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'url'       => $target['url'],
			'path'      => $target['path'],
			'width'     => $width,
			'generated' => true,
		);
	}

	/**
	 * Ensure alternate format variant exists for a candidate.
	 *
	 * @param string $candidate_path Candidate source path.
	 * @param array  $source         Original source info.
	 * @param int    $width          Width.
	 * @param string $format         Format.
	 * @param bool   $force          Force rebuild.
	 * @return array|\WP_Error
	 */
	private function ensure_format_variant( string $candidate_path, array $source, int $width, string $format, bool $force ) {
		$target       = $this->target_file_for_source( $source, $width, $format );
		$source_mtime = filemtime( $candidate_path );
		$source_mtime = false === $source_mtime ? absint( $source['mtime'] ) : (int) $source_mtime;

		if ( ! $force && file_exists( $target['path'] ) && filemtime( $target['path'] ) >= $source_mtime ) {
			return array(
				'url'       => $target['url'],
				'generated' => false,
			);
		}

		if ( ! wp_mkdir_p( dirname( $target['path'] ) ) ) {
			return new \WP_Error( 'empirical_responsive_images_asset_mkdir_failed', __( 'Could not create asset image directory.', 'empirical-responsive-images' ) );
		}

		$editor = wp_get_image_editor( $candidate_path );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		if ( method_exists( $editor, 'set_quality' ) ) {
			$editor->set_quality( (int) apply_filters( 'empirical_responsive_images_output_quality', 82, $format, $candidate_path ) );
		}

		$saved = $editor->save( $target['path'], 'image/' . $format );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'url'       => $target['url'],
			'generated' => true,
		);
	}

	/**
	 * Get target file data.
	 *
	 * @param array  $source Source info.
	 * @param int    $width Width.
	 * @param string $extension Extension.
	 * @return array
	 */
	private function target_file_for_source( array $source, int $width, string $extension ): array {
		$uploads  = wp_get_upload_dir();
		$filename = $source['filename'] . '-' . absint( $width ) . 'w.' . sanitize_key( $extension );
		$relative = trailingslashit( self::VARIANT_DIRECTORY ) . $source['key'] . '/' . $filename;

		return array(
			'relative' => $relative,
			'path'     => trailingslashit( $uploads['basedir'] ) . $relative,
			'url'      => $this->with_site_scheme( trailingslashit( $uploads['baseurl'] ) . $relative ),
		);
	}

	/**
	 * Match generated URLs to the public site scheme.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function with_site_scheme( string $url ): string {
		$scheme = wp_parse_url( home_url( '/' ), PHP_URL_SCHEME );
		$scheme = is_string( $scheme ) && '' !== $scheme ? $scheme : 'https';

		return set_url_scheme( $url, $scheme );
	}

	/**
	 * Build srcset from URL/width candidates.
	 *
	 * @param array $candidates Candidates.
	 * @return string
	 */
	private function build_srcset_from_candidates( array $candidates ): string {
		$items = array();

		foreach ( $candidates as $candidate ) {
			if ( empty( $candidate['url'] ) || empty( $candidate['width'] ) ) {
				continue;
			}

			$items[] = esc_url_raw( $candidate['url'] ) . ' ' . absint( $candidate['width'] ) . 'w';
		}

		return implode( ', ', array_unique( $items ) );
	}

	/**
	 * Build an empirical sizes attribute from observed CSS slots.
	 *
	 * @param array $slot_widths Slot width map.
	 * @return string
	 */
	public function build_empirical_sizes( array $slot_widths ): string {
		$slots = array();

		foreach ( $slot_widths as $viewport => $slot_width ) {
			$viewport   = absint( $viewport );
			$slot_width = absint( $slot_width );

			if ( $viewport > 0 && $slot_width > 0 ) {
				$slots[ $viewport ] = $slot_width;
			}
		}

		if ( empty( $slots ) ) {
			return '100vw';
		}

		ksort( $slots );

		$parts = array();

		foreach ( $slots as $viewport => $slot_width ) {
			$parts[] = '(max-width: ' . $viewport . 'px) ' . $slot_width . 'px';
		}

		$parts[] = max( $slots ) . 'px';

		return implode( ', ', array_unique( $parts ) );
	}

	/**
	 * Check server support for a target format.
	 *
	 * @param string $format Format.
	 * @return bool
	 */
	private function supports_format( string $format ): bool {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		return (bool) wp_image_editor_supports(
			array(
				'mime_type' => 'image/' . $format,
			)
		);
	}
}
