<?php
/**
 * WP-CLI command.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CLI tools.
 */
final class CLI_Command {
	/**
	 * List empirical image sizes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp empirical-responsive-images sizes
	 *
	 * @return void
	 */
	public function sizes(): void {
		$plugin     = Plugin::instance();
		$settings   = $plugin->get_settings();
		$candidates = $plugin->get_observation_store()->get_registered_candidates( $settings );
		$items      = array();

		foreach ( $candidates as $candidate ) {
			$items[] = array(
				'name'         => Image_Sizes::name_for_width( absint( $candidate['width'] ?? 0 ) ),
				'width'        => absint( $candidate['width'] ?? 0 ),
				'min_height'   => absint( $candidate['min_height'] ?? 0 ),
				'max_height'   => absint( $candidate['max_height'] ?? 0 ),
				'observations' => absint( $candidate['count'] ?? 0 ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $items, array( 'name', 'width', 'min_height', 'max_height', 'observations' ) );
	}

	/**
	 * List observed local asset images.
	 *
	 * ## EXAMPLES
	 *
	 *     wp empirical-responsive-images assets
	 *
	 * @return void
	 */
	public function assets(): void {
		$plugin   = Plugin::instance();
		$settings = $plugin->get_settings();
		$assets   = $plugin->get_observation_store()->get_asset_candidates( $settings );
		$items    = array();

		foreach ( $assets as $asset ) {
			$items[] = array(
				'url'          => (string) ( $asset['url'] ?? '' ),
				'widths'       => implode( ',', array_map( 'absint', is_array( $asset['target_widths'] ?? null ) ? $asset['target_widths'] : array() ) ),
				'observations' => absint( $asset['count'] ?? 0 ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $items, array( 'url', 'widths', 'observations' ) );
	}

	/**
	 * Regenerate thumbnails and modern sidecars.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Rebuild existing metadata and sidecars.
	 *
	 * [--batch-size=<number>]
	 * : Images per batch. Default 10.
	 *
	 * ## EXAMPLES
	 *
	 *     wp empirical-responsive-images regenerate --batch-size=10
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Assoc args.
	 * @return void
	 */
	public function regenerate( array $args, array $assoc_args ): void {
		$regenerator = Plugin::instance()->get_regenerator();
		$batch_size  = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 10;
		$force       = ! empty( $assoc_args['force'] );
		$page        = 1;
		$total       = null;
		$generated   = 0;

		do {
			$result     = $regenerator->regenerate_batch( $page, $batch_size, $force );
			$total      = null === $total ? absint( $result['total'] ?? 0 ) : $total;
			$generated += absint( $result['format_generated'] ?? 0 );

			\WP_CLI::log(
				sprintf(
					'Processed %1$d of %2$d image records. Generated %3$d files.',
					absint( $result['done_count'] ?? 0 ),
					$total,
					$generated
				)
			);

			$page = absint( $result['next_page'] ?? ( $page + 1 ) );
		} while ( empty( $result['done'] ) );

		\WP_CLI::success(
			sprintf(
				'Done. Processed %1$d image records. Generated %2$d files.',
				$total,
				$generated
			)
		);
	}
}
