<?php
/**
 * Paste & Parse without AI: turns pasted recipe text into recipe data.
 *
 * Handles the common shape of recipes copied from websites, documents and emails:
 * a title, a few description lines, "Prep time / Servings" lines, an Ingredients
 * section (with optional "For the frosting:" groups), an Instructions section and Notes.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Rule-based recipe text parser. Pure functions: no WordPress calls besides sanitizing.
 */
final class Recipe_Parser {

	const MAX_CHARS = 30000;

	/**
	 * Section headings (matched against a whole, lower-cased line without a trailing colon).
	 */
	const HEADINGS = array(
		'ingredients'  => '/^(ingredients?|ingredient list|what you\'?ll need|you\'?ll need|you will need|shopping list)(\s*\(.{0,40}\))?$/',
		'instructions' => '/^(instructions?|directions?|method|steps|preparation|how to make( it)?|to make)(\s*\(.{0,40}\))?$/',
		'notes'        => '/^(notes?|recipe notes|tips|tips (and|&) notes|cook\'?s notes|chef\'?s notes|storage|make ahead)$/',
		'nutrition'    => '/^(nutrition|nutrition facts|nutrition information|nutritional information)( per serving)?(\s*\(.{0,30}\))?$/',
	);

	/**
	 * Nutrition labels → field.
	 */
	const NUTRITION = array(
		'serving size'    => 'serving_size',
		'calories'        => 'calories',
		'carbohydrates'   => 'carbohydrates',
		'carbs'           => 'carbohydrates',
		'protein'         => 'protein',
		'saturated fat'   => 'saturated_fat',
		'trans fat'       => 'trans_fat',
		'unsaturated fat' => 'unsaturated_fat',
		'fat'             => 'fat',
		'cholesterol'     => 'cholesterol',
		'sodium'          => 'sodium',
		'fiber'           => 'fiber',
		'fibre'           => 'fiber',
		'sugar'           => 'sugar',
		'sugars'          => 'sugar',
	);

	/**
	 * Parse pasted text.
	 *
	 * @param string $text Pasted text (plain text or simple HTML).
	 * @return array{title:string,recipe:array<string,mixed>}
	 */
	public static function parse( $text ) {
		$lines = self::lines( $text );

		$title     = '';
		$summary   = array();
		$notes     = array();
		$times     = array(
			'prep'  => 0,
			'cook'  => 0,
			'rest'  => 0,
			'total' => 0,
		);
		$servings  = 0;
		$unit      = '';
		$nutrition = array();
		$ing       = array();
		$steps     = array();
		$section   = 'head';
		$has_heads = false;

		foreach ( $lines as $line ) {
			if ( null !== self::heading( $line ) ) {
				$has_heads = true;
				break;
			}
		}

		foreach ( $lines as $line ) {
			$heading = self::heading( $line );
			if ( null !== $heading ) {
				$section = $heading;
				continue;
			}

			// Times and servings, anywhere before the ingredients or on their own line.
			// Outside the header only labelled lines count ("Cook time: 10 min"), so a step
			// like "Cook 5 minutes until soft" stays a step.
			$meta = self::meta( $line );
			if ( $meta && ( 'head' === $section || ( $meta['whole'] && preg_match( '/^[a-z ]{3,24}:/i', $line ) ) ) ) {
				foreach ( $meta['times'] as $k => $v ) {
					$times[ $k ] = $v;
				}
				if ( $meta['servings'] ) {
					$servings = $meta['servings'];
					$unit     = '' !== $meta['unit'] ? $meta['unit'] : $unit;
				}
				if ( $meta['whole'] ) {
					continue;
				}
			}

			switch ( $section ) {
				case 'head':
					if ( '' === $title && mb_strlen( $line ) <= 120 ) {
						$title = rtrim( $line, ':' );
					} elseif ( ! $has_heads ) {
						// No headings at all: sort lines by what they look like.
						if ( self::looks_like_ingredient( $line ) ) {
							self::add_item( $ing, $line );
						} elseif ( mb_strlen( $line ) > 25 || $steps ) {
							self::add_step( $steps, $line );
						} else {
							$summary[] = $line;
						}
					} else {
						$summary[] = $line;
					}
					break;
				case 'ingredients':
					self::add_item( $ing, $line );
					break;
				case 'instructions':
					self::add_step( $steps, $line );
					break;
				case 'notes':
					$notes[] = self::strip_bullet( $line );
					break;
				case 'nutrition':
					$nutrition = array_merge( $nutrition, self::nutrition( $line ) );
					break;
			}
		}

		return array(
			'title'  => $title,
			'recipe' => array(
				'summary'            => implode( ' ', $summary ),
				'servings'           => $servings,
				'servings_unit'      => $unit,
				'times'              => $times,
				'ingredient_groups'  => $ing ? $ing : array(
					array(
						'title' => '',
						'items' => array(),
					),
				),
				'instruction_groups' => $steps ? $steps : array(
					array(
						'title' => '',
						'steps' => array(),
					),
				),
				'notes'              => implode( "\n\n", $notes ),
				'nutrition'          => $nutrition,
			),
		);
	}

