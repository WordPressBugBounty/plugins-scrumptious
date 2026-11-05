<?php
/**
 * Import from WP Recipe Maker (post type wprm_recipe, meta wprm_*).
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * WP Recipe Maker importer.
 */
final class Import_Wprm extends Importer {

	/**
	 * {@inheritDoc}
	 */
	public function slug() {
		return 'wprm';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'WP Recipe Maker';
	}

	/**
	 * {@inheritDoc}
	 */
	public function ids() {
		return self::post_ids( 'wprm_recipe' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function markers() {
		return array( '%wp:wp-recipe-maker/%', '%[wprm-recipe%', '%<!--WPRM Recipe %' );
	}

	/**
	 * Meta value (unserialized).
	 *
	 * @param int    $id  Post ID.
	 * @param string $key Key.
	 * @return mixed
	 */
	private static function meta( $id, $key ) {
		return get_post_meta( $id, $key, true ); // Already unserialized by WordPress; no second pass.
	}

	/**
	 * Read one source recipe.
	 *
	 * @param int $id Source ID.
	 * @return array<string,mixed>|null
	 */
	public function load( $id ) {
		$post = get_post( $id );
		if ( ! $post || 'wprm_recipe' !== $post->post_type ) {
			return null;
		}

		$ingredients = array();
		$source      = self::meta( $id, 'wprm_ingredients' );
		foreach ( is_array( $source ) ? $source : array() as $group ) {
			$items = array();
			foreach ( isset( $group['ingredients'] ) && is_array( $group['ingredients'] ) ? $group['ingredients'] : array() as $ing ) {
				$line  = trim( implode( ' ', array_filter( array( self::text( isset( $ing['amount'] ) ? $ing['amount'] : '' ), self::text( isset( $ing['unit'] ) ? $ing['unit'] : '' ), self::text( isset( $ing['name'] ) ? $ing['name'] : '' ) ) ) ) );
				$notes = self::text( isset( $ing['notes'] ) ? $ing['notes'] : '' );
				if ( '' !== $notes ) {
					$line .= ( '(' === $notes[0] ? ' ' : ', ' ) . $notes;
				}
				if ( '' === $line && ! empty( $ing['raw'] ) ) {
					$line = self::text( $ing['raw'] );
				}
				if ( '' !== $line ) {
					$items[] = $line;
				}
			}
			$ingredients[] = array(
				'title' => self::text( isset( $group['name'] ) ? $group['name'] : '' ),
				'items' => $items,
			);
		}

		$instructions = array();
		$source       = self::meta( $id, 'wprm_instructions' );
		foreach ( is_array( $source ) ? $source : array() as $group ) {
			$steps = array();
			foreach ( isset( $group['instructions'] ) && is_array( $group['instructions'] ) ? $group['instructions'] : array() as $step ) {
				$text = isset( $step['text'] ) ? trim( (string) $step['text'] ) : '';
				// Strip the <p> wrapper WPRM stores around each step.
				$text = preg_replace( '#^<p>(.*)</p>$#s', '$1', $text );
				if ( isset( $step['type'] ) && 'tip' === $step['type'] && '' !== $text ) {
					/* translators: %s: tip text */
					$text = sprintf( __( 'Tip: %s', 'scrumptious' ), $text );
				}
				if ( '' === self::text( $text ) && empty( $step['image'] ) ) {
					continue;
				}
				$steps[] = array(
					'text'     => $text,
					'image_id' => isset( $step['image'] ) ? absint( $step['image'] ) : 0,
				);
			}
			$instructions[] = array(
				'title' => self::text( isset( $group['name'] ) ? $group['name'] : '' ),
				'steps' => $steps,
			);
		}

		$nutrition = array();
		foreach ( Recipe::NUTRITION_FIELDS as $f ) {
			$v = get_post_meta( $id, 'wprm_nutrition_' . $f, true );
			if ( '' !== (string) $v && false !== $v ) {
				$nutrition[ $f ] = (string) $v;
			}
		}
		$unit = (string) get_post_meta( $id, 'wprm_nutrition_serving_unit', true );
		if ( isset( $nutrition['serving_size'] ) && '' !== $unit ) {
			$nutrition['serving_size'] .= ' ' . $unit;
		}

		$video = (string) get_post_meta( $id, 'wprm_video_embed', true );
		$video = preg_match( '#^https?://\S+$#', trim( $video ) ) ? trim( $video ) : '';

		$rating = self::meta( $id, 'wprm_rating' );
		$count  = is_array( $rating ) && isset( $rating['count'] ) ? (int) $rating['count'] : 0;
		$total  = is_array( $rating ) && isset( $rating['total'] ) ? (float) $rating['total'] : 0.0;

		return array(
			'title'    => html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ),
			'recipe'   => array(
				'summary'            => $post->post_content,
				'author_name'        => 'custom' === get_post_meta( $id, 'wprm_author_display', true ) ? (string) get_post_meta( $id, 'wprm_author_name', true ) : '',
				'servings'           => (float) get_post_meta( $id, 'wprm_servings', true ),
				'servings_unit'      => self::text( get_post_meta( $id, 'wprm_servings_unit', true ) ),
				'times'              => array(
					'prep'  => (int) get_post_meta( $id, 'wprm_prep_time', true ),
					'cook'  => (int) get_post_meta( $id, 'wprm_cook_time', true ),
					'rest'  => (int) get_post_meta( $id, 'wprm_custom_time', true ),
					'total' => (int) get_post_meta( $id, 'wprm_total_time', true ),
				),
				'ingredient_groups'  => $ingredients,
				'instruction_groups' => $instructions,
				'notes'              => (string) get_post_meta( $id, 'wprm_notes', true ),
				'video_url'          => $video,
				'keywords'           => implode( ', ', self::term_names( $id, 'wprm_keyword' ) ),
				'nutrition'          => $nutrition,
			),
			'image_id' => (int) get_post_thumbnail_id( $post ),
			'status'   => $post->post_status,
			'date'     => $post->post_date,
			'author'   => (int) $post->post_author,
			'terms'    => array(
				'scrumptious_course'  => self::term_names( $id, 'wprm_course' ),
				'scrumptious_cuisine' => self::term_names( $id, 'wprm_cuisine' ),
				'scrumptious_diet'    => self::term_names( $id, 'wprm_suitablefordiet' ),
			),
			'rating'   => array(
				'count' => $count,
				'total' => $total,
			),
		);
	}

	/**
	 * Swap this plugin's cards in post content.
	 *
	 * @param string         $content Post content.
	 * @param array<int,int> $map     Source ID => Scrumptious recipe ID.
	 * @return string
	 */
	public function replace( $content, array $map ) {
		$recipe  = static function ( $id ) use ( $map ) {
			$id = (int) $id;
			return isset( $map[ $id ] ) ? self::recipe_block( $map[ $id ] ) : null;
		};
		$content = self::swap_block(
			$content,
			'wp-recipe-maker/recipe',
			static function ( $a ) use ( $recipe ) {
				return $recipe( isset( $a['id'] ) ? $a['id'] : 0 );
			}
		);
		// Classic posts: WPRM saves a static copy of the recipe between these comments
		// (so the content survives deactivation) instead of the shortcode.
		$content = preg_replace_callback(
			'#<!--WPRM Recipe (\d+)-->.*?<!--End WPRM Recipe-->#s',
			static function ( $m ) use ( $map ) {
				$id = (int) $m[1];
				return isset( $map[ $id ] ) ? '[scrumptious id="' . (int) $map[ $id ] . '"]' : $m[0];
			},
			$content
		);
		$content = self::swap_shortcode(
			$content,
			'wprm-recipe',
			static function ( $a ) use ( $recipe ) {
				return $recipe( isset( $a['id'] ) ? $a['id'] : 0 );
			}
		);
		// Jump / print buttons: Scrumptious has its own (print is on the card).
		$content = self::swap_block(
			$content,
			'wp-recipe-maker/jump-to-recipe',
			static function () {
				return self::jump_block();
			}
		);
		$content = self::swap_shortcode(
			$content,
			'wprm-recipe-jump',
			static function () {
				return self::jump_block();
			}
		);
		$content = self::swap_block(
			$content,
			'wp-recipe-maker/print-recipe',
			static function () {
				return '';
			}
		);
		return self::swap_shortcode(
			$content,
			'wprm-recipe-print',
			static function () {
				return '';
			}
		);
	}
}
