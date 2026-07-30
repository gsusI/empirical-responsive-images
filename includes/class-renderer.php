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
	 * Modern format generator.
	 *
	 * @var Format_Generator
	 */
	private $format_generator;

	/**
	 * Constructor.
	 *
	 * @param Settings              $settings         Settings.
	 * @param Observation_Store     $store            Observation store.
	 * @param Asset_Manager         $asset_manager    Asset manager.
	 * @param Format_Generator|null $format_generator Modern format generator.
	 */
	public function __construct( Settings $settings, Observation_Store $store, Asset_Manager $asset_manager, ?Format_Generator $format_generator = null ) {
		$this->settings         = $settings;
		$this->store            = $store;
		$this->asset_manager    = $asset_manager;
		$this->format_generator = $format_generator ?? new Format_Generator( $settings );
	}

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress filter signature requires $attr.
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

		$image = $this->add_attachment_target_html( $html, $attachment_id, true );

		return $this->wrap_image_html( $image, $attachment_id );
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

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
			return $this->add_asset_target_html( $html );
		}

		return $this->add_attachment_target_html( $html, $attachment_id );
	}

	/**
	 * Enhance local asset image tags in final HTML.
	 *
	 * @param string $html     Final HTML.
	 * @param bool   $fragment Whether input is an HTML fragment.
	 * @return string
	 */
	public function filter_final_html( string $html, bool $fragment = false ): string {
		if ( '' === $html || false === stripos( $html, '<img' ) || ! class_exists( 'WP_HTML_Processor' ) ) {
			return $html;
		}

		try {
			$processor = $fragment
				? \WP_HTML_Processor::create_fragment( $html )
				: \WP_HTML_Processor::create_full_parser( $html );

			if ( null === $processor ) {
				return $html;
			}

			$rewrite_modes = array();
			$rewrite_id    = 0;

			while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
				$marker                   = (string) ++$rewrite_id;
				$breadcrumbs              = $processor->get_breadcrumbs();
				$rewrite_modes[ $marker ] = in_array( 'PICTURE', $breadcrumbs, true ) ? 'picture' : 'standalone';
				$processor->set_attribute( 'data-empirical-responsive-images-rewrite-id', $marker );
			}
		} catch ( \Throwable $error ) {
			return $html;
		}

		if ( empty( $rewrite_modes ) ) {
			return $html;
		}

		$tagged = $processor->get_updated_html();
		$output = preg_replace_callback(
			'/<img\b(?=[^>]*\bdata-empirical-responsive-images-rewrite-id="[0-9]+")[^>]*>/i',
			function ( array $matches ) use ( $rewrite_modes ): string {
				if ( ! preg_match( '/\bdata-empirical-responsive-images-rewrite-id="([0-9]+)"/', $matches[0], $marker_match ) ) {
					return $matches[0];
				}

				$mode = $rewrite_modes[ $marker_match[1] ] ?? '';
				$html = $this->remove_rewrite_marker_html( $matches[0] );

				if ( false !== strpos( $html, 'data-empirical-responsive-images-picture-child' ) ) {
					return $html;
				}

				$attachment_id = $this->attachment_id_from_html( $html );

				if ( 'picture' === $mode ) {
					if ( $attachment_id > 0 ) {
						return $this->remove_learning_attributes_html( $html );
					}

					$image = $this->settings->is_asset_processing_enabled()
						? $this->enhance_asset_image_html( $html, false )
						: $html;

					return $this->remove_learning_attributes_html( $image );
				}

				if ( $attachment_id > 0 ) {
					$image = $this->add_attachment_target_html( $html, $attachment_id, true );

					return $this->wrap_image_html( $image, $attachment_id );
				}

				return $this->settings->is_asset_processing_enabled()
					? $this->enhance_asset_image_html( $html )
					: $html;
			},
			$tagged
		);

		return is_string( $output ) ? $output : $html;
	}

	/**
	 * Enhance post-content fragments when the optional full-page buffer is absent.
	 *
	 * @param string $html Content HTML.
	 * @return string
	 */
	public function filter_content_fragment( string $html ): string {
		return $this->filter_final_html( $html, true );
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

		$html = $this->mark_picture_child_html( $html );

		return '<picture class="empirical-responsive-image-picture" data-empirical-responsive-images-picture="1">' . $sources . $html . '</picture>';
	}

	/**
	 * Add srcset/sizes/picture output to one local asset image.
	 *
	 * @param string $html                  Image HTML.
	 * @param bool   $allow_picture_wrapper Whether this image may be wrapped.
	 * @return string
	 */
	private function enhance_asset_image_html( string $html, bool $allow_picture_wrapper = true ): string {
		$already_enhanced = false !== strpos( $html, 'data-empirical-responsive-images-asset' );

		if ( ! $already_enhanced ) {
			$original = new \WP_HTML_Tag_Processor( $html );

			if ( $original->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
				$author_srcset = $original->get_attribute( 'srcset' );

				if ( is_string( $author_srcset ) && '' !== trim( $author_srcset ) ) {
					return $this->remove_learning_attributes_html( $html );
				}
			}
		}

		$html = $this->add_asset_target_html( $html, $allow_picture_wrapper );

		if ( $already_enhanced || false !== strpos( $html, 'data-empirical-responsive-images-picture' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		if ( ! $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			return $html;
		}

		$src = $processor->get_attribute( 'src' );

		if ( ! is_string( $src ) || '' === $src ) {
			return $html;
		}

		if ( $this->image_has_attachment_class( (string) $processor->get_attribute( 'class' ) ) ) {
			return $html;
		}

		$candidate     = $this->store->get_asset_candidate_for_url( $src, $this->settings );
		$target_widths = is_array( $candidate['target_widths'] ?? null ) ? $candidate['target_widths'] : array();
		$slot_widths   = is_array( $candidate['slot_widths'] ?? null ) ? $candidate['slot_widths'] : array();
		$target_widths = array_values(
			array_unique(
				array_merge(
					array_map( 'absint', $target_widths ),
					$this->asset_manager->get_existing_asset_widths( $src )
				)
			)
		);

		$sources = $this->asset_manager->get_existing_asset_sources( $src, $target_widths, $slot_widths );

		if ( empty( $sources['srcset'] ) ) {
			return $html;
		}

		$processor->set_attribute( 'srcset', $sources['srcset'] );

		$target_kind   = (string) $processor->get_attribute( 'data-empirical-responsive-images-target-kind' );
		$current_sizes = (string) $processor->get_attribute( 'sizes' );

		if ( 'asset' === $target_kind && ( ! empty( $slot_widths ) || '' === trim( $current_sizes ) ) ) {
			$empirical_sizes = ! empty( $sources['sizes'] ) ? $sources['sizes'] : '100vw';
			$loading         = strtolower( (string) $processor->get_attribute( 'loading' ) );

			if ( 'lazy' === $loading && 0 !== strpos( ltrim( $empirical_sizes ), 'auto,' ) ) {
				$empirical_sizes = 'auto, ' . $empirical_sizes;
			}

			$processor->set_attribute( 'sizes', $empirical_sizes );
			$processor->set_attribute( 'data-empirical-responsive-images-sizes', 'empirical' );
		}

		if ( ! is_string( $processor->get_attribute( 'decoding' ) ) || '' === (string) $processor->get_attribute( 'decoding' ) ) {
			$processor->set_attribute( 'decoding', 'async' );
		}

		$processor->set_attribute( 'data-empirical-responsive-images-asset', '1' );
		$processor->set_attribute( 'class', $this->append_classes( (string) $processor->get_attribute( 'class' ), array( 'empirical-responsive-image', 'empirical-responsive-image--asset' ) ) );

		$updated = $processor->get_updated_html();

		if ( ! $allow_picture_wrapper || ! $this->settings->is_picture_enabled() || empty( $sources['formats'] ) || ! is_array( $sources['formats'] ) ) {
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

		$updated = $this->mark_picture_child_html( $updated );

		return '<picture class="empirical-responsive-image-picture empirical-responsive-image-picture--asset" data-empirical-responsive-images-picture="1">' . $source_html . $updated . '</picture>';
	}

	/**
	 * Add signed visitor target to one attachment image tag.
	 *
	 * @param string $html          Image HTML.
	 * @param int    $attachment_id Attachment ID.
	 * @param bool   $apply_empirical_sizes Whether standalone context is known.
	 * @return string
	 */
	private function add_attachment_target_html( string $html, int $attachment_id, bool $apply_empirical_sizes = false ): string {
		if ( $attachment_id < 1 || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		if ( $apply_empirical_sizes ) {
			$html = $this->apply_attachment_empirical_sizes_html( $html, $attachment_id );
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		if ( ! $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			return $html;
		}

		$processor->set_attribute( 'data-empirical-responsive-images-attachment-id', (string) $attachment_id );
		$processor->set_attribute( 'class', $this->append_classes( (string) $processor->get_attribute( 'class' ), array( 'empirical-responsive-image', 'empirical-responsive-image--attachment' ) ) );
		$marked_html = $processor->get_updated_html();

		if ( ! $this->settings->is_visitor_gap_learning_enabled() ) {
			return $marked_html;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( is_array( $metadata ) && ! $this->attachment_html_preserves_original_aspect_ratio( $marked_html, $metadata ) ) {
			return $this->remove_learning_attributes_html( $marked_html );
		}

		$target = Visitor_Gap_Target::token_for_attachment( $attachment_id );

		if ( '' === $target ) {
			return $marked_html;
		}

		$processor->set_attribute( 'data-empirical-responsive-images-target', $target );
		$processor->set_attribute(
			'data-empirical-responsive-images-target-kind',
			$this->responsive_sizes_are_plugin_manageable( $processor ) ? 'attachment' : 'attachment-fixed-sizes'
		);
		$resolved = Visitor_Gap_Target::resolve( $target, $this->asset_manager );
		$formats  = Visitor_Gap_Target::get_applicable_formats( $resolved, $this->asset_manager, $this->format_generator->get_required_frontend_formats() );
		$processor->set_attribute( 'data-empirical-responsive-images-required-formats', (string) wp_json_encode( $formats ) );
		$processor->set_attribute( 'data-empirical-responsive-images-maximum-width', (string) Visitor_Gap_Target::maximum_width( $resolved, $this->settings ) );

		return $processor->get_updated_html();
	}

	/**
	 * Add signed visitor target to one manageable local asset image tag.
	 *
	 * @param string $html                  Image HTML.
	 * @param bool   $allow_format_learning Whether generated format sources can be inserted.
	 * @return string
	 */
	private function add_asset_target_html( string $html, bool $allow_format_learning = true ): string {
		if ( ! $this->settings->is_visitor_gap_learning_enabled() || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		if ( ! $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			return $html;
		}

		if ( $this->image_has_attachment_class( (string) $processor->get_attribute( 'class' ) ) ) {
			return $html;
		}

		$existing_srcset = $processor->get_attribute( 'srcset' );
		$plugin_owned    = '1' === (string) $processor->get_attribute( 'data-empirical-responsive-images-asset' );

		if ( ! $plugin_owned && is_string( $existing_srcset ) && '' !== trim( $existing_srcset ) ) {
			return $this->remove_learning_attributes_html( $html );
		}

		$src = $processor->get_attribute( 'src' );

		if ( ! is_string( $src ) || '' === $src ) {
			return $html;
		}

		$target = Visitor_Gap_Target::token_for_asset( $src, $this->asset_manager );

		if ( '' === $target ) {
			return $html;
		}

		$processor->set_attribute( 'data-empirical-responsive-images-target', $target );
		$processor->set_attribute(
			'data-empirical-responsive-images-target-kind',
			$this->responsive_sizes_are_plugin_manageable( $processor ) ? 'asset' : 'asset-fixed-sizes'
		);
		$resolved = Visitor_Gap_Target::resolve( $target, $this->asset_manager );
		$formats  = $allow_format_learning
			? Visitor_Gap_Target::get_applicable_formats( $resolved, $this->asset_manager, $this->format_generator->get_required_frontend_formats() )
			: array();
		$processor->set_attribute( 'data-empirical-responsive-images-required-formats', (string) wp_json_encode( $formats ) );
		$processor->set_attribute( 'data-empirical-responsive-images-maximum-width', (string) Visitor_Gap_Target::maximum_width( $resolved, $this->settings ) );
		$processor->set_attribute( 'class', $this->append_classes( (string) $processor->get_attribute( 'class' ), array( 'empirical-responsive-image', 'empirical-responsive-image--asset' ) ) );

		return $processor->get_updated_html();
	}

	/**
	 * Apply a bounded empirical sizes fallback to one standalone attachment.
	 *
	 * Lazy images retain the standards-based auto keyword, which lets supporting
	 * browsers use the actual layout slot even when CSS uses %, vh, or calc().
	 *
	 * @param string $html          Image HTML.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	private function apply_attachment_empirical_sizes_html( string $html, int $attachment_id ): string {
		$slot_widths = $this->store->get_attachment_slot_widths( $attachment_id );

		if ( empty( $slot_widths ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		if ( ! $processor->next_tag( array( 'tag_name' => 'IMG' ) ) || ! $this->responsive_sizes_are_plugin_manageable( $processor ) ) {
			return $html;
		}

		$sizes   = $this->asset_manager->build_empirical_sizes( $slot_widths );
		$loading = strtolower( (string) $processor->get_attribute( 'loading' ) );

		if ( 'lazy' === $loading && 0 !== strpos( ltrim( $sizes ), 'auto,' ) ) {
			$sizes = 'auto, ' . $sizes;
		}

		$processor->set_attribute( 'sizes', $sizes );
		$processor->set_attribute( 'data-empirical-responsive-images-sizes', 'empirical' );

		return $processor->get_updated_html();
	}

	/**
	 * Check whether plugin learning may replace an image's sizes fallback.
	 *
	 * Empty, plugin-owned, and standard WordPress default values are safe.
	 * Bespoke author sizes remain authoritative.
	 *
	 * @param \WP_HTML_Tag_Processor $processor Positioned image processor.
	 * @return bool
	 */
	private function responsive_sizes_are_plugin_manageable( \WP_HTML_Tag_Processor $processor ): bool {
		if ( 'empirical' === (string) $processor->get_attribute( 'data-empirical-responsive-images-sizes' ) ) {
			return true;
		}

		$sizes = trim( (string) $processor->get_attribute( 'sizes' ) );

		if ( '' === $sizes || '100vw' === strtolower( $sizes ) ) {
			return true;
		}

		return 1 === preg_match(
			'/^(?:auto,\s*)?\(max-width:\s*[0-9]+px\)\s*100vw,\s*[0-9]+px$/i',
			$sizes
		);
	}

	/**
	 * Mark an image as the fallback child of a plugin-owned picture.
	 *
	 * @param string $html Image HTML.
	 * @return string
	 */
	private function mark_picture_child_html( string $html ): string {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		if ( ! $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			return $html;
		}

		$processor->set_attribute( 'data-empirical-responsive-images-picture-child', '1' );

		return $processor->get_updated_html();
	}

	/**
	 * Remove the request-local safe-rewrite marker.
	 *
	 * @param string $html Image HTML.
	 * @return string
	 */
	private function remove_rewrite_marker_html( string $html ): string {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		if ( ! $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			return $html;
		}

		$processor->remove_attribute( 'data-empirical-responsive-images-rewrite-id' );

		return $processor->get_updated_html();
	}

	/**
	 * Remove learning attributes from an ineligible attachment rendition.
	 *
	 * @param string $html Image HTML.
	 * @return string
	 */
	private function remove_learning_attributes_html( string $html ): string {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		if ( ! $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			return $html;
		}

		foreach ( array( 'data-empirical-responsive-images-target', 'data-empirical-responsive-images-target-kind', 'data-empirical-responsive-images-required-formats', 'data-empirical-responsive-images-maximum-width' ) as $attribute ) {
			$processor->remove_attribute( $attribute );
		}

		return $processor->get_updated_html();
	}

	/**
	 * Check that a rendered attachment source has the original aspect ratio.
	 *
	 * Cropped renditions are left to their author-owned crop pipeline. Signing
	 * them as the uncropped attachment would generate unusable derivatives.
	 *
	 * @param string $html     Image HTML.
	 * @param array  $metadata Attachment metadata.
	 * @return bool
	 */
	private function attachment_html_preserves_original_aspect_ratio( string $html, array $metadata ): bool {
		$image = $this->extract_image_attributes( $html );

		if ( '' !== $image['src'] ) {
			$relative = $this->relative_upload_path_from_url( $image['src'] );

			if ( '' !== $relative ) {
				return $this->metadata_source_preserves_aspect_ratio( $relative, $metadata );
			}
		}

		$width         = absint( $image['width'] ?? 0 );
		$height        = absint( $image['height'] ?? 0 );
		$source_width  = absint( $metadata['width'] ?? 0 );
		$source_height = absint( $metadata['height'] ?? 0 );

		return 0 === $width || 0 === $height || $this->metadata_size_preserves_aspect_ratio(
			array(
				'width'  => $width,
				'height' => $height,
			),
			$source_width,
			$source_height
		);
	}

	/**
	 * Extract an attachment ID from WordPress image classes.
	 *
	 * @param string $html Image HTML.
	 * @return int
	 */
	private function attachment_id_from_html( string $html ): int {
		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$processor = new \WP_HTML_Tag_Processor( $html );

			if ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
				$marked_id = absint( $processor->get_attribute( 'data-empirical-responsive-images-attachment-id' ) );

				if ( $marked_id > 0 && wp_attachment_is_image( $marked_id ) ) {
					return $marked_id;
				}
			}
		}

		$classes = $this->image_class_from_html( $html );
		return preg_match( '/(?:^|\s)wp-image-([0-9]+)(?:\s|$)/', $classes, $matches )
			? absint( $matches[1] )
			: 0;
	}

	/**
	 * Extract an image class list.
	 *
	 * @param string $html Image HTML.
	 * @return string
	 */
	private function image_class_from_html( string $html ): string {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return '';
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		if ( ! $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			return '';
		}

		return (string) $processor->get_attribute( 'class' );
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
			'width'  => '',
			'height' => '',
		);

		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $attributes;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		if ( ! $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
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

		$srcset_parts = preg_split( '/,\s*/', trim( $srcset ) );
		$srcset_parts = is_array( $srcset_parts ) ? $srcset_parts : array();

		foreach ( $srcset_parts as $part ) {
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

		$uploads     = wp_get_upload_dir();
		$source_path = trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) . ltrim( wp_normalize_path( $relative_path ), '/' );

		if ( ! empty( $files[ $relative_path ][ $format ] ) && is_string( $files[ $relative_path ][ $format ] ) ) {
			$mapped_relative = wp_normalize_path( $files[ $relative_path ][ $format ] );
			$mapped_path     = trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) . ltrim( $mapped_relative, '/' );

			if ( Format_Generator::is_plugin_sidecar_path( $mapped_relative ) && Visitor_Gap_Target::is_current_file( $source_path, $mapped_path ) ) {
				return $this->upload_url_for_relative_path( $mapped_relative );
			}
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

		if ( wp_normalize_path( $relative_path ) === $main_file ) {
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
