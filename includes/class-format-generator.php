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
	public const METADATA_KEY = '_empirical_responsive_images_formats';

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
		if ( ! is_array( $metadata ) ) {
			return $metadata;
		}

		$result = $this->generate_for_metadata( $attachment_id, $metadata, false );

		return $result['metadata'];
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
	 * Generate alternate files for all metadata sources.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $metadata      Metadata.
	 * @param bool  $force         Whether to overwrite existing sidecars.
	 * @return array
	 */
	private function generate_for_metadata( int $attachment_id, array $metadata, bool $force ): array {
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
		$sources = $this->get_metadata_sources( $metadata );

		if ( empty( $metadata[ self::METADATA_KEY ] ) || ! is_array( $metadata[ self::METADATA_KEY ] ) ) {
			$metadata[ self::METADATA_KEY ] = array(
				'generated_at' => 0,
				'files'        => array(),
			);
		}
		$metadata[ self::METADATA_KEY ]['files'] = $this->filter_metadata_files_to_sources(
			is_array( $metadata[ self::METADATA_KEY ]['files'] ?? null ) ? $metadata[ self::METADATA_KEY ]['files'] : array(),
			$sources
		);

		$generated = 0;
		$skipped   = 0;
		$errors    = array();

		foreach ( $sources as $relative_path ) {
			$source_path = $base . $relative_path;

			if ( ! is_readable( $source_path ) || ! $this->source_can_be_converted( $source_path ) ) {
				++$skipped;
				continue;
			}

			foreach ( $formats as $format ) {
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
		$target_rel    = preg_replace( '/\.[^.]+$/', '.' . $format, $relative_path );
		$target_rel    = is_string( $target_rel ) ? $target_rel : $relative_path . '.' . $format;
		$target_path   = trailingslashit( $uploads['basedir'] ) . $target_rel;
		$target_folder = dirname( $target_path );

		if ( file_exists( $target_path ) && ! $force ) {
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
	 * @param array $metadata Attachment metadata.
	 * @return array
	 */
	private function get_metadata_sources( array $metadata ): array {
		if ( empty( $metadata['file'] ) || ! is_string( $metadata['file'] ) ) {
			return array();
		}

		$directory = dirname( $metadata['file'] );
		$directory = '.' === $directory ? '' : trailingslashit( $directory );
		$sources   = array( $metadata['file'] );
		$source_width = absint( $metadata['width'] ?? 0 );
		$source_height = absint( $metadata['height'] ?? 0 );

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! is_array( $size ) || empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
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
	 * Check if source file can be converted safely.
	 *
	 * @param string $source_path Source absolute path.
	 * @return bool
	 */
	private function source_can_be_converted( string $source_path ): bool {
		$type = wp_check_filetype( $source_path );
		$mime = isset( $type['type'] ) ? (string) $type['type'] : '';

		return in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ), true );
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
