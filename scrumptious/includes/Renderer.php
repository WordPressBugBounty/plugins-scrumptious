<?php
/**
 * Front-end rendering: recipe card HTML, CSS (inlined, only on recipe pages) and JSON-LD.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Renders recipe cards, styles and JSON-LD on the front end.
 */
final class Renderer {

	const STYLE_HANDLE  = 'scrumptious-card';
	const SCRIPT_HANDLE = 'scrumptious-card';

	/**
	 * JSON-LD nodes collected during render, keyed by recipe ID.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static $schema = array();

	/**
	 * Hook up front-end output.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_schema' ), 5 );
		add_image_size( 'swp-1x1', 1200, 1200, true );
		add_image_size( 'swp-4x3', 1200, 900, true );
		add_image_size( 'swp-16x9', 1200, 675, true );
	}

	/**
	 * Register the (inline) style. Zero extra HTTP requests.
	 */
	public static function register_style() {
		if ( wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			return;
		}
		wp_register_style( self::STYLE_HANDLE, false, array(), SCRUMPTIOUS_VERSION );
		$css_file = SCRUMPTIOUS_DIR . 'assets/css/card.css';
		if ( is_readable( $css_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
			wp_add_inline_style( self::STYLE_HANDLE, (string) file_get_contents( $css_file ) );
		}
		$accent = Settings::get( 'accent_color' );
		if ( $accent && '#e11d74' !== strtolower( $accent ) ) {
			wp_add_inline_style( self::STYLE_HANDLE, '.swp-card,.swp-jump,.swp-grid,.swp-index,.swp-editor{--swp-accent:' . $accent . '}' );
		}
	}

	/**
	 * Enqueue in <head> only when the current singular post contains a recipe.
	 */
	public static function maybe_enqueue() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		if ( Post_Type::SLUG === $post->post_type || has_block( 'scrumptious/recipe', $post ) || has_block( 'scrumptious/jump', $post ) || has_block( 'scrumptious/recipe-grid', $post ) || has_block( 'scrumptious/recipe-part', $post ) || has_shortcode( $post->post_content, 'scrumptious' ) || has_shortcode( $post->post_content, 'scrumptious-jump' ) ) {
			self::register_style();
			wp_enqueue_style( self::STYLE_HANDLE );
		}
	}

	/**
	 * Render a recipe card.
	 *
	 * @param int                 $recipe_id Recipe post ID.
	 * @param array<string,mixed> $args      Options (reserved: 'align', 'class').
	 * @return string
	 */
	public static function render( $recipe_id, array $args = array() ) {
		$prepared = self::prepare( $recipe_id, ! empty( $args['is_print'] ) );
		if ( null === $prepared ) {
			return '';
		}
		list( $post, $recipe, $ctx, $features, $rating ) = $prepared;
		$recipe_id                                       = (int) $post->ID;

		$template  = self::locate_template( 'recipe-card.php' );
		$title     = get_the_title( $post );
		$style     = self::resolve_style( isset( $args['style'] ) ? (string) $args['style'] : '' );
		$print     = isset( $args['print'] ) && '' !== $args['print'] && null !== $args['print'] ? (bool) $args['print'] : (bool) Settings::get( 'show_print' );
		$print_url = $print && empty( $args['is_print'] ) && 'publish' === $post->post_status ? Print_View::url( $recipe_id ) : '';

		/**
		 * Filter which card sections are shown (image, summary, times, notes, nutrition).
		 *
		 * @param array<string,bool>  $show      Section visibility.
		 * @param int                 $recipe_id Recipe ID.
		 * @param array<string,mixed> $args      Render arguments (block attributes).
		 */
		$show = (array) apply_filters( 'scrumptious_card_sections', self::resolve_sections( isset( $args['sections'] ) && is_array( $args['sections'] ) ? $args['sections'] : array() ), $recipe_id, $args );

		/**
		 * Filter extra CSS classes on the card wrapper (the base swp-card classes are always added).
		 *
		 * @param string[]            $classes   Extra classes.
		 * @param int                 $recipe_id Recipe ID.
		 * @param array<string,mixed> $args      Render arguments.
		 */
		$classes = (array) apply_filters( 'scrumptious_card_classes', array_filter( explode( ' ', isset( $args['class'] ) ? (string) $args['class'] : '' ) ), $recipe_id, $args );
		$class   = implode( ' ', array_map( 'sanitize_html_class', $classes ) );

		ob_start();
		/**
		 * Fires before the recipe card markup.
		 *
		 * @param int                 $recipe_id Recipe ID.
		 * @param array<string,mixed> $recipe    Recipe data.
		 * @param array<string,mixed> $args      Render arguments.
		 */
		do_action( 'scrumptious_before_card', $recipe_id, $recipe, $args );
		// The template receives the recipe, its context, title, CSS classes, visible sections, style and print URL.
		include $template;
		/**
		 * Fires after the recipe card markup.
		 *
		 * @param int                 $recipe_id Recipe ID.
		 * @param array<string,mixed> $recipe    Recipe data.
		 * @param array<string,mixed> $args      Render arguments.
		 */
		do_action( 'scrumptious_after_card', $recipe_id, $recipe, $args );
		$html = (string) ob_get_clean();

		/**
		 * Filter the final card HTML.
		 *
		 * @param string              $html      Card HTML.
		 * @param int                 $recipe_id Recipe ID.
		 * @param array<string,mixed> $recipe    Recipe data.
		 */
		return (string) apply_filters( 'scrumptious_card_html', $html, $recipe_id, $recipe );
	}

