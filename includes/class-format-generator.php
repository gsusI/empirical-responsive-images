<?php
/**
 * Modern format generation.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates WebP and AVIF sidecar files for generated image sizes.
 */
final class Format_Generator {
	public const METADATA_KEY       = '_empirical_responsive_images_formats';
	private const SIDECAR_DIRECTORY = 'empirical-responsive-images/attachments';
	private const MAX_SOURCE_BYTES  = 20971520;
	private const MAX_SOURCE_PIXELS = 24000000;

	/**
	 * Whether automatic generation is temporarily suspended.
	 *
	 * @var bool
	 */
	private $metadata_filter_suspended = false;

	/**
	 * Settings service.
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
	 * Generate alternates after core metadata generation.
	 *
	 * @param mixed $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return mixed
	 */
	public function filter_generate_attachment_metadata( $metadata, int $attachment_id ) {
		if ( $this->metadata_filter_suspended || ! is_array( $metadata ) ) {
			return $metadata;
		}

		$source_path = get_attached_file( $attachment_id );

		if ( ! is_string( $source_path ) || ! $this->source_is_safe_for_synchronous_conversion( $source_path ) ) {
			return $metadata;
		}

		$result = $this->generate_for_metadata( $attachment_id, $metadata, false );

		return $result['metadata'];
	}

	/**
	 * Remove plugin-owned sidecars no longer referenced by attachment metadata.
	 *
	 * @param mixed $metadata      New attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return mixed
	 */
	public function filter_update_attachment_metadata( $metadata, int $attachment_id ) {
		if ( ! is_array( $metadata ) ) {
			return $metadata;
		}

		$previous = wp_get_attachment_metadata( $attachment_id );

		if ( is_array( $previous ) ) {
			$this->delete_unreferenced_sidecars( $previous, $metadata );
		}

		return $metadata;
	}

	/**
	 * Delete every plugin-owned sidecar belonging to a deleted attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function delete_attachment_sidecars( int $attachment_id ): void {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( is_array( $metadata ) ) {
			$this->delete_unreferenced_sidecars( $metadata, array() );
		}
	}

	/**
	 * Run core metadata generation without invoking automatic sidecar generation.
	 *
	 * Explicit regeneration uses this to avoid encoding every sidecar twice.
	 *
	 * @param callable $callback Metadata callback.
	 * @return mixed
	 */
	public function without_metadata_filter( callable $callback ) {
		$previous                        = $this->metadata_filter_suspended;
		$this->metadata_filter_suspended = true;

		try {
			return $callback();
		} finally {
			$this->metadata_filter_suspended = $previous;
		}
	}

	/**
	 * Generate alternates for one attachment.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $force         Whether to overwrite existing sidecars.
	 * @return array
	 */
	public function generate_for_attachment( int $attachment_id, bool $force = false ): array {
		$file = get_attached_file( $attachment_id );

		if ( ! is_string( $file ) || '' === $file || ! file_exists( $file ) ) {
			return array(
				'generated' => 0,
				'skipped'   => 0,
				'errors'    => array( __( 'Original file is missing.', 'empirical-responsive-images' ) ),
			);
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $metadata ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
		}

		if ( ! is_array( $metadata ) ) {
			return array(
				'generated' => 0,
				'skipped'   => 0,
				'errors'    => array( __( 'Attachment metadata could not be generated.', 'empirical-responsive-images' ) ),
			);
		}

		$result = $this->generate_for_metadata( $attachment_id, $metadata, $force );
		wp_update_attachment_metadata( $attachment_id, $result['metadata'] );

		return $result;
	}

