<?php
/**
 * Recipe part: summary.
 *
 * Override by copying to {your-theme}/scrumptious/parts/summary.php.
 *
 * @var array  $recipe    Normalized recipe data.
 * @var int    $recipe_id Recipe post ID.
 * @var string $print_url Print view URL ('' when off).
 *
 * @package Scrumptious
 */

defined( 'ABSPATH' ) || exit;
?>
<p class="swp-card__summary"><?php echo wp_kses_post( $recipe['summary'] ); ?></p>
