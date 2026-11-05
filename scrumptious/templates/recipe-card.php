<?php
/**
 * Recipe card template.
 *
 * Override by copying to {your-theme}/scrumptious/recipe-card.php.
 *
 * Available variables:
 *
 * @var array  $recipe    Normalized recipe data (see Scrumptious\Recipe::defaults()).
 * @var array  $ctx       Context (images, terms, url, rating …).
 * @var int    $recipe_id Recipe post ID.
 * @var string $title     Recipe title.
 * @var string $class     Extra CSS classes.
 * @var array  $show      Resolved section visibility (image, summary, times, share, gallery, notes, nutrition).
 * @var string $style     Card style: full | compact | minimal.
 * @var string $print_url Print view URL, or '' when the Print button is off.
 * @var array  $features  Enabled reader features (scale, units, cook, rate).
 * @var array|null $rating Rating aggregate { average, count } or null when ratings are off.
 *
 * @package Scrumptious
 */

use Scrumptious\Renderer;
use Scrumptious\Settings;

defined( 'ABSPATH' ) || exit;

// Included from Renderer::render(), so these variables are function-scoped, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$scrumptious_vars          = compact( 'recipe', 'recipe_id', 'rating' );
$scrumptious_has_nutrition = Renderer::has_nutrition( $recipe );
?>
<div id="swp-recipe-<?php echo (int) $recipe_id; ?>" class="swp-card swp-card--<?php echo esc_attr( $style ); ?> <?php echo esc_attr( $class ); ?>" data-recipe-id="<?php echo (int) $recipe_id; ?>" data-servings="<?php echo esc_attr( (string) $recipe['servings'] ); ?>" data-features="<?php echo esc_attr( implode( ' ', $features ) ); ?>">
	<header class="swp-card__header">
		<?php if ( ! empty( $ctx['image_id'] ) && ! empty( $show['image'] ) ) : ?>
			<div class="swp-card__image">
				<?php
				echo wp_get_attachment_image(
					(int) $ctx['image_id'],
					'swp-1x1',
					false,
					array(
						'loading' => 'lazy',
						'sizes'   => '(max-width: 600px) 40vw, 220px',
					)
				);
				?>
			</div>
		<?php endif; ?>
		<div class="swp-card__heading">
			<h2 class="swp-card__title"><?php echo esc_html( $title ); ?></h2>
			<?php if ( '' !== $recipe['summary'] && ! empty( $show['summary'] ) ) : ?>
				<p class="swp-card__summary"><?php echo wp_kses_post( $recipe['summary'] ); ?></p>
			<?php endif; ?>
			<?php Renderer::part( 'rating', $scrumptious_vars ); ?>
			<?php
			ob_start();
			/**
			 * Fires inside the card's button row (after Print), for extra buttons such as "Add to meal plan".
			 *
			 * @param int   $recipe_id Recipe ID.
			 * @param array $recipe    Normalized recipe.
			 */
			do_action( 'scrumptious_card_actions', $recipe_id, $recipe );
			$scrumptious_extra_actions = (string) ob_get_clean();
			?>
			<?php if ( '' !== $print_url || '' !== trim( $scrumptious_extra_actions ) ) : ?>
				<div class="swp-card__actions">
					<?php if ( '' !== $print_url ) : ?>
						<a class="swp-button swp-button--print" href="<?php echo esc_url( $print_url ); ?>" target="_blank" rel="nofollow noopener"><?php esc_html_e( 'Print recipe', 'scrumptious' ); ?></a>
					<?php endif; ?>
					<?php echo $scrumptious_extra_actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output of hooked callbacks, escaped by them. ?>
				</div>
			<?php endif; ?>
		</div>
	</header>
	<?php
	/**
	 * Filter where the share buttons sit on the card: 'bottom' (after notes and nutrition) or 'top' (small, under the title).
	 *
	 * @param string $position  Position from Recipes → Settings → Sharing.
	 * @param int    $recipe_id Recipe ID.
	 */
	$scrumptious_share_pos = 'top' === apply_filters( 'scrumptious_share_position', (string) Settings::get( 'share_position' ), $recipe_id ) ? 'top' : 'bottom';
	?>
	<?php if ( ! empty( $show['share'] ) && 'top' === $scrumptious_share_pos ) : ?>
		<?php Renderer::part( 'share', array_merge( $scrumptious_vars, array( 'share_position' => 'top' ) ) ); ?>
	<?php endif; ?>
	<?php if ( ( array_filter( $recipe['times'] ) || $recipe['servings'] ) && ! empty( $show['times'] ) ) : ?>
		<?php Renderer::part( 'meta', $scrumptious_vars ); ?>
	<?php endif; ?>

	<?php if ( $recipe['ingredient_groups'] ) : ?>
		<?php Renderer::part( 'ingredients', $scrumptious_vars ); ?>
	<?php endif; ?>

	<?php if ( $recipe['instruction_groups'] ) : ?>
		<?php Renderer::part( 'instructions', $scrumptious_vars ); ?>
	<?php endif; ?>

	<?php if ( ! empty( $recipe['gallery'] ) && ! empty( $show['gallery'] ) ) : ?>
		<?php Renderer::part( 'gallery', $scrumptious_vars ); ?>
	<?php endif; ?>

	<?php if ( '' !== $recipe['notes'] && ! empty( $show['notes'] ) ) : ?>
		<?php Renderer::part( 'notes', $scrumptious_vars ); ?>
	<?php endif; ?>

	<?php if ( $scrumptious_has_nutrition && ! empty( $show['nutrition'] ) ) : ?>
		<?php Renderer::part( 'nutrition', $scrumptious_vars ); ?>
	<?php endif; ?>

	<?php if ( ! empty( $show['share'] ) && 'bottom' === $scrumptious_share_pos ) : ?>
		<?php Renderer::part( 'share', array_merge( $scrumptious_vars, array( 'share_position' => 'bottom' ) ) ); ?>
	<?php endif; ?>

	<?php
	/**
	 * Filter the "Recipe by" credit line text ('' hides it). Defaults to the recipe's author name.
	 *
	 * @param string $credit    Author name.
	 * @param array  $recipe    Normalized recipe.
	 * @param int    $recipe_id Recipe ID.
	 */
	$scrumptious_credit = (string) apply_filters( 'scrumptious_card_credit', $recipe['author_name'], $recipe, $recipe_id );
	?>
	<?php if ( '' !== $scrumptious_credit ) : ?>
		<p class="swp-card__source swp-card__credit">
			<?php
			/* translators: %s: recipe author name */
			echo esc_html( sprintf( __( 'Recipe by %s', 'scrumptious' ), $scrumptious_credit ) );
			?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $recipe['source_url'] ) : ?>
		<p class="swp-card__source">
			<?php
			$scrumptious_source = '' !== $recipe['source_name'] ? $recipe['source_name'] : (string) wp_parse_url( $recipe['source_url'], PHP_URL_HOST );
			printf(
				/* translators: %s: link to the original recipe */
				esc_html__( 'Adapted from %s', 'scrumptious' ),
				'<a href="' . esc_url( $recipe['source_url'] ) . '" rel="noopener" target="_blank">' . esc_html( $scrumptious_source ) . '</a>'
			);
			?>
		</p>
	<?php endif; ?>

	<?php
	/**
	 * Fires at the end of the recipe card, inside its wrapper.
	 *
	 * @param int   $recipe_id Recipe ID.
	 * @param array $recipe    Normalized recipe.
	 */
	do_action( 'scrumptious_card_end', $recipe_id, $recipe );
	?>
</div>
