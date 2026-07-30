<?php
/**
 * Format generator tests.
 *
 * @package EmpiricalResponsiveImages
 */

declare(strict_types=1);

namespace EmpiricalResponsiveImages {
	function get_attached_file( int $attachment_id ) {
		return $GLOBALS['eri_test_format_attachment_files'][ $attachment_id ]
			?? $GLOBALS['eri_test_attachment_files'][ $attachment_id ]
			?? false;
	}

	function wp_get_attachment_metadata( int $attachment_id ) {
		return $GLOBALS['eri_test_format_attachment_metadata'][ $attachment_id ]
			?? $GLOBALS['eri_test_attachment_metadata'][ $attachment_id ]
			?? false;
	}

	function wp_update_attachment_metadata( int $attachment_id, array $metadata ): bool {
		$GLOBALS['eri_test_format_updated_metadata'][ $attachment_id ] = $metadata;
		$GLOBALS['eri_test_attachment_metadata'][ $attachment_id ]     = $metadata;

		return true;
	}

	function wp_get_image_editor( string $path ) {
		$GLOBALS['eri_test_format_editor_sources'][] = $path;

		return new \EmpiricalResponsiveImages\Tests\FakeFormatImageEditor();
	}

	function wp_mkdir_p( string $path ): bool {
		return true;
	}

	function wp_delete_file( string $path ): bool {
		$GLOBALS['eri_test_format_deleted_files'][] = $path;

		return ! file_exists( $path ) || unlink( $path );
	}
}

namespace EmpiricalResponsiveImages\Tests {

	use EmpiricalResponsiveImages\Format_Generator;
	use EmpiricalResponsiveImages\Settings;
	use PHPUnit\Framework\TestCase;
	use ReflectionProperty;
	use RuntimeException;

	final class FakeFormatImageEditor {
		public function set_quality( int $quality ): void {}

		public function save( string $path, string $mime_type = '' ): array {
			$GLOBALS['eri_test_format_saved_files'][] = array(
				'path'      => $path,
				'mime_type' => $mime_type,
			);

			return array( 'path' => $path );
		}
	}

	final class FormatGeneratorTest extends TestCase {
		protected function setUp(): void {
			eri_test_reset_state();

			$settings                 = ( new Settings() )->defaults();
				$settings['avif_enabled'] = false;
				$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;
				$GLOBALS['eri_test_supported_formats']['image/webp'] = true;
				$GLOBALS['eri_test_uploads'] = array(
				'basedir' => dirname( __DIR__ ) . '/.wordpress-org',
				'baseurl' => 'https://example.test/wp-content/plugins/empirical-responsive-images/.wordpress-org',
			);
			$GLOBALS['eri_test_format_attachment_files'] = array(
				7 => dirname( __DIR__ ) . '/.wordpress-org/source-icon-1024.png',
			);
			$GLOBALS['eri_test_format_attachment_metadata'] = array(
				7 => array(
					'file'   => 'source-icon-1024.png',
					'width'  => 1024,
					'height' => 1024,
					'sizes'  => array(
						'empirical-responsive-images-128' => array(
							'file'   => 'icon-128x128.png',
							'width'  => 128,
							'height' => 128,
						),
						'empirical-responsive-images-256' => array(
							'file'   => 'icon-256x256.png',
							'width'  => 256,
							'height' => 256,
						),
					),
					Format_Generator::METADATA_KEY => array(
						'generated_at' => 123,
						'files'        => array(
							'source-icon-1024.png' => array(
								'webp' => 'source-icon-1024.webp',
								'avif' => 'source-icon-1024.avif',
							),
							'icon-256x256.png'     => array(
								'webp' => 'icon-256x256.webp',
							),
						),
					),
				),
			);
			$GLOBALS['eri_test_format_updated_metadata'] = array();
			$GLOBALS['eri_test_format_editor_sources']   = array();
			$GLOBALS['eri_test_format_saved_files']      = array();
			$GLOBALS['eri_test_format_deleted_files']    = array();
		}

		protected function tearDown(): void {
			$GLOBALS['eri_test_uploads'] = array(
				'basedir' => __DIR__ . '/runtime/uploads',
				'baseurl' => 'https://example.test/uploads',
			);
		}

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

