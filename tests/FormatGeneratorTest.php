<?php
/**
 * Format generator tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

namespace EmpiricalResponsiveImages\Tests;

use EmpiricalResponsiveImages\Format_Generator;
use EmpiricalResponsiveImages\Settings;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class FormatGeneratorTest extends TestCase {
	public function test_metadata_filter_state_is_restored_after_exception(): void {
		$generator = new Format_Generator( new Settings() );

		try {
			$generator->without_metadata_filter(
				static function (): void {
					throw new RuntimeException( 'Expected test exception.' );
				}
			);
			self::fail( 'Expected exception was not thrown.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'Expected test exception.', $exception->getMessage() );
		}

		$property = new ReflectionProperty( $generator, 'metadata_filter_suspended' );

		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}

		self::assertFalse( $property->getValue( $generator ) );
	}
}
