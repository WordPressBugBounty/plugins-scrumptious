<?php
/**
 * Builds Google-compliant Recipe JSON-LD.
 *
 * Pure function of (recipe data, context) so it is fully unit-testable.
 *
 * @see https://developers.google.com/search/docs/appearance/structured-data/recipe
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Builds schema.org Recipe JSON-LD.
 */
final class Schema {

	const NUTRITION_MAP = array(
		'serving_size'    => array( 'servingSize', '' ),
		'calories'        => array( 'calories', ' kcal' ),
		'carbohydrates'   => array( 'carbohydrateContent', ' g' ),
		'protein'         => array( 'proteinContent', ' g' ),
		'fat'             => array( 'fatContent', ' g' ),
		'saturated_fat'   => array( 'saturatedFatContent', ' g' ),
		'trans_fat'       => array( 'transFatContent', ' g' ),
		'unsaturated_fat' => array( 'unsaturatedFatContent', ' g' ),
		'cholesterol'     => array( 'cholesterolContent', ' mg' ),
		'sodium'          => array( 'sodiumContent', ' mg' ),
		'fiber'           => array( 'fiberContent', ' g' ),
		'sugar'           => array( 'sugarContent', ' g' ),
	);

	/**
	 * Schema.org RestrictedDiet values, keyed by a normalized diet name.
	 */
	const DIETS = array(
		'diabetic'    => 'DiabeticDiet',
		'glutenfree'  => 'GlutenFreeDiet',
		'halal'       => 'HalalDiet',
		'hindu'       => 'HinduDiet',
		'kosher'      => 'KosherDiet',
		'lowcalorie'  => 'LowCalorieDiet',
		'lowcal'      => 'LowCalorieDiet',
		'lowfat'      => 'LowFatDiet',
		'lowlactose'  => 'LowLactoseDiet',
		'lactosefree' => 'LowLactoseDiet',
		'lowsalt'     => 'LowSaltDiet',
		'lowsodium'   => 'LowSaltDiet',
		'vegan'       => 'VeganDiet',
		'plantbased'  => 'VeganDiet',
		'vegetarian'  => 'VegetarianDiet',
		'veggie'      => 'VegetarianDiet',
	);

	/**
	 * Map a diet name ("Gluten-free", "Vegan") to a schema.org RestrictedDiet, or ''.
	 * Diets Google has no value for (e.g. Keto, Paleo) return ''.
	 *
	 * @param string $name Diet name.
	 * @return string
	 */
	public static function diet( $name ) {
		$key = preg_replace( '/[^a-z]/', '', strtolower( (string) $name ) );
		$key = preg_replace( '/diet$/', '', $key );
		return isset( self::DIETS[ $key ] ) ? self::DIETS[ $key ] : '';
	}

	/**
	 * Build the Recipe node.
	 *
	 * @param array<string,mixed> $recipe Normalized recipe (Recipe::normalize()).
	 * @param array<string,mixed> $ctx    {
	 *     Context for the page the recipe is shown on.
	 *     @type int      $id             Recipe post ID (used for step anchors).
	 *     @type string   $name           Recipe title (required by Google).
	 *     @type string   $url            Canonical URL of the page showing the recipe.
	 *     @type string[] $images         Image URLs, ideally 1:1, 4:3, 16:9 (required by Google).
	 *     @type string   $author         Author display name.
	 *     @type string   $date_published ISO 8601 date.
	 *     @type string[] $category       Course names.
	 *     @type string[] $cuisine        Cuisine names.
	 *     @type string[] $diet           Diet names (mapped to suitableForDiet when known).
	 *     @type array    $rating         { value: float, count: int }.
	 * }
	 * @return array<string,mixed>
	 */
	public static function build( array $recipe, array $ctx ) {
		$recipe['ingredient_groups']  = Recipe::filled( $recipe['ingredient_groups'], 'items' );
		$recipe['instruction_groups'] = Recipe::filled( $recipe['instruction_groups'], 'steps' );

		$node = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Recipe',
			'name'     => self::plain( isset( $ctx['name'] ) ? $ctx['name'] : '' ),
		);

		if ( ! empty( $ctx['url'] ) ) {
			$node['@id'] = $ctx['url'] . '#recipe-' . ( isset( $ctx['id'] ) ? (int) $ctx['id'] : 0 );
			$node['url'] = $ctx['url'];
		}

		if ( ! empty( $ctx['images'] ) ) {
			$node['image'] = array_values( array_unique( array_filter( (array) $ctx['images'] ) ) );
		}

		if ( '' !== $recipe['summary'] ) {
			$node['description'] = self::plain( $recipe['summary'] );
		}

		$author = '' !== $recipe['author_name'] ? $recipe['author_name'] : ( isset( $ctx['author'] ) ? $ctx['author'] : '' );
		if ( '' !== $author ) {
			$node['author'] = array(
				'@type' => 'Person',
				'name'  => self::plain( $author ),
			);
		}

		if ( ! empty( $ctx['date_published'] ) ) {
			$node['datePublished'] = $ctx['date_published'];
		}

		foreach ( array(
			'prep'  => 'prepTime',
			'cook'  => 'cookTime',
			'total' => 'totalTime',
		) as $k => $prop ) {
			$iso = Duration::to_iso( $recipe['times'][ $k ] );
			if ( '' !== $iso ) {
				$node[ $prop ] = $iso;
			}
		}

