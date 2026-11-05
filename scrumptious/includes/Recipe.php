<?php
/**
 * Recipe data model: one versioned JSON object stored in post meta.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Loads, sanitizes and saves recipe data.
 */
final class Recipe {

	const META_KEY       = '_scrumptious_recipe';
	const SCHEMA_VERSION = 1;
	const MAX_ITEMS      = 250;

	const MAX_GALLERY = 30;

	const NUTRITION_FIELDS = array(
		'serving_size',
		'calories',
		'carbohydrates',
		'protein',
		'fat',
		'saturated_fat',
		'trans_fat',
		'unsaturated_fat',
		'cholesterol',
		'sodium',
		'fiber',
		'sugar',
	);

	/**
	 * Register the meta field (exposed to REST so the block editor can edit it).
	 */
	public static function register_meta() {
		register_post_meta(
			Post_Type::SLUG,
			self::META_KEY,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_meta' ),
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	/**
	 * Keep recipe data out of REST responses for password-protected recipes
	 * (core hides the content of protected posts, but not their meta).
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post     Recipe.
	 * @return \WP_REST_Response
	 */
	public static function rest_protect( $response, $post ) {
		if ( post_password_required( $post ) && ! current_user_can( 'edit_post', $post->ID ) ) {
			$data = $response->get_data();
			if ( isset( $data['meta'][ self::META_KEY ] ) ) {
				$data['meta'][ self::META_KEY ] = '';
				$response->set_data( $data );
			}
		}
		return $response;
	}

	/**
	 * Empty recipe structure.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'v'                  => self::SCHEMA_VERSION,
			'summary'            => '',
			'author_name'        => '',
			'servings'           => 0,
			'servings_unit'      => '',
			'times'              => array(
				'prep'  => 0,
				'cook'  => 0,
				'rest'  => 0,
				'total' => 0,
			),
			'ingredient_groups'  => array(),
			'instruction_groups' => array(),
			'notes'              => '',
			'video_url'          => '',
			'keywords'           => '',
			'nutrition'          => array_fill_keys( self::NUTRITION_FIELDS, '' ),
			'gallery'            => array(),
			'source_url'         => '',
			'source_name'        => '',
		);
	}

	/**
	 * Load a normalized recipe for a recipe post.
	 *
	 * @param int $post_id Recipe post ID.
	 * @return array<string,mixed>
	 */
	public static function get( $post_id ) {
		$json = get_post_meta( (int) $post_id, self::META_KEY, true );
		$data = is_string( $json ) && '' !== $json ? json_decode( $json, true ) : array();
		return self::normalize( is_array( $data ) ? $data : array() );
	}

	/**
	 * Save a recipe array.
	 *
	 * @param int                 $post_id Recipe post ID.
	 * @param array<string,mixed> $data    Data.
	 * @return bool
	 */
	public static function save( $post_id, array $data ) {
		return (bool) update_post_meta( (int) $post_id, self::META_KEY, wp_slash( self::encode( self::normalize( $data ) ) ) );
	}

	/**
	 * Meta sanitize callback: accepts a JSON string, returns sanitized JSON.
	 *
	 * @param mixed $value Raw meta value.
	 * @return string
	 */
	public static function sanitize_meta( $value ) {
		if ( is_array( $value ) ) {
			$data = $value;
		} else {
			$data = json_decode( (string) $value, true );
		}
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		return self::encode( self::normalize( $data ) );
	}

	/**
	 * JSON-encode for storage.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return string
	 */
	public static function encode( array $data ) {
		return (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Sanitize + fill defaults. Idempotent: normalize(normalize(x)) === normalize(x).
	 *
	 * @param array<string,mixed> $data Untrusted input.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $data ) {
		$out = self::defaults();

		$out['summary']       = self::rich( self::str( $data, 'summary' ) );
		$out['author_name']   = sanitize_text_field( self::str( $data, 'author_name' ) );
		$out['servings']      = self::num( isset( $data['servings'] ) ? $data['servings'] : 0 );
		$out['servings_unit'] = sanitize_text_field( self::str( $data, 'servings_unit' ) );
		$out['notes']         = self::rich( self::str( $data, 'notes' ) );

		// Gallery: attachment IDs, in order, no duplicates.
		$gallery            = isset( $data['gallery'] ) && is_array( $data['gallery'] ) ? $data['gallery'] : array();
		$out['gallery']     = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $gallery ) ) ) ), 0, self::MAX_GALLERY );
		$out['video_url']   = esc_url_raw( self::str( $data, 'video_url' ) );
		$out['source_url']  = esc_url_raw( self::str( $data, 'source_url' ) );
		$out['source_name'] = sanitize_text_field( self::str( $data, 'source_name' ) );
		$out['keywords']    = sanitize_text_field( self::str( $data, 'keywords' ) );

		// Times (minutes).
		$times = isset( $data['times'] ) && is_array( $data['times'] ) ? $data['times'] : array();
		foreach ( array( 'prep', 'cook', 'rest', 'total' ) as $k ) {
			$out['times'][ $k ] = isset( $times[ $k ] ) ? max( 0, min( 525600, (int) $times[ $k ] ) ) : 0;
		}
		if ( 0 === $out['times']['total'] ) {
			$out['times']['total'] = $out['times']['prep'] + $out['times']['cook'] + $out['times']['rest'];
		}

		// Ingredients.
		$groups = isset( $data['ingredient_groups'] ) && is_array( $data['ingredient_groups'] ) ? $data['ingredient_groups'] : array();
		foreach ( array_slice( array_values( $groups ), 0, 50 ) as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$items = array();
			$raw   = isset( $group['items'] ) && is_array( $group['items'] ) ? $group['items'] : array();
			foreach ( array_slice( array_values( $raw ), 0, self::MAX_ITEMS ) as $item ) {
				$clean = self::ingredient( $item );
				if ( null !== $clean ) {
					$items[] = $clean;
				}
			}
			$title = sanitize_text_field( self::str( $group, 'title' ) );
			// Empty groups are kept so the editor can show a group before anything is typed in it.
			$out['ingredient_groups'][] = array(
				'title' => $title,
				'items' => $items,
			);
		}

		// Instructions.
		$groups = isset( $data['instruction_groups'] ) && is_array( $data['instruction_groups'] ) ? $data['instruction_groups'] : array();
		foreach ( array_slice( array_values( $groups ), 0, 50 ) as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$steps = array();
			$raw   = isset( $group['steps'] ) && is_array( $group['steps'] ) ? $group['steps'] : array();
			foreach ( array_slice( array_values( $raw ), 0, self::MAX_ITEMS ) as $step ) {
				if ( is_string( $step ) ) {
					$step = array( 'text' => $step );
				}
				if ( ! is_array( $step ) ) {
					continue;
				}
				$text = self::rich( self::str( $step, 'text' ) );
				if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
					continue;
				}
				$steps[] = array(
					'text'     => $text,
					'image_id' => isset( $step['image_id'] ) ? absint( $step['image_id'] ) : 0,
					'timer'    => isset( $step['timer'] ) ? max( 0, min( 10080, (int) $step['timer'] ) ) : 0,
				);
			}
			$title                       = sanitize_text_field( self::str( $group, 'title' ) );
			$out['instruction_groups'][] = array(
				'title' => $title,
				'steps' => $steps,
			);
		}

		// Nutrition (free text numbers, e.g. "250" or "12.5").
		$nutrition = isset( $data['nutrition'] ) && is_array( $data['nutrition'] ) ? $data['nutrition'] : array();
		foreach ( self::NUTRITION_FIELDS as $k ) {
			$v                      = sanitize_text_field( self::str( $nutrition, $k ) );
			$out['nutrition'][ $k ] = 'serving_size' === $k ? $v : preg_replace( '/[^0-9.]/', '', $v );
		}

		return $out;
	}

	/**
	 * Groups that have something to show (non-empty item/step lists).
	 *
	 * @param array<int,array<string,mixed>> $groups Groups.
	 * @param string                         $key    'items' or 'steps'.
	 * @return array<int,array<string,mixed>>
	 */
	public static function filled( array $groups, $key ) {
		return array_values(
			array_filter(
				$groups,
				static function ( $g ) use ( $key ) {
					return ! empty( $g[ $key ] );
				}
			)
		);
	}

	/**
	 * Sanitize one ingredient. Accepts a string or an item with `raw` and/or structured fields.
	 * If only `raw` is present (or `reparse` is set) the line is parsed.
	 *
	 * @param mixed $item Item.
	 * @return array<string,mixed>|null
	 */
	private static function ingredient( $item ) {
		if ( is_string( $item ) ) {
			$item = array( 'raw' => $item );
		}
		if ( ! is_array( $item ) ) {
			return null;
		}

		$raw = sanitize_text_field( self::str( $item, 'raw' ) );

		$needs_parse = ! empty( $item['reparse'] ) || ( '' !== $raw && '' === self::str( $item, 'name' ) );
		if ( $needs_parse ) {
			$item = Ingredient_Parser::parse( $raw );
		}

		$name = sanitize_text_field( self::str( $item, 'name' ) );
		if ( '' === $name && '' === $raw ) {
			return null;
		}

		$amount_value = isset( $item['amount_value'] ) && is_numeric( $item['amount_value'] ) ? (float) $item['amount_value'] : null;
		$amount_max   = isset( $item['amount_max'] ) && is_numeric( $item['amount_max'] ) ? (float) $item['amount_max'] : null;

		// Only canonical unit keys are allowed (whitelist).
		$unit_key = self::str( $item, 'unit_key' );
		if ( ! isset( Ingredient_Parser::UNITS[ $unit_key ] ) ) {
			$unit_key = '';
		}

		return array(
			'raw'          => '' !== $raw ? $raw : $name,
			'amount'       => sanitize_text_field( self::str( $item, 'amount' ) ),
			'amount_value' => $amount_value,
			'amount_max'   => $amount_max,
			'unit'         => sanitize_text_field( self::str( $item, 'unit' ) ),
			'unit_key'     => $unit_key,
			'name'         => $name,
			'notes'        => sanitize_text_field( self::str( $item, 'notes' ) ),
			'link'         => esc_url_raw( self::str( $item, 'link' ) ),
		);
	}

	/**
	 * Get a string field.
	 *
	 * @param array<string,mixed> $arr Array.
	 * @param string              $key Key.
	 * @return string
	 */
	private static function str( array $arr, $key ) {
		return isset( $arr[ $key ] ) && is_scalar( $arr[ $key ] ) ? (string) $arr[ $key ] : '';
	}

	/**
	 * Positive number, max 2 decimals.
	 *
	 * @param mixed $v Value.
	 * @return float|int
	 */
	private static function num( $v ) {
		$n = is_numeric( $v ) ? (float) $v : Ingredient_Parser::to_number( (string) $v );
		$n = max( 0, min( 10000, round( $n, 2 ) ) );
		return floor( $n ) === $n ? (int) $n : $n;
	}

	/**
	 * Limited inline HTML (bold, italic, links, line breaks).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function rich( $html ) {
		// Drop script/style blocks entirely (wp_kses would keep their text content).
		$html = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $html );
		return trim(
			wp_kses(
				$html,
				array(
					'strong' => array(),
					'b'      => array(),
					'em'     => array(),
					'i'      => array(),
					'br'     => array(),
					'a'      => array(
						'href'   => true,
						'target' => true,
						'rel'    => true,
					),
				)
			)
		);
	}
}
