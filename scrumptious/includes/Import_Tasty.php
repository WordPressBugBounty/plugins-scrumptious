<?php
/**
 * Import from Tasty Recipes / Tasty Recipes Lite (post type tasty_recipe, HTML fields in meta).
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Tasty Recipes importer.
 */
final class Import_Tasty extends Importer {

	/**
	 * {@inheritDoc}
	 */
	public function slug() {
		return 'tasty';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'Tasty Recipes';
	}

	/**
	 * {@inheritDoc}
	 */
	public function ids() {
		return self::post_ids( 'tasty_recipe' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function markers() {
		return array( '%wp:wp-tasty/tasty-recipe%', '%[tasty-recipe%' );
	}

	/**
	 * Comma-separated text → list.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function list_of( $text ) {
		return array_values( array_filter( array_map( 'trim', explode( ',', self::text( $text ) ) ) ) );
	}

	/**
	 * Tasty stores diets as schema.org names ("GlutenFreeDiet") → "Gluten Free".
	 *
	 * @param string $diet Diet.
	 * @return string
	 */
	public static function diet_name( $diet ) {
		$diet = preg_replace( '#^https?://schema\.org/#', '', trim( (string) $diet ) );
		$diet = preg_replace( '/Diet$/', '', $diet );
		return trim( preg_replace( '/(?<=[a-z])(?=[A-Z])/', ' ', $diet ) );
	}

	/**
	 * Read one source recipe.
	 *
	 * @param int $id Source ID.
	 * @return array<string,mixed>|null
	 */
	public function load( $id ) {
		$post = get_post( $id );
		if ( ! $post || 'tasty_recipe' !== $post->post_type ) {
			return null;
		}
		$m = static function ( $key ) use ( $id ) {
			return (string) get_post_meta( $id, $key, true );
		};

		list( $servings, $unit ) = self::yield_parts( $m( 'yield' ) );

		$nutrition = array();
		foreach ( Recipe::NUTRITION_FIELDS as $f ) {
			if ( '' !== $m( $f ) ) {
				$nutrition[ $f ] = $m( $f );
			}
		}

		$diets = array();
		foreach ( self::list_of( $m( 'diet' ) ) as $d ) {
			$diets[] = self::diet_name( $d );
		}

		$image = (int) $m( 'image_id' );
		if ( ! $image ) {
			$image = (int) get_post_thumbnail_id( $post );
		}

		return array(
			'title'    => html_entity_decode( '' !== $m( 'title' ) ? $m( 'title' ) : $post->post_title, ENT_QUOTES, 'UTF-8' ),
			'recipe'   => array(
				'summary'            => $m( 'description' ),
				'author_name'        => $m( 'author_name' ),
				'servings'           => $servings,
				'servings_unit'      => $unit,
				'times'              => array(
					'prep'  => Recipe_Parser::minutes( $m( 'prep_time' ) ),
					'cook'  => Recipe_Parser::minutes( $m( 'cook_time' ) ),
					'rest'  => Recipe_Parser::minutes( $m( 'additional_time_value' ) ),
					'total' => Recipe_Parser::minutes( $m( 'total_time' ) ),
				),
				'ingredient_groups'  => self::groups( $m( 'ingredients' ), 'items' ),
				'instruction_groups' => self::groups( $m( 'instructions' ), 'steps' ),
				'notes'              => $m( 'notes' ),
				'video_url'          => esc_url_raw( $m( 'video_url' ) ),
				'keywords'           => self::text( $m( 'keywords' ) ),
				'nutrition'          => $nutrition,
			),
			'image_id' => $image,
			'status'   => $post->post_status,
			'date'     => $post->post_date,
			'author'   => (int) $post->post_author,
			'terms'    => array(
				'scrumptious_course'  => self::list_of( $m( 'category' ) ),
				'scrumptious_cuisine' => self::list_of( $m( 'cuisine' ) ),
				'scrumptious_diet'    => $diets,
			),
			'rating'   => array(
				'count' => (int) $m( 'total_reviews' ),
				'total' => (float) $m( 'average_rating' ) * (int) $m( 'total_reviews' ),
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
		$recipe  = static function ( $a ) use ( $map ) {
			$id = isset( $a['id'] ) ? (int) $a['id'] : 0;
			return isset( $map[ $id ] ) ? self::recipe_block( $map[ $id ] ) : null;
		};
		$content = self::swap_block( $content, 'wp-tasty/tasty-recipe', $recipe );
		return self::swap_shortcode( $content, 'tasty-recipe', $recipe );
	}
}
