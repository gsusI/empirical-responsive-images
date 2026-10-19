<?php
/**
 * Automatic background image optimization.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes qualified visitor image gaps through bounded WP-Cron jobs.
 */
final class Background_Optimizer {
	public const BATCH_HOOK    = 'empirical_responsive_images_optimizer_batch';
	public const WATCHDOG_HOOK = 'empirical_responsive_images_optimizer_watchdog';

	private const LOCK_OPTION               = 'empirical_responsive_images_background_optimizer_lock';
	private const LOCK_TTL_SECONDS          = 900;
	private const DEFAULT_DELAY             = 5;
	private const LOCK_RETRY_DELAY          = 60;
	private const MAX_BATCH_DELAY           = 3600;
	private const DEFAULT_BATCH_RUNTIME     = 30;
	private const MINIMUM_BATCH_RUNTIME     = 1;
	private const MAXIMUM_BATCH_RUNTIME     = 120;
	private const WATCHDOG_INTERVAL         = 'hourly';
	private const MINIMUM_FREE_UPLOAD_BYTES = 268435456;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Visitor gap job store.
	 *
	 * @var Visitor_Gap_Store
	 */
	private $store;

	/**
	 * Local asset manager.
	 *
	 * @var Asset_Manager
	 */
	private $asset_manager;

	/**
	 * Authoritative observation store.
	 *
	 * @var Observation_Store
	 */
	private $observation_store;

	/**
	 * Attachment format generator.
	 *
	 * @var Format_Generator
	 */
	private $format_generator;

	/**
	 * Token owned by current worker lock.
	 *
	 * @var string
	 */
	private $lock_token = '';

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings          Settings.
	 * @param Visitor_Gap_Store $store             Visitor gap store.
	 * @param Asset_Manager     $asset_manager     Asset manager.
	 * @param Observation_Store $observation_store Observation store.
	 * @param Format_Generator  $format_generator  Format generator.
	 */
	public function __construct( Settings $settings, Visitor_Gap_Store $store, Asset_Manager $asset_manager, Observation_Store $observation_store, Format_Generator $format_generator ) {
		$this->settings          = $settings;
		$this->store             = $store;
		$this->asset_manager     = $asset_manager;
		$this->observation_store = $observation_store;
		$this->format_generator  = $format_generator;
	}

	/**
	 * Schedule automatic processing when the plugin becomes active.
	 *
	 * @param Settings $settings Settings.
	 * @return void
	 */
	public static function activate( Settings $settings ): void {
		if ( ! $settings->is_visitor_gap_learning_enabled() ) {
			return;
		}

		self::schedule_watchdog_event();
		self::schedule_batch_event( self::DEFAULT_DELAY );
	}

	/**
	 * Stop all background work when the plugin becomes inactive.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::BATCH_HOOK );
		wp_clear_scheduled_hook( self::WATCHDOG_HOOK );
	}

	/**
	 * Ensure the hourly abandoned-job watchdog exists.
	 *
	 * @return bool
	 */
	public function ensure_watchdog_scheduled(): bool {
		if ( ! $this->settings->is_visitor_gap_learning_enabled() ) {
			return false;
		}

		return self::schedule_watchdog_event();
	}

	/**
	 * Enable or disable all worker schedules after a settings change.
	 *
	 * @param bool $enabled Whether automatic optimization is enabled.
	 * @return void
	 */
	public function configure_schedules( bool $enabled ): void {
		if ( ! $enabled ) {
			self::deactivate();
			return;
		}

		$this->ensure_watchdog_scheduled();
		$this->schedule();
	}

	/**
	 * Schedule the hourly watchdog event when absent.
	 *
	 * @return bool
	 */
	private static function schedule_watchdog_event(): bool {
		if ( false !== wp_next_scheduled( self::WATCHDOG_HOOK ) ) {
			return true;
		}

		$result = wp_schedule_event(
			time() + self::MAX_BATCH_DELAY,
			self::WATCHDOG_INTERVAL,
			self::WATCHDOG_HOOK,
			array(),
			true
		);

		return true === $result;
	}

