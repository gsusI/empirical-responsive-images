<?php
/**
 * Automatic background optimizer tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

namespace EmpiricalResponsiveImages;

function disk_free_space( string $directory ) {
	$GLOBALS['eri_test_disk_free_space_paths'][] = $directory;
	$available = $GLOBALS['eri_test_disk_free_space'] ?? null;

	if ( 'warning' === $available ) {
		return \disk_free_space( '/empirical-responsive-images-test-path-does-not-exist' );
	}

	if ( null !== $available ) {
		return $available;
	}

	return function_exists( 'disk_free_space' ) ? \disk_free_space( $directory ) : false;
}

function microtime( bool $as_float = false ) {
	if ( ! empty( $GLOBALS['eri_test_monotonic_times'] ) ) {
		$value = array_shift( $GLOBALS['eri_test_monotonic_times'] );

		return $as_float ? (float) $value : (string) $value;
	}

	return \microtime( $as_float );
}

namespace EmpiricalResponsiveImages\Tests;

use EmpiricalResponsiveImages\Asset_Manager;
use EmpiricalResponsiveImages\Background_Optimizer;
use EmpiricalResponsiveImages\Format_Generator;
use EmpiricalResponsiveImages\Observation_Store;
use EmpiricalResponsiveImages\Settings;
use EmpiricalResponsiveImages\Visitor_Gap_Store;
use EmpiricalResponsiveImages\Visitor_Gap_Target;
use PHPUnit\Framework\TestCase;

final class BackgroundOptimizerTest extends TestCase {
	protected function setUp(): void {
		eri_test_reset_state();

		$settings                 = ( new Settings() )->defaults();
		$settings['webp_enabled'] = false;
		$settings['avif_enabled'] = false;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;
		$GLOBALS['eri_test_disk_free_space']                   = PHP_INT_MAX;
		$GLOBALS['eri_test_disk_free_space_paths']             = array();
		$GLOBALS['eri_test_monotonic_times']                    = array();
	}

	protected function tearDown(): void {
		$GLOBALS['eri_test_disk_free_space']       = null;
		$GLOBALS['eri_test_disk_free_space_paths'] = array();
		$GLOBALS['eri_test_monotonic_times']        = array();
	}

	public function test_activation_schedules_one_fixed_batch_and_watchdog(): void {
		$settings = new Settings();

		Background_Optimizer::activate( $settings );
		Background_Optimizer::activate( $settings );

		self::assertCount( 2, $GLOBALS['eri_test_cron_events'] );
		self::assertNotFalse( wp_next_scheduled( Background_Optimizer::BATCH_HOOK ) );
		self::assertNotFalse( wp_next_scheduled( Background_Optimizer::WATCHDOG_HOOK ) );

		foreach ( $GLOBALS['eri_test_cron_events'] as $event ) {
			self::assertSame( array(), $event['args'] );
		}
	}

	public function test_deactivation_clears_events_without_stealing_a_live_worker_lock(): void {
		Background_Optimizer::activate( new Settings() );
		$GLOBALS['eri_test_options']['empirical_responsive_images_background_optimizer_lock'] = array(
			'token'      => 'worker',
			'expires_at' => time() + 300,
		);

		Background_Optimizer::deactivate();

		self::assertSame( array(), $GLOBALS['eri_test_cron_events'] );
		self::assertSame(
			'worker',
			$GLOBALS['eri_test_options']['empirical_responsive_images_background_optimizer_lock']['token']
		);
	}

	public function test_below_threshold_evidence_does_not_schedule_worker(): void {
		list( $optimizer, $store, $target ) = $this->services();

		$store->record_gaps( array( $this->gap( $target, 704 ) ), new Settings() );

		self::assertFalse( $optimizer->schedule() );
		self::assertSame( array(), $GLOBALS['eri_test_cron_events'] );
	}

	public function test_active_worker_lock_defers_without_claiming_job(): void {
		list( $optimizer, $store, $target ) = $this->services();

		$this->qualify_gap( $store, $target, 704 );
		$GLOBALS['eri_test_options']['empirical_responsive_images_background_optimizer_lock'] = array(
			'token'      => 'another-worker',
			'expires_at' => time() + 300,
		);

		$optimizer->run();

		$job = reset( $store->get_data()['jobs'] );

		self::assertSame( 'queued', $job['status'] );
		self::assertSame( 0, $job['attempts'] );
		self::assertNotFalse( wp_next_scheduled( Background_Optimizer::BATCH_HOOK ) );
	}

	public function test_qualified_gap_schedules_one_event_and_completes_without_admin_action(): void {
		list( $optimizer, $store, $target ) = $this->services();

		$this->qualify_gap( $store, $target, 704 );

		self::assertTrue( $optimizer->schedule() );
		self::assertTrue( $optimizer->schedule() );
		self::assertCount( 1, $GLOBALS['eri_test_cron_events'] );

		$GLOBALS['eri_test_cron_events'] = array();
		$metadata                        = $GLOBALS['eri_test_attachment_metadata'][303];
		$metadata['sizes']['empirical-responsive-images-796'] = array(
			'file'   => 'source-icon-1024.png',
			'width'  => 796,
			'height' => 498,
		);
		$GLOBALS['eri_test_attachment_metadata'][303] = $metadata;

		$optimizer->run();

		$data   = $store->get_data();
		$ledger = reset( $data['targets'] );

		self::assertSame( array(), $data['jobs'] );
		self::assertSame( array( 796 ), $ledger['widths'] );
		self::assertFalse( $data['cache']['dirty'] );
		self::assertSame( 1, $GLOBALS['eri_test_siteground_purge_all'] );
	}

	public function test_run_drains_multiple_qualified_targets_within_runtime_budget(): void {
		list( $optimizer, $store, $first_target ) = $this->services();
		$second_target                            = $this->add_attachment( 304 );
		$third_target                             = $this->add_attachment( 305 );

		foreach ( array( $first_target, $second_target, $third_target ) as $target ) {
			$store->record_gaps( array( $this->gap( $target, 704 ) ), new Settings(), true );
		}

		foreach ( array( 303, 304, 305 ) as $attachment_id ) {
			$this->add_covered_attachment_size( $attachment_id );
		}

		$optimizer->run();

		$data = $store->get_data();

		self::assertSame( array(), $data['jobs'] );
		self::assertCount( 3, $data['targets'] );
		self::assertSame( 3, $data['daily']['processed'] );
		self::assertSame( 1, $GLOBALS['eri_test_siteground_purge_all'] );
	}

	public function test_run_stops_before_claiming_another_job_after_runtime_budget(): void {
		list( $optimizer, $store, $first_target ) = $this->services();
		$second_target                            = $this->add_attachment( 304 );

		foreach ( array( $first_target, $second_target ) as $target ) {
			$store->record_gaps( array( $this->gap( $target, 704 ) ), new Settings(), true );
		}

		$this->add_covered_attachment_size( 303 );
		$this->add_covered_attachment_size( 304 );

		add_filter(
			'empirical_responsive_images_background_optimizer_runtime_seconds',
			static function (): int {
				return 1;
			}
		);

		$GLOBALS['eri_test_monotonic_times'] = array( 100.0, 102.0 );

		$optimizer->run();

		$data = $store->get_data();

		self::assertCount( 1, $data['jobs'] );
		self::assertCount( 1, $data['targets'] );
		self::assertSame( 1, $data['daily']['processed'] );
		self::assertNotFalse( wp_next_scheduled( Background_Optimizer::BATCH_HOOK ) );
	}

	public function test_low_upload_space_leaves_qualified_job_queued_for_watchdog(): void {
		list( $optimizer, $store, $target ) = $this->services();

		$this->qualify_gap( $store, $target, 704 );
		$GLOBALS['eri_test_disk_free_space'] = 268435455;

		$optimizer->run();

		$job = reset( $store->get_data()['jobs'] );

		self::assertSame( 'queued', $job['status'] );
		self::assertSame( 0, $job['attempts'] );
		self::assertNotFalse( wp_next_scheduled( Background_Optimizer::WATCHDOG_HOOK ) );
		self::assertFalse( wp_next_scheduled( Background_Optimizer::BATCH_HOOK ) );
		self::assertArrayNotHasKey( 'empirical_responsive_images_background_optimizer_lock', $GLOBALS['eri_test_options'] );
		self::assertSame( array( dirname( __DIR__ ) . '/.wordpress-org' ), $GLOBALS['eri_test_disk_free_space_paths'] );
	}

	public function test_free_upload_reserve_is_filterable(): void {
		list( $optimizer, $store, $target ) = $this->services();

		$this->qualify_gap( $store, $target, 704 );
		$this->add_covered_attachment_size();
		$GLOBALS['eri_test_disk_free_space'] = 1024;

		add_filter(
			'empirical_responsive_images_minimum_free_upload_bytes',
			static function (): int {
				return 512;
			}
		);

		$optimizer->run();

		self::assertSame( array(), $store->get_data()['jobs'] );
	}

	public function test_unavailable_free_space_data_fails_open_without_warning(): void {
		list( $optimizer, $store, $target ) = $this->services();

		$this->qualify_gap( $store, $target, 704 );
		$this->add_covered_attachment_size();
		$GLOBALS['eri_test_disk_free_space'] = 'warning';

		set_error_handler(
			static function ( int $severity, string $message ): bool {
				throw new \ErrorException( $message, 0, $severity );
			}
		);

		try {
			$optimizer->run();
		} finally {
			restore_error_handler();
		}

		self::assertSame( array(), $store->get_data()['jobs'] );
	}

	/**
	 * Build optimizer services and one signed attachment target.
	 *
	 * @return array
	 */
	private function services(): array {
		$attachment_id = 303;
		$settings      = new Settings();
		$asset_manager = new Asset_Manager( $settings );
		$observations  = new Observation_Store( $asset_manager );
		$store         = new Visitor_Gap_Store( $asset_manager );
		$formats       = new Format_Generator( $settings );

		$GLOBALS['eri_test_image_attachments'][ $attachment_id ]   = true;
		$GLOBALS['eri_test_attachment_files'][ $attachment_id ]    = dirname( __DIR__ ) . '/.wordpress-org/source-icon-1024.png';
		$GLOBALS['eri_test_uploads']                               = array(
			'basedir' => dirname( __DIR__ ) . '/.wordpress-org',
			'baseurl' => 'https://example.test/plugin-assets',
		);
		$GLOBALS['eri_test_attachment_metadata'][ $attachment_id ] = array(
			'file'   => 'source-icon-1024.png',
			'width'  => 1600,
			'height' => 1000,
			'sizes'  => array(),
		);

		$target    = Visitor_Gap_Target::token_for_attachment( $attachment_id );
		$optimizer = new Background_Optimizer( $settings, $store, $asset_manager, $observations, $formats );

		return array( $optimizer, $store, $target );
	}

	/**
	 * Register another covered test attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Signed visitor-gap target.
	 */
	private function add_attachment( int $attachment_id ): string {
		$GLOBALS['eri_test_image_attachments'][ $attachment_id ]   = true;
		$GLOBALS['eri_test_attachment_files'][ $attachment_id ]    = dirname( __DIR__ ) . '/.wordpress-org/source-icon-1024.png';
		$GLOBALS['eri_test_attachment_metadata'][ $attachment_id ] = array(
			'file'   => 'source-icon-1024.png',
			'width'  => 1600,
			'height' => 1000,
			'sizes'  => array(),
		);

		return Visitor_Gap_Target::token_for_attachment( $attachment_id );
	}

	/**
	 * Qualify a target across three server windows.
	 *
	 * @param Visitor_Gap_Store $store  Store.
	 * @param string            $target Target.
	 * @param int               $width  Width.
	 * @return void
	 */
	private function qualify_gap( Visitor_Gap_Store $store, string $target, int $width ): void {
		for ( $confirmation = 1; $confirmation <= 3; ++$confirmation ) {
			$store->record_gaps( array( $this->gap( $target, $width ) ), new Settings() );

			if ( $confirmation < 3 ) {
				$data = $store->get_data();

				foreach ( $data['jobs'] as &$job ) {
					$job['last_window'] = (int) $job['last_window'] - 1;
				}
				unset( $job );

				$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $data;
			}
		}
	}

	/**
	 * Add an already-covered size so storage admission tests avoid file writes.
	 *
	 * @return void
	 */
	private function add_covered_attachment_size( int $attachment_id = 303 ): void {
		$metadata = $GLOBALS['eri_test_attachment_metadata'][ $attachment_id ];
		$metadata['sizes']['empirical-responsive-images-796'] = array(
			'file'   => 'source-icon-1024.png',
			'width'  => 796,
			'height' => 498,
		);
		$GLOBALS['eri_test_attachment_metadata'][ $attachment_id ] = $metadata;
	}

	/**
	 * Build one target-bound gap.
	 *
	 * @param string $target Target.
	 * @param int    $width  Width.
	 * @return array
	 */
	private function gap( string $target, int $width ): array {
		return compact( 'target', 'width' );
	}
}
