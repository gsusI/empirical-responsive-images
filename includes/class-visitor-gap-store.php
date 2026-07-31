<?php
/**
 * Bounded storage and queue for visitor image-gap evidence.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores target-bound evidence and exposes one bounded background queue.
 */
final class Visitor_Gap_Store {
	public const OPTION_NAME             = 'empirical_responsive_images_visitor_gap_hints';
	public const WRITE_LOCK_OPTION       = 'empirical_responsive_images_visitor_gap_hints_lock';
	public const MAX_GAPS_PER_REPORT     = 12;
	public const MAX_WIDTHS_PER_REPORT   = self::MAX_GAPS_PER_REPORT;
	public const BUCKET_PROTOCOL_VERSION = 2;

	private const DATA_VERSION                   = 5;
	private const WRITE_LOCK_TTL_SECONDS         = 5;
	private const CONFIRMATION_WINDOW            = 900;
	private const PENDING_RETENTION_SECONDS      = 2592000;
	private const FAILURE_RETENTION_SECONDS      = 2592000;
	private const FAILURE_REOPEN_COOLDOWN        = 86400;
	private const PROCESSING_STALE_SECONDS       = 900;
	private const MAX_JOBS                       = 256;
	private const MAX_ACTIVE_TARGETS             = 256;
	private const MAX_PROBATION_JOBS             = 96;
	private const MAX_QUALIFIED_JOBS             = 50;
	private const MAX_FAILED_JOBS                = 32;
	private const MAX_COMPLETED_TARGET_HISTORY   = 256;
	private const MAX_LEARNED_WIDTHS_PER_TARGET  = 38;
	private const MAX_CONFIRMATIONS              = 1000000;
	private const MAX_REPORTS_PER_WINDOW         = 120;
	private const MAX_PAIRS_PER_WINDOW           = 256;
	private const MAX_GENERATIONS_PER_DAY        = 50;
	private const MAX_ATTEMPTS                   = 3;
	private const CACHE_PURGE_INTERVAL           = 900;
	private const TRUSTED_CONFIRMATIONS_REQUIRED = 1;

	/**
	 * Local asset manager.
	 *
	 * @var Asset_Manager|null
	 */
	private $asset_manager;

	/**
	 * Modern format generator.
	 *
	 * @var Format_Generator|null
	 */
	private $format_generator;

	/**
	 * Token owned by current write lock.
	 *
	 * @var string
	 */
	private $write_lock_token = '';

	/**
	 * Constructor.
	 *
	 * @param Asset_Manager|null    $asset_manager    Local asset manager.
	 * @param Format_Generator|null $format_generator Modern format generator.
	 */
	public function __construct( ?Asset_Manager $asset_manager = null, ?Format_Generator $format_generator = null ) {
		$this->asset_manager    = $asset_manager;
		$this->format_generator = $format_generator;
	}

	/**
	 * Ensure current storage exists. Old width-only hints cannot be migrated safely.
	 *
	 * @return void
	 */
	public function ensure_option(): void {
		$current = get_option( self::OPTION_NAME, false );

		if ( false === $current ) {
			add_option( self::OPTION_NAME, $this->empty_data(), '', false );
			return;
		}

		if ( ! is_array( $current ) || self::DATA_VERSION !== absint( $current['version'] ?? 0 ) ) {
			update_option( self::OPTION_NAME, $this->empty_data(), false );
		}
	}

	/**
	 * Build stable same-site report token.
	 *
	 * This exposed token reduces blind cross-site noise. It is not authentication.
	 *
	 * @return string
	 */
	public static function report_token(): string {
		$site_id = function_exists( 'get_current_blog_id' ) ? absint( get_current_blog_id() ) : 0;
		$context = $site_id . '|' . strtolower( untrailingslashit( home_url( '/' ) ) );

		return hash_hmac( 'sha256', 'empirical-responsive-images-visitor-gap-report|' . $context, wp_salt( 'nonce' ) );
	}

	/**
	 * Validate same-site report token.
	 *
	 * @param string $token Submitted token.
	 * @return bool
	 */
	public static function is_valid_report_token( string $token ): bool {
		return '' !== $token && hash_equals( self::report_token(), $token );
	}

	/**
	 * Get strict canonical physical-width buckets for automatic learning.
	 *
	 * This is the minimum integer palette covering 1 through 5,120 while every
	 * bucket stays between 98 and 120 percent of its target width.
	 *
	 * @return array
	 */
	public static function canonical_widths(): array {
		return array(
			1,
			2,
			3,
			4,
			6,
			8,
			10,
			13,
			16,
			20,
			25,
			31,
			38,
			46,
			56,
			69,
			85,
			104,
			128,
			157,
			193,
			236,
			289,
			354,
			434,
			531,
			650,
			796,
			975,
			1194,
			1462,
			1790,
			2192,
			2684,
			3286,
			4024,
			4928,
			5120,
		);
	}

	/**
	 * Map a physical target to the strict canonical palette.
	 *
	 * A target-specific ceiling can replace the final palette value. Because
	 * the target is clamped to that same ceiling, the result remains inside the
	 * strict coverage band.
	 *
	 * @param int $target_width Physical target width.
	 * @param int $maximum      Target-specific maximum.
	 * @return int
	 */
	public static function canonical_width( int $target_width, int $maximum = 5120 ): int {
		$maximum = max( 1, min( 5120, $maximum ) );
		$target  = min( $maximum, max( 1, $target_width ) );

		foreach ( self::canonical_widths() as $bucket ) {
			if ( ( 50 * $bucket ) >= ( 49 * $target ) ) {
				return min( $bucket, $maximum );
			}
		}

		return $maximum;
	}

	/**
	 * Empty persisted data.
	 *
	 * @return array
	 */
	public function empty_data(): array {
		return array(
			'version'                => self::DATA_VERSION,
			'updated_at'             => 0,
			'accepted_confirmations' => 0,
			'queue_sequence'         => 0,
			'jobs'                   => array(),
			'targets'                => array(),
			'rate'                   => array(
				'window'  => -1,
				'reports' => 0,
				'pairs'   => 0,
			),
			'daily'                  => array(
				'day'       => '',
				'processed' => 0,
			),
			'cache'                  => array(
				'dirty'         => false,
				'generation'    => 0,
				'last_purge_at' => 0,
			),
		);
	}

