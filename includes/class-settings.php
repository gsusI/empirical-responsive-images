<?php
/**
 * Plugin settings.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and sanitizes plugin options.
 */
final class Settings {
	public const OPTION_NAME = 'empirical_responsive_images_settings';

	/**
	 * Ensure option exists.
	 *
	 * @return void
	 */
	public function ensure_option(): void {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, $this->defaults(), '', false );
		}
	}

	/**
	 * Register WordPress setting.
	 *
	 * @return void
	 */
	public function register(): void {
		register_setting(
			'empirical_responsive_images',
			self::OPTION_NAME,
			array(
				'sanitize_callback' => array( $this, 'sanitize' ),
				'type'              => 'array',
			)
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public function defaults(): array {
		return array(
			'collection_enabled'        => true,
			'cache_guard_enabled'       => true,
			'picture_enabled'           => true,
			'asset_processing_enabled'  => true,
			'webp_enabled'              => true,
			'avif_enabled'              => true,
			'cache_ready_runs'          => 1,
			'sample_rate'               => 1,
			'rounding_step'             => 32,
			'min_rendered_width'        => 1,
			'max_registered_width'      => 2560,
			'max_observed_sizes'        => 80,
			'max_payload_images'        => 40,
		);
	}

	/**
	 * Get all settings with defaults.
	 *
	 * @return array
	 */
	public function all(): array {
		$options = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $options ) ) {
			$options = array();
		}

		return wp_parse_args( $options, $this->defaults() );
	}

	/**
	 * Sanitize settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = $this->defaults();

		return array(
			'collection_enabled'        => ! empty( $input['collection_enabled'] ),
			'cache_guard_enabled'       => ! empty( $input['cache_guard_enabled'] ),
			'picture_enabled'           => ! empty( $input['picture_enabled'] ),
			'asset_processing_enabled'  => ! empty( $input['asset_processing_enabled'] ),
			'webp_enabled'              => ! empty( $input['webp_enabled'] ),
			'avif_enabled'              => ! empty( $input['avif_enabled'] ),
			'cache_ready_runs'          => $this->sanitize_int( $input['cache_ready_runs'] ?? $defaults['cache_ready_runs'], 1, 5 ),
			'sample_rate'               => $this->sanitize_float( $input['sample_rate'] ?? $defaults['sample_rate'], 0, 1 ),
			'rounding_step'             => $this->sanitize_int( $input['rounding_step'] ?? $defaults['rounding_step'], 8, 256 ),
			'min_rendered_width'        => $this->sanitize_int( $input['min_rendered_width'] ?? $defaults['min_rendered_width'], 1, 1200 ),
			'max_registered_width'      => $this->sanitize_int( $input['max_registered_width'] ?? $defaults['max_registered_width'], 320, 5120 ),
			'max_observed_sizes'        => $this->sanitize_int( $input['max_observed_sizes'] ?? $defaults['max_observed_sizes'], 8, 300 ),
			'max_payload_images'        => $this->sanitize_int( $input['max_payload_images'] ?? $defaults['max_payload_images'], 1, 120 ),
		);
	}

	/**
	 * Check whether front-end measurement is enabled.
	 *
	 * @return bool
	 */
	public function is_collection_enabled(): bool {
		$options = $this->all();

		return (bool) $options['collection_enabled'];
	}

	/**
	 * Check whether page cache guard is enabled.
	 *
	 * @return bool
	 */
	public function is_cache_guard_enabled(): bool {
		$options = $this->all();

		return (bool) $options['cache_guard_enabled'];
	}

	/**
	 * Check whether picture output is enabled.
	 *
	 * @return bool
	 */
	public function is_picture_enabled(): bool {
		$options = $this->all();

		return (bool) $options['picture_enabled'];
	}

	/**
	 * Check whether local asset images should be processed.
	 *
	 * @return bool
	 */
	public function is_asset_processing_enabled(): bool {
		$options = $this->all();

		return (bool) $options['asset_processing_enabled'];
	}

	/**
	 * Get enabled alternate output formats.
	 *
	 * @return array
	 */
	public function get_enabled_formats(): array {
		$options = $this->all();
		$formats = array();

		if ( ! empty( $options['avif_enabled'] ) ) {
			$formats[] = 'avif';
		}

		if ( ! empty( $options['webp_enabled'] ) ) {
			$formats[] = 'webp';
		}

		return $formats;
	}

	/**
	 * Get integer option.
	 *
	 * @param string $key Setting key.
	 * @return int
	 */
	public function get_int( string $key ): int {
		$options = $this->all();

		return isset( $options[ $key ] ) ? (int) $options[ $key ] : 0;
	}

	/**
	 * Get float option.
	 *
	 * @param string $key Setting key.
	 * @return float
	 */
	public function get_float( string $key ): float {
		$options = $this->all();

		return isset( $options[ $key ] ) ? (float) $options[ $key ] : 0.0;
	}

	/**
	 * Sanitize integer range.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min Minimum.
	 * @param int   $max Maximum.
	 * @return int
	 */
	private function sanitize_int( $value, int $min, int $max ): int {
		return max( $min, min( $max, absint( $value ) ) );
	}

	/**
	 * Sanitize float range.
	 *
	 * @param mixed $value Raw value.
	 * @param float $min Minimum.
	 * @param float $max Maximum.
	 * @return float
	 */
	private function sanitize_float( $value, float $min, float $max ): float {
		$value = is_numeric( $value ) ? (float) $value : 0.0;

		return max( $min, min( $max, $value ) );
	}
}
