<?php
/**
 * SCORR_MC_Report — gathers maintenance data from WordPress and lays out
 * the branded PDF report.
 *
 * collect_data() touches WordPress; build() is pure PHP so the layout can
 * be tested from the CLI with fixture data.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'SCORR_MC_CLI_TEST' ) ) {
	exit;
}

class SCORR_MC_Report {

	/* Brand palette. */
	const RED   = array( 167, 25, 48 );  // #a71930.
	const TEAL  = array( 0, 151, 169 );  // #0097a9.
	const INK   = array( 20, 20, 20 );
	const GRAY  = array( 105, 105, 105 );
	const RULE  = array( 214, 214, 214 );
	const BAND  = array( 243, 243, 243 );
	const ZEBRA = array( 250, 250, 250 );

	const MARGIN    = 54.0;
	const CONTENT_W = 504.0; // 612 - 2 * 54.
	const BOTTOM    = 726.0; // content must stay above this; footer sits below.

	private $pdf;
	private $y;

	/* ------------------------------------------------------------------ */
	/* Data collection (WordPress context)                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Assemble everything the PDF needs.
	 *
	 * @param array $args { backup: bool, contact_form: bool }
	 */
	public static function collect_data( $args = array() ) {
		global $wp_version;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Make sure update info is current before reporting.
		wp_version_check();
		wp_update_plugins();
		SCORR_MC_Tracker::capture();

		$now = current_time( 'timestamp' );

		// Pending plugin updates (same source as the Plugins screen).
		$pending    = array();
		$all_plugins = get_plugins();
		$transient  = get_site_transient( 'update_plugins' );
		if ( $transient && ! empty( $transient->response ) ) {
			foreach ( $transient->response as $file => $update ) {
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

		// Latest core version on offer.
		$core_latest    = $wp_version;
		$core_available = false;
		$core_transient = get_site_transient( 'update_core' );
		if ( $core_transient && ! empty( $core_transient->updates ) ) {
			foreach ( $core_transient->updates as $offer ) {
				if ( 'upgrade' === $offer->response && ! empty( $offer->current ) ) {
					$core_latest    = $offer->current;
					$core_available = version_compare( $offer->current, $wp_version, '>' );
					break;
				}
				if ( 'latest' === $offer->response && ! empty( $offer->current ) ) {
					$core_latest = $offer->current;
				}
			}
		}

		// Updates recorded since the last report.
		$cutoff          = SCORR_MC_Tracker::get_report_cutoff();
		$entries         = SCORR_MC_Tracker::get_log_since( $cutoff );
		$plugins_updated = array();
		$core_updates    = array();
		foreach ( $entries as $entry ) {
			$row = array(
				'name' => $entry['name'],
				'from' => $entry['from'],
				'to'   => $entry['to'],
				'date' => date_i18n( 'M j, Y', $entry['time'] ),
			);
			if ( 'core' === $entry['type'] ) {
				$core_updates[] = $row;
			} else {
				$plugins_updated[] = $row;
			}
		}
		usort(
			$plugins_updated,
			function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		// SEO scan results — included only when the scan was run during this
		// reporting window (i.e. after the previous report was generated).
		$seo     = get_option( SCORR_MC_SEO::OPT_RESULTS );
		$seo_out = null;
		if ( is_array( $seo ) && isset( $seo['time'] ) && $seo['time'] >= $cutoff ) {
			$seo_out = array(
				'date'    => date_i18n( 'F j, Y g:i a', $seo['time'] ),
				'scanned' => (int) $seo['scanned'],
				'missing' => is_array( $seo['missing'] ) ? array_values( $seo['missing'] ) : array(),
				'errors'  => isset( $seo['errors'] ) && is_array( $seo['errors'] ) ? array_values( $seo['errors'] ) : array(),
			);
		}

		return array(
			'site_name'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_host'       => preg_replace( '#^https?://#', '', untrailingslashit( home_url() ) ),
			'date'            => date_i18n( 'F j, Y', $now ),
			'backup'          => ! empty( $args['backup'] ),
			'contact_form'    => ! empty( $args['contact_form'] ),
			'comments'        => isset( $args['comments'] ) ? (string) $args['comments'] : '',
			'wp_current'      => $wp_version,
			'wp_latest'       => $core_latest,
			'core_available'  => $core_available,
			'core_updates'    => $core_updates,
			'plugins_updated' => $plugins_updated,
			'plugins_pending' => $pending,
			'since'           => date_i18n( 'F j, Y', $cutoff ),
			'seo'             => $seo_out,
			'scorr_logo'      => SCORR_MC_DIR . 'assets/scorr-logo.jpg',
			'site_logo'       => self::prepare_site_logo(),
		);
	}

	/**
	 * Flatten the site's customizer logo to a temporary JPEG the PDF can
	 * embed. Returns a path or null (SVG logos and GD-less hosts are skipped).
	 */
	private static function prepare_site_logo() {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( ! $logo_id ) {
			return null;
		}
		$file = get_attached_file( $logo_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return null;
		}
		$info = @getimagesize( $file );
		if ( ! $info ) {
			return null; // SVG or unreadable.
		}
		if ( IMAGETYPE_JPEG === $info[2] ) {
			return $file;
		}
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return null;
		}
		$src = @imagecreatefromstring( file_get_contents( $file ) );
		if ( ! $src ) {
			return null;
		}
		$w = imagesx( $src );
		$h = imagesy( $src );
		if ( $w > 1600 ) {
			$scaled = imagescale( $src, 1600 );
			if ( $scaled ) {
				imagedestroy( $src );
				$src = $scaled;
				$w   = imagesx( $src );
				$h   = imagesy( $src );
			}
		}
		$canvas = imagecreatetruecolor( $w, $h );
		$white  = imagecolorallocate( $canvas, 255, 255, 255 );
		imagefill( $canvas, 0, 0, $white );
		imagecopy( $canvas, $src, 0, 0, 0, 0, $w, $h );
		$tmp = trailingslashit( get_temp_dir() ) . 'scorr-mc-site-logo-' . md5( $file . filemtime( $file ) ) . '.jpg';
		$ok  = imagejpeg( $canvas, $tmp, 90 );
		imagedestroy( $src );
		imagedestroy( $canvas );
		return $ok ? $tmp : null;
	}

	/* ------------------------------------------------------------------ */
	/* PDF layout (pure PHP)                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Build the PDF and return it as a binary string.
	 */
	public static function build( $data ) {
		$report      = new self();
		$report->pdf = new SCORR_MC_PDF( 'Maintenance Report - ' . $data['site_name'] );
		return $report->render( $data );
	}

	private function color( $method, $rgb ) {
		$this->pdf->{$method}( $rgb[0], $rgb[1], $rgb[2] );
	}

	private function render( $data ) {
		$pdf = $this->pdf;
		$pdf->add_page();

		$this->draw_header( $data );

		/* -------------------- Maintenance summary -------------------- */
		$this->section_title( 'Maintenance Summary' );

		$core_line = 'Up to date (version ' . $data['wp_current'] . ')';
		if ( ! empty( $data['core_updates'] ) ) {
			$first     = $data['core_updates'][0];
			$last      = end( $data['core_updates'] );
			$core_line = 'Updated ' . $first['from'] . ' ' . "\xC2\xBB" . ' ' . $last['to'] . ' on ' . $last['date'];
		} elseif ( ! empty( $data['core_available'] ) ) {
			$core_line = 'Update available: ' . $data['wp_current'] . ' ' . "\xC2\xBB" . ' ' . $data['wp_latest'];
		}

		$rows = array(
			array( 'Maintenance date', $data['date'] ),
			array( 'Website backup', $data['backup'] ? 'Completed' : 'Not completed during this check' ),
			array( 'WordPress core update', $core_line ),
			array( 'Current WordPress version', $data['wp_current'] . ( version_compare( $data['wp_latest'], $data['wp_current'], '>' ) ? '  (latest available: ' . $data['wp_latest'] . ')' : '  (latest)' ) ),
			array( 'Plugin updates applied', (string) count( $data['plugins_updated'] ) . ' since last report' ),
			array( 'Plugin updates pending', empty( $data['plugins_pending'] ) ? 'None' : (string) count( $data['plugins_pending'] ) ),
		);
		if ( ! empty( $data['contact_form'] ) ) {
			$rows[] = array( 'Contact form', 'Tested and working' );
		}

		foreach ( $rows as $row ) {
			$this->summary_row( $row[0], $row[1] );
		}
		$this->y += 10;

		/* -------------------- Additional comments -------------------- */
		if ( isset( $data['comments'] ) && '' !== trim( (string) $data['comments'] ) ) {
			$this->section_title( 'Additional Comments' );
			$this->comments_block( $data['comments'] );
			$this->y += 14;
		}

		/* -------------------- Plugins updated ------------------------ */
		$this->section_title( 'Plugins Updated' );
		$this->note_line( 'Updates recorded since the last report (' . $data['since'] . ').' );

		if ( empty( $data['plugins_updated'] ) ) {
			$this->body_line( 'No plugin updates were recorded during this period.' );
		} else {
			$this->table(
				array( 'Plugin', 'Previous', 'Updated To', 'Date' ),
				array( 234, 80, 90, 100 ),
				array_map(
					function ( $r ) {
						return array( $r['name'], $r['from'], $r['to'], $r['date'] );
					},
					$data['plugins_updated']
				)
			);
		}
		$this->y += 14;

		/* -------------------- Plugins awaiting update ----------------- */
		$this->section_title( 'Plugins Awaiting Update' );

		if ( empty( $data['plugins_pending'] ) ) {
			$this->body_line( 'All plugins are up to date.' );
		} else {
			$this->note_line( 'These updates were available when this report was generated.' );
			$this->table(
				array( 'Plugin', 'Current Version', 'Available Version' ),
				array( 284, 110, 110 ),
				array_map(
					function ( $r ) {
						return array( $r['name'], $r['current'], $r['new'] );
					},
					$data['plugins_pending']
				)
			);
		}
		$this->y += 14;

		/* ---- SEO — only when a scan was run for this report cycle ---- */
		if ( ! empty( $data['seo'] ) ) {
			$seo = $data['seo'];
			$this->section_title( 'SEO' );
			$this->note_line( 'Scanned ' . $seo['scanned'] . ' published pages on ' . $seo['date'] . '.' );

			if ( empty( $seo['missing'] ) ) {
				$this->body_line( 'All scanned pages have a meta description.' );
			} else {
				$this->body_line( count( $seo['missing'] ) . ' page' . ( 1 === count( $seo['missing'] ) ? ' is' : 's are' ) . ' missing a meta description:', 'B' );
				$this->y += 2;
				$this->table(
					array( 'Page', 'URL' ),
					array( 200, 304 ),
					array_map(
						function ( $r ) {
							return array( $r['title'], $r['url'] );
						},
						$seo['missing']
					)
				);
			}

			if ( ! empty( $seo['errors'] ) ) {
				$this->y += 6;
				$this->note_line( count( $seo['errors'] ) . ' page' . ( 1 === count( $seo['errors'] ) ? '' : 's' ) . ' could not be checked (fetch error).' );
			}
		}

		$this->draw_footers( $data );

		return $pdf->output();
	}

	/* -------------------- header / footer ----------------------------- */

	private function draw_header( $data ) {
		$pdf = $this->pdf;
		$x   = self::MARGIN;

		// SCORR logo (aspect 1200x546), 42pt tall.
		$logo_h = 42.0;
		$placed = $pdf->image( $data['scorr_logo'], $x, 42, 0, $logo_h );
		$x     += ( $placed ? $placed['w'] : 0 ) + 14;

		// Optional site logo: "+" divider, then logo fitted to 42pt tall / 150pt wide.
		if ( ! empty( $data['site_logo'] ) ) {
			$size = SCORR_MC_PDF::jpeg_size( $data['site_logo'] );
			if ( $size ) {
				$pdf->set_font( '', 20 );
				$this->color( 'set_text_color', self::GRAY );
				$pdf->text( $x, 42 + $logo_h / 2 + 7, '+' );
				$x += $pdf->string_width( '+' ) + 14;

				$w = $logo_h * $size['w'] / $size['h'];
				$h = $logo_h;
				if ( $w > 150 ) {
					$h = $h * 150 / $w;
					$w = 150;
				}
				$pdf->image( $data['site_logo'], $x, 42 + ( $logo_h - $h ) / 2, $w, $h );
				$x += $w;
			}
		}

		// Right-aligned "{CLIENT NAME} MAINTENANCE REPORT" + date. Shrink to
		// fit beside the logos; truncate the name as a last resort.
		$name  = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $data['site_name'], 'UTF-8' ) : strtoupper( $data['site_name'] );
		$label = trim( $name . ' MAINTENANCE REPORT' );
		$avail = self::MARGIN + self::CONTENT_W - $x - 12;
		$size  = 10.0;
		$pdf->set_font( 'B', $size );
		while ( $size > 7 && $pdf->string_width( $label ) > $avail ) {
			$size -= 0.5;
			$pdf->set_font( 'B', $size );
		}
		while ( $pdf->string_width( $label ) > $avail && ( function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name ) ) > 4 ) {
			$name  = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, -2 ) : substr( $name, 0, -2 );
			$label = rtrim( $name ) . '... MAINTENANCE REPORT';
		}
		$this->color( 'set_text_color', self::INK );
		$pdf->text_right( self::MARGIN + self::CONTENT_W, 58, $label );
		$pdf->set_font( '', 10 );
		$this->color( 'set_text_color', self::GRAY );
		$pdf->text_right( self::MARGIN + self::CONTENT_W, 72, $data['date'] );

		// Two-tone brand rule.
		$this->color( 'set_fill_color', self::RED );
		$pdf->rect( self::MARGIN, 100, 426, 3.2 );
		$this->color( 'set_fill_color', self::TEAL );
		$pdf->rect( self::MARGIN + 428, 100, 76, 3.2 );

		$this->y = 130;
	}

	private function draw_footers( $data ) {
		$pdf   = $this->pdf;
		$total = $pdf->page_count();
		for ( $i = 0; $i < $total; $i++ ) {
			$pdf->select_page( $i );

			$this->color( 'set_draw_color', self::RULE );
			$pdf->set_line_width( 0.75 );
			$pdf->line( self::MARGIN, 748, self::MARGIN + self::CONTENT_W, 748 );

			$pdf->bullseye( self::MARGIN + 5, 761, 5 );

			$pdf->set_font( 'B', 8 );
			$this->color( 'set_text_color', self::INK );
			$pdf->text( self::MARGIN + 16, 764, 'SCORR Marketing' );

			$pdf->set_font( '', 8 );
			$this->color( 'set_text_color', self::GRAY );
			$pdf->text_right(
				self::MARGIN + self::CONTENT_W,
				764,
				'Generated ' . $data['date'] . '   ' . "\xC2\xB7" . '   Page ' . ( $i + 1 ) . ' of ' . $total
			);
		}
	}

	/* -------------------- layout helpers ------------------------------ */

	private function ensure_space( $needed ) {
		if ( $this->y + $needed > self::BOTTOM ) {
			$this->pdf->add_page();
			$this->y = 60;
		}
	}

	private function section_title( $title ) {
		// Never strand a section heading at the bottom of a page.
		$this->ensure_space( 96 );
		$pdf = $this->pdf;

		$pdf->set_font( 'B', 14 );
		$this->color( 'set_text_color', self::INK );
		$pdf->text( self::MARGIN, $this->y + 11.5, $title );

		$this->color( 'set_draw_color', self::RULE );
		$pdf->set_line_width( 0.75 );
		$pdf->line( self::MARGIN, $this->y + 21, self::MARGIN + self::CONTENT_W, $this->y + 21 );

		$this->y += 36;
	}

	private function summary_row( $label, $value ) {
		$this->ensure_space( 18 );
		$pdf = $this->pdf;

		$pdf->set_font( 'B', 10 );
		$this->color( 'set_text_color', self::INK );
		$pdf->text( self::MARGIN, $this->y, $label );

		$pdf->set_font( '', 10 );
		$lines = $pdf->wrap( $value, self::CONTENT_W - 190 );
		foreach ( $lines as $k => $line ) {
			if ( $k > 0 ) {
				$this->y += 15;
				$this->ensure_space( 15 );
			}
			$pdf->text( self::MARGIN + 190, $this->y, $line );
		}
		$this->y += 17;
	}

	/**
	 * Free-form comment text: preserves line breaks, wraps long lines,
	 * and breaks across pages as needed.
	 */
	private function comments_block( $text ) {
		$pdf = $this->pdf;
		$pdf->set_font( '', 10 );
		$this->color( 'set_text_color', self::INK );

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $paragraph ) {
			if ( '' === trim( $paragraph ) ) {
				$this->y += 7; // blank line = paragraph gap.
				continue;
			}
			foreach ( $pdf->wrap( $paragraph, self::CONTENT_W ) as $line ) {
				$this->ensure_space( 14 );
				$pdf->text( self::MARGIN, $this->y, $line );
				$this->y += 14;
			}
		}
	}

	private function note_line( $text ) {
		$this->ensure_space( 14 );
		$pdf = $this->pdf;
		$pdf->set_font( 'I', 9 );
		$this->color( 'set_text_color', self::GRAY );
		$pdf->text( self::MARGIN, $this->y, $text );
		$this->y += 16;
	}

	private function body_line( $text, $style = '' ) {
		$this->ensure_space( 14 );
		$pdf = $this->pdf;
		$pdf->set_font( $style, 10 );
		$this->color( 'set_text_color', self::INK );
		foreach ( $pdf->wrap( $text, self::CONTENT_W ) as $line ) {
			$pdf->text( self::MARGIN, $this->y, $line );
			$this->y += 14;
			$this->ensure_space( 14 );
		}
		$this->y += 2;
	}

	/**
	 * Simple table with a filled header row, zebra striping, wrapped cells
	 * and page-break handling (header row repeats on a new page).
	 *
	 * @param array $headers Column headings.
	 * @param array $widths  Column widths in points (should sum to 504).
	 * @param array $rows    Array of row arrays (plain strings).
	 */
	private function table( $headers, $widths, $rows ) {
		$this->ensure_space( 40 );
		$this->table_header( $headers, $widths );

		$pdf  = $this->pdf;
		$size = 9.5;
		$lh   = 12.0;
		$pad  = 6.0;

		foreach ( $rows as $index => $row ) {
			$pdf->set_font( '', $size );

			// Wrap every cell, row height = tallest cell.
			$cells     = array();
			$max_lines = 1;
			foreach ( $row as $col => $value ) {
				$lines       = $pdf->wrap( (string) $value, $widths[ $col ] - 2 * $pad );
				$cells[]     = $lines;
				$max_lines   = max( $max_lines, count( $lines ) );
			}
			$row_h = $max_lines * $lh + 7;

			if ( $this->y + $row_h > self::BOTTOM ) {
				$pdf->add_page();
				$this->y = 60;
				$this->table_header( $headers, $widths );
				$pdf->set_font( '', $size );
			}

			if ( $index % 2 === 1 ) {
				$this->color( 'set_fill_color', self::ZEBRA );
				$pdf->rect( self::MARGIN, $this->y, self::CONTENT_W, $row_h );
			}

			$this->color( 'set_text_color', self::INK );
			$x = self::MARGIN;
			foreach ( $cells as $col => $lines ) {
				foreach ( $lines as $k => $line ) {
					$pdf->text( $x + $pad, $this->y + 13 + $k * $lh, $line );
				}
				$x += $widths[ $col ];
			}

			$this->y += $row_h;

			$this->color( 'set_draw_color', self::RULE );
			$pdf->set_line_width( 0.5 );
			$pdf->line( self::MARGIN, $this->y, self::MARGIN + self::CONTENT_W, $this->y );
		}
		$this->y += 6;
	}

	private function table_header( $headers, $widths ) {
		$pdf = $this->pdf;

		$this->color( 'set_fill_color', self::BAND );
		$pdf->rect( self::MARGIN, $this->y, self::CONTENT_W, 20 );
		$this->color( 'set_fill_color', self::TEAL );
		$pdf->rect( self::MARGIN, $this->y, self::CONTENT_W, 1.6 );

		$pdf->set_font( 'B', 9.5 );
		$this->color( 'set_text_color', self::INK );
		$x = self::MARGIN;
		foreach ( $headers as $col => $header ) {
			$pdf->text( $x + 6, $this->y + 14, $header );
			$x += $widths[ $col ];
		}
		$this->y += 20;
	}
}
