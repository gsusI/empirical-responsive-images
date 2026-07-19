<?php
/**
 * Image size tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

namespace EmpiricalResponsiveImages\Tests;

use EmpiricalResponsiveImages\Image_Sizes;
use EmpiricalResponsiveImages\Observation_Store;
use EmpiricalResponsiveImages\Settings;
use PHPUnit\Framework\TestCase;

final class ImageSizesTest extends TestCase {
	protected function setUp(): void {
		eri_test_reset_state();
	}

	public function test_width_name_is_deterministic_and_safe(): void {
		self::assertSame( 'empirical-responsive-images-640', Image_Sizes::name_for_width( 640 ) );
		self::assertSame( 'empirical-responsive-images-640', Image_Sizes::name_for_width( -640 ) );
	}

	public function test_observer_is_not_enqueued_without_management_capability(): void {
		$image_sizes = new Image_Sizes( new Settings(), new Observation_Store() );

		$image_sizes->enqueue_observer();

		self::assertArrayNotHasKey( 'empirical-responsive-images-observer', $GLOBALS['eri_test_enqueued_scripts'] );
	}

	public function test_authorized_observer_receives_rest_nonce(): void {
		$GLOBALS['eri_test_current_user_can_manage'] = true;
		$image_sizes                                = new Image_Sizes( new Settings(), new Observation_Store() );

		$image_sizes->enqueue_observer();

		self::assertArrayHasKey( 'empirical-responsive-images-observer', $GLOBALS['eri_test_enqueued_scripts'] );
		self::assertSame(
			'test-nonce-wp_rest',
			$GLOBALS['eri_test_localized_scripts']['empirical-responsive-images-observer']['data']['nonce']
		);
	}
}
