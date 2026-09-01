<?php
/**
 * SCORR_MC_Admin — the "Maintenance Checks" admin screen and the
 * Generate Report (PDF download) endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCORR_MC_Admin {

	const SLUG = 'scorr-maintenance-check';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_force_check' ) );
		add_action( 'admin_post_scorr_mc_generate_report', array( __CLASS__, 'handle_generate' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'SCORR Maintenance Checks', 'scorr-maintenance-check' ),
			__( 'Maintenance Checks', 'scorr-maintenance-check' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-yes-alt'
		);
	}

	public static function enqueue( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'scorr-mc-admin', SCORR_MC_URL . 'assets/admin.css', array(), SCORR_MC_VERSION );
		wp_enqueue_script( 'scorr-mc-admin', SCORR_MC_URL . 'assets/admin.js', array(), SCORR_MC_VERSION, true );

		wp_localize_script(
			'scorr-mc-admin',
			'scorrMC',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'scorr_mc_seo' ),
				'total'   => count( SCORR_MC_SEO::get_scan_targets() ),
			)
		);
	}

	/**
	 * "Check again" link: flush update caches, then bounce back to the screen.
	 */
	public static function maybe_force_check() {
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SLUG !== $page || ! isset( $_GET['scorr-mc-force-check'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'scorr_mc_force_check' );

		wp_clean_update_cache();
		wp_version_check( array(), true );
		wp_update_plugins();
		SCORR_MC_Tracker::capture();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&scorr-mc-checked=1' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Screen                                                              */
	/* ------------------------------------------------------------------ */

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wp_version;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Refresh update data (WordPress throttles these internally).
		wp_version_check();
		wp_update_plugins();
		SCORR_MC_Tracker::capture();

		// Latest core version on offer.
		$latest    = $wp_version;
		$core_up   = false;
		$transient = get_site_transient( 'update_core' );
		if ( $transient && ! empty( $transient->updates ) ) {
			foreach ( $transient->updates as $offer ) {
				if ( 'upgrade' === $offer->response && ! empty( $offer->current ) ) {
					$latest  = $offer->current;
					$core_up = version_compare( $offer->current, $wp_version, '>' );
					break;
				}
				if ( 'latest' === $offer->response && ! empty( $offer->current ) ) {
					$latest = $offer->current;
				}
			}
		}

		// Pending plugin updates (same transient the Plugins screen uses).
		$pending     = array();
		$all_plugins = get_plugins();
		$updates     = get_site_transient( 'update_plugins' );
		if ( $updates && ! empty( $updates->response ) ) {
			foreach ( $updates->response as $file => $update ) {
				$pending[] = array(
					'name'    => isset( $all_plugins[ $file ]['Name'] ) ? $all_plugins[ $file ]['Name'] : $file,
					'current' => isset( $all_plugins[ $file ]['Version'] ) ? $all_plugins[ $file ]['Version'] : '',
					'new'     => isset( $update->new_version ) ? $update->new_version : '',
				);
			}
			usort(
				$pending,
				function ( $a, $b ) {
					return strcasecmp( $a['name'], $b['name'] );
				}
			);
		}

		$cutoff  = SCORR_MC_Tracker::get_report_cutoff();
		$recent  = SCORR_MC_Tracker::get_log_since( $cutoff );
		$seo     = SCORR_MC_SEO::get_results();
		$targets = count( SCORR_MC_SEO::get_scan_targets() );

		$force_url = wp_nonce_url(
			admin_url( 'admin.php?page=' . self::SLUG . '&scorr-mc-force-check=1' ),
			'scorr_mc_force_check'
		);
		?>
		<div class="wrap scorr-mc-wrap">

			<div class="scorr-mc-header">
				<img src="<?php echo esc_url( SCORR_MC_URL . 'assets/scorr-logo.png' ); ?>" alt="SCORR Marketing" class="scorr-mc-logo" />
				<h1><?php esc_html_e( 'SCORR Maintenance Checks', 'scorr-maintenance-check' ); ?></h1>
			</div>

			<?php if ( isset( $_GET['scorr-mc-checked'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Update information refreshed.', 'scorr-maintenance-check' ); ?></p></div>
			<?php endif; ?>

			<div class="scorr-mc-grid">

				<div class="scorr-mc-card">
					<h2><?php esc_html_e( 'WordPress', 'scorr-maintenance-check' ); ?></h2>
					<p class="scorr-mc-version"><?php echo esc_html( $wp_version ); ?></p>
					<p>
						<?php
						/* translators: %s: version number */
						printf( esc_html__( 'Latest available: %s', 'scorr-maintenance-check' ), esc_html( $latest ) );
						?>
						<?php if ( $core_up ) : ?>
							<span class="scorr-mc-badge warn"><?php esc_html_e( 'Update available', 'scorr-maintenance-check' ); ?></span>
						<?php else : ?>
							<span class="scorr-mc-badge ok"><?php esc_html_e( 'Up to date', 'scorr-maintenance-check' ); ?></span>
						<?php endif; ?>
					</p>
					<p><a href="<?php echo esc_url( $force_url ); ?>"><?php esc_html_e( 'Check again', 'scorr-maintenance-check' ); ?></a></p>
				</div>

				<div class="scorr-mc-card">
					<h2><?php esc_html_e( 'Generate Report', 'scorr-maintenance-check' ); ?></h2>
					<form id="scorr-mc-generate-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="scorr_mc_generate_report" />
						<?php wp_nonce_field( 'scorr_mc_generate' ); ?>
						<p>
							<label>
								<input type="checkbox" name="scorr_mc_backup" value="1" checked />
								<?php esc_html_e( 'Site backup completed', 'scorr-maintenance-check' ); ?>
							</label>
						</p>
						<p>
							<label>
								<input type="checkbox" name="scorr_mc_contact_form" value="1" />
								<?php esc_html_e( 'Contact form tested and working', 'scorr-maintenance-check' ); ?>
							</label>
						</p>
						<p class="scorr-mc-comments-field">
							<label for="scorr-mc-comments"><strong><?php esc_html_e( 'Additional Comments', 'scorr-maintenance-check' ); ?></strong></label>
							<textarea name="scorr_mc_comments" id="scorr-mc-comments" rows="4" class="large-text" maxlength="5000" placeholder="<?php esc_attr_e( 'Optional — only included in the PDF when filled in.', 'scorr-maintenance-check' ); ?>"></textarea>
						</p>
						<p>
							<button type="submit" class="button button-primary button-hero scorr-mc-generate-btn">
								<?php esc_html_e( 'Generate Report', 'scorr-maintenance-check' ); ?>
							</button>
						</p>
						<p class="description">
							<?php esc_html_e( 'Downloads a branded PDF. The SEO section is included only if you run an SEO scan before generating; otherwise it is left out.', 'scorr-maintenance-check' ); ?>
						</p>
					</form>
				</div>

				<div class="scorr-mc-card scorr-mc-card-wide">
					<h2>
						<?php esc_html_e( 'Plugin updates available', 'scorr-maintenance-check' ); ?>
						<span class="scorr-mc-count <?php echo $pending ? 'warn' : 'ok'; ?>"><?php echo esc_html( count( $pending ) ); ?></span>
					</h2>
					<?php if ( empty( $pending ) ) : ?>
						<p class="scorr-mc-allgood"><?php esc_html_e( 'All plugins are up to date.', 'scorr-maintenance-check' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Plugin', 'scorr-maintenance-check' ); ?></th>
									<th><?php esc_html_e( 'Current', 'scorr-maintenance-check' ); ?></th>
									<th><?php esc_html_e( 'Available', 'scorr-maintenance-check' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $pending as $row ) : ?>
									<tr>
										<td><?php echo esc_html( $row['name'] ); ?></td>
										<td><?php echo esc_html( $row['current'] ); ?></td>
										<td><strong><?php echo esc_html( $row['new'] ); ?></strong></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p class="description">
							<a href="<?php echo esc_url( admin_url( 'plugins.php?plugin_status=upgrade' ) ); ?>">
								<?php esc_html_e( 'Update these on the Plugins screen', 'scorr-maintenance-check' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>

				<div class="scorr-mc-card scorr-mc-card-wide">
					<h2><?php esc_html_e( 'Updates recorded since last report', 'scorr-maintenance-check' ); ?></h2>
					<p class="description">
						<?php
						/* translators: %s: date */
						printf( esc_html__( 'Window started %s. Core and plugin updates are logged automatically.', 'scorr-maintenance-check' ), esc_html( date_i18n( 'F j, Y', $cutoff ) ) );
						?>
					</p>
					<?php if ( empty( $recent ) ) : ?>
						<p><?php esc_html_e( 'No updates recorded in this window yet.', 'scorr-maintenance-check' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Name', 'scorr-maintenance-check' ); ?></th>
									<th><?php esc_html_e( 'From', 'scorr-maintenance-check' ); ?></th>
									<th><?php esc_html_e( 'To', 'scorr-maintenance-check' ); ?></th>
									<th><?php esc_html_e( 'Date', 'scorr-maintenance-check' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $recent as $entry ) : ?>
									<tr>
										<td>
											<?php echo esc_html( $entry['name'] ); ?>
											<?php if ( 'core' === $entry['type'] ) : ?>
												<span class="scorr-mc-badge core"><?php esc_html_e( 'Core', 'scorr-maintenance-check' ); ?></span>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( $entry['from'] ); ?></td>
										<td><strong><?php echo esc_html( $entry['to'] ); ?></strong></td>
										<td><?php echo esc_html( date_i18n( 'M j, Y', $entry['time'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<div class="scorr-mc-card scorr-mc-card-wide" id="scorr-mc-seo-card">
					<h2><?php esc_html_e( 'SEO — Meta descriptions', 'scorr-maintenance-check' ); ?></h2>
					<p id="scorr-mc-seo-summary">
						<?php if ( is_array( $seo ) && isset( $seo['time'] ) ) : ?>
							<?php
							printf(
								/* translators: 1: page count, 2: date, 3: missing count */
								esc_html__( 'Last scan: %1$d pages on %2$s — %3$d missing a meta description.', 'scorr-maintenance-check' ),
								(int) $seo['scanned'],
								esc_html( date_i18n( 'M j, Y g:i a', $seo['time'] ) ),
								is_array( $seo['missing'] ) ? count( $seo['missing'] ) : 0
							);
							?>
						<?php else : ?>
							<?php
							/* translators: %d: number of pages */
							printf( esc_html__( 'No scan has been run yet. %d published pages will be checked.', 'scorr-maintenance-check' ), (int) $targets );
							?>
						<?php endif; ?>
					</p>
					<?php if ( is_array( $seo ) && isset( $seo['time'] ) && $seo['time'] >= $cutoff ) : ?>
						<p><span class="scorr-mc-badge ok"><?php esc_html_e( 'Will be included in the next report', 'scorr-maintenance-check' ); ?></span></p>
					<?php else : ?>
						<p><span class="scorr-mc-badge warn"><?php esc_html_e( 'Not included in the next report — run a scan to include SEO results', 'scorr-maintenance-check' ); ?></span></p>
					<?php endif; ?>
					<p>
						<button type="button" class="button" id="scorr-mc-scan"><?php esc_html_e( 'Run SEO Scan', 'scorr-maintenance-check' ); ?></button>
					</p>
					<div class="scorr-mc-progress" id="scorr-mc-scan-progress" hidden>
						<div class="scorr-mc-progress-bar"></div>
						<p class="scorr-mc-progress-text"></p>
					</div>

					<?php if ( is_array( $seo ) && ! empty( $seo['missing'] ) ) : ?>
						<h3>
							<?php
							/* translators: %d: count */
							printf( esc_html__( 'Missing meta descriptions (%d)', 'scorr-maintenance-check' ), count( $seo['missing'] ) );
							?>
						</h3>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Page', 'scorr-maintenance-check' ); ?></th>
									<th><?php esc_html_e( 'URL', 'scorr-maintenance-check' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $seo['missing'] as $row ) : ?>
									<tr>
										<td><?php echo esc_html( $row['title'] ); ?></td>
										<td><a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['url'] ); ?></a></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php elseif ( is_array( $seo ) && isset( $seo['time'] ) ) : ?>
						<p class="scorr-mc-allgood"><?php esc_html_e( 'All scanned pages have a meta description.', 'scorr-maintenance-check' ); ?></p>
					<?php endif; ?>

					<?php if ( is_array( $seo ) && ! empty( $seo['errors'] ) ) : ?>
						<p class="description">
							<?php
							/* translators: %d: count */
							printf( esc_html__( '%d pages could not be checked (fetch error).', 'scorr-maintenance-check' ), count( $seo['errors'] ) );
							?>
						</p>
					<?php endif; ?>
				</div>

			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* PDF download                                                        */
	/* ------------------------------------------------------------------ */

	public static function handle_generate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'scorr-maintenance-check' ) );
		}
		check_admin_referer( 'scorr_mc_generate' );

		$comments = '';
		if ( isset( $_POST['scorr_mc_comments'] ) && is_string( $_POST['scorr_mc_comments'] ) ) {
			$comments = sanitize_textarea_field( wp_unslash( $_POST['scorr_mc_comments'] ) );
			$comments = function_exists( 'mb_substr' ) ? mb_substr( $comments, 0, 5000 ) : substr( $comments, 0, 5000 );
		}

		$data = SCORR_MC_Report::collect_data(
			array(
				'backup'       => ! empty( $_POST['scorr_mc_backup'] ),
				'contact_form' => ! empty( $_POST['scorr_mc_contact_form'] ),
				'comments'     => $comments,
			)
		);

		$pdf = SCORR_MC_Report::build( $data );

		SCORR_MC_Tracker::mark_report_generated();

		$filename = sanitize_title( get_bloginfo( 'name' ) );
		$filename = ( $filename ? $filename : 'website' ) . '-maintenance-report-' . date_i18n( 'Y-m-d' ) . '.pdf';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}
}
