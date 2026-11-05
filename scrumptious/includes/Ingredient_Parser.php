<?php
/**
 * Parses a free-text ingredient line into amount / unit / name / notes.
 *
 * Pure PHP (no WordPress dependencies) so it can be unit-tested and reused
 * by importers and the AI fallback path.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Splits ingredient lines into structured parts.
 */
final class Ingredient_Parser {

	/**
	 * Unicode vulgar fractions => decimal value.
	 *
	 * @var array<string,float>
	 */
	const UNICODE_FRACTIONS = array(
		'½' => 0.5,
		'⅓' => 0.3333,
		'⅔' => 0.6667,
		'¼' => 0.25,
		'¾' => 0.75,
		'⅕' => 0.2,
		'⅖' => 0.4,
		'⅗' => 0.6,
		'⅘' => 0.8,
		'⅙' => 0.1667,
		'⅚' => 0.8333,
		'⅛' => 0.125,
		'⅜' => 0.375,
		'⅝' => 0.625,
		'⅞' => 0.875,
	);

	/**
	 * Canonical unit => accepted spellings. Case-sensitive entries (T / t)
	 * are matched exactly; everything else case-insensitively.
	 *
	 * @var array<string,string[]>
	 */
	const UNITS = array(
		'cup'     => array( 'cups', 'cup', 'c.', 'c' ),
		'tbsp'    => array( 'tablespoons', 'tablespoon', 'tbsps', 'tbsp.', 'tbsp', 'tbs.', 'tbs', 'tbl', 'T' ),
		'tsp'     => array( 'teaspoons', 'teaspoon', 'tsps', 'tsp.', 'tsp', 't' ),
		'fl oz'   => array( 'fluid ounces', 'fluid ounce', 'fl. oz.', 'fl. oz', 'fl oz' ),
		'oz'      => array( 'ounces', 'ounce', 'oz.', 'oz' ),
		'lb'      => array( 'pounds', 'pound', 'lbs.', 'lbs', 'lb.', 'lb' ),
		'g'       => array( 'grams', 'gram', 'gr', 'g' ),
		'kg'      => array( 'kilograms', 'kilogram', 'kgs', 'kg' ),
		'mg'      => array( 'milligrams', 'milligram', 'mg' ),
		'ml'      => array( 'milliliters', 'millilitres', 'milliliter', 'millilitre', 'mls', 'ml' ),
		'l'       => array( 'liters', 'litres', 'liter', 'litre', 'l' ),
		'qt'      => array( 'quarts', 'quart', 'qts', 'qt.', 'qt' ),
		'pt'      => array( 'pints', 'pint', 'pts', 'pt.', 'pt' ),
		'gal'     => array( 'gallons', 'gallon', 'gal.', 'gal' ),
		'pinch'   => array( 'pinches', 'pinch' ),
		'dash'    => array( 'dashes', 'dash' ),
		'clove'   => array( 'cloves', 'clove' ),
		'can'     => array( 'cans', 'can' ),
		'jar'     => array( 'jars', 'jar' ),
		'pkg'     => array( 'packages', 'package', 'packets', 'packet', 'pkgs', 'pkg.', 'pkg' ),
		'stick'   => array( 'sticks', 'stick' ),
		'slice'   => array( 'slices', 'slice' ),
		'sprig'   => array( 'sprigs', 'sprig' ),
		'bunch'   => array( 'bunches', 'bunch' ),
		'head'    => array( 'heads', 'head' ),
		'handful' => array( 'handfuls', 'handful' ),
		'piece'   => array( 'pieces', 'piece', 'pcs', 'pc' ),
	);

	/**
	 * Parse one ingredient line.
	 *
	 * @param string $line Raw text, e.g. "1 1/2 cups all-purpose flour, sifted".
	 * @return array{raw:string,amount:string,amount_value:?float,amount_max:?float,unit:string,unit_key:string,name:string,notes:string}
	 */
	public static function parse( $line ) {
		$raw  = trim( preg_replace( '/\s+/u', ' ', (string) $line ) );
		$rest = $raw;

		$result = array(
			'raw'          => $raw,
			'amount'       => '',
			'amount_value' => null,
			'amount_max'   => null,
			'unit'         => '',
			'unit_key'     => '',
			'name'         => '',
			'notes'        => '',
		);

		if ( '' === $raw ) {
			return $result;
		}

		// Strip leading bullets / list markers.
		$rest = preg_replace( '/^(?:[-*•·▪◦]+|\d+[.)](?=\s))\s*/u', '', $rest );

		// Amount.
		$amount = self::extract_amount( $rest );
		if ( null !== $amount ) {
			$result['amount']       = $amount['text'];
			$result['amount_value'] = $amount['value'];
			$result['amount_max']   = $amount['max'];
			$rest                   = ltrim( $amount['rest'] );
		}

		// Optional package size right after the amount: "1 (14 oz) can".
		$size = '';
		if ( null !== $amount && preg_match( '/^(\([^)]*\))\s*/u', $rest, $m ) ) {
			$size = $m[1];
			$rest = substr( $rest, strlen( $m[0] ) );
		}

		// Unit (only when an amount was found, or the line starts with a unit word like "pinch").
		$unit = self::extract_unit( $rest, null !== $amount );
		if ( null !== $unit ) {
			$result['unit']     = trim( ( '' !== $size ? $size . ' ' : '' ) . $unit['unit'] );
			$result['unit_key'] = $unit['key'];
			$rest               = ltrim( $unit['rest'] );
			$rest               = preg_replace( '/^of\s+/i', '', $rest );
		} elseif ( '' !== $size ) {
			$result['notes'] = trim( $size, '()' );
		}

		// Notes: text after the first comma, or a trailing parenthetical.
		$notes = '';
		if ( preg_match( '/^(.*?),\s*(.+)$/u', $rest, $m ) ) {
			$rest  = $m[1];
			$notes = $m[2];
		}
		// Trailing bracketed notes, including nested or repeated ones like "((~250g each) (Note 1))".
		if ( preg_match( '/^(.*?)\s*((?:(?<p>\((?:[^()]++|(?&p))*\))\s*)+)$/u', $rest, $m ) && '' !== trim( $m[1] ) ) {
			$rest  = $m[1];
			$notes = trim( self::bracket_notes( $m[2] ) . ( '' !== $notes ? ', ' . $notes : '' ) );
		}

		$result['name']  = trim( $rest );
		$result['notes'] = trim( ( '' !== $result['notes'] ? $result['notes'] . ', ' : '' ) . $notes );

		return $result;
	}

