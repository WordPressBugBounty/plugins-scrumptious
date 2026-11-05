<?php
/**
 * Recipe part: times & servings. Used by the Recipe Card and the recipe part blocks.
 *
 * Override by copying to {your-theme}/scrumptious/parts/meta.php.
 *
 * @var array $recipe    Normalized recipe data.
 * @var int   $recipe_id Recipe post ID.
 *
 * @package Scrumptious
 */

use Scrumptious\Duration;

defined( 'ABSPATH' ) || exit;

// Included from Renderer, so these variables are function-scoped, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>
<?php
$scrumptious_times = array(
	'prep'  => __( 'Prep', 'scrumptious' ),
	'cook'  => __( 'Cook', 'scrumptious' ),
	'rest'  => __( 'Rest', 'scrumptious' ),
	'total' => __( 'Total', 'scrumptious' ),
);
?>
	<dl class="swp-card__meta">
		<?php foreach ( $scrumptious_times as $scrumptious_key => $scrumptious_label ) : ?>
			<?php if ( $recipe['times'][ $scrumptious_key ] > 0 ) : ?>
				<div class="swp-card__meta-item swp-card__meta-item--<?php echo esc_attr( $scrumptious_key ); ?>">
					<dt><?php echo esc_html( $scrumptious_label ); ?></dt>
					<dd><time datetime="<?php echo esc_attr( Duration::to_iso( $recipe['times'][ $scrumptious_key ] ) ); ?>"><?php echo esc_html( Duration::human( $recipe['times'][ $scrumptious_key ] ) ); ?></time></dd>
				</div>
			<?php endif; ?>
		<?php endforeach; ?>
		<?php if ( $recipe['servings'] ) : ?>
			<div class="swp-card__meta-item swp-card__meta-item--servings">
				<dt><?php esc_html_e( 'Servings', 'scrumptious' ); ?></dt>
				<dd><span class="swp-servings"><?php echo esc_html( (string) $recipe['servings'] ); ?></span> <?php echo esc_html( $recipe['servings_unit'] ); ?></dd>
			</div>
		<?php endif; ?>
	</dl>
