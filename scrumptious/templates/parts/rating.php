<?php
/**
 * Recipe part: star rating. Used by the Recipe Card and the recipe part blocks.
 *
 * Override by copying to {your-theme}/scrumptious/parts/rating.php.
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
if ( null !== $rating ) :
	/* translators: 1: average rating, 2: number of ratings */
	$scrumptious_label = sprintf( __( 'Rated %1$s out of 5 from %2$d ratings', 'scrumptious' ), $rating['average'], $rating['count'] );
	$scrumptious_text  = $rating['count']
		/* translators: 1: average rating, 2: number of ratings */
		? sprintf( _n( '%1$s from %2$d rating', '%1$s from %2$d ratings', $rating['count'], 'scrumptious' ), $rating['average'], $rating['count'] )
		: __( 'Be the first to rate', 'scrumptious' );
	?>
	<div class="swp-rating" data-recipe="<?php echo (int) $recipe_id; ?>" style="--swp-rating:<?php echo esc_attr( (string) $rating['average'] ); ?>">
		<span class="swp-rating__stars" role="img" aria-label="<?php echo esc_attr( $scrumptious_label ); ?>">
			<?php for ( $scrumptious_i = 1; $scrumptious_i <= 5; $scrumptious_i++ ) : ?>
				<?php /* translators: %d: number of stars */ ?>
				<button type="button" class="swp-rating__star" data-value="<?php echo (int) $scrumptious_i; ?>" disabled aria-label="<?php echo esc_attr( sprintf( _n( 'Rate %d star', 'Rate %d stars', $scrumptious_i, 'scrumptious' ), $scrumptious_i ) ); ?>"></button>
			<?php endfor; ?>
		</span>
		<span class="swp-rating__text" aria-live="polite"><?php echo esc_html( $scrumptious_text ); ?></span>
	</div>
<?php endif; ?>
