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
use EmpiricalResponsiveImages\Visitor_Gap_Store;
use PHPUnit\Framework\TestCase;

final class ImageSizesTest extends TestCase {
	protected function setUp(): void {
		eri_test_reset_state();
	}

	public function test_width_name_is_deterministic_and_safe(): void {
		self::assertSame( 'empirical-responsive-images-640', Image_Sizes::name_for_width( 640 ) );
		self::assertSame( 'empirical-responsive-images-640', Image_Sizes::name_for_width( -640 ) );
	}

	public function test_zero_touch_mode_does_not_multiply_every_future_upload(): void {
		$store = new Observation_Store();
		$data  = $store->empty_data();

		foreach ( range( 1, 20 ) as $index ) {
			$width = $index * 64;
			$data['sizes'][ 'w' . $width ] = array(
				'width' => $width,
				'count' => 1,
			);
		}

		$GLOBALS['eri_test_options'][ Observation_Store::OPTION_NAME ] = $data;

		( new Image_Sizes( new Settings(), $store ) )->register_observed_image_sizes();

		self::assertSame( array(), $GLOBALS['eri_test_added_image_sizes'] );
	}

	public function test_legacy_global_registration_is_capped_at_six_sizes(): void {
		$settings                                 = ( new Settings() )->defaults();
		$settings['visitor_gap_learning_enabled'] = false;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;
		$store                                    = new Observation_Store();
		$data                                     = $store->empty_data();

		foreach ( range( 1, 20 ) as $index ) {
			$width = $index * 64;
			$data['sizes'][ 'w' . $width ] = array(
				'width' => $width,
				'count' => 1,
			);
		}

		$GLOBALS['eri_test_options'][ Observation_Store::OPTION_NAME ] = $data;

		( new Image_Sizes( new Settings(), $store ) )->register_observed_image_sizes();

		self::assertCount( 6, $GLOBALS['eri_test_added_image_sizes'] );
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

	public function test_opted_in_visitor_receives_gap_detector_without_rest_nonce(): void {
		$settings                                 = ( new Settings() )->defaults();
		$settings['visitor_gap_learning_enabled'] = true;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;
		$image_sizes                              = new Image_Sizes( new Settings(), new Observation_Store() );

		$image_sizes->enqueue_observer();

		self::assertArrayHasKey( 'empirical-responsive-images-visitor-gap-observer', $GLOBALS['eri_test_enqueued_scripts'] );
		self::assertArrayNotHasKey( 'empirical-responsive-images-observer', $GLOBALS['eri_test_enqueued_scripts'] );
		self::assertArrayHasKey(
			'token',
			$GLOBALS['eri_test_localized_scripts']['empirical-responsive-images-visitor-gap-observer']['data']
		);
		self::assertArrayNotHasKey(
			'nonce',
			$GLOBALS['eri_test_localized_scripts']['empirical-responsive-images-visitor-gap-observer']['data']
		);
		self::assertSame(
			Visitor_Gap_Store::BUCKET_PROTOCOL_VERSION,
			$GLOBALS['eri_test_localized_scripts']['empirical-responsive-images-visitor-gap-observer']['data']['bucketVersion']
		);
		self::assertSame(
			Visitor_Gap_Store::canonical_widths(),
			$GLOBALS['eri_test_localized_scripts']['empirical-responsive-images-visitor-gap-observer']['data']['bucketWidths']
		);
	}

	public function test_gap_detector_receives_only_supported_enabled_frontend_formats(): void {
		$GLOBALS['eri_test_supported_formats']['image/webp'] = true;
		$image_sizes = new Image_Sizes( new Settings(), new Observation_Store() );

		$image_sizes->enqueue_observer();

		self::assertSame(
			array( 'webp' ),
			$GLOBALS['eri_test_localized_scripts']['empirical-responsive-images-visitor-gap-observer']['data']['requiredFormats']
		);
	}

	public function test_authorized_administrator_also_participates_in_automatic_learning(): void {
		$settings                                 = ( new Settings() )->defaults();
		$settings['visitor_gap_learning_enabled'] = true;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;
		$GLOBALS['eri_test_current_user_can_manage']           = true;
		$image_sizes                              = new Image_Sizes( new Settings(), new Observation_Store() );

		$image_sizes->enqueue_observer();

		self::assertArrayHasKey( 'empirical-responsive-images-observer', $GLOBALS['eri_test_enqueued_scripts'] );
		self::assertArrayHasKey( 'empirical-responsive-images-visitor-gap-observer', $GLOBALS['eri_test_enqueued_scripts'] );
	}
}
