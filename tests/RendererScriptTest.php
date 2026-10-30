<?php
/**
 * Picture source sync script tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

namespace EmpiricalResponsiveImages\Tests;

use EmpiricalResponsiveImages\Asset_Manager;
use EmpiricalResponsiveImages\Format_Generator;
use EmpiricalResponsiveImages\Observation_Store;
use EmpiricalResponsiveImages\Renderer;
use EmpiricalResponsiveImages\Settings;
use PHPUnit\Framework\TestCase;

final class RendererScriptTest extends TestCase {
	/**
	 * Temporary uploads directory.
	 *
	 * @var string
	 */
	private $upload_directory = '';

	protected function setUp(): void {
		eri_test_reset_state();
	}

	protected function tearDown(): void {
		if ( '' === $this->upload_directory ) {
			return;
		}

		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->upload_directory, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $entries as $entry ) {
			$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
		}

		rmdir( $this->upload_directory );
	}

	private function renderer(): Renderer {
		$settings = new Settings();

		return new Renderer( $settings, new Observation_Store(), new Asset_Manager( $settings ) );
	}

	public function test_picture_source_sync_is_inlined_in_the_footer_when_picture_output_is_enabled(): void {
		$this->renderer()->enqueue_picture_source_sync();

		$handle = Renderer::PICTURE_SOURCE_SYNC_HANDLE;
		self::assertFalse( $GLOBALS['eri_test_registered_scripts'][ $handle ]['src'] );
		self::assertSame( array( 'in_footer' => true ), $GLOBALS['eri_test_registered_scripts'][ $handle ]['args'] );
		self::assertArrayHasKey( $handle, $GLOBALS['eri_test_enqueued_scripts'] );
		self::assertCount( 1, $GLOBALS['eri_test_inline_scripts'][ $handle ]['after'] );
		self::assertStringContainsString( 'data-empirical-responsive-images-picture-child', $GLOBALS['eri_test_inline_scripts'][ $handle ]['after'][0] );
	}

	public function test_picture_source_sync_is_skipped_when_picture_output_is_disabled(): void {
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = array( 'picture_enabled' => false );

		$this->renderer()->enqueue_picture_source_sync();

		self::assertSame( array(), $GLOBALS['eri_test_registered_scripts'] );
		self::assertSame( array(), $GLOBALS['eri_test_inline_scripts'] );
	}

	public function test_picture_source_sync_script_bypasses_delayed_javascript(): void {
		$renderer   = $this->renderer();
		$attributes = $renderer->filter_picture_source_sync_attributes(
			array( 'id' => Renderer::PICTURE_SOURCE_SYNC_HANDLE . '-js-after' ),
			''
		);

		self::assertSame( '1', $attributes['data-nowprocket'] );
		self::assertSame( 'false', $attributes['data-cfasync'] );
		self::assertSame( '1', $attributes['data-no-optimize'] );

		$other = array( 'id' => 'theme-script-js-after' );
		self::assertSame( $other, $renderer->filter_picture_source_sync_attributes( $other, '' ) );
	}

	/**
	 * Build a picture source srcset for an attachment with a generated WebP sidecar.
	 *
	 * @param string $upload_base_url Uploads base URL reported by WordPress.
	 * @param string $image_url       Image URL printed in the page.
	 * @return string
	 */
	private function alternate_srcset_for( string $upload_base_url, string $image_url ): string {
		$base_dir               = sys_get_temp_dir() . '/eri-renderer-' . bin2hex( random_bytes( 8 ) );
		$this->upload_directory = $base_dir;
		$sidecar  = 'empirical-responsive-images/attachments/2026/10/shirt.webp';

		mkdir( dirname( $base_dir . '/' . $sidecar ), 0777, true );
		mkdir( $base_dir . '/2026/10', 0777, true );
		touch( $base_dir . '/2026/10/shirt.jpg', time() - 60 );
		touch( $base_dir . '/' . $sidecar );

		$GLOBALS['eri_test_uploads'] = array(
			'basedir' => $base_dir,
			'baseurl' => $upload_base_url,
		);

		$metadata = array(
			'file'                         => '2026/10/shirt.jpg',
			'width'                        => 1200,
			'height'                       => 1200,
			Format_Generator::METADATA_KEY => array(
				'files' => array(
					'2026/10/shirt.jpg' => array( 'webp' => $sidecar ),
				),
			),
		);
		$image    = array(
			'src'    => $image_url,
			'srcset' => $image_url . ' 1200w',
		);

		$method = new \ReflectionMethod( Renderer::class, 'build_alternate_srcset' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return (string) $method->invoke( $this->renderer(), $image, $metadata, 'webp' );
	}

	/**
	 * Expected WebP source for an uploads base URL.
	 *
	 * @param string $upload_base_url Uploads base URL.
	 * @return string
	 */
	private function expected_srcset( string $upload_base_url ): string {
		return $upload_base_url . '/empirical-responsive-images/attachments/2026/10/shirt.webp 1200w';
	}

	public function test_alternate_sources_upgrade_to_https_when_the_image_is_https(): void {
		$srcset = $this->alternate_srcset_for( 'http://example.test/wp-content/uploads', 'https://example.test/wp-content/uploads/2026/10/shirt.jpg' );

		self::assertSame( $this->expected_srcset( 'https://example.test/wp-content/uploads' ), $srcset );
	}

	public function test_alternate_sources_upgrade_to_https_on_secure_pages(): void {
		$GLOBALS['eri_test_is_ssl'] = true;

		$srcset = $this->alternate_srcset_for( 'http://example.test/wp-content/uploads', 'http://example.test/wp-content/uploads/2026/10/shirt.jpg' );

		self::assertSame( $this->expected_srcset( 'https://example.test/wp-content/uploads' ), $srcset );
	}

	public function test_alternate_sources_keep_https_uploads_when_old_content_links_over_http(): void {
		$srcset = $this->alternate_srcset_for( 'https://example.test/wp-content/uploads', 'http://example.test/wp-content/uploads/2026/10/shirt.jpg' );

		self::assertSame( $this->expected_srcset( 'https://example.test/wp-content/uploads' ), $srcset );
	}

	public function test_alternate_sources_stay_on_http_for_http_sites(): void {
		$srcset = $this->alternate_srcset_for( 'http://example.test/wp-content/uploads', 'http://example.test/wp-content/uploads/2026/10/shirt.jpg' );

		self::assertSame( $this->expected_srcset( 'http://example.test/wp-content/uploads' ), $srcset );
	}
}