		public function test_attachment_width_generates_only_matching_sidecars_and_preserves_other_metadata(): void {
			$before = $GLOBALS['eri_test_format_attachment_metadata'][7][ Format_Generator::METADATA_KEY ]['files'];

			$result = ( new Format_Generator( new Settings() ) )->generate_for_attachment_width( 7, 128 );

			self::assertSame( 1, $result['generated'] );
			self::assertSame( 0, $result['skipped'] );
			self::assertSame( array(), $result['errors'] );
			self::assertSame(
				array( dirname( __DIR__ ) . '/.wordpress-org/icon-128x128.png' ),
				$GLOBALS['eri_test_format_editor_sources']
			);
			self::assertSame(
				$before['source-icon-1024.png'],
				$result['metadata'][ Format_Generator::METADATA_KEY ]['files']['source-icon-1024.png']
			);
			self::assertSame(
				$before['icon-256x256.png'],
				$result['metadata'][ Format_Generator::METADATA_KEY ]['files']['icon-256x256.png']
			);
			self::assertSame(
				'empirical-responsive-images/attachments/'
					. substr( sha1( 'icon-128x128.png' ), 0, 2 )
					. '/'
					. sha1( 'icon-128x128.png' )
					. '/icon-128x128.webp',
				$result['metadata'][ Format_Generator::METADATA_KEY ]['files']['icon-128x128.png']['webp']
			);
			self::assertSame( $result['metadata'], $GLOBALS['eri_test_format_updated_metadata'][7] );
		}

		public function test_attachment_width_without_matching_subsize_performs_no_encoding(): void {
			$before = $GLOBALS['eri_test_format_attachment_metadata'][7][ Format_Generator::METADATA_KEY ]['files'];

			$result = ( new Format_Generator( new Settings() ) )->generate_for_attachment_width( 7, 640 );

			self::assertSame( 0, $result['generated'] );
			self::assertSame( array(), $GLOBALS['eri_test_format_editor_sources'] );
			self::assertSame( $before, $result['metadata'][ Format_Generator::METADATA_KEY ]['files'] );
		}

		public function test_attachment_sidecar_uses_collision_proof_plugin_directory(): void {
			$result = ( new Format_Generator( new Settings() ) )->generate_for_attachment_width( 7, 1024 );
			$saved  = reset( $GLOBALS['eri_test_format_saved_files'] );
			$path   = is_array( $saved ) ? (string) ( $saved['path'] ?? '' ) : '';

			self::assertSame( 1, $result['generated'] );
			self::assertStringContainsString( '/empirical-responsive-images/attachments/', $path );
			self::assertNotSame( dirname( __DIR__ ) . '/.wordpress-org/source-icon-1024.webp', $path );
			self::assertNotSame(
				'source-icon-1024.webp',
				$result['metadata'][ Format_Generator::METADATA_KEY ]['files']['source-icon-1024.png']['webp']
			);
		}

		public function test_webp_source_does_not_generate_webp_alternate(): void {
			$source = $this->configure_modern_format_source( 'webp' );

			try {
				$result = ( new Format_Generator( new Settings() ) )->generate_for_attachment_width( 7, 1024 );

				self::assertSame( 0, $result['generated'] );
				self::assertSame( 1, $result['skipped'] );
				self::assertSame( array(), $result['errors'] );
				self::assertSame( array(), $GLOBALS['eri_test_format_editor_sources'] );
				self::assertArrayNotHasKey(
					'webp',
					$result['metadata'][ Format_Generator::METADATA_KEY ]['files']['source-modern.webp']
				);
			} finally {
				unlink( $source );
			}
		}

		public function test_avif_source_does_not_generate_avif_alternate(): void {
			$source   = $this->configure_modern_format_source( 'avif' );
			$settings = ( new Settings() )->defaults();

			$settings['webp_enabled'] = false;
			$settings['avif_enabled'] = true;
			$GLOBALS['eri_test_options'][ Settings::OPTION_NAME ] = $settings;
			$GLOBALS['eri_test_supported_formats']['image/avif']  = true;

			try {
				$result = ( new Format_Generator( new Settings() ) )->generate_for_attachment_width( 7, 1024 );

				self::assertSame( 0, $result['generated'] );
				self::assertSame( 1, $result['skipped'] );
				self::assertSame( array(), $result['errors'] );
				self::assertSame( array(), $GLOBALS['eri_test_format_editor_sources'] );
				self::assertArrayNotHasKey(
					'avif',
					$result['metadata'][ Format_Generator::METADATA_KEY ]['files']['source-modern.avif']
				);
			} finally {
				unlink( $source );
			}
		}

		public function test_metadata_hook_skips_source_over_byte_limit_without_error(): void {
			add_filter(
				'empirical_responsive_images_max_attachment_source_bytes',
				static function (): int {
					return 1;
				}
			);

			$metadata = $GLOBALS['eri_test_format_attachment_metadata'][7];
			$result   = ( new Format_Generator( new Settings() ) )->filter_generate_attachment_metadata( $metadata, 7 );

			self::assertSame( $metadata, $result );
			self::assertSame( array(), $GLOBALS['eri_test_format_editor_sources'] );
			self::assertSame( array(), $GLOBALS['eri_test_getimagesize_calls'] );
		}

