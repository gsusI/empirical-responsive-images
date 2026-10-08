<?php
/**
 * Picture source sync script tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

namespace EmpiricalResponsiveImages\Tests;

use EmpiricalResponsiveImages\Asset_Manager;
use EmpiricalResponsiveImages\Observation_Store;
use EmpiricalResponsiveImages\Renderer;
use EmpiricalResponsiveImages\Settings;
use PHPUnit\Framework\TestCase;

final class RendererScriptTest extends TestCase {
	protected function setUp(): void {
		eri_test_reset_state();
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
}
