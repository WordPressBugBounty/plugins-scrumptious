<?php
/**
 * Recipe part: buttons (print, plus anything hooked to scrumptious_card_actions; cook mode is added by the script).
 *
 * Override by copying to {your-theme}/scrumptious/parts/actions.php.
 *
 * @var array  $recipe    Normalized recipe data.
 * @var int    $recipe_id Recipe post ID.
 * @var string $print_url Print view URL ('' when off).
 *
 * @package Scrumptious
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="swp-card__actions">
	<?php if ( '' !== $print_url ) : ?>
		<a class="swp-button swp-button--print" href="<?php echo esc_url( $print_url ); ?>" target="_blank" rel="nofollow noopener"><?php esc_html_e( 'Print recipe', 'scrumptious' ); ?></a>
	<?php endif; ?>
	<?php
	/** This action is documented in templates/recipe-card.php */
	do_action( 'scrumptious_card_actions', $recipe_id, $recipe );
	?>
</div>
