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

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

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
		$source = $this->resolve_source( $url );

		if ( empty( $source ) ) {
			return array();
		}

		$widths = $this->normalize_target_widths( $target_widths, absint( $source['width'] ) );

		if ( empty( $widths ) ) {
			return array();
		}

		$candidates = array();
		$formats    = array();
		$errors     = array();
		$generated  = 0;
		$skipped    = 0;

		foreach ( $widths as $width ) {
			$result = $this->ensure_width_variant( $source, $width, $force );

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
				$result = $this->ensure_format_variant( $candidate['path'], $source, absint( $candidate['width'] ), $format, $force );

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

		$path = rawurldecode( $normalized_url );

		if ( false !== strpos( $path, '..' ) ) {
			return array();
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
			$mime = isset( $type['type'] ) ? (string) $type['type'] : '';

			if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ), true ) ) {
				continue;
			}

			if ( 'image/gif' === $mime && $this->is_animated_gif( $real_path ) ) {
				continue;
			}

			$size = wp_getimagesize( $real_path );

			if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
				continue;
			}

			return array(
				'key'       => substr( sha1( wp_normalize_path( $real_path ) . '|' . $normalized_url ), 0, 20 ),
				'url'       => home_url( $normalized_url ),
				'path'      => $real_path,
				'width'     => absint( $size[0] ),
				'height'    => absint( $size[1] ),
				'extension' => strtolower( (string) pathinfo( $real_path, PATHINFO_EXTENSION ) ),
				'filename'  => sanitize_file_name( (string) pathinfo( $real_path, PATHINFO_FILENAME ) ),
				'mtime'     => filemtime( $real_path ),
			);
		}

		return array();
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
		$paths     = array();

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
	 * Detect animated GIFs so resizing does not silently drop animation.
	 *
	 * @param string $path GIF path.
	 * @return bool
	 */
	private function is_animated_gif( string $path ): bool {
		$contents = file_get_contents( $path, false, null, 0, 1048576 );

		if ( ! is_string( $contents ) ) {
			return false;
		}

		return preg_match_all( '#\x00\x21\xF9\x04#s', $contents ) > 1;
	}

	/**
	 * Normalize target physical widths.
	 *
	 * @param array $widths Source widths.
	 * @param int   $source_width Source width.
	 * @return array
	 */
	private function normalize_target_widths( array $widths, int $source_width ): array {
		$normalized = array();

		foreach ( $widths as $width ) {
			$width = absint( $width );

			if ( $width > 0 ) {
				$normalized[] = min( $width, $source_width );
			}
		}

		$normalized[] = $source_width;
		$normalized   = array_values( array_unique( array_filter( $normalized ) ) );
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
		$target = $this->target_file_for_source( $source, $width, $format );
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
	private function build_empirical_sizes( array $slot_widths ): string {
		$slots = array();

		foreach ( $slot_widths as $viewport => $slot_width ) {
			$viewport  = absint( $viewport );
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
