<?php
/**
 * REST controller.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for observations and admin actions.
 */
final class REST_Controller {
	private const NAMESPACE         = 'empirical-responsive-images/v1';
	private const MAX_REQUEST_BYTES = 131072;

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
	 * Format generator.
	 *
	 * @var Format_Generator
	 */
	private $format_generator;

	/**
	 * Regenerator.
	 *
	 * @var Regenerator
	 */
	private $regenerator;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Settings.
	 * @param Observation_Store $store Observation store.
	 * @param Format_Generator  $format_generator Format generator.
	 * @param Regenerator       $regenerator Regenerator.
	 */
	public function __construct( Settings $settings, Observation_Store $store, Format_Generator $format_generator, Regenerator $regenerator ) {
		$this->settings         = $settings;
		$this->store            = $store;
		$this->format_generator = $format_generator;
		$this->regenerator      = $regenerator;
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/observations',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'record_observations' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'page_url' => array(
						'type'     => 'string',
						'format'   => 'uri',
						'required' => true,
					),
					'viewport' => array(
						'type'     => 'object',
						'required' => true,
					),
					'images'   => array(
						'type'     => 'array',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/regenerate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'regenerate_batch' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 5,
						'sanitize_callback' => 'absint',
					),
					'force'    => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Permission callback for admin routes.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Record administrator-authorized observations.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function record_observations( \WP_REST_Request $request ) {
		if ( ! $this->settings->is_collection_enabled() ) {
			return rest_ensure_response(
				array(
					'accepted' => 0,
					'disabled' => true,
				)
			);
		}

		if ( strlen( $request->get_body() ) > self::MAX_REQUEST_BYTES ) {
			return new \WP_Error(
				'empirical_responsive_images_payload_too_large',
				__( 'Image observation payload is too large.', 'empirical-responsive-images' ),
				array( 'status' => 413 )
			);
		}

		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		if ( empty( $params['page_url'] ) || ! is_string( $params['page_url'] ) || ! $this->is_local_page_url( $params['page_url'] ) ) {
			return new \WP_Error(
				'empirical_responsive_images_invalid_page_url',
				__( 'Image observations must come from this site.', 'empirical-responsive-images' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->store->record_payload( $params, $this->settings );

		if ( ! empty( $result['busy'] ) ) {
			return new \WP_Error(
				'empirical_responsive_images_store_busy',
				__( 'Image observation storage is busy. Retry shortly.', 'empirical-responsive-images' ),
				array( 'status' => 503 )
			);
		}

		if ( ! empty( $result['status_changed'] ) && ! empty( $result['purge_url'] ) && is_string( $result['purge_url'] ) ) {
			Cache_Compat::purge_url( $result['purge_url'] );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Check whether a reported page URL belongs to this WordPress site.
	 *
	 * @param string $url Reported page URL.
	 * @return bool
	 */
	private function is_local_page_url( string $url ): bool {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $scheme ) || ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) || ! is_string( $host ) || '' === $host ) {
			return false;
		}

		$allowed_hosts = array();

		foreach ( array( home_url( '/' ), site_url( '/' ) ) as $site_url ) {
			$site_host = wp_parse_url( $site_url, PHP_URL_HOST );

			if ( is_string( $site_host ) && '' !== $site_host ) {
				$allowed_hosts[] = strtolower( $site_host );
			}
		}

		return in_array( strtolower( $host ), array_unique( $allowed_hosts ), true );
	}

	/**
	 * Run one regeneration batch.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function regenerate_batch( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->regenerator->regenerate_batch(
			absint( $request->get_param( 'page' ) ),
			absint( $request->get_param( 'per_page' ) ),
			(bool) $request->get_param( 'force' )
		);

		return rest_ensure_response( $result );
	}

	/**
	 * Return admin status.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_status(): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'observations' => $this->store->get_data(),
				'assets'       => $this->store->get_asset_candidates( $this->settings ),
				'formats'      => $this->format_generator->get_support_status(),
			)
		);
	}
}
