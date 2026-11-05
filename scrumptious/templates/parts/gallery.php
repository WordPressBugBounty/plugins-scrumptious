<?php
/**
 * Recipe part: photo gallery. Used by the Recipe Card and the recipe part blocks.
 *
 * Override by copying to {your-theme}/scrumptious/parts/gallery.php.
 *
 * Each thumbnail links to the full-size photo; the card script opens it in a lightbox.
 *
 * @var array $recipe    Normalized recipe data (`gallery` is a list of attachment IDs).
 * @var int   $recipe_id Recipe post ID.
 *
 * @package Scrumptious
 */

defined( 'ABSPATH' ) || exit;

// Included from Renderer, so these variables are function-scoped, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$scrumptious_photos = array();
foreach ( (array) $recipe['gallery'] as $scrumptious_id ) {
	$scrumptious_full = wp_get_attachment_image_url( (int) $scrumptious_id, 'full' );
	if ( $scrumptious_full ) {
		$scrumptious_photos[ (int) $scrumptious_id ] = $scrumptious_full;
	}
}
if ( ! $scrumptious_photos ) {
	return;
}
?>
	<section class="swp-card__section swp-card__gallery">
		<h3><?php esc_html_e( 'Gallery', 'scrumptious' ); ?></h3>
		<ul class="swp-gallery">
			<?php foreach ( $scrumptious_photos as $scrumptious_id => $scrumptious_full ) : ?>
				<?php $scrumptious_caption = wp_get_attachment_caption( $scrumptious_id ); ?>
				<li class="swp-gallery__item">
					<a class="swp-gallery__link" href="<?php echo esc_url( $scrumptious_full ); ?>"<?php echo $scrumptious_caption ? ' data-caption="' . esc_attr( $scrumptious_caption ) . '"' : ''; ?>>
						<?php
						echo wp_get_attachment_image(
							$scrumptious_id,
							'swp-1x1',
							false,
							array(
								'loading' => 'lazy',
								'sizes'   => '(max-width: 600px) 33vw, 180px',
								'class'   => 'swp-gallery__img',
							)
						);
						?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
