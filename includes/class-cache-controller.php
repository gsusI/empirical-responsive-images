<?php
/**
 * Page cache guard.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevents caching until observed image sizes are stable.
 */
final class Cache_Controller {
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
	 * Whether this request is blocked from cache.
	 *
	 * @var bool
	 */
	private $request_uncacheable = false;

	/**
	 * Bypass reason for current request.
	 *
	 * @var string
	 */
	private $bypass_reason = '';

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
	 * Mark current request as uncacheable when page is not confirmed ready.
	 *
	 * @return void
	 */
	public function maybe_mark_current_request_uncacheable(): void {
		if ( ! $this->should_guard_current_request() ) {
			return;
		}

		$page_key = $this->current_page_key();

		if ( '' === $page_key || $this->store->is_page_cache_ready( $page_key ) ) {
			return;
		}

		$this->request_uncacheable = true;
		$this->bypass_reason       = $this->store->get_page_cache_reason( $page_key );

		Cache_Compat::define_no_cache_constants();
	}

	/**
	 * Send no-cache headers if the request is not ready to cache.
	 *
	 * @return void
	 */
	public function send_no_cache_headers(): void {
		if ( ! $this->request_uncacheable ) {
			return;
		}

		nocache_headers();

		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'Pragma: no-cache', true );
		header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT', true );
		header( 'CDN-Cache-Control: no-store', false );
		header( 'Cloudflare-CDN-Cache-Control: no-store', false );
		header( 'Surrogate-Control: no-store', false );
		header( 'X-LiteSpeed-Cache-Control: no-cache', false );
		header( 'X-Empirical-Responsive-Images-Cache: bypass; reason="' . sanitize_key( $this->bypass_reason ) . '"', false );
	}

	/**
	 * Check whether current request is guardable HTML.
	 *
	 * @return bool
	 */
	private function should_guard_current_request(): bool {
		if ( ! $this->settings->is_cache_guard_enabled() || is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		return in_array( $method, array( 'GET', 'HEAD' ), true );
	}

	/**
	 * Get current page cache key.
	 *
	 * @return string
	 */
	private function current_page_key(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

		return Observation_Store::page_key_from_url( home_url( $request_uri ) );
	}
}
