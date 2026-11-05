<?php
/**
 * Atomic hit counters for rate limits.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window counters that stay correct when requests arrive at the same time.
 *
 * A read-then-write transient (get, compare, set) lets parallel requests all read the
 * same count and all get through. Here the increment itself is atomic: in a persistent
 * object cache (Redis, Memcached) with wp_cache_incr(), otherwise with one
 * INSERT … ON DUPLICATE KEY UPDATE on the options table. The rows are written as
 * transients (with a timeout row), so WordPress's own expired-transient cleanup removes them.
 */
final class Rate_Limit {

	const GROUP = 'scrumptious_rl';

	/**
	 * Count one hit and say whether it's within the limit.
	 *
	 * @param string $key    What's being limited (e.g. 'rate_' . $voter). Hashed, so any string is fine.
	 * @param int    $limit  Hits allowed per window. 0 or less means no limit.
	 * @param int    $window Window length in seconds.
	 * @return bool True when this hit is allowed.
	 */
	public static function hit( $key, $limit, $window = HOUR_IN_SECONDS ) {
		$limit = (int) $limit;
		if ( $limit <= 0 ) {
			return true;
		}
		return self::increment( $key, $window ) <= $limit;
	}

	/**
	 * Atomically add one to the counter for the current window.
	 *
	 * @param string $key    Key.
	 * @param int    $window Window in seconds.
	 * @return int New count.
	 */
	public static function increment( $key, $window = HOUR_IN_SECONDS ) {
		$window = max( 1, (int) $window );
		$name   = self::name( $key, $window, time() );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $name, 0, self::GROUP, $window );
			$n = wp_cache_incr( $name, 1, self::GROUP );
			if ( false !== $n ) {
				return (int) $n;
			}
		}

		global $wpdb;
		$option = '_transient_' . $name;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- atomic counter; the options API can't increment.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				$option,
				'1',
				'off'
			)
		);
		// Timeout row so core's delete_expired_transients() cleans up. INSERT IGNORE keeps the first one.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				'_transient_timeout_' . $name,
				(string) ( time() + $window ),
				'off'
			)
		);
		$n = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		// phpcs:enable
		return (int) $n;
	}

	/**
	 * Counter name for a key and window: short enough for option_name (191 chars).
	 *
	 * @param string $key    Key.
	 * @param int    $window Window in seconds.
	 * @param int    $now    Timestamp.
	 * @return string
	 */
	public static function name( $key, $window, $now ) {
		return 'scrumptious_rl_' . substr( md5( (string) $key ), 0, 20 ) . '_' . (int) floor( $now / max( 1, (int) $window ) );
	}
}
