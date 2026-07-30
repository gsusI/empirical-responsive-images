<?php
/**
 * Alpha compatibility tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

namespace EmpiricalResponsiveImages\Tests;

use EmpiricalResponsiveImages\Alpha_Compat;
use PHPUnit\Framework\TestCase;

final class AlphaCompatTest extends TestCase {
	protected function setUp(): void {
		eri_test_reset_state();
	}

	public function test_unknown_modern_sources_fail_closed_for_avif_without_blocking_webp(): void {
		foreach ( array( 'source.webp', 'source.avif' ) as $source ) {
			self::assertTrue( Alpha_Compat::can_use_format_for_source( $source, 'webp' ) );
			self::assertFalse( Alpha_Compat::can_use_format_for_source( $source, 'avif' ) );
		}
	}

	public function test_site_can_explicitly_allow_alpha_safe_avif_output(): void {
		add_filter(
			'empirical_responsive_images_format_preserves_alpha',
			static function ( bool $preserves, string $format ): bool {
				return 'avif' === $format ? true : $preserves;
			},
			10,
			2
		);

		self::assertTrue( Alpha_Compat::can_use_format_for_source( 'source.webp', 'avif' ) );
	}

	public function test_large_ancillary_png_chunk_cannot_hide_transparency(): void {
		$file = sys_get_temp_dir() . '/eri-alpha-' . bin2hex( random_bytes( 8 ) ) . '.png';
		$png  = "\x89PNG\r\n\x1a\n";
		$png .= $this->png_chunk(
			'IHDR',
			pack( 'NNCCCCC', 1, 1, 8, 3, 0, 0, 0 )
		);
		$png .= $this->png_chunk( 'tEXt', str_repeat( 'a', 1048576 ) );
		$png .= $this->png_chunk( 'tRNS', "\x00" );
		$png .= $this->png_chunk( 'IDAT', gzcompress( "\x00\x00" ) );
		$png .= $this->png_chunk( 'IEND', '' );

		self::assertNotFalse( file_put_contents( $file, $png ) );

		try {
			self::assertFalse( Alpha_Compat::can_use_format_for_source( $file, 'avif' ) );
		} finally {
			unlink( $file );
		}
	}

	private function png_chunk( string $type, string $data ): string {
		return pack( 'N', strlen( $data ) )
			. $type
			. $data
			. pack( 'N', crc32( $type . $data ) );
	}
}