	/**
	 * Schedule the next bounded worker invocation when work is ready.
	 *
	 * @param int $minimum_delay Minimum delay in seconds.
	 * @return bool
	 */
	public function schedule( int $minimum_delay = 0 ): bool {
		if ( ! $this->settings->is_visitor_gap_learning_enabled() ) {
			return false;
		}

		if ( false !== wp_next_scheduled( self::BATCH_HOOK ) ) {
			return true;
		}

		$has_runnable = $this->store->has_runnable_jobs();
		$store_delay  = max( 0, $this->store->seconds_until_next_work() );

		if ( ! $has_runnable && 0 === $store_delay && ! $this->store->should_purge_cache() ) {
			return false;
		}

		$delay = max( self::DEFAULT_DELAY, $minimum_delay, $store_delay );

		return self::schedule_batch_event( min( self::MAX_BATCH_DELAY, $delay ) );
	}

	/**
	 * Process qualified target-width jobs within one bounded cron invocation.
	 *
	 * @return void
	 */
	public function run(): void {
		if ( ! $this->settings->is_visitor_gap_learning_enabled() ) {
			return;
		}

		if ( ! $this->acquire_lock() ) {
			$this->schedule( self::LOCK_RETRY_DELAY );
			return;
		}

		$deadline          = microtime( true ) + $this->batch_runtime_seconds();
		$processed         = 0;
		$storage_exhausted = false;

		try {
			while ( true ) {
				if ( $processed > 0 && microtime( true ) >= $deadline ) {
					break;
				}

				if ( ! $this->renew_lock() ) {
					break;
				}

				if ( ! $this->upload_storage_has_capacity() ) {
					$storage_exhausted = true;
					break;
				}

				$job_id      = '';
				$lease_token = '';

				try {
					$job         = $this->store->claim_next_job();
					$job_id      = sanitize_text_field( (string) ( $job['id'] ?? '' ) );
					$lease_token = sanitize_text_field( (string) ( $job['lease_token'] ?? '' ) );

					if ( '' === $job_id ) {
						break;
					}

					++$processed;
					$this->process_job( $job_id, $job );
				} catch ( \Throwable $error ) {
					if ( '' === $job_id || '' === $lease_token ) {
						break;
					}

					$this->complete_safely( $job_id, $lease_token, false, 'unexpected_worker_error' );
				}
			}

			$this->maybe_purge_completed_work();

			if ( $storage_exhausted ) {
				$this->ensure_watchdog_scheduled();
			}
		} finally {
			$this->release_lock();
		}

		if ( ! $storage_exhausted ) {
			$this->schedule();
		}
	}

	/**
	 * Get the wall-clock budget for one cron invocation.
	 *
	 * A job already in progress may finish after the deadline. The worker checks
	 * the budget before claiming each subsequent job.
	 *
	 * @return float Runtime budget in seconds.
	 */
	private function batch_runtime_seconds(): float {
		$runtime = (float) apply_filters(
			'empirical_responsive_images_background_optimizer_runtime_seconds',
			self::DEFAULT_BATCH_RUNTIME
		);

		return max( self::MINIMUM_BATCH_RUNTIME, min( self::MAXIMUM_BATCH_RUNTIME, $runtime ) );
	}

	/**
	 * Check uploads filesystem capacity before claiming image-generation work.
	 *
	 * Unavailable filesystem statistics fail open. A known low-space result
	 * fails closed and leaves the queued job untouched for the watchdog.
	 *
	 * @return bool
	 */
	private function upload_storage_has_capacity(): bool {
		$uploads   = wp_get_upload_dir();
		$directory = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ? $uploads['basedir'] : '';
		$available = $this->upload_free_bytes( $directory );

		if ( null === $available ) {
			return true;
		}

		$reserve = max(
			0,
			(int) apply_filters(
				'empirical_responsive_images_minimum_free_upload_bytes',
				self::MINIMUM_FREE_UPLOAD_BYTES,
				$directory
			)
		);

		return $available >= $reserve;
	}

	/**
	 * Read free bytes without exposing filesystem warnings.
	 *
	 * @param string $directory Uploads base directory.
	 * @return float|null
	 */
	private function upload_free_bytes( string $directory ): ?float {
		$namespaced_function = __NAMESPACE__ . '\disk_free_space';

		if (
			'' === $directory
			|| ! is_dir( $directory )
			|| ( ! function_exists( $namespaced_function ) && ! function_exists( 'disk_free_space' ) )
		) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Contains one unsupported-filesystem warning around disk_free_space(), then restores the previous handler.
		set_error_handler(
			static function (): bool {
				return true;
			},
			E_WARNING
		);

		try {
			$available = disk_free_space( $directory );
		} catch ( \Throwable $error ) {
			$available = false;
		} finally {
			restore_error_handler();
		}

		if ( false === $available ) {
			return null;
		}

		return max( 0.0, (float) $available );
	}

