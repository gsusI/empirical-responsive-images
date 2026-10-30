<?php
/**
 * Observation store tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

namespace EmpiricalResponsiveImages\Tests;

use EmpiricalResponsiveImages\Asset_Manager;
use EmpiricalResponsiveImages\Observation_Store;
use EmpiricalResponsiveImages\Settings;
use PHPUnit\Framework\TestCase;

final class ObservationStoreTest extends TestCase {
	protected function setUp(): void {
		eri_test_reset_state();

		$settings                              = ( new Settings() )->defaults();
		$settings['asset_processing_enabled'] = false;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;
	}

	public function test_page_key_is_canonical_and_drops_private_query_data(): void {
		$key = Observation_Store::page_key_from_url( 'https://example.test/shop//item?email=person@example.test&utm_source=test' );

		self::assertSame( '/shop/item/', $key );
	}

	public function test_first_observation_for_page_requests_cache_purge(): void {
		$store  = new Observation_Store();
		$result = $store->record_payload( $this->payload(), new Settings() );

		self::assertSame( 1, $result['accepted'] );
		self::assertTrue( $result['status_changed'] );
		self::assertSame( 'https://example.test/products/example/', $result['purge_url'] );
		self::assertFalse( $result['cache_ready'] );
	}

	public function test_stable_follow_up_marks_page_ready(): void {
		$store = new Observation_Store();
		$store->record_payload( $this->payload(), new Settings() );
		$result = $store->record_payload( $this->payload(), new Settings() );

		self::assertTrue( $result['cache_ready'] );
		self::assertTrue( $result['status_changed'] );
	}

	public function test_active_write_lock_returns_busy_instead_of_losing_data(): void {
		$GLOBALS['eri_test_options']['empirical_responsive_images_observations_lock'] = array(
			'token'      => 'other-request',
			'created_at' => time(),
		);

		$result = ( new Observation_Store() )->record_payload( $this->payload(), new Settings() );

		self::assertTrue( $result['busy'] );
		self::assertSame( 0, $result['accepted'] );
	}

	public function test_stale_write_lock_is_replaced_and_released_safely(): void {
		$GLOBALS['eri_test_options']['empirical_responsive_images_observations_lock'] = array(
			'token'      => 'stale-request',
			'created_at' => time() - 60,
			'expires_at' => time() - 45,
		);

		$result = ( new Observation_Store() )->record_payload( $this->payload(), new Settings() );

		self::assertSame( 1, $result['accepted'] );
		self::assertArrayNotHasKey( 'empirical_responsive_images_observations_lock', $GLOBALS['eri_test_options'] );
	}

	public function test_failed_observation_write_is_not_reported_as_accepted(): void {
		$GLOBALS['eri_test_update_option_failures'][ Observation_Store::OPTION_NAME ] = 1;

		$result = ( new Observation_Store() )->record_payload( $this->payload(), new Settings() );

		self::assertSame( 0, $result['accepted'] );
		self::assertTrue( $result['storage_error'] );
		self::assertSame( array(), ( new Observation_Store() )->get_data()['sizes'] );
	}

	public function test_automatic_asset_widths_are_bounded_without_replacing_measurements(): void {
		$settings                             = $GLOBALS['eri_test_options'][ Settings::OPTION_NAME ];
		$settings['asset_processing_enabled'] = true;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;

		$asset_url = 'https://example.test/wp-content/.wordpress-org/icon-128x128.png';
		$manager   = new Asset_Manager( new Settings() );
		$store     = new Observation_Store( $manager );
		$payload   = array(
			'page_url' => 'https://example.test/assets/',
			'viewport' => array( 'width' => 1280 ),
			'images'   => array(
				array(
					'src'             => $asset_url,
					'current_src'     => $asset_url,
					'rendered_width'  => 48,
					'rendered_height' => 48,
					'target_width'    => 96,
					'target_height'   => 96,
					'natural_width'   => 128,
					'dpr'             => 2,
				),
			),
		);

		$store->record_payload( $payload, new Settings() );
		$key    = $manager->key_for_url( $asset_url );
		$before = $store->get_data()['assets'][ $key ];

		foreach ( range( 1, 38 ) as $width ) {
			self::assertTrue( $store->record_automatic_asset_width( $asset_url, $width ) );
		}

		self::assertFalse( $store->record_automatic_asset_width( $asset_url, 39 ) );
		self::assertTrue( $store->record_automatic_asset_width( $asset_url, 1 ) );

		$after = $store->get_data()['assets'][ $key ];

		self::assertSame( $before['count'], $after['count'] );
		self::assertSame( $before['slot_widths'], $after['slot_widths'] );
		self::assertSame( $before['pages'], $after['pages'] );
		self::assertSame( $before['natural_width'], $after['natural_width'] );
		self::assertSame( 96, $after['target_widths']['w96'] );
		self::assertCount( 38, $after['automatic_target_widths'] );
	}

	public function test_automatic_layout_uses_largest_slot_and_attachment_records_are_bounded(): void {
		$settings                             = $GLOBALS['eri_test_options'][ Settings::OPTION_NAME ];
		$settings['asset_processing_enabled'] = true;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;

		$asset_url = 'https://example.test/wp-content/.wordpress-org/icon-128x128.png';
		$manager   = new Asset_Manager( new Settings() );
		$store     = new Observation_Store( $manager );

		self::assertTrue( $store->record_automatic_asset_width( $asset_url, 64, 1536, 208 ) );
		self::assertTrue( $store->record_automatic_asset_width( $asset_url, 64, 1536, 160 ) );

		$asset = $store->get_asset_candidate_for_url( $asset_url, new Settings() );

		self::assertSame( 208, $asset['slot_widths']['1536'] );

		$GLOBALS['eri_test_image_attachments'][901] = true;

		self::assertTrue( $store->record_automatic_attachment_slot( 901, 1536, 208 ) );
		self::assertTrue( $store->record_automatic_attachment_slot( 901, 1536, 160 ) );
		self::assertSame( array( '1536' => 208 ), $store->get_attachment_slot_widths( 901 ) );

		$store->delete_attachment_slots( 901 );

		self::assertSame( array(), $store->get_attachment_slot_widths( 901 ) );
	}

	public function test_layout_checks_report_only_evidence_the_worker_would_keep(): void {
		$settings                             = $GLOBALS['eri_test_options'][ Settings::OPTION_NAME ];
		$settings['asset_processing_enabled'] = true;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;
		$GLOBALS['eri_test_image_attachments'][901]           = true;

		$asset_url = 'https://example.test/wp-content/.wordpress-org/icon-128x128.png';
		$store     = new Observation_Store( new Asset_Manager( new Settings() ) );

		self::assertFalse( $store->attachment_layout_is_recorded( 901, 1536, 208 ) );
		self::assertTrue( $store->record_automatic_attachment_slot( 901, 1536, 208 ) );
		self::assertTrue( $store->attachment_layout_is_recorded( 901, 1536, 208 ) );
		self::assertTrue( $store->attachment_layout_is_recorded( 901, 1536, 160 ) );
		self::assertFalse( $store->attachment_layout_is_recorded( 901, 1536, 224 ) );
		self::assertFalse( $store->attachment_layout_is_recorded( 901, 1024, 208 ) );

		self::assertFalse( $store->asset_layout_is_recorded( $asset_url, 64, 1536, 208 ) );
		self::assertTrue( $store->record_automatic_asset_width( $asset_url, 64, 1536, 208 ) );
		self::assertTrue( $store->asset_layout_is_recorded( $asset_url, 64, 1536, 208 ) );
		self::assertTrue( $store->asset_layout_is_recorded( $asset_url, 64, 1536, 160 ) );
		self::assertFalse( $store->asset_layout_is_recorded( $asset_url, 64, 1536, 224 ) );
		self::assertFalse( $store->asset_layout_is_recorded( $asset_url, 80, 1536, 208 ) );
		self::assertTrue( $store->asset_layout_is_recorded( $asset_url, 128, 1536, 208 ) );
	}

	public function test_layout_checks_treat_viewports_past_a_full_slot_map_as_settled(): void {
		$GLOBALS['eri_test_image_attachments'][901] = true;

		$store = new Observation_Store();

		for ( $bucket = 1; $bucket <= 40; $bucket++ ) {
			self::assertTrue( $store->record_automatic_attachment_slot( 901, 200 + $bucket * 2, 64 ) );
		}

		self::assertTrue( $store->attachment_layout_is_recorded( 901, 5120, 64 ) );
		self::assertFalse( $store->attachment_layout_is_recorded( 901, 203, 64 ) );
		self::assertFalse( $store->record_automatic_attachment_slot( 901, 5120, 64 ) );
	}

	public function test_every_coarse_viewport_bucket_remains_learnable(): void {
		$settings                             = $GLOBALS['eri_test_options'][ Settings::OPTION_NAME ];
		$settings['asset_processing_enabled'] = true;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;

		$asset_url = 'https://example.test/wp-content/.wordpress-org/icon-128x128.png';
		$manager   = new Asset_Manager( new Settings() );
		$store     = new Observation_Store( $manager );

		$GLOBALS['eri_test_image_attachments'][902] = true;

		foreach ( range( 128, 5120, 128 ) as $viewport ) {
			$slot = min( $viewport, 128 );

			self::assertTrue( $store->record_automatic_asset_width( $asset_url, 64, $viewport, $slot ) );
			self::assertTrue( $store->record_automatic_attachment_slot( 902, $viewport, $slot ) );
		}

		$asset_slots = $store->get_asset_candidate_for_url( $asset_url, new Settings() )['slot_widths'];

		self::assertCount( 40, $asset_slots );
		self::assertArrayHasKey( '5120', $asset_slots );
		self::assertCount( 40, $store->get_attachment_slot_widths( 902 ) );
		self::assertArrayHasKey( '5120', $store->get_attachment_slot_widths( 902 ) );
	}

	public function test_automatic_asset_width_rejects_unmanageable_source(): void {
		$store = new Observation_Store( new Asset_Manager( new Settings() ) );

		self::assertFalse( $store->record_automatic_asset_width( 'https://external.example/image.jpg', 640 ) );
		self::assertSame( array(), $store->get_data()['assets'] );
	}

	public function test_automatic_asset_registration_keeps_option_storage_bounded(): void {
		$settings                             = $GLOBALS['eri_test_options'][ Settings::OPTION_NAME ];
		$settings['asset_processing_enabled'] = true;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;

		$manager = new Asset_Manager( new Settings() );
		$store   = new Observation_Store( $manager );
		$data    = $store->empty_data();

		for ( $index = 0; $index < 330; ++$index ) {
			$data['assets'][ 'old-' . $index ] = array(
				'count'     => 1,
				'last_seen' => 1,
			);
		}

		$GLOBALS['eri_test_options'][ Observation_Store::OPTION_NAME ] = $data;
		$asset_url = 'https://example.test/wp-content/.wordpress-org/icon-128x128.png';
		$key       = $manager->key_for_url( $asset_url );

		self::assertTrue( $store->record_automatic_asset_width( $asset_url, 64 ) );
		self::assertCount( 320, $store->get_data()['assets'] );
		self::assertArrayHasKey( $key, $store->get_data()['assets'] );
	}

	private function payload(): array {
		return array(
			'page_url' => 'https://example.test/products/example/?secret=value',
			'viewport' => array( 'width' => 1280 ),
			'images'   => array(
				array(
					'rendered_width'  => 300,
					'rendered_height' => 200,
					'target_width'    => 600,
					'target_height'   => 400,
					'dpr'             => 2,
				),
			),
		);
	}
}
