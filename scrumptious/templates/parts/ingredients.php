<?php
/**
 * Recipe part: ingredients. Used by the Recipe Card and the recipe part blocks.
 *
 * Override by copying to {your-theme}/scrumptious/parts/ingredients.php.
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
	<section class="swp-card__section swp-card__ingredients">
		<h3><?php esc_html_e( 'Ingredients', 'scrumptious' ); ?></h3>
		<?php foreach ( $recipe['ingredient_groups'] as $scrumptious_group ) : ?>
			<?php if ( '' !== $scrumptious_group['title'] ) : ?>
				<h4 class="swp-card__group-title"><?php echo esc_html( $scrumptious_group['title'] ); ?></h4>
			<?php endif; ?>
			<ul class="swp-ingredients">
				<?php foreach ( $scrumptious_group['items'] as $scrumptious_item ) : ?>
					<li class="swp-ingredient"<?php echo null !== $scrumptious_item['amount_value'] ? ' data-amount="' . esc_attr( (string) $scrumptious_item['amount_value'] ) . '"' : ''; ?><?php echo null !== $scrumptious_item['amount_max'] ? ' data-amount-max="' . esc_attr( (string) $scrumptious_item['amount_max'] ) . '"' : ''; ?><?php echo '' !== $scrumptious_item['unit_key'] ? ' data-unit="' . esc_attr( $scrumptious_item['unit_key'] ) . '"' : ''; ?>>
						<?php if ( '' !== $scrumptious_item['amount'] ) : ?>
							<span class="swp-ingredient__amount"><?php echo esc_html( $scrumptious_item['amount'] ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $scrumptious_item['unit'] ) : ?>
							<span class="swp-ingredient__unit"><?php echo esc_html( $scrumptious_item['unit'] ); ?></span>
						<?php endif; ?>
						<?php
						$scrumptious_name = esc_html( $scrumptious_item['name'] );
						if ( '' !== $scrumptious_item['link'] ) {
							$scrumptious_name = '<a href="' . esc_url( $scrumptious_item['link'] ) . '" rel="nofollow noopener sponsored" target="_blank">' . $scrumptious_name . '</a>';
						}
						?>
						<span class="swp-ingredient__name"><?php echo $scrumptious_name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></span><?php echo '' !== $scrumptious_item['notes'] ? '<span class="swp-ingredient__notes">' . esc_html( $scrumptious_item['notes'] ) . '</span>' : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>
	</section>
