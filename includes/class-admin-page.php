<?php
/**
 * Admin page.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders plugin admin UI.
 */
final class Admin_Page {
	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Observation store.
	 *
	 * @var Observation_Store
	 */
	private $store;

	/**
	 * Format generator.
	 *
	 * @var Format_Generator
	 */
	private $format_generator;

	/**
	 * Admin hook suffix.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Settings.
	 * @param Observation_Store $store Observation store.
	 * @param Format_Generator  $format_generator Format generator.
	 */
	public function __construct( Settings $settings, Observation_Store $store, Format_Generator $format_generator ) {
		$this->settings         = $settings;
		$this->store            = $store;
		$this->format_generator = $format_generator;
	}

	/**
	 * Register tools page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->hook_suffix = add_management_page(
			__( 'Responsive Images', 'empirical-responsive-images' ),
			__( 'Responsive Images', 'empirical-responsive-images' ),
			'manage_options',
			'empirical-responsive-images',
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'empirical-responsive-images-admin',
			EMPIRICAL_RESPONSIVE_IMAGES_URL . 'assets/css/admin.css',
			array(),
			EMPIRICAL_RESPONSIVE_IMAGES_VERSION
		);

		wp_enqueue_script(
			'empirical-responsive-images-admin',
			EMPIRICAL_RESPONSIVE_IMAGES_URL . 'assets/js/admin.js',
			array(),
			EMPIRICAL_RESPONSIVE_IMAGES_VERSION,
			true
		);

		wp_localize_script(
			'empirical-responsive-images-admin',
			'EmpiricalResponsiveImagesAdmin',
			array(
				'regenerateEndpoint' => esc_url_raw( rest_url( 'empirical-responsive-images/v1/regenerate' ) ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'batchSize'          => 5,
				'i18n'               => array(
					'starting' => __( 'Starting regeneration...', 'empirical-responsive-images' ),
					/* translators: 1: processed image records, 2: total image records, 3: generated files. */
					'running'  => __( 'Processed %1$d of %2$d image records. Generated %3$d files.', 'empirical-responsive-images' ),
					/* translators: 1: processed image records, 2: generated files. */
					'done'     => __( 'Done. Processed %1$d image records. Generated %2$d files.', 'empirical-responsive-images' ),
					'failed'   => __( 'Regeneration failed.', 'empirical-responsive-images' ),
				),
			)
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options    = $this->settings->all();
		$data       = $this->store->get_data();
		$candidates = $this->store->get_registered_candidates( $this->settings );
		$assets     = $this->store->get_asset_candidates( $this->settings );
		$formats    = $this->format_generator->get_support_status();
		?>
		<div class="wrap empirical-responsive-images-admin-page">
			<h1 class="empirical-responsive-images-admin-page__title"><?php esc_html_e( 'Empirical Responsive Images', 'empirical-responsive-images' ); ?></h1>

			<section class="empirical-responsive-images-admin-card empirical-responsive-images-admin-card--summary" aria-labelledby="empirical-responsive-images-summary-title">
				<h2 class="empirical-responsive-images-admin-card__title" id="empirical-responsive-images-summary-title"><?php esc_html_e( 'Observed image slots', 'empirical-responsive-images' ); ?></h2>
				<p class="empirical-responsive-images-admin-card__description">
					<?php esc_html_e( 'The front-end observer records aggregate rendered image widths, DPR, viewport widths, attachment IDs, and page paths. It does not persist raw IP addresses.', 'empirical-responsive-images' ); ?>
				</p>
				<ul class="empirical-responsive-images-summary-list">
					<li class="empirical-responsive-images-summary-list__item">
						<strong class="empirical-responsive-images-summary-list__label"><?php esc_html_e( 'Payloads', 'empirical-responsive-images' ); ?>:</strong>
						<span class="empirical-responsive-images-summary-list__value"><?php echo esc_html( number_format_i18n( absint( $data['totals']['payloads'] ?? 0 ) ) ); ?></span>
					</li>
					<li class="empirical-responsive-images-summary-list__item">
						<strong class="empirical-responsive-images-summary-list__label"><?php esc_html_e( 'Image observations', 'empirical-responsive-images' ); ?>:</strong>
						<span class="empirical-responsive-images-summary-list__value"><?php echo esc_html( number_format_i18n( absint( $data['totals']['observations'] ?? 0 ) ) ); ?></span>
					</li>
					<li class="empirical-responsive-images-summary-list__item">
						<strong class="empirical-responsive-images-summary-list__label"><?php esc_html_e( 'Registered empirical sizes', 'empirical-responsive-images' ); ?>:</strong>
						<span class="empirical-responsive-images-summary-list__value"><?php echo esc_html( number_format_i18n( count( $candidates ) ) ); ?></span>
					</li>
					<li class="empirical-responsive-images-summary-list__item">
						<strong class="empirical-responsive-images-summary-list__label"><?php esc_html_e( 'Observed local assets', 'empirical-responsive-images' ); ?>:</strong>
						<span class="empirical-responsive-images-summary-list__value"><?php echo esc_html( number_format_i18n( count( $assets ) ) ); ?></span>
					</li>
				</ul>
			</section>

			<section class="empirical-responsive-images-admin-card empirical-responsive-images-admin-card--formats" aria-labelledby="empirical-responsive-images-formats-title">
				<h2 class="empirical-responsive-images-admin-card__title" id="empirical-responsive-images-formats-title"><?php esc_html_e( 'Modern format support', 'empirical-responsive-images' ); ?></h2>
				<ul class="empirical-responsive-images-format-list">
					<?php foreach ( $formats as $format => $status ) : ?>
						<li class="empirical-responsive-images-format-list__item">
							<strong class="empirical-responsive-images-format-list__label"><?php echo esc_html( strtoupper( $format ) ); ?>:</strong>
							<span class="empirical-responsive-images-format-list__value">
								<?php
								echo esc_html(
									$status['enabled']
										? ( $status['supported'] ? __( 'enabled and supported', 'empirical-responsive-images' ) : __( 'enabled but not supported by this server', 'empirical-responsive-images' ) )
										: __( 'disabled', 'empirical-responsive-images' )
								);
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>

			<form class="empirical-responsive-images-settings-form" method="post" action="options.php">
				<?php settings_fields( 'empirical_responsive_images' ); ?>
				<section class="empirical-responsive-images-admin-card empirical-responsive-images-admin-card--settings" aria-labelledby="empirical-responsive-images-settings-title">
					<h2 class="empirical-responsive-images-admin-card__title" id="empirical-responsive-images-settings-title"><?php esc_html_e( 'Settings', 'empirical-responsive-images' ); ?></h2>
					<table class="form-table empirical-responsive-images-settings-table" role="presentation">
						<tbody class="empirical-responsive-images-settings-table__body">
							<?php $this->render_checkbox_row( 'collection_enabled', __( 'Collect rendered image sizes', 'empirical-responsive-images' ), $options ); ?>
							<?php $this->render_checkbox_row( 'cache_guard_enabled', __( 'Prevent page caching until image sizes are confirmed', 'empirical-responsive-images' ), $options ); ?>
							<?php $this->render_checkbox_row( 'picture_enabled', __( 'Serve generated WebP/AVIF through picture sources', 'empirical-responsive-images' ), $options ); ?>
							<?php $this->render_checkbox_row( 'asset_processing_enabled', __( 'Generate responsive variants for local theme and plugin asset images', 'empirical-responsive-images' ), $options ); ?>
							<?php $this->render_checkbox_row( 'webp_enabled', __( 'Generate WebP sidecars when supported', 'empirical-responsive-images' ), $options ); ?>
							<?php $this->render_checkbox_row( 'avif_enabled', __( 'Generate AVIF sidecars when supported', 'empirical-responsive-images' ), $options ); ?>
							<?php $this->render_number_row( 'cache_ready_runs', __( 'Stable uncached runs before caching', 'empirical-responsive-images' ), $options, '1', '5', '1' ); ?>
							<?php $this->render_number_row( 'sample_rate', __( 'Measurement sample rate', 'empirical-responsive-images' ), $options, '0', '1', '0.01' ); ?>
							<?php $this->render_number_row( 'rounding_step', __( 'Round observed widths to nearest pixels', 'empirical-responsive-images' ), $options, '8', '256', '1' ); ?>
							<?php $this->render_number_row( 'min_rendered_width', __( 'Minimum rendered image width to observe', 'empirical-responsive-images' ), $options, '1', '1200', '1' ); ?>
							<?php $this->render_number_row( 'max_registered_width', __( 'Maximum registered width', 'empirical-responsive-images' ), $options, '320', '5120', '1' ); ?>
						</tbody>
					</table>
					<?php submit_button( __( 'Save settings', 'empirical-responsive-images' ), 'primary', 'submit', false, array( 'class' => 'empirical-responsive-images-settings-form__submit button button-primary' ) ); ?>
				</section>
			</form>

			<section class="empirical-responsive-images-admin-card empirical-responsive-images-admin-card--sizes" aria-labelledby="empirical-responsive-images-sizes-title">
				<h2 class="empirical-responsive-images-admin-card__title" id="empirical-responsive-images-sizes-title"><?php esc_html_e( 'Registered empirical sizes', 'empirical-responsive-images' ); ?></h2>
				<?php $this->render_sizes_table( $candidates ); ?>
			</section>

			<section class="empirical-responsive-images-admin-card empirical-responsive-images-admin-card--assets" aria-labelledby="empirical-responsive-images-assets-title">
				<h2 class="empirical-responsive-images-admin-card__title" id="empirical-responsive-images-assets-title"><?php esc_html_e( 'Observed local assets', 'empirical-responsive-images' ); ?></h2>
				<?php $this->render_assets_table( $assets ); ?>
			</section>

			<section class="empirical-responsive-images-admin-card empirical-responsive-images-admin-card--regenerate" aria-labelledby="empirical-responsive-images-regenerate-title">
				<h2 class="empirical-responsive-images-admin-card__title" id="empirical-responsive-images-regenerate-title"><?php esc_html_e( 'Regenerate thumbnails', 'empirical-responsive-images' ); ?></h2>
				<p class="empirical-responsive-images-admin-card__description"><?php esc_html_e( 'Regenerates missing image subsizes for all image attachments, then creates observed local asset variants and WebP/AVIF sidecars when the server supports them.', 'empirical-responsive-images' ); ?></p>
				<label class="empirical-responsive-images-regenerate-control__force-label">
					<input class="empirical-responsive-images-regenerate-control__force-checkbox" type="checkbox" value="1">
					<?php esc_html_e( 'Force rebuild existing metadata and sidecars', 'empirical-responsive-images' ); ?>
				</label>
				<div class="empirical-responsive-images-regenerate-control">
					<button class="empirical-responsive-images-regenerate-control__button button button-primary" type="button">
						<?php esc_html_e( 'Regenerate all image variants', 'empirical-responsive-images' ); ?>
					</button>
					<progress class="empirical-responsive-images-regenerate-control__progress" value="0" max="100"></progress>
					<p class="empirical-responsive-images-regenerate-control__status" aria-live="polite"></p>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Render checkbox setting row.
	 *
	 * @param string $key     Option key.
	 * @param string $label   Label.
	 * @param array  $options Options.
	 * @return void
	 */
	private function render_checkbox_row( string $key, string $label, array $options ): void {
		$field_id = 'empirical-responsive-images-' . str_replace( '_', '-', $key );
		?>
		<tr class="empirical-responsive-images-settings-table__row">
			<th class="empirical-responsive-images-settings-table__heading" scope="row"><?php echo esc_html( $label ); ?></th>
			<td class="empirical-responsive-images-settings-table__cell">
				<label class="empirical-responsive-images-settings-table__checkbox-label" for="<?php echo esc_attr( $field_id ); ?>">
					<input class="empirical-responsive-images-settings-table__checkbox" id="<?php echo esc_attr( $field_id ); ?>" type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $options[ $key ] ) ); ?>>
					<?php esc_html_e( 'Enabled', 'empirical-responsive-images' ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render numeric setting row.
	 *
	 * @param string $key     Option key.
	 * @param string $label   Label.
	 * @param array  $options Options.
	 * @param string $min     Min.
	 * @param string $max     Max.
	 * @param string $step    Step.
	 * @return void
	 */
	private function render_number_row( string $key, string $label, array $options, string $min, string $max, string $step ): void {
		$field_id = 'empirical-responsive-images-' . str_replace( '_', '-', $key );
		?>
		<tr class="empirical-responsive-images-settings-table__row">
			<th class="empirical-responsive-images-settings-table__heading" scope="row">
				<label class="empirical-responsive-images-settings-table__label" for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td class="empirical-responsive-images-settings-table__cell">
				<input class="empirical-responsive-images-settings-table__input small-text" id="<?php echo esc_attr( $field_id ); ?>" type="number" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" step="<?php echo esc_attr( $step ); ?>" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) ( $options[ $key ] ?? '' ) ); ?>">
			</td>
		</tr>
		<?php
	}

	/**
	 * Render observed size table.
	 *
	 * @param array $candidates Size candidates.
	 * @return void
	 */
	private function render_sizes_table( array $candidates ): void {
		if ( empty( $candidates ) ) {
			?>
			<p class="empirical-responsive-images-empty-state"><?php esc_html_e( 'No front-end image observations yet. Visit the site on desktop and mobile, then return here.', 'empirical-responsive-images' ); ?></p>
			<?php
			return;
		}
		?>
		<table class="widefat striped empirical-responsive-images-size-table">
			<thead class="empirical-responsive-images-size-table__head">
				<tr class="empirical-responsive-images-size-table__row empirical-responsive-images-size-table__row--head">
					<th class="empirical-responsive-images-size-table__heading" scope="col"><?php esc_html_e( 'Image size', 'empirical-responsive-images' ); ?></th>
					<th class="empirical-responsive-images-size-table__heading" scope="col"><?php esc_html_e( 'Width', 'empirical-responsive-images' ); ?></th>
					<th class="empirical-responsive-images-size-table__heading" scope="col"><?php esc_html_e( 'Height range', 'empirical-responsive-images' ); ?></th>
					<th class="empirical-responsive-images-size-table__heading" scope="col"><?php esc_html_e( 'Observations', 'empirical-responsive-images' ); ?></th>
					<th class="empirical-responsive-images-size-table__heading" scope="col"><?php esc_html_e( 'Viewports', 'empirical-responsive-images' ); ?></th>
					<th class="empirical-responsive-images-size-table__heading" scope="col"><?php esc_html_e( 'DPRs', 'empirical-responsive-images' ); ?></th>
				</tr>
			</thead>
			<tbody class="empirical-responsive-images-size-table__body">
				<?php foreach ( $candidates as $candidate ) : ?>
					<tr class="empirical-responsive-images-size-table__row">
						<td class="empirical-responsive-images-size-table__cell"><code class="empirical-responsive-images-size-table__code"><?php echo esc_html( Image_Sizes::name_for_width( absint( $candidate['width'] ?? 0 ) ) ); ?></code></td>
						<td class="empirical-responsive-images-size-table__cell"><?php echo esc_html( number_format_i18n( absint( $candidate['width'] ?? 0 ) ) ); ?>px</td>
						<td class="empirical-responsive-images-size-table__cell"><?php echo esc_html( number_format_i18n( absint( $candidate['min_height'] ?? 0 ) ) ); ?>-<?php echo esc_html( number_format_i18n( absint( $candidate['max_height'] ?? 0 ) ) ); ?>px</td>
						<td class="empirical-responsive-images-size-table__cell"><?php echo esc_html( number_format_i18n( absint( $candidate['count'] ?? 0 ) ) ); ?></td>
						<td class="empirical-responsive-images-size-table__cell"><?php echo esc_html( implode( ', ', array_keys( is_array( $candidate['viewports'] ?? null ) ? $candidate['viewports'] : array() ) ) ); ?></td>
						<td class="empirical-responsive-images-size-table__cell"><?php echo esc_html( implode( ', ', array_keys( is_array( $candidate['dprs'] ?? null ) ? $candidate['dprs'] : array() ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render observed asset table.
	 *
	 * @param array $assets Asset entries.
	 * @return void
	 */
	private function render_assets_table( array $assets ): void {
		if ( empty( $assets ) ) {
			?>
			<p class="empirical-responsive-images-empty-state"><?php esc_html_e( 'No manageable local asset images observed yet.', 'empirical-responsive-images' ); ?></p>
			<?php
			return;
		}
		?>
		<table class="widefat striped empirical-responsive-images-asset-table">
			<thead class="empirical-responsive-images-asset-table__head">
				<tr class="empirical-responsive-images-asset-table__row empirical-responsive-images-asset-table__row--head">
					<th class="empirical-responsive-images-asset-table__heading" scope="col"><?php esc_html_e( 'Asset', 'empirical-responsive-images' ); ?></th>
					<th class="empirical-responsive-images-asset-table__heading" scope="col"><?php esc_html_e( 'Observed widths', 'empirical-responsive-images' ); ?></th>
					<th class="empirical-responsive-images-asset-table__heading" scope="col"><?php esc_html_e( 'Observed slots', 'empirical-responsive-images' ); ?></th>
					<th class="empirical-responsive-images-asset-table__heading" scope="col"><?php esc_html_e( 'Observations', 'empirical-responsive-images' ); ?></th>
				</tr>
			</thead>
			<tbody class="empirical-responsive-images-asset-table__body">
				<?php foreach ( $assets as $asset ) : ?>
					<tr class="empirical-responsive-images-asset-table__row">
						<td class="empirical-responsive-images-asset-table__cell"><code class="empirical-responsive-images-asset-table__code"><?php echo esc_html( (string) ( $asset['url'] ?? '' ) ); ?></code></td>
						<td class="empirical-responsive-images-asset-table__cell"><?php echo esc_html( implode( ', ', array_map( 'absint', is_array( $asset['target_widths'] ?? null ) ? $asset['target_widths'] : array() ) ) ); ?></td>
						<td class="empirical-responsive-images-asset-table__cell"><?php echo esc_html( $this->format_slot_widths( is_array( $asset['slot_widths'] ?? null ) ? $asset['slot_widths'] : array() ) ); ?></td>
						<td class="empirical-responsive-images-asset-table__cell"><?php echo esc_html( number_format_i18n( absint( $asset['count'] ?? 0 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Format viewport slot widths.
	 *
	 * @param array $slot_widths Slot widths.
	 * @return string
	 */
	private function format_slot_widths( array $slot_widths ): string {
		$items = array();

		foreach ( $slot_widths as $viewport => $slot_width ) {
			$items[] = absint( $viewport ) . 'px:' . absint( $slot_width ) . 'px';
		}

		return implode( ', ', $items );
	}
}
