<?php
/**
 * Thumbnail regeneration.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Regenerates attachment metadata in safe batches.
 */
final class Regenerator {
	/**
	 * Format generator.
	 *
	 * @var Format_Generator
	 */
	private $format_generator;

	/**
	 * Observation store.
	 *
	 * @var Observation_Store
	 */
	private $store;

	/**
	 * Asset manager.
	 *
	 * @var Asset_Manager
	 */
	private $asset_manager;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Format_Generator  $format_generator Format generator.
	 * @param Observation_Store $store Observation store.
	 * @param Asset_Manager     $asset_manager Asset manager.
	 * @param Settings          $settings Settings.
	 */
	public function __construct( Format_Generator $format_generator, Observation_Store $store, Asset_Manager $asset_manager, Settings $settings ) {
		$this->format_generator = $format_generator;
		$this->store            = $store;
		$this->asset_manager    = $asset_manager;
		$this->settings         = $settings;
	}

	/**
	 * Regenerate one batch of images.
	 *
	 * @param int  $page     Page number.
	 * @param int  $per_page Batch size.
	 * @param bool $force    Force rebuild existing metadata.
	 * @return array
	 */
	public function regenerate_batch( int $page, int $per_page, bool $force = false ): array {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$page             = max( 1, $page );
		$per_page         = max( 1, min( 25, $per_page ) );
		$attachment_total = $this->count_image_attachments();
		$assets           = $this->store->get_asset_candidates( $this->settings );
		$asset_total      = count( $assets );
		$total            = $attachment_total + $asset_total;
		$offset           = ( $page - 1 ) * $per_page;
		$remaining        = $per_page;

		$attachment_ids = array();

		if ( $offset < $attachment_total && $remaining > 0 ) {
			$query = new \WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => 'image',
					'fields'         => 'ids',
					'posts_per_page' => min( $remaining, $attachment_total - $offset ),
					'offset'         => $offset,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			$attachment_ids = $query->posts;
		}

		$processed        = 0;
		$metadata_updated = 0;
		$format_generated = 0;
		$format_skipped   = 0;
		$errors           = array();

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$file          = get_attached_file( $attachment_id );

			if ( ! is_string( $file ) || '' === $file || ! file_exists( $file ) ) {
				$errors[] = sprintf(
					/* translators: %d: attachment ID. */
					__( 'Attachment %d has no readable original file.', 'empirical-responsive-images' ),
					$attachment_id
				);
				++$processed;
				--$remaining;
				continue;
			}

			$metadata = $force ? wp_generate_attachment_metadata( $attachment_id, $file ) : wp_update_image_subsizes( $attachment_id );

			if ( is_array( $metadata ) ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
				++$metadata_updated;
			} else {
				$errors[] = sprintf(
					/* translators: %d: attachment ID. */
					__( 'Attachment %d metadata could not be regenerated.', 'empirical-responsive-images' ),
					$attachment_id
				);
			}

			$format_result     = $this->format_generator->generate_for_attachment( $attachment_id, $force );
			$format_generated += absint( $format_result['generated'] ?? 0 );
			$format_skipped   += absint( $format_result['skipped'] ?? 0 );

			if ( ! empty( $format_result['errors'] ) && is_array( $format_result['errors'] ) ) {
				$errors = array_merge( $errors, array_map( 'sanitize_text_field', $format_result['errors'] ) );
			}

			++$processed;
			--$remaining;
		}

		if ( $remaining > 0 ) {
			$asset_offset = max( 0, $offset - $attachment_total );
			$asset_batch  = array_slice( $assets, $asset_offset, $remaining );

			foreach ( $asset_batch as $asset ) {
				$result            = $this->asset_manager->generate_for_asset( (string) $asset['url'], is_array( $asset['target_widths'] ?? null ) ? $asset['target_widths'] : array(), is_array( $asset['slot_widths'] ?? null ) ? $asset['slot_widths'] : array(), $force );
				$format_generated += absint( $result['generated'] ?? 0 );
				$format_skipped   += absint( $result['skipped'] ?? 0 );

				if ( ! empty( $result['errors'] ) && is_array( $result['errors'] ) ) {
					$errors = array_merge( $errors, array_map( 'sanitize_text_field', $result['errors'] ) );
				}

				++$processed;
			}
		}

		$done_count = min( $total, $offset + $processed );

		return array(
			'page'             => $page,
			'per_page'         => $per_page,
			'total'            => $total,
			'attachments_total' => $attachment_total,
			'assets_total'     => $asset_total,
			'processed'        => $processed,
			'done_count'       => $done_count,
			'done'             => $done_count >= $total,
			'next_page'        => $page + 1,
			'metadata_updated' => $metadata_updated,
			'format_generated' => $format_generated,
			'format_skipped'   => $format_skipped,
			'errors'           => array_slice( array_unique( $errors ), 0, 20 ),
		);
	}

	/**
	 * Count image attachments.
	 *
	 * @return int
	 */
	private function count_image_attachments(): int {
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);

		return absint( $query->found_posts );
	}
}
