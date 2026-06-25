<?php
/**
 * Observation storage.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores aggregate rendered image slot observations.
 */
final class Observation_Store {
	public const OPTION_NAME = 'empirical_responsive_images_observations';

	/**
	 * Asset manager.
	 *
	 * @var Asset_Manager|null
	 */
	private $asset_manager;

	/**
	 * Constructor.
	 *
	 * @param Asset_Manager|null $asset_manager Asset manager.
	 */
	public function __construct( ?Asset_Manager $asset_manager = null ) {
		$this->asset_manager = $asset_manager;
	}

	/**
	 * Ensure option exists.
	 *
	 * @return void
	 */
	public function ensure_option(): void {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, $this->empty_data(), '', false );
		}
	}

	/**
	 * Empty data shape.
	 *
	 * @return array
	 */
	public function empty_data(): array {
		return array(
			'version'    => 3,
			'updated_at' => 0,
			'totals'     => array(
				'observations' => 0,
				'payloads'     => 0,
			),
			'pages'      => array(),
			'sizes'      => array(),
			'assets'     => array(),
		);
	}

	/**
	 * Get stored data.
	 *
	 * @return array
	 */
	public function get_data(): array {
		$data = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$data = wp_parse_args( $data, $this->empty_data() );

		if ( ! is_array( $data['pages'] ) ) {
			$data['pages'] = array();
		}

		if ( ! is_array( $data['sizes'] ) ) {
			$data['sizes'] = array();
		}

		if ( ! is_array( $data['assets'] ) ) {
			$data['assets'] = array();
		}

		return $data;
	}

	/**
	 * Build stable page key from URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function page_key_from_url( string $url ): string {
		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			return '';
		}

		$path         = wp_parse_url( $url, PHP_URL_PATH );
		$query        = wp_parse_url( $url, PHP_URL_QUERY );
		$path         = is_string( $path ) && '' !== $path ? $path : '/';
		$trimmed_path = trim( $path, '/' );
		$path         = '' === $trimmed_path ? '/' : '/' . $trimmed_path . '/';

		if ( is_string( $query ) && '' !== $query ) {
			parse_str( $query, $query_args );
			$query_args = array_filter(
				$query_args,
				static function ( string $key ): bool {
					return ! preg_match( '/^(utm_|fbclid$|gclid$|gbraid$|wbraid$)/', $key );
				},
				ARRAY_FILTER_USE_KEY
			);
			ksort( $query_args );
			$query = http_build_query( $query_args, '', '&', PHP_QUERY_RFC3986 );

			if ( '' !== $query ) {
				$path .= '?' . $query;
			}
		}

		return sanitize_text_field( $path );
	}

	/**
	 * Check whether a page has completed the stable no-cache confirmation run.
	 *
	 * @param string $page_key Page key.
	 * @return bool
	 */
	public function is_page_cache_ready( string $page_key ): bool {
		$data = $this->get_data();
		$page = isset( $data['pages'][ $page_key ] ) && is_array( $data['pages'][ $page_key ] ) ? $data['pages'][ $page_key ] : array();

		return 'ready' === (string) ( $page['status'] ?? '' );
	}

	/**
	 * Get page cache status reason.
	 *
	 * @param string $page_key Page key.
	 * @return string
	 */
	public function get_page_cache_reason( string $page_key ): string {
		$data = $this->get_data();
		$page = isset( $data['pages'][ $page_key ] ) && is_array( $data['pages'][ $page_key ] ) ? $data['pages'][ $page_key ] : array();

		return '' !== (string) ( $page['last_reason'] ?? '' ) ? (string) $page['last_reason'] : 'not_confirmed';
	}

	/**
	 * Mark all known pages as needing a fresh no-cache confirmation.
	 *
	 * @param string $reason Reason key.
	 * @return void
	 */
	public function invalidate_cache_readiness( string $reason ): void {
		$data   = $this->get_data();
		$reason = sanitize_key( $reason );

		foreach ( $data['pages'] as $page_key => $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}

			$page['status']              = 'blocked';
			$page['ready_confirmations'] = 0;
			$page['last_reason']         = '' !== $reason ? $reason : 'invalidated';
			$data['pages'][ $page_key ]  = $page;
		}

		$data['updated_at'] = time();

		update_option( self::OPTION_NAME, $data, false );
	}

	/**
	 * Get image size candidates.
	 *
	 * @param Settings $settings Settings service.
	 * @return array
	 */
	public function get_registered_candidates( Settings $settings ): array {
		$data       = $this->get_data();
		$candidates = array();

		foreach ( $data['sizes'] as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['width'] ) ) {
				continue;
			}

			$width = absint( $entry['width'] );

			if ( $width < $settings->get_int( 'min_rendered_width' ) || $width > $settings->get_int( 'max_registered_width' ) ) {
				continue;
			}

			$candidates[] = $entry;
		}

		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				return (int) $a['width'] <=> (int) $b['width'];
			}
		);

		return $candidates;
	}

	/**
	 * Get observed manageable asset entries.
	 *
	 * @param Settings $settings Settings service.
	 * @return array
	 */
	public function get_asset_candidates( Settings $settings ): array {
		$data       = $this->get_data();
		$candidates = array();

		if ( ! $settings->is_asset_processing_enabled() || empty( $data['assets'] ) || ! is_array( $data['assets'] ) ) {
			return array();
		}

		foreach ( $data['assets'] as $key => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['url'] ) || empty( $entry['target_widths'] ) || ! is_array( $entry['target_widths'] ) ) {
				continue;
			}

			$target_widths = array_map( 'absint', array_values( $entry['target_widths'] ) );
			$target_widths = array_values( array_filter( array_unique( $target_widths ) ) );

			if ( empty( $target_widths ) ) {
				continue;
			}

			$candidates[] = array(
				'key'           => sanitize_key( (string) $key ),
				'url'           => esc_url_raw( (string) $entry['url'] ),
				'target_widths' => $target_widths,
				'slot_widths'   => is_array( $entry['slot_widths'] ?? null ) ? $this->sanitize_slot_width_map( $entry['slot_widths'] ) : array(),
				'count'         => absint( $entry['count'] ?? 0 ),
			);
		}

		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				return absint( $b['count'] ?? 0 ) <=> absint( $a['count'] ?? 0 );
			}
		);

		return $candidates;
	}

	/**
	 * Get observed asset source data by URL.
	 *
	 * @param string   $url      Image URL.
	 * @param Settings $settings Settings service.
	 * @return array
	 */
	public function get_asset_candidate_for_url( string $url, Settings $settings ): array {
		if ( null === $this->asset_manager || ! $settings->is_asset_processing_enabled() ) {
			return array();
		}

		$key = $this->asset_manager->key_for_url( $url );

		if ( '' === $key ) {
			return array();
		}

		$data  = $this->get_data();
		$entry = isset( $data['assets'][ $key ] ) && is_array( $data['assets'][ $key ] ) ? $data['assets'][ $key ] : array();

		if ( empty( $entry ) ) {
			return array();
		}

		$target_widths = isset( $entry['target_widths'] ) && is_array( $entry['target_widths'] ) ? array_map( 'absint', $entry['target_widths'] ) : array();
		$slot_widths   = isset( $entry['slot_widths'] ) && is_array( $entry['slot_widths'] ) ? $this->sanitize_slot_width_map( $entry['slot_widths'] ) : array();

		return array(
			'key'           => $key,
			'url'           => esc_url_raw( (string) ( $entry['url'] ?? '' ) ),
			'target_widths' => array_values( array_filter( array_unique( $target_widths ) ) ),
			'slot_widths'   => $slot_widths,
		);
	}

	/**
	 * Store observation payload.
	 *
	 * @param array    $payload  Sanitized-ish REST payload.
	 * @param Settings $settings Settings service.
	 * @return array
	 */
	public function record_payload( array $payload, Settings $settings ): array {
		$data          = $this->get_data();
		$images        = isset( $payload['images'] ) && is_array( $payload['images'] ) ? $payload['images'] : array();
		$max_images    = $settings->get_int( 'max_payload_images' );
		$raw_page_url  = isset( $payload['page_url'] ) && is_string( $payload['page_url'] ) ? esc_url_raw( $payload['page_url'] ) : '';
		$page_key      = self::page_key_from_url( $raw_page_url );
		$page_url      = '' !== $page_key ? home_url( $page_key ) : '';
		$viewport      = isset( $payload['viewport'] ) && is_array( $payload['viewport'] ) ? $payload['viewport'] : array();
		$seen          = 0;
		$page_snapshot = $this->new_page_snapshot();

		$images = array_slice( $images, 0, $max_images );

		foreach ( $images as $image ) {
			if ( ! is_array( $image ) ) {
				continue;
			}

			$observation = $this->normalize_image_observation( $image, $viewport, $settings );

			if ( empty( $observation ) ) {
				continue;
			}

			$key       = 'w' . (string) $observation['width'];
			$is_new    = empty( $data['sizes'][ $key ] ) || ! is_array( $data['sizes'][ $key ] );
			$page_snapshot['widths'][ $key ] = $observation['width'];
			$asset_key = (string) ( $observation['asset_key'] ?? '' );

			if ( $is_new ) {
				$page_snapshot['new_widths'][ $key ] = $observation['width'];
			}

			if ( $is_new ) {
				$data['sizes'][ $key ] = $this->new_size_entry( $observation['width'], $observation['height'] );
			}

			if ( $this->is_missing_expected_srcset_width( $observation ) ) {
				$page_snapshot['missing_srcset_widths'][ $key ] = $observation['width'];
			}

			if ( absint( $observation['attachment_id'] ) > 0 || '' !== $asset_key ) {
				++$page_snapshot['managed_images'];
			}

			$data['sizes'][ $key ] = $this->merge_observation( $data['sizes'][ $key ], $observation, $page_key );

			if ( '' !== $asset_key ) {
				$data['assets'][ $asset_key ] = $this->merge_asset_observation( isset( $data['assets'][ $asset_key ] ) && is_array( $data['assets'][ $asset_key ] ) ? $data['assets'][ $asset_key ] : array(), $observation, $page_key );
				$data['sizes'][ $key ]['assets'] = $this->increment_limited_map( $data['sizes'][ $key ]['assets'] ?? array(), $asset_key, 24 );
			}

			++$seen;
		}

		$data['updated_at']              = time();
		$data['totals']['payloads']      = absint( $data['totals']['payloads'] ?? 0 ) + 1;
		$data['totals']['observations']  = absint( $data['totals']['observations'] ?? 0 ) + $seen;
		$data['sizes']                   = $this->trim_sizes( $data['sizes'], $settings->get_int( 'max_observed_sizes' ) );
		$data['assets']                  = $this->trim_assets( is_array( $data['assets'] ?? null ) ? $data['assets'] : array(), $settings->get_int( 'max_observed_sizes' ) * 4 );
		$page_update                     = $this->update_page_cache_state( $data, $page_key, $page_url, $seen, $page_snapshot, $settings );
		$data                            = $page_update['data'];

		update_option( self::OPTION_NAME, $data, false );

		return array(
			'accepted'       => $seen,
			'cache_ready'    => (bool) ( $page_update['cache_ready'] ?? false ),
			'new_widths'     => array_values( $page_snapshot['new_widths'] ),
			'missing_srcset' => array_values( $page_snapshot['missing_srcset_widths'] ),
			'page_key'       => $page_key,
			'purge_url'      => ! empty( $page_update['purge_url'] ) ? $page_update['purge_url'] : '',
			'status_changed' => ! empty( $page_update['status_changed'] ),
			'sizes'          => count( $data['sizes'] ),
		);
	}

	/**
	 * Create a fresh size entry.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return array
	 */
	private function new_size_entry( int $width, int $height ): array {
		return array(
			'width'      => $width,
			'min_height' => $height,
			'max_height' => $height,
			'count'      => 0,
			'first_seen' => time(),
			'last_seen'  => time(),
			'dprs'       => array(),
			'viewports'  => array(),
			'pages'      => array(),
			'attachments' => array(),
			'assets'     => array(),
		);
	}

	/**
	 * Merge one observation into a size entry.
	 *
	 * @param array  $entry       Current entry.
	 * @param array  $observation Observation.
	 * @param string $page_path   Page path.
	 * @return array
	 */
	private function merge_observation( array $entry, array $observation, string $page_path ): array {
		$entry['count']      = absint( $entry['count'] ?? 0 ) + 1;
		$entry['last_seen']  = time();
		$entry['min_height'] = min( absint( $entry['min_height'] ?? $observation['height'] ), $observation['height'] );
		$entry['max_height'] = max( absint( $entry['max_height'] ?? $observation['height'] ), $observation['height'] );

		$dpr_key       = (string) $observation['dpr'];
		$viewport_key  = (string) $observation['viewport_width'];
		$attachment_id = absint( $observation['attachment_id'] );

		$entry['dprs']      = $this->increment_limited_map( $entry['dprs'] ?? array(), $dpr_key, 10 );
		$entry['viewports'] = $this->increment_limited_map( $entry['viewports'] ?? array(), $viewport_key, 16 );

		if ( '' !== $page_path ) {
			$entry['pages'] = $this->increment_limited_map( $entry['pages'] ?? array(), $page_path, 12 );
		}

		if ( $attachment_id > 0 ) {
			$entry['attachments'] = $this->increment_limited_map( $entry['attachments'] ?? array(), (string) $attachment_id, 24 );
		}

		return $entry;
	}

	/**
	 * Merge one asset observation into source entry.
	 *
	 * @param array  $entry       Current asset entry.
	 * @param array  $observation Observation.
	 * @param string $page_path   Page path.
	 * @return array
	 */
	private function merge_asset_observation( array $entry, array $observation, string $page_path ): array {
		$now            = time();
		$target_key     = 'w' . absint( $observation['width'] ?? 0 );
		$viewport_width = absint( $observation['viewport_width'] ?? 0 );
		$rendered_width = absint( $observation['rendered_width'] ?? 0 );

		$entry = wp_parse_args(
			$entry,
			array(
				'url'           => '',
				'count'         => 0,
				'first_seen'    => $now,
				'last_seen'     => 0,
				'natural_width' => 0,
				'target_widths' => array(),
				'slot_widths'   => array(),
				'pages'         => array(),
			)
		);

		$entry['url']           = esc_url_raw( (string) ( $observation['source_url'] ?? $entry['url'] ) );
		$entry['count']         = absint( $entry['count'] ?? 0 ) + 1;
		$entry['last_seen']     = $now;
		$entry['natural_width'] = max( absint( $entry['natural_width'] ?? 0 ), absint( $observation['natural_width'] ?? 0 ) );

		if ( '' !== $target_key && 'w0' !== $target_key ) {
			$entry['target_widths'][ $target_key ] = absint( $observation['width'] ?? 0 );
		}

		if ( $viewport_width > 0 && $rendered_width > 0 ) {
			$current_slot = absint( $entry['slot_widths'][ (string) $viewport_width ] ?? 0 );
			$entry['slot_widths'][ (string) $viewport_width ] = max( $current_slot, $rendered_width );
			ksort( $entry['slot_widths'] );
			$entry['slot_widths'] = array_slice( $entry['slot_widths'], 0, 16, true );
		}

		if ( '' !== $page_path ) {
			$entry['pages'] = $this->increment_limited_map( $entry['pages'] ?? array(), $page_path, 12 );
		}

		ksort( $entry['target_widths'] );

		return $entry;
	}

	/**
	 * Normalize one image observation.
	 *
	 * @param array    $image    Raw image payload.
	 * @param array    $viewport Raw viewport payload.
	 * @param Settings $settings Settings service.
	 * @return array
	 */
	private function normalize_image_observation( array $image, array $viewport, Settings $settings ): array {
		$dpr             = isset( $image['dpr'] ) && is_numeric( $image['dpr'] ) ? (float) $image['dpr'] : 1.0;
		$rendered_width  = isset( $image['rendered_width'] ) && is_numeric( $image['rendered_width'] ) ? (float) $image['rendered_width'] : 0.0;
		$rendered_height = isset( $image['rendered_height'] ) && is_numeric( $image['rendered_height'] ) ? (float) $image['rendered_height'] : 0.0;
		$target_width    = isset( $image['target_width'] ) && is_numeric( $image['target_width'] ) ? (float) $image['target_width'] : $rendered_width * $dpr;
		$target_height   = isset( $image['target_height'] ) && is_numeric( $image['target_height'] ) ? (float) $image['target_height'] : $rendered_height * $dpr;

		if ( $rendered_width < $settings->get_int( 'min_rendered_width' ) || $target_width < 1 ) {
			return array();
		}

		$width  = $this->round_to_step( (int) ceil( $target_width ), $settings->get_int( 'rounding_step' ) );
		$height = max( 1, $this->round_to_step( (int) ceil( $target_height ), $settings->get_int( 'rounding_step' ) ) );

		if ( $width > $settings->get_int( 'max_registered_width' ) ) {
			$width = $settings->get_int( 'max_registered_width' );
		}

		return array(
			'width'          => $width,
			'height'         => $height,
			'rendered_width'  => max( 1, (int) round( $rendered_width ) ),
			'rendered_height' => max( 1, (int) round( $rendered_height ) ),
			'dpr'            => round( max( 1, min( 4, $dpr ) ), 2 ),
			'viewport_width' => absint( $viewport['width'] ?? 0 ),
			'attachment_id'  => absint( $image['attachment_id'] ?? 0 ),
			'natural_width'  => absint( $image['natural_width'] ?? 0 ),
			'srcset_widths'  => $this->sanitize_srcset_widths( $image['srcset_widths'] ?? array() ),
			'source_url'     => $this->source_url_from_observation( $image ),
			'asset_key'      => $settings->is_asset_processing_enabled() ? $this->asset_key_from_observation( $image ) : '',
		);
	}

	/**
	 * Get default page snapshot state.
	 *
	 * @return array
	 */
	private function new_page_snapshot(): array {
		return array(
			'managed_images'          => 0,
			'widths'                  => array(),
			'new_widths'              => array(),
			'missing_srcset_widths'   => array(),
		);
	}

	/**
	 * Update per-page cache readiness.
	 *
	 * @param array    $data          Current data.
	 * @param string   $page_key      Page key.
	 * @param string   $page_url      Page URL.
	 * @param int      $seen          Accepted image observations.
	 * @param array    $snapshot      Page snapshot.
	 * @param Settings $settings      Settings.
	 * @return array
	 */
	private function update_page_cache_state( array $data, string $page_key, string $page_url, int $seen, array $snapshot, Settings $settings ): array {
		if ( '' === $page_key ) {
			return array(
				'data'           => $data,
				'cache_ready'    => false,
				'status_changed' => false,
				'purge_url'      => '',
			);
		}

		$page            = isset( $data['pages'][ $page_key ] ) && is_array( $data['pages'][ $page_key ] ) ? $data['pages'][ $page_key ] : $this->new_page_entry();
		$previous_status = (string) ( $page['status'] ?? 'blocked' );
		$has_new_widths  = ! empty( $snapshot['new_widths'] );
		$has_missing     = ! empty( $snapshot['missing_srcset_widths'] );
		$reason          = 'stable';

		if ( $has_new_widths ) {
			$reason = 'new_sizes';
		} elseif ( $has_missing ) {
			$reason = 'missing_srcset';
		} elseif ( $seen < 1 ) {
			$reason = 'no_images';
		}

		$page['last_seen']      = time();
		$page['last_reason']    = $reason;
		$page['last_image_count'] = $seen;
		$page['managed_images'] = absint( $snapshot['managed_images'] ?? 0 );
		$page['widths']         = $this->merge_width_counts( is_array( $page['widths'] ?? null ) ? $page['widths'] : array(), $snapshot['widths'] );
		$page['missing_srcset_widths'] = array_values( $snapshot['missing_srcset_widths'] );

		if ( $has_new_widths ) {
			$page['status']              = 'blocked';
			$page['ready_confirmations'] = 0;
		} else {
			$page['ready_confirmations'] = absint( $page['ready_confirmations'] ?? 0 ) + 1;
			$page['status']              = $page['ready_confirmations'] >= $settings->get_int( 'cache_ready_runs' ) ? 'ready' : 'warming';
		}

		$data['pages'][ $page_key ] = $page;

		return array(
			'data'           => $data,
			'cache_ready'    => 'ready' === $page['status'],
			'status_changed' => $previous_status !== $page['status'],
			'purge_url'      => $previous_status !== $page['status'] ? $page_url : '',
		);
	}

	/**
	 * Create a fresh page cache entry.
	 *
	 * @return array
	 */
	private function new_page_entry(): array {
		return array(
			'status'                => 'blocked',
			'ready_confirmations'   => 0,
			'last_seen'             => 0,
			'last_reason'           => 'not_confirmed',
			'last_image_count'      => 0,
			'managed_images'        => 0,
			'widths'                => array(),
			'missing_srcset_widths' => array(),
		);
	}

	/**
	 * Check if a managed image is missing the expected srcset width.
	 *
	 * @param array $observation Observation.
	 * @return bool
	 */
	private function is_missing_expected_srcset_width( array $observation ): bool {
		$attachment_id = absint( $observation['attachment_id'] ?? 0 );
		$asset_key     = (string) ( $observation['asset_key'] ?? '' );

		if ( $attachment_id < 1 && '' === $asset_key ) {
			return false;
		}

		$srcset_widths = isset( $observation['srcset_widths'] ) && is_array( $observation['srcset_widths'] ) ? $observation['srcset_widths'] : array();

		if ( empty( $srcset_widths ) ) {
			return true;
		}

		$metadata       = $attachment_id > 0 ? wp_get_attachment_metadata( $attachment_id ) : array();
		$original_width = is_array( $metadata ) ? absint( $metadata['width'] ?? 0 ) : 0;

		if ( $original_width < 1 && '' !== $asset_key && null !== $this->asset_manager ) {
			$original_width = $this->asset_manager->source_width_for_url( (string) ( $observation['source_url'] ?? '' ) );
		}

		$original_width = $original_width > 0 ? $original_width : absint( $observation['natural_width'] ?? 0 );
		$target_width   = absint( $observation['width'] ?? 0 );

		if ( $original_width > 0 && $target_width > $original_width ) {
			return max( $srcset_widths ) < $original_width;
		}

		return ! in_array( $target_width, $srcset_widths, true );
	}

	/**
	 * Merge width observation counts.
	 *
	 * @param array $current Current counts.
	 * @param array $widths  Widths.
	 * @return array
	 */
	private function merge_width_counts( array $current, array $widths ): array {
		foreach ( $widths as $key => $width ) {
			$current[ $key ] = absint( $current[ $key ] ?? 0 ) + 1;
		}

		arsort( $current );

		return array_slice( $current, 0, 80, true );
	}

	/**
	 * Sanitize srcset width list.
	 *
	 * @param mixed $widths Raw widths.
	 * @return array
	 */
	private function sanitize_srcset_widths( $widths ): array {
		if ( ! is_array( $widths ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $widths as $width ) {
			$width = absint( $width );

			if ( $width > 0 ) {
				$sanitized[] = $width;
			}
		}

		sort( $sanitized );

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Sanitize viewport-to-slot-width map.
	 *
	 * @param array $widths Raw map.
	 * @return array
	 */
	private function sanitize_slot_width_map( array $widths ): array {
		$sanitized = array();

		foreach ( $widths as $viewport => $slot_width ) {
			$viewport  = absint( $viewport );
			$slot_width = absint( $slot_width );

			if ( $viewport > 0 && $slot_width > 0 ) {
				$sanitized[ (string) $viewport ] = $slot_width;
			}
		}

		ksort( $sanitized );

		return $sanitized;
	}

	/**
	 * Extract best source URL from observation payload.
	 *
	 * @param array $image Raw image payload.
	 * @return string
	 */
	private function source_url_from_observation( array $image ): string {
		foreach ( array( 'src', 'current_src' ) as $key ) {
			if ( empty( $image[ $key ] ) || ! is_string( $image[ $key ] ) ) {
				continue;
			}

			$url = esc_url_raw( $image[ $key ] );

			if ( '' !== $url ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Get manageable asset key from observation payload.
	 *
	 * @param array $image Raw image payload.
	 * @return string
	 */
	private function asset_key_from_observation( array $image ): string {
		if ( null === $this->asset_manager || absint( $image['attachment_id'] ?? 0 ) > 0 ) {
			return '';
		}

		$url = $this->source_url_from_observation( $image );

		if ( '' === $url ) {
			return '';
		}

		return $this->asset_manager->key_for_url( $url );
	}

	/**
	 * Round to configured step.
	 *
	 * @param int $value Value.
	 * @param int $step  Step.
	 * @return int
	 */
	private function round_to_step( int $value, int $step ): int {
		$step = max( 1, $step );

		return max( $step, (int) ( ceil( $value / $step ) * $step ) );
	}

	/**
	 * Increment a count map and keep top entries.
	 *
	 * @param array  $map   Count map.
	 * @param string $key   Entry key.
	 * @param int    $limit Entry limit.
	 * @return array
	 */
	private function increment_limited_map( array $map, string $key, int $limit ): array {
		$key = sanitize_text_field( $key );

		if ( '' === $key ) {
			return $map;
		}

		$map[ $key ] = absint( $map[ $key ] ?? 0 ) + 1;
		arsort( $map );

		return array_slice( $map, 0, $limit, true );
	}

	/**
	 * Keep most-used size entries.
	 *
	 * @param array $sizes Size entries.
	 * @param int   $limit Max entries.
	 * @return array
	 */
	private function trim_sizes( array $sizes, int $limit ): array {
		uasort(
			$sizes,
			static function ( array $a, array $b ): int {
				return absint( $b['count'] ?? 0 ) <=> absint( $a['count'] ?? 0 );
			}
		);

		return array_slice( $sizes, 0, $limit, true );
	}

	/**
	 * Keep most-observed asset entries.
	 *
	 * @param array $assets Asset entries.
	 * @param int   $limit  Max entries.
	 * @return array
	 */
	private function trim_assets( array $assets, int $limit ): array {
		uasort(
			$assets,
			static function ( array $a, array $b ): int {
				return absint( $b['count'] ?? 0 ) <=> absint( $a['count'] ?? 0 );
			}
		);

		return array_slice( $assets, 0, max( 20, $limit ), true );
	}

	/**
	 * Sanitize page URL down to path only.
	 *
	 * @param mixed $page_url Raw page URL.
	 * @return string
	 */
	private function sanitize_page_path( $page_url ): string {
		$page_url = is_string( $page_url ) ? esc_url_raw( $page_url ) : '';
		$path     = '' !== $page_url ? wp_parse_url( $page_url, PHP_URL_PATH ) : '';

		return is_string( $path ) ? sanitize_text_field( $path ) : '';
	}
}