	/**
	 * Generate alternates only for attachment sub-sizes matching one width.
	 *
	 * Existing alternate-format metadata for the original and other sub-sizes
	 * remains untouched.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param int  $width         Exact metadata sub-size width.
	 * @param bool $force         Whether to overwrite existing sidecars.
	 * @return array
	 */
	public function generate_for_attachment_width( int $attachment_id, int $width, bool $force = false ): array {
		$file  = get_attached_file( $attachment_id );
		$width = absint( $width );

		if ( $width < 1 ) {
			return array(
				'generated' => 0,
				'skipped'   => 0,
				'errors'    => array( __( 'Requested image width is invalid.', 'empirical-responsive-images' ) ),
			);
		}

		if ( ! is_string( $file ) || '' === $file || ! file_exists( $file ) ) {
			return array(
				'generated' => 0,
				'skipped'   => 0,
				'errors'    => array( __( 'Original file is missing.', 'empirical-responsive-images' ) ),
			);
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $metadata ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$metadata = $this->without_metadata_filter(
				static function () use ( $attachment_id, $file ) {
					return wp_generate_attachment_metadata( $attachment_id, $file );
				}
			);
		}

		if ( ! is_array( $metadata ) ) {
			return array(
				'generated' => 0,
				'skipped'   => 0,
				'errors'    => array( __( 'Attachment metadata could not be generated.', 'empirical-responsive-images' ) ),
			);
		}

		$sources = $this->get_metadata_sources( $metadata, $width );
		$result  = $this->generate_for_sources( $metadata, $sources, $force, false );

		wp_update_attachment_metadata( $attachment_id, $result['metadata'] );

		return $result;
	}

	/**
	 * Get format support status.
	 *
	 * @return array
	 */
	public function get_support_status(): array {
		$status = array();

		foreach ( array( 'webp', 'avif' ) as $format ) {
			$status[ $format ] = array(
				'enabled'   => in_array( $format, $this->settings->get_enabled_formats(), true ),
				'supported' => $this->supports_format( $format ),
				'mime'      => $this->mime_for_format( $format ),
			);
		}

		return $status;
	}

	/**
	 * Get enabled formats supported by the active WordPress image editor.
	 *
	 * @return array
	 */
	public function get_supported_enabled_formats(): array {
		$formats = array();

		foreach ( $this->get_support_status() as $format => $status ) {
			if ( ! empty( $status['enabled'] ) && ! empty( $status['supported'] ) ) {
				$formats[] = sanitize_key( (string) $format );
			}
		}

		return $formats;
	}

	/**
	 * Get alternate formats that automatic front-end optimization must cover.
	 *
	 * Formats are only required when picture output can serve them.
	 *
	 * @return array
	 */
	public function get_required_frontend_formats(): array {
		if ( ! $this->settings->is_picture_enabled() ) {
			return array();
		}

		return $this->get_supported_enabled_formats();
	}

	/**
	 * Check whether an upload-relative path belongs to plugin sidecar storage.
	 *
	 * @param string $relative_path Upload-relative path.
	 * @return bool
	 */
	public static function is_plugin_sidecar_path( string $relative_path ): bool {
		$relative_path = wp_normalize_path( $relative_path );
		$prefix        = trailingslashit( self::SIDECAR_DIRECTORY );

		return 0 === strpos( $relative_path, $prefix )
			&& false === strpos( $relative_path, '..' )
			&& '/' !== substr( $relative_path, 0, 1 );
	}

	/**
	 * Generate alternate files for all metadata sources.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $metadata      Metadata.
	 * @param bool  $force         Whether to overwrite existing sidecars.
	 * @return array
	 */
	private function generate_for_metadata( int $attachment_id, array $metadata, bool $force ): array {
		$sources = $this->get_metadata_sources( $metadata );

		return $this->generate_for_sources( $metadata, $sources, $force, true );
	}

	/**
	 * Generate alternate files for selected metadata sources.
	 *
	 * @param array $metadata             Attachment metadata.
	 * @param array $sources              Upload-relative source paths.
	 * @param bool  $force                Whether to overwrite existing sidecars.
	 * @param bool  $prune_other_metadata Whether stale metadata outside selected sources should be removed.
	 * @return array
	 */
	private function generate_for_sources( array $metadata, array $sources, bool $force, bool $prune_other_metadata ): array {
		$formats = array_filter(
			$this->settings->get_enabled_formats(),
			function ( string $format ): bool {
				return $this->supports_format( $format );
			}
		);

		if ( empty( $formats ) ) {
			return array(
				'metadata'  => $metadata,
				'generated' => 0,
				'skipped'   => 0,
				'errors'    => array(),
			);
		}

		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );

		if ( empty( $metadata[ self::METADATA_KEY ] ) || ! is_array( $metadata[ self::METADATA_KEY ] ) ) {
			$metadata[ self::METADATA_KEY ] = array(
				'generated_at' => 0,
				'files'        => array(),
			);
		}

