<?php
defined( 'ABSPATH' ) || exit;

/**
 * Code 128 barcode as inline SVG (sets B and C), readable by courier scanners.
 */
class CODM_Barcode {

	const PATTERNS = array( '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112' );

	/** Bar/space widths for a value, e.g. "212222". */
	private static function widths( $text ) {
		$text = (string) $text;
		if ( ctype_digit( $text ) && strlen( $text ) >= 4 && 0 === strlen( $text ) % 2 ) {
			$start  = 105; // Code C: pairs of digits.
			$values = array_map( 'intval', str_split( $text, 2 ) );
		} else {
			$start  = 104; // Code B: printable ASCII.
			$values = array();
			foreach ( str_split( $text ) as $char ) {
				$code = ord( $char );
				if ( $code >= 32 && $code <= 126 ) {
					$values[] = $code - 32;
				}
			}
		}
		$check = $start;
		foreach ( $values as $i => $v ) {
			$check += ( $i + 1 ) * $v;
		}
		$sequence = array_merge( array( $start ), $values, array( $check % 103, 106 ) );
		$out      = '';
		foreach ( $sequence as $s ) {
			$out .= self::PATTERNS[ $s ];
		}
		return $out;
	}

	/** Inline SVG. Width scales to its container; height in CSS units. */
	public static function svg( $text, $height = '14mm' ) {
		$widths = self::widths( $text );
		if ( '' === $widths ) {
			return '';
		}
		$quiet = 10;
		$x     = $quiet;
		$rects = '';
		$bar   = true;
		foreach ( str_split( $widths ) as $w ) {
			$w = (int) $w;
			if ( $bar ) {
				$rects .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="1"/>';
			}
			$x  += $w;
			$bar = ! $bar;
		}
		$total = $x + $quiet;
		// About 0.38 mm per module: proportional, and never wider than the label.
		$width = round( $total * 0.38, 1 );
		return '<svg class="codm-barcode" viewBox="0 0 ' . $total . ' 1" preserveAspectRatio="none" style="width:min(100%,' . $width . 'mm)" height="' . esc_attr( $height ) . '" shape-rendering="crispEdges" role="img" aria-label="' . esc_attr( $text ) . '"><g fill="#000">' . $rects . '</g></svg>';
	}
}
