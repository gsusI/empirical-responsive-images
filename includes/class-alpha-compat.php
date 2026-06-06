<?php
/**
 * Alpha-channel compatibility checks.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps transparent sources out of formats that flatten alpha in the active stack.
 */
final class Alpha_Compat {
	/**
	 * Check whether a source can be emitted in a target format without alpha loss.
	 *
	 * @param string $source_path Source absolute path.
	 * @param string $format      Target file extension.
	 * @return bool
	 */
	public static function can_use_format_for_source( string $source_path, string $format ): bool {
		if ( ! self::source_needs_alpha_preservation( $source_path ) ) {
			return true;
		}

		return (bool) apply_filters(
			'empirical_responsive_images_format_preserves_alpha',
			self::format_preserves_alpha_by_default( $format ),
			$format,
			$source_path
		);
	}

	/**
	 * Check whether the source has transparency that must survive conversion.
	 *
	 * @param string $source_path Source absolute path.
	 * @return bool
	 */
	private static function source_needs_alpha_preservation( string $source_path ): bool {
		$type = wp_check_filetype( $source_path );
		$mime = isset( $type['type'] ) ? (string) $type['type'] : '';

		if ( 'image/png' === $mime ) {
			return self::png_has_alpha_channel( $source_path );
		}

		if ( in_array( $mime, array( 'image/webp', 'image/avif' ), true ) ) {
			return self::imagick_reports_alpha_channel( $source_path );
		}

		return false;
	}

	/**
	 * Conservative default for formats safely emitted by WordPress image editors.
	 *
	 * @param string $format Target file extension.
	 * @return bool
	 */
	private static function format_preserves_alpha_by_default( string $format ): bool {
		return in_array( sanitize_key( $format ), array( 'png', 'webp', 'gif' ), true );
	}

	/**
	 * Read cheap PNG header/chunk data for alpha support.
	 *
	 * @param string $source_path Source absolute path.
	 * @return bool
	 */
	private static function png_has_alpha_channel( string $source_path ): bool {
		$header = file_get_contents( $source_path, false, null, 0, 33 );

		if ( ! is_string( $header ) || strlen( $header ) < 26 || "\x89PNG\r\n\x1a\n" !== substr( $header, 0, 8 ) ) {
			return false;
		}

		$color_type = ord( $header[25] );

		if ( in_array( $color_type, array( 4, 6 ), true ) ) {
			return true;
		}

		$chunk_scan = file_get_contents( $source_path, false, null, 0, 1048576 );

		return is_string( $chunk_scan ) && false !== strpos( $chunk_scan, 'tRNS' );
	}

	/**
	 * Fallback alpha detection for modern source formats.
	 *
	 * @param string $source_path Source absolute path.
	 * @return bool
	 */
	private static function imagick_reports_alpha_channel( string $source_path ): bool {
		if ( ! class_exists( '\Imagick' ) ) {
			return false;
		}

		try {
			$image = new \Imagick( $source_path );

			if ( method_exists( $image, 'setIteratorIndex' ) ) {
				$image->setIteratorIndex( 0 );
			}

			if ( method_exists( $image, 'getImageAlphaChannel' ) && ! $image->getImageAlphaChannel() ) {
				$image->clear();
				$image->destroy();

				return false;
			}

			if ( method_exists( $image, 'getImageChannelExtrema' ) ) {
				$extrema = $image->getImageChannelExtrema( \Imagick::CHANNEL_ALPHA );
				$image->clear();
				$image->destroy();

				if ( is_array( $extrema ) && isset( $extrema['minima'], $extrema['maxima'] ) ) {
					return (int) $extrema['minima'] < (int) $extrema['maxima'];
				}

				return true;
			}

			$image->clear();
			$image->destroy();
		} catch ( \Throwable $exception ) {
			return false;
		}

		return true;
	}
}
