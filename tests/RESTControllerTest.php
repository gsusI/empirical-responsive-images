<?php
/**
 * REST controller tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

namespace EmpiricalResponsiveImages\Tests;

use EmpiricalResponsiveImages\Asset_Manager;
use EmpiricalResponsiveImages\Format_Generator;
use EmpiricalResponsiveImages\Observation_Store;
use EmpiricalResponsiveImages\Regenerator;
use EmpiricalResponsiveImages\REST_Controller;
use EmpiricalResponsiveImages\Settings;
use PHPUnit\Framework\TestCase;

final class RESTControllerTest extends TestCase {
	protected function setUp(): void {
		eri_test_reset_state();
	}

	public function test_every_rest_route_requires_management_capability(): void {
		$controller = $this->controller();

		$controller->register_routes();

		self::assertCount( 3, $GLOBALS['eri_test_registered_routes'] );

		foreach ( $GLOBALS['eri_test_registered_routes'] as $registered_route ) {
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

	private function controller(): REST_Controller {
		$settings         = new Settings();
		$asset_manager    = new Asset_Manager( $settings );
		$store            = new Observation_Store( $asset_manager );
		$format_generator = new Format_Generator( $settings );
		$regenerator      = new Regenerator( $format_generator, $store, $asset_manager, $settings );

		return new REST_Controller( $settings, $store, $format_generator, $regenerator );
	}
}