		if ( $recipe['servings'] > 0 ) {
			$yield               = (string) $recipe['servings'];
			$node['recipeYield'] = array( $yield );
			if ( '' !== $recipe['servings_unit'] ) {
				$node['recipeYield'][] = $yield . ' ' . $recipe['servings_unit'];
			}
		}

		if ( ! empty( $ctx['category'] ) ) {
			$node['recipeCategory'] = array_values( (array) $ctx['category'] );
		}
		if ( ! empty( $ctx['cuisine'] ) ) {
			$node['recipeCuisine'] = array_values( (array) $ctx['cuisine'] );
		}
		if ( '' !== $recipe['keywords'] ) {
			$node['keywords'] = $recipe['keywords'];
		}
		if ( ! empty( $recipe['source_url'] ) ) {
			$node['isBasedOn'] = $recipe['source_url'];
		}
		$diets = array();
		foreach ( isset( $ctx['diet'] ) ? (array) $ctx['diet'] : array() as $name ) {
			$diet = self::diet( $name );
			if ( '' !== $diet ) {
				$diets[] = 'https://schema.org/' . $diet;
			}
		}
		if ( $diets ) {
			$node['suitableForDiet'] = array_values( array_unique( $diets ) );
		}

		// Ingredients: flat list of readable strings.
		$ingredients = array();
		foreach ( $recipe['ingredient_groups'] as $group ) {
			foreach ( $group['items'] as $item ) {
				$ingredients[] = self::ingredient_text( $item );
			}
		}
		if ( $ingredients ) {
			$node['recipeIngredient'] = $ingredients;
		}

		// Instructions: HowToSection when groups have titles, else flat HowToSteps.
		$has_sections = count( $recipe['instruction_groups'] ) > 1;
		$instructions = array();
		$rid          = isset( $ctx['id'] ) ? (int) $ctx['id'] : 0;
		foreach ( $recipe['instruction_groups'] as $g => $group ) {
			$steps = array();
			foreach ( $group['steps'] as $s => $step ) {
				$text  = self::plain( $step['text'] );
				$entry = array(
					'@type' => 'HowToStep',
					'text'  => $text,
				);
				if ( ! empty( $ctx['url'] ) ) {
					$entry['url'] = $ctx['url'] . '#' . self::step_anchor( $rid, $g, $s );
				}
				if ( ! empty( $ctx['step_images'][ $g ][ $s ] ) ) {
					$entry['image'] = $ctx['step_images'][ $g ][ $s ];
				}
				$steps[] = $entry;
			}
			if ( ! $steps ) {
				continue;
			}
			if ( $has_sections ) {
				$instructions[] = array(
					'@type'           => 'HowToSection',
					'name'            => '' !== $group['title'] ? $group['title'] : sprintf( 'Part %d', $g + 1 ),
					'itemListElement' => $steps,
				);
			} else {
				$instructions = array_merge( $instructions, $steps );
			}
		}
		if ( $instructions ) {
			$node['recipeInstructions'] = $instructions;
		}

		// Nutrition. Google requires recipeYield when calories are present.
		$nutrition = array();
		foreach ( self::NUTRITION_MAP as $k => $map ) {
			$v = isset( $recipe['nutrition'][ $k ] ) ? trim( (string) $recipe['nutrition'][ $k ] ) : '';
			if ( '' !== $v ) {
				$nutrition[ $map[0] ] = $v . $map[1];
			}
		}
		if ( $nutrition && isset( $node['recipeYield'] ) ) {
			$node['nutrition'] = array( '@type' => 'NutritionInformation' ) + $nutrition;
		}

		// Ratings.
		if ( ! empty( $ctx['rating']['count'] ) && (int) $ctx['rating']['count'] > 0 ) {
			$node['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (string) round( (float) $ctx['rating']['value'], 2 ),
				'ratingCount' => (string) (int) $ctx['rating']['count'],
				'bestRating'  => '5',
				'worstRating' => '1',
			);
		}

		// Video.
		if ( '' !== $recipe['video_url'] ) {
			$node['video'] = array(
				'@type'        => 'VideoObject',
				'name'         => $node['name'],
				'description'  => isset( $node['description'] ) ? $node['description'] : $node['name'],
				'contentUrl'   => $recipe['video_url'],
				'thumbnailUrl' => isset( $node['image'][0] ) ? array( $node['image'][0] ) : array(),
			);
			if ( ! empty( $ctx['date_published'] ) ) {
				$node['video']['uploadDate'] = $ctx['date_published'];
			}
		}

		return $node;
	}

	/**
	 * Readable ingredient string, e.g. "1 1/2 cups flour, sifted".
	 *
	 * @param array<string,mixed> $item Item.
	 * @return string
	 */
	public static function ingredient_text( array $item ) {
		$parts = array_filter( array( $item['amount'], $item['unit'], $item['name'] ), 'strlen' );
		$text  = implode( ' ', $parts );
		if ( '' !== $item['notes'] ) {
			$text .= ', ' . $item['notes'];
		}
		return '' !== $text ? $text : (string) $item['raw'];
	}

	/**
	 * HTML anchor id for a step.
	 *
	 * @param int $recipe_id Recipe ID.
	 * @param int $group     Group index.
	 * @param int $step      Step index.
	 * @return string
	 */
	public static function step_anchor( $recipe_id, $group, $step ) {
		return sprintf( 'swp-%d-step-%d-%d', (int) $recipe_id, (int) $group + 1, (int) $step + 1 );
	}

	/**
	 * Strip tags + decode entities + collapse whitespace.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function plain( $html ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}
}
