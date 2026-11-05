<?php
/**
 * Duration helpers (minutes <-> ISO 8601 / human text).
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Converts between minutes, ISO 8601 durations and human-readable labels.
 */
final class Duration {

	/**
	 * Minutes to ISO 8601 duration (e.g. 90 => PT1H30M). Returns '' for 0/invalid.
	 *
	 * @param int|float|string $minutes Minutes.
	 * @return string
	 */
	public static function to_iso( $minutes ) {
		$minutes = (int) round( (float) $minutes );
		if ( $minutes <= 0 ) {
			return '';
		}
		$days  = intdiv( $minutes, 1440 );
		$hours = intdiv( $minutes % 1440, 60 );
		$mins  = $minutes % 60;
		$out   = 'P' . ( $days ? $days . 'D' : '' );
		$time  = ( $hours ? $hours . 'H' : '' ) . ( $mins ? $mins . 'M' : '' );
		return $out . ( '' !== $time ? 'T' . $time : '' );
	}

	/**
	 * ISO 8601 duration (PT1H30M, P1DT2H) to minutes. Returns 0 if unparseable.
	 *
	 * @param string $iso ISO duration.
	 * @return int
	 */
	public static function from_iso( $iso ) {
		if ( ! is_string( $iso ) || ! preg_match( '/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i', trim( $iso ), $m ) ) {
			return 0;
		}
		$d = isset( $m[1] ) ? (int) $m[1] : 0;
		$h = isset( $m[2] ) ? (int) $m[2] : 0;
		$i = isset( $m[3] ) ? (int) $m[3] : 0;
		$s = isset( $m[4] ) ? (int) $m[4] : 0;
		return $d * 1440 + $h * 60 + $i + (int) round( $s / 60 );
	}

	/**
	 * Human label, e.g. 90 => "1 hr 30 mins".
	 *
	 * @param int $minutes Minutes.
	 * @return string
	 */
	public static function human( $minutes ) {
		$minutes = (int) $minutes;
		if ( $minutes <= 0 ) {
			return '';
		}
		$parts = array();
		$days  = intdiv( $minutes, 1440 );
		$hours = intdiv( $minutes % 1440, 60 );
		$mins  = $minutes % 60;
		if ( $days ) {
			/* translators: %d: number of days */
			$parts[] = sprintf( _n( '%d day', '%d days', $days, 'scrumptious' ), $days );
		}
		if ( $hours ) {
			/* translators: %d: number of hours */
			$parts[] = sprintf( _n( '%d hr', '%d hrs', $hours, 'scrumptious' ), $hours );
		}
		if ( $mins ) {
			/* translators: %d: number of minutes */
			$parts[] = sprintf( _n( '%d min', '%d mins', $mins, 'scrumptious' ), $mins );
		}
		return implode( ' ', $parts );
	}
}
