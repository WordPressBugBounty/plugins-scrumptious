<?php
/**
 * Recipe part: notes. Used by the Recipe Card and the recipe part blocks.
 *
 * Override by copying to {your-theme}/scrumptious/parts/notes.php.
 *
 * @var array $recipe    Normalized recipe data.
 * @var int   $recipe_id Recipe post ID.
 *
 * @package Scrumptious
 */

defined( 'ABSPATH' ) || exit;

// Included from Renderer, so these variables are function-scoped, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>
	<section class="swp-card__section swp-card__notes">
		<h3><?php esc_html_e( 'Notes', 'scrumptious' ); ?></h3>
		<div class="swp-card__notes-text"><?php echo wp_kses_post( wpautop( $recipe['notes'] ) ); ?></div>
	</section>