	/**
	 * Turn one or more bracketed groups into plain notes: "((~250g each) (Note 1))" → "~250g each, Note 1".
	 * A group that only wraps other groups is unwrapped; brackets inside ordinary text are kept.
	 *
	 * @param string $text Bracketed text.
	 * @return string
	 */
	public static function bracket_notes( $text ) {
		$parts = array();
		$depth = 0;
		$start = 0;
		$len   = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( '(' === $text[ $i ] ) {
				if ( 0 === $depth ) {
					$start = $i + 1;
				}
				++$depth;
			} elseif ( ')' === $text[ $i ] && $depth > 0 ) {
				--$depth;
				if ( 0 === $depth ) {
					$inner = trim( substr( $text, $start, $i - $start ) );
					if ( '' !== $inner && preg_match( '/^(?:(?<p>\((?:[^()]++|(?&p))*\))\s*)+$/u', $inner ) ) {
						$inner = self::bracket_notes( $inner );
					}
					if ( '' !== $inner ) {
						$parts[] = $inner;
					}
				}
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Pull a leading amount (int, decimal, fraction, mixed number, unicode fraction, range).
	 *
	 * @param string $text Text.
	 * @return array{text:string,value:float,max:?float,rest:string}|null
	 */
	private static function extract_amount( $text ) {
		$uf     = implode( '', array_keys( self::UNICODE_FRACTIONS ) );
		$single = '(?:\d+\s*[' . $uf . ']|\d+\s+\d+\s*\/\s*\d+|\d+\s*\/\s*\d+|\d+(?:[.,]\d+)?|[' . $uf . '])';
		$regex  = '/^(' . $single . ')(?:\s*(?:-|–|—|to)\s*(' . $single . '))?(?=\s|$|[a-zA-Z(])/u';

		if ( ! preg_match( $regex, $text, $m ) ) {
			return null;
		}

		$value = self::to_number( $m[1] );
		$max   = isset( $m[2] ) && '' !== $m[2] ? self::to_number( $m[2] ) : null;

		return array(
			'text'  => trim( $m[0] ),
			'value' => $value,
			'max'   => $max,
			'rest'  => substr( $text, strlen( $m[0] ) ),
		);
	}

	/**
	 * Convert an amount token to a float.
	 *
	 * @param string $token Token.
	 * @return float
	 */
	public static function to_number( $token ) {
		$token = trim( str_replace( ',', '.', $token ) );
		$total = 0.0;

		foreach ( self::UNICODE_FRACTIONS as $char => $val ) {
			if ( false !== strpos( $token, $char ) ) {
				$total += $val;
				$token  = trim( str_replace( $char, '', $token ) );
			}
		}

		if ( preg_match( '/^(\d+)\s+(\d+)\s*\/\s*(\d+)$/', $token, $m ) ) {
			return $total + (float) $m[1] + ( (int) $m[3] ? (float) $m[2] / (float) $m[3] : 0 );
		}
		if ( preg_match( '/^(\d+)\s*\/\s*(\d+)$/', $token, $m ) ) {
			return $total + ( (int) $m[2] ? (float) $m[1] / (float) $m[2] : 0 );
		}
		if ( '' !== $token && is_numeric( $token ) ) {
			$total += (float) $token;
		}

		return round( $total, 4 );
	}

	/**
	 * Pull a leading unit word.
	 *
	 * @param string $text       Text after the amount.
	 * @param bool   $had_amount Whether an amount preceded it.
	 * @return array{unit:string,key:string,rest:string}|null
	 */
	private static function extract_unit( $text, $had_amount ) {
		// Single letters (c, t, T, g, l) are only units right after an amount.
		foreach ( self::UNITS as $canonical => $spellings ) {
			foreach ( $spellings as $spelling ) {
				$is_short = strlen( rtrim( $spelling, '.' ) ) <= 1;
				if ( $is_short && ! $had_amount ) {
					continue;
				}
				$case    = in_array( $spelling, array( 'T', 't' ), true ) ? '' : 'i';
				$pattern = '/^' . preg_quote( $spelling, '/' ) . '(?=\s|$|[.,)])\.?/' . $case . 'u';
				if ( preg_match( $pattern, $text, $m ) ) {
					return array(
						'unit' => rtrim( $m[0] ),
						'key'  => $canonical,
						'rest' => substr( $text, strlen( $m[0] ) ),
					);
				}
			}
		}
		return null;
	}
}
