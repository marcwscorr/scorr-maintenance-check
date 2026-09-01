<?php
/**
 * Plugin Name:       SCORR Maintenance Check
 * Description:       Website maintenance reporting for SCORR Marketing client sites — tracks WordPress core and plugin updates, scans for missing meta descriptions, and generates a branded PDF maintenance report.
 * Version:           1.0.0
 * Author:            SCORR Marketing
 * Author URI:        https://www.scorrmarketing.com
 * License:           GPL-2.0-or-later
 * Text Domain:       scorr-maintenance-check
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Update URI:        false
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCORR_MC_VERSION', '1.0.0' );
define( 'SCORR_MC_FILE', __FILE__ );
define( 'SCORR_MC_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCORR_MC_URL', plugin_dir_url( __FILE__ ) );

require_once SCORR_MC_DIR . 'includes/class-scorr-mc-tracker.php';
require_once SCORR_MC_DIR . 'includes/class-scorr-mc-seo.php';
require_once SCORR_MC_DIR . 'includes/class-scorr-mc-pdf.php';
require_once SCORR_MC_DIR . 'includes/class-scorr-mc-report.php';
require_once SCORR_MC_DIR . 'includes/class-scorr-mc-admin.php';

SCORR_MC_Tracker::init();
SCORR_MC_SEO::init();
SCORR_MC_Admin::init();

/**
 * Seed the version snapshot on activation so update tracking starts immediately.
 */
register_activation_hook( __FILE__, array( 'SCORR_MC_Tracker', 'capture' ) );
