<?php
/**
 * Base class for importers from other recipe plugins.
 *
 * Importers read the other plugin's data straight from the database, so they work
 * even after that plugin has been deactivated. Source recipes are never changed.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * One source plugin: find its recipes, read one, and swap its blocks/shortcodes in post content.
 */
abstract class Importer {

	/**
	 * Short slug, e.g. "wprm".
	 *
	 * @return string
	 */
	abstract public function slug();

	/**
	 * Plugin name shown to users.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * All source recipe IDs (oldest first).
	 *
	 * @return int[]
	 */
	abstract public function ids();

	/**
	 * Read one source recipe.
	 *
	 * @param int $id Source ID.
	 * @return array{title:string,recipe:array<string,mixed>,image_id:int,status:string,date:string,author:int,terms:array<string,string[]>,rating:array{count:int,total:float}}|null
	 */
	abstract public function load( $id );

	/**
	 * Replace this plugin's recipe blocks/shortcodes in post content.
	 *
	 * @param string         $content Post content.
	 * @param array<int,int> $map     Source ID => Scrumptious recipe ID.
	 * @return string
	 */
	abstract public function replace( $content, array $map );

	/**
	 * SQL LIKE patterns that find posts using this plugin's recipes.
	 *
	 * @return string[]
	 */
	abstract public function markers();

	/**
	 * Whether this site has recipes from this plugin.
	 *
	 * @return bool
	 */
	public function available() {
		return count( $this->ids() ) > 0;
	}

	/* ---------- Shared helpers ---------- */

	/**
	 * IDs of posts of a (possibly unregistered) post type.
	 *
	 * @param string $post_type Post type.
	 * @return int[]
	 */
	protected static function post_ids( $post_type ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- works when the source plugin is inactive (type unregistered).
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit') ORDER BY ID ASC", $post_type ) );
		return array_map( 'intval', $ids );
	}

