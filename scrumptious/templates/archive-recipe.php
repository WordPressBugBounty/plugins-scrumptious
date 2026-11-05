<?php
/**
 * Recipe index for classic themes: the theme's header and footer around a Scrumptious grid.
 *
 * Themes can replace it with their own archive-scrumptious_recipe.php, or turn it off under
 * Recipes → Settings → Recipe index layout.
 *
 * @package Scrumptious
 */

use Scrumptious\Block;
use Scrumptious\Recipe_Pages;
use Scrumptious\Renderer;

defined( 'ABSPATH' ) || exit;

Renderer::register_style();
wp_enqueue_style( Renderer::STYLE_HANDLE );

$scrumptious_index   = (string) get_post_type_archive_link( 'scrumptious_recipe' );
$scrumptious_active  = Recipe_Pages::index_filters();
$scrumptious_filters = array(
	'course'  => array( 'scrumptious_course', __( 'Course', 'scrumptious' ) ),
	'cuisine' => array( 'scrumptious_cuisine', __( 'Cuisine', 'scrumptious' ) ),
);

/**
 * Index URL with the current filters, one of them changed ('' removes it). Always page 1.
 *
 * @param string $param course|cuisine.
 * @param string $slug  Term slug, '' for all.
 * @return string
 */
$scrumptious_url = static function ( $param, $slug ) use ( $scrumptious_index, $scrumptious_active ) {
	$args = array();
	if ( isset( $scrumptious_active['scrumptious_course'] ) ) {
		$args['course'] = $scrumptious_active['scrumptious_course'];
	}
	if ( isset( $scrumptious_active['scrumptious_cuisine'] ) ) {
		$args['cuisine'] = $scrumptious_active['scrumptious_cuisine'];
	}
	$args[ $param ] = $slug;
	return add_query_arg( array_filter( $args ), $scrumptious_index );
};

get_header();
?>
<div class="swp-index">
	<header class="swp-index__header">
		<h1 class="swp-index__title"><?php post_type_archive_title(); ?></h1>
	</header>

	<?php
	foreach ( $scrumptious_filters as $scrumptious_param => list( $scrumptious_tax, $scrumptious_label ) ) :
		$scrumptious_terms = get_terms(
			array(
				'taxonomy'   => $scrumptious_tax,
				'hide_empty' => true,
			)
		);
		if ( is_wp_error( $scrumptious_terms ) || count( $scrumptious_terms ) < 2 ) {
			continue;
		}
		$scrumptious_current = isset( $scrumptious_active[ $scrumptious_tax ] ) ? $scrumptious_active[ $scrumptious_tax ] : '';
		?>
		<nav class="swp-index__filter" aria-label="<?php echo esc_attr( $scrumptious_label ); ?>">
			<span class="swp-index__filter-label"><?php echo esc_html( $scrumptious_label ); ?></span>
			<a class="swp-chip<?php echo '' === $scrumptious_current ? ' is-active' : ''; ?>" href="<?php echo esc_url( $scrumptious_url( $scrumptious_param, '' ) ); ?>"<?php echo '' === $scrumptious_current ? ' aria-current="true"' : ''; ?>><?php esc_html_e( 'All', 'scrumptious' ); ?></a>
			<?php foreach ( $scrumptious_terms as $scrumptious_term ) : ?>
				<?php $scrumptious_on = $scrumptious_term->slug === $scrumptious_current; ?>
				<a class="swp-chip<?php echo $scrumptious_on ? ' is-active' : ''; ?>" href="<?php echo esc_url( $scrumptious_url( $scrumptious_param, $scrumptious_term->slug ) ); ?>"<?php echo $scrumptious_on ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $scrumptious_term->name ); ?></a>
			<?php endforeach; ?>
		</nav>
	<?php endforeach; ?>

	<?php if ( have_posts() ) : ?>
		<div class="swp-grid swp-index__grid">
			<ul class="swp-grid__list" style="--swp-columns:3">
				<?php
				while ( have_posts() ) :
					the_post();
					echo Block::grid_item_html( get_post(), (string) get_permalink() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in grid_item_html().
				endwhile;
				?>
			</ul>
		</div>
		<?php
		the_posts_pagination(
			array(
				'mid_size'  => 1,
				'prev_text' => __( 'Previous', 'scrumptious' ),
				'next_text' => __( 'Next', 'scrumptious' ),
				'class'     => 'swp-index__pagination',
			)
		);
		?>
	<?php else : ?>
		<p class="swp-index__empty"><?php esc_html_e( 'No recipes found.', 'scrumptious' ); ?></p>
	<?php endif; ?>
</div>
<?php
get_footer();
