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
	/**
	 * Temporary upload directory.
	 *
	 * @var string
	 */
	private $upload_directory = '';

	/**
	 * Files created by a test.
	 *
	 * @var array
	 */
	private $created_files = array();

	/**
	 * Directories created by a test, shallowest first.
	 *
	 * @var array
	 */
	private $created_directories = array();

	protected function setUp(): void {
		eri_test_reset_state();

		$settings                 = ( new Settings() )->defaults();
		$settings['webp_enabled'] = false;
		$settings['avif_enabled'] = false;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;
		$this->upload_directory = sys_get_temp_dir() . '/eri-asset-manager-' . bin2hex( random_bytes( 8 ) );
		$GLOBALS['eri_test_uploads'] = array(
			'basedir' => $this->upload_directory,
			'baseurl' => 'https://example.test/uploads',
		);
	}

	protected function tearDown(): void {
		foreach ( array_reverse( $this->created_files ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}

		foreach ( array_reverse( $this->created_directories ) as $directory ) {
			if ( is_dir( $directory ) ) {
				rmdir( $directory );
			}
		}

		$GLOBALS['eri_test_uploads'] = array(
			'basedir' => __DIR__ . '/runtime/uploads',
			'baseurl' => 'https://example.test/uploads',
		);
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

	public function test_automatic_width_generation_does_not_add_larger_full_source_candidate(): void {
		$manager = new Asset_Manager( new Settings() );
		$url     = 'https://example.test/wp-content/.wordpress-org/source-icon-1024.png';

		self::assertSame( 1024, $manager->source_width_for_url( $url ) );
		$this->create_same_format_variant( $manager, $url, 736 );

		$automatic = $manager->generate_for_asset_width( $url, 736 );
		$manual    = $manager->generate_for_asset( $url, array( 736 ), array() );

		self::assertSame( 0, $automatic['generated'] );
		self::assertSame( 1, $automatic['skipped'] );
		self::assertSame( array(), $automatic['errors'] );
		self::assertSame( 0, $manual['generated'] );
		self::assertSame( 2, $manual['skipped'] );
		self::assertSame( array(), $manual['errors'] );
	}

	public function test_existing_asset_widths_discovers_non_grid_variant(): void {
		$manager = new Asset_Manager( new Settings() );
		$url     = 'https://example.test/wp-content/.wordpress-org/source-icon-1024.png';

		$this->create_same_format_variant( $manager, $url, 736 );

		self::assertSame( array( 736, 1024 ), $manager->get_existing_asset_widths( $url ) );
	}

	public function test_repeated_source_queries_read_image_dimensions_once_per_request(): void {
		$manager = new Asset_Manager( new Settings() );
		$url     = 'https://example.test/wp-content/.wordpress-org/source-icon-1024.png';
		$path    = dirname( __DIR__ ) . '/.wordpress-org/source-icon-1024.png';

		self::assertSame( 1024, $manager->source_width_for_url( $url ) );
		self::assertNotSame( '', $manager->key_for_url( $url ) );
		self::assertNotSame( array(), $manager->source_dimensions_for_url( $url ) );
		self::assertSame( 1, $GLOBALS['eri_test_getimagesize_calls'][ $path ] ?? 0 );
	}

	/**
	 * Create a current same-format variant in an asset's keyed output directory.
	 *
	 * @param Asset_Manager $manager Asset manager.
	 * @param string        $url     Asset URL.
	 * @param int           $width   Variant width.
	 * @return void
	 */
	private function create_same_format_variant( Asset_Manager $manager, string $url, int $width ): void {
		$key        = $manager->key_for_url( $url );
		$root       = $this->upload_directory;
		$plugin     = $root . '/empirical-responsive-images';
		$assets     = $plugin . '/assets';
		$directory  = $assets . '/' . $key;
		$source     = dirname( __DIR__ ) . '/.wordpress-org/source-icon-1024.png';
		$target     = $directory . '/source-icon-1024-' . $width . 'w.png';
		$source_age = filemtime( $source );

		self::assertNotSame( '', $key );
		self::assertTrue( mkdir( $directory, 0777, true ) );

		$this->created_directories = array( $root, $plugin, $assets, $directory );

		self::assertTrue( copy( $source, $target ) );
		self::assertTrue( touch( $target, false === $source_age ? time() : $source_age ) );

		$this->created_files[] = $target;
	}
}
