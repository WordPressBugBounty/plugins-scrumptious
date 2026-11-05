<?php
/**
 * Recipe part: share buttons. Used by the Recipe Card and the recipe part blocks.
 *
 * Override by copying to {your-theme}/scrumptious/parts/share.php.
 *
 * Plain links to each network (no third-party scripts), shown as brand icons with the
 * network name for screen readers (and as a tooltip). The card script adds the phone's
 * share menu and Copy link when the browser supports them.
 *
 * @var array $recipe    Normalized recipe data.
 * @var int   $recipe_id      Recipe post ID.
 * @var string $share_position 'top', 'bottom' or '' (Recipe Share Buttons block).
 *
 * @package Scrumptious
 */

use Scrumptious\Renderer;
use Scrumptious\Settings;
use Scrumptious\Share_Icons;

defined( 'ABSPATH' ) || exit;

// Included from Renderer, so these variables are function-scoped, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$scrumptious_share = Renderer::share_links( $recipe_id );
if ( ! $scrumptious_share ) {
	return;
}
?>
	<div class="swp-share<?php echo 'labels' === Settings::get( 'share_style' ) ? ' swp-share--labels' : ''; ?><?php echo '' !== $share_position ? ' swp-share--' . esc_attr( $share_position ) : ''; ?>" data-url="<?php echo esc_url( $scrumptious_share['url'] ); ?>" data-title="<?php echo esc_attr( $scrumptious_share['title'] ); ?>" role="group" aria-label="<?php esc_attr_e( 'Share this recipe', 'scrumptious' ); ?>">
		<span class="swp-share__label"><?php 'bottom' === $share_position ? esc_html_e( 'Share this recipe', 'scrumptious' ) : esc_html_e( 'Share', 'scrumptious' ); ?></span>
		<button type="button" class="swp-share__btn swp-share__btn--native swp-share__native" title="<?php esc_attr_e( 'Share…', 'scrumptious' ); ?>" hidden>
			<?php echo Share_Icons::svg( 'native' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG, attributes escaped. ?>
			<span class="swp-share__text"><?php esc_html_e( 'Share…', 'scrumptious' ); ?></span>
		</button>
		<?php foreach ( $scrumptious_share['links'] as $scrumptious_network => $scrumptious_link ) : ?>
			<a class="swp-share__btn swp-share__btn--<?php echo esc_attr( $scrumptious_network ); ?>" data-network="<?php echo esc_attr( $scrumptious_network ); ?>" href="<?php echo esc_url( $scrumptious_link['href'] ); ?>" title="<?php echo esc_attr( $scrumptious_link['label'] ); ?>" <?php echo 'email' === $scrumptious_network ? '' : 'target="_blank" rel="nofollow noopener noreferrer"'; ?>>
				<?php echo Share_Icons::svg( $scrumptious_network ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG, attributes escaped. ?>
				<span class="swp-share__text"><?php echo esc_html( $scrumptious_link['label'] ); ?></span>
				<?php if ( 'email' !== $scrumptious_network ) : ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'scrumptious' ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
		<?php if ( $scrumptious_share['copy'] ) : ?>
			<button type="button" class="swp-share__btn swp-share__btn--copy swp-share__copy" title="<?php esc_attr_e( 'Copy link', 'scrumptious' ); ?>" hidden>
				<?php echo Share_Icons::svg( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG, attributes escaped. ?>
				<span class="swp-share__text"><?php esc_html_e( 'Copy link', 'scrumptious' ); ?></span>
			</button>
		<?php endif; ?>
	</div>