	/**
	 * Term names for a post in a taxonomy that may no longer be registered.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return string[]
	 */
	protected static function term_names( $post_id, $taxonomy ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- taxonomy may be unregistered.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT t.name FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
				WHERE tt.taxonomy = %s AND tr.object_id = %d ORDER BY t.name",
				$taxonomy,
				(int) $post_id
			)
		);
		return array_values( array_filter( array_map( 'html_entity_decode', $names ) ) );
	}

	/**
	 * Term name by ID, whatever its taxonomy.
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	protected static function term_name( $term_id ) {
		global $wpdb;
		if ( ! $term_id ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- taxonomy may be unregistered.
		return html_entity_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->terms} WHERE term_id = %d", (int) $term_id ) ) );
	}

	/**
	 * Plain text from a little HTML (keeps words apart).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function text( $html ) {
		$html = preg_replace( '#<\s*(br|/p|/li|/div)[^>]*>#i', ' ', (string) $html );
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * Split an HTML (or plain text) list into groups, using headings as group titles.
	 *
	 * Handles <h*> headings, <p><strong>Heading</strong></p>, "For the sauce:" lines,
	 * <li> items and, when there is no list markup, one item per line/paragraph.
	 *
	 * @param string $html  HTML.
	 * @param string $key   'items' (plain text) or 'steps' (keeps inline HTML).
	 * @return array<int,array<string,mixed>>
	 */
	public static function groups( $html, $key ) {
		$html   = str_replace( array( "\r\n", "\r" ), "\n", (string) $html );
		$groups = array();
		$add    = static function ( $title, $value ) use ( &$groups, $key ) {
			if ( null !== $title ) {
				$groups[] = array(
					'title' => $title,
					$key    => array(),
				);
				return;
			}
			if ( ! $groups ) {
				$groups[] = array(
					'title' => '',
					$key    => array(),
				);
			}
			$groups[ count( $groups ) - 1 ][ $key ][] = 'steps' === $key ? array( 'text' => $value ) : $value;
		};

		$has_list = (bool) preg_match( '#<li[\s>]#i', $html );
		if ( preg_match( '#<(li|p|h[1-6]|div)[\s>]#i', $html ) ) {
			preg_match_all( '#<(h[1-6])[^>]*>(.*?)</\1>|<li[^>]*>(.*?)</li>|<p[^>]*>(.*?)</p>|<div[^>]*>(.*?)</div>#is', $html, $m, PREG_SET_ORDER );
			foreach ( $m as $match ) {
				if ( ! empty( $match[1] ) ) {
					$title = self::text( $match[2] );
					if ( '' !== $title ) {
						$add( rtrim( $title, ':：' ), '' );
					}
					continue;
				}
				$inner = isset( $match[3] ) && '' !== $match[3] ? $match[3] : ( isset( $match[4] ) && '' !== $match[4] ? $match[4] : ( isset( $match[5] ) ? $match[5] : '' ) );
				$plain = self::text( $inner );
				if ( '' === $plain ) {
					continue;
				}
				$is_li = '' !== $match[3];
				// A bold-only paragraph or a short "Sauce:" line between lists is a heading.
				if ( ! $is_li && ( preg_match( '#^\s*<(strong|b)>[^<]+</\1>\s*$#i', $inner ) || null !== Recipe_Parser::group_title( $plain ) ) && mb_strlen( $plain ) <= 60 ) {
					$add( rtrim( $plain, ':：' ), '' );
					continue;
				}
				if ( $has_list && ! $is_li ) {
					continue; // Intro text around a list is not an item.
				}
				$add( null, 'steps' === $key ? trim( $inner ) : $plain );
			}
		} else {
			foreach ( Recipe_Parser::lines( $html ) as $line ) {
				$title = Recipe_Parser::group_title( $line );
				if ( null !== $title ) {
					$add( $title, '' );
				} else {
					$line = Recipe_Parser::strip_bullet( $line );
					if ( 'steps' === $key ) {
						$line = trim( preg_replace( '/^(?:step\s*)?\d{1,2}\s*[.):\-–]\s*/i', '', $line ) );
					}
					$add( null, $line );
				}
			}
		}

		// Drop groups that only have a title and nothing after them (except when they're all empty).
		$filled = array_values(
			array_filter(
				$groups,
				static function ( $g ) use ( $key ) {
					return ! empty( $g[ $key ] );
				}
			)
		);
		return $filled ? $filled : array(
			array(
				'title' => '',
				$key    => array(),
			),
		);
	}

	/**
	 * Servings number + unit from a yield text like "24 cookies" or "4-6 servings".
	 *
	 * @param string $text Yield text.
	 * @return array{0:float,1:string}
	 */
	public static function yield_parts( $text ) {
		$meta = Recipe_Parser::meta( 'Yield: ' . self::text( $text ) );
		return $meta ? array( (float) $meta['servings'], (string) $meta['unit'] ) : array( 0.0, '' );
	}

	/**
	 * Swap `[shortcode ...]` occurrences (optionally wrapped in a core Shortcode block).
	 *
	 * @param string   $content  Content.
	 * @param string   $tag      Shortcode tag.
	 * @param callable $callback fn( array $atts ): ?string — replacement block markup, or null to leave as is.
	 * @return string
	 */
	protected static function swap_shortcode( $content, $tag, $callback ) {
		$re = '#(<!--\s+wp:shortcode\s+-->\s*)?\[' . preg_quote( $tag, '#' ) . '(\s[^\]]*)?\](\s*<!--\s+/wp:shortcode\s+-->)?#i';
		return preg_replace_callback(
			$re,
			static function ( $m ) use ( $callback ) {
				$atts = shortcode_parse_atts( isset( $m[2] ) ? trim( $m[2] ) : '' );
				$out  = $callback( is_array( $atts ) ? $atts : array() );
				if ( null === $out ) {
					return $m[0];
				}
				$in_block = ! empty( $m[1] ) && ! empty( $m[3] );
				// Outside block markup (classic content, paragraphs) use our shortcode fallback.
				if ( ! $in_block && '' !== $out && preg_match( '#"recipeId":(\d+)#', $out, $id ) ) {
					return '[scrumptious id="' . $id[1] . '"]';
				}
				if ( ! $in_block && false !== strpos( $out, 'scrumptious/jump' ) ) {
					return '[scrumptious-jump]';
				}
				return $out;
			},
			$content
		);
	}

	/**
	 * Swap a block (self-closing or with inner content).
	 *
	 * @param string   $content  Content.
	 * @param string   $name     Block name, e.g. "wp-recipe-maker/recipe".
	 * @param callable $callback fn( array $attrs ): ?string.
	 * @return string
	 */
	protected static function swap_block( $content, $name, $callback ) {
		$n  = preg_quote( $name, '#' );
		$re = '#<!--\s+wp:' . $n . '(\s+(\{.*?\}))?\s+(?:/-->|-->.*?<!--\s+/wp:' . $n . '\s+-->)#s';
		return preg_replace_callback(
			$re,
			static function ( $m ) use ( $callback ) {
				$attrs = isset( $m[2] ) ? json_decode( $m[2], true ) : array();
				$out   = $callback( is_array( $attrs ) ? $attrs : array() );
				return null === $out ? $m[0] : $out;
			},
			$content
		);
	}

	/**
	 * Our recipe block markup.
	 *
	 * @param int $recipe_id Scrumptious recipe ID.
	 * @return string
	 */
	protected static function recipe_block( $recipe_id ) {
		return '<!-- wp:scrumptious/recipe {"recipeId":' . (int) $recipe_id . '} /-->';
	}

	/**
	 * Our Jump to Recipe block markup.
	 *
	 * @return string
	 */
	protected static function jump_block() {
		return '<!-- wp:scrumptious/jump /-->';
	}
}
