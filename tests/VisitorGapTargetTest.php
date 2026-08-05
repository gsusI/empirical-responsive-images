<?php
/**
 * Signed visitor gap target coverage tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

namespace EmpiricalResponsiveImages\Tests;

use EmpiricalResponsiveImages\Asset_Manager;
use EmpiricalResponsiveImages\Format_Generator;
use EmpiricalResponsiveImages\Settings;
use EmpiricalResponsiveImages\Visitor_Gap_Target;
use PHPUnit\Framework\TestCase;

final class VisitorGapTargetTest extends TestCase {
	/**
	 * Temporary upload directory.
	 *
	 * @var string
	 */
	private $upload_directory = '';

	protected function setUp(): void {
		eri_test_reset_state();

		$this->upload_directory = sys_get_temp_dir() . '/eri-target-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->upload_directory, 0777, true ) );

		$GLOBALS['eri_test_uploads'] = array(
			'basedir' => $this->upload_directory,
			'baseurl' => 'https://example.test/uploads',
		);
	}

	protected function tearDown(): void {
		foreach ( array( $this->sidecar_relative_path(), 'source-icon-1024.webp', 'source-icon-1024.png', 'static-source.webp', 'animated-source.webp' ) as $filename ) {
			$path = $this->upload_directory . '/' . $filename;

			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}

		$sidecar_directory = dirname( $this->upload_directory . '/' . $this->sidecar_relative_path() );

		for ( $depth = 0; $depth < 4 && is_dir( $sidecar_directory ); ++$depth ) {
			rmdir( $sidecar_directory );
			$sidecar_directory = dirname( $sidecar_directory );
		}

		if ( is_dir( $this->upload_directory ) ) {
			rmdir( $this->upload_directory );
		}

		$GLOBALS['eri_test_uploads'] = array(
			'basedir' => __DIR__ . '/runtime/uploads',
			'baseurl' => 'https://example.test/uploads',
		);
	}

	public function test_native_width_without_required_sidecar_is_not_fully_covered(): void {
		$target  = $this->attachment_target( 801 );
		$manager = new Asset_Manager( new Settings() );

		self::assertTrue( Visitor_Gap_Target::is_width_covered( $target, 1024, $manager ) );
		self::assertFalse( Visitor_Gap_Target::is_optimization_covered( $target, 1024, $manager, array( 'webp' ) ) );
	}

	public function test_unmapped_same_stem_upload_is_never_treated_as_a_sidecar(): void {
		$target  = $this->attachment_target( 807 );
		$fixture = dirname( __DIR__ ) . '/.wordpress-org/source-icon-1024.png';
		$sibling = $this->upload_directory . '/source-icon-1024.webp';

		self::assertTrue( copy( $fixture, $sibling ) );
		touch( $sibling, time() );

		self::assertFalse(
			Visitor_Gap_Target::is_optimization_covered(
				$target,
				1024,
				new Asset_Manager( new Settings() ),
				array( 'webp' )
			)
		);
	}

	public function test_readable_required_sidecar_completes_attachment_coverage(): void {
		$target  = $this->attachment_target( 802, true );
		$manager = new Asset_Manager( new Settings() );

		self::assertTrue( Visitor_Gap_Target::is_optimization_covered( $target, 1024, $manager, array( 'webp' ) ) );
	}

	public function test_original_source_counts_as_an_exact_readable_width(): void {
		$target  = $this->attachment_target( 803 );
		$manager = new Asset_Manager( new Settings() );

		self::assertTrue( Visitor_Gap_Target::has_exact_width( $target, 1024, $manager ) );
	}

	public function test_static_webp_attachment_can_participate_in_learning(): void {
		$target   = $this->webp_attachment_target( 809, false );
		$manager  = new Asset_Manager( new Settings() );
		$resolved = Visitor_Gap_Target::resolve( $target, $manager );

		self::assertNotSame( '', $target );
		self::assertSame( 'attachment', $resolved['type'] ?? '' );
		self::assertSame( array(), Visitor_Gap_Target::get_applicable_formats( $resolved, $manager, array( 'webp' ) ) );
	}

	public function test_animated_webp_attachment_fails_closed(): void {
		self::assertSame( '', $this->webp_attachment_target( 810, true ) );
	}

	public function test_missing_metadata_subsize_file_is_not_coverage(): void {
		$target = $this->attachment_target( 804 );

		$GLOBALS['eri_test_attachment_metadata'][804]['sizes']['missing'] = array(
			'file'   => 'missing-736.png',
			'width'  => 736,
			'height' => 736,
		);
		$target = Visitor_Gap_Target::resolve(
			Visitor_Gap_Target::token_for_attachment( 804 ),
			new Asset_Manager( new Settings() )
		);

		self::assertFalse( Visitor_Gap_Target::has_exact_width( $target, 736, new Asset_Manager( new Settings() ) ) );
		self::assertFalse( Visitor_Gap_Target::is_width_covered( $target, 736, new Asset_Manager( new Settings() ) ) );
	}

	public function test_integer_coverage_boundaries_do_not_round_past_quality_limits(): void {
		$target = $this->attachment_target( 808 );

		$GLOBALS['eri_test_attachment_metadata'][808]['sizes'] = array(
			'five'      => array(
				'file'   => 'source-icon-1024.png',
				'width'  => 5,
				'height' => 5,
			),
			'fifty-six' => array(
				'file'   => 'source-icon-1024.png',
				'width'  => 56,
				'height' => 56,
			),
		);

		$target = Visitor_Gap_Target::resolve(
			Visitor_Gap_Target::token_for_attachment( 808 ),
			new Asset_Manager( new Settings() )
		);
		$manager = new Asset_Manager( new Settings() );

		self::assertFalse( Visitor_Gap_Target::is_width_covered( $target, 4, $manager ) );
		self::assertFalse( Visitor_Gap_Target::is_width_covered( $target, 58, $manager ) );
	}

	public function test_stale_sidecar_is_not_format_coverage(): void {
		$target      = $this->attachment_target( 805, true );
		$source_path = $this->upload_directory . '/source-icon-1024.png';
		$sidecar     = $this->upload_directory . '/' . $this->sidecar_relative_path();

		touch( $sidecar, time() - 20 );
		touch( $source_path, time() - 10 );

		self::assertFalse(
			Visitor_Gap_Target::is_optimization_covered(
				$target,
				1024,
				new Asset_Manager( new Settings() ),
				array( 'webp' )
			)
		);
	}

	public function test_attachment_learning_respects_wordpress_srcset_ceiling(): void {
		$target = $this->attachment_target( 806 );
		$target['source_width']  = 4000;
		$target['source_height'] = 2500;

		self::assertSame( 2048, Visitor_Gap_Target::maximum_width( $target, new Settings() ) );
	}

	/**
	 * Build a resolved attachment target backed by temporary source files.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $with_sidecar  Whether WebP metadata and a readable file exist.
	 * @return array
	 */
	private function attachment_target( int $attachment_id, bool $with_sidecar = false ): array {
		$fixture = dirname( __DIR__ ) . '/.wordpress-org/source-icon-1024.png';
		$source  = $this->upload_directory . '/source-icon-1024.png';

		self::assertTrue( copy( $fixture, $source ) );

		$metadata = array(
			'file'   => 'source-icon-1024.png',
			'width'  => 1024,
			'height' => 1024,
			'sizes'  => array(),
		);

		if ( $with_sidecar ) {
			$sidecar_relative = $this->sidecar_relative_path();
			$sidecar_path     = $this->upload_directory . '/' . $sidecar_relative;

			self::assertTrue( mkdir( dirname( $sidecar_path ), 0777, true ) );
			self::assertTrue( copy( $fixture, $sidecar_path ) );
			touch( $source, time() - 10 );
			touch( $sidecar_path, time() );
			$metadata[ Format_Generator::METADATA_KEY ] = array(
				'files' => array(
					'source-icon-1024.png' => array(
						'webp' => $sidecar_relative,
					),
				),
			);
		}

		$GLOBALS['eri_test_image_attachments'][ $attachment_id ]   = true;
		$GLOBALS['eri_test_attachment_files'][ $attachment_id ]    = $source;
		$GLOBALS['eri_test_attachment_metadata'][ $attachment_id ] = $metadata;

		$token = Visitor_Gap_Target::token_for_attachment( $attachment_id );

		return Visitor_Gap_Target::resolve( $token, new Asset_Manager( new Settings() ) );
	}

	/**
	 * Create a tiny real WebP fixture with an optional animation feature flag.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $animated      Whether to mark the VP8X container animated.
	 * @return string Signed token, or empty when rejected.
	 */
	private function webp_attachment_target( int $attachment_id, bool $animated ): string {
		$filename = $animated ? 'animated-source.webp' : 'static-source.webp';
		$source   = $this->upload_directory . '/' . $filename;
		$contents = base64_decode(
			'UklGRloAAABXRUJQVlA4WAoAAAAQAAAAAQAAAQAAQUxQSAUAAAAA/Pz8/ABWUDggLgAAAPABAJ0BKgIAAgACADQlmAJ0ugADCPHKgAD++xgHzobpv1difChBP43m81qxAAA=',
			true
		);

		self::assertIsString( $contents );

		if ( $animated ) {
			$contents[20] = chr( ord( $contents[20] ) | 0x02 );
		}

		self::assertNotFalse( file_put_contents( $source, $contents ) );

		$GLOBALS['eri_test_image_attachments'][ $attachment_id ]   = true;
		$GLOBALS['eri_test_attachment_files'][ $attachment_id ]    = $source;
		$GLOBALS['eri_test_attachment_metadata'][ $attachment_id ] = array(
			'file'   => $filename,
			'width'  => 2,
			'height' => 2,
			'sizes'  => array(),
		);

		return Visitor_Gap_Target::token_for_attachment( $attachment_id );
	}

	private function sidecar_relative_path(): string {
		$key = sha1( 'source-icon-1024.png' );

		return 'empirical-responsive-images/attachments/'
			. substr( $key, 0, 2 )
			. '/'
			. $key
			. '/source-icon-1024.webp';
	}
}
