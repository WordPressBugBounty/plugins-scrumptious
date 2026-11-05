<?php
/**
 * Recipe part: nutrition. Used by the Recipe Card and the recipe part blocks.
 *
 * Override by copying to {your-theme}/scrumptious/parts/nutrition.php.
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
<?php
$scrumptious_nutrition_labels = array(
	'calories'      => array( __( 'Calories', 'scrumptious' ), 'kcal' ),
	'carbohydrates' => array( __( 'Carbs', 'scrumptious' ), 'g' ),
	'protein'       => array( __( 'Protein', 'scrumptious' ), 'g' ),
	'fat'           => array( __( 'Fat', 'scrumptious' ), 'g' ),
	'fiber'         => array( __( 'Fiber', 'scrumptious' ), 'g' ),
	'sugar'         => array( __( 'Sugar', 'scrumptious' ), 'g' ),
	'sodium'        => array( __( 'Sodium', 'scrumptious' ), 'mg' ),
);
?>
	<section class="swp-card__section swp-card__nutrition">
		<h3><?php esc_html_e( 'Nutrition', 'scrumptious' ); ?></h3>
		<?php if ( '' !== $recipe['nutrition']['serving_size'] ) : ?>
			<p class="swp-card__serving-size">
				<?php
				/* translators: %s: serving size, e.g. "1 slice" */
				echo esc_html( sprintf( __( 'Per serving (%s)', 'scrumptious' ), $recipe['nutrition']['serving_size'] ) );
				?>
			</p>
		<?php endif; ?>
		<dl class="swp-nutrition">
			<?php foreach ( $scrumptious_nutrition_labels as $scrumptious_key => $scrumptious_meta ) : ?>
				<?php if ( '' !== $recipe['nutrition'][ $scrumptious_key ] ) : ?>
					<div><dt><?php echo esc_html( $scrumptious_meta[0] ); ?></dt><dd><?php echo esc_html( $recipe['nutrition'][ $scrumptious_key ] . ' ' . $scrumptious_meta[1] ); ?></dd></div>
				<?php endif; ?>
			<?php endforeach; ?>
		</dl>
	</section>
