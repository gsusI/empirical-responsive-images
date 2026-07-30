<?php
/**
 * Plugin coordination tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

namespace EmpiricalResponsiveImages\Tests;

use EmpiricalResponsiveImages\Plugin;
use EmpiricalResponsiveImages\Settings;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-plugin.php';

final class PluginTest extends TestCase {
	protected function setUp(): void {
		eri_test_reset_state();
	}

	public function test_detector_markup_cache_is_purged_when_public_config_changes(): void {
		$old                                  = ( new Settings() )->defaults();
		$old['visitor_gap_learning_enabled'] = true;
		$new                                  = $old;
		$new['max_registered_width']          = 3200;

		Plugin::instance()->maybe_purge_after_settings_change( $old, $new );

		self::assertSame( 1, $GLOBALS['eri_test_siteground_purge_all'] );
	}

	public function test_unrelated_or_disabled_detector_settings_do_not_purge_markup_cache(): void {
		$old                                  = ( new Settings() )->defaults();
		$new                                  = $old;
		$new['visitor_gap_min_confirmations'] = 5;

		Plugin::instance()->maybe_purge_after_settings_change( $old, $new );

		self::assertSame( 0, $GLOBALS['eri_test_siteground_purge_all'] );
	}
}
