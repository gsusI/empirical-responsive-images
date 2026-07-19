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
	 * Constructor.
	 *
	 * @param Settings          $settings Settings.
	 * @param Observation_Store $store Observation store.
	 */
	public function __construct( Settings $settings, Observation_Store $store ) {
		$this->settings = $settings;
		$this->store    = $store;
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
		foreach ( $this->store->get_registered_candidates( $this->settings ) as $candidate ) {
			$width = absint( $candidate['width'] ?? 0 );

			if ( $width < 1 ) {
				continue;
			}

			add_image_size( self::name_for_width( $width ), $width, 0, false );
		}
	}

	/**
	 * Enqueue front-end observer.
	 *
	 * @return void
	 */
	public function enqueue_observer(): void {
		if ( is_admin() || is_feed() || ! current_user_can( 'manage_options' ) || ! $this->settings->is_collection_enabled() ) {
			return;
		}

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
		if ( 'empirical-responsive-images-observer' !== $handle || ! current_user_can( 'manage_options' ) ) {
			return $tag;
		}

		$attributes     = array(
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
		$attribute_html = '';

		foreach ( $attributes as $name => $value ) {
			$attribute_html .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}

		return str_replace( '<script ', '<script' . $attribute_html . ' ', $tag );
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

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