	/**
	 * Clean, non-empty lines.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function lines( $text ) {
		$text = mb_substr( (string) $text, 0, self::MAX_CHARS );
		// Keep block-level HTML as line breaks, then drop tags.
		$text = preg_replace( '#<\s*(br|/p|/li|/h\d|/div|/tr)[^>]*>#i', "\n", $text );
		$text = html_entity_decode( wp_strip_all_tags( $text, false ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\r\n", "\r", "\u{00A0}", "\t" ), array( "\n", "\n", ' ', ' ' ), $text );
		$out  = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$line = trim( preg_replace( '/\s+/u', ' ', $line ) );
			if ( '' !== $line && ! preg_match( '/^(jump to recipe|print( recipe)?|pin( recipe)?|save( recipe)?|rate this recipe|cook mode.*|\d+ (reviews?|ratings?|comments?))$/i', $line ) ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Section a heading line starts, or null.
	 *
	 * @param string $line Line.
	 * @return string|null
	 */
	public static function heading( $line ) {
		$l = strtolower( trim( preg_replace( '/^#+\s*|[:：]\s*$/u', '', $line ) ) );
		$l = str_replace( '’', '\'', $l );
		if ( mb_strlen( $l ) > 60 ) {
			return null;
		}
		foreach ( self::HEADINGS as $section => $re ) {
			if ( preg_match( $re, $l ) ) {
				return $section;
			}
		}
		return null;
	}

	/**
	 * Times and servings found in a line.
	 *
	 * @param string $line Line.
	 * @return array{times:array<string,int>,servings:float,unit:string,whole:bool}|null
	 */
	public static function meta( $line ) {
		$times = array();
		$found = array();
		if ( preg_match_all( '/\b(prep(?:aration)?|cook(?:ing)?|bake|baking|total|rest(?:ing)?|chill(?:ing)?|inactive|marinat(?:e|ing))\s*(?:time)?\s*[:\-–]?\s*((?:\d+(?:[.,]\d+)?\s*(?:days?|d|hours?|hrs?|h|minutes?|mins?|m)\b\.?\s*(?:and\s+|,\s*)?)+)/i', $line, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$key = strtolower( $match[1] );
				if ( 0 === strpos( $key, 'prep' ) ) {
					$key = 'prep';
				} elseif ( 0 === strpos( $key, 'cook' ) || 0 === strpos( $key, 'bak' ) ) {
					$key = 'cook';
				} elseif ( 'total' !== $key ) {
					$key = 'rest';
				}
				$times[ $key ] = self::minutes( $match[2] );
				$found[]       = $match[0];
			}
		}

		$servings = 0;
		$unit     = '';
		if ( preg_match( '/\b(servings?|serves|yield|yields|makes)\s*[:\-–]?\s*(?:about\s+)?(\d+(?:[.,]\d+)?)(?:\s*(?:-|–|to)\s*\d+)?\s*([a-z][a-z\- ]{0,28})?/i', $line, $sm ) ) {
			$servings = (float) str_replace( ',', '.', $sm[2] );
			$unit     = isset( $sm[3] ) ? trim( preg_replace( '/\b(people|persons?|servings?|portions?)\b/i', '', $sm[3] ) ) : '';
			// Stop at words that start another field ("24 cookies prep time 10 min").
			$unit = trim( preg_replace( '/\s+(prep|cook|total|calories|course|cuisine|author)\b.*$/i', '', $unit ) );
			// "16 XL cookies or 24 regular" becomes "XL cookies".
			$unit    = trim( preg_replace( '/\s+(or|and|to|plus)(\s.*)?$/i', '', $unit ) );
			$found[] = $sm[0];
		}

		if ( ! $times && ! $servings ) {
			return null;
		}
		// The whole line is metadata when little else is left over.
		$rest  = preg_replace( '/[\s|•·,;:\-–]+/u', '', str_ireplace( array_merge( $found, array( 'time', 'minutes', 'mins' ) ), '', $line ) );
		$whole = mb_strlen( $rest ) <= 12 || mb_strlen( implode( '', $found ) ) >= mb_strlen( $line ) * 0.6;

		return array(
			'times'    => $times,
			'servings' => $servings,
			'unit'     => $unit,
			'whole'    => $whole,
		);
	}

