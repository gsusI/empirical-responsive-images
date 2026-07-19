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
		self::assertFalse( $defaults['delete_data_on_uninstall'] );
		self::assertSame( 40, $defaults['max_payload_images'] );
	}

	public function test_sanitize_clamps_numeric_values_and_normalizes_booleans(): void {
		$sanitized = ( new Settings() )->sanitize(
			array(
				'collection_enabled'       => '1',
				'delete_data_on_uninstall' => '1',
				'sample_rate'              => '9',
				'rounding_step'            => '2',
				'max_payload_images'       => '9999',
			)
		);

		self::assertTrue( $sanitized['collection_enabled'] );
		self::assertTrue( $sanitized['delete_data_on_uninstall'] );
		self::assertSame( 1.0, $sanitized['sample_rate'] );
		self::assertSame( 8, $sanitized['rounding_step'] );
		self::assertSame( 120, $sanitized['max_payload_images'] );
	}
}
