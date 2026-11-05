<?php
/**
 * Recipe block + shortcode registration.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the recipe block and the [scrumptious] shortcode.
 */
final class Block {

	/**
	 * Register block and shortcode.
	 */
	public static function register() {
		$dir = SCRUMPTIOUS_DIR . 'build/blocks/recipe';
		if ( file_exists( $dir . '/block.json' ) ) {
			register_block_type(
				$dir,
				array(
					'render_callback' => array( __CLASS__, 'render_block' ),
				)
			);
		}

		$jump = SCRUMPTIOUS_DIR . 'build/blocks/jump';
		if ( file_exists( $jump . '/block.json' ) ) {
			register_block_type(
				$jump,
				array(
					'render_callback' => array( __CLASS__, 'render_jump' ),
				)
			);
		}

		foreach ( array(
			'recipe-part' => 'render_part',
			'recipe-grid' => 'render_grid',
		) as $dir => $callback ) {
			if ( file_exists( SCRUMPTIOUS_DIR . 'build/blocks/' . $dir . '/block.json' ) ) {
				register_block_type(
					SCRUMPTIOUS_DIR . 'build/blocks/' . $dir,
					array(
						'render_callback' => array( __CLASS__, $callback ),
					)
				);
			}
		}

		add_shortcode( 'scrumptious', array( __CLASS__, 'render_shortcode' ) );
		add_shortcode( 'scrumptious-jump', array( __CLASS__, 'render_jump_shortcode' ) );
		add_filter( 'the_content', array( __CLASS__, 'auto_jump' ), 8 );
	}

	/**
	 * Jump to Recipe buttons.
	 *
	 * @param int    $recipe_id  Recipe ID (0 = first recipe in the current post).
	 * @param string $label    Button label ('' = default).
	 * @param bool   $show_print Show the print button.
	 * @param string $wrapper  Wrapper attributes.
	 * @return string
	 */
	public static function jump_html( $recipe_id, $label = '', $show_print = true, $wrapper = '' ) {
		if ( ! $recipe_id ) {
			$post = get_post();
			$ids  = $post ? Usage::ordered_ids( $post->post_content ) : array();
			if ( ! $ids ) {
				return '';
			}
			$recipe_id = $ids[0];
		}
		if ( 'publish' !== get_post_status( $recipe_id ) ) {
			return '';
		}
		$label = '' !== trim( wp_strip_all_tags( $label ) ) ? wp_strip_all_tags( $label ) : __( 'Jump to Recipe', 'scrumptious' );
		$html  = '<a class="swp-button swp-button--jump" href="#swp-recipe-' . (int) $recipe_id . '">' . esc_html( $label ) . '</a>';
		if ( $show_print ) {
			$html .= '<a class="swp-button swp-button--print" href="' . esc_url( Print_View::url( $recipe_id ) ) . '" target="_blank" rel="nofollow noopener">' . esc_html__( 'Print Recipe', 'scrumptious' ) . '</a>';
		}
		$wrapper = '' !== $wrapper ? $wrapper : 'class="swp-jump"';
		return '<div ' . $wrapper . '>' . $html . '</div>';
	}

	/**
	 * Which recipe a block should show: explicit ID, the current recipe (templates / query loops),
	 * or the first recipe in the current post.
	 *
	 * @param array<string,mixed> $attributes Attributes.
	 * @param \WP_Block|null      $block      Block.
	 * @return int
	 */
	public static function resolve_recipe( $attributes, $block ) {
		$id = isset( $attributes['recipeId'] ) ? absint( $attributes['recipeId'] ) : 0;
		if ( $id ) {
			return $id;
		}
		if ( $block instanceof \WP_Block && isset( $block->context['postType'], $block->context['postId'] ) && Post_Type::SLUG === $block->context['postType'] ) {
			return absint( $block->context['postId'] );
		}
		$post = get_post();
		if ( ! $post ) {
			return 0;
		}
		if ( Post_Type::SLUG === $post->post_type ) {
			return (int) $post->ID;
		}
		$ids = Usage::ordered_ids( $post->post_content );
		return $ids ? $ids[0] : 0;
	}

	/**
	 * Recipe Part block.
	 *
	 * @param array<string,mixed> $attributes Attributes.
	 * @param string              $content    Unused.
	 * @param \WP_Block|null      $block      Block.
	 * @return string
	 */
	public static function render_part( $attributes, $content = '', $block = null ) {
		$part = isset( $attributes['part'] ) ? sanitize_key( $attributes['part'] ) : 'ingredients';
		$html = Renderer::render_part( $part, self::resolve_recipe( $attributes, $block ) );
		return '' !== $html ? '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>' : '';
	}

