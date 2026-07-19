<?php
/**
 * Asset manager tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

namespace EmpiricalResponsiveImages\Tests;

use EmpiricalResponsiveImages\Asset_Manager;
use EmpiricalResponsiveImages\Settings;
use PHPUnit\Framework\TestCase;

final class AssetManagerTest extends TestCase {
	protected function setUp(): void {
		eri_test_reset_state();

		$settings                 = ( new Settings() )->defaults();
		$settings['webp_enabled'] = false;
		$settings['avif_enabled'] = false;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;
	}

	public function test_frontend_source_lookup_does_not_generate_missing_files(): void {
		$manager    = new Asset_Manager( new Settings() );
		$upload_dir = $GLOBALS['eri_test_uploads']['basedir'];
		$url        = 'https://example.test/wp-content/.wordpress-org/icon-128x128.png';

		self::assertDirectoryDoesNotExist( $upload_dir );

		$result = $manager->get_existing_asset_sources( $url, array( 64 ), array( 1280 => 64 ) );

		self::assertStringContainsString( 'icon-128x128.png 128w', $result['srcset'] );
		self::assertDirectoryDoesNotExist( $upload_dir );
	}
}
