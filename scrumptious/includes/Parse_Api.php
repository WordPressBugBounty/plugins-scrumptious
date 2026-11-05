<?php
/**
 * Paste & Parse REST endpoint: POST /scrumptious/v1/parse { text, mode }.
 *
 * Uses the site's AI provider when one is set up, and the built-in parser otherwise
 * (or when the AI call fails), so Paste & Parse always returns something useful.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Parse endpoint and the AI extraction prompt.
 */
final class Parse_Api {

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
			'/parse',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'text' => array(
						'type'     => 'string',
						'required' => true,
					),
					'mode' => array(
						'type'    => 'string',
						'enum'    => array( 'auto', 'basic', 'ai' ),
						'default' => 'auto',
					),
				),
			)
		);
	}

	/**
	 * Handle a parse request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle( $request ) {
		$text = mb_substr( (string) $request->get_param( 'text' ), 0, Recipe_Parser::MAX_CHARS );
		if ( '' === trim( $text ) ) {
			return new \WP_Error( 'scrumptious_empty', __( 'Paste a recipe first.', 'scrumptious' ), array( 'status' => 400 ) );
		}
		$mode = (string) $request->get_param( 'mode' );
		if ( 'basic' !== $mode && Ai::configured() && ! Ai::within_limit() ) {
			$out           = self::parse( $text, 'basic' );
			$out['notice'] = trim( __( 'You\'ve reached this hour\'s AI limit, so the built-in parser was used.', 'scrumptious' ) . ' ' . $out['notice'] );
			return rest_ensure_response( $out );
		}
		return rest_ensure_response( self::parse( $text, $mode ) );
	}

	/**
	 * Parse text into { title, recipe, source, notice }.
	 *
	 * @param string $text Text.
	 * @param string $mode auto (AI if set up) | ai | basic.
	 * @return array<string,mixed>
	 */
	public static function parse( $text, $mode = 'auto' ) {
		$notice = '';
		$result = null;
		$source = 'basic';
		$terms  = array();

		if ( 'basic' !== $mode && Ai::configured() ) {
			$ai = Ai::json( self::prompt( Tagger::existing() ), $text );
			if ( is_wp_error( $ai ) ) {
				/* translators: %s: AI error message */
				$notice = sprintf( __( 'AI could not read this one, so the built-in parser was used. %s', 'scrumptious' ), $ai->get_error_message() );
			} else {
				$result = self::from_ai( $ai );
				$source = 'ai';
				$terms  = Tagger::ids( Tagger::from_ai( $ai ) );
			}
		} elseif ( 'ai' === $mode ) {
			$notice = __( 'AI is not set up, so the built-in parser was used. Connect a provider under Recipes → Settings → AI for better results.', 'scrumptious' );
		}

		if ( null === $result ) {
			$result = Recipe_Parser::parse( $text );
		}

		$out = array(
			'title'  => sanitize_text_field( $result['title'] ),
			'recipe' => Recipe::normalize( $result['recipe'] ),
			'source' => $source,
			'notice' => $notice,
			'terms'  => $terms,
		);

		/**
		 * Filter a Paste & Parse result before it is returned to the editor.
		 *
		 * @param array<string,mixed> $out  { title, recipe, source, notice, terms }. terms: taxonomy => term IDs (AI only).
		 * @param string              $text The pasted text.
		 */
		return (array) apply_filters( 'scrumptious_parse_result', $out, $text );
	}

	/**
	 * Map the AI's JSON onto recipe data (normalized later).
	 *
	 * @param array<string,mixed> $ai AI JSON.
	 * @return array{title:string,recipe:array<string,mixed>}
	 */
	public static function from_ai( array $ai ) {
		$str = static function ( $v ) {
			return is_scalar( $v ) ? trim( (string) $v ) : '';
		};

		$times = array();
		foreach ( array( 'prep', 'cook', 'rest', 'total' ) as $k ) {
			$times[ $k ] = isset( $ai['times'][ $k ] ) && is_numeric( $ai['times'][ $k ] ) ? (int) round( (float) $ai['times'][ $k ] ) : 0;
		}

		$ing = array();
		foreach ( isset( $ai['ingredient_groups'] ) && is_array( $ai['ingredient_groups'] ) ? $ai['ingredient_groups'] : array() as $g ) {
			if ( ! is_array( $g ) ) {
				continue;
			}
			$items = array();
			foreach ( isset( $g['items'] ) && is_array( $g['items'] ) ? $g['items'] : array() as $item ) {
				$line = is_array( $item ) ? $str( isset( $item['raw'] ) ? $item['raw'] : ( isset( $item['text'] ) ? $item['text'] : '' ) ) : $str( $item );
				if ( '' !== $line ) {
					$items[] = $line;
				}
			}
			$ing[] = array(
				'title' => $str( isset( $g['title'] ) ? $g['title'] : '' ),
				'items' => $items,
			);
		}

		$steps = array();
		foreach ( isset( $ai['instruction_groups'] ) && is_array( $ai['instruction_groups'] ) ? $ai['instruction_groups'] : array() as $g ) {
			if ( ! is_array( $g ) ) {
				continue;
			}
			$list = array();
			foreach ( isset( $g['steps'] ) && is_array( $g['steps'] ) ? $g['steps'] : array() as $step ) {
				$line = is_array( $step ) ? $str( isset( $step['text'] ) ? $step['text'] : '' ) : $str( $step );
				$line = preg_replace( '/^(?:step\s*)?\d{1,2}\s*[.):\-–]\s*/i', '', $line );
				if ( '' !== $line ) {
					$list[] = array( 'text' => esc_html( $line ) );
				}
			}
			$steps[] = array(
				'title' => $str( isset( $g['title'] ) ? $g['title'] : '' ),
				'steps' => $list,
			);
		}

		$nutrition = array();
		foreach ( Recipe::NUTRITION_FIELDS as $f ) {
			if ( isset( $ai['nutrition'][ $f ] ) && is_scalar( $ai['nutrition'][ $f ] ) && '' !== (string) $ai['nutrition'][ $f ] ) {
				$nutrition[ $f ] = (string) $ai['nutrition'][ $f ];
			}
		}

		return array(
			'title'  => $str( isset( $ai['title'] ) ? $ai['title'] : '' ),
			'recipe' => array(
				'summary'            => esc_html( $str( isset( $ai['summary'] ) ? $ai['summary'] : '' ) ),
				'servings'           => isset( $ai['servings'] ) && is_numeric( $ai['servings'] ) ? (float) $ai['servings'] : 0,
				'servings_unit'      => $str( isset( $ai['servings_unit'] ) ? $ai['servings_unit'] : '' ),
				'times'              => $times,
				'ingredient_groups'  => $ing ? $ing : array(
					array(
						'title' => '',
						'items' => array(),
					),
				),
				'instruction_groups' => $steps ? $steps : array(
					array(
						'title' => '',
						'steps' => array(),
					),
				),
				'notes'              => esc_html( $str( isset( $ai['notes'] ) ? $ai['notes'] : '' ) ),
				'keywords'           => $str( isset( $ai['keywords'] ) ? $ai['keywords'] : '' ),
				'nutrition'          => $nutrition,
			),
		);
	}

	/**
	 * The extraction prompt.
	 *
	 * @param array<string,array<int,string>> $existing Existing course/cuisine/diet terms, taxonomy => [ id => name ].
	 * @return string
	 */
	public static function prompt( array $existing = array() ) {
		$prompt = <<<'PROMPT'
You turn recipe text that a food blogger pasted (from a website, document or email) into structured JSON.

Use ONLY what is in the text. Never invent quantities, times, servings, nutrition, ingredients or steps. Leave a field empty (null, "" or []) when the text doesn't say.

Return exactly one JSON object with this shape and nothing else:
{
  "title": "Recipe name",
  "summary": "The recipe's one- or two-sentence description if the text has one, else \"\"",
  "servings": 24,
  "servings_unit": "cookies",
  "times": { "prep": 15, "cook": 10, "rest": 0, "total": 25 },
  "ingredient_groups": [
    { "title": "", "items": ["1 cup (200 g) granulated sugar", "2 large eggs, room temperature"] },
    { "title": "For the frosting", "items": ["..."] }
  ],
  "instruction_groups": [
    { "title": "", "steps": ["Preheat the oven to 350°F (175°C).", "..."] }
  ],
  "notes": "Tips, substitutions and storage notes, as plain text",
  "keywords": "comma, separated, keywords if given",
  "nutrition": { "serving_size": "", "calories": 0, "carbohydrates": 0, "protein": 0, "fat": 0, "saturated_fat": 0, "cholesterol": 0, "sodium": 0, "fiber": 0, "sugar": 0 },
  "course": ["Dessert"],
  "cuisine": ["American"],
  "diet": ["Vegetarian"]
}

Rules:
- times are whole minutes. "1 hour 15 minutes" = 75.
- servings is a number; servings_unit is what is being counted ("cookies", "slices"), or "" when it's people/servings.
- Each ingredient is ONE line exactly as written in the text (amount, unit, name, notes). Don't convert units or reword. Remove bullets and checkboxes.
- Use ingredient/instruction group titles only when the text has sub-headings like "For the sauce".
- Each step is one instruction paragraph without its number. Don't merge or split steps unless the text clearly does.
- Nutrition values are numbers without units (g, mg, kcal), only when the text lists them; otherwise leave nutrition as {}.
- Ignore everything that isn't the recipe: stories, ads, navigation, comments, ratings, "jump to recipe", social buttons.
- course, cuisine and diet label the recipe (these may be inferred; see below).
PROMPT;

		// Course / cuisine / diet: reuse the tagging rules, minus their own output format.
		$tags    = Tagger::prompt( $existing );
		$tags    = preg_replace( '/^Return exactly one JSON object.*?\n\n/ms', '', $tags );
		$tags    = preg_replace( '/^You label a recipe[^\n]*\n\n/', '', $tags );
		$prompt .= "\n\nLabelling course, cuisine and diet:\n" . $tags;

		/**
		 * Filter the AI prompt used by Paste & Parse.
		 *
		 * @param string                          $prompt   Prompt.
		 * @param array<string,array<int,string>> $existing Existing course/cuisine/diet terms.
		 */
		return (string) apply_filters( 'scrumptious_parse_prompt', $prompt, $existing );
	}
}