	/**
	 * Recipe Grid block.
	 *
	 * @param array<string,mixed> $attributes Attributes.
	 * @return string
	 */
	public static function render_grid( $attributes ) {
		Renderer::register_style();
		wp_enqueue_style( Renderer::STYLE_HANDLE );

		$a = wp_parse_args(
			$attributes,
			array(
				'course'     => 0,
				'cuisine'    => 0,
				'diet'       => 0,
				'count'      => 6,
				'columns'    => 3,
				'orderBy'    => 'date',
				'showRating' => true,
				'showTime'   => true,
			)
		);

		$tax = array();
		foreach ( array(
			'course'  => 'scrumptious_course',
			'cuisine' => 'scrumptious_cuisine',
			'diet'    => 'scrumptious_diet',
		) as $key => $taxonomy ) {
			if ( absint( $a[ $key ] ) ) {
				$tax[] = array(
					'taxonomy' => $taxonomy,
					'terms'    => array( absint( $a[ $key ] ) ),
				);
			}
		}

		$args = array(
			'post_type'           => Post_Type::SLUG,
			'post_status'         => 'publish',
			'posts_per_page'      => max( 1, min( 24, absint( $a['count'] ) ) ) * 2, // Headroom: recipes with no page to link to are skipped.
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		);
		if ( $tax ) {
			$args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- filtered listing.
		}
		switch ( $a['orderBy'] ) {
			case 'rating':
				$args['meta_key'] = Ratings::META_AVG; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- sort by cached rating.
				$args['orderby']  = array(
					'meta_value_num' => 'DESC',
					'date'           => 'DESC',
				);
				break;
			case 'title':
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
				break;
			case 'rand':
				$args['orderby'] = 'rand';
				break;
		}

		/**
		 * Filter the Recipe Grid query (WP_Query arguments).
		 *
		 * @param array<string,mixed> $args       Query arguments.
		 * @param array<string,mixed> $attributes Block attributes.
		 */
		$args = (array) apply_filters( 'scrumptious_grid_query_args', $args, $a );

		$items = array();
		foreach ( get_posts( $args ) as $recipe_post ) {
			// Link to the primary page: the recipe page when public pages are on, else the post that shows it.
			$link = Recipe_Pages::primary_url( $recipe_post->ID );
			if ( ! $link ) {
				continue;
			}
			$items[] = array( $recipe_post, $link );
			if ( count( $items ) >= absint( $a['count'] ) ) {
				break;
			}
		}

		if ( ! $items ) {
			return '<div ' . get_block_wrapper_attributes( array( 'class' => 'swp-grid swp-grid--empty' ) ) . '><p>' . esc_html__( 'No recipes to show yet.', 'scrumptious' ) . '</p></div>';
		}

		$out = '';
		foreach ( $items as list( $recipe_post, $link ) ) {
			$out .= self::grid_item_html( $recipe_post, $link, (bool) $a['showRating'], (bool) $a['showTime'] );
		}

		$columns = max( 1, min( 6, absint( $a['columns'] ) ) );
		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'swp-grid' ) ) . '><ul class="swp-grid__list" style="--swp-columns:' . $columns . '">' . $out . '</ul></div>';
	}

	/**
	 * One Recipe Grid item (also used by the recipe index on classic themes).
	 *
	 * @param \WP_Post $recipe_post Recipe post.
	 * @param string   $link        URL the item links to.
	 * @param bool     $show_rating Show the star rating.
	 * @param bool     $show_time   Show the total time.
	 * @return string
	 */
	public static function grid_item_html( $recipe_post, $link, $show_rating = true, $show_time = true ) {
		$recipe = Recipe::get( $recipe_post->ID );
		$meta   = '';
		if ( $show_rating && Settings::get( 'ratings' ) ) {
			$r = Ratings::get( $recipe_post->ID );
			if ( $r['count'] ) {
				/* translators: 1: average rating, 2: number of ratings */
				$label = sprintf( __( 'Rated %1$s out of 5 from %2$d ratings', 'scrumptious' ), $r['average'], $r['count'] );
				$meta .= '<span class="swp-rating swp-grid__rating" style="--swp-rating:' . esc_attr( (string) $r['average'] ) . '"><span class="swp-rating__stars" role="img" aria-label="' . esc_attr( $label ) . '"></span><span>(' . (int) $r['count'] . ')</span></span>';
			}
		}
		if ( $show_time && $recipe['times']['total'] ) {
			$meta .= '<span class="swp-grid__time">' . esc_html( Duration::human( $recipe['times']['total'] ) ) . '</span>';
		}
		$image = get_the_post_thumbnail(
			$recipe_post,
			'swp-4x3',
			array(
				'loading' => 'lazy',
				'sizes'   => '(max-width: 600px) 100vw, 400px',
			)
		);
		return '<li class="swp-grid__item"><a class="swp-grid__link" href="' . esc_url( $link ) . '">'
			. ( $image ? '<span class="swp-grid__image">' . $image . '</span>' : '<span class="swp-grid__image swp-grid__image--empty" aria-hidden="true"></span>' )
			. '<span class="swp-grid__title">' . esc_html( get_the_title( $recipe_post ) ) . '</span>'
			. ( '' !== $meta ? '<span class="swp-grid__meta">' . $meta . '</span>' : '' )
			. '</a></li>';
	}

	/**
	 * Jump block render callback.
	 *
	 * @param array<string,mixed> $attributes Attributes.
	 * @return string
	 */
	public static function render_jump( $attributes ) {
		Renderer::register_style();
		wp_enqueue_style( Renderer::STYLE_HANDLE );
		return self::jump_html(
			isset( $attributes['recipeId'] ) ? absint( $attributes['recipeId'] ) : 0,
			isset( $attributes['label'] ) ? (string) $attributes['label'] : '',
			! isset( $attributes['showPrint'] ) || (bool) $attributes['showPrint'],
			get_block_wrapper_attributes( array( 'class' => 'swp-jump' ) )
		);
	}

	/**
	 * [scrumptious-jump label="" print="1"] — classic-editor fallback for the Jump to Recipe block.
	 *
	 * @param array<string,string>|string $atts Attributes.
	 * @return string
	 */
	public static function render_jump_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'label' => '',
				'print' => '1',
			),
			is_array( $atts ) ? $atts : array(),
			'scrumptious-jump'
		);
		Renderer::register_style();
		wp_enqueue_style( Renderer::STYLE_HANDLE );
		return self::jump_html( absint( $atts['id'] ), sanitize_text_field( $atts['label'] ), '0' !== (string) $atts['print'] );
	}

	/**
	 * Optional setting: add Jump to Recipe above the content of posts that have a recipe
	 * (skipped when the post already contains a Jump block).
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function auto_jump( $content ) {
		if ( ! Settings::get( 'auto_jump' ) || ! is_singular() || ! in_the_loop() || ! is_main_query() || is_singular( Post_Type::SLUG ) ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || has_block( 'scrumptious/jump', $post ) || has_shortcode( $post->post_content, 'scrumptious-jump' ) ) {
			return $content;
		}
		return self::jump_html( 0, '', (bool) Settings::get( 'show_print' ) ) . $content;
	}

	/**
	 * Block render callback.
	 *
	 * On a recipe's own screen/page the block carries no recipeId and binds to
	 * the surrounding post (block context postId).
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content    Inner content (unused).
	 * @param \WP_Block|null      $block      Block instance.
	 * @return string
	 */
	public static function render_block( $attributes, $content = '', $block = null ) {
		$id = isset( $attributes['recipeId'] ) ? absint( $attributes['recipeId'] ) : 0;
		if ( ! $id && $block instanceof \WP_Block && isset( $block->context['postType'], $block->context['postId'] ) && Post_Type::SLUG === $block->context['postType'] ) {
			$id = absint( $block->context['postId'] );
		}
		if ( ! $id && Post_Type::SLUG === get_post_type() ) {
			$id = (int) get_the_ID();
		}
		if ( ! $id ) {
			return '';
		}
		$wrapper = get_block_wrapper_attributes( array( 'class' => 'wp-block-scrumptious-recipe' ) );
		$html    = Renderer::render(
			$id,
			array(
				'style'    => isset( $attributes['cardStyle'] ) ? (string) $attributes['cardStyle'] : '',
				'sections' => isset( $attributes['sections'] ) && is_array( $attributes['sections'] ) ? $attributes['sections'] : array(),
				'print'    => isset( $attributes['showPrint'] ) && 'default' !== $attributes['showPrint'] ? 'show' === $attributes['showPrint'] : null,
			)
		);
		return '' !== $html ? '<div ' . $wrapper . '>' . $html . '</div>' : '';
	}

	/**
	 * [scrumptious id="123"] for Classic editor, Elementor, Divi, etc.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'scrumptious' );
		return Renderer::render( absint( $atts['id'] ) );
	}
}
