<?php
/**
 * SCORR_MC_PDF — minimal, dependency-free PDF writer.
 *
 * Purpose-built for the SCORR maintenance report: US Letter pages, the
 * Helvetica core fonts (regular / bold / oblique, WinAnsi encoding),
 * RGB colors, lines, rectangles (plain + rounded), circles, wrapped text,
 * and baseline JPEG images. No external libraries, no composer.
 *
 * Coordinates are given from the TOP-LEFT of the page in points
 * (Letter = 612 x 792) and converted to PDF space internally.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'SCORR_MC_CLI_TEST' ) ) {
	exit;
}

class SCORR_MC_PDF {

	const PAGE_W = 612.0;
	const PAGE_H = 792.0;

	private $pages    = array(); // content stream per page.
	private $cur_page = -1;      // index of the page ops are written to.
	private $images   = array(); // file => array( 'i' => n, 'w' =>, 'h' =>, 'cs' =>, 'data' => ).
	private $title    = '';

	private $font       = 'F1';
	private $font_size  = 10.0;
	private $text_color = '0 0 0';
	private $fill_color = '0 0 0';
	private $draw_color = '0 0 0';
	private $line_width = 1.0;

	/** Helvetica / Helvetica-Bold glyph widths (per 1000 units, WinAnsi). */
	private static $cw = null;

	public function __construct( $title = '' ) {
		$this->title = $title;
		self::load_widths();
	}

	/* ------------------------------------------------------------------ */
	/* Pages                                                               */
	/* ------------------------------------------------------------------ */

	public function add_page() {
		$this->pages[]  = '';
		$this->cur_page = count( $this->pages ) - 1;
	}

	public function page_count() {
		return count( $this->pages );
	}

	/**
	 * Redirect drawing to an existing page (used to stamp footers with
	 * final page numbers after all content is laid out).
	 */
	public function select_page( $index ) {
		if ( isset( $this->pages[ $index ] ) ) {
			$this->cur_page = (int) $index;
		}
	}

	private function out( $s ) {
		$this->pages[ $this->cur_page ] .= $s . "\n";
	}

	private static function n( $v ) {
		return rtrim( rtrim( sprintf( '%.3F', $v ), '0' ), '.' );
	}

	private function yy( $y ) {
		return self::PAGE_H - $y;
	}

	/* ------------------------------------------------------------------ */
	/* State                                                               */
	/* ------------------------------------------------------------------ */

	public function set_font( $style = '', $size = 10.0 ) {
		$map             = array( '' => 'F1', 'B' => 'F2', 'I' => 'F3' );
		$this->font      = isset( $map[ $style ] ) ? $map[ $style ] : 'F1';
		$this->font_size = (float) $size;
	}

	private static function rgb( $r, $g, $b ) {
		return self::n( $r / 255 ) . ' ' . self::n( $g / 255 ) . ' ' . self::n( $b / 255 );
	}

	public function set_text_color( $r, $g, $b ) {
		$this->text_color = self::rgb( $r, $g, $b );
	}

	public function set_fill_color( $r, $g, $b ) {
		$this->fill_color = self::rgb( $r, $g, $b );
	}

	public function set_draw_color( $r, $g, $b ) {
		$this->draw_color = self::rgb( $r, $g, $b );
	}

	public function set_line_width( $w ) {
		$this->line_width = (float) $w;
	}

	/* ------------------------------------------------------------------ */
	/* Text                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Convert a UTF-8 string to WinAnsi (CP1252) for the core fonts.
	 */
	public static function enc( $s ) {
		$s = (string) $s;
		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( 'UTF-8', 'CP1252//TRANSLIT//IGNORE', $s );
			if ( false !== $out ) {
				return $out;
			}
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$out = @mb_convert_encoding( $s, 'Windows-1252', 'UTF-8' );
			if ( false !== $out && null !== $out ) {
				return $out;
			}
		}
		// Last resort: strip non-ASCII bytes.
		return preg_replace( '/[\x80-\xFF]/', '?', $s );
	}

	private static function esc( $s ) {
		return str_replace(
			array( '\\', '(', ')', "\r", "\n", "\t" ),
			array( '\\\\', '\\(', '\\)', ' ', ' ', ' ' ),
			$s
		);
	}

	/**
	 * Width of a UTF-8 string in points at the current font/size.
	 */
	public function string_width( $s ) {
		$s     = self::enc( $s );
		$table = ( 'F2' === $this->font ) ? self::$cw['B'] : self::$cw['R'];
		$w     = 0;
		$len   = strlen( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$o  = ord( $s[ $i ] );
			$w += isset( $table[ $o ] ) ? $table[ $o ] : 556;
		}
		return $w * $this->font_size / 1000;
	}

	/**
	 * Draw a single line of text; ($x, $y) is the BASELINE start point,
	 * measured from the top of the page.
	 */
	public function text( $x, $y, $s ) {
		$s = self::esc( self::enc( $s ) );
		$this->out(
			'BT ' . $this->text_color . ' rg /' . $this->font . ' ' . self::n( $this->font_size ) .
			' Tf ' . self::n( $x ) . ' ' . self::n( $this->yy( $y ) ) . ' Td (' . $s . ') Tj ET'
		);
	}

	public function text_right( $x_right, $y, $s ) {
		$this->text( $x_right - $this->string_width( $s ), $y, $s );
	}

	public function text_center( $cx, $y, $s ) {
		$this->text( $cx - $this->string_width( $s ) / 2, $y, $s );
	}

	/**
	 * Split a UTF-8 string into lines that fit $max_w points at the current
	 * font/size. Overlong single words (URLs) are broken by character.
	 */
	public function wrap( $s, $max_w ) {
		$s = trim( preg_replace( '/\s+/u', ' ', (string) $s ) );
		if ( '' === $s ) {
			return array( '' );
		}
		$words = explode( ' ', $s );
		$lines = array();
		$line  = '';
		foreach ( $words as $word ) {
			// Hard-break a word that alone exceeds the width.
			while ( $this->string_width( $word ) > $max_w ) {
				if ( '' !== $line ) {
					$lines[] = $line;
					$line    = '';
				}
				$cut = 1;
				$len = function_exists( 'mb_strlen' ) ? mb_strlen( $word ) : strlen( $word );
				for ( $i = 1; $i <= $len; $i++ ) {
					$piece = function_exists( 'mb_substr' ) ? mb_substr( $word, 0, $i ) : substr( $word, 0, $i );
					if ( $this->string_width( $piece ) > $max_w ) {
						break;
					}
					$cut = $i;
				}
				$lines[] = function_exists( 'mb_substr' ) ? mb_substr( $word, 0, $cut ) : substr( $word, 0, $cut );
				$word    = function_exists( 'mb_substr' ) ? mb_substr( $word, $cut ) : substr( $word, $cut );
			}
			$try = ( '' === $line ) ? $word : $line . ' ' . $word;
			if ( $this->string_width( $try ) <= $max_w ) {
				$line = $try;
			} else {
				if ( '' !== $line ) {
					$lines[] = $line;
				}
				$line = $word;
			}
		}
		if ( '' !== $line ) {
			$lines[] = $line;
		}
		return $lines;
	}

	/* ------------------------------------------------------------------ */
	/* Shapes                                                              */
	/* ------------------------------------------------------------------ */

	public function line( $x1, $y1, $x2, $y2 ) {
		$this->out(
			$this->draw_color . ' RG ' . self::n( $this->line_width ) . ' w ' .
			self::n( $x1 ) . ' ' . self::n( $this->yy( $y1 ) ) . ' m ' .
			self::n( $x2 ) . ' ' . self::n( $this->yy( $y2 ) ) . ' l S'
		);
	}

	/**
	 * Rectangle from its top-left corner. $mode: 'F' fill, 'D' stroke, 'FD' both.
	 */
	public function rect( $x, $y, $w, $h, $mode = 'F' ) {
		$op = ( 'F' === $mode ) ? 'f' : ( ( 'D' === $mode ) ? 'S' : 'B' );
		$this->out(
			$this->fill_color . ' rg ' . $this->draw_color . ' RG ' . self::n( $this->line_width ) . ' w ' .
			self::n( $x ) . ' ' . self::n( $this->yy( $y ) - $h ) . ' ' . self::n( $w ) . ' ' . self::n( $h ) . ' re ' . $op
		);
	}

	/**
	 * Rounded-corner rectangle from its top-left corner.
	 */
	public function round_rect( $x, $y, $w, $h, $r, $mode = 'F' ) {
		$k  = 0.5522847498;
		$yb = $this->yy( $y ) - $h; // bottom in PDF space.
		$yt = $this->yy( $y );      // top in PDF space.
		$op = ( 'F' === $mode ) ? 'f' : ( ( 'D' === $mode ) ? 'S' : 'B' );
		$p  = $this->fill_color . ' rg ' . $this->draw_color . ' RG ' . self::n( $this->line_width ) . ' w ';
		$p .= self::n( $x + $r ) . ' ' . self::n( $yb ) . ' m ';
		$p .= self::n( $x + $w - $r ) . ' ' . self::n( $yb ) . ' l ';
		$p .= self::n( $x + $w - $r + $k * $r ) . ' ' . self::n( $yb ) . ' ' . self::n( $x + $w ) . ' ' . self::n( $yb + $r - $k * $r ) . ' ' . self::n( $x + $w ) . ' ' . self::n( $yb + $r ) . ' c ';
		$p .= self::n( $x + $w ) . ' ' . self::n( $yt - $r ) . ' l ';
		$p .= self::n( $x + $w ) . ' ' . self::n( $yt - $r + $k * $r ) . ' ' . self::n( $x + $w - $r + $k * $r ) . ' ' . self::n( $yt ) . ' ' . self::n( $x + $w - $r ) . ' ' . self::n( $yt ) . ' c ';
		$p .= self::n( $x + $r ) . ' ' . self::n( $yt ) . ' l ';
		$p .= self::n( $x + $r - $k * $r ) . ' ' . self::n( $yt ) . ' ' . self::n( $x ) . ' ' . self::n( $yt - $r + $k * $r ) . ' ' . self::n( $x ) . ' ' . self::n( $yt - $r ) . ' c ';
		$p .= self::n( $x ) . ' ' . self::n( $yb + $r ) . ' l ';
		$p .= self::n( $x ) . ' ' . self::n( $yb + $r - $k * $r ) . ' ' . self::n( $x + $r - $k * $r ) . ' ' . self::n( $yb ) . ' ' . self::n( $x + $r ) . ' ' . self::n( $yb ) . ' c ';
		$this->out( $p . 'h ' . $op );
	}

	/**
	 * Filled circle centered on ($cx, $cy), top-based coordinates.
	 */
	public function circle( $cx, $cy, $r ) {
		$k  = 0.5522847498 * $r;
		$cy = $this->yy( $cy );
		$p  = $this->fill_color . ' rg ';
		$p .= self::n( $cx + $r ) . ' ' . self::n( $cy ) . ' m ';
		$p .= self::n( $cx + $r ) . ' ' . self::n( $cy + $k ) . ' ' . self::n( $cx + $k ) . ' ' . self::n( $cy + $r ) . ' ' . self::n( $cx ) . ' ' . self::n( $cy + $r ) . ' c ';
		$p .= self::n( $cx - $k ) . ' ' . self::n( $cy + $r ) . ' ' . self::n( $cx - $r ) . ' ' . self::n( $cy + $k ) . ' ' . self::n( $cx - $r ) . ' ' . self::n( $cy ) . ' c ';
		$p .= self::n( $cx - $r ) . ' ' . self::n( $cy - $k ) . ' ' . self::n( $cx - $k ) . ' ' . self::n( $cy - $r ) . ' ' . self::n( $cx ) . ' ' . self::n( $cy - $r ) . ' c ';
		$p .= self::n( $cx + $k ) . ' ' . self::n( $cy - $r ) . ' ' . self::n( $cx + $r ) . ' ' . self::n( $cy - $k ) . ' ' . self::n( $cx + $r ) . ' ' . self::n( $cy ) . ' c ';
		$this->out( $p . 'f' );
	}

	/**
	 * SCORR bullseye mark drawn with concentric circles (ring ratios taken
	 * from the brand SVG). ($cx, $cy) center, $r outer radius.
	 */
	public function bullseye( $cx, $cy, $r ) {
		$this->set_fill_color( 167, 25, 48 );   // red outer ring (#a71930).
		$this->circle( $cx, $cy, $r );
		$this->set_fill_color( 255, 255, 255 );
		$this->circle( $cx, $cy, $r * 0.733 );
		$this->set_fill_color( 0, 151, 169 );   // teal ring (#0097a9).
		$this->circle( $cx, $cy, $r * 0.512 );
		$this->set_fill_color( 255, 255, 255 );
		$this->circle( $cx, $cy, $r * 0.269 );
		$this->set_fill_color( 35, 31, 32 );    // black center.
		$this->circle( $cx, $cy, $r * 0.146 );
	}

	/* ------------------------------------------------------------------ */
	/* Images (baseline JPEG)                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Place a JPEG with its top-left corner at ($x, $y). Pass one of $w/$h
	 * as 0 to preserve the aspect ratio. Returns false if unusable.
	 */
	public function image( $file, $x, $y, $w = 0, $h = 0 ) {
		if ( ! isset( $this->images[ $file ] ) ) {
			if ( ! is_readable( $file ) ) {
				return false;
			}
			$info = @getimagesize( $file );
			if ( ! $info || IMAGETYPE_JPEG !== $info[2] ) {
				return false;
			}
			$cs = 'DeviceRGB';
			if ( isset( $info['channels'] ) && 4 === (int) $info['channels'] ) {
				$cs = 'DeviceCMYK';
			} elseif ( isset( $info['channels'] ) && 1 === (int) $info['channels'] ) {
				$cs = 'DeviceGray';
			}
			$this->images[ $file ] = array(
				'i'    => count( $this->images ) + 1,
				'w'    => (int) $info[0],
				'h'    => (int) $info[1],
				'cs'   => $cs,
				'data' => file_get_contents( $file ),
			);
		}
		$img = $this->images[ $file ];
		if ( $w <= 0 && $h <= 0 ) {
			$w = $img['w'] * 0.75;
			$h = $img['h'] * 0.75;
		} elseif ( $w <= 0 ) {
			$w = $h * $img['w'] / $img['h'];
		} elseif ( $h <= 0 ) {
			$h = $w * $img['h'] / $img['w'];
		}
		$this->out(
			'q ' . self::n( $w ) . ' 0 0 ' . self::n( $h ) . ' ' .
			self::n( $x ) . ' ' . self::n( $this->yy( $y ) - $h ) . ' cm /I' . $img['i'] . ' Do Q'
		);
		return array( 'w' => $w, 'h' => $h );
	}

	/**
	 * Natural pixel size of a registered/readable JPEG, or false.
	 */
	public static function jpeg_size( $file ) {
		if ( ! is_readable( $file ) ) {
			return false;
		}
		$info = @getimagesize( $file );
		if ( ! $info || IMAGETYPE_JPEG !== $info[2] ) {
			return false;
		}
		return array( 'w' => (int) $info[0], 'h' => (int) $info[1] );
	}

	/* ------------------------------------------------------------------ */
	/* Output                                                              */
	/* ------------------------------------------------------------------ */

	public function output() {
		$objects = array(); // 1-based object bodies (without "n 0 obj").

		// 1: Catalog, 2: Pages (placeholder, filled after pages known).
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[2] = '';

		// 3-5: core fonts.
		foreach ( array( 3 => 'Helvetica', 4 => 'Helvetica-Bold', 5 => 'Helvetica-Oblique' ) as $num => $base ) {
			$objects[ $num ] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $base . ' /Encoding /WinAnsiEncoding >>';
		}

		// Images.
		$img_refs = '';
		$next     = 6;
		foreach ( $this->images as $img ) {
			$objects[ $next ] = array(
				'dict'   => '<< /Type /XObject /Subtype /Image /Width ' . $img['w'] . ' /Height ' . $img['h'] .
					' /ColorSpace /' . $img['cs'] . ( 'DeviceCMYK' === $img['cs'] ? ' /Decode [1 0 1 0 1 0 1 0]' : '' ) .
					' /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $img['data'] ) . ' >>',
				'stream' => $img['data'],
			);
			$img_refs .= '/I' . $img['i'] . ' ' . $next . ' 0 R ';
			$next++;
		}

		$resources = '<< /ProcSet [/PDF /Text /ImageC /ImageG] /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >>' .
			( $img_refs ? ' /XObject << ' . $img_refs . '>>' : '' ) . ' >>';

		// Pages: content stream + page object per page.
		$page_refs = '';
		foreach ( $this->pages as $content ) {
			$use_flate = function_exists( 'gzcompress' );
			$stream    = $use_flate ? gzcompress( $content ) : $content;

			$objects[ $next ] = array(
				'dict'   => '<< ' . ( $use_flate ? '/Filter /FlateDecode ' : '' ) . '/Length ' . strlen( $stream ) . ' >>',
				'stream' => $stream,
			);
			$content_num = $next;
			$next++;

			$objects[ $next ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::n( self::PAGE_W ) . ' ' . self::n( self::PAGE_H ) . ']' .
				' /Resources ' . $resources . ' /Contents ' . $content_num . ' 0 R >>';
			$page_refs       .= $next . ' 0 R ';
			$next++;
		}

		$objects[2] = '<< /Type /Pages /Kids [' . trim( $page_refs ) . '] /Count ' . count( $this->pages ) . ' >>';

		// Info.
		$objects[ $next ] = '<< /Title (' . self::esc( self::enc( $this->title ) ) . ') /Producer (SCORR Maintenance Check) >>';
		$info_num         = $next;

		// Assemble.
		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		$count   = count( $objects );
		for ( $i = 1; $i <= $count; $i++ ) {
			$offsets[ $i ] = strlen( $pdf );
			$body          = $objects[ $i ];
			if ( is_array( $body ) ) {
				$pdf .= $i . " 0 obj\n" . $body['dict'] . "\nstream\n" . $body['stream'] . "\nendstream\nendobj\n";
			} else {
				$pdf .= $i . " 0 obj\n" . $body . "\nendobj\n";
			}
		}

		$xref_pos = strlen( $pdf );
		$pdf     .= "xref\n0 " . ( $count + 1 ) . "\n0000000000 65535 f \n";
		for ( $i = 1; $i <= $count; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}
		$pdf .= "trailer\n<< /Size " . ( $count + 1 ) . ' /Root 1 0 R /Info ' . $info_num . " 0 R >>\nstartxref\n" . $xref_pos . "\n%%EOF";

		return $pdf;
	}

	/* ------------------------------------------------------------------ */
	/* Font metrics                                                        */
	/* ------------------------------------------------------------------ */

	private static function load_widths() {
		if ( null !== self::$cw ) {
			return;
		}

		// Helvetica (Adobe AFM widths), ASCII 32-126.
		$r = array(
			32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191,
			40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
			58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556, 64 => 1015,
			65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722,
			73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778, 80 => 667,
			81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944, 88 => 667,
			89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556, 96 => 333,
			97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556, 104 => 556,
			105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556, 111 => 556, 112 => 556,
			113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722, 120 => 500,
			121 => 500, 122 => 500, 123 => 334, 124 => 260, 125 => 334, 126 => 584,
			// Common WinAnsi extras.
			149 => 350, 150 => 556, 151 => 1000, 169 => 737, 171 => 556, 187 => 556, 176 => 400,
		);
		for ( $i = 48; $i <= 57; $i++ ) {
			$r[ $i ] = 556; // digits.
		}

		$b = array(
			32 => 278, 33 => 333, 34 => 474, 35 => 556, 36 => 556, 37 => 889, 38 => 722, 39 => 238,
			40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
			58 => 333, 59 => 333, 60 => 584, 61 => 584, 62 => 584, 63 => 611, 64 => 975,
			65 => 722, 66 => 722, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722,
			73 => 278, 74 => 556, 75 => 722, 76 => 611, 77 => 833, 78 => 722, 79 => 778, 80 => 667,
			81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944, 88 => 667,
			89 => 667, 90 => 611, 91 => 333, 92 => 278, 93 => 333, 94 => 584, 95 => 556, 96 => 333,
			97 => 556, 98 => 611, 99 => 556, 100 => 611, 101 => 556, 102 => 333, 103 => 611, 104 => 611,
			105 => 278, 106 => 278, 107 => 556, 108 => 278, 109 => 889, 110 => 611, 111 => 611, 112 => 611,
			113 => 611, 114 => 389, 115 => 556, 116 => 333, 117 => 611, 118 => 556, 119 => 778, 120 => 556,
			121 => 556, 122 => 500, 123 => 389, 124 => 280, 125 => 389, 126 => 584,
			149 => 350, 150 => 556, 151 => 1000, 169 => 737, 171 => 556, 187 => 556, 176 => 400,
		);
		for ( $i = 48; $i <= 57; $i++ ) {
			$b[ $i ] = 556;
		}

		self::$cw = array( 'R' => $r, 'B' => $b );
	}
}
