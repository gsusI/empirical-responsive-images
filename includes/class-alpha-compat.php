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
	private const MAX_REQUEST_CACHE_ENTRIES = 128;

	/**
	 * Request-local source transparency cache.
	 *
	 * @var array
	 */
	private static $source_alpha_cache = array();

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
		$mime = is_string( $type['type'] ) ? $type['type'] : '';
		$key  = wp_normalize_path( $source_path )
			. '|'
			. (string) ( is_file( $source_path ) ? filemtime( $source_path ) : 0 )
			. '|'
			. (string) ( is_file( $source_path ) ? filesize( $source_path ) : 0 );

		if ( array_key_exists( $key, self::$source_alpha_cache ) ) {
			return self::$source_alpha_cache[ $key ];
		}

		if ( 'image/png' === $mime ) {
			$needs_alpha = self::png_has_alpha_channel( $source_path );
		} elseif ( in_array( $mime, array( 'image/webp', 'image/avif' ), true ) ) {
			/*
			 * Determining alpha in modern containers normally decodes the full
			 * image. Front-end eligibility must stay cheap, so unknown modern
			 * sources are conservatively treated as transparent.
			 */
			$needs_alpha = true;
		} else {
			$needs_alpha = false;
		}

		if ( count( self::$source_alpha_cache ) >= self::MAX_REQUEST_CACHE_ENTRIES ) {
			array_shift( self::$source_alpha_cache );
		}

		self::$source_alpha_cache[ $key ] = $needs_alpha;

		return $needs_alpha;
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a bounded local binary header, not a URL.
		$header = file_get_contents( $source_path, false, null, 0, 33 );

		if ( ! is_string( $header ) || strlen( $header ) < 26 || "\x89PNG\r\n\x1a\n" !== substr( $header, 0, 8 ) ) {
			return true;
		}

		$color_type = ord( $header[25] );

		if ( in_array( $color_type, array( 4, 6 ), true ) ) {
			return true;
		}

		$file_size = filesize( $source_path );
		$offset    = 8;

		if ( false === $file_size || $file_size < 33 ) {
			return true;
		}

		/*
		 * PNG transparency chunks must precede the first image-data chunk.
		 * Read only fixed eight-byte chunk headers and seek over payloads, so a
		 * large valid ancillary chunk cannot hide tRNS beyond a byte scan limit.
		 * Unknown, malformed, or pathologically fragmented files fail closed.
		 */
		for ( $chunk_count = 0; $chunk_count < 128 && $offset + 12 <= $file_size; ++$chunk_count ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads one bounded local binary chunk header.
			$chunk_header = file_get_contents( $source_path, false, null, $offset, 8 );

			if ( ! is_string( $chunk_header ) || 8 !== strlen( $chunk_header ) ) {
				return true;
			}

			$length_data  = unpack( 'Nlength', substr( $chunk_header, 0, 4 ) );
			$chunk_length = is_array( $length_data ) ? absint( $length_data['length'] ?? 0 ) : 0;
			$chunk_type   = substr( $chunk_header, 4, 4 );
			$next_offset  = $offset + 12 + $chunk_length;

			if ( $next_offset > $file_size || $next_offset <= $offset ) {
				return true;
			}

			if ( 'tRNS' === $chunk_type ) {
				return true;
			}

			if ( in_array( $chunk_type, array( 'IDAT', 'IEND' ), true ) ) {
				return false;
			}

			$offset = $next_offset;
		}

		return true;
	}
}
