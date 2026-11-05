<?php
/**
 * Tracks which posts use which recipes (block or shortcode).
 *
 * Stored both ways so admin columns and cleanups are cheap:
 * host post meta `_scrumptious_recipe_ids` and recipe post meta `_scrumptious_used_in`.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Maintains the post <-> recipe usage index.
 */
final class Usage {

	const HOST_META   = '_scrumptious_recipe_ids';
	const RECIPE_META = '_scrumptious_used_in';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
	}

	/**
	 * Recipe IDs referenced in post content (blocks, nested blocks, shortcodes).
	 *
	 * @param string $content Post content.
	 * @return int[]
	 */
	public static function extract_ids( $content ) {
		$ids = self::ordered_ids( $content );
		sort( $ids );
		return $ids;
	}

	/**
	 * Recipe IDs in the order they appear in the content.
	 *
	 * @param string $content Post content.
	 * @return int[]
	 */
	public static function ordered_ids( $content ) {
		$content = (string) $content;
		$ids     = array();
		if ( function_exists( 'parse_blocks' ) && false !== strpos( $content, '<!-- wp:scrumptious/recipe' ) ) {
			self::walk( parse_blocks( $content ), $ids );
		}
		if ( false !== strpos( $content, '[scrumptious' ) && preg_match_all( '/\[scrumptious\b[^\]]*\bid=["\']?(\d+)/', $content, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[] = (int) $id;
			}
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * Recursively collect recipe IDs from parsed blocks.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param int[]                          $ids    Collected IDs (by reference).
	 */
	private static function walk( array $blocks, array &$ids ) {
		foreach ( $blocks as $block ) {
			if ( isset( $block['blockName'] ) && 'scrumptious/recipe' === $block['blockName'] && ! empty( $block['attrs']['recipeId'] ) ) {
				$ids[] = (int) $block['attrs']['recipeId'];
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::walk( $block['innerBlocks'], $ids );
			}
		}
	}

	/**
	 * Update the index when a post is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || Post_Type::SLUG === $post->post_type ) {
			return;
		}
		$new = 'trash' === $post->post_status ? array() : self::extract_ids( (string) $post->post_content );
		self::sync( (int) $post_id, $new );
		if ( 'publish' === $post->post_status ) {
			self::publish_recipes( $new );
		}
	}

	/**
	 * Recipes made inside a post start as drafts, so nothing leaks before the post goes live.
	 * When the post is published, its draft recipes are published with it.
	 *
	 * @param int[] $ids Recipe IDs used by a published post.
	 */
	public static function publish_recipes( array $ids ) {
		$type = get_post_type_object( Post_Type::SLUG );
		if ( ! $type || ! current_user_can( $type->cap->publish_posts ) ) {
			return;
		}
		foreach ( $ids as $id ) {
			if ( Post_Type::SLUG === get_post_type( $id ) && 'draft' === get_post_status( $id ) && current_user_can( 'edit_post', $id ) ) {
				wp_publish_post( $id );
			}
		}
	}

	/**
	 * Drop a deleted post from the index.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_delete( $post_id ) {
		if ( Post_Type::SLUG === get_post_type( $post_id ) ) {
			return;
		}
		self::sync( (int) $post_id, array() );
	}

	/**
	 * Write both sides of the index.
	 *
	 * @param int   $host_id Host post ID.
	 * @param int[] $current Recipe IDs now used by the host.
	 */
	private static function sync( $host_id, array $current ) {
		$old = get_post_meta( $host_id, self::HOST_META, true );
		$old = is_array( $old ) ? array_map( 'intval', $old ) : array();

		foreach ( array_diff( $old, $current ) as $rid ) {
			$hosts = self::hosts( $rid );
			update_post_meta( $rid, self::RECIPE_META, array_values( array_diff( $hosts, array( $host_id ) ) ) );
		}
		foreach ( array_diff( $current, $old ) as $rid ) {
			if ( Post_Type::SLUG !== get_post_type( $rid ) ) {
				continue;
			}
			$hosts   = self::hosts( $rid );
			$hosts[] = $host_id;
			update_post_meta( $rid, self::RECIPE_META, array_values( array_unique( $hosts ) ) );
		}

		if ( $current ) {
			update_post_meta( $host_id, self::HOST_META, $current );
		} else {
			delete_post_meta( $host_id, self::HOST_META );
		}
	}

	/**
	 * Rebuild the index from existing content (used on upgrade).
	 *
	 * @return int Number of posts scanned.
	 */
	public static function backfill() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time upgrade scan, no core API for content search by type-agnostic LIKE.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ( %s, 'revision' ) AND post_status NOT IN ( 'auto-draft', 'trash' ) AND ( post_content LIKE %s OR post_content LIKE %s ) LIMIT 5000",
				Post_Type::SLUG,
				'%' . $wpdb->esc_like( '<!-- wp:scrumptious/recipe' ) . '%',
				'%' . $wpdb->esc_like( '[scrumptious' ) . '%'
			)
		);
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post ) {
				self::on_save( (int) $id, $post );
			}
		}
		return count( $ids );
	}

	/**
	 * Host post IDs using a recipe.
	 *
	 * @param int $recipe_id Recipe ID.
	 * @return int[]
	 */
	public static function hosts( $recipe_id ) {
		$hosts = get_post_meta( (int) $recipe_id, self::RECIPE_META, true );
		return is_array( $hosts ) ? array_values( array_map( 'intval', $hosts ) ) : array();
	}

	/**
	 * First published host post (the canonical page for the recipe's schema URL).
	 *
	 * @param int $recipe_id Recipe ID.
	 * @return \WP_Post|null
	 */
	public static function primary_host( $recipe_id ) {
		foreach ( self::hosts( $recipe_id ) as $id ) {
			$post = get_post( $id );
			if ( $post && 'publish' === $post->post_status ) {
				return $post;
			}
		}
		return null;
	}
}