		$metadata[ self::METADATA_KEY ]['files'] = is_array( $metadata[ self::METADATA_KEY ]['files'] ?? null )
			? $metadata[ self::METADATA_KEY ]['files']
			: array();

		if ( $prune_other_metadata ) {
			$metadata[ self::METADATA_KEY ]['files'] = $this->filter_metadata_files_to_sources(
				$metadata[ self::METADATA_KEY ]['files'],
				$sources
			);
		}

		$generated = 0;
		$skipped   = 0;
		$errors    = array();

		foreach ( $sources as $relative_path ) {
			if ( '' === $relative_path || false !== strpos( $relative_path, '..' ) || '/' === substr( $relative_path, 0, 1 ) ) {
				++$skipped;
				continue;
			}

			$source_path = $base . $relative_path;

			if ( ! is_readable( $source_path ) || ! $this->source_can_be_converted( $source_path ) ) {
				++$skipped;
				continue;
			}

			foreach ( $formats as $format ) {
				if ( in_array( $this->source_mime( $source_path ), array( 'image/webp', 'image/avif' ), true ) ) {
					/*
					 * These containers may be animated. WordPress image editors
					 * generally flatten animation while resizing or transcoding,
					 * so automatic conversion must preserve the original instead.
					 */
					unset( $metadata[ self::METADATA_KEY ]['files'][ $relative_path ][ $format ] );
					++$skipped;
					continue;
				}

				if ( $this->mime_for_format( $format ) === $this->source_mime( $source_path ) ) {
					unset( $metadata[ self::METADATA_KEY ]['files'][ $relative_path ][ $format ] );
					++$skipped;
					continue;
				}

				if ( ! Alpha_Compat::can_use_format_for_source( $source_path, $format ) ) {
					unset( $metadata[ self::METADATA_KEY ]['files'][ $relative_path ][ $format ] );
					++$skipped;
					continue;
				}

				$result = $this->generate_sidecar( $source_path, $relative_path, $format, $force );

				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
					continue;
				}

				if ( 'generated' === $result['status'] ) {
					++$generated;
				} else {
					++$skipped;
				}

				$metadata[ self::METADATA_KEY ]['files'][ $relative_path ][ $format ] = $result['relative_path'];
			}
		}

		$metadata[ self::METADATA_KEY ]['generated_at'] = time();

		return array(
			'metadata'  => $metadata,
			'generated' => $generated,
			'skipped'   => $skipped,
			'errors'    => $errors,
		);
	}

	/**
	 * Generate one sidecar.
	 *
	 * @param string $source_path   Source absolute path.
	 * @param string $relative_path Source upload-relative path.
	 * @param string $format        Target format.
	 * @param bool   $force         Whether to overwrite.
	 * @return array|\WP_Error
	 */
	private function generate_sidecar( string $source_path, string $relative_path, string $format, bool $force ) {
		$uploads       = wp_get_upload_dir();
		$source_key    = sha1( wp_normalize_path( $relative_path ) );
		$source_name   = sanitize_file_name( (string) pathinfo( $relative_path, PATHINFO_FILENAME ) );
		$source_name   = '' !== $source_name ? $source_name : 'image';
		$target_rel    = trailingslashit( self::SIDECAR_DIRECTORY )
			. substr( $source_key, 0, 2 )
			. '/'
			. $source_key
			. '/'
			. $source_name
			. '.'
			. $format;
		$target_path   = trailingslashit( $uploads['basedir'] ) . $target_rel;
		$target_folder = dirname( $target_path );

		if ( ! $force && Visitor_Gap_Target::is_current_file( $source_path, $target_path ) ) {
			return array(
				'status'        => 'exists',
				'relative_path' => $target_rel,
			);
		}

		if ( ! wp_mkdir_p( $target_folder ) ) {
			return new \WP_Error( 'empirical_responsive_images_mkdir_failed', __( 'Could not create sidecar image directory.', 'empirical-responsive-images' ) );
		}

		$editor = wp_get_image_editor( $source_path );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		if ( method_exists( $editor, 'set_quality' ) ) {
			$editor->set_quality( (int) apply_filters( 'empirical_responsive_images_output_quality', 82, $format, $source_path ) );
		}

		$saved = $editor->save( $target_path, $this->mime_for_format( $format ) );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'status'        => 'generated',
			'relative_path' => $target_rel,
		);
	}

	/**
	 * Get upload-relative metadata source files.
	 *
	 * @param array    $metadata       Attachment metadata.
	 * @param int|null $matching_width Optional exact sub-size width. Original is excluded when set.
	 * @return array
	 */
	private function get_metadata_sources( array $metadata, ?int $matching_width = null ): array {
		if ( empty( $metadata['file'] ) || ! is_string( $metadata['file'] ) ) {
			return array();
		}

		$directory      = dirname( $metadata['file'] );
		$directory      = '.' === $directory ? '' : trailingslashit( $directory );
		$sources        = null === $matching_width ? array( $metadata['file'] ) : array();
		$source_width   = absint( $metadata['width'] ?? 0 );
		$source_height  = absint( $metadata['height'] ?? 0 );
		$matching_width = null === $matching_width ? null : absint( $matching_width );

		if ( null !== $matching_width && $source_width === $matching_width ) {
			$sources[] = $metadata['file'];
		}

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! is_array( $size ) || empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
					continue;
				}

				if ( null !== $matching_width && absint( $size['width'] ?? 0 ) !== $matching_width ) {
					continue;
				}

				if ( ! $this->metadata_size_preserves_aspect_ratio( $size, $source_width, $source_height ) ) {
					continue;
				}

				$sources[] = $directory . $size['file'];
			}
		}

		return array_values( array_unique( array_map( 'wp_normalize_path', $sources ) ) );
	}

	/**
	 * Check whether a generated metadata size keeps original aspect ratio.
	 *
	 * @param array $size          Size metadata.
	 * @param int   $source_width  Original width.
	 * @param int   $source_height Original height.
	 * @return bool
	 */
	private function metadata_size_preserves_aspect_ratio( array $size, int $source_width, int $source_height ): bool {
		$width  = absint( $size['width'] ?? 0 );
		$height = absint( $size['height'] ?? 0 );

		if ( $width < 1 || $height < 1 || $source_width < 1 || $source_height < 1 ) {
			return false;
		}

		return abs( ( $width * $source_height ) - ( $height * $source_width ) ) <= max( 2, (int) round( $source_width * $source_height * 0.002 ) );
	}

	/**
	 * Drop stale sidecar metadata for sources no longer eligible.
	 *
	 * @param array $files   Existing sidecar metadata.
	 * @param array $sources Allowed source paths.
	 * @return array
	 */
	private function filter_metadata_files_to_sources( array $files, array $sources ): array {
		$allowed = array_fill_keys( $sources, true );

		foreach ( array_keys( $files ) as $relative_path ) {
			if ( ! is_string( $relative_path ) || ! isset( $allowed[ $relative_path ] ) ) {
				unset( $files[ $relative_path ] );
			}
		}

		return $files;
	}

	/**
	 * Delete old plugin-owned sidecars absent from replacement metadata.
	 *
	 * @param array $previous Previous attachment metadata.
	 * @param array $next     Replacement attachment metadata.
	 * @return void
	 */
	private function delete_unreferenced_sidecars( array $previous, array $next ): void {
		$previous_paths = $this->metadata_sidecar_paths( $previous );
		$next_paths     = array_fill_keys( $this->metadata_sidecar_paths( $next ), true );

		foreach ( $previous_paths as $relative_path ) {
			if ( isset( $next_paths[ $relative_path ] ) ) {
				continue;
			}

			$this->delete_plugin_owned_sidecar( $relative_path );
		}
	}

	/**
	 * Flatten plugin sidecar paths from attachment metadata.
	 *
	 * @param array $metadata Attachment metadata.
	 * @return array
	 */
	private function metadata_sidecar_paths( array $metadata ): array {
		$files = isset( $metadata[ self::METADATA_KEY ]['files'] ) && is_array( $metadata[ self::METADATA_KEY ]['files'] )
			? $metadata[ self::METADATA_KEY ]['files']
			: array();
		$paths = array();

		foreach ( $files as $formats ) {
			if ( ! is_array( $formats ) ) {
				continue;
			}

			foreach ( $formats as $relative_path ) {
				if ( is_string( $relative_path ) && '' !== $relative_path ) {
					$paths[ $relative_path ] = $relative_path;
				}
			}
		}

		return array_values( $paths );
	}

	/**
	 * Safely delete one sidecar inside the plugin-owned attachment directory.
	 *
	 * @param string $relative_path Upload-relative sidecar path.
	 * @return void
	 */
	private function delete_plugin_owned_sidecar( string $relative_path ): void {
		$relative_path = wp_normalize_path( $relative_path );
		$prefix        = trailingslashit( self::SIDECAR_DIRECTORY );

		if ( ! self::is_plugin_sidecar_path( $relative_path ) ) {
			return;
		}

		$uploads     = wp_get_upload_dir();
		$base        = trailingslashit( wp_normalize_path( (string) ( $uploads['basedir'] ?? '' ) ) );
		$owned_root  = $base . $prefix;
		$target      = $base . $relative_path;
		$root_real   = realpath( $owned_root );
		$target_real = realpath( $target );

		if ( false === $root_real || false === $target_real || ! is_file( $target_real ) ) {
			return;
		}

		$root_real   = trailingslashit( wp_normalize_path( $root_real ) );
		$target_real = wp_normalize_path( $target_real );

		if ( 0 !== strpos( $target_real, $root_real ) ) {
			return;
		}

		wp_delete_file( $target_real );

		if ( file_exists( $target_real ) ) {
			return;
		}

		$directory = dirname( $target_real );

		for ( $depth = 0; $depth < 2; ++$depth ) {
			$normalized_directory = trailingslashit( wp_normalize_path( $directory ) );

			if ( 0 !== strpos( $normalized_directory, $root_real ) || $normalized_directory === $root_real ) {
				break;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Removes only empty plugin-owned hash directories; failure means the directory is retained.
			if ( ! @rmdir( $directory ) ) {
				break;
			}

			$directory = dirname( $directory );
		}
	}

	/**
	 * Check if source file can be converted safely.
	 *
	 * @param string $source_path Source absolute path.
	 * @return bool
	 */
	private function source_can_be_converted( string $source_path ): bool {
		$mime = $this->source_mime( $source_path );

		return in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ), true );
	}

	/**
	 * Check source limits before the metadata hook starts synchronous encoding.
	 *
	 * @param string $source_path Source absolute path.
	 * @return bool
	 */
	private function source_is_safe_for_synchronous_conversion( string $source_path ): bool {
		if ( '' === $source_path || ! is_readable( $source_path ) ) {
			return false;
		}

		$bytes      = filesize( $source_path );
		$byte_limit = (int) apply_filters( 'empirical_responsive_images_max_attachment_source_bytes', self::MAX_SOURCE_BYTES, $source_path );

		if ( false === $bytes || $byte_limit < 1 || $bytes > $byte_limit ) {
			return false;
		}

		$dimensions = wp_getimagesize( $source_path );

		if ( ! is_array( $dimensions ) ) {
			return false;
		}

		$width       = absint( $dimensions[0] ?? 0 );
		$height      = absint( $dimensions[1] ?? 0 );
		$pixel_limit = (int) apply_filters( 'empirical_responsive_images_max_source_pixels', self::MAX_SOURCE_PIXELS, $source_path );

		return $width > 0
			&& $height > 0
			&& $pixel_limit > 0
			&& $width <= intdiv( $pixel_limit, $height );
	}

	/**
	 * Get source MIME type.
	 *
	 * @param string $source_path Source absolute path.
	 * @return string
	 */
	private function source_mime( string $source_path ): string {
		$type = wp_check_filetype( $source_path );

		return is_string( $type['type'] ) ? $type['type'] : '';
	}

	/**
	 * Check server support for a target format.
	 *
	 * @param string $format Format.
	 * @return bool
	 */
	private function supports_format( string $format ): bool {
		if ( ! function_exists( 'wp_image_editor_supports' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		return (bool) wp_image_editor_supports(
			array(
				'mime_type' => $this->mime_for_format( $format ),
			)
		);
	}

	/**
	 * Get MIME type for format.
	 *
	 * @param string $format Format.
	 * @return string
	 */
	private function mime_for_format( string $format ): string {
		return 'image/' . $format;
	}
}
