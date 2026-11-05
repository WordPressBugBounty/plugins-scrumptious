<?php
/**
 * Star ratings: storage, REST endpoint and aggregate meta.
 *
 * Votes live in {prefix}scrumptious_ratings. One vote per visitor per recipe
 * (logged-in user ID, or a salted hash of the IP — raw IPs are never stored).
 * The average and count are cached in post meta for rendering and schema.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Recipe ratings.
 */
final class Ratings {

	const TABLE_SUFFIX = 'scrumptious_ratings';
	const META_AVG     = '_scrumptious_rating_avg';
	const META_COUNT   = '_scrumptious_rating_count';
	const META_BASE    = '_scrumptious_rating_base';
	const RATE_LIMIT   = 30; // Votes per visitor per hour, across all recipes.

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'deleted_post', array( __CLASS__, 'on_delete' ) );
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Create / update the table (dbDelta is idempotent).
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				recipe_id bigint(20) unsigned NOT NULL,
				rating tinyint(1) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				voter char(64) NOT NULL,
				created datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY recipe_voter (recipe_id,voter),
				KEY recipe_id (recipe_id)
			) {$charset};"
		);
	}

	/**
	 * Register REST route.
	 */
	public static function routes() {
		register_rest_route(
			'scrumptious/v1',
			'/rate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_rate' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'recipe_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'rating'    => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
						'maximum'  => 5,
					),
				),
			)
		);
	}

	/**
	 * Visitor fingerprint: user ID when logged in, else salted IP hash.
	 *
	 * @return string
	 */
	public static function voter() {
		$user = get_current_user_id();
		if ( $user ) {
			return hash( 'sha256', 'u:' . $user );
		}
		return hash_hmac( 'sha256', 'ip:' . self::client_ip(), wp_salt( 'nonce' ) );
	}

	/** Cloudflare's published edge ranges (cloudflare.com/ips). Filter: scrumptious_trusted_proxies. */
	const CLOUDFLARE = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * Visitor IP. The connecting address (REMOTE_ADDR) is used, unless it belongs to a trusted proxy:
	 * then the proxy's client header is used (CF-Connecting-IP for Cloudflare, which is trusted by default).
	 * Headers from anyone else are ignored, so they can't be faked to get extra votes.
	 *
	 * @return string
	 */
	public static function client_ip() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- validated with FILTER_VALIDATE_IP below.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip     = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
		/**
		 * Proxies whose client-IP header is trusted: CIDR list. Add your own load balancer or CDN here.
		 *
		 * @param string[] $ranges CIDR ranges (Cloudflare by default).
		 */
		$trusted = (array) apply_filters( 'scrumptious_trusted_proxies', self::CLOUDFLARE );
		if ( '' !== $ip && self::in_ranges( $ip, $trusted ) ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP' ) as $header ) {
				$value = isset( $_SERVER[ $header ] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) ) : '';
				if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
					$ip = $value;
					break;
				}
			}
		}
		// phpcs:enable
		return (string) apply_filters( 'scrumptious_client_ip', $ip );
	}

	/**
	 * Whether an IP is inside any of the CIDR ranges (IPv4 and IPv6).
	 *
	 * @param string   $ip     IP address.
	 * @param string[] $ranges CIDR ranges.
	 * @return bool
	 */
	public static function in_ranges( $ip, array $ranges ) {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
		if ( false === $bin ) {
			return false;
		}
		foreach ( $ranges as $range ) {
			$parts = explode( '/', (string) $range, 2 );
			$net   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
			if ( false === $net || strlen( $net ) !== strlen( $bin ) ) {
				continue;
			}
			$bits  = isset( $parts[1] ) ? (int) $parts[1] : strlen( $bin ) * 8;
			$bytes = intdiv( $bits, 8 );
			if ( substr( $bin, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
				continue;
			}
			$rest = $bits % 8;
			if ( 0 === $rest ) {
				return true;
			}
			$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
			if ( ( $bin[ $bytes ] & $mask ) === ( $net[ $bytes ] & $mask ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * POST /scrumptious/v1/rate
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_rate( $request ) {
		if ( ! Settings::get( 'ratings' ) ) {
			return new \WP_Error( 'scrumptious_ratings_off', __( 'Ratings are turned off.', 'scrumptious' ), array( 'status' => 403 ) );
		}
		$recipe_id = (int) $request['recipe_id'];
		$rating    = (int) $request['rating'];
		if ( Post_Type::SLUG !== get_post_type( $recipe_id ) || 'publish' !== get_post_status( $recipe_id ) ) {
			return new \WP_Error( 'scrumptious_not_found', __( 'Recipe not found.', 'scrumptious' ), array( 'status' => 404 ) );
		}

		$voter = self::voter();
		if ( ! Rate_Limit::hit( 'rate_' . $voter, self::RATE_LIMIT ) ) {
			return new \WP_Error( 'scrumptious_rate_limited', __( 'Too many ratings. Please try again later.', 'scrumptious' ), array( 'status' => 429 ) );
		}

		self::save_vote( $recipe_id, $rating, $voter, get_current_user_id() );
		$agg = self::recalculate( $recipe_id );
		self::purge_pages( $recipe_id );

		return rest_ensure_response(
			array(
				'average' => $agg['average'],
				'count'   => $agg['count'],
				'yours'   => $rating,
			)
		);
	}

	/**
	 * Ask page caches to refresh the pages that show this recipe, so a cached page
	 * doesn't keep showing the old average. Covers the recipe's own page and every
	 * post it's in; caching plugins we know are called directly, others can use the action.
	 *
	 * @param int $recipe_id Recipe.
	 */
	public static function purge_pages( $recipe_id ) {
		$ids = array_unique( array_merge( array( (int) $recipe_id ), Usage::hosts( $recipe_id ) ) );
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
			do_action( 'litespeed_purge_post', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's purge hook.
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $id );
			}
			if ( function_exists( 'w3tc_flush_post' ) ) {
				w3tc_flush_post( $id );
			}
			if ( function_exists( 'wpsc_delete_post_cache' ) ) {
				wpsc_delete_post_cache( $id );
			}
		}
		/**
		 * Fires after a rating changes, with the posts whose cached pages should refresh.
		 *
		 * @param int   $recipe_id Recipe.
		 * @param int[] $ids       Recipe ID plus every post showing it.
		 */
		do_action( 'scrumptious_rating_changed', (int) $recipe_id, $ids );
	}

	/**
	 * Insert or update one visitor's vote.
	 *
	 * @param int    $recipe_id Recipe.
	 * @param int    $rating    1–5.
	 * @param string $voter     Fingerprint.
	 * @param int    $user_id   User ID or 0.
	 */
	public static function save_vote( $recipe_id, $rating, $voter, $user_id = 0 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table upsert.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (recipe_id, rating, user_id, voter, created) VALUES (%d, %d, %d, %s, %s) ON DUPLICATE KEY UPDATE rating = VALUES(rating), created = VALUES(created)',
				$table,
				(int) $recipe_id,
				max( 1, min( 5, (int) $rating ) ),
				(int) $user_id,
				$voter,
				current_time( 'mysql', true )
			)
		);
		// phpcs:enable
	}

	/**
	 * Recompute and cache average + count.
	 *
	 * @param int $recipe_id Recipe.
	 * @return array{average:float,count:int}
	 */
	public static function recalculate( $recipe_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table aggregate.
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS c, AVG(rating) AS a FROM %i WHERE recipe_id = %d', $table, (int) $recipe_id ) );
		$count = $row ? (int) $row->c : 0;
		$sum   = $row ? (float) $row->a * $count : 0.0;
		// Ratings imported from another plugin count as a starting point.
		$base = get_post_meta( $recipe_id, self::META_BASE, true );
		if ( is_array( $base ) && ! empty( $base['count'] ) ) {
			$count += (int) $base['count'];
			$sum   += (float) $base['total'];
		}
		$agg = self::aggregate( $count, $count ? $sum / $count : 0.0 );
		update_post_meta( $recipe_id, self::META_AVG, $agg['average'] );
		update_post_meta( $recipe_id, self::META_COUNT, $agg['count'] );
		return $agg;
	}

	/**
	 * Store ratings imported from another plugin (count and sum of stars) and refresh the average.
	 *
	 * @param int   $recipe_id Recipe.
	 * @param int   $count     Number of ratings.
	 * @param float $total     Sum of all ratings.
	 * @return array{average:float,count:int}
	 */
	public static function set_base( $recipe_id, $count, $total ) {
		$count = max( 0, (int) $count );
		$total = max( 0.0, min( 5.0 * $count, (float) $total ) );
		update_post_meta(
			$recipe_id,
			self::META_BASE,
			array(
				'count' => $count,
				'total' => $total,
			)
		);
		return self::recalculate( $recipe_id );
	}

	/**
	 * Normalize an aggregate.
	 *
	 * @param int   $count   Votes.
	 * @param float $average Mean.
	 * @return array{average:float,count:int}
	 */
	public static function aggregate( $count, $average ) {
		$count = max( 0, (int) $count );
		return array(
			'average' => $count ? round( max( 1.0, min( 5.0, (float) $average ) ), 2 ) : 0.0,
			'count'   => $count,
		);
	}

	/**
	 * Cached aggregate for a recipe.
	 *
	 * @param int $recipe_id Recipe.
	 * @return array{average:float,count:int}
	 */
	public static function get( $recipe_id ) {
		return self::aggregate( (int) get_post_meta( $recipe_id, self::META_COUNT, true ), (float) get_post_meta( $recipe_id, self::META_AVG, true ) );
	}

	/**
	 * Remove votes when a recipe is deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_delete( $post_id ) {
		global $wpdb;
		// Only recipe IDs ever have rows, so this is a no-op for other posts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table cleanup.
		$wpdb->delete( self::table(), array( 'recipe_id' => (int) $post_id ), array( '%d' ) );
	}
}
