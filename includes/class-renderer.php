<?php
/**
 * Front-end picture renderer.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps WordPress images with modern format source sets.
 */
final class Renderer {
	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Format generator.
	 *
	 * @var Format_Generator
	 */
	private $format_generator;

	/**
	 * Observation store.
	 *
	 * @var Observation_Store
	 */
	private $store;

	/**
	 * Asset manager.
	 *
	 * @var Asset_Manager
	 */
	private $asset_manager;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Settings.
	 * @param Format_Generator  $format_generator Format generator.
	 * @param Observation_Store $store Observation store.
	 * @param Asset_Manager     $asset_manager Asset manager.
	 */
	public function __construct( Settings $settings, Format_Generator $format_generator, Observation_Store $store, Asset_Manager $asset_manager ) {
		$this->settings         = $settings;
		$this->format_generator = $format_generator;
		$this->store            = $store;
		$this->asset_manager    = $asset_manager;
	}

	/**
	 * Filter WP-generated attachment image HTML.
	 *
	 * @param string $html          Image HTML.
	 * @param int    $attachment_id Attachment ID.
	 * @param mixed  $size          Size.
	 * @param bool   $icon          Icon mode.
	 * @param mixed  $attr          Attributes.
	 * @return string
	 */
	public function filter_attachment_image_html( string $html, int $attachment_id, $size, bool $icon, $attr ): string {
		if ( $icon ) {
			return $html;
		}

		return $this->wrap_image_html( $html, $attachment_id );
	}