	/**
	 * Read normalized persisted data.
	 *
	 * @return array
	 */
	public function get_data(): array {
		$data = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $data ) || self::DATA_VERSION !== absint( $data['version'] ?? 0 ) ) {
			return $this->empty_data();
		}

		$data = wp_parse_args( $data, $this->empty_data() );

		foreach ( array( 'jobs', 'targets', 'rate', 'daily', 'cache' ) as $key ) {
			if ( ! is_array( $data[ $key ] ) ) {
				$data[ $key ] = $this->empty_data()[ $key ];
			}
		}

		$data['rate']           = array(
			'window'  => (int) ( $data['rate']['window'] ?? -1 ),
			'reports' => absint( $data['rate']['reports'] ?? 0 ),
			'pairs'   => absint( $data['rate']['pairs'] ?? 0 ),
		);
		$data['daily']          = wp_parse_args( $data['daily'], $this->empty_data()['daily'] );
		$data['cache']          = wp_parse_args( $data['cache'], $this->empty_data()['cache'] );
		$data['targets']        = $this->trim_completed_targets( $data['targets'] );
		$data['queue_sequence'] = absint( $data['queue_sequence'] ?? 0 );

		return $data;
	}

	/**
	 * Get sorted pending/queued visitor jobs for read-only status display.
	 *
	 * @param Settings $settings Settings.
	 * @return array
	 */
	public function get_hints( Settings $settings ): array {
		$now    = time();
		$stored = $this->get_data();
		$data   = $this->prune_jobs( $stored, $now, $settings );

		if ( $data['jobs'] !== $stored['jobs'] && $this->acquire_write_lock() ) {
			try {
				$current = $this->get_data();
				$data    = $this->prune_jobs( $current, $now, $settings );

				if ( $data['jobs'] !== $current['jobs'] ) {
					$data['updated_at'] = $now;
					$this->persist_locked_data( $data );
				}
			} finally {
				$this->release_write_lock();
			}
		}

		$hints = array_values( $data['jobs'] );

		foreach ( $hints as &$hint ) {
			$status            = sanitize_key( (string) ( $hint['status'] ?? 'pending' ) );
			$hint['qualified'] = in_array( $status, array( 'queued', 'processing', 'retry_wait' ), true );
		}
		unset( $hint );

		usort(
			$hints,
			static function ( array $a, array $b ): int {
				$status_order = array(
					'processing' => 0,
					'queued'     => 1,
					'retry_wait' => 2,
					'pending'    => 3,
					'failed'     => 4,
				);
				$a_status     = sanitize_key( (string) ( $a['status'] ?? 'pending' ) );
				$b_status     = sanitize_key( (string) ( $b['status'] ?? 'pending' ) );
				$order        = ( $status_order[ $a_status ] ?? 5 ) <=> ( $status_order[ $b_status ] ?? 5 );

				if ( 0 !== $order ) {
					return $order;
				}

				$first_seen_order = absint( $a['first_seen'] ?? 0 ) <=> absint( $b['first_seen'] ?? 0 );

				if ( 0 !== $first_seen_order ) {
					return $first_seen_order;
				}

				$width_order = absint( $a['width'] ?? 0 ) <=> absint( $b['width'] ?? 0 );

				return 0 !== $width_order
					? $width_order
					: strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
			}
		);

		return $hints;
	}

	/**
	 * Record signed target/width pairs.
	 *
	 * Each pair can advance once per server window. Qualification automatically
	 * moves it into the fixed background queue.
	 *
	 * @param array    $gaps                 Raw target/width pairs.
	 * @param Settings $settings             Settings.
	 * @param bool     $trusted_confirmation Whether capability and nonce authenticated this report.
	 * @return array
	 */
	public function record_gaps( array $gaps, Settings $settings, bool $trusted_confirmation = false ): array {
		if ( ! $settings->is_visitor_gap_learning_enabled() ) {
			return array(
				'accepted' => 0,
				'disabled' => true,
			);
		}

		if ( empty( $gaps ) ) {
			return array(
				'accepted' => 0,
				'ignored'  => true,
			);
		}

		if ( ! $this->acquire_write_lock() ) {
			return array(
				'accepted' => 0,
				'busy'     => true,
			);
		}

		try {
			$now            = time();
			$current_window = (int) floor( $now / self::CONFIRMATION_WINDOW );
			$data           = $this->prune_jobs( $this->get_data(), $now, $settings );
			$data['rate']   = $this->rate_for_window( $data['rate'], $current_window );

			if ( absint( $data['rate']['reports'] ?? 0 ) >= self::MAX_REPORTS_PER_WINDOW || absint( $data['rate']['pairs'] ?? 0 ) >= self::MAX_PAIRS_PER_WINDOW ) {
				return array(
					'accepted'              => 0,
					'rate_limited'          => true,
					'accepted_rate_limited' => true,
					'hints'                 => count( $data['jobs'] ),
				);
			}

			$accepted = 0;
			$queued   = 0;
			$seen     = array();

			foreach ( array_slice( $gaps, 0, self::MAX_GAPS_PER_REPORT ) as $gap ) {
				if ( absint( $data['rate']['pairs'] ?? 0 ) >= self::MAX_PAIRS_PER_WINDOW ) {
					break;
				}

				if ( ! is_array( $gap ) || ! is_string( $gap['target'] ?? null ) || ! is_numeric( $gap['width'] ?? null ) ) {
					continue;
				}

				$raw_key = hash( 'sha256', sanitize_text_field( $gap['target'] ) . '|' . (string) (float) $gap['width'] );

				if ( isset( $seen[ $raw_key ] ) ) {
					continue;
				}

				$seen[ $raw_key ] = true;
				$normalized       = $this->normalize_gap( $gap, $settings, $data, $current_window, $now, $trusted_confirmation );

				if ( empty( $normalized ) ) {
					continue;
				}

				$job_id    = $this->job_id( $normalized['target'], $normalized['width'] );
				$exists    = isset( $data['jobs'][ $job_id ] ) && is_array( $data['jobs'][ $job_id ] );
				$reopening = $exists && 'failed' === (string) ( $data['jobs'][ $job_id ]['status'] ?? '' );

				if (
					( ! $exists || $reopening )
					&& (
						$this->target_width_budget_exhausted( $data, $normalized )
						|| ! $this->prepare_queue_admission( $data, $normalized )
					)
				) {
					continue;
				}

				$job = $exists
					? $data['jobs'][ $job_id ]
					: array(
						'id'                   => $job_id,
						'target_key'           => $normalized['target_key'],
						'target'               => $normalized['target'],
						'type'                 => $normalized['type'],
						'label'                => $normalized['label'],
						'width'                => $normalized['width'],
						'requested_width'      => $normalized['requested_width'],
						'viewport_width'       => $normalized['viewport_width'],
						'slot_width'           => $normalized['slot_width'],
						'creates_width'        => $normalized['creates_width'],
						'confirmations'        => 0,
						'first_seen'           => $now,
						'last_seen'            => 0,
						'last_window'          => -1,
						'status'               => 'pending',
						'attempts'             => 0,
						'started_at'           => 0,
						'lease_token'          => '',
						'next_attempt_at'      => 0,
						'last_error'           => '',
						'terminal_at'          => 0,
						'qualified_at'         => 0,
						'queue_order'          => 0,
						'trusted_confirmation' => false,
					);

				if ( $reopening ) {
					$job = $this->reopen_failed_job( $job, $now );
				}

				foreach ( array( 'requested_width', 'viewport_width', 'slot_width' ) as $metric ) {
					if ( absint( $normalized[ $metric ] ?? 0 ) > 0 ) {
						$job[ $metric ] = absint( $normalized[ $metric ] );
					}
				}

				$status          = (string) ( $job['status'] ?? '' );
				$trusted_upgrade = ! empty( $normalized['trusted_confirmation'] ) && empty( $job['trusted_confirmation'] );

				if (
					in_array( $status, array( 'queued', 'processing', 'retry_wait', 'failed' ), true )
					|| ( (int) ( $job['last_window'] ?? -1 ) === $current_window && ! $trusted_upgrade )
				) {
					continue;
				}

				if ( ! empty( $normalized['trusted_confirmation'] ) ) {
					$job['trusted_confirmation'] = true;
				}

				if ( (int) ( $job['last_window'] ?? -1 ) !== $current_window ) {
					$job['confirmations'] = min( self::MAX_CONFIRMATIONS, absint( $job['confirmations'] ?? 0 ) + 1 );
				} else {
					$job['confirmations'] = max( 1, absint( $job['confirmations'] ?? 0 ) );
				}

				$job['last_seen']   = $now;
				$job['last_window'] = $current_window;

				if (
					0 === absint( $job['qualified_at'] ?? 0 )
					&& absint( $job['confirmations'] ) >= $this->confirmations_required( $job, $settings )
				) {
					$job['qualified_at'] = $now;
				}

				$data['jobs'][ $job_id ] = $job;
				++$data['rate']['pairs'];
				++$accepted;
			}

			if ( $accepted > 0 ) {
				++$data['rate']['reports'];
				$queued += $this->promote_qualified_pending_jobs( $data, $settings );
			}

			if ( $accepted > 0 ) {
				$data['updated_at']             = $now;
				$data['accepted_confirmations'] = min(
					self::MAX_CONFIRMATIONS,
					absint( $data['accepted_confirmations'] ?? 0 ) + $accepted
				);

				if ( ! $this->persist_locked_data( $data ) ) {
					return array(
						'accepted'      => 0,
						'busy'          => true,
						'storage_error' => true,
					);
				}
			}

			return array(
				'accepted'     => $accepted,
				'queued'       => $queued,
				'hints'        => count( $data['jobs'] ),
				'needs_worker' => $queued > 0 || $this->data_has_runnable_jobs( $data, $now ),
			);
		} finally {
			$this->release_write_lock();
		}
	}

	/**
	 * Claim one due job and consume one fixed daily work unit.
	 *
	 * @return array
	 */
	public function claim_next_job(): array {
		if ( ! $this->acquire_write_lock() ) {
			return array();
		}

		try {
			$now  = time();
			$data = $this->prune_jobs( $this->recover_stale_processing( $this->get_data(), $now ), $now, new Settings() );
			$data = $this->reset_daily_counter( $data, $now );
			$this->promote_qualified_pending_jobs( $data, new Settings() );

			if ( absint( $data['daily']['processed'] ?? 0 ) >= self::MAX_GENERATIONS_PER_DAY ) {
				$this->persist_locked_data( $data );
				return array();
			}

			$processing_targets = array();

			foreach ( $data['jobs'] as $stored_job ) {
				if ( is_array( $stored_job ) && 'processing' === (string) ( $stored_job['status'] ?? '' ) ) {
					$processing_targets[ (string) ( $stored_job['target_key'] ?? '' ) ] = true;
				}
			}

			$candidates = array_filter(
				$data['jobs'],
				static function ( $job ) use ( $now, $processing_targets ): bool {
					if ( ! is_array( $job ) ) {
						return false;
					}

					$status     = (string) ( $job['status'] ?? '' );
					$target_key = (string) ( $job['target_key'] ?? '' );

					return ! isset( $processing_targets[ $target_key ] )
						&& ( 'queued' === $status || ( 'retry_wait' === $status && absint( $job['next_attempt_at'] ?? 0 ) <= $now ) );
				}
			);

			uasort(
				$candidates,
				static function ( array $a, array $b ): int {
					$a_order = absint( $a['queue_order'] ?? 0 );
					$b_order = absint( $b['queue_order'] ?? 0 );

					if ( $a_order !== $b_order ) {
						if ( 0 === $a_order ) {
							return -1;
						}

						if ( 0 === $b_order ) {
							return 1;
						}

						return $a_order <=> $b_order;
					}

					$first_seen_order = absint( $a['first_seen'] ?? 0 ) <=> absint( $b['first_seen'] ?? 0 );

					return 0 !== $first_seen_order
						? $first_seen_order
						: strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
				}
			);

			$job = reset( $candidates );

			if ( ! is_array( $job ) || empty( $job['id'] ) || ! isset( $data['jobs'][ $job['id'] ] ) ) {
				$this->persist_locked_data( $data );
				return array();
			}

			$job['status']      = 'processing';
			$job['started_at']  = $now;
			$job['attempts']    = min( self::MAX_ATTEMPTS, absint( $job['attempts'] ?? 0 ) + 1 );
			$job['lease_token'] = hash(
				'sha256',
				wp_generate_uuid4() . '|' . $job['id'] . '|' . $job['attempts'] . '|' . $now
			);

			$data['jobs'][ $job['id'] ] = $job;
			++$data['daily']['processed'];
			$data['updated_at'] = $now;

			if ( ! $this->persist_locked_data( $data ) ) {
				return array();
			}

			return $job;
		} finally {
			$this->release_write_lock();
		}
	}

	/**
	 * Complete or retry one claimed job.
	 *
	 * @param string $job_id      Job ID.
	 * @param string $lease_token Current claim lease token.
	 * @param bool   $success     Whether target generation succeeded.
	 * @param string $error       Bounded error description.
	 * @return array
	 */
	public function complete_job( string $job_id, string $lease_token, bool $success, string $error = '' ): array {
		if ( ! $this->acquire_write_lock() ) {
			return array(
				'updated' => false,
				'busy'    => true,
			);
		}

		try {
			$now  = time();
			$data = $this->get_data();
			$job  = isset( $data['jobs'][ $job_id ] ) && is_array( $data['jobs'][ $job_id ] ) ? $data['jobs'][ $job_id ] : array();

			if (
				empty( $job )
				|| 'processing' !== (string) ( $job['status'] ?? '' )
				|| '' === $lease_token
				|| ! hash_equals( (string) ( $job['lease_token'] ?? '' ), $lease_token )
			) {
				return array(
					'updated' => false,
					'stale'   => true,
				);
			}

			if ( $success ) {
				$target_key = (string) ( $job['target_key'] ?? '' );
				$target     = isset( $data['targets'][ $target_key ] ) && is_array( $data['targets'][ $target_key ] )
					? $data['targets'][ $target_key ]
					: array(
						'target'     => (string) ( $job['target'] ?? '' ),
						'type'       => sanitize_key( (string) ( $job['type'] ?? '' ) ),
						'label'      => sanitize_text_field( (string) ( $job['label'] ?? '' ) ),
						'widths'     => array(),
						'created_at' => $now,
						'updated_at' => 0,
					);
				$widths     = is_array( $target['widths'] ?? null ) ? array_map( 'absint', $target['widths'] ) : array();
				$widths[]   = absint( $job['width'] ?? 0 );
				$widths     = array_values( array_filter( array_unique( $widths ) ) );
				sort( $widths );

				$target['widths']               = array_slice( $widths, 0, self::MAX_LEARNED_WIDTHS_PER_TARGET );
				$target['updated_at']           = $now;
				$data['targets'][ $target_key ] = $target;
				$data['cache']['dirty']         = true;
				$data['cache']['generation']    = min( PHP_INT_MAX, absint( $data['cache']['generation'] ?? 0 ) + 1 );
				$data['targets']                = $this->trim_completed_targets( $data['targets'] );
				unset( $data['jobs'][ $job_id ] );
			} else {
				$job['last_error']  = substr( sanitize_text_field( $error ), 0, 200 );
				$job['started_at']  = 0;
				$job['lease_token'] = '';

				if ( absint( $job['attempts'] ?? 0 ) >= self::MAX_ATTEMPTS ) {
					$job['status']      = 'failed';
					$job['terminal_at'] = $now;
				} else {
					$delays                 = array( 900, 7200, 86400 );
					$job['status']          = 'retry_wait';
					$job['next_attempt_at'] = $now + $delays[ max( 0, absint( $job['attempts'] ?? 1 ) - 1 ) ];
				}

				$data['jobs'][ $job_id ] = $job;
			}

			$this->promote_qualified_pending_jobs( $data, new Settings() );
			$data['updated_at'] = $now;

			if ( ! $this->persist_locked_data( $data ) ) {
				return array(
					'updated'       => false,
					'storage_error' => true,
				);
			}

			return array(
				'updated' => true,
				'success' => $success,
				'pending' => count( $data['jobs'] ),
			);
		} finally {
			$this->release_write_lock();
		}
	}

	/**
	 * Remove one claimed job that is no longer enabled.
	 *
	 * @param string $job_id      Job ID.
	 * @param string $lease_token Optional current claim lease.
	 * @return bool Whether a job was removed.
	 */
	public function discard_job( string $job_id, string $lease_token = '' ): bool {
		if ( ! $this->acquire_write_lock() ) {
			return false;
		}

		try {
			$data = $this->get_data();

			if ( ! isset( $data['jobs'][ $job_id ] ) || ! is_array( $data['jobs'][ $job_id ] ) ) {
				return false;
			}

			$job = $data['jobs'][ $job_id ];

			if (
				'processing' === (string) ( $job['status'] ?? '' )
				&& ( '' === $lease_token || ! hash_equals( (string) ( $job['lease_token'] ?? '' ), $lease_token ) )
			) {
				return false;
			}

			unset( $data['jobs'][ $job_id ] );
			$this->promote_qualified_pending_jobs( $data, new Settings() );
			$data['updated_at'] = time();

			return $this->persist_locked_data( $data );
		} finally {
			$this->release_write_lock();
		}
	}

	/**
	 * Remove queued evidence for a target type disabled in settings.
	 *
	 * @param string $type Target type.
	 * @return int Number of jobs removed.
	 */
	public function discard_jobs_by_type( string $type ): int {
		$type = sanitize_key( $type );

		if ( '' === $type || ! $this->acquire_write_lock() ) {
			return 0;
		}

		try {
			$data    = $this->get_data();
			$removed = 0;

			foreach ( $data['jobs'] as $job_id => $job ) {
				if (
					! is_array( $job )
					|| sanitize_key( (string) ( $job['type'] ?? '' ) ) !== $type
					|| 'processing' === (string) ( $job['status'] ?? '' )
				) {
					continue;
				}

				unset( $data['jobs'][ $job_id ] );
				++$removed;
			}

			if ( $removed > 0 ) {
				$this->promote_qualified_pending_jobs( $data, new Settings() );
				$data['updated_at'] = time();

				if ( ! $this->persist_locked_data( $data ) ) {
					return 0;
				}
			}

			return $removed;
		} finally {
			$this->release_write_lock();
		}
	}

	/**
	 * Check whether immediate or recoverable work exists.
	 *
	 * @return bool
	 */
	public function has_runnable_jobs(): bool {
		return $this->data_has_runnable_jobs( $this->get_data(), time() );
	}

	/**
	 * Seconds before next queue/retry/cache task should run.
	 *
	 * Zero means no scheduled work remains.
	 *
	 * @return int
	 */
	public function seconds_until_next_work(): int {
		$now    = time();
		$data   = $this->get_data();
		$data   = $this->reset_daily_counter( $data, $now );
		$delays = array();

		if ( ! empty( $data['cache']['dirty'] ) ) {
			$delays[] = max( 60, absint( $data['cache']['last_purge_at'] ?? 0 ) + self::CACHE_PURGE_INTERVAL - $now );
		}

		if ( absint( $data['daily']['processed'] ?? 0 ) >= self::MAX_GENERATIONS_PER_DAY ) {
			$tomorrow = strtotime( 'tomorrow 00:00 UTC', $now );
			$delays[] = false !== $tomorrow ? max( 60, $tomorrow - $now ) : 3600;

			return min( $delays );
		}

		foreach ( $data['jobs'] as $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}

			$status = (string) ( $job['status'] ?? '' );

			if ( 'queued' === $status ) {
				$delays[] = 60;
			} elseif ( 'retry_wait' === $status ) {
				$delays[] = max( 60, absint( $job['next_attempt_at'] ?? 0 ) - $now );
			} elseif ( 'processing' === $status ) {
				$delays[] = max( 60, absint( $job['started_at'] ?? 0 ) + self::PROCESSING_STALE_SECONDS - $now );
			}
		}

		return empty( $delays ) ? 0 : min( $delays );
	}

	/**
	 * Check whether dirty generated output may purge cache now.
	 *
	 * @return bool
	 */
	public function should_purge_cache(): bool {
		$data = $this->get_data();

		return ! empty( $data['cache']['dirty'] )
			&& absint( $data['cache']['last_purge_at'] ?? 0 ) <= time() - self::CACHE_PURGE_INTERVAL;
	}

	/**
	 * Get generation represented by current dirty cache state.
	 *
	 * @return int
	 */
	public function get_cache_generation(): int {
		return absint( $this->get_data()['cache']['generation'] ?? 0 );
	}

	/**
	 * Mark one completed, debounced cache purge if its generation is current.
	 *
	 * @param int $expected_generation Generation that was purged.
	 * @return bool Whether the current generation was marked purged.
	 */
	public function mark_cache_purged( int $expected_generation ): bool {
		if ( ! $this->acquire_write_lock() ) {
			return false;
		}

		try {
			$data = $this->get_data();

			if ( absint( $data['cache']['generation'] ?? 0 ) !== $expected_generation ) {
				return false;
			}

			$data['cache']['dirty']         = false;
			$data['cache']['last_purge_at'] = time();
			$data['updated_at']             = time();

			return $this->persist_locked_data( $data );
		} finally {
			$this->release_write_lock();
		}
	}

	/**
	 * Normalize and verify one signed gap pair.
	 *
	 * @param mixed    $gap                  Raw pair.
	 * @param Settings $settings             Settings.
	 * @param array    $data                 Current queue data.
	 * @param int      $current_window       Current confirmation window.
	 * @param int      $now                  Current timestamp.
	 * @param bool     $trusted_confirmation Whether capability and nonce authenticated this report.
	 * @return array
	 */
	private function normalize_gap( $gap, Settings $settings, array $data, int $current_window, int $now, bool $trusted_confirmation ): array {
		if ( ! is_array( $gap ) || empty( $gap['target'] ) || ! is_string( $gap['target'] ) || ! isset( $gap['width'] ) || ! is_numeric( $gap['width'] ) ) {
			return array();
		}

		$target_token = sanitize_text_field( $gap['target'] );

		if ( '' === $target_token || strlen( $target_token ) > 1024 ) {
			return array();
		}

		$target = Visitor_Gap_Target::resolve( $target_token, $this->get_asset_manager() );

		if ( empty( $target ) ) {
			return array();
		}

		if ( 'asset' === (string) ( $target['type'] ?? '' ) && ! $settings->is_asset_processing_enabled() ) {
			return array();
		}

		$minimum      = max( 1, $settings->get_int( 'min_rendered_width' ) );
		$source_width = absint( $target['source_width'] ?? 0 );
		$maximum      = Visitor_Gap_Target::maximum_width( $target, $settings );
		$raw_width    = (float) $gap['width'];

		if ( $source_width < 1 || $maximum < $minimum || $raw_width < $minimum ) {
			return array();
		}

		$reported_width      = min( $maximum, max( 1, (int) ceil( $raw_width ) ) );
		$is_current_protocol = self::BUCKET_PROTOCOL_VERSION === absint( $gap['_bucket_protocol_version'] ?? 0 );
		$hinted_native_width = $is_current_protocol ? absint( $gap['native_width'] ?? 0 ) : 0;
		$native_width        = $is_current_protocol
			? (
				$hinted_native_width > 0
				&& $hinted_native_width <= $maximum
				&& Visitor_Gap_Target::has_exact_width( $target, $hinted_native_width, $this->get_asset_manager() )
					? $hinted_native_width
					: 0
			)
			: Visitor_Gap_Target::covering_width( $target, $reported_width, $this->get_asset_manager(), $maximum );
		$requested_width     = $native_width > 0
			? $reported_width
			: self::canonical_width( $reported_width, $maximum );

		$work_width = $native_width > 0 ? $native_width : $requested_width;
		$job_id     = $this->job_id( $target_token, $work_width );
		$job        = isset( $data['jobs'][ $job_id ] ) && is_array( $data['jobs'][ $job_id ] ) ? $data['jobs'][ $job_id ] : array();

		if ( ! empty( $job ) ) {
			$status = (string) ( $job['status'] ?? '' );

			if ( in_array( $status, array( 'queued', 'processing', 'retry_wait' ), true ) ) {
				return array();
			}

			if ( 'failed' === $status ) {
				if ( ! $this->failure_can_reopen( $job, $now ) ) {
					return array();
				}
			} elseif (
				absint( $job['confirmations'] ?? 0 ) >= $this->confirmations_required( $job, $settings )
				|| (
					(int) ( $job['last_window'] ?? -1 ) === $current_window
					&& ( ! $trusted_confirmation || ! empty( $job['trusted_confirmation'] ) )
				)
			) {
				return array();
			}
		}

		$required_formats = Visitor_Gap_Target::get_applicable_formats(
			$target,
			$this->get_asset_manager(),
			$this->get_format_generator( $settings )->get_required_frontend_formats()
		);
		$type             = sanitize_key( (string) ( $target['type'] ?? '' ) );
		$viewport_width   = $this->normalize_metric( $gap['viewport'] ?? 0, 128, 5120 );
		$slot_width       = $this->normalize_metric( $gap['slot'] ?? 0, 16, 5120 );

		if ( ! $this->layout_metrics_are_plausible( $reported_width, $viewport_width, $slot_width ) ) {
			$viewport_width = 0;
			$slot_width     = 0;
		}

		$has_layout_hint   = $viewport_width > 0 && $slot_width > 0;
		$already_optimized = $is_current_protocol
			? (
				$native_width > 0
				&& Visitor_Gap_Target::is_optimization_covered( $target, $native_width, $this->get_asset_manager(), $required_formats )
			)
			: Visitor_Gap_Target::is_optimization_covered( $target, $reported_width, $this->get_asset_manager(), $required_formats );

		if ( $requested_width < $minimum || ( $already_optimized && ! $has_layout_hint ) ) {
			return array();
		}

		$label = 'attachment' === $type
			? '#' . absint( $target['attachment_id'] ?? 0 )
			: sanitize_text_field( (string) ( $target['url'] ?? __( 'Local asset', 'empirical-responsive-images' ) ) );

		return array(
			'target'               => $target_token,
			'target_key'           => substr( hash( 'sha256', $target_token ), 0, 32 ),
			'type'                 => $type,
			'label'                => $label,
			'width'                => $work_width,
			'requested_width'      => $requested_width,
			'viewport_width'       => $viewport_width,
			'slot_width'           => $slot_width,
			'creates_width'        => 0 === $native_width,
			'managed_widths'       => Visitor_Gap_Target::get_managed_widths( $target, $this->get_asset_manager() ),
			'trusted_confirmation' => $trusted_confirmation,
		);
	}

	/**
	 * Get confirmation threshold for one pending job.
	 *
	 * @param array    $job      Pending job.
	 * @param Settings $settings Settings.
	 * @return int
	 */
	private function confirmations_required( array $job, Settings $settings ): int {
		return ! empty( $job['trusted_confirmation'] )
			? self::TRUSTED_CONFIRMATIONS_REQUIRED
			: max( 1, $settings->get_int( 'visitor_gap_min_confirmations' ) );
	}

	/**
	 * Normalize one anonymous layout metric into a fixed bounded bucket.
	 *
	 * @param mixed $value Raw metric.
	 * @param int   $step  Bucket step.
	 * @param int   $limit Maximum accepted value.
	 * @return int
	 */
	private function normalize_metric( $value, int $step, int $limit ): int {
		if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
			return 0;
		}

		return min( $limit, (int) ( ceil( (float) $value / $step ) * $step ) );
	}

	/**
	 * Reject anonymous layout hints that cannot describe the reported image.
	 *
	 * @param int $physical_width Coarse physical-pixel target.
	 * @param int $viewport_width Coarse CSS viewport width.
	 * @param int $slot_width     Coarse CSS slot width.
	 * @return bool
	 */
	private function layout_metrics_are_plausible( int $physical_width, int $viewport_width, int $slot_width ): bool {
		if ( $viewport_width < 1 || $slot_width < 1 || $physical_width < 1 ) {
			return false;
		}

		if ( $slot_width > $viewport_width + 32 ) {
			return false;
		}

		return $physical_width >= (int) floor( $slot_width * 0.8 )
			&& $physical_width <= (int) ceil( $slot_width * 4.8 );
	}

	/**
	 * Check fixed per-target and global target budgets.
	 *
	 * @param array $data       Stored data.
	 * @param array $normalized Normalized pair.
	 * @return bool
	 */
	private function target_width_budget_exhausted( array $data, array $normalized ): bool {
		if ( empty( $normalized['creates_width'] ) ) {
			return false;
		}

		$target_key = (string) $normalized['target_key'];
		$width      = absint( $normalized['width'] );
		$widths     = is_array( $normalized['managed_widths'] ?? null )
			? array_map( 'absint', $normalized['managed_widths'] )
			: array();

		foreach ( $data['jobs'] as $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}

			$status = sanitize_key( (string) ( $job['status'] ?? 'pending' ) );

			if ( ! in_array( $status, array( 'pending', 'queued', 'processing', 'retry_wait' ), true ) ) {
				continue;
			}

			if ( (string) ( $job['target_key'] ?? '' ) === $target_key && ! empty( $job['creates_width'] ) ) {
				$widths[] = absint( $job['width'] ?? 0 );
			}
		}

		$widths = array_values( array_filter( array_unique( $widths ) ) );

		if ( in_array( $width, $widths, true ) ) {
			return false;
		}

		return count( $widths ) >= self::MAX_LEARNED_WIDTHS_PER_TARGET;
	}

	/**
	 * Make bounded room for one brand-new evidence pair.
	 *
	 * Unqualified entries are probationary. They cannot occupy the entire queue.
	 * Pending evidence may be displaced under pressure so continuously replayed
	 * anonymous reports cannot permanently block a new target.
	 *
	 * @param array $data       Stored data, modified when one pending entry is evicted.
	 * @param array $normalized Normalized new pair.
	 * @return bool
	 */
	private function prepare_queue_admission( array &$data, array $normalized ): bool {
		$counts        = $this->active_queue_counts( $data );
		$is_new_target = ! isset( $counts['targets'][ (string) $normalized['target_key'] ] );
		$needs_room    = $counts['active'] >= self::MAX_JOBS
			|| $counts['probation'] >= self::MAX_PROBATION_JOBS
			|| ( $is_new_target && count( $counts['targets'] ) >= self::MAX_ACTIVE_TARGETS );

		if ( ! $needs_room ) {
			return true;
		}

		$candidates = array();

		foreach ( $data['jobs'] as $job_id => $job ) {
			if (
				! is_array( $job )
				|| 'pending' !== (string) ( $job['status'] ?? '' )
			) {
				continue;
			}

			$candidates[ $job_id ] = $job;
		}

		uasort(
			$candidates,
			static function ( array $a, array $b ): int {
				$last_seen_order = absint( $a['last_seen'] ?? 0 ) <=> absint( $b['last_seen'] ?? 0 );

				if ( 0 !== $last_seen_order ) {
					return $last_seen_order;
				}

				$first_seen_order = absint( $a['first_seen'] ?? 0 ) <=> absint( $b['first_seen'] ?? 0 );

				return 0 !== $first_seen_order
					? $first_seen_order
					: strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
			}
		);

		foreach ( array_keys( $candidates ) as $eviction_id ) {
			$trial = $data;
			unset( $trial['jobs'][ $eviction_id ] );

			$trial_counts  = $this->active_queue_counts( $trial );
			$is_new_target = ! isset( $trial_counts['targets'][ (string) $normalized['target_key'] ] );
			$fits          = $trial_counts['active'] < self::MAX_JOBS
				&& $trial_counts['probation'] < self::MAX_PROBATION_JOBS
				&& ( ! $is_new_target || count( $trial_counts['targets'] ) < self::MAX_ACTIVE_TARGETS );

			if ( $fits ) {
				$data = $trial;
				return true;
			}
		}

		return false;
	}

	/**
	 * Promote confirmed evidence into a drain-matched, target-fair work queue.
	 *
	 * At most one job per target can be qualified at once. Other confirmed
	 * widths remain pending and are promoted automatically as capacity opens.
	 *
	 * @param array    $data     Stored data, modified in place.
	 * @param Settings $settings Settings.
	 * @return int Number of newly queued jobs.
	 */
	private function promote_qualified_pending_jobs( array &$data, Settings $settings ): int {
		$qualified_targets = array();
		$qualified_count   = 0;
		$candidates        = array();

		foreach ( $data['jobs'] as $job_id => $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}

			$status     = (string) ( $job['status'] ?? '' );
			$target_key = (string) ( $job['target_key'] ?? '' );

			if ( in_array( $status, array( 'queued', 'processing', 'retry_wait' ), true ) ) {
				++$qualified_count;

				if ( '' !== $target_key ) {
					$qualified_targets[ $target_key ] = true;
				}
			} elseif (
				'pending' === $status
				&& absint( $job['confirmations'] ?? 0 ) >= $this->confirmations_required( $job, $settings )
			) {
				if ( 0 === absint( $job['qualified_at'] ?? 0 ) ) {
					$job['qualified_at'] = max(
						absint( $job['last_seen'] ?? 0 ),
						absint( $job['first_seen'] ?? 0 )
					);

					$data['jobs'][ $job_id ]['qualified_at'] = $job['qualified_at'];
				}

				$candidates[ $job_id ] = $job;
			}
		}

		uasort(
			$candidates,
			static function ( array $a, array $b ): int {
				$confirmation_order = absint( $a['confirmations'] ?? 0 ) <=> absint( $b['confirmations'] ?? 0 );

				if ( 0 !== $confirmation_order ) {
					return $confirmation_order;
				}

				$qualified_order = absint( $a['qualified_at'] ?? 0 ) <=> absint( $b['qualified_at'] ?? 0 );

				if ( 0 !== $qualified_order ) {
					return $qualified_order;
				}

				$first_seen_order = absint( $a['first_seen'] ?? 0 ) <=> absint( $b['first_seen'] ?? 0 );

				return 0 !== $first_seen_order
					? $first_seen_order
					: strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
			}
		);

		$promoted = 0;

		foreach ( $candidates as $job_id => $job ) {
			if ( $qualified_count >= self::MAX_QUALIFIED_JOBS ) {
				break;
			}

			$target_key = (string) ( $job['target_key'] ?? '' );

			if ( '' === $target_key || isset( $qualified_targets[ $target_key ] ) ) {
				continue;
			}

			$data['queue_sequence']                 = min( PHP_INT_MAX, absint( $data['queue_sequence'] ?? 0 ) + 1 );
			$data['jobs'][ $job_id ]['status']      = 'queued';
			$data['jobs'][ $job_id ]['queue_order'] = $data['queue_sequence'];
			$qualified_targets[ $target_key ]       = true;
			++$qualified_count;
			++$promoted;
		}

		return $promoted;
	}

	/**
	 * Count active jobs, probation entries, and active target keys.
	 *
	 * @param array $data Stored data.
	 * @return array
	 */
	private function active_queue_counts( array $data ): array {
		$active  = 0;
		$pending = 0;
		$targets = array();

		foreach ( $data['jobs'] as $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}

			$status = (string) ( $job['status'] ?? '' );

			if ( ! in_array( $status, array( 'pending', 'queued', 'processing', 'retry_wait' ), true ) ) {
				continue;
			}

			++$active;

			if (
				'pending' === $status
				&& absint( $job['confirmations'] ?? 0 ) <= 1
				&& empty( $job['trusted_confirmation'] )
			) {
				++$pending;
			}

			$target_key = (string) ( $job['target_key'] ?? '' );

			if ( '' !== $target_key ) {
				$targets[ $target_key ] = true;
			}
		}

		return array(
			'active'    => $active,
			'probation' => $pending,
			'targets'   => $targets,
		);
	}

	/**
	 * Build fixed job ID.
	 *
	 * @param string $target Signed target.
	 * @param int    $width  Width.
	 * @return string
	 */
	private function job_id( string $target, int $width ): string {
		return substr( hash( 'sha256', $target . '|' . $width ), 0, 32 );
	}

	/**
	 * Reset fixed global rate counters for a new server window.
	 *
	 * @param array $rate           Stored rate data.
	 * @param int   $current_window Current window.
	 * @return array
	 */
	private function rate_for_window( array $rate, int $current_window ): array {
		if ( (int) ( $rate['window'] ?? -1 ) !== $current_window ) {
			return array(
				'window'  => $current_window,
				'reports' => 0,
				'pairs'   => 0,
			);
		}

		return array(
			'window'  => $current_window,
			'reports' => absint( $rate['reports'] ?? 0 ),
			'pairs'   => absint( $rate['pairs'] ?? 0 ),
		);
	}

	/**
	 * Check whether a terminal failure may collect a fresh evidence cycle.
	 *
	 * @param array $job Failed job.
	 * @param int   $now Current timestamp.
	 * @return bool
	 */
	private function failure_can_reopen( array $job, int $now ): bool {
		$terminal_at = absint( $job['terminal_at'] ?? 0 );

		return $terminal_at > 0 && $terminal_at <= $now - self::FAILURE_REOPEN_COOLDOWN;
	}

	/**
	 * Reset a cooled-down failure before accepting fresh evidence.
	 *
	 * @param array $job Failed job.
	 * @param int   $now Current timestamp.
	 * @return array
	 */
	private function reopen_failed_job( array $job, int $now ): array {
		$job['status']               = 'pending';
		$job['confirmations']        = 0;
		$job['first_seen']           = $now;
		$job['last_seen']            = 0;
		$job['last_window']          = -1;
		$job['attempts']             = 0;
		$job['started_at']           = 0;
		$job['lease_token']          = '';
		$job['next_attempt_at']      = 0;
		$job['last_error']           = '';
		$job['terminal_at']          = 0;
		$job['qualified_at']         = 0;
		$job['queue_order']          = 0;
		$job['trusted_confirmation'] = false;

		return $job;
	}

	/**
	 * Keep only the most recently completed target histories.
	 *
	 * @param array $targets Stored completed targets.
	 * @return array
	 */
	private function trim_completed_targets( array $targets ): array {
		$normalized = array();

		foreach ( $targets as $target_key => $target ) {
			if ( ! is_string( $target_key ) || '' === $target_key || ! is_array( $target ) ) {
				continue;
			}

			$widths = is_array( $target['widths'] ?? null ) ? array_map( 'absint', $target['widths'] ) : array();
			$widths = array_values( array_filter( array_unique( $widths ) ) );
			sort( $widths );

			if ( empty( $widths ) ) {
				continue;
			}

			$target['widths']          = array_slice( $widths, 0, self::MAX_LEARNED_WIDTHS_PER_TARGET );
			$target['created_at']      = absint( $target['created_at'] ?? 0 );
			$target['updated_at']      = absint( $target['updated_at'] ?? 0 );
			$normalized[ $target_key ] = $target;
		}

		uasort(
			$normalized,
			static function ( array $a, array $b ): int {
				$updated_order = absint( $b['updated_at'] ?? 0 ) <=> absint( $a['updated_at'] ?? 0 );

				return 0 !== $updated_order
					? $updated_order
					: absint( $b['created_at'] ?? 0 ) <=> absint( $a['created_at'] ?? 0 );
			}
		);

		return array_slice( $normalized, 0, self::MAX_COMPLETED_TARGET_HISTORY, true );
	}

	/**
	 * Drop stale pending and terminal jobs.
	 *
	 * Queued and processing jobs never expire.
	 *
	 * @param array    $data     Stored data.
	 * @param int      $now      Current timestamp.
	 * @param Settings $settings Settings.
	 * @return array
	 */
	private function prune_jobs( array $data, int $now, Settings $settings ): array {
		$minimum = max( 1, $settings->get_int( 'min_rendered_width' ) );
		$maximum = min( Visitor_Gap_Target::MAX_AUTOMATIC_WIDTH, $settings->get_int( 'max_registered_width' ) );

		foreach ( $data['jobs'] as $key => $job ) {
			if ( ! is_array( $job ) ) {
				unset( $data['jobs'][ $key ] );
				continue;
			}

			$status = (string) ( $job['status'] ?? 'pending' );
			$width  = absint( $job['width'] ?? 0 );

			if ( 'processing' !== $status && ( $width < $minimum || $width > $maximum ) ) {
				unset( $data['jobs'][ $key ] );
			} elseif ( 'pending' === $status && absint( $job['last_seen'] ?? 0 ) < $now - self::PENDING_RETENTION_SECONDS ) {
				unset( $data['jobs'][ $key ] );
			} elseif ( 'failed' === $status && absint( $job['terminal_at'] ?? 0 ) < $now - self::FAILURE_RETENTION_SECONDS ) {
				unset( $data['jobs'][ $key ] );
			}
		}

		$data['jobs'] = $this->trim_failed_jobs( $data['jobs'] );

		return $data;
	}

	/**
	 * Keep a small recent failure history without consuming active queue space.
	 *
	 * @param array $jobs Stored jobs.
	 * @return array
	 */
	private function trim_failed_jobs( array $jobs ): array {
		$failed = array_filter(
			$jobs,
			static function ( $job ): bool {
				return is_array( $job ) && 'failed' === (string) ( $job['status'] ?? '' );
			}
		);

		uasort(
			$failed,
			static function ( array $a, array $b ): int {
				return absint( $b['terminal_at'] ?? 0 ) <=> absint( $a['terminal_at'] ?? 0 );
			}
		);

		$keep = array_fill_keys( array_slice( array_keys( $failed ), 0, self::MAX_FAILED_JOBS ), true );

		foreach ( array_keys( $failed ) as $job_id ) {
			if ( ! isset( $keep[ $job_id ] ) ) {
				unset( $jobs[ $job_id ] );
			}
		}

		return $jobs;
	}

	/**
	 * Recover abandoned processing jobs without creating another queue key.
	 *
	 * @param array $data Stored data.
	 * @param int   $now  Current timestamp.
	 * @return array
	 */
	private function recover_stale_processing( array $data, int $now ): array {
		foreach ( $data['jobs'] as $key => $job ) {
			if ( ! is_array( $job ) || 'processing' !== (string) ( $job['status'] ?? '' ) || absint( $job['started_at'] ?? 0 ) > $now - self::PROCESSING_STALE_SECONDS ) {
				continue;
			}

			if ( absint( $job['attempts'] ?? 0 ) >= self::MAX_ATTEMPTS ) {
				$job['status']      = 'failed';
				$job['terminal_at'] = $now;
				$job['last_error']  = __( 'Background processing timed out.', 'empirical-responsive-images' );
			} else {
				$job['status']          = 'retry_wait';
				$job['next_attempt_at'] = $now;
			}

			$job['started_at']    = 0;
			$job['lease_token']   = '';
			$data['jobs'][ $key ] = $job;
		}

		return $data;
	}

	/**
	 * Reset fixed daily generation counter.
	 *
	 * @param array $data Stored data.
	 * @param int   $now  Current timestamp.
	 * @return array
	 */
	private function reset_daily_counter( array $data, int $now ): array {
		$day = gmdate( 'Y-m-d', $now );

		if ( (string) ( $data['daily']['day'] ?? '' ) !== $day ) {
			$data['daily'] = array(
				'day'       => $day,
				'processed' => 0,
			);
		}

		return $data;
	}

	/**
	 * Check whether stored data contains a runnable or stale job.
	 *
	 * @param array $data Stored data.
	 * @param int   $now  Current timestamp.
	 * @return bool
	 */
	private function data_has_runnable_jobs( array $data, int $now ): bool {
		foreach ( $data['jobs'] as $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}

			$status = (string) ( $job['status'] ?? '' );

			if ( 'queued' === $status || ( 'retry_wait' === $status && absint( $job['next_attempt_at'] ?? 0 ) <= $now ) || ( 'processing' === $status && absint( $job['started_at'] ?? 0 ) <= $now - self::PROCESSING_STALE_SECONDS ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get local asset manager.
	 *
	 * @return Asset_Manager
	 */
	private function get_asset_manager(): Asset_Manager {
		if ( null === $this->asset_manager ) {
			$this->asset_manager = new Asset_Manager( new Settings() );
		}

		return $this->asset_manager;
	}

	/**
	 * Get request-local modern format generator.
	 *
	 * @param Settings $settings Settings service.
	 * @return Format_Generator
	 */
	private function get_format_generator( Settings $settings ): Format_Generator {
		if ( null === $this->format_generator ) {
			$this->format_generator = new Format_Generator( $settings );
		}

		return $this->format_generator;
	}

	/**
	 * Acquire short-lived fixed-key write lock.
	 *
	 * @return bool
	 */
	private function acquire_write_lock(): bool {
		$this->write_lock_token = Option_Lock::acquire( self::WRITE_LOCK_OPTION, self::WRITE_LOCK_TTL_SECONDS );

		return '' !== $this->write_lock_token;
	}

	/**
	 * Persist data only while this request still owns a renewed write lock.
	 *
	 * @param array $data Complete store data.
	 * @return bool Whether the exact state is durable.
	 */
	private function persist_locked_data( array $data ): bool {
		if ( ! Option_Lock::refresh( self::WRITE_LOCK_OPTION, $this->write_lock_token, self::WRITE_LOCK_TTL_SECONDS ) ) {
			return false;
		}

		update_option( self::OPTION_NAME, $data, false );

		return get_option( self::OPTION_NAME, null ) === $data;
	}

	/**
	 * Release only lock owned by current request.
	 *
	 * @return void
	 */
	private function release_write_lock(): void {
		Option_Lock::release( self::WRITE_LOCK_OPTION, $this->write_lock_token );
		$this->write_lock_token = '';
	}
}
