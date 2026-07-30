<?php
/**
 * Settings tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

namespace EmpiricalResponsiveImages\Tests;

use EmpiricalResponsiveImages\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {
	protected function setUp(): void {
		eri_test_reset_state();
	}

	public function test_defaults_are_safe_and_bounded(): void {
		$defaults = ( new Settings() )->defaults();

		self::assertTrue( $defaults['collection_enabled'] );
		self::assertTrue( $defaults['visitor_gap_learning_enabled'] );
		self::assertFalse( $defaults['cache_guard_enabled'] );
		self::assertSame( 3, $defaults['visitor_gap_min_confirmations'] );
		self::assertFalse( $defaults['delete_data_on_uninstall'] );
		self::assertSame( 40, $defaults['max_payload_images'] );
	}

	public function test_sanitize_clamps_numeric_values_and_normalizes_booleans(): void {
		$sanitized = ( new Settings() )->sanitize(
			array(
				'collection_enabled'       => '1',
				'visitor_gap_learning_enabled' => '1',
				'delete_data_on_uninstall' => '1',
				'sample_rate'              => '9',
				'rounding_step'            => '2',
				'max_payload_images'       => '9999',
				'visitor_gap_min_confirmations' => '999',
			)
		);

		self::assertTrue( $sanitized['collection_enabled'] );
		self::assertTrue( $sanitized['visitor_gap_learning_enabled'] );
		self::assertTrue( $sanitized['delete_data_on_uninstall'] );
		self::assertSame( 1.0, $sanitized['sample_rate'] );
		self::assertSame( 8, $sanitized['rounding_step'] );
		self::assertSame( 120, $sanitized['max_payload_images'] );
		self::assertSame( 20, $sanitized['visitor_gap_min_confirmations'] );
	}

	public function test_automatic_learning_never_depends_on_legacy_page_cache_confirmation(): void {
		$settings = ( new Settings() )->defaults();

		$settings['visitor_gap_learning_enabled'] = true;
		$settings['cache_guard_enabled']          = true;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;

		self::assertFalse( ( new Settings() )->is_cache_guard_enabled() );

		$settings['visitor_gap_learning_enabled'] = false;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;

		self::assertTrue( ( new Settings() )->is_cache_guard_enabled() );
	}
}
