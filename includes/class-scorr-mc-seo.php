<?php
/**
 * SCORR_MC_SEO — scans the site's published pages for missing meta
 * descriptions by fetching each page's rendered HTML. Works with any SEO
 * plugin (or none), since it checks the actual output.
 *
 * The scan runs in small AJAX batches from the admin screen so large
 * sites don't time out; results are stored with a timestamp for the report.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCORR_MC_SEO {

	const OPT_RESULTS = 'scorr_mc_seo_results';
	const TRAN_PARTIAL = 'scorr_mc_seo_partial';
	const BATCH_SIZE  = 5;
	const MAX_PAGES   = 500;

	public static function init() {
		add_action( 'wp_ajax_scorr_mc_seo_scan', array( __CLASS__, 'ajax_scan' ) );
	}

	/**
	 * All URLs the scan will check: the front page plus every published
	 * item of a publicly viewable post type (capped at MAX_PAGES).
	 */
	public static function get_scan_targets() {
		$targets = array();
		$seen    = array();

		$add = function ( $title, $url ) use ( &$targets, &$seen ) {
			$url = esc_url_raw( $url );
			if ( ! $url || isset( $seen[ $url ] ) ) {
				return;
			}
			$seen[ $url ]  = true;
			$targets[] = array(
				'title' => wp_specialchars_decode( $title ?: '(no title)', ENT_QUOTES ),
				'url'   => $url,
			);
		};

		// Front page (covers the "latest posts" homepage, which has no post ID).
		$add( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ' (home)', home_url( '/' ) );

		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			if ( in_array( $type->name, array( 'post', 'page' ), true ) || $type->publicly_queryable ) {
				$types[] = $type->name;
			}
		}
		if ( empty( $types ) ) {
			return $targets;
		}

		$ids = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'numberposts'      => self::MAX_PAGES,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		foreach ( $ids as $id ) {
			$link = get_permalink( $id );
			if ( $link ) {
				$add( get_the_title( $id ), $link );
			}
		}

		return $targets;
	}

	/**
	 * AJAX: scan one batch. The client calls this repeatedly with an
	 * increasing offset until `done` comes back true.
	 */
	public static function ajax_scan() {
		check_ajax_referer( 'scorr_mc_seo', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$offset  = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$targets = self::get_scan_targets();
		$total   = count( $targets );

		$partial = ( 0 === $offset ) ? array( 'missing' => array(), 'errors' => array() ) : get_transient( self::TRAN_PARTIAL );
		if ( ! is_array( $partial ) ) {
			$partial = array( 'missing' => array(), 'errors' => array() );
		}

		$batch = array_slice( $targets, $offset, self::BATCH_SIZE );
		foreach ( $batch as $target ) {
			$check = self::check_url( $target['url'] );
			if ( 'error' === $check ) {
				$partial['errors'][] = $target;
			} elseif ( 'missing' === $check ) {
				$partial['missing'][] = $target;
			}
		}

		$next = $offset + count( $batch );
		$done = $next >= $total;

		if ( $done ) {
			update_option(
				self::OPT_RESULTS,
				array(
					'time'    => current_time( 'timestamp' ),
					'scanned' => $total,
					'missing' => $partial['missing'],
					'errors'  => $partial['errors'],
				),
				false
			);
			delete_transient( self::TRAN_PARTIAL );
		} else {
			set_transient( self::TRAN_PARTIAL, $partial, HOUR_IN_SECONDS );
		}

		wp_send_json_success(
			array(
				'done'    => $done,
				'offset'  => $next,
				'total'   => $total,
				'missing' => count( $partial['missing'] ),
			)
		);
	}

	/**
	 * Fetch a URL and report 'ok', 'missing', or 'error'.
	 */
	public static function check_url( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'sslverify'   => false, // self-scan; local/staging certs are often self-signed.
				'user-agent'  => 'SCORR-Maintenance-Check/' . SCORR_MC_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return 'error';
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 || $code < 200 ) {
			return 'error';
		}

		return self::html_has_meta_description( wp_remote_retrieve_body( $response ) ) ? 'ok' : 'missing';
	}

	/**
	 * True when the HTML contains a non-empty <meta name="description">.
	 */
	public static function html_has_meta_description( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return false;
		}
		if ( ! preg_match_all( '/<meta\b[^>]*\bname\s*=\s*(["\']?)description\1[^>]*>/i', $html, $tags ) ) {
			return false;
		}
		foreach ( $tags[0] as $tag ) {
			if ( preg_match( '/\bcontent\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $m ) ) {
				$content = isset( $m[3] ) && '' !== $m[3] ? $m[3] : $m[2];
				if ( '' !== trim( html_entity_decode( $content ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Stored results of the last completed scan (or false).
	 */
	public static function get_results() {
		return get_option( self::OPT_RESULTS );
	}
}