	/**
	 * Process one claimed job and report its result to the store.
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $job    Claimed job.
	 * @return void
	 */
	private function process_job( string $job_id, array $job ): void {
		$token          = isset( $job['target'] ) && is_string( $job['target'] ) ? $job['target'] : '';
		$width          = absint( $job['width'] ?? 0 );
		$lease          = sanitize_text_field( (string) ( $job['lease_token'] ?? '' ) );
		$viewport_width = absint( $job['viewport_width'] ?? 0 );
		$slot_width     = absint( $job['slot_width'] ?? 0 );
		$creates_width  = ! empty( $job['creates_width'] );

		if ( '' === $token || $width < 1 || '' === $lease ) {
			$this->complete_safely( $job_id, $lease, false, 'invalid_job' );
			return;
		}

		if ( ! $this->renew_lock() ) {
			return;
		}

		$target = Visitor_Gap_Target::resolve( $token, $this->asset_manager );

		if ( empty( $target ) ) {
			$this->complete_safely( $job_id, $lease, false, 'target_unavailable' );
			return;
		}

		$minimum = max( 1, $this->settings->get_int( 'min_rendered_width' ) );
		$maximum = Visitor_Gap_Target::maximum_width( $target, $this->settings );

		if ( $width < $minimum || $width > $maximum ) {
			$this->store->discard_job( $job_id, $lease );
			return;
		}

		if ( 'attachment' === (string) ( $target['type'] ?? '' ) ) {
			if ( ! $this->renew_lock() ) {
				return;
			}

			$result = $this->process_attachment( $target, $width, $viewport_width, $slot_width, $creates_width );
		} elseif ( 'asset' === (string) ( $target['type'] ?? '' ) ) {
			if ( ! $this->settings->is_asset_processing_enabled() ) {
				$this->store->discard_job( $job_id, $lease );
				return;
			}

			if ( ! $this->renew_lock() ) {
				return;
			}

			$result = $this->process_asset( $target, $width, $viewport_width, $slot_width, $creates_width );
		} else {
			$result = array(
				'success' => false,
				'error'   => 'unsupported_target',
			);
		}

		if ( ! $this->renew_lock() ) {
			return;
		}

		$this->complete_safely(
			$job_id,
			$lease,
			! empty( $result['success'] ),
			sanitize_key( (string) ( $result['error'] ?? '' ) ),
			! empty( $result['success'] ) ? $this->target_page_urls( $target ) : array()
		);
	}

	/**
	 * Find the published pages known to show an attachment target.
	 *
	 * Covers the post the image was uploaded to and posts using it as their
	 * featured image. Archives and other listings pick up new widths when their
	 * cache expires. Visitor reports never carry page URLs.
	 *
	 * @param array $target Resolved target.
	 * @return string[]
	 */
	private function target_page_urls( array $target ): array {
		$attachment_id = 'attachment' === (string) ( $target['type'] ?? '' ) ? absint( $target['attachment_id'] ?? 0 ) : 0;
		$urls          = array();

		try {
			$urls = $attachment_id > 0 ? $this->attachment_page_urls( $attachment_id ) : array();
		} catch ( \Throwable $error ) {
			$urls = array();
		}

		/**
		 * Filter the pages purged after background work generates sizes for a target.
		 *
		 * @param string[] $urls   Page URLs.
		 * @param array    $target Resolved target.
		 */
		$urls = apply_filters( 'empirical_responsive_images_background_purge_urls', $urls, $target );

		return is_array( $urls ) ? array_values( array_filter( $urls, 'is_string' ) ) : array();
	}