		public function test_metadata_hook_skips_source_over_pixel_limit_without_error(): void {
			add_filter(
				'empirical_responsive_images_max_source_pixels',
				static function (): int {
					return 1;
				}
			);

			$source   = $GLOBALS['eri_test_format_attachment_files'][7];
			$metadata = $GLOBALS['eri_test_format_attachment_metadata'][7];
			$result   = ( new Format_Generator( new Settings() ) )->filter_generate_attachment_metadata( $metadata, 7 );

			self::assertSame( $metadata, $result );
			self::assertSame( array(), $GLOBALS['eri_test_format_editor_sources'] );
			self::assertSame( 1, $GLOBALS['eri_test_getimagesize_calls'][ $source ] ?? 0 );
		}

		public function test_metadata_update_deletes_only_unreferenced_plugin_owned_sidecars(): void {
			$base          = __DIR__ . '/runtime/uploads';
			$stale_relative = 'empirical-responsive-images/attachments/aa/stale/stale.webp';
			$kept_relative  = 'empirical-responsive-images/attachments/bb/kept/kept.webp';
			$outside_relative = 'do-not-delete.webp';
			$stale_path     = $base . '/' . $stale_relative;
			$kept_path      = $base . '/' . $kept_relative;
			$outside_path   = $base . '/' . $outside_relative;

			if ( ! is_dir( dirname( $stale_path ) ) ) {
				mkdir( dirname( $stale_path ), 0777, true );
			}

			if ( ! is_dir( dirname( $kept_path ) ) ) {
				mkdir( dirname( $kept_path ), 0777, true );
			}

			file_put_contents( $stale_path, 'stale' );
			file_put_contents( $kept_path, 'kept' );
			file_put_contents( $outside_path, 'outside' );

			$GLOBALS['eri_test_uploads'] = array(
				'basedir' => $base,
				'baseurl' => 'https://example.test/uploads',
			);
			$GLOBALS['eri_test_format_attachment_metadata'][7] = array(
				Format_Generator::METADATA_KEY => array(
					'files' => array(
						'old.png' => array(
							'webp' => $stale_relative,
							'avif' => $outside_relative,
						),
						'kept.png' => array(
							'webp' => $kept_relative,
						),
					),
				),
			);
			$replacement = array(
				Format_Generator::METADATA_KEY => array(
					'files' => array(
						'kept.png' => array(
							'webp' => $kept_relative,
						),
					),
				),
			);

			$result = ( new Format_Generator( new Settings() ) )->filter_update_attachment_metadata( $replacement, 7 );

			self::assertSame( $replacement, $result );
			self::assertFileDoesNotExist( $stale_path );
			self::assertFileExists( $kept_path );
			self::assertFileExists( $outside_path );
			self::assertSame( array( realpath( $base ) . '/' . $stale_relative ), $GLOBALS['eri_test_format_deleted_files'] );

			unlink( $kept_path );
			unlink( $outside_path );
			rmdir( dirname( $kept_path ) );
			rmdir( dirname( dirname( $kept_path ) ) );
		}

		public function test_attachment_deletion_removes_all_mapped_plugin_sidecars(): void {
			$base          = __DIR__ . '/runtime/uploads';
			$relative_path = 'empirical-responsive-images/attachments/cc/delete/delete.webp';
			$sidecar_path  = $base . '/' . $relative_path;

			if ( ! is_dir( dirname( $sidecar_path ) ) ) {
				mkdir( dirname( $sidecar_path ), 0777, true );
			}

			file_put_contents( $sidecar_path, 'delete' );

			$GLOBALS['eri_test_uploads'] = array(
				'basedir' => $base,
				'baseurl' => 'https://example.test/uploads',
			);
			$GLOBALS['eri_test_format_attachment_metadata'][7] = array(
				Format_Generator::METADATA_KEY => array(
					'files' => array(
						'source.png' => array(
							'webp' => $relative_path,
						),
					),
				),
			);

			( new Format_Generator( new Settings() ) )->delete_attachment_sidecars( 7 );

			self::assertFileDoesNotExist( $sidecar_path );
		}

		/**
		 * Configure one readable modern-format source fixture.
		 *
		 * @param string $extension Source extension.
		 * @return string
		 */
		private function configure_modern_format_source( string $extension ): string {
			$base   = __DIR__ . '/runtime/uploads';
			$source = $base . '/source-modern.' . $extension;

			if ( ! is_dir( $base ) ) {
				mkdir( $base, 0777, true );
			}

			copy( dirname( __DIR__ ) . '/.wordpress-org/source-icon-1024.png', $source );

			$GLOBALS['eri_test_uploads'] = array(
				'basedir' => $base,
				'baseurl' => 'https://example.test/uploads',
			);
			$GLOBALS['eri_test_format_attachment_files'][7] = $source;
			$GLOBALS['eri_test_format_attachment_metadata'][7] = array(
				'file'   => basename( $source ),
				'width'  => 1024,
				'height' => 1024,
				'sizes'  => array(),
				Format_Generator::METADATA_KEY => array(
					'generated_at' => 123,
					'files'        => array(
						basename( $source ) => array(
							$extension => 'empirical-responsive-images/attachments/stale.' . $extension,
						),
					),
				),
			);

			return $source;
		}
	}
}
