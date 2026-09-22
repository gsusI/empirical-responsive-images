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
	public function test_upgrade_waits_for_loaded_cache_dependencies_and_finishes_once(): void {
		$plugin = Plugin::instance();
		$settings = new Settings();
		$store = new \EmpiricalResponsiveImages\Observation_Store( new \EmpiricalResponsiveImages\Asset_Manager( $settings ) );
		foreach ( array( 'settings' => $settings, 'observation_store' => $store ) as $name => $value ) {
			$property = new \ReflectionProperty( Plugin::class, $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( $plugin, $value );
		}
		update_option( 'empirical_responsive_images_version', '0.3.8' );
		$upgrade = new \ReflectionMethod( Plugin::class, 'maybe_run_version_upgrade' );
		if ( PHP_VERSION_ID < 80100 ) {
			$upgrade->setAccessible( true );
		}
		$upgrade->invoke( $plugin );
		self::assertSame( 0, $GLOBALS['eri_test_siteground_purge_all'] );
		self::assertSame( '0.3.8', get_option( 'empirical_responsive_images_version' ) );
		do_action( 'wp_loaded' );
		self::assertSame( 1, $GLOBALS['eri_test_siteground_purge_all'] );
		self::assertSame( EMPIRICAL_RESPONSIVE_IMAGES_VERSION, get_option( 'empirical_responsive_images_version' ) );
		$plugin->finish_version_upgrade();
		$upgrade->invoke( $plugin );
		self::assertSame( 1, $GLOBALS['eri_test_siteground_purge_all'] );
	}

}
