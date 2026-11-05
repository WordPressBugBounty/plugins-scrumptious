<?php
/**
 * Optional public recipe pages (/recipe/slug/) and the recipe index.
 *
 * - Block themes: plugin-registered templates (single + archive), editable in the Site Editor.
 * - Classic themes: the card is injected into the_content of the theme's single template.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end behaviour for public recipe pages.
 */
final class Recipe_Pages {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_templates' ), 20 );
		add_filter( 'the_content', array( __CLASS__, 'inject_card' ), 9 );
		add_filter( 'comments_open', array( __CLASS__, 'comments_open' ), 10, 2 );
		add_filter( 'pings_open', array( __CLASS__, 'comments_open' ), 10, 2 );
		add_filter( 'post_type_link', array( __CLASS__, 'permalink' ), 10, 2 );
		add_filter( 'template_include', array( __CLASS__, 'index_template' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'index_query' ) );
	}

	/**
	 * Whether the recipe index uses the Scrumptious grid (classic themes; block themes get a template).
	 *
	 * @return bool
	 */
	public static function grid_index() {
		return self::enabled() && ! wp_is_block_theme() && 'grid' === Settings::get( 'archive_layout' );
	}

	/**
	 * Course and cuisine filters on the recipe index, from ?course= and ?cuisine= (term slugs).
	 *
	 * @return array<string,string> Taxonomy => term slug.
	 */
	public static function index_filters() {
		$out = array();
		foreach ( array(
			'course'  => 'scrumptious_course',
			'cuisine' => 'scrumptious_cuisine',
		) as $param => $taxonomy ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only listing filter.
			$slug = isset( $_GET[ $param ] ) ? sanitize_title( wp_unslash( (string) $_GET[ $param ] ) ) : '';
			if ( '' !== $slug && term_exists( $slug, $taxonomy ) ) {
				$out[ $taxonomy ] = $slug;
			}
		}
		return $out;
	}

	/**
	 * Recipe index query on classic themes: 12 per page, plus the course/cuisine filters.
	 *
	 * @param \WP_Query $query Query.
	 */
	public static function index_query( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( Post_Type::SLUG ) || ! self::grid_index() ) {
			return;
		}
		/**
		 * Filter how many recipes the recipe index shows per page (classic themes).
		 *
		 * @param int $per_page Recipes per page. Default 12.
		 */
		$query->set( 'posts_per_page', (int) apply_filters( 'scrumptious_index_per_page', 12 ) );
		$tax = array();
		foreach ( self::index_filters() as $taxonomy => $slug ) {
			$tax[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => array( $slug ),
			);
		}
		if ( $tax ) {
			$query->set( 'tax_query', $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- filtered listing.
		}
	}

	/**
	 * Use the Scrumptious recipe index template on classic themes, unless the theme has its own.
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public static function index_template( $template ) {
		if ( ! is_post_type_archive( Post_Type::SLUG ) || ! self::grid_index() || locate_template( array( 'archive-' . Post_Type::SLUG . '.php' ) ) ) {
			return $template;
		}
		return SCRUMPTIOUS_DIR . 'templates/archive-recipe.php';
	}

	/**
	 * The primary URL for a recipe: its own page when public pages are on,
	 * otherwise the first post that shows it. '' when it has neither.
	 *
	 * @param int $recipe_id Recipe ID.
	 * @return string
	 */
	public static function primary_url( $recipe_id ) {
		if ( self::enabled() ) {
			$url = (string) get_permalink( $recipe_id );
		} else {
			$host = Usage::primary_host( $recipe_id );
			$url  = $host ? (string) get_permalink( $host ) : '';
		}
		/**
		 * Filter the primary URL of a recipe (grid links, print "Recipe from", schema url).
		 *
		 * @param string $url       URL, '' when the recipe is not shown anywhere.
		 * @param int    $recipe_id Recipe ID.
		 */
		return (string) apply_filters( 'scrumptious_recipe_url', $url, (int) $recipe_id );
	}

	/**
	 * Without public pages a recipe has no URL of its own: link to the post that shows it.
	 *
	 * @param string   $link Permalink.
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function permalink( $link, $post ) {
		if ( Post_Type::SLUG !== $post->post_type || self::enabled() ) {
			return $link;
		}
		$host = Usage::primary_host( $post->ID );
		return $host ? get_permalink( $host ) . '#swp-recipe-' . (int) $post->ID : $link;
	}

	/**
	 * Whether public pages are on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Settings::get( 'public_pages' );
	}

	/**
	 * Register block templates (WP 6.7+) for block themes.
	 */
	public static function register_templates() {
		if ( ! self::enabled() || ! function_exists( 'register_block_template' ) ) {
			return;
		}
		$comments = Settings::get( 'allow_comments' ) ? '<!-- wp:comments {"className":"swp-comments"} --><div class="wp-block-comments swp-comments"><!-- wp:comments-title /--><!-- wp:comment-template --><!-- wp:comment-author-name /--><!-- wp:comment-content /--><!-- /wp:comment-template --><!-- wp:comments-pagination /--><!-- wp:post-comments-form /--></div><!-- /wp:comments -->' : '';

		register_block_template(
			'scrumptious//single-' . Post_Type::SLUG,
			array(
				'title'       => __( 'Single Recipe', 'scrumptious' ),
				'description' => __( 'Displays a single recipe page.', 'scrumptious' ),
				'post_types'  => array( Post_Type::SLUG ),
				'content'     => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
					. '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group">'
					. '<!-- wp:post-featured-image {"aspectRatio":"16/9","style":{"border":{"radius":"14px"}}} /-->'
					. '<!-- wp:post-title {"level":1} /-->'
					. '<!-- wp:scrumptious/recipe-part {"part":"summary"} /-->'
					. '<!-- wp:scrumptious/recipe-part {"part":"rating"} /-->'
					. '<!-- wp:scrumptious/recipe-part {"part":"actions"} /-->'
					. '<!-- wp:scrumptious/recipe-part {"part":"meta"} /-->'
					. '<!-- wp:columns --><div class="wp-block-columns">'
					. '<!-- wp:column {"width":"40%"} --><div class="wp-block-column" style="flex-basis:40%"><!-- wp:scrumptious/recipe-part {"part":"ingredients"} /--></div><!-- /wp:column -->'
					. '<!-- wp:column {"width":"60%"} --><div class="wp-block-column" style="flex-basis:60%"><!-- wp:scrumptious/recipe-part {"part":"instructions"} /--></div><!-- /wp:column -->'
					. '</div><!-- /wp:columns -->'
					. '<!-- wp:scrumptious/recipe-part {"part":"notes"} /-->'
					. '<!-- wp:scrumptious/recipe-part {"part":"nutrition"} /-->'
					. $comments
					. '</main><!-- /wp:group -->'
					. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
			)
		);

		register_block_template(
			'scrumptious//archive-' . Post_Type::SLUG,
			array(
				'title'       => __( 'Recipe Index', 'scrumptious' ),
				'description' => __( 'Displays the list of recipes.', 'scrumptious' ),
				'content'     => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
					. '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group">'
					. '<!-- wp:query-title {"type":"archive","showPrefix":false} /-->'
					. '<!-- wp:query {"queryId":1,"query":{"inherit":true,"perPage":12},"layout":{"type":"default"}} --><div class="wp-block-query">'
					. '<!-- wp:post-template {"layout":{"type":"grid","columnCount":3}} -->'
					. '<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"1","style":{"border":{"radius":"12px"}}} /-->'
					. '<!-- wp:post-title {"level":3,"isLink":true,"style":{"typography":{"fontSize":"1.1rem"}}} /-->'
					. '<!-- wp:scrumptious/recipe-part {"part":"rating"} /-->'
					. '<!-- /wp:post-template -->'
					. '<!-- wp:query-pagination --><!-- wp:query-pagination-previous /--><!-- wp:query-pagination-numbers /--><!-- wp:query-pagination-next /--><!-- /wp:query-pagination -->'
					. '<!-- wp:query-no-results --><!-- wp:paragraph --><p>' . esc_html__( 'No recipes yet.', 'scrumptious' ) . '</p><!-- /wp:paragraph --><!-- /wp:query-no-results -->'
					. '</div><!-- /wp:query -->'
					. '</main><!-- /wp:group -->'
					. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
			)
		);
	}

	/**
	 * Classic themes (and recipes created without the block): make sure the card shows.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function inject_card( $content ) {
		if ( ! is_singular( Post_Type::SLUG ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( has_block( 'scrumptious/recipe' ) ) {
			return $content;
		}
		return Renderer::render( get_the_ID() );
	}

	/**
	 * Comments on recipe pages follow the plugin setting.
	 *
	 * @param bool        $open    Open.
	 * @param int|WP_Post $post_id Post.
	 * @return bool
	 */
	public static function comments_open( $open, $post_id ) {
		if ( Post_Type::SLUG !== get_post_type( $post_id ) ) {
			return $open;
		}
		return self::enabled() && (bool) Settings::get( 'allow_comments' );
	}
}
