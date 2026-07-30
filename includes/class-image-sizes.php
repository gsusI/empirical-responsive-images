<?php
/**
 * WordPress image size registration and observer assets.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers observed sizes and enqueues measurement.
 */
final class Image_Sizes {
	private const MAX_LEGACY_GLOBAL_SIZES = 6;

	/**
	 * Settings service.
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
	 * @param Format_Generator|null $format_generator Modern format generator.
	 */
	public function __construct( Settings $settings, Observation_Store $store, ?Format_Generator $format_generator = null ) {
		$this->settings         = $settings;
		$this->store            = $store;
		$this->format_generator = $format_generator ?? new Format_Generator( $settings );
	}

	/**
	 * Get deterministic WordPress image size name.
	 *
	 * @param int $width Width.
	 * @return string
	 */
	public static function name_for_width( int $width ): string {
		return 'empirical-responsive-images-' . absint( $width );
	}

	/**
	 * Register observed width candidates as WordPress image sizes.
	 *
	 * @return void
	 */
	public function register_observed_image_sizes(): void {
		/*
		 * Zero-touch learning creates only target-specific files in its bounded
		 * worker. Registering every observed width globally would multiply work
		 * for every future upload, including images that never use those slots.
		 */
		if ( $this->settings->is_visitor_gap_learning_enabled() ) {
			return;
		}

		$candidates = $this->store->get_registered_candidates( $this->settings );
		$candidates = array_slice( $candidates, 0, self::MAX_LEGACY_GLOBAL_SIZES );

		foreach ( $candidates as $candidate ) {
			$width = absint( $candidate['width'] ?? 0 );

			if ( $width < 1 ) {
				continue;
			}

			add_image_size( self::name_for_width( $width ), $width, 0, false );
		}
	}

	/**
	 * Enqueue authorized observer or lightweight visitor gap detector.
	 *
	 * @return void
	 */
	public function enqueue_observer(): void {
		if ( is_admin() || is_feed() ) {
			return;
		}

		if ( $this->settings->is_visitor_gap_learning_enabled() ) {
			$this->enqueue_visitor_gap_observer();
		}

		if ( current_user_can( 'manage_options' ) && $this->settings->is_collection_enabled() ) {
			$this->enqueue_authorized_observer();
		}
	}

	/**
	 * Enqueue full administrator-authorized observer.
	 *
	 * @return void
	 */
	private function enqueue_authorized_observer(): void {
		$handle = 'empirical-responsive-images-observer';

		wp_enqueue_script(
			$handle,
			EMPIRICAL_RESPONSIVE_IMAGES_URL . 'assets/js/observer.js',
			array(),
			EMPIRICAL_RESPONSIVE_IMAGES_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'EmpiricalResponsiveImagesObserver',
			array(
				'endpoint'            => esc_url_raw( rest_url( 'empirical-responsive-images/v1/observations' ) ),
				'nonce'               => wp_create_nonce( 'wp_rest' ),
				'sampleRate'          => $this->settings->get_float( 'sample_rate' ),
				'minRenderedWidth'    => $this->settings->get_int( 'min_rendered_width' ),
				'maxImagesPerPayload' => $this->settings->get_int( 'max_payload_images' ),
			)
		);
	}