	/**
	 * "1 hour 30 minutes" / "1h 30m" / "45 mins" → minutes.
	 *
	 * @param string $text Duration text.
	 * @return int
	 */
	public static function minutes( $text ) {
		$total = 0.0;
		if ( preg_match_all( '/(\d+(?:[.,]\d+)?)\s*(days?|d|hours?|hrs?|h|minutes?|mins?|m)\b/i', (string) $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $part ) {
				$n = (float) str_replace( ',', '.', $part[1] );
				$u = strtolower( $part[2] );
				if ( 'd' === $u[0] ) {
					$total += $n * 1440;
				} elseif ( 'h' === $u[0] ) {
					$total += $n * 60;
				} else {
					$total += $n;
				}
			}
		}
		return (int) round( $total );
	}

	/**
	 * Group title like "For the frosting:" / "Sauce:" (not an ingredient or step).
	 *
	 * @param string $line Line.
	 * @return string|null Title without the colon.
	 */
	public static function group_title( $line ) {
		$line = self::strip_bullet( $line );
		if ( mb_strlen( $line ) > 60 || preg_match( '/^\d/', $line ) ) {
			return null;
		}
		if ( preg_match( '/^(.+?)\s*[:：]$/u', $line, $m ) && ! preg_match( '/[.!?]/', $m[1] ) ) {
			return $m[1];
		}
		if ( preg_match( '/^for the\s+[\w\s&\'’-]+$/iu', $line ) ) {
			return $line;
		}
		return null;
	}

	/**
	 * Remove list bullets, checkboxes and "Step 1:" prefixes.
	 *
	 * @param string $line Line.
	 * @return string
	 */
	public static function strip_bullet( $line ) {
		return trim( (string) preg_replace( '/^[\-\*•·▢□☐◦‣⁃▪■✓✔\s]+/u', '', $line ) );
	}

	/**
	 * Whether a line reads like an ingredient ("2 cups flour", "1/2 tsp salt").
	 *
	 * @param string $line Line.
	 * @return bool
	 */
	public static function looks_like_ingredient( $line ) {
		$line = self::strip_bullet( $line );
		if ( mb_strlen( $line ) > 90 || preg_match( '/[.!?]\s+\S/', $line ) ) {
			return false;
		}
		return (bool) preg_match( '/^(\d|[½⅓⅔¼¾⅛⅜⅝⅞]|a pinch|pinch|a handful|handful|dash|a dash)/iu', $line );
	}

	/**
	 * Add an ingredient line (or start a new group).
	 *
	 * @param array<int,array<string,mixed>> $groups Groups (by reference).
	 * @param string                         $line   Line.
	 */
	private static function add_item( array &$groups, $line ) {
		$title = self::group_title( $line );
		if ( null !== $title ) {
			$groups[] = array(
				'title' => $title,
				'items' => array(),
			);
			return;
		}
		if ( ! $groups ) {
			$groups[] = array(
				'title' => '',
				'items' => array(),
			);
		}
		$groups[ count( $groups ) - 1 ]['items'][] = self::strip_bullet( $line );
	}

	/**
	 * Add an instruction line (or start a new group).
	 *
	 * @param array<int,array<string,mixed>> $groups Groups (by reference).
	 * @param string                         $line   Line.
	 */
	private static function add_step( array &$groups, $line ) {
		$line = self::strip_bullet( $line );
		if ( preg_match( '/^step\s*\d+\s*[:.)\-–]?$/i', $line ) ) {
			return; // "Step 1" on its own line.
		}
		$title = self::group_title( $line );
		if ( null !== $title ) {
			$groups[] = array(
				'title' => $title,
				'steps' => array(),
			);
			return;
		}
		$line = trim( preg_replace( '/^(?:step\s*)?\d{1,2}\s*[.):\-–]\s*/i', '', $line ) );
		if ( '' === $line ) {
			return;
		}
		if ( ! $groups ) {
			$groups[] = array(
				'title' => '',
				'steps' => array(),
			);
		}
		$groups[ count( $groups ) - 1 ]['steps'][] = array( 'text' => $line );
	}

	/**
	 * Nutrition values in a line ("Calories: 250kcal | Fat: 12g").
	 *
	 * @param string $line Line.
	 * @return array<string,string>
	 */
	public static function nutrition( $line ) {
		$out = array();
		foreach ( self::NUTRITION as $label => $field ) {
			if ( isset( $out[ $field ] ) ) {
				continue;
			}
			if ( 'serving size' === $label ) {
				$re = '/\bserving size\s*[:\-]?\s*([^|,;]+)/i';
			} elseif ( 'fat' === $label ) {
				$re = '/(?<!saturated |trans )\bfat\s*[:\-]?\s*(\d+(?:[.,]\d+)?)/i'; // Not "saturated fat".
			} else {
				$re = '/(?<![a-z])' . preg_quote( $label, '/' ) . '\s*[:\-]?\s*(\d+(?:[.,]\d+)?)/i';
			}
			if ( preg_match( $re, $line, $m ) ) {
				$out[ $field ] = trim( str_replace( ',', '.', $m[1] ) );
			}
		}
		return $out;
	}
}