	/**
	 * Filter content image HTML.
	 *
	 * @param string $html          Image HTML.
	 * @param string $context       Content context.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public function filter_content_image_html( string $html, string $context, int $attachment_id ): string {
		if ( $attachment_id < 1 ) {
			return $html;
		}

		return $this->wrap_image_html( $html, $attachment_id );
	}

	/**
	 * Enhance local asset image tags in final HTML.
	 *
	 * @param string $html Final HTML.
	 * @return string
	 */
	public function filter_final_html( string $html ): string {
		if ( ! $this->settings->is_asset_processing_enabled() || '' === $html || false === stripos( $html, '<img' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$original  = $html;
		$protected = array();
		$html      = preg_replace_callback(
			'/<picture\b[^>]*>.*?<\/picture>/is',
			static function ( array $matches ) use ( &$protected ): string {
				$key               = '<!--eri-picture-' . count( $protected ) . '-->';
				$protected[ $key ] = $matches[0];

				return $key;
			},
			$html
		);

		if ( ! is_string( $html ) ) {
			return $original;
		}

		$html = preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( array $matches ): string {
				return $this->enhance_asset_image_html( $matches[0] );
			},
			$html
		);

		if ( ! is_string( $html ) ) {
			return $original;
		}

		if ( ! empty( $protected ) ) {
			$html = strtr( $html, $protected );
		}

		return $html;
	}

	/**
	 * Wrap image HTML with picture sources when alternates exist.
	 *
	 * @param string $html          Image HTML.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	private function wrap_image_html( string $html, int $attachment_id ): string {
		if ( is_admin() || ! $this->settings->is_picture_enabled() || false !== strpos( $html, '<picture' ) || false !== strpos( $html, 'data-empirical-responsive-images-picture' ) ) {
			return $html;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $metadata ) || empty( $metadata[ Format_Generator::METADATA_KEY ] ) ) {
			return $html;
		}

		$image = $this->extract_image_attributes( $html );

		if ( empty( $image['src'] ) ) {
			return $html;
		}

		$sources = '';

		foreach ( $this->settings->get_enabled_formats() as $format ) {
			$srcset = $this->build_alternate_srcset( $image, $metadata, $format );

			if ( '' === $srcset ) {
				continue;
			}

			$sizes_attr = '' !== $image['sizes'] ? ' sizes="' . esc_attr( $image['sizes'] ) . '"' : '';
			$sources   .= '<source class="empirical-responsive-image-picture__source empirical-responsive-image-picture__source--' . esc_attr( $format ) . '" type="' . esc_attr( 'image/' . $format ) . '" srcset="' . esc_attr( $srcset ) . '"' . $sizes_attr . '>';
		}

		if ( '' === $sources ) {
			return $html;
		}

		return '<picture class="empirical-responsive-image-picture" data-empirical-responsive-images-picture="1">' . $sources . $html . '</picture>';
	}

	/**
	 * Add srcset/sizes/picture output to one local asset image.
	 *
	 * @param string $html Image HTML.
	 * @return string
	 */
	private function enhance_asset_image_html( string $html ): string {
		if ( false !== strpos( $html, 'data-empirical-responsive-images-asset' ) || false !== strpos( $html, 'data-empirical-responsive-images-picture' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		if ( ! $processor->next_tag( 'IMG' ) ) {
			return $html;
		}

		$src = $processor->get_attribute( 'src' );

		if ( ! is_string( $src ) || '' === $src ) {
			return $html;
		}

		if ( $this->image_has_attachment_class( (string) $processor->get_attribute( 'class' ) ) ) {
			return $html;
		}

		$candidate = $this->store->get_asset_candidate_for_url( $src, $this->settings );

		if ( empty( $candidate['target_widths'] ) ) {
			return $html;
		}

		$sources = $this->asset_manager->build_asset_sources( $src, $candidate['target_widths'], is_array( $candidate['slot_widths'] ?? null ) ? $candidate['slot_widths'] : array(), false );

		if ( empty( $sources['srcset'] ) ) {
			return $html;
		}

		$processor->set_attribute( 'srcset', $sources['srcset'] );

		if ( ! is_string( $processor->get_attribute( 'sizes' ) ) || '' === (string) $processor->get_attribute( 'sizes' ) ) {
			$processor->set_attribute( 'sizes', ! empty( $sources['sizes'] ) ? $sources['sizes'] : '100vw' );
		}

		if ( ! is_string( $processor->get_attribute( 'decoding' ) ) || '' === (string) $processor->get_attribute( 'decoding' ) ) {
			$processor->set_attribute( 'decoding', 'async' );
		}

		$processor->set_attribute( 'data-empirical-responsive-images-asset', '1' );
		$processor->set_attribute( 'class', $this->append_classes( (string) $processor->get_attribute( 'class' ), array( 'empirical-responsive-image', 'empirical-responsive-image--asset' ) ) );

		$updated = $processor->get_updated_html();

		if ( ! $this->settings->is_picture_enabled() || empty( $sources['formats'] ) || ! is_array( $sources['formats'] ) ) {
			return $updated;
		}

		$source_html = '';
		$sizes_attr  = $this->extract_image_attributes( $updated )['sizes'];

		foreach ( $this->settings->get_enabled_formats() as $format ) {
			if ( empty( $sources['formats'][ $format ] ) ) {
				continue;
			}

			$source_html .= '<source class="empirical-responsive-image-picture__source empirical-responsive-image-picture__source--' . esc_attr( $format ) . '" type="' . esc_attr( 'image/' . $format ) . '" srcset="' . esc_attr( $sources['formats'][ $format ] ) . '" sizes="' . esc_attr( $sizes_attr ) . '">';
		}

		if ( '' === $source_html ) {
			return $updated;
		}

		return '<picture class="empirical-responsive-image-picture empirical-responsive-image-picture--asset" data-empirical-responsive-images-picture="1">' . $source_html . $updated . '</picture>';
	}

	/**
	 * Extract src, srcset, and sizes from image HTML.
	 *
	 * @param string $html Image HTML.
	 * @return array
	 */
	private function extract_image_attributes( string $html ): array {
		$attributes = array(
			'src'    => '',
			'srcset' => '',
			'sizes'  => '',
		);

		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $attributes;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		if ( ! $processor->next_tag( 'IMG' ) ) {
			return $attributes;
		}

		foreach ( array_keys( $attributes ) as $name ) {
			$value = $processor->get_attribute( $name );

			if ( is_string( $value ) ) {
				$attributes[ $name ] = $value;
			}
		}

		return $attributes;
	}

	/**
	 * Check if class list identifies a WordPress attachment image.
	 *
	 * @param string $classes Class attribute.
	 * @return bool
	 */
	private function image_has_attachment_class( string $classes ): bool {
		return (bool) preg_match( '/(?:^|\s)wp-image-[0-9]+(?:\s|$)/', $classes );
	}

	/**
	 * Append classes without duplicates.
	 *
	 * @param string $classes Existing classes.
	 * @param array  $extra   Extra classes.
	 * @return string
	 */
	private function append_classes( string $classes, array $extra ): string {
		$all = preg_split( '/\s+/', trim( $classes ) );
		$all = is_array( $all ) ? array_filter( $all ) : array();
		$all = array_merge( $all, $extra );

		return implode( ' ', array_unique( array_map( 'sanitize_html_class', $all ) ) );
	}

	/**
	 * Build one alternate srcset.
	 *
	 * @param array  $image    Image attributes.
	 * @param array  $metadata Attachment metadata.
	 * @param string $format   Target format.
	 * @return string
	 */
	private function build_alternate_srcset( array $image, array $metadata, string $format ): string {
		$items = $this->parse_srcset( $image['srcset'] );

		if ( empty( $items ) ) {
			$width = absint( $metadata['width'] ?? 0 );
			$items = array(
				array(
					'url'        => $image['src'],
					'descriptor' => $width > 0 ? $width . 'w' : '',
				),
			);
		}

		$output = array();

		foreach ( $items as $item ) {
			$alternate_url = $this->alternate_url_for_upload_url( $item['url'], $metadata, $format );

			if ( '' === $alternate_url ) {
				continue;
			}

			$output[] = trim( $alternate_url . ' ' . $item['descriptor'] );
		}

		return implode( ', ', array_unique( $output ) );
	}

	/**
	 * Parse basic WordPress srcset syntax.
	 *
	 * @param string $srcset Srcset.
	 * @return array
	 */
	private function parse_srcset( string $srcset ): array {
		$items = array();

		foreach ( preg_split( '/,\s*/', trim( $srcset ) ) ?: array() as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				continue;
			}

			if ( preg_match( '/^(.+?)\s+([0-9.]+[wx])$/', $part, $matches ) ) {
				$items[] = array(
					'url'        => $matches[1],
					'descriptor' => $matches[2],
				);
			} else {
				$items[] = array(
					'url'        => $part,
					'descriptor' => '',
				);
			}
		}

		return $items;
	}

	/**
	 * Convert an upload URL to its alternate format URL.
	 *
	 * @param string $url      Source URL.
	 * @param array  $metadata Attachment metadata.
	 * @param string $format   Target format.
	 * @return string
	 */
	private function alternate_url_for_upload_url( string $url, array $metadata, string $format ): string {
		$relative_path = $this->relative_upload_path_from_url( $url );

		if ( '' === $relative_path ) {
			return '';
		}

		if ( ! $this->metadata_source_preserves_aspect_ratio( $relative_path, $metadata ) ) {
			return '';
		}

		$files = isset( $metadata[ Format_Generator::METADATA_KEY ]['files'] ) && is_array( $metadata[ Format_Generator::METADATA_KEY ]['files'] ) ? $metadata[ Format_Generator::METADATA_KEY ]['files'] : array();

		if ( ! empty( $files[ $relative_path ][ $format ] ) && is_string( $files[ $relative_path ][ $format ] ) ) {
			return $this->upload_url_for_relative_path( $files[ $relative_path ][ $format ] );
		}

		$computed = preg_replace( '/\.[^.]+$/', '.' . $format, $relative_path );
		$computed = is_string( $computed ) ? $computed : '';
		$uploads  = wp_get_upload_dir();

		if ( '' !== $computed && file_exists( trailingslashit( $uploads['basedir'] ) . $computed ) ) {
			return $this->upload_url_for_relative_path( $computed );
		}

		return '';
	}

	/**
	 * Check whether an upload-relative source keeps original aspect ratio.
	 *
	 * @param string $relative_path Upload-relative image path.
	 * @param array  $metadata      Attachment metadata.
	 * @return bool
	 */
	private function metadata_source_preserves_aspect_ratio( string $relative_path, array $metadata ): bool {
		$main_file = isset( $metadata['file'] ) && is_string( $metadata['file'] ) ? wp_normalize_path( $metadata['file'] ) : '';

		if ( $main_file === wp_normalize_path( $relative_path ) ) {
			return true;
		}

		$source_width  = absint( $metadata['width'] ?? 0 );
		$source_height = absint( $metadata['height'] ?? 0 );
		$directory     = dirname( $main_file );
		$directory     = '.' === $directory ? '' : trailingslashit( $directory );

		if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return false;
		}

		foreach ( $metadata['sizes'] as $size ) {
			if ( ! is_array( $size ) || empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
				continue;
			}

			if ( wp_normalize_path( $directory . $size['file'] ) !== wp_normalize_path( $relative_path ) ) {
				continue;
			}

			return $this->metadata_size_preserves_aspect_ratio( $size, $source_width, $source_height );
		}

		return false;
	}

	/**
	 * Check whether a metadata size keeps original aspect ratio.
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
	 * Map upload URL to upload-relative path.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function relative_upload_path_from_url( string $url ): string {
		$uploads   = wp_get_upload_dir();
		$url_path  = wp_parse_url( $url, PHP_URL_PATH );
		$base_path = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );

		if ( ! is_string( $url_path ) ) {
			return '';
		}

		$url_path  = rawurldecode( $url_path );
		$base_path = is_string( $base_path ) ? rawurldecode( $base_path ) : '';
		$base_path = trailingslashit( $base_path );

		if ( '' !== $base_path && 0 === strpos( $url_path, $base_path ) ) {
			return ltrim( substr( $url_path, strlen( $base_path ) ), '/' );
		}

		return '';
	}

	/**
	 * Build upload URL for a relative file.
	 *
	 * @param string $relative_path Upload-relative path.
	 * @return string
	 */
	private function upload_url_for_relative_path( string $relative_path ): string {
		$uploads = wp_get_upload_dir();

		return esc_url_raw( trailingslashit( $uploads['baseurl'] ) . ltrim( $relative_path, '/' ) );
	}
}
