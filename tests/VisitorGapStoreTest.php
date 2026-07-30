<?php
/**
 * Visitor gap queue tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

namespace EmpiricalResponsiveImages\Tests;

use EmpiricalResponsiveImages\Format_Generator;
use EmpiricalResponsiveImages\Image_Sizes;
use EmpiricalResponsiveImages\Settings;
use EmpiricalResponsiveImages\Visitor_Gap_Store;
use EmpiricalResponsiveImages\Visitor_Gap_Target;
use PHPUnit\Framework\TestCase;

final class VisitorGapStoreTest extends TestCase {
	protected function setUp(): void {
		eri_test_reset_state();

		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = ( new Settings() )->defaults();
	}

	public function test_public_report_token_is_stable_and_validated(): void {
		$token = Visitor_Gap_Store::report_token();

		self::assertSame( $token, Visitor_Gap_Store::report_token() );
		self::assertTrue( Visitor_Gap_Store::is_valid_report_token( $token ) );
		self::assertFalse( Visitor_Gap_Store::is_valid_report_token( 'forged' ) );
	}

	public function test_signed_target_resolves_and_forgery_fails(): void {
		$token = $this->attachment_target();

		self::assertNotSame( '', $token );
		self::assertSame( 101, Visitor_Gap_Target::resolve( $token, $this->asset_manager() )['attachment_id'] );
		self::assertSame( array(), Visitor_Gap_Target::resolve( $token . 'x', $this->asset_manager() ) );
	}

	public function test_nearby_widths_share_one_target_bound_bucket_and_window(): void {
		$store  = new Visitor_Gap_Store( $this->asset_manager() );
		$target = $this->attachment_target();
		$result = $store->record_gaps(
			array(
				$this->gap( $target, 641 ),
				$this->gap( $target, 642 ),
			),
			new Settings()
		);
		$jobs   = $store->get_data()['jobs'];

		self::assertSame( 1, $result['accepted'] );
		self::assertCount( 1, $jobs );
		self::assertSame( 650, reset( $jobs )['width'] );
		self::assertSame( 1, reset( $jobs )['confirmations'] );
	}

	public function test_strict_canonical_palette_covers_every_supported_width(): void {
		$palette  = Visitor_Gap_Store::canonical_widths();
		$observed = array();

		self::assertCount( 38, $palette );

		for ( $target = 1; $target <= 5120; ++$target ) {
			$bucket = Visitor_Gap_Store::canonical_width( $target, 5120 );

			self::assertGreaterThanOrEqual( 49 * $target, 50 * $bucket );
			self::assertLessThanOrEqual( 6 * $target, 5 * $bucket );
			self::assertSame( $bucket, Visitor_Gap_Store::canonical_width( $bucket, 5120 ) );
			$observed[ $bucket ] = true;
		}

		self::assertSame( $palette, array_map( 'intval', array_keys( $observed ) ) );
		self::assertSame( 4, Visitor_Gap_Store::canonical_width( 4, 5120 ) );
		self::assertSame( 6, Visitor_Gap_Store::canonical_width( 5, 5120 ) );
		self::assertSame( 56, Visitor_Gap_Store::canonical_width( 57, 5120 ) );
		self::assertSame( 69, Visitor_Gap_Store::canonical_width( 58, 5120 ) );
		self::assertSame( 4928, Visitor_Gap_Store::canonical_width( 5028, 5120 ) );
		self::assertSame( 5120, Visitor_Gap_Store::canonical_width( 5029, 5120 ) );
	}

	public function test_three_distinct_windows_automatically_queue_job(): void {
		$store  = new Visitor_Gap_Store( $this->asset_manager() );
		$target = $this->attachment_target();

		for ( $confirmation = 1; $confirmation <= 3; ++$confirmation ) {
			$store->record_gaps( array( $this->gap( $target, 641 ) ), new Settings() );

			if ( $confirmation < 3 ) {
				$this->move_jobs_to_previous_window( $store );
			}
		}

		$job = reset( $store->get_data()['jobs'] );

		self::assertSame( 3, $job['confirmations'] );
		self::assertSame( 'queued', $job['status'] );
		self::assertTrue( $store->has_runnable_jobs() );
	}

	public function test_server_rejects_covered_and_forged_target_gaps(): void {
		$target = $this->attachment_target(
			101,
			array(
				'medium' => array(
					'width'  => 640,
					'height' => 400,
					'file'   => 'image-640.jpg',
				),
			)
		);
		$store  = new Visitor_Gap_Store( $this->asset_manager() );
		$result = $store->record_gaps(
			array(
				$this->gap( $target, 640 ),
				$this->gap( 'forged-target', 896 ),
			),
			new Settings()
		);

		self::assertSame( 0, $result['accepted'] );
		self::assertSame( array(), $store->get_data()['jobs'] );
	}

	public function test_layout_metrics_must_match_the_reported_image_geometry(): void {
		$target = $this->attachment_target(
			101,
			array(
				'medium' => array(
					'width'  => 640,
					'height' => 400,
					'file'   => 'image-640.jpg',
				),
			)
		);
		$store  = new Visitor_Gap_Store( $this->asset_manager() );

		$poisoned = $this->gap( $target, 640 );
		$poisoned['viewport'] = 128;
		$poisoned['slot']     = 5120;

		self::assertSame( 0, $store->record_gaps( array( $poisoned ), new Settings() )['accepted'] );
		self::assertSame( array(), $store->get_data()['jobs'] );

		$plausible = $this->gap( $target, 640 );
		$plausible['viewport'] = 1440;
		$plausible['slot']     = 320;
		$result = $store->record_gaps( array( $plausible ), new Settings() );
		$job    = reset( $store->get_data()['jobs'] );

		self::assertSame( 1, $result['accepted'] );
		self::assertSame( 1536, $job['viewport_width'] );
		self::assertSame( 320, $job['slot_width'] );
		self::assertFalse( $job['creates_width'] );
	}

	public function test_server_accepts_covered_native_width_when_required_format_is_missing(): void {
		$settings = new Settings();
		$manager  = $this->asset_manager();
		$source   = dirname( __DIR__ ) . '/.wordpress-org/source-icon-1024.png';

		$GLOBALS['eri_test_supported_formats']['image/webp'] = true;
		$GLOBALS['eri_test_uploads'] = array(
			'basedir' => dirname( __DIR__ ) . '/.wordpress-org',
			'baseurl' => 'https://example.test/plugin-assets',
		);
		$GLOBALS['eri_test_image_attachments'][151]   = true;
		$GLOBALS['eri_test_attachment_files'][151]    = $source;
		$GLOBALS['eri_test_attachment_metadata'][151] = array(
			'file'   => 'source-icon-1024.png',
			'width'  => 1024,
			'height' => 1024,
			'sizes'  => array(),
		);

		try {
			$target = Visitor_Gap_Target::token_for_attachment( 151 );
			$store  = new Visitor_Gap_Store( $manager, new Format_Generator( $settings ) );
			$result = $store->record_gaps( array( $this->gap( $target, 1024 ) ), $settings );

			self::assertSame( 1, $result['accepted'] );
			self::assertCount( 1, $store->get_data()['jobs'] );
		} finally {
			$GLOBALS['eri_test_uploads'] = array(
				'basedir' => __DIR__ . '/runtime/uploads',
				'baseurl' => 'https://example.test/uploads',
			);
		}
	}

	public function test_active_lock_returns_busy_without_queue_change(): void {
		$GLOBALS['eri_test_options'][ Visitor_Gap_Store::WRITE_LOCK_OPTION ] = array(
			'token'      => 'other-request',
			'created_at' => time(),
		);

		$result = ( new Visitor_Gap_Store( $this->asset_manager() ) )->record_gaps(
			array( $this->gap( $this->attachment_target(), 704 ) ),
			new Settings()
		);

		self::assertTrue( $result['busy'] );
		self::assertArrayNotHasKey( Visitor_Gap_Store::OPTION_NAME, $GLOBALS['eri_test_options'] );
	}

	public function test_duplicate_and_same_window_replays_skip_repeated_format_probes(): void {
		$GLOBALS['eri_test_supported_formats']['image/webp'] = true;
		$GLOBALS['eri_test_supported_formats']['image/avif'] = true;
		$probes = 0;

		add_filter(
			'empirical_responsive_images_format_preserves_alpha',
			static function ( bool $preserves ) use ( &$probes ): bool {
				++$probes;
				return $preserves;
			}
		);

		$store  = new Visitor_Gap_Store( $this->asset_manager() );
		$target = $this->attachment_target( 155, array(), 1024, 1024 );
		$gaps   = array_fill( 0, Visitor_Gap_Store::MAX_GAPS_PER_REPORT, $this->gap( $target, 704 ) );
		$result = $store->record_gaps( $gaps, new Settings() );

		self::assertSame( 1, $result['accepted'] );
		self::assertLessThanOrEqual( 2, $probes );

		$probes = 0;
		$replay = $store->record_gaps( $gaps, new Settings() );

		self::assertSame( 0, $replay['accepted'] );
		self::assertSame( 0, $probes );
	}

	public function test_accepted_report_rate_limit_bounds_valid_anonymous_work(): void {
		$store    = new Visitor_Gap_Store( $this->asset_manager() );
		$settings = new Settings();

		for ( $report = 1; $report <= 96; ++$report ) {
			$target = $this->attachment_target( 1000 + $report );
			$result = $store->record_gaps( array( $this->gap( $target, 704 ) ), $settings );

			self::assertArrayNotHasKey( 'rate_limited', $result );
			self::assertSame( 1, $result['accepted'] );
		}

		$data = $store->get_data();

		foreach ( $data['jobs'] as &$job ) {
			$job['last_window'] = (int) $job['last_window'] - 1;
		}
		unset( $job );
		$data['rate']['window'] = (int) $data['rate']['window'] - 1;
		$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $data;

		for ( $report = 1; $report <= 96; ++$report ) {
			$target = $this->attachment_target( 1000 + $report );
			$result = $store->record_gaps( array( $this->gap( $target, 704 ) ), $settings );

			self::assertArrayNotHasKey( 'rate_limited', $result );
			self::assertSame( 1, $result['accepted'] );
		}

		for ( $report = 97; $report <= 120; ++$report ) {
			$target = $this->attachment_target( 1000 + $report );
			$result = $store->record_gaps( array( $this->gap( $target, 704 ) ), $settings );

			self::assertArrayNotHasKey( 'rate_limited', $result );
			self::assertSame( 1, $result['accepted'] );
		}

		$target = $this->attachment_target( 1121 );
		$result = $store->record_gaps( array( $this->gap( $target, 704 ) ), $settings );

		self::assertTrue( $result['rate_limited'] );
		self::assertTrue( $result['accepted_rate_limited'] );
		self::assertSame( 0, $result['accepted'] );
		self::assertSame( 120, $store->get_data()['rate']['reports'] );
	}

	public function test_invalid_and_replayed_reports_cannot_exhaust_accepted_allowance_or_write_store_state(): void {
		$store    = new Visitor_Gap_Store( $this->asset_manager() );
		$settings = new Settings();

		for ( $attempt = 1; $attempt <= 650; ++$attempt ) {
			$result = $store->record_gaps( array( $this->gap( 'forged-target', 704 ) ), $settings );

			self::assertSame( 0, $result['accepted'] );
			self::assertArrayNotHasKey( 'rate_limited', $result );
		}

		self::assertArrayNotHasKey( Visitor_Gap_Store::OPTION_NAME, $GLOBALS['eri_test_options'] );

		$first_valid = $store->record_gaps(
			array( $this->gap( $this->attachment_target( 501 ), 704 ) ),
			$settings
		);
		$stored      = $GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ];

		self::assertSame( 1, $first_valid['accepted'] );

		for ( $attempt = 1; $attempt <= 650; ++$attempt ) {
			$replay = $store->record_gaps(
				array( $this->gap( $this->attachment_target( 501 ), 704 ) ),
				$settings
			);

			self::assertSame( 0, $replay['accepted'] );
			self::assertArrayNotHasKey( 'rate_limited', $replay );
		}

		self::assertSame( $stored, $GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] );

		$second_valid = $store->record_gaps(
			array( $this->gap( $this->attachment_target( 502 ), 704 ) ),
			$settings
		);
		$rate         = $store->get_data()['rate'];

		self::assertSame( 1, $second_valid['accepted'] );
		self::assertArrayNotHasKey( 'ingress', $rate );
		self::assertSame( 2, $rate['reports'] );
		self::assertSame( 2, $rate['pairs'] );
	}

	public function test_probation_capacity_always_leaves_admission_for_new_demand(): void {
		$store    = new Visitor_Gap_Store( $this->asset_manager() );
		$settings = new Settings();

		for ( $index = 1; $index <= 96; ++$index ) {
			$result = $store->record_gaps(
				array( $this->gap( $this->attachment_target( 3000 + $index ), 704 ) ),
				$settings
			);

			self::assertSame( 1, $result['accepted'] );
		}

		$rejected = $store->record_gaps(
			array( $this->gap( $this->attachment_target( 3097 ), 704 ) ),
			$settings
		);

		self::assertSame( 1, $rejected['accepted'] );
		self::assertCount( 96, $store->get_data()['jobs'] );
	}

	public function test_replayed_qualified_pending_queue_cannot_block_a_new_target(): void {
		$store = new Visitor_Gap_Store( $this->asset_manager() );
		$data  = $store->empty_data();

		for ( $index = 0; $index < 50; ++$index ) {
			$job_id = sprintf( 'queued-%03d', $index );
			$data['jobs'][ $job_id ] = array(
				'id'            => $job_id,
				'target_key'    => 'queued-target-' . $index,
				'width'         => 796,
				'status'        => 'queued',
				'confirmations' => 3,
				'first_seen'    => time() - 100,
				'last_seen'     => time(),
			);
		}

		for ( $index = 0; $index < 206; ++$index ) {
			$job_id = sprintf( 'pending-%03d', $index );
			$data['jobs'][ $job_id ] = array(
				'id'            => $job_id,
				'target_key'    => 'pending-target-' . $index,
				'width'         => 796,
				'status'        => 'pending',
				'confirmations' => 100,
				'first_seen'    => time() - 100,
				'last_seen'     => time(),
				'last_window'   => (int) floor( time() / 900 ),
			);
		}

		$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $data;
		$new_target = $this->attachment_target( 5999 );
		$result     = $store->record_gaps( array( $this->gap( $new_target, 704 ) ), new Settings() );
		$new_job_id = substr( hash( 'sha256', $new_target . '|796' ), 0, 32 );

		self::assertSame( 1, $result['accepted'] );
		self::assertCount( 256, $store->get_data()['jobs'] );
		self::assertArrayHasKey( $new_job_id, $store->get_data()['jobs'] );
	}

	public function test_replay_confirmations_cannot_starve_a_newly_qualified_job_after_queue_completions(): void {
		$store          = new Visitor_Gap_Store( $this->asset_manager() );
		$genuine_target = $this->attachment_target( 5998 );

		$store->record_gaps( array( $this->gap( $genuine_target, 704 ) ), new Settings() );
		$this->move_jobs_to_previous_window( $store );
		$store->record_gaps( array( $this->gap( $genuine_target, 704 ) ), new Settings() );

		$data = $store->get_data();
		$now  = time();

		for ( $index = 0; $index < 49; ++$index ) {
			$job_id = sprintf( 'queued-fair-%03d', $index );
			$data['jobs'][ $job_id ] = array(
				'id'            => $job_id,
				'target_key'    => 'queued-fair-target-' . $index,
				'target'        => 'queued-fair-token-' . $index,
				'type'          => 'attachment',
				'label'         => '#' . $index,
				'width'         => 796,
				'status'        => 'queued',
				'confirmations' => 3,
				'first_seen'    => $now - 300,
				'last_seen'     => $now - 200,
				'last_window'   => (int) floor( $now / 900 ),
				'queue_order'   => 0,
			);
		}

		for ( $index = 0; $index < 205; ++$index ) {
			$job_id = sprintf( 'replayed-fair-%03d', $index );
			$data['jobs'][ $job_id ] = array(
				'id'            => $job_id,
				'target_key'    => 'replayed-fair-target-' . $index,
				'target'        => 'replayed-fair-token-' . $index,
				'type'          => 'attachment',
				'label'         => '#' . $index,
				'width'         => 796,
				'status'        => 'pending',
				'confirmations' => 100,
				'first_seen'    => $now - 300,
				'last_seen'     => $now - 200,
				'last_window'   => (int) floor( $now / 900 ),
				'qualified_at'  => $now - 200,
				'queue_order'   => 0,
			);
		}

		$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $data;
		$this->move_jobs_to_previous_window( $store );

		$result         = $store->record_gaps( array( $this->gap( $genuine_target, 704 ) ), new Settings() );
		$genuine_job_id = substr( hash( 'sha256', $genuine_target . '|796' ), 0, 32 );
		$genuine_job    = $store->get_data()['jobs'][ $genuine_job_id ];

		self::assertSame( 1, $result['accepted'] );
		self::assertSame( 'queued', $genuine_job['status'] );
		self::assertSame( 3, $genuine_job['confirmations'] );
		self::assertSame( 1, $genuine_job['queue_order'] );

		for ( $claim_index = 1; $claim_index <= 50; ++$claim_index ) {
			$claimed = $store->claim_next_job();

			self::assertNotSame( array(), $claimed );

			if ( $claim_index < 50 ) {
				self::assertNotSame( $genuine_job_id, $claimed['id'] );
			} else {
				self::assertSame( $genuine_job_id, $claimed['id'] );
			}

			self::assertTrue( $store->complete_job( $claimed['id'], $claimed['lease_token'], true )['success'] );
		}
	}

	public function test_stale_probation_is_evicted_under_pressure(): void {
		$store    = new Visitor_Gap_Store( $this->asset_manager() );
		$settings = new Settings();

		for ( $index = 1; $index <= 96; ++$index ) {
			$store->record_gaps(
				array( $this->gap( $this->attachment_target( 4000 + $index ), 704 ) ),
				$settings
			);
		}

		$data = $store->get_data();

		foreach ( $data['jobs'] as &$job ) {
			$job['last_window'] = (int) $job['last_window'] - 2;
		}
		unset( $job );
		$data['rate']['window'] = -1;
		$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $data;

		$new_target = $this->attachment_target( 4999 );
		$result     = $store->record_gaps( array( $this->gap( $new_target, 704 ) ), $settings );
		$new_job_id = substr( hash( 'sha256', $new_target . '|796' ), 0, 32 );

		self::assertSame( 1, $result['accepted'] );
		self::assertCount( 96, $store->get_data()['jobs'] );
		self::assertArrayHasKey( $new_job_id, $store->get_data()['jobs'] );
	}

	public function test_full_active_queue_rejects_format_backfill_without_overflow(): void {
		$GLOBALS['eri_test_supported_formats']['image/webp'] = true;

		$store = new Visitor_Gap_Store(
			$this->asset_manager(),
			new Format_Generator( new Settings() )
		);
		$data  = $store->empty_data();

		for ( $index = 0; $index < 256; ++$index ) {
			$job_id = sprintf( 'queued-%03d', $index );
			$data['jobs'][ $job_id ] = array(
				'id'            => $job_id,
				'target_key'    => 'target-' . $index,
				'width'         => 704,
				'status'        => 'queued',
				'confirmations' => 3,
				'first_seen'    => time() - 60,
			);
		}

		$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $data;
		$target = $this->attachment_target( 5100, array(), 1024, 1024 );
		$result = $store->record_gaps( array( $this->gap( $target, 1024 ) ), new Settings() );

		self::assertSame( 0, $result['accepted'] );
		self::assertCount( 256, $store->get_data()['jobs'] );
	}

	public function test_only_unqualified_stale_evidence_expires(): void {
		$store = new Visitor_Gap_Store( $this->asset_manager() );
		$store->record_gaps( array( $this->gap( $this->attachment_target(), 704 ) ), new Settings() );

		$data = $store->get_data();

		foreach ( $data['jobs'] as &$job ) {
			$job['last_seen'] = time() - 2592001;
		}
		unset( $job );

		$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $data;

		self::assertSame( array(), $store->get_hints( new Settings() ) );
		self::assertSame( array(), $store->get_data()['jobs'] );
	}

	public function test_distinct_meaningful_widths_beyond_six_remain_learnable(): void {
		$store  = new Visitor_Gap_Store( $this->asset_manager() );
		$target = $this->attachment_target( 101, array(), 3200, 2000 );
		$gaps   = array();

		foreach ( array( 384, 640, 896, 1100, 1300, 1600, 1900 ) as $width ) {
			$gaps[] = $this->gap( $target, $width );
		}

		$result = $store->record_gaps( $gaps, new Settings() );

		self::assertSame( 7, $result['accepted'] );
		self::assertCount( 7, $store->get_data()['jobs'] );
	}

	public function test_later_width_remains_learnable_after_six_managed_widths_and_format_backfill(): void {
		$directory = sys_get_temp_dir() . '/eri-managed-widths-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $directory, 0777, true ) );

		$fixture = dirname( __DIR__ ) . '/.wordpress-org/source-icon-1024.png';
		$source  = $directory . '/source.png';
		self::assertTrue( copy( $fixture, $source ) );
		touch( $source, time() - 20 );

		$sizes = array();

		foreach ( array( 128, 192, 256, 320, 384, 448 ) as $width ) {
			$file = 'source-' . $width . '.png';
			self::assertTrue( copy( $fixture, $directory . '/' . $file ) );
			touch( $directory . '/' . $file, time() );
			$sizes[ Image_Sizes::name_for_width( $width ) ] = array(
				'file'   => $file,
				'width'  => $width,
				'height' => $width,
			);
		}

		try {
			$GLOBALS['eri_test_uploads'] = array(
				'basedir' => $directory,
				'baseurl' => 'https://example.test/uploads',
			);
			$GLOBALS['eri_test_image_attachments'][5200]   = true;
			$GLOBALS['eri_test_attachment_files'][5200]    = $source;
			$GLOBALS['eri_test_attachment_metadata'][5200] = array(
				'file'   => 'source.png',
				'width'  => 1024,
				'height' => 1024,
				'sizes'  => $sizes,
			);
			$GLOBALS['eri_test_supported_formats']['image/webp'] = true;

			$target = Visitor_Gap_Target::token_for_attachment( 5200 );
			$store  = new Visitor_Gap_Store(
				$this->asset_manager(),
				new Format_Generator( new Settings() )
			);

			$seventh_width = $store->record_gaps( array( $this->gap( $target, 512 ) ), new Settings() );
			$format_gap    = array(
				'target'                   => $target,
				'width'                    => 531,
				'native_width'             => 448,
				'_bucket_protocol_version' => Visitor_Gap_Store::BUCKET_PROTOCOL_VERSION,
			);
			$format_only   = $store->record_gaps( array( $format_gap ), new Settings() );

			self::assertSame( 1, $seventh_width['accepted'] );
			self::assertSame( 1, $format_only['accepted'] );
			self::assertCount( 2, $store->get_data()['jobs'] );
			self::assertContains( true, array_column( $store->get_data()['jobs'], 'creates_width' ) );
			self::assertContains( false, array_column( $store->get_data()['jobs'], 'creates_width' ) );
			self::assertContains( 448, array_column( $store->get_data()['jobs'], 'width' ) );
		} finally {
			foreach ( glob( $directory . '/*' ) ?: array() as $path ) {
				unlink( $path );
			}
			rmdir( $directory );
		}
	}

	public function test_completed_job_moves_to_bounded_target_history(): void {
		$store  = new Visitor_Gap_Store( $this->asset_manager() );
		$target = $this->attachment_target();

		$this->qualify_gap( $store, $target, 704 );

		$job = $store->claim_next_job();

		self::assertSame( 'processing', $job['status'] );

		$result = $store->complete_job( $job['id'], $job['lease_token'], true );
		$data   = $store->get_data();
		$ledger = reset( $data['targets'] );

		self::assertTrue( $result['success'] );
		self::assertSame( array(), $data['jobs'] );
		self::assertSame( array( 796 ), $ledger['widths'] );
		self::assertTrue( $data['cache']['dirty'] );
		self::assertSame( 1, $data['cache']['generation'] );
	}

	public function test_completed_history_over_capacity_does_not_block_new_target(): void {
		$store = new Visitor_Gap_Store( $this->asset_manager() );
		$data  = $store->empty_data();
		$base  = time() - 31536000;

		for ( $index = 0; $index < 300; ++$index ) {
			$data['targets'][ sprintf( 'fake-%03d', $index ) ] = array(
				'target'     => 'historical-target-' . $index,
				'type'       => 'attachment',
				'label'      => '#' . $index,
				'widths'     => array( 704 ),
				'created_at' => $base + $index,
				'updated_at' => $base + $index,
			);
		}

		$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $data;

		$target = $this->attachment_target( 777 );
		$result = $store->record_gaps( array( $this->gap( $target, 704 ) ), new Settings() );

		self::assertSame( 1, $result['accepted'] );

		$this->move_jobs_to_previous_window( $store );
		$store->record_gaps( array( $this->gap( $target, 704 ) ), new Settings() );
		$this->move_jobs_to_previous_window( $store );
		$store->record_gaps( array( $this->gap( $target, 704 ) ), new Settings() );

		$job = $store->claim_next_job();
		$store->complete_job( $job['id'], $job['lease_token'], true );

		$targets    = $store->get_data()['targets'];
		$target_key = substr( hash( 'sha256', $target ), 0, 32 );

		self::assertCount( 256, $targets );
		self::assertArrayHasKey( $target_key, $targets );
		self::assertArrayHasKey( 'fake-299', $targets );
		self::assertArrayNotHasKey( 'fake-044', $targets );
	}

	public function test_failed_job_has_bounded_retries_and_terminal_tombstone(): void {
		$store  = new Visitor_Gap_Store( $this->asset_manager() );
		$target = $this->attachment_target();

		$this->qualify_gap( $store, $target, 704 );

		for ( $attempt = 1; $attempt <= 3; ++$attempt ) {
			$job = $store->claim_next_job();
			self::assertNotSame( array(), $job );
			$store->complete_job( $job['id'], $job['lease_token'], false, 'generation_failed' );

			if ( $attempt < 3 ) {
				$data = $store->get_data();

				foreach ( $data['jobs'] as &$stored_job ) {
					$stored_job['next_attempt_at'] = time() - 1;
				}
				unset( $stored_job );

				$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $data;
			}
		}

		$failed = reset( $store->get_data()['jobs'] );

		self::assertSame( 'failed', $failed['status'] );
		self::assertSame( 3, $failed['attempts'] );
		self::assertGreaterThan( 0, $failed['terminal_at'] );
	}

	public function test_failed_job_reopens_after_cooldown_and_requires_fresh_multi_window_evidence(): void {
		$store  = new Visitor_Gap_Store( $this->asset_manager() );
		$target = $this->attachment_target();

		$this->qualify_gap( $store, $target, 704 );

		for ( $attempt = 1; $attempt <= 3; ++$attempt ) {
			$job = $store->claim_next_job();
			$store->complete_job( $job['id'], $job['lease_token'], false, 'generation_failed' );

			if ( $attempt < 3 ) {
				$data = $store->get_data();

				foreach ( $data['jobs'] as &$stored_job ) {
					$stored_job['next_attempt_at'] = time() - 1;
				}
				unset( $stored_job );

				$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $data;
			}
		}

		$failed_data = $store->get_data();
		$job_id      = (string) array_key_first( $failed_data['jobs'] );

		self::assertSame( 0, $store->record_gaps( array( $this->gap( $target, 704 ) ), new Settings() )['accepted'] );
		self::assertSame( $failed_data, $store->get_data() );

		$failed_data['jobs'][ $job_id ]['terminal_at'] = time() - 86401;
		$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $failed_data;

		$reopened = $store->record_gaps( array( $this->gap( $target, 704 ) ), new Settings() );
		$job      = $store->get_data()['jobs'][ $job_id ];

		self::assertSame( 1, $reopened['accepted'] );
		self::assertSame( 'pending', $job['status'] );
		self::assertSame( 1, $job['confirmations'] );
		self::assertSame( 0, $job['attempts'] );
		self::assertSame( 0, $job['terminal_at'] );
		self::assertSame( '', $job['last_error'] );
		self::assertSame( 0, $job['queue_order'] );

		$this->move_jobs_to_previous_window( $store );
		$store->record_gaps( array( $this->gap( $target, 704 ) ), new Settings() );
		$this->move_jobs_to_previous_window( $store );
		$store->record_gaps( array( $this->gap( $target, 704 ) ), new Settings() );

		$qualified = $store->get_data()['jobs'][ $job_id ];

		self::assertSame( 'queued', $qualified['status'] );
		self::assertSame( 3, $qualified['confirmations'] );

		$claimed = $store->claim_next_job();

		self::assertSame( $job_id, $claimed['id'] );
		self::assertSame( 1, $claimed['attempts'] );
	}

	public function test_failed_job_history_uses_thirty_day_expiry(): void {
		$store = new Visitor_Gap_Store( $this->asset_manager() );
		$data  = $store->empty_data();
		$job   = array(
			'id'            => 'failed-retention',
			'target_key'    => 'failed-retention-target',
			'target'        => 'failed-retention-token',
			'width'         => 796,
			'status'        => 'failed',
			'confirmations' => 3,
			'attempts'      => 3,
			'terminal_at'   => time() - 2591900,
		);

		$data['jobs'][ $job['id'] ] = $job;
		$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $data;

		self::assertCount( 1, $store->get_hints( new Settings() ) );

		$data['jobs'][ $job['id'] ]['terminal_at'] = time() - 2592100;
		$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $data;

		self::assertSame( array(), $store->get_hints( new Settings() ) );
		self::assertSame( array(), $store->get_data()['jobs'] );
	}

	public function test_stale_lease_cannot_complete_successor_claim(): void {
		$store  = new Visitor_Gap_Store( $this->asset_manager() );
		$target = $this->attachment_target();

		$this->qualify_gap( $store, $target, 704 );

		$first_claim = $store->claim_next_job();
		$data        = $store->get_data();
		$data['jobs'][ $first_claim['id'] ]['started_at'] = time() - 3601;
		$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $data;

		$successor_claim = $store->claim_next_job();

		self::assertNotSame( $first_claim['lease_token'], $successor_claim['lease_token'] );

		$stale_result = $store->complete_job( $first_claim['id'], $first_claim['lease_token'], true );
		$stored_job   = $store->get_data()['jobs'][ $first_claim['id'] ];

		self::assertFalse( $stale_result['updated'] );
		self::assertTrue( $stale_result['stale'] );
		self::assertSame( 'processing', $stored_job['status'] );
		self::assertSame( $successor_claim['lease_token'], $stored_job['lease_token'] );

		$current_result = $store->complete_job( $successor_claim['id'], $successor_claim['lease_token'], true );

		self::assertTrue( $current_result['updated'] );
		self::assertSame( array(), $store->get_data()['jobs'] );
	}

	public function test_claim_is_not_returned_when_lease_cannot_be_persisted(): void {
		$store  = new Visitor_Gap_Store( $this->asset_manager() );
		$target = $this->attachment_target();

		$this->qualify_gap( $store, $target, 704 );
		$GLOBALS['eri_test_update_option_failures'][ Visitor_Gap_Store::OPTION_NAME ] = 1;

		self::assertSame( array(), $store->claim_next_job() );

		$job = reset( $store->get_data()['jobs'] );

		self::assertSame( 'queued', $job['status'] );
		self::assertSame( 0, $job['attempts'] );
		self::assertSame( 0, $store->get_data()['daily']['processed'] );
	}

	public function test_completion_reports_storage_failure_and_keeps_persisted_lease(): void {
		$store  = new Visitor_Gap_Store( $this->asset_manager() );
		$target = $this->attachment_target();

		$this->qualify_gap( $store, $target, 704 );
		$claimed = $store->claim_next_job();
		$GLOBALS['eri_test_update_option_failures'][ Visitor_Gap_Store::OPTION_NAME ] = 1;

		$result = $store->complete_job( $claimed['id'], $claimed['lease_token'], true );
		$stored = $store->get_data()['jobs'][ $claimed['id'] ];

		self::assertFalse( $result['updated'] );
		self::assertTrue( $result['storage_error'] );
		self::assertSame( 'processing', $stored['status'] );
		self::assertSame( $claimed['lease_token'], $stored['lease_token'] );
		self::assertFalse( $store->get_data()['cache']['dirty'] );
	}

	public function test_claim_skips_other_widths_for_a_processing_target(): void {
		$store        = new Visitor_Gap_Store( $this->asset_manager() );
		$first_target = $this->attachment_target( 220 );
		$other_target = $this->attachment_target( 221 );

		$this->qualify_gap( $store, $first_target, 704 );
		$this->qualify_gap( $store, $first_target, 768 );
		$this->qualify_gap( $store, $other_target, 704 );

		$first  = $store->claim_next_job();
		$second = $store->claim_next_job();

		self::assertNotSame( array(), $first );
		self::assertNotSame( array(), $second );
		self::assertNotSame( $first['target_key'], $second['target_key'] );
		self::assertSame( array(), $store->claim_next_job() );
	}

	public function test_cache_generation_fences_purge_race(): void {
		$store = new Visitor_Gap_Store( $this->asset_manager() );

		$this->qualify_gap( $store, $this->attachment_target( 201 ), 704 );
		$this->qualify_gap( $store, $this->attachment_target( 202 ), 704 );

		$first_job = $store->claim_next_job();
		$store->complete_job( $first_job['id'], $first_job['lease_token'], true );
		$first_generation = $store->get_cache_generation();

		$second_job = $store->claim_next_job();
		$store->complete_job( $second_job['id'], $second_job['lease_token'], true );
		$second_generation = $store->get_cache_generation();

		self::assertSame( 1, $first_generation );
		self::assertSame( 2, $second_generation );
		self::assertFalse( $store->mark_cache_purged( $first_generation ) );
		self::assertTrue( $store->get_data()['cache']['dirty'] );
		self::assertTrue( $store->mark_cache_purged( $second_generation ) );
		self::assertFalse( $store->get_data()['cache']['dirty'] );
	}

	public function test_due_cache_purge_is_not_delayed_by_daily_generation_cap(): void {
		$store = new Visitor_Gap_Store( $this->asset_manager() );
		$data  = $store->empty_data();

		$data['daily'] = array(
			'day'       => gmdate( 'Y-m-d' ),
			'processed' => 50,
		);
		$data['cache'] = array(
			'dirty'         => true,
			'generation'    => 1,
			'last_purge_at' => time() - 900,
		);
		$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $data;

		self::assertSame( 60, $store->seconds_until_next_work() );
	}

	public function test_invalidated_jobs_are_pruned_before_daily_quota_is_consumed(): void {
		$settings                         = ( new Settings() )->defaults();
		$settings['max_registered_width'] = 1024;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;

		$store = new Visitor_Gap_Store( $this->asset_manager() );
		$data  = $store->empty_data();

		for ( $index = 0; $index < 50; ++$index ) {
			$job_id = 'invalid-' . $index;
			$data['jobs'][ $job_id ] = array(
				'id'            => $job_id,
				'target_key'    => 'invalid-target-' . $index,
				'target'        => 'invalid',
				'width'         => 1216,
				'status'        => 'queued',
				'confirmations' => 3,
				'first_seen'    => time() - 100,
			);
		}

		$data['jobs']['valid'] = array(
			'id'            => 'valid',
			'target_key'    => 'valid-target',
			'target'        => 'valid',
			'width'         => 704,
			'status'        => 'queued',
			'confirmations' => 3,
			'first_seen'    => time(),
		);
		$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $data;

		$claimed = $store->claim_next_job();
		$stored  = $store->get_data();

		self::assertSame( 'valid', $claimed['id'] );
		self::assertSame( 1, $stored['daily']['processed'] );
		self::assertCount( 1, $stored['jobs'] );
	}

	public function test_old_width_only_store_is_reset_during_upgrade(): void {
		$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = array(
			'version' => 1,
			'widths'  => array(
				'w704' => array( 'width' => 704 ),
			),
		);
		$store = new Visitor_Gap_Store( $this->asset_manager() );

		$store->ensure_option();

		self::assertSame( 5, $store->get_data()['version'] );
		self::assertSame( array(), $store->get_data()['jobs'] );
	}

	/**
	 * Create one valid attachment target.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $sizes         Metadata sizes.
	 * @param int   $width         Original width.
	 * @param int   $height        Original height.
	 * @return string
	 */
	private function attachment_target( int $attachment_id = 101, array $sizes = array(), int $width = 1600, int $height = 1000 ): string {
		$fixture_name = $width > 1024 ? 'source-banner-2204x713.png' : 'source-icon-1024.png';
		$fixture      = dirname( __DIR__ ) . '/.wordpress-org/' . $fixture_name;

		foreach ( $sizes as &$size ) {
			if ( is_array( $size ) ) {
				$size['file'] = $fixture_name;
			}
		}
		unset( $size );

		$GLOBALS['eri_test_uploads'] = array(
			'basedir' => dirname( __DIR__ ) . '/.wordpress-org',
			'baseurl' => 'https://example.test/plugin-assets',
		);
		$GLOBALS['eri_test_image_attachments'][ $attachment_id ]   = true;
		$GLOBALS['eri_test_attachment_files'][ $attachment_id ]    = $fixture;
		$GLOBALS['eri_test_attachment_metadata'][ $attachment_id ] = array(
			'width'  => $width,
			'height' => $height,
			'file'   => $fixture_name,
			'sizes'  => $sizes,
		);

		return Visitor_Gap_Target::token_for_attachment( $attachment_id );
	}

	/**
	 * Build one raw target gap.
	 *
	 * @param string $target Signed target.
	 * @param int    $width  Width.
	 * @return array
	 */
	private function gap( string $target, int $width ): array {
		return compact( 'target', 'width' );
	}

	/**
	 * Move all pending jobs into a prior time window for deterministic tests.
	 *
	 * @param Visitor_Gap_Store $store Store.
	 * @return void
	 */
	private function move_jobs_to_previous_window( Visitor_Gap_Store $store ): void {
		$data = $store->get_data();

		foreach ( $data['jobs'] as &$job ) {
			$job['last_window'] = (int) $job['last_window'] - 1;
		}
		unset( $job );

		$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $data;
	}

	/**
	 * Qualify one gap across three fixed windows.
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
				$this->move_jobs_to_previous_window( $store );
			}
		}
	}

	/**
	 * Build local asset manager.
	 *
	 * @return \EmpiricalResponsiveImages\Asset_Manager
	 */
	private function asset_manager(): \EmpiricalResponsiveImages\Asset_Manager {
		return new \EmpiricalResponsiveImages\Asset_Manager( new Settings() );
	}
}
