<?php
/**
 * Recipe part: instructions. Used by the Recipe Card and the recipe part blocks.
 *
 * Override by copying to {your-theme}/scrumptious/parts/instructions.php.
 *
 * @var array $recipe    Normalized recipe data.
 * @var int   $recipe_id Recipe post ID.
 *
 * @package Scrumptious
 */

use Scrumptious\Schema;

defined( 'ABSPATH' ) || exit;

// Included from Renderer, so these variables are function-scoped, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>
	<section class="swp-card__section swp-card__instructions">
		<h3><?php esc_html_e( 'Instructions', 'scrumptious' ); ?></h3>
		<?php foreach ( $recipe['instruction_groups'] as $scrumptious_g => $scrumptious_group ) : ?>
			<?php if ( '' !== $scrumptious_group['title'] ) : ?>
				<h4 class="swp-card__group-title"><?php echo esc_html( $scrumptious_group['title'] ); ?></h4>
			<?php endif; ?>
			<ol class="swp-steps">
				<?php foreach ( $scrumptious_group['steps'] as $scrumptious_s => $scrumptious_step ) : ?>
					<li id="<?php echo esc_attr( Schema::step_anchor( $recipe_id, $scrumptious_g, $scrumptious_s ) ); ?>" class="swp-step"<?php echo $scrumptious_step['timer'] ? ' data-timer="' . (int) $scrumptious_step['timer'] . '"' : ''; ?>>
						<div class="swp-step__text"><?php echo wp_kses_post( $scrumptious_step['text'] ); ?></div>
						<?php if ( $scrumptious_step['image_id'] ) : ?>
							<div class="swp-step__image"><?php echo wp_get_attachment_image( $scrumptious_step['image_id'], 'medium_large', false, array( 'loading' => 'lazy' ) ); ?></div>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endforeach; ?>
	</section>
