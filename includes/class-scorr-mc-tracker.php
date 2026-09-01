<?php
/**
 * SCORR_MC_Tracker — records WordPress core and plugin version changes.
 *
 * Keeps a snapshot of installed versions and diffs it on admin loads and
 * after upgrader runs. Any change is appended to a log with from/to
 * versions and a timestamp, so the report can list what was updated no
 * matter how the update happened (manual, auto-update, WP-CLI, etc.).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCORR_MC_Tracker {

	const OPT_SNAPSHOT    = 'scorr_mc_versions';
	const OPT_LOG         = 'scorr_mc_update_log';
	const OPT_LAST_REPORT = 'scorr_mc_last_report';
	const OPT_CUTOFF      = 'scorr_mc_report_cutoff';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_capture' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'capture' ), 20, 0 );
		add_action( 'automatic_updates_complete', array( __CLASS__, 'capture' ), 20, 0 );
	}

	/**
	 * Throttled capture for ordinary admin page loads.
	 */
	public static function maybe_capture() {
		if ( get_transient( 'scorr_mc_capture_lock' ) ) {
			return;
		}
		set_transient( 'scorr_mc_capture_lock', 1, MINUTE_IN_SECONDS );
		self::capture();
	}

	/**
	 * Diff current core/plugin versions against the stored snapshot and
	 * log every change, then store the new snapshot.
	 */
	public static function capture() {
		global $wp_version;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		wp_clean_plugins_cache( false );
		$plugins = get_plugins();

		$current = array(
			'core'    => (string) $wp_version,
			'plugins' => array(),
		);
		foreach ( $plugins as $file => $headers ) {
			$current['plugins'][ $file ] = array(
				'name'    => isset( $headers['Name'] ) && '' !== $headers['Name'] ? $headers['Name'] : $file,
				'version' => isset( $headers['Version'] ) ? (string) $headers['Version'] : '',
			);
		}

		$snapshot = get_option( self::OPT_SNAPSHOT );
		$now      = current_time( 'timestamp' );

		if ( is_array( $snapshot ) && isset( $snapshot['plugins'] ) ) {
			$log     = get_option( self::OPT_LOG, array() );
			$changed = false;

			if ( ! empty( $snapshot['core'] ) && $snapshot['core'] !== $current['core'] ) {
				$log[]   = array(
					'time' => $now,
					'type' => 'core',
					'name' => 'WordPress',
					'from' => $snapshot['core'],
					'to'   => $current['core'],
				);
				$changed = true;
			}

			foreach ( $current['plugins'] as $file => $info ) {
				if ( isset( $snapshot['plugins'][ $file ] ) &&
					'' !== $info['version'] &&
					'' !== $snapshot['plugins'][ $file ]['version'] &&
					$snapshot['plugins'][ $file ]['version'] !== $info['version'] ) {
					$log[]   = array(
						'time' => $now,
						'type' => 'plugin',
						'name' => $info['name'],
						'from' => $snapshot['plugins'][ $file ]['version'],
						'to'   => $info['version'],
					);
					$changed = true;
				}
			}

			if ( $changed ) {
				// Keep the log to a sane size (newest entries win).
				if ( count( $log ) > 300 ) {
					$log = array_slice( $log, -300 );
				}
				update_option( self::OPT_LOG, $log, false );
			}
		}

		if ( $snapshot !== $current ) {
			update_option( self::OPT_SNAPSHOT, $current, false );
		}
	}

	/**
	 * Log entries recorded on/after a timestamp, oldest first.
	 */
	public static function get_log_since( $timestamp ) {
		$log = get_option( self::OPT_LOG, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}
		$out = array();
		foreach ( $log as $entry ) {
			if ( isset( $entry['time'] ) && $entry['time'] >= $timestamp ) {
				$out[] = $entry;
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $a['time'] <=> $b['time'];
			}
		);
		return $out;
	}

	/**
	 * Timestamp the report's "updated since" window starts at.
	 *
	 * Normally the previous report's generation time. Regenerating a report
	 * on the same day reuses the previous cutoff so the PDF comes out
	 * identical instead of losing the updates it just listed. With no
	 * previous report, fall back to the last 30 days.
	 */
	public static function get_report_cutoff() {
		$last   = (int) get_option( self::OPT_LAST_REPORT, 0 );
		$cutoff = (int) get_option( self::OPT_CUTOFF, 0 );
		$now    = current_time( 'timestamp' );

		if ( $last && $cutoff && gmdate( 'Y-m-d', $last ) === gmdate( 'Y-m-d', $now ) ) {
			return $cutoff;
		}
		if ( $last ) {
			return $last;
		}
		return $now - 30 * DAY_IN_SECONDS;
	}

	/**
	 * Roll the reporting window after a report has been generated.
	 */
	public static function mark_report_generated() {
		update_option( self::OPT_CUTOFF, self::get_report_cutoff(), false );
		update_option( self::OPT_LAST_REPORT, current_time( 'timestamp' ), false );
	}
}
