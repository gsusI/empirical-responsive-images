<?php
/**
 * REST controller tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

namespace EmpiricalResponsiveImages\Tests;

use EmpiricalResponsiveImages\Asset_Manager;
use EmpiricalResponsiveImages\Background_Optimizer;
use EmpiricalResponsiveImages\Format_Generator;
use EmpiricalResponsiveImages\Observation_Store;
use EmpiricalResponsiveImages\Regenerator;
use EmpiricalResponsiveImages\REST_Controller;
use EmpiricalResponsiveImages\Settings;
use EmpiricalResponsiveImages\Visitor_Gap_Store;
use EmpiricalResponsiveImages\Visitor_Gap_Target;
use PHPUnit\Framework\TestCase;

final class RESTControllerTest extends TestCase {
	protected function setUp(): void {
		eri_test_reset_state();
	}

	public function test_only_bounded_visitor_hint_route_is_public(): void {
		$controller = $this->controller();

		$controller->register_routes();

		self::assertCount( 4, $GLOBALS['eri_test_registered_routes'] );

		foreach ( $GLOBALS['eri_test_registered_routes'] as $path => $registered_route ) {
			if ( false !== strpos( $path, '/visitor-gap-hints' ) ) {
				self::assertSame( '__return_true', $registered_route['args']['permission_callback'] );
				continue;
			}

			self::assertSame(
				array( $controller, 'can_manage' ),
				$registered_route['args']['permission_callback']
			);
		}
	}

	public function test_management_capability_controls_route_access(): void {
		$controller = $this->controller();

		self::assertFalse( $controller->can_manage() );

		$GLOBALS['eri_test_current_user_can_manage'] = true;

		self::assertTrue( $controller->can_manage() );
	}

	public function test_disabled_visitor_gap_route_changes_no_state(): void {
		$settings                                 = ( new Settings() )->defaults();
		$settings['visitor_gap_learning_enabled'] = false;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;
		$controller          = $this->controller();
		$before_observations = $GLOBALS['eri_test_options'][ Observation_Store::OPTION_NAME ] ?? null;
		$before_hints        = $GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] ?? null;
		$response            = $controller->record_visitor_gap_hints( $this->visitor_gap_request( array( $this->gap( 641 ) ) ) );

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( array( 'accepted' => 0, 'disabled' => true ), $response->get_data() );
		self::assertSame( $before_observations, $GLOBALS['eri_test_options'][ Observation_Store::OPTION_NAME ] ?? null );
		self::assertSame( $before_hints, $GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] ?? null );
	}

	public function test_valid_public_hint_updates_only_bounded_hint_store(): void {
		$settings                                 = ( new Settings() )->defaults();
		$settings['visitor_gap_learning_enabled'] = true;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;
		$controller                               = $this->controller();
		$before_observations                      = $GLOBALS['eri_test_options'][ Observation_Store::OPTION_NAME ] ?? null;
		$response                                 = $controller->record_visitor_gap_hints(
			$this->visitor_gap_request(
				array(
					$this->gap( 641 ),
					$this->gap( 642 ),
				)
			)
		);

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 1, $response->get_data()['accepted'] );
		self::assertSame( $before_observations, $GLOBALS['eri_test_options'][ Observation_Store::OPTION_NAME ] ?? null );
		self::assertCount( 1, $GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ]['jobs'] );
		self::assertSame( 650, reset( $GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ]['jobs'] )['width'] );
		self::assertSame( array(), $GLOBALS['eri_test_cron_events'] );
		self::assertSame( array(), $GLOBALS['eri_test_siteground_purge_urls'] );
		self::assertSame( 0, $GLOBALS['eri_test_siteground_purge_all'] );
	}

	public function test_invalid_public_hint_token_is_rejected_without_writes(): void {
		$settings                                 = ( new Settings() )->defaults();
		$settings['visitor_gap_learning_enabled'] = true;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;
		$controller                               = $this->controller();
		$request                                  = $this->visitor_gap_request( array( $this->gap( 640 ) ) );
		$request->set_header( 'X-Empirical-Responsive-Images-Token', 'forged' );
		$response = $controller->record_visitor_gap_hints( $request );

		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 'empirical_responsive_images_invalid_visitor_gap_token', $response->get_error_code() );
		self::assertArrayNotHasKey( Visitor_Gap_Store::OPTION_NAME, $GLOBALS['eri_test_options'] );
	}

	public function test_stale_bucket_protocol_is_rejected_without_writes(): void {
		$this->enable_visitor_gap_learning();
		$request = $this->visitor_gap_request( array( $this->gap( 640 ) ) );
		$request->set_header( 'X-Empirical-Responsive-Images-Bucket-Version', '1' );

		$response = $this->controller()->record_visitor_gap_hints( $request );

		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 'empirical_responsive_images_invalid_visitor_gap_bucket_version', $response->get_error_code() );
		self::assertArrayNotHasKey( Visitor_Gap_Store::OPTION_NAME, $GLOBALS['eri_test_options'] );
	}

	public function test_oversized_public_hint_body_is_rejected_before_json_parsing(): void {
		$this->enable_visitor_gap_learning();
		$request = new \WP_REST_Request();
		$request->set_header( 'X-Empirical-Responsive-Images-Token', Visitor_Gap_Store::report_token() );
		$request->set_body( str_repeat( 'x', 16385 ) );

		$response = $this->controller()->record_visitor_gap_hints( $request );

		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 'empirical_responsive_images_visitor_gap_payload_too_large', $response->get_error_code() );
		self::assertArrayNotHasKey( Visitor_Gap_Store::OPTION_NAME, $GLOBALS['eri_test_options'] );
	}

	public function test_public_hint_target_count_is_rejected_above_fixed_limit(): void {
		$this->enable_visitor_gap_learning();
		$gaps = array();

		foreach ( range( 64, 832, 64 ) as $width ) {
			$gaps[] = $this->gap( $width );
		}

		$request = $this->visitor_gap_request( $gaps );

		$response = $this->controller()->record_visitor_gap_hints( $request );

		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 'empirical_responsive_images_too_many_visitor_gaps', $response->get_error_code() );
		self::assertArrayNotHasKey( Visitor_Gap_Store::OPTION_NAME, $GLOBALS['eri_test_options'] );
	}

	public function test_qualification_schedules_only_one_fixed_background_event(): void {
		$this->enable_visitor_gap_learning();
		$controller = $this->controller();

		for ( $confirmation = 1; $confirmation <= 3; ++$confirmation ) {
			$controller->record_visitor_gap_hints( $this->visitor_gap_request( array( $this->gap( 704 ) ) ) );

			if ( $confirmation < 3 ) {
				$data = $GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ];

				foreach ( $data['jobs'] as &$job ) {
					$job['last_window'] = (int) $job['last_window'] - 1;
				}
				unset( $job );

				$GLOBALS['eri_test_options'][ Visitor_Gap_Store::OPTION_NAME ] = $data;
			}
		}

		self::assertCount( 1, $GLOBALS['eri_test_cron_events'] );
		self::assertSame( Background_Optimizer::BATCH_HOOK, $GLOBALS['eri_test_cron_events'][0]['hook'] );
		self::assertSame( array(), $GLOBALS['eri_test_cron_events'][0]['args'] );
	}

	private function visitor_gap_request( array $gaps ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		$request->set_header( 'X-Empirical-Responsive-Images-Token', Visitor_Gap_Store::report_token() );
		$request->set_header( 'X-Empirical-Responsive-Images-Bucket-Version', (string) Visitor_Gap_Store::BUCKET_PROTOCOL_VERSION );
		$request->set_body( (string) json_encode( array( 'gaps' => $gaps ) ) );

		return $request;
	}

	private function enable_visitor_gap_learning(): void {
		$settings                                 = ( new Settings() )->defaults();
		$settings['visitor_gap_learning_enabled'] = true;
		$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;
	}

	private function controller(): REST_Controller {
		$settings         = new Settings();
		$asset_manager    = new Asset_Manager( $settings );
		$store            = new Observation_Store( $asset_manager );
		$visitor_gap_store = new Visitor_Gap_Store( $asset_manager );
		$format_generator = new Format_Generator( $settings );
		$regenerator      = new Regenerator( $format_generator, $store, $asset_manager, $settings );
		$background       = new Background_Optimizer( $settings, $visitor_gap_store, $asset_manager, $store, $format_generator );

		return new REST_Controller( $settings, $store, $visitor_gap_store, $format_generator, $regenerator, $background );
	}

	private function gap( int $width ): array {
		$attachment_id = 202;
		$fixture       = dirname( __DIR__ ) . '/.wordpress-org/source-icon-1024.png';

		$GLOBALS['eri_test_uploads'] = array(
			'basedir' => dirname( __DIR__ ) . '/.wordpress-org',
			'baseurl' => 'https://example.test/plugin-assets',
		);
		$GLOBALS['eri_test_image_attachments'][ $attachment_id ]   = true;
		$GLOBALS['eri_test_attachment_files'][ $attachment_id ]    = $fixture;
		$GLOBALS['eri_test_attachment_metadata'][ $attachment_id ] = array(
			'width'  => 1600,
			'height' => 1000,
			'file'   => 'source-icon-1024.png',
			'sizes'  => array(),
		);

		return array(
			'target' => Visitor_Gap_Target::token_for_attachment( $attachment_id ),
			'width'  => $width,
		);
	}
}
