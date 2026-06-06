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
	private const NAMESPACE = 'empirical-responsive-images/v1';

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
				'permission_callback' => '__return_true',
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
	 * Record public observations.
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

		if ( ! $this->allow_public_observation_request() ) {
			return new \WP_Error(
				'empirical_responsive_images_rate_limited',
				__( 'Too many image observation requests.', 'empirical-responsive-images' ),
				array( 'status' => 429 )
			);
		}

		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$result = $this->store->record_payload( $params, $this->settings );

		if ( ! empty( $result['status_changed'] ) && ! empty( $result['purge_url'] ) && is_string( $result['purge_url'] ) ) {
			Cache_Compat::purge_url( $result['purge_url'] );
		}

		return rest_ensure_response( $result );
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

	/**
	 * Basic public write rate limit. Stores only salted anonymous transient key.
	 *
	 * @return bool
	 */
	private function allow_public_observation_request(): bool {
		$ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$key        = 'eri_obs_' . md5( $ip_address . '|' . $user_agent . '|' . wp_salt( 'nonce' ) );
		$count      = absint( get_transient( $key ) );

		if ( $count >= 60 ) {
			return false;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}
}
