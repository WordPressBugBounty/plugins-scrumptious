<?php
/**
 * Course, cuisine and diet: matching names to terms, and AI suggestions.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Suggests and matches course / cuisine / diet terms.
 */
final class Tagger {

	/** Short key => taxonomy. */
	const TAXONOMIES = array(
		'course'  => 'scrumptious_course',
		'cuisine' => 'scrumptious_cuisine',
		'diet'    => 'scrumptious_diet',
	);

	/** Most suggestions per taxonomy. */
	const MAX = 3;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register the route.
	 */
	public static function routes() {
		register_rest_route(
			'scrumptious/v1',
			'/suggest-terms',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => static function ( $request ) {
					$id = (int) $request->get_param( 'recipe_id' );
					return $id ? current_user_can( 'edit_post', $id ) : current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'recipe_id' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'title'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'recipe'    => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);
	}

	/**
	 * Handle a suggestion request. Unsaved editor data wins over the saved recipe.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle( $request ) {
		$id     = (int) $request->get_param( 'recipe_id' );
		$recipe = (array) $request->get_param( 'recipe' );
		$title  = (string) $request->get_param( 'title' );
		if ( $id && ! $recipe ) {
			$recipe = Recipe::get( $id );
		}
		if ( $id && '' === $title ) {
			$title = get_the_title( $id );
		}
		$recipe = Recipe::normalize( $recipe );
		if ( '' === trim( $title ) && ! Recipe::filled( $recipe['ingredient_groups'], 'items' ) ) {
			return new \WP_Error( 'scrumptious_empty', __( 'Add a title and ingredients first, then ask for suggestions.', 'scrumptious' ), array( 'status' => 400 ) );
		}
		if ( ! Ai::within_limit() ) {
			return new \WP_Error( 'scrumptious_ai_limit', __( 'You\'ve reached this hour\'s AI limit. Try again later.', 'scrumptious' ), array( 'status' => 429 ) );
		}
		$out = self::suggest( $title, $recipe );
		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	/**
	 * Ask the AI for course, cuisine and diet, matched to existing terms.
	 *
	 * @param string              $title  Recipe name.
	 * @param array<string,mixed> $recipe Normalized recipe.
	 * @return array<string,array<int,array{id:int,name:string}>>|\WP_Error Keyed by taxonomy; id 0 = new term.
	 */
	public static function suggest( $title, array $recipe ) {
		$existing = self::existing();
		$ai       = Ai::json( self::prompt( $existing ), self::recipe_text( $title, $recipe ) );
		if ( is_wp_error( $ai ) ) {
			return $ai;
		}
		$out = array();
		foreach ( self::from_ai( $ai ) as $tax => $names ) {
			$out[ $tax ] = self::match_all( $names, isset( $existing[ $tax ] ) ? $existing[ $tax ] : array() );
		}

		/**
		 * Filter AI course / cuisine / diet suggestions before they reach the editor.
		 *
		 * @param array<string,array<int,array{id:int,name:string}>> $out    Keyed by taxonomy; id 0 = new term.
		 * @param string                                             $title  Recipe name.
		 * @param array<string,mixed>                                $recipe Normalized recipe.
		 */
		return (array) apply_filters( 'scrumptious_suggest_terms', $out, $title, $recipe );
	}

	/**
	 * Existing terms per taxonomy.
	 *
	 * @return array<string,array<int,string>> taxonomy => [ term_id => name ].
	 */
	public static function existing() {
		$out = array();
		foreach ( self::TAXONOMIES as $tax ) {
			$out[ $tax ] = array();
			$terms       = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
					'number'     => 200,
				)
			);
			if ( is_array( $terms ) ) {
				foreach ( $terms as $t ) {
					$out[ $tax ][ (int) $t->term_id ] = $t->name;
				}
			}
		}
		return $out;
	}

	/**
	 * Read the AI reply: { course: [], cuisine: [], diet: [] } → taxonomy => names.
	 *
	 * @param array<string,mixed> $ai AI JSON.
	 * @return array<string,string[]>
	 */
	public static function from_ai( array $ai ) {
		$out = array();
		foreach ( self::TAXONOMIES as $key => $tax ) {
			$list = isset( $ai[ $key ] ) ? $ai[ $key ] : array();
			if ( is_string( $list ) ) {
				$list = explode( ',', $list );
			}
			$names = array();
			foreach ( is_array( $list ) ? $list : array() as $name ) {
				$name = is_scalar( $name ) ? trim( sanitize_text_field( (string) $name ) ) : '';
				if ( '' !== $name && mb_strlen( $name ) <= 40 && ! in_array( self::key( $name ), array_map( array( __CLASS__, 'key' ), $names ), true ) ) {
					$names[] = $name;
				}
			}
			$out[ $tax ] = array_slice( $names, 0, self::MAX );
		}
		return $out;
	}

	/**
	 * Match names to existing terms.
	 *
	 * @param string[]          $names    Names.
	 * @param array<int,string> $existing term_id => name.
	 * @return array<int,array{id:int,name:string}> id 0 = no match (a new term).
	 */
	public static function match_all( array $names, array $existing ) {
		$out  = array();
		$seen = array();
		foreach ( $names as $name ) {
			$id   = self::match( $name, $existing );
			$item = $id ? array(
				'id'   => $id,
				'name' => $existing[ $id ],
			) : array(
				'id'   => 0,
				'name' => $name,
			);
			$k    = $id ? 'id' . $id : self::key( $name );
			if ( ! isset( $seen[ $k ] ) ) {
				$seen[ $k ] = true;
				$out[]      = $item;
			}
		}
		return $out;
	}

	/**
	 * Find the existing term for a name: same name ignoring case, spaces, hyphens and a plural "s",
	 * or (for diets) the same Google diet, so "Plant-based" finds "Vegan".
	 *
	 * @param string            $name     Name.
	 * @param array<int,string> $existing term_id => name.
	 * @return int Term ID or 0.
	 */
	public static function match( $name, array $existing ) {
		$key  = self::key( $name );
		$diet = Schema::diet( $name );
		foreach ( $existing as $id => $term ) {
			if ( self::key( $term ) === $key ) {
				return (int) $id;
			}
		}
		if ( '' !== $diet ) {
			foreach ( $existing as $id => $term ) {
				if ( Schema::diet( $term ) === $diet ) {
					return (int) $id;
				}
			}
		}
		return 0;
	}

	/**
	 * Comparison key: lowercase letters and digits, trailing "s" dropped.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function key( $name ) {
		$k = preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( (string) $name ) ) );
		return strlen( $k ) > 3 ? preg_replace( '/s$/', '', $k ) : $k;
	}

	/**
	 * Names → term IDs, creating missing terms when the user may.
	 *
	 * @param array<string,string[]> $terms  taxonomy => names.
	 * @param bool                   $create Create missing terms.
	 * @return array<string,int[]>
	 */
	public static function ids( array $terms, $create = true ) {
		$out      = array();
		$existing = null;
		foreach ( $terms as $tax => $names ) {
			$tx = get_taxonomy( $tax );
			if ( ! $tx ) {
				continue;
			}
			if ( null === $existing ) {
				$existing = self::existing();
			}
			$have = isset( $existing[ $tax ] ) ? $existing[ $tax ] : array();
			foreach ( array_slice( (array) $names, 0, 10 ) as $name ) {
				$name = sanitize_text_field( (string) $name );
				if ( '' === $name ) {
					continue;
				}
				$id = self::match( $name, $have );
				if ( ! $id && $create && current_user_can( $tx->cap->edit_terms ) ) {
					$made = wp_insert_term( $name, $tax );
					if ( ! is_wp_error( $made ) ) {
						$id          = (int) $made['term_id'];
						$have[ $id ] = $name;
					}
				}
				if ( $id ) {
					$out[ $tax ][] = $id;
				}
			}
			if ( isset( $out[ $tax ] ) ) {
				$out[ $tax ] = array_values( array_unique( $out[ $tax ] ) );
			}
			$existing[ $tax ] = $have;
		}
		return $out;
	}

	/**
	 * The recipe as short plain text for the AI (title, summary, ingredients, a little of the method).
	 *
	 * @param string              $title  Recipe name.
	 * @param array<string,mixed> $recipe Normalized recipe.
	 * @return string
	 */
	public static function recipe_text( $title, array $recipe ) {
		$lines = array( 'Title: ' . wp_strip_all_tags( (string) $title ) );
		if ( ! empty( $recipe['summary'] ) ) {
			$lines[] = 'Summary: ' . wp_strip_all_tags( $recipe['summary'] );
		}
		if ( ! empty( $recipe['keywords'] ) ) {
			$lines[] = 'Keywords: ' . $recipe['keywords'];
		}
		$lines[] = 'Ingredients:';
		foreach ( (array) $recipe['ingredient_groups'] as $g ) {
			if ( ! empty( $g['title'] ) ) {
				$lines[] = $g['title'] . ':';
			}
			foreach ( (array) $g['items'] as $item ) {
				$lines[] = '- ' . ( '' !== $item['raw'] ? $item['raw'] : trim( $item['amount'] . ' ' . $item['unit'] . ' ' . $item['name'] ) );
			}
		}
		$method = array();
		foreach ( (array) $recipe['instruction_groups'] as $g ) {
			foreach ( (array) $g['steps'] as $step ) {
				$method[] = wp_strip_all_tags( $step['text'] );
			}
		}
		if ( $method ) {
			$lines[] = 'Method: ' . mb_substr( implode( ' ', $method ), 0, 1500 );
		}
		if ( ! empty( $recipe['notes'] ) ) {
			$lines[] = 'Notes: ' . mb_substr( wp_strip_all_tags( $recipe['notes'] ), 0, 500 );
		}
		return implode( "\n", $lines );
	}

	/**
	 * The tagging prompt, listing the site's existing terms.
	 *
	 * @param array<string,array<int,string>> $existing taxonomy => [ id => name ].
	 * @return string
	 */
	public static function prompt( array $existing = array() ) {
		$list    = static function ( $tax ) use ( $existing ) {
			return empty( $existing[ $tax ] ) ? '(none yet)' : implode( ', ', $existing[ $tax ] );
		};
		$course  = $list( 'scrumptious_course' );
		$cuisine = $list( 'scrumptious_cuisine' );
		$diet    = $list( 'scrumptious_diet' );

		$prompt = <<<'PROMPT'
You label a recipe for a food blog with its course, cuisine and diet.

The blog already uses these terms. Reuse them whenever one fits, spelled exactly as listed:
- Courses: {course}
- Cuisines: {cuisine}
- Diets: {diet}

Only suggest a new term when none of the existing ones fit. Use short, common names in Title Case ("Main", "Dessert", "Breakfast", "Side", "Soup", "Salad", "Snack", "Drink"; "Italian", "Mexican", "Thai").

Return exactly one JSON object and nothing else:
{ "course": ["Main"], "cuisine": ["Italian"], "diet": ["Vegetarian"] }

Rules:
- course: 1 or 2 values. cuisine: 0 or 1 value (use [] for a generic recipe with no clear cuisine). diet: 0 to 3 values.
- Diets must be true for EVERY ingredient as written. Readers with allergies rely on this, so when in doubt leave it out.
  - Vegetarian: no meat, poultry, fish, seafood, gelatin, or meat/fish stock or sauce (fish sauce, anchovies, Worcestershire).
  - Vegan: vegetarian and also no eggs, dairy (milk, butter, cheese, yogurt, cream, ghee) or honey.
  - Gluten-free: no wheat, flour, bread, pasta, barley, rye, couscous, regular soy sauce, beer or malt, unless the text says the ingredient is gluten-free. Oats only if marked gluten-free.
  - Dairy-free: no milk, butter, cheese, yogurt, cream or ghee.
  - Only use other diets (Low-carb, Keto, Low-fat, Halal, Kosher...) when the recipe text says so.
- Don't guess from the title alone; read the ingredients.
PROMPT;
		$prompt = strtr(
			$prompt,
			array(
				'{course}'  => $course,
				'{cuisine}' => $cuisine,
				'{diet}'    => $diet,
			)
		);

		/**
		 * Filter the AI prompt used to suggest course, cuisine and diet.
		 *
		 * @param string                          $prompt   Prompt.
		 * @param array<string,array<int,string>> $existing Existing terms, taxonomy => [ id => name ].
		 */
		return (string) apply_filters( 'scrumptious_tag_prompt', $prompt, $existing );
	}
}