	/**
	 * Published permalinks of an attachment's parent post and featured-image users.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string[]
	 */
	private function attachment_page_urls( int $attachment_id ): array {
		$urls     = array();
		$post_ids = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => 'publish',
				'meta_key'         => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded, indexed by meta_key.
				'meta_value'       => (string) $attachment_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded, indexed by meta_key.
				'fields'           => 'ids',
				'posts_per_page'   => 10,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		array_unshift( $post_ids, wp_get_post_parent_id( $attachment_id ) );

		foreach ( array_unique( array_map( 'absint', $post_ids ) ) as $post_id ) {
			$post_type = $post_id > 0 ? get_post_type( $post_id ) : false;

			if ( false === $post_type || 'publish' !== get_post_status( $post_id ) || ! is_post_type_viewable( $post_type ) ) {
				continue;
			}

			$url = get_permalink( $post_id );

			if ( is_string( $url ) && '' !== $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Generate one exact attachment sub-size and its enabled formats.
	 *
	 * @param array $target         Resolved attachment target.
	 * @param int   $width          Target width.
	 * @param int   $viewport_width Coarse viewport bucket.
	 * @param int   $slot_width     Coarse CSS slot bucket.
	 * @param bool  $creates_width  Whether evidence requested a new exact width.
	 * @return array
	 */
	private function process_attachment( array $target, int $width, int $viewport_width, int $slot_width, bool $creates_width ): array {
		$attachment_id    = absint( $target['attachment_id'] ?? 0 );
		$required_formats = Visitor_Gap_Target::get_applicable_formats(
			$target,
			$this->asset_manager,
			$this->format_generator->get_required_frontend_formats()
		);

		if ( $attachment_id < 1 || ! wp_attachment_is_image( $attachment_id ) ) {
			return array(
				'success' => false,
				'error'   => 'invalid_attachment',
			);
		}

		if (
			! Visitor_Gap_Target::is_optimization_covered( $target, $width, $this->asset_manager, $required_formats )
			|| ( $creates_width && ! Visitor_Gap_Target::has_exact_width( $target, $width, $this->asset_manager ) )
		) {
			if ( ! Visitor_Gap_Target::has_exact_width( $target, $width, $this->asset_manager ) ) {
				$size_name = Image_Sizes::name_for_width( $width );
				$filter    = static function ( array $sizes ) use ( $size_name ): array {
					if ( ! isset( $sizes[ $size_name ] ) ) {
						return array();
					}

					return array(
						$size_name => $sizes[ $size_name ],
					);
				};

				require_once ABSPATH . 'wp-admin/includes/image.php';

				if ( ! $this->renew_lock() ) {
					return array(
						'success' => false,
						'error'   => 'worker_lock_lost',
					);
				}

				add_image_size( $size_name, $width, 0, false );
				$current_metadata = wp_get_attachment_metadata( $attachment_id );

				if ( is_array( $current_metadata ) ) {
					if ( isset( $current_metadata['sizes'][ $size_name ] ) ) {
						unset( $current_metadata['sizes'][ $size_name ] );
						wp_update_attachment_metadata( $attachment_id, $current_metadata );
					}

					add_filter( 'wp_get_missing_image_subsizes', $filter, PHP_INT_MAX, 1 );

					try {
						$metadata = $this->format_generator->without_metadata_filter(
							static function () use ( $attachment_id ) {
								return wp_update_image_subsizes( $attachment_id );
							}
						);
					} finally {
						remove_filter( 'wp_get_missing_image_subsizes', $filter, PHP_INT_MAX );
					}
				} else {
					$metadata = $this->create_single_attachment_subsize_metadata( $attachment_id, $width, $size_name );
				}

				if ( is_wp_error( $metadata ) || ! is_array( $metadata ) ) {
					return array(
						'success' => false,
						'error'   => 'attachment_subsize_failed',
					);
				}
			}

			if ( ! Visitor_Gap_Target::has_exact_width( $target, $width, $this->asset_manager ) ) {
				return array(
					'success' => false,
					'error'   => 'attachment_width_missing',
				);
			}

			if ( ! $this->renew_lock() ) {
				return array(
					'success' => false,
					'error'   => 'worker_lock_lost',
				);
			}

			$formats = $this->format_generator->generate_for_attachment_width( $attachment_id, $width, false );

			if ( ! empty( $formats['errors'] ) ) {
				return array(
					'success' => false,
					'error'   => 'attachment_format_failed',
				);
			}
		}

		if (
			! Visitor_Gap_Target::is_optimization_covered( $target, $width, $this->asset_manager, $required_formats )
			|| ( $creates_width && ! Visitor_Gap_Target::has_exact_width( $target, $width, $this->asset_manager ) )
		) {
			return array(
				'success' => false,
				'error'   => 'attachment_optimization_missing',
			);
		}

		if (
			$viewport_width > 0
			&& $slot_width > 0
			&& ! $this->observation_store->record_automatic_attachment_slot( $attachment_id, $viewport_width, $slot_width )
		) {
			return array(
				'success' => false,
				'error'   => 'attachment_layout_persistence_failed',
			);
		}

		return array(
			'success' => true,
			'error'   => '',
		);
	}

	/**
	 * Repair missing metadata without invoking WordPress's full sub-size pipeline.
	 *
	 * The full pipeline may create a scaled replacement original before size
	 * filters run. Automatic learning must create only its one requested file.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param int    $width         Requested width.
	 * @param string $size_name     Deterministic metadata size name.
	 * @return array|\WP_Error
	 */
	private function create_single_attachment_subsize_metadata( int $attachment_id, int $width, string $size_name ) {
		$file = get_attached_file( $attachment_id );

		if ( ! is_string( $file ) || '' === $file || ! is_readable( $file ) ) {
			return new \WP_Error( 'empirical_responsive_images_attachment_source_missing', __( 'Attachment source file is unavailable.', 'empirical-responsive-images' ) );
		}

		$dimensions = wp_getimagesize( $file );

		if ( ! is_array( $dimensions ) || empty( $dimensions[0] ) || empty( $dimensions[1] ) ) {
			return new \WP_Error( 'empirical_responsive_images_attachment_dimensions_missing', __( 'Attachment dimensions are unavailable.', 'empirical-responsive-images' ) );
		}

		$source_width  = absint( $dimensions[0] );
		$source_height = absint( $dimensions[1] );
		$target_width  = min( $source_width, max( 1, $width ) );
		$target_height = max( 1, (int) round( $source_height * $target_width / $source_width ) );
		$uploads       = wp_get_upload_dir();
		$base_dir      = trailingslashit( (string) ( $uploads['basedir'] ?? '' ) );
		$relative      = 0 === strpos( wp_normalize_path( $file ), wp_normalize_path( $base_dir ) )
			? ltrim( substr( wp_normalize_path( $file ), strlen( wp_normalize_path( $base_dir ) ) ), '/' )
			: wp_basename( $file );
		$metadata      = array(
			'width'  => $source_width,
			'height' => $source_height,
			'file'   => wp_normalize_path( $relative ),
			'sizes'  => array(),
		);

		if ( $target_width >= $source_width ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );

			return $metadata;
		}

		$editor = wp_get_image_editor( $file );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		if ( method_exists( $editor, 'set_quality' ) ) {
			$extension = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
			$editor->set_quality( (int) apply_filters( 'empirical_responsive_images_output_quality', 82, $extension, $file ) );
		}

		$resized = $editor->resize( $target_width, null, false );

		if ( is_wp_error( $resized ) ) {
			return $resized;
		}

		$target_file = $editor->generate_filename( $target_width . 'x' . $target_height );
		$saved       = $editor->save( $target_file );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$saved_path                      = isset( $saved['path'] ) && is_string( $saved['path'] ) ? $saved['path'] : $target_file;
		$saved_width                     = absint( $saved['width'] ?? $target_width );
		$saved_height                    = absint( $saved['height'] ?? $target_height );
		$mime_type                       = isset( $saved['mime-type'] ) && is_string( $saved['mime-type'] ) ? $saved['mime-type'] : (string) ( wp_check_filetype( $saved_path )['type'] ?? '' );
		$metadata['sizes'][ $size_name ] = array(
			'file'      => wp_basename( $saved_path ),
			'width'     => $saved_width,
			'height'    => $saved_height,
			'mime-type' => $mime_type,
			'filesize'  => is_readable( $saved_path ) ? (int) filesize( $saved_path ) : 0,
		);

		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $metadata;
	}

	/**
	 * Generate one exact local asset width and register it for rendering.
	 *
	 * @param array $target         Resolved asset target.
	 * @param int   $width          Target width.
	 * @param int   $viewport_width Coarse viewport bucket.
	 * @param int   $slot_width     Coarse CSS slot bucket.
	 * @param bool  $creates_width  Whether evidence requested a new exact width.
	 * @return array
	 */
	private function process_asset( array $target, int $width, int $viewport_width, int $slot_width, bool $creates_width ): array {
		$url              = isset( $target['url'] ) && is_string( $target['url'] ) ? esc_url_raw( $target['url'] ) : '';
		$required_formats = Visitor_Gap_Target::get_applicable_formats(
			$target,
			$this->asset_manager,
			$this->format_generator->get_required_frontend_formats()
		);

		if ( '' === $url ) {
			return array(
				'success' => false,
				'error'   => 'invalid_asset',
			);
		}

		if (
			! Visitor_Gap_Target::is_optimization_covered( $target, $width, $this->asset_manager, $required_formats )
			|| ( $creates_width && ! Visitor_Gap_Target::has_exact_width( $target, $width, $this->asset_manager ) )
		) {
			if ( ! $this->renew_lock() ) {
				return array(
					'success' => false,
					'error'   => 'worker_lock_lost',
				);
			}

			$generated = $this->asset_manager->generate_for_asset_width( $url, $width, false );

			if ( ! empty( $generated['errors'] ) ) {
				return array(
					'success' => false,
					'error'   => 'asset_generation_failed',
				);
			}
		}

		if (
			! Visitor_Gap_Target::is_optimization_covered( $target, $width, $this->asset_manager, $required_formats )
			|| ( $creates_width && ! Visitor_Gap_Target::has_exact_width( $target, $width, $this->asset_manager ) )
		) {
			return array(
				'success' => false,
				'error'   => 'asset_optimization_missing',
			);
		}

		/*
		 * Registration enriches empirical sizes, but rendering also discovers
		 * current variants directly from disk. A full/busy bounded observation
		 * option must not turn valid generated files into a failed job.
		 */
		if ( ! $this->observation_store->record_automatic_asset_width( $url, $width, $viewport_width, $slot_width ) ) {
			return array(
				'success' => false,
				'error'   => 'asset_observation_persistence_failed',
			);
		}

		return array(
			'success' => true,
			'error'   => '',
		);
	}

	/**
	 * Purge stale page markup only after the job store declares a completed run.
	 *
	 * Only the pages known to show the finished targets are purged. A full purge
	 * here cleared whole sites, CDN included, each night the daily-capped queue
	 * resumed. Other pages pick up the new widths when their cache expires.
	 *
	 * @return void
	 */
	private function maybe_purge_completed_work(): void {
		if ( ! $this->store->should_purge_cache() ) {
			return;
		}

		$generation = $this->store->get_cache_generation();

		try {
			foreach ( $this->store->get_cache_purge_urls() as $url ) {
				Cache_Compat::purge_url( $url );
			}

			$this->store->mark_cache_purged( $generation );
		} catch ( \Throwable $error ) {
			return;
		}
	}

	/**
	 * Complete a failed job without allowing error recovery to break the worker.
	 *
	 * @param string $job_id      Job identifier.
	 * @param string $lease_token Current claim lease token.
	 * @param bool   $success     Whether processing succeeded.
	 * @param string $error       Stable error key.
	 * @param array  $purge_urls  Pages to purge once the run's work is done.
	 * @return void
	 */
	private function complete_safely( string $job_id, string $lease_token, bool $success, string $error, array $purge_urls = array() ): void {
		for ( $attempt = 0; $attempt < 120; ++$attempt ) {
			try {
				$result = $this->store->complete_job( $job_id, $lease_token, $success, $error, $purge_urls );

				if ( ! empty( $result['updated'] ) || ! empty( $result['stale'] ) ) {
					return;
				}
			} catch ( \Throwable $completion_error ) {
				$result = array();
			}

			if ( $attempt < 119 ) {
				usleep( 50000 );
			}
		}
	}

	/**
	 * Schedule one fixed-argument batch event.
	 *
	 * @param int $delay Delay in seconds.
	 * @return bool
	 */
	private static function schedule_batch_event( int $delay ): bool {
		if ( false !== wp_next_scheduled( self::BATCH_HOOK ) ) {
			return true;
		}

		$result = wp_schedule_single_event(
			time() + max( 1, $delay ),
			self::BATCH_HOOK,
			array(),
			true
		);

		return true === $result;
	}

	/**
	 * Acquire fixed-key worker lock.
	 *
	 * @return bool
	 */
	private function acquire_lock(): bool {
		$this->lock_token = Option_Lock::acquire( self::LOCK_OPTION, self::LOCK_TTL_SECONDS );

		return '' !== $this->lock_token;
	}

	/**
	 * Extend worker ownership before another external mutation.
	 *
	 * @return bool Whether this worker still owns the lock.
	 */
	private function renew_lock(): bool {
		return Option_Lock::refresh( self::LOCK_OPTION, $this->lock_token, self::LOCK_TTL_SECONDS );
	}

	/**
	 * Release only the worker lock owned by this process.
	 *
	 * @return void
	 */
	private function release_lock(): void {
		Option_Lock::release( self::LOCK_OPTION, $this->lock_token );
		$this->lock_token = '';
	}
}
