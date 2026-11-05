<?php
/**
 * Recipes list screen: useful columns.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Admin list-table columns for recipes.
 */
final class Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		$type = Post_Type::SLUG;
		add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_assets' ) );
		add_action( 'enqueue_block_assets', array( __CLASS__, 'editor_card_style' ) );
	}

	/**
	 * Card styles inside the block editor, so the Preview of a recipe (and the Recipe Grid) look like the front end.
	 */
	public static function editor_card_style() {
		if ( ! is_admin() ) {
			return; // The front end enqueues the card only where a recipe is shown.
		}
		Renderer::register_style();
		wp_enqueue_style( Renderer::STYLE_HANDLE );
	}

	/**
	 * Where a recipe can be viewed: its own page (when public) and every published post using it.
	 *
	 * @param int $recipe_id Recipe ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function view_targets( $recipe_id ) {
		$out = array();
		if ( Recipe_Pages::enabled() && 'publish' === get_post_status( $recipe_id ) ) {
			$out[] = array(
				'id'    => (int) $recipe_id,
				'title' => __( 'Recipe page', 'scrumptious' ),
				'link'  => (string) get_permalink( $recipe_id ),
				'edit'  => '',
				'type'  => 'recipe',
			);
		}
		foreach ( Usage::hosts( $recipe_id ) as $host_id ) {
			$host = get_post( $host_id );
			if ( ! $host || 'trash' === $host->post_status ) {
				continue;
			}
			$link  = 'publish' === $host->post_status ? get_permalink( $host ) : get_preview_post_link( $host );
			$out[] = array(
				'id'     => (int) $host_id,
				'title'  => get_the_title( $host ),
				'link'   => $link ? $link . '#swp-recipe-' . (int) $recipe_id : '',
				'edit'   => (string) get_edit_post_link( $host_id, 'raw' ),
				'type'   => 'post',
				'status' => $host->post_status,
			);
		}
		return $out;
	}

	/**
	 * "View" row actions pointing at the recipe page and/or the posts that show the recipe.
	 *
	 * @param array<string,string> $actions Actions.
	 * @param \WP_Post             $post    Post.
	 * @return array<string,string>
	 */
	public static function row_actions( $actions, $post ) {
		if ( Post_Type::SLUG !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['view'] );
		foreach ( self::view_targets( $post->ID ) as $i => $t ) {
			if ( '' === $t['link'] ) {
				continue;
			}
			$label = 'recipe' === $t['type']
				/* translators: %s: recipe title */
				? __( 'View', 'scrumptious' )
				/* translators: %s: post title */
				: sprintf( __( 'View in “%s”', 'scrumptious' ), wp_trim_words( $t['title'], 5 ) );
			$actions[ 'scrumptious_view_' . $i ] = '<a href="' . esc_url( $t['link'] ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
			if ( $i >= 2 ) {
				break;
			}
		}
		return $actions;
	}

	/**
	 * Recipe-screen-only editor script ("Where this recipe appears" panel).
	 */
	public static function editor_assets() {
		// Paste & Parse needs to know whether AI is set up (never the key itself).
		$provider = Ai::provider();
		wp_add_inline_script(
			'scrumptious-recipe-editor-script',
			'window.scrumptiousEditor = ' . wp_json_encode(
				array(
					'ai'          => Ai::configured(),
					'provider'    => $provider ? Ai::PROVIDERS[ $provider ]['label'] : '',
					'settingsUrl' => current_user_can( 'manage_options' ) ? Settings::url() . '#swp-ai' : '',
					'helpBase'    => Help::url(),
					'proHints'    => Upsell::editor_hints(),
				)
			) . ';',
			'before'
		);

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Post_Type::SLUG !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}
		$asset_file = SCRUMPTIOUS_DIR . 'build/admin/views-panel.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_enqueue_script( 'scrumptious-views-panel', SCRUMPTIOUS_URL . 'build/admin/views-panel.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'scrumptious-views-panel', 'scrumptious' );
	}

	/**
	 * Expose view targets to the recipe editor (read-only).
	 */
	public static function rest_fields() {
		register_rest_field(
			Post_Type::SLUG,
			'scrumptious_views',
			array(
				'get_callback' => static function ( $data ) {
					return current_user_can( 'edit_post', $data['id'] ) ? self::view_targets( (int) $data['id'] ) : array();
				},
				'schema'       => array(
					'type'     => 'array',
					'context'  => array( 'edit' ),
					'readonly' => true,
				),
			)
		);
	}

	/**
	 * Column set.
	 *
	 * @param array<string,string> $cols Columns.
	 * @return array<string,string>
	 */
	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $key => $label ) {
			if ( 'title' === $key ) {
				$out['scrumptious_image'] = '<span class="screen-reader-text">' . esc_html__( 'Image', 'scrumptious' ) . '</span>';
			}
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['scrumptious_time']    = __( 'Total time', 'scrumptious' );
				$out['scrumptious_used_in'] = __( 'Used in', 'scrumptious' );
			}
		}
		return $out;
	}

	/**
	 * Column content.
	 *
	 * @param string $col     Column key.
	 * @param int    $post_id Recipe ID.
	 */
	public static function column( $col, $post_id ) {
		switch ( $col ) {
			case 'scrumptious_image':
				$thumb = get_the_post_thumbnail( $post_id, array( 48, 48 ) );
				echo '' !== $thumb ? $thumb : '<span class="swp-noimg" title="' . esc_attr__( 'No image — Google requires one', 'scrumptious' ) . '">!</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-escaped HTML.
				break;
			case 'scrumptious_time':
				$recipe = Recipe::get( $post_id );
				$human  = Duration::human( $recipe['times']['total'] );
				echo esc_html( '' !== $human ? $human : '—' );
				break;
			case 'scrumptious_used_in':
				$links = array();
				foreach ( Usage::hosts( $post_id ) as $host_id ) {
					if ( 'publish' !== get_post_status( $host_id ) && ! current_user_can( 'edit_post', $host_id ) ) {
						continue;
					}
					$title = get_the_title( $host_id );
					if ( '' === $title ) {
						continue;
					}
					$url     = get_edit_post_link( $host_id );
					$links[] = $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
				}
				echo $links ? implode( ', ', $links ) : '<span aria-hidden="true">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				break;
		}
	}

	/**
	 * Admin styles (Recipes list columns, setup wizard).
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function styles( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ( $screen && Post_Type::SLUG === $screen->post_type ) || false !== strpos( (string) $hook, 'scrumptious' ) ) {
			wp_enqueue_style( 'scrumptious-admin', SCRUMPTIOUS_URL . 'assets/css/admin.css', array(), SCRUMPTIOUS_VERSION );
		}
	}
}
