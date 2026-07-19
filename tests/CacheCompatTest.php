<?php
/**
 * Cache compatibility tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

namespace EmpiricalResponsiveImages\Tests;

use EmpiricalResponsiveImages\Cache_Compat;
use PHPUnit\Framework\TestCase;

final class CacheCompatTest extends TestCase {
	protected function setUp(): void {
		eri_test_reset_state();
	}

	public function test_siteground_url_purge_uses_public_function(): void {
		Cache_Compat::purge_url( 'https://example.test/products/example/' );

		self::assertSame(
			array( 'https://example.test/products/example/' ),
			$GLOBALS['eri_test_siteground_purge_urls']
		);
		self::assertNotContains( 'sg_cachepress_purge_cache', array_column( $GLOBALS['eri_test_actions'], 0 ) );
	}

	public function test_siteground_full_purge_uses_public_function(): void {
		Cache_Compat::purge_all();

		self::assertSame( 1, $GLOBALS['eri_test_siteground_purge_all'] );
		self::assertNotContains( 'sg_cachepress_purge_cache', array_column( $GLOBALS['eri_test_actions'], 0 ) );
	}
}
