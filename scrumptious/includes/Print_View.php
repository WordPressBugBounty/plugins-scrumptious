<?php
/**
 * Clean, theme-free print view: /?scrumptious_print=ID
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a standalone printable recipe page (no theme, no ads, noindex).
 */
final class Print_View {

	const QUERY_VAR = 'scrumptious_print';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
	}

	/**
	 * Print URL for a recipe.
	 *
	 * @param int $recipe_id Recipe ID.
	 * @return string
	 */
	public static function url( $recipe_id ) {
		return add_query_arg( self::QUERY_VAR, (int) $recipe_id, home_url( '/' ) );
	}

	/**
	 * Register the query var.
	 *
	 * @param string[] $vars Vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Output the print page and stop.
	 */
	public static function maybe_render() {
		$id = absint( get_query_var( self::QUERY_VAR ) );
		if ( ! $id ) {
			return;
		}

		$card = Renderer::render(
			$id,
			array(
				'style'    => 'full',
				'is_print' => true,
				'print'    => false,
			)
		);
		if ( '' === $card ) {
			status_header( 404 );
			nocache_headers();
			return;
		}

		status_header( 200 );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$source = Recipe_Pages::primary_url( $id );
		$source = '' !== $source ? $source : home_url( '/' );
		$accent = (string) Settings::get( 'accent_color' );

		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( get_the_title( $id ) . ' – ' . get_bloginfo( 'name' ) ); ?></title>
		<?php
		wp_register_style( 'scrumptious-print', SCRUMPTIOUS_URL . 'assets/css/print.css', array( Renderer::STYLE_HANDLE ), SCRUMPTIOUS_VERSION );
		$scrumptious_accent = (string) sanitize_hex_color( $accent );
		if ( '' !== $scrumptious_accent ) {
			wp_add_inline_style( 'scrumptious-print', '.swp-print,.swp-print .swp-card{--swp-accent:' . $scrumptious_accent . '}' );
		}
		wp_print_styles( array( Renderer::STYLE_HANDLE, 'scrumptious-print' ) );
		?>
</head>
<body>
<main class="swp-print" id="swp-print">
	<div class="swp-print__bar" role="toolbar" aria-label="<?php esc_attr_e( 'Print options', 'scrumptious' ); ?>">
		<button type="button" data-print><?php esc_html_e( 'Print', 'scrumptious' ); ?></button>
		<label><input type="checkbox" checked data-toggle="no-images"> <?php esc_html_e( 'Images', 'scrumptious' ); ?></label>
		<label><input type="checkbox" checked data-toggle="no-notes"> <?php esc_html_e( 'Notes', 'scrumptious' ); ?></label>
		<label><input type="checkbox" checked data-toggle="no-nutrition"> <?php esc_html_e( 'Nutrition', 'scrumptious' ); ?></label>
	</div>
		<?php echo $card; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in template. ?>
	<p class="swp-print__source">
		<?php
		/* translators: %s: URL of the recipe */
		printf( esc_html__( 'Recipe from %s', 'scrumptious' ), esc_html( $source ) );
		?>
	</p>
</main>
		<?php
		wp_register_script( 'scrumptious-print', SCRUMPTIOUS_URL . 'assets/js/print.js', array(), SCRUMPTIOUS_VERSION, true );
		wp_print_scripts( array( 'scrumptious-print' ) );
		?>
</body>
</html>
		<?php
		exit;
	}
}
