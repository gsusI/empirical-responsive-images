<?php
/**
 * Observation store tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

namespace EmpiricalResponsiveImages\Tests;

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
