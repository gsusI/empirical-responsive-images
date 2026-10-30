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
	 * Non-authoritative visitor gap store.
	 *
	 * @var Visitor_Gap_Store
	 */
	private $visitor_gap_store;

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
	 * Automatic target-bound optimizer.
	 *
	 * @var Background_Optimizer
	 */
	private $background_optimizer;

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
		$gap_store     = new Visitor_Gap_Store();

		$settings->ensure_option();
		$store->ensure_option();
		$gap_store->ensure_option();
		Background_Optimizer::activate( $settings );
		update_option( self::VERSION_OPTION, EMPIRICAL_RESPONSIVE_IMAGES_VERSION, false );
		Cache_Compat::purge_all();
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Background_Optimizer::deactivate();
	}

	/**
	 * Initialize services.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->settings             = new Settings();
		$this->asset_manager        = new Asset_Manager( $this->settings );
		$this->observation_store    = new Observation_Store( $this->asset_manager );
		$this->format_generator     = new Format_Generator( $this->settings );
		$this->visitor_gap_store    = new Visitor_Gap_Store( $this->asset_manager, $this->format_generator, $this->observation_store );
		$this->image_sizes          = new Image_Sizes( $this->settings, $this->observation_store, $this->format_generator );
		$this->regenerator          = new Regenerator( $this->format_generator, $this->observation_store, $this->asset_manager, $this->settings );
		$this->background_optimizer = new Background_Optimizer( $this->settings, $this->visitor_gap_store, $this->asset_manager, $this->observation_store, $this->format_generator );

		$cache_controller = new Cache_Controller( $this->settings, $this->observation_store );
		$renderer         = new Renderer( $this->settings, $this->observation_store, $this->asset_manager, $this->format_generator );
		$admin_page       = new Admin_Page( $this->settings, $this->observation_store, $this->visitor_gap_store, $this->format_generator );
		$rest_controller  = new REST_Controller( $this->settings, $this->observation_store, $this->visitor_gap_store, $this->format_generator, $this->regenerator, $this->background_optimizer );

		$this->maybe_run_version_upgrade();
		$this->visitor_gap_store->ensure_option();
		$this->background_optimizer->ensure_watchdog_scheduled();
		$cache_controller->maybe_mark_current_request_uncacheable();

		add_action( 'template_redirect', array( $cache_controller, 'maybe_mark_current_request_uncacheable' ), 0 );
		add_action( 'send_headers', array( $cache_controller, 'send_no_cache_headers' ), 0 );
		add_action( 'after_setup_theme', array( $this->image_sizes, 'register_observed_image_sizes' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this->image_sizes, 'enqueue_observer' ) );
		add_filter( 'script_loader_tag', array( $this->image_sizes, 'filter_observer_script_tag' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $renderer, 'enqueue_picture_source_sync' ) );
		add_filter( 'wp_inline_script_attributes', array( $renderer, 'filter_picture_source_sync_attributes' ), 10, 2 );
		add_action( 'admin_init', array( $this->settings, 'register' ) );
		add_action( 'admin_menu', array( $admin_page, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $admin_page, 'enqueue_assets' ) );
		add_action( 'update_option_' . Settings::OPTION_NAME, array( $this, 'maybe_purge_after_settings_change' ), 10, 2 );
		add_action( Background_Optimizer::BATCH_HOOK, array( $this->background_optimizer, 'run' ) );
		add_action( Background_Optimizer::WATCHDOG_HOOK, array( $this->background_optimizer, 'run' ) );
		add_action( 'rest_api_init', array( $rest_controller, 'register_routes' ) );
		add_action( 'delete_attachment', array( $this->format_generator, 'delete_attachment_sidecars' ) );
		add_action( 'delete_attachment', array( $this->observation_store, 'delete_attachment_slots' ) );
		add_filter( 'wp_generate_attachment_metadata', array( $this->format_generator, 'filter_generate_attachment_metadata' ), 20, 2 );
		add_filter( 'wp_update_attachment_metadata', array( $this->format_generator, 'filter_update_attachment_metadata' ), 20, 2 );
		add_filter( 'wp_get_attachment_image', array( $renderer, 'filter_attachment_image_html' ), 20, 5 );
		add_filter( 'wp_content_img_tag', array( $renderer, 'filter_content_image_html' ), 20, 3 );
		add_filter( 'the_content', array( $renderer, 'filter_content_fragment' ), 99 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this->image_sizes, 'filter_attachment_attributes' ), 20, 3 );
		add_filter( 'wp_template_enhancement_output_buffer', array( $renderer, 'filter_final_html' ) );
		// WP Rocket inserts its stored image preloads after template enhancement.
		add_filter( 'rocket_buffer', array( $renderer, 'filter_image_preloads' ), PHP_INT_MAX );

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
	 * Purge cached markup when visitor gap detector inclusion changes.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $new_value New settings.
	 * @return void
	 */
	public function maybe_purge_after_settings_change( $old_value, $new_value ): void {
		$defaults     = ( new Settings() )->defaults();
		$old_settings = array_merge( $defaults, is_array( $old_value ) ? $old_value : array() );
		$new_settings = array_merge( $defaults, is_array( $new_value ) ? $new_value : array() );
		$old_enabled  = ! empty( $old_settings['visitor_gap_learning_enabled'] );
		$new_enabled  = ! empty( $new_settings['visitor_gap_learning_enabled'] );
		$old_assets   = ! empty( $old_settings['asset_processing_enabled'] );
		$new_assets   = ! empty( $new_settings['asset_processing_enabled'] );
		$purge_cache  = false;

		if ( $old_enabled !== $new_enabled ) {
			$this->background_optimizer->configure_schedules( $new_enabled );
			$purge_cache = true;
		}

		if ( $old_assets && ! $new_assets ) {
			$this->visitor_gap_store->discard_jobs_by_type( 'asset' );
		}

		foreach ( array( 'min_rendered_width', 'max_registered_width', 'rounding_step', 'asset_processing_enabled', 'picture_enabled', 'webp_enabled', 'avif_enabled' ) as $key ) {
			if ( (string) $old_settings[ $key ] !== (string) $new_settings[ $key ] ) {
				$purge_cache = true;
				break;
			}
		}

		if ( $purge_cache ) {
			Cache_Compat::purge_all();
		}
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

		// Cache plugins may expose purge functions before their dependencies are loaded.
		add_action( 'wp_loaded', array( $this, 'finish_version_upgrade' ), 20 );
	}

	/**
	 * Purge upgrade caches after plugins and the theme have finished loading.
	 *
	 * @return void
	 */
	public function finish_version_upgrade(): void {
		if ( EMPIRICAL_RESPONSIVE_IMAGES_VERSION === (string) get_option( self::VERSION_OPTION, '' ) ) {
			return;
		}

		Cache_Compat::purge_all();
		update_option( self::VERSION_OPTION, EMPIRICAL_RESPONSIVE_IMAGES_VERSION, false );
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