	/**
	 * Enqueue anonymous, non-authoritative visitor gap detector.
	 *
	 * @return void
	 */
	private function enqueue_visitor_gap_observer(): void {
		$handle = 'empirical-responsive-images-visitor-gap-observer';

		wp_enqueue_script(
			$handle,
			EMPIRICAL_RESPONSIVE_IMAGES_URL . 'assets/js/visitor-gap-observer.js',
			array(),
			EMPIRICAL_RESPONSIVE_IMAGES_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'EmpiricalResponsiveImagesVisitorGapObserver',
			array(
				'endpoint'         => esc_url_raw( rest_url( 'empirical-responsive-images/v1/visitor-gap-hints' ) ),
				'token'            => Visitor_Gap_Store::report_token(),
				'sampleRate'       => 1,
				'minRenderedWidth' => $this->settings->get_int( 'min_rendered_width' ),
				'maximumWidth'     => $this->settings->get_int( 'max_registered_width' ),
				'bucketVersion'    => Visitor_Gap_Store::BUCKET_PROTOCOL_VERSION,
				'bucketWidths'     => Visitor_Gap_Store::canonical_widths(),
				'maximumImages'    => 80,
				'maximumGaps'      => Visitor_Gap_Store::MAX_WIDTHS_PER_REPORT,
				'requiredFormats'  => $this->format_generator->get_required_frontend_formats(),
			)
		);
	}

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress filter signature requires $src.
	/**
	 * Add cache/optimizer exclusion attributes and fallback config to observer script.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script source.
	 * @return string
	 */
	public function filter_observer_script_tag( string $tag, string $handle, string $src ): string {
		if ( 'empirical-responsive-images-visitor-gap-observer' === $handle ) {
			if ( ! $this->settings->is_visitor_gap_learning_enabled() ) {
				return $tag;
			}

			$attributes = array(
				'data-cfasync'            => 'false',
				'data-no-defer'           => '1',
				'data-no-minify'          => '1',
				'data-no-optimize'        => '1',
				'data-pagespeed-no-defer' => '1',
				'data-empirical-responsive-images-visitor-gap-endpoint' => esc_url_raw( rest_url( 'empirical-responsive-images/v1/visitor-gap-hints' ) ),
				'data-empirical-responsive-images-visitor-gap-token' => Visitor_Gap_Store::report_token(),
				'data-empirical-responsive-images-visitor-gap-sample-rate' => '1',
				'data-empirical-responsive-images-visitor-gap-min-width' => (string) $this->settings->get_int( 'min_rendered_width' ),
				'data-empirical-responsive-images-visitor-gap-max-width' => (string) $this->settings->get_int( 'max_registered_width' ),
				'data-empirical-responsive-images-visitor-gap-bucket-version' => (string) Visitor_Gap_Store::BUCKET_PROTOCOL_VERSION,
				'data-empirical-responsive-images-visitor-gap-bucket-widths' => wp_json_encode( Visitor_Gap_Store::canonical_widths() ),
				'data-empirical-responsive-images-visitor-gap-max-images' => '80',
				'data-empirical-responsive-images-visitor-gap-max-gaps' => (string) Visitor_Gap_Store::MAX_WIDTHS_PER_REPORT,
				'data-empirical-responsive-images-visitor-gap-required-formats' => wp_json_encode( $this->format_generator->get_required_frontend_formats() ),
			);

			return $this->add_script_attributes( $tag, $attributes );
		}

		if ( 'empirical-responsive-images-observer' !== $handle || ! current_user_can( 'manage_options' ) ) {
			return $tag;
		}

		$attributes = array(
			'data-cfasync'                                 => 'false',
			'data-no-defer'                                => '1',
			'data-no-minify'                               => '1',
			'data-no-optimize'                             => '1',
			'data-pagespeed-no-defer'                      => '1',
			'data-empirical-responsive-images-endpoint'    => esc_url_raw( rest_url( 'empirical-responsive-images/v1/observations' ) ),
			'data-empirical-responsive-images-nonce'       => wp_create_nonce( 'wp_rest' ),
			'data-empirical-responsive-images-sample-rate' => (string) $this->settings->get_float( 'sample_rate' ),
			'data-empirical-responsive-images-min-rendered-width' => (string) $this->settings->get_int( 'min_rendered_width' ),
			'data-empirical-responsive-images-max-images'  => (string) $this->settings->get_int( 'max_payload_images' ),
		);
		return $this->add_script_attributes( $tag, $attributes );
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Add optimizer-bypass and fallback configuration attributes.
	 *
	 * @param string $tag        Script tag.
	 * @param array  $attributes Attributes.
	 * @return string
	 */
	private function add_script_attributes( string $tag, array $attributes ): string {
		$attribute_html = '';

		foreach ( $attributes as $name => $value ) {
			$attribute_html .= ' ' . esc_attr( $name ) . '="' . esc_attr( (string) $value ) . '"';
		}

		return str_replace( '<script ', '<script' . $attribute_html . ' ', $tag );
	}

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress filter signature requires attachment and size.
	/**
	 * Add useful defaults to WordPress-generated image attributes.
	 *
	 * @param array    $attr       Attributes.
	 * @param \WP_Post $attachment Attachment post.
	 * @param mixed    $size       Requested size.
	 * @return array
	 */
	public function filter_attachment_attributes( array $attr, \WP_Post $attachment, $size ): array {
		$classes = isset( $attr['class'] ) ? preg_split( '/\s+/', (string) $attr['class'] ) : array();
		$classes = is_array( $classes ) ? array_filter( $classes ) : array();

		$classes[]     = 'empirical-responsive-image';
		$attr['class'] = implode( ' ', array_unique( $classes ) );

		if ( empty( $attr['decoding'] ) ) {
			$attr['decoding'] = 'async';
		}

		return $attr;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
}