	/**
	 * Load a recipe for display: access check, assets, schema collection.
	 *
	 * @param int  $recipe_id Recipe ID.
	 * @param bool $is_print  Print view (no scripts, no rating widget).
	 * @return array{0:\WP_Post,1:array<string,mixed>,2:array<string,mixed>,3:string[],4:?array}|null
	 */
	public static function prepare( $recipe_id, $is_print = false ) {
		$recipe_id = absint( $recipe_id );
		$post      = $recipe_id ? get_post( $recipe_id ) : null;

		if ( ! $post || Post_Type::SLUG !== $post->post_type ) {
			return null;
		}
		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $recipe_id ) ) {
			return null;
		}
		if ( post_password_required( $post ) && ! current_user_can( 'edit_post', $recipe_id ) ) {
			return null;
		}

		self::register_style();
		wp_enqueue_style( self::STYLE_HANDLE ); // Late fallback (e.g. widgets, builders).

		$features = array();
		if ( ! $is_print ) {
			$features = array_keys(
				array_filter(
					array(
						'scale' => Settings::get( 'scaling' ),
						'units' => Settings::get( 'units' ),
						'cook'  => Settings::get( 'cook_mode' ),
						'rate'  => Settings::get( 'ratings' ),
					)
				)
			);
			/**
			 * Filter the reader features enabled on a card: scale, units, cook, rate.
			 * Return an empty array to load no JavaScript for this recipe.
			 *
			 * @param string[] $features  Feature keys.
			 * @param int      $recipe_id Recipe ID.
			 */
			$features = array_values( array_intersect( (array) apply_filters( 'scrumptious_reader_features', $features, $recipe_id ), array( 'scale', 'units', 'cook', 'rate' ) ) );
			if ( $features ) {
				self::enqueue_script();
			}
		}
		$rating = Settings::get( 'ratings' ) && ! $is_print ? Ratings::get( $recipe_id ) : null;

		$recipe = Recipe::get( $recipe_id );
		if ( ! $is_print && ( $recipe['gallery'] || ( Settings::show( 'share' ) && Settings::get( 'share_networks' ) ) ) ) {
			self::enqueue_extras(); // Gallery lightbox, share buttons.
		}
		$recipe['ingredient_groups']  = Recipe::filled( $recipe['ingredient_groups'], 'items' );
		$recipe['instruction_groups'] = Recipe::filled( $recipe['instruction_groups'], 'steps' );

		/**
		 * Filter recipe data before it is displayed (card, parts, schema). Stored data is unchanged.
		 *
		 * @param array<string,mixed> $recipe Normalized recipe data.
		 * @param \WP_Post           $post   Recipe post.
		 */
		$recipe = (array) apply_filters( 'scrumptious_recipe_data', $recipe, $post );
		$ctx    = self::context( $post, $recipe );

		if ( self::should_output_schema( $post ) && ! isset( self::$schema[ $recipe_id ] ) ) {
			/**
			 * Filter the Recipe JSON-LD node before output.
			 *
			 * @param array   $node   Schema node.
			 * @param array   $recipe Normalized recipe data.
			 * @param WP_Post $post   Recipe post.
			 */
			self::$schema[ $recipe_id ] = apply_filters( 'scrumptious_schema', Schema::build( $recipe, $ctx ), $recipe, $post );
		}

		return array( $post, $recipe, $ctx, $features, $rating );
	}

	/**
	 * Parts available as standalone blocks, and the reader features each one uses.
	 */
	const PARTS = array(
		'summary'      => array(),
		'rating'       => array( 'rate' ),
		'meta'         => array(),
		'ingredients'  => array( 'scale', 'units' ),
		'instructions' => array(),
		'notes'        => array(),
		'gallery'      => array(),
		'share'        => array(),
		'nutrition'    => array(),
		'actions'      => array( 'cook' ),
	);

	/**
	 * Include one part template with the given variables.
	 *
	 * @param string              $part Part name.
	 * @param array<string,mixed> $vars Variables (recipe, recipe_id, rating, print_url).
	 */
	public static function part( $part, array $vars ) {
		$file = self::locate_template( 'parts/' . sanitize_key( $part ) . '.php' );
		if ( ! is_readable( $file ) ) {
			return;
		}
		$recipe    = isset( $vars['recipe'] ) ? $vars['recipe'] : array();
		$recipe_id = isset( $vars['recipe_id'] ) ? (int) $vars['recipe_id'] : 0;
		$rating    = isset( $vars['rating'] ) ? $vars['rating'] : null;
		$print_url = isset( $vars['print_url'] ) ? (string) $vars['print_url'] : '';
		// Where a part sits: 'top' / 'bottom' for share buttons on the card, '' elsewhere (e.g. part blocks).
		$share_position = isset( $vars['share_position'] ) ? (string) $vars['share_position'] : '';
		/**
		 * Fires before a card section (summary, rating, meta, ingredients, instructions, notes, nutrition, actions).
		 * Also fires the part-specific hook "scrumptious_before_part_{$part}".
		 *
		 * @param string              $part      Part name.
		 * @param int                 $recipe_id Recipe ID.
		 * @param array<string,mixed> $recipe    Recipe data.
		 */
		do_action( 'scrumptious_before_part', $part, $recipe_id, $recipe );
		do_action( "scrumptious_before_part_{$part}", $recipe_id, $recipe );
		include $file;
		// After-part counterparts: "scrumptious_after_part_{$part}" and "scrumptious_after_part" (same arguments).
		do_action( "scrumptious_after_part_{$part}", $recipe_id, $recipe );
		do_action( 'scrumptious_after_part', $part, $recipe_id, $recipe );
	}

	/**
	 * Render one recipe part on its own (for the part blocks and templates).
	 *
	 * @param string $part      Part name.
	 * @param int    $recipe_id Recipe ID.
	 * @return string
	 */
	public static function render_part( $part, $recipe_id ) {
		if ( ! isset( self::PARTS[ $part ] ) ) {
			return '';
		}
		$prepared = self::prepare( $recipe_id );
		if ( null === $prepared ) {
			return '';
		}
		list( $post, $recipe, , $features, $rating ) = $prepared;
		$recipe_id                                   = (int) $post->ID;

		$vars = array(
			'recipe'    => $recipe,
			'recipe_id' => $recipe_id,
			'rating'    => $rating,
			'print_url' => Settings::get( 'show_print' ) && 'publish' === $post->post_status ? Print_View::url( $recipe_id ) : '',
		);

		$empty = array(
			'summary'      => '' === $recipe['summary'],
			'rating'       => null === $rating,
			'meta'         => ! array_filter( $recipe['times'] ) && ! $recipe['servings'],
			'ingredients'  => ! $recipe['ingredient_groups'],
			'instructions' => ! $recipe['instruction_groups'],
			'notes'        => '' === $recipe['notes'],
			'nutrition'    => ! self::has_nutrition( $recipe ),
			'gallery'      => empty( $recipe['gallery'] ),
			'share'        => ! self::share_links( $recipe_id ),
			'actions'      => '' === $vars['print_url'] && ! in_array( 'cook', $features, true ),
		);
		if ( $empty[ $part ] ) {
			return '';
		}

		$part_features = array_values( array_intersect( self::PARTS[ $part ], $features ) );
		ob_start();
		self::part( $part, $vars );
		$html = (string) ob_get_clean();

		return sprintf(
			'<div class="swp-card swp-part swp-part--%1$s" data-recipe-id="%2$d" data-servings="%3$s" data-features="%4$s">%5$s</div>',
			esc_attr( $part ),
			$recipe_id,
			esc_attr( (string) $recipe['servings'] ),
			esc_attr( implode( ' ', $part_features ) ),
			$html
		);
	}

	/**
	 * Whether a recipe has any displayable nutrition values.
	 *
	 * @param array<string,mixed> $recipe Recipe.
	 * @return bool
	 */
	public static function has_nutrition( array $recipe ) {
		$n = $recipe['nutrition'];
		unset( $n['serving_size'] );
		return count( array_filter( $n, 'strlen' ) ) > 0;
	}

	/**
	 * Small extra script for the gallery lightbox and share buttons.
	 */
	public static function enqueue_extras() {
		if ( wp_script_is( 'scrumptious-extras', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_script(
			'scrumptious-extras',
			SCRUMPTIOUS_URL . 'assets/js/extras.js',
			array(),
			SCRUMPTIOUS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		$config = array(
			'i18n' => array(
				'gallery'    => __( 'Gallery', 'scrumptious' ),
				'prev'       => __( 'Previous photo', 'scrumptious' ),
				'next'       => __( 'Next photo', 'scrumptious' ),
				'close'      => __( 'Close', 'scrumptious' ),
				'copied'     => __( 'Link copied!', 'scrumptious' ),
				'copyPrompt' => __( 'Copy this link:', 'scrumptious' ),
			),
		);
		wp_add_inline_script( 'scrumptious-extras', 'window.scrumptiousExtras=' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Share links for a recipe (no third-party scripts: plain links to each network).
	 *
	 * @param int $recipe_id Recipe ID.
	 * @return array{url:string,title:string,links:array<string,array{label:string,href:string}>,native:bool,copy:bool}|array{}
	 */
	public static function share_links( $recipe_id ) {
		$networks = (array) Settings::get( 'share_networks' );
		if ( ! $networks ) {
			return array();
		}
		$url = Recipe_Pages::primary_url( $recipe_id );
		if ( '' === $url ) {
			$url = (string) get_permalink();
		}
		if ( '' === $url ) {
			return array();
		}
		$title = html_entity_decode( get_the_title( $recipe_id ), ENT_QUOTES, 'UTF-8' );
		$image = (string) get_the_post_thumbnail_url( $recipe_id, 'full' );
		$e     = 'rawurlencode';
		$all   = array(
			'pinterest' => array(
				'label' => __( 'Pin it', 'scrumptious' ),
				'href'  => 'https://www.pinterest.com/pin/create/button/?url=' . $e( $url ) . ( $image ? '&media=' . $e( $image ) : '' ) . '&description=' . $e( $title ),
			),
			'facebook'  => array(
				'label' => __( 'Facebook', 'scrumptious' ),
				'href'  => 'https://www.facebook.com/sharer/sharer.php?u=' . $e( $url ),
			),
			'x'         => array(
				'label' => __( 'X', 'scrumptious' ),
				'href'  => 'https://twitter.com/intent/tweet?url=' . $e( $url ) . '&text=' . $e( $title ),
			),
			'whatsapp'  => array(
				'label' => __( 'WhatsApp', 'scrumptious' ),
				'href'  => 'https://wa.me/?text=' . $e( $title . ' ' . $url ),
			),
			'email'     => array(
				'label' => __( 'Email', 'scrumptious' ),
				'href'  => 'mailto:?subject=' . $e( $title ) . '&body=' . $e( $url ),
			),
		);
		$links = array();
		foreach ( $networks as $n ) {
			if ( isset( $all[ $n ] ) ) {
				$links[ $n ] = $all[ $n ];
			}
		}
		$data = array(
			'url'    => $url,
			'title'  => $title,
			'links'  => $links,
			'native' => true,
			'copy'   => in_array( 'copy', $networks, true ),
		);
		/**
		 * Filter a recipe's share links (add networks, change labels or URLs).
		 *
		 * @param array $data      { url, title, links: { network: { label, href } }, native, copy }.
		 * @param int   $recipe_id Recipe ID.
		 */
		return (array) apply_filters( 'scrumptious_share_links', $data, (int) $recipe_id );
	}

	/**
	 * Front-end script (deferred, footer, only when a card renders).
	 */
	public static function enqueue_script() {
		if ( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
			return;
		}
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			SCRUMPTIOUS_URL . 'assets/js/card.js',
			array(),
			SCRUMPTIOUS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		$config = array(
			'rest' => esc_url_raw( rest_url( 'scrumptious/v1/rate' ) ),
			'i18n' => array(
				'scale'    => __( 'Scale recipe', 'scrumptious' ),
				'fewer'    => __( 'Fewer servings', 'scrumptious' ),
				'more'     => __( 'More servings', 'scrumptious' ),
				'units'    => __( 'Units', 'scrumptious' ),
				'us'       => __( 'US', 'scrumptious' ),
				'metric'   => __( 'Metric', 'scrumptious' ),
				'cook'     => __( 'Cook mode', 'scrumptious' ),
				'cookHelp' => __( 'Keeps your screen on while you cook', 'scrumptious' ),
				'thanks'   => __( 'Thanks for rating!', 'scrumptious' ),
				'error'    => __( 'Could not save your rating.', 'scrumptious' ),
			),
		);
		wp_add_inline_script( self::SCRIPT_HANDLE, 'window.scrumptious=' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Card style: block override, else the site default.
	 *
	 * @param string $style Requested style.
	 * @return string
	 */
	public static function resolve_style( $style ) {
		return in_array( $style, Settings::STYLES, true ) ? $style : (string) Settings::get( 'card_style' );
	}

	/**
	 * Section visibility: per-block 'show'/'hide' overrides on top of site defaults.
	 *
	 * @param array<string,string> $overrides Section => 'show' | 'hide' | 'default'.
	 * @return array<string,bool>
	 */
	public static function resolve_sections( array $overrides ) {
		$out = array();
		foreach ( Settings::SECTIONS as $section ) {
			$o               = isset( $overrides[ $section ] ) ? $overrides[ $section ] : 'default';
			$out[ $section ] = 'show' === $o ? true : ( 'hide' === $o ? false : Settings::show( $section ) );
		}
		return $out;
	}

	/**
	 * Theme-overridable template lookup: {theme}/scrumptious/{name}.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public static function locate_template( $name ) {
		$theme = locate_template( array( 'scrumptious/' . $name ) );
		$path  = $theme ? $theme : SCRUMPTIOUS_DIR . 'templates/' . $name;
		return (string) apply_filters( 'scrumptious_template', $path, $name );
	}

	/**
	 * Print collected JSON-LD in the footer.
	 */
	public static function print_schema() {
		if ( ! self::$schema || ! apply_filters( 'scrumptious_output_schema', true ) ) {
			return;
		}
		foreach ( self::$schema as $node ) {
			$json = wp_json_encode( $node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
			if ( $json ) {
				echo "\n" . '<script type="application/ld+json" class="scrumptious-schema">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with HEX_TAG.
			}
		}
		self::$schema = array();
	}

	/**
	 * Collected schema (for tests / integrations like Yoast graph merging).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function collected_schema() {
		return self::$schema;
	}

	/**
	 * Only on real front-end singular views (not feeds, REST, admin, excerpts in archives).
	 *
	 * @param \WP_Post|null $post Recipe being rendered.
	 * @return bool
	 */
	private static function should_output_schema( $post = null ) {
		if ( ! Settings::get( 'output_schema' ) || is_admin() || is_feed() || wp_is_json_request() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		// With public recipe pages on, the recipe page is the primary page: recipe data is
		// output there only, never on blog posts that also show the card.
		if ( Recipe_Pages::enabled() ) {
			return $post && is_singular( Post_Type::SLUG ) && (int) get_queried_object_id() === (int) $post->ID;
		}
		return is_singular();
	}

	/**
	 * Build schema/template context.
	 *
	 * @param \WP_Post            $post   Recipe post.
	 * @param array<string,mixed> $recipe Recipe data.
	 * @return array<string,mixed>
	 */
	private static function context( $post, array $recipe ) {
		// The primary page for this recipe: its own public page when public pages are on,
		// otherwise the blog post that shows it.
		if ( Recipe_Pages::enabled() ) {
			$host = $post;
		} else {
			$host = get_post();
			if ( ! $host || Post_Type::SLUG === $host->post_type ) {
				$host = Usage::primary_host( $post->ID );
			}
		}

		$image_id = (int) get_post_thumbnail_id( $post );
		if ( ! $image_id && $host ) {
			$image_id = (int) get_post_thumbnail_id( $host );
		}

		$images = array();
		if ( $image_id ) {
			foreach ( array( 'swp-1x1', 'swp-4x3', 'swp-16x9', 'full' ) as $size ) {
				$src = wp_get_attachment_image_src( $image_id, $size );
				if ( $src && ( 'full' === $size || ! empty( $src[3] ) ) ) {
					$images[] = $src[0];
				}
			}
		}
		// Extra photos of the finished dish (first five gallery images).
		foreach ( array_slice( isset( $recipe['gallery'] ) ? (array) $recipe['gallery'] : array(), 0, 5 ) as $gid ) {
			$url = wp_get_attachment_image_url( (int) $gid, 'full' );
			if ( $url ) {
				$images[] = $url;
			}
		}

		$step_images = array();
		foreach ( $recipe['instruction_groups'] as $g => $group ) {
			foreach ( $group['steps'] as $s => $step ) {
				if ( $step['image_id'] ) {
					$url = wp_get_attachment_image_url( $step['image_id'], 'large' );
					if ( $url ) {
						$step_images[ $g ][ $s ] = $url;
					}
				}
			}
		}

		$terms = array();
		foreach ( Post_Type::TAXONOMIES as $tax ) {
			$list          = get_the_terms( $post, $tax );
			$terms[ $tax ] = is_array( $list ) ? wp_list_pluck( $list, 'name' ) : array();
		}

		$date = $host ? get_post_time( 'c', true, $host ) : get_post_time( 'c', true, $post );

		return array(
			'id'             => (int) $post->ID,
			'name'           => get_the_title( $post ),
			'url'            => ( Recipe_Pages::enabled() || ! $host ) ? Recipe_Pages::primary_url( $post->ID ) : (string) get_permalink( $host ),
			'images'         => $images,
			'image_id'       => $image_id,
			'step_images'    => $step_images,
			'author'         => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			'date_published' => $date ? (string) $date : '',
			'category'       => $terms['scrumptious_course'],
			'cuisine'        => $terms['scrumptious_cuisine'],
			'diet'           => $terms['scrumptious_diet'],
			'rating'         => array(
				'value' => (float) get_post_meta( $post->ID, '_scrumptious_rating_avg', true ),
				'count' => (int) get_post_meta( $post->ID, '_scrumptious_rating_count', true ),
			),
		);
	}
}
