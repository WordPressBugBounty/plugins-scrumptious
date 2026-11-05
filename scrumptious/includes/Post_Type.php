<?php
/**
 * Recipe post type + taxonomies.
 *
 * Recipes are stored as their own (non-public) post type and embedded in
 * regular posts with the recipe block, so one recipe can appear in several
 * posts and survives theme / builder changes.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the recipe post type and its taxonomies.
 */
final class Post_Type {

	/** Admin menu icon: the cookie with a bite, as an SVG data URI (WordPress recolours it to match the admin theme). */
	const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNTYgMjU2Ij48cGF0aCBmaWxsPSJibGFjayIgZD0iTTIxNi40IDEwOS4xIDIwOS4wIDEwOS45IDE5OS45IDEwOS43IDE5MC45IDEwOC4xIDE4Mi4zIDEwNS4zIDE3NC4xIDEwMS4yIDE2Ni43IDk1LjkgMTYwLjEgODkuNiAxNTQuNCA4Mi40IDE1MC4wIDc0LjUgMTQ2LjcgNjYuMCAxNDQuNyA1Ny4xIDE0NC4wIDQ4LjAgMTQ0LjcgMzguOSAxNDYuNyAzMC4wIDE1MC4wIDIxLjUgMTU0LjMgMTMuOCAxNDEuOSAxMS4zIDEyNC4wIDEwLjAgMTA2LjEgMTEuMyA5NC40IDEzLjcgODIuOSAxNy4xIDcxLjggMjEuNyA2MS4zIDI3LjQgNTEuMyAzNC4wIDQyLjEgNDEuNiAzMy42IDUwLjEgMjYuMCA1OS4zIDE5LjQgNjkuMyAxMy43IDc5LjggOS4xIDkwLjkgNS43IDEwMi40IDMuMyAxMTQuMSAyLjEgMTI2LjAgMi4xIDEzOC4wIDMuMyAxNDkuOSA1LjcgMTYxLjYgOS4xIDE3My4xIDEzLjcgMTg0LjIgMTkuNCAxOTQuNyAyNi4wIDIwNC43IDMzLjYgMjEzLjkgNDIuMSAyMjIuNCA1MS4zIDIzMC4wIDYxLjMgMjM2LjYgNzEuOCAyNDIuMyA4Mi45IDI0Ni45IDk0LjQgMjUwLjMgMTA2LjEgMjUyLjcgMTE4LjAgMjUzLjkgMTMwLjAgMjUzLjkgMTQxLjkgMjUyLjcgMTUzLjYgMjUwLjMgMTY1LjEgMjQ2LjkgMTgxLjUgMjM5LjYgMTk2LjcgMjMwLjAgMjA1LjkgMjIyLjQgMjE0LjQgMjEzLjkgMjIyLjAgMjA0LjcgMjMxLjYgMTg5LjUgMjM2LjcgMTc4LjcgMjQwLjcgMTY3LjQgMjQzLjMgMTU3LjQgMjM2LjIgMTU1LjMgMjMwLjAgMTUxLjkgMjI0LjUgMTQ3LjUgMjE5LjEgMTQwLjUgMjE2LjEgMTM0LjEgMjE0LjQgMTI3LjMgMjE0LjIgMTE4LjUgMjE2LjQgMTA5LjFaIi8+PC9zdmc+';


	const SLUG       = 'scrumptious_recipe';
	const CONTENT    = '<!-- wp:scrumptious/recipe /-->';
	const TAXONOMIES = array( 'scrumptious_course', 'scrumptious_cuisine', 'scrumptious_diet' );

