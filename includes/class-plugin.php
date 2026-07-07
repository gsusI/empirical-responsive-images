<?php
/**
 * Plugin bootstrap.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates plugin services.
 */
final class Plugin {
	private const VERSION_OPTION = 'empirical_responsive_images_version';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

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
	private $observation_store;

	/**
	 * Local asset manager.
	 *
	 * @var Asset_Manager
	 */
	private $asset_manager;

	/**
	 * Image size registrar.
	 *
	 * @var Image_Sizes
	 */
	private $image_sizes;

	/**
	 * Modern format generator.
	 *
	 * @var Format_Generator
	 */
	private $format_generator;

	/**
	 * Thumbnail regenerator.
	 *
	 * @var Regenerator
	 */
	private $regenerator;

	/**
	 * Get singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$settings      = new Settings();
		$asset_manager = new Asset_Manager( $settings );
		$store         = new Observation_Store( $asset_manager );

		$settings->ensure_option();
		$store->ensure_option();
		update_option( self::VERSION_OPTION, EMPIRICAL_RESPONSIVE_IMAGES_VERSION, false );
		Cache_Compat::purge_all();
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// No scheduled jobs.
	}

	/**
	 * Initialize services.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->settings          = new Settings();
		$this->asset_manager     = new Asset_Manager( $this->settings );
		$this->observation_store = new Observation_Store( $this->asset_manager );
		$this->image_sizes       = new Image_Sizes( $this->settings, $this->observation_store );
		$this->format_generator  = new Format_Generator( $this->settings );
		$this->regenerator       = new Regenerator( $this->format_generator, $this->observation_store, $this->asset_manager, $this->settings );

		$cache_controller = new Cache_Controller( $this->settings, $this->observation_store );
		$renderer        = new Renderer( $this->settings, $this->format_generator, $this->observation_store, $this->asset_manager );
		$admin_page      = new Admin_Page( $this->settings, $this->observation_store, $this->format_generator );
		$rest_controller = new REST_Controller( $this->settings, $this->observation_store, $this->format_generator, $this->regenerator );

		$this->maybe_run_version_upgrade();
		$cache_controller->maybe_mark_current_request_uncacheable();

		add_action( 'template_redirect', array( $cache_controller, 'maybe_mark_current_request_uncacheable' ), 0 );
		add_action( 'send_headers', array( $cache_controller, 'send_no_cache_headers' ), 0 );
		add_action( 'after_setup_theme', array( $this->image_sizes, 'register_observed_image_sizes' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this->image_sizes, 'enqueue_observer' ) );
		add_filter( 'script_loader_tag', array( $this->image_sizes, 'filter_observer_script_tag' ), 10, 3 );
		add_action( 'admin_init', array( $this->settings, 'register' ) );
		add_action( 'admin_menu', array( $admin_page, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $admin_page, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( $rest_controller, 'register_routes' ) );
		add_filter( 'wp_generate_attachment_metadata', array( $this->format_generator, 'filter_generate_attachment_metadata' ), 20, 2 );
		add_filter( 'wp_get_attachment_image', array( $renderer, 'filter_attachment_image_html' ), 20, 5 );
		add_filter( 'wp_content_img_tag', array( $renderer, 'filter_content_image_html' ), 20, 3 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this->image_sizes, 'filter_attachment_attributes' ), 20, 3 );
		add_action( 'template_redirect', array( $renderer, 'start_output_buffer' ), 999 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'empirical-responsive-images', CLI_Command::class );
		}
	}

	/**
	 * Get settings service.
	 *
	 * @return Settings
	 */
	public function get_settings(): Settings {
		return $this->settings;
	}

	/**
	 * Get observation store.
	 *
	 * @return Observation_Store
	 */
	public function get_observation_store(): Observation_Store {
		return $this->observation_store;
	}

	/**
	 * Get regenerator.
	 *
	 * @return Regenerator
	 */
	public function get_regenerator(): Regenerator {
		return $this->regenerator;
	}

	/**
	 * Run lightweight version upgrade tasks.
	 *
	 * @return void
	 */
	private function maybe_run_version_upgrade(): void {
		$stored_version = (string) get_option( self::VERSION_OPTION, '' );

		if ( EMPIRICAL_RESPONSIVE_IMAGES_VERSION === $stored_version ) {
			return;
		}

		$this->settings->ensure_option();
		$this->observation_store->ensure_option();
		$this->maybe_upgrade_min_rendered_width_default( $stored_version );
		$this->observation_store->invalidate_cache_readiness( 'plugin_upgraded' );

		update_option( self::VERSION_OPTION, EMPIRICAL_RESPONSIVE_IMAGES_VERSION, false );
		Cache_Compat::purge_all();
	}

	/**
	 * Migrate old default min width so small assets are observed too.
	 *
	 * @param string $stored_version Previously stored plugin version.
	 * @return void
	 */
	private function maybe_upgrade_min_rendered_width_default( string $stored_version ): void {
		if ( '' !== $stored_version && ! version_compare( $stored_version, '0.1.2', '<' ) ) {
			return;
		}

		$options = get_option( Settings::OPTION_NAME, array() );

		if ( ! is_array( $options ) || 80 !== absint( $options['min_rendered_width'] ?? 80 ) ) {
			return;
		}

		$options['min_rendered_width'] = 1;

		update_option( Settings::OPTION_NAME, $options, false );
	}
}