	/**
	 * Hooks that keep every recipe post bound to its Recipe Card block.
	 */
	public static function init() {
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'enforce_content' ), 10, 2 );
		add_filter( 'rest_prepare_' . self::SLUG, array( __CLASS__, 'rest_content' ), 10, 3 );
	}

	/**
	 * A recipe post's content is always exactly one Recipe Card block.
	 *
	 * @param array<string,mixed> $data    Slashed post data.
	 * @param array<string,mixed> $postarr Raw post array.
	 * @return array<string,mixed>
	 */
	public static function enforce_content( $data, $postarr ) {
		unset( $postarr );
		if ( isset( $data['post_type'] ) && self::SLUG === $data['post_type'] && 'auto-draft' !== $data['post_status'] ) {
			$data['post_content'] = wp_slash( self::CONTENT );
		}
		return $data;
	}

	/**
	 * Editor fallback for recipes whose stored content predates the block.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post     Post.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_REST_Response
	 */
	public static function rest_content( $response, $post, $request ) {
		unset( $post );
		if ( 'edit' === $request['context'] && isset( $response->data['content']['raw'] ) && false === strpos( (string) $response->data['content']['raw'], 'wp:scrumptious/recipe' ) ) {
			$response->data['content']['raw'] = self::CONTENT;
		}
		return $response;
	}

	/**
	 * Whether saved permalinks lack the recipe URLs (e.g. another plugin or a host tool
	 * rebuilt them while Scrumptious wasn't loaded). Checked at most once an hour.
	 *
	 * @param string $base Recipe URL base.
	 * @return bool
	 */
	private static function rules_missing( $base ) {
		if ( ! get_option( 'permalink_structure' ) || get_transient( 'scrumptious_rules_checked' ) ) {
			return false;
		}
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || ! $rules ) {
			return false; // WordPress rebuilds empty rules itself, with our post type registered.
		}
		set_transient( 'scrumptious_rules_checked', 1, HOUR_IN_SECONDS );
		$prefix = $base . '/';
		foreach ( array_keys( $rules ) as $pattern ) {
			if ( 0 === strpos( (string) $pattern, $prefix ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Register CPT and taxonomies.
	 */
	public static function register() {
		$settings = Settings::all();
		$public   = (bool) $settings['public_pages'];

		$supports = array( 'title', 'editor', 'thumbnail', 'author', 'custom-fields' );
		if ( $public && $settings['allow_comments'] ) {
			$supports[] = 'comments';
		}

		register_post_type(
			self::SLUG,
			array(
				'labels'              => array(
					'name'               => __( 'Recipes', 'scrumptious' ),
					'singular_name'      => __( 'Recipe', 'scrumptious' ),
					'add_new_item'       => __( 'Add New Recipe', 'scrumptious' ),
					'edit_item'          => __( 'Edit Recipe', 'scrumptious' ),
					'new_item'           => __( 'New Recipe', 'scrumptious' ),
					'view_item'          => __( 'View Recipe', 'scrumptious' ),
					'search_items'       => __( 'Search Recipes', 'scrumptious' ),
					'not_found'          => __( 'No recipes found.', 'scrumptious' ),
					'not_found_in_trash' => __( 'No recipes found in Trash.', 'scrumptious' ),
					'all_items'          => __( 'All Recipes', 'scrumptious' ),
					'archives'           => __( 'Recipe Index', 'scrumptious' ),
					'menu_name'          => __( 'Recipes', 'scrumptious' ),
				),
				'public'              => $public,
				'publicly_queryable'  => $public,
				'exclude_from_search' => ! $public,
				'show_in_nav_menus'   => $public,
				'has_archive'         => $public ? $settings['archive_base'] : false,
				'rewrite'             => $public ? array(
					'slug'       => $settings['recipe_base'],
					'with_front' => false,
				) : false,
				'query_var'           => $public,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => true,
				'rest_base'           => 'scrumptious_recipe',
				'menu_icon'           => self::MENU_ICON, // The ScrumptiousWP cookie (brand/scrumptious-icon-mono.svg).
				'menu_position'       => 25,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'supports'            => $supports,
				'can_export'          => true,
				'delete_with_user'    => false,
				// The recipe screen is one locked Recipe Card block bound to this post.
				'template'            => array( array( 'scrumptious/recipe', array() ) ),
				'template_lock'       => 'all',
			)
		);

		if ( get_option( 'scrumptious_flush_rewrite' ) || ( $public && self::rules_missing( $settings['recipe_base'] ) ) ) {
			delete_option( 'scrumptious_flush_rewrite' );
			add_action( 'wp_loaded', 'flush_rewrite_rules' );
		}

		$tax_labels = array(
			'scrumptious_course'  => array( __( 'Courses', 'scrumptious' ), __( 'Course', 'scrumptious' ) ),
			'scrumptious_cuisine' => array( __( 'Cuisines', 'scrumptious' ), __( 'Cuisine', 'scrumptious' ) ),
			'scrumptious_diet'    => array( __( 'Diets', 'scrumptious' ), __( 'Diet', 'scrumptious' ) ),
		);

		foreach ( $tax_labels as $tax => $labels ) {
			register_taxonomy(
				$tax,
				self::SLUG,
				array(
					'labels'            => array(
						'name'          => $labels[0],
						'singular_name' => $labels[1],
					),
					'public'            => false,
					'show_ui'           => true,
					'show_in_rest'      => true,
					'show_admin_column' => true,
					'hierarchical'      => false,
					'rewrite'           => false,
				)
			);
		}
	}
}
