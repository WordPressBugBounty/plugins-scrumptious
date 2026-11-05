<?php
/**
 * Import from Create by Mediavine (custom tables mv_creations, mv_supplies, mv_nutrition).
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Create by Mediavine importer (recipe cards only; lists and how-tos are skipped).
 */
final class Import_Create extends Importer {

	/**
	 * {@inheritDoc}
	 */
	public function slug() {
		return 'create';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'Create by Mediavine';
	}

	/**
	 * Table name with prefix, or '' when it doesn't exist.
	 *
	 * @param string $name Table without prefix.
	 * @return string
	 */
	private static function table( $name ) {
		global $wpdb;
		static $exists = array();
		$table         = $wpdb->prefix . $name;
		if ( ! isset( $exists[ $table ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- checking for another plugin's table.
			$exists[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		}
		return $exists[ $table ] ? $table : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function ids() {
		global $wpdb;
		$table = self::table( 'mv_creations' );
		if ( '' === $table ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table.
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE type = 'recipe' ORDER BY id ASC", $table ) ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function markers() {
		return array( '%wp:mv/recipe%', '%[mv_create %' );
	}

	/**
	 * Read one source recipe.
	 *
	 * @param int $id Source ID.
	 * @return array<string,mixed>|null
	 */
	public function load( $id ) {
		global $wpdb;
		$table = self::table( 'mv_creations' );
		if ( '' === $table ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table.
		$c = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, (int) $id ), ARRAY_A );
		if ( ! $c || 'recipe' !== $c['type'] ) {
			return null;
		}
		$v = static function ( $key ) use ( $c ) {
			return isset( $c[ $key ] ) ? (string) $c[ $key ] : '';
		};

		// Ingredients, grouped and ordered.
		$ingredients = array();
		$supplies    = self::table( 'mv_supplies' );
		if ( '' !== $supplies ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE creation = %d AND type = 'ingredients' ORDER BY position ASC, id ASC", $supplies, (int) $id ), ARRAY_A );
			$by   = array();
			foreach ( (array) $rows as $row ) {
				$line = self::text( isset( $row['original_text'] ) ? $row['original_text'] : '' );
				if ( '' === $line ) {
					$line = trim( implode( ' ', array_filter( array( self::text( $row['amount'] ), self::text( $row['unit'] ), self::text( $row['item'] ) ) ) ) );
				}
				$note = self::text( isset( $row['note'] ) ? $row['note'] : '' );
				if ( '' !== $note && false === strpos( $line, $note ) ) {
					$line .= ', ' . $note;
				}
				if ( '' === $line ) {
					continue;
				}
				$group = self::text( isset( $row['group'] ) ? $row['group'] : '' );
				// Create stores the default group as "mv-has-no-group".
				$group          = 'mv-has-no-group' === $group ? '' : $group;
				$by[ $group ][] = $line;
			}
			foreach ( $by as $title => $items ) {
				$ingredients[] = array(
					'title' => (string) $title,
					'items' => $items,
				);
			}
		}

		// Nutrition.
		$nutrition = array();
		$servings  = 0.0;
		$ntable    = self::table( 'mv_nutrition' );
		if ( '' !== $ntable ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table.
			$n   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE creation = %d', $ntable, (int) $id ), ARRAY_A );
			$map = array(
				'serving_size'    => 'serving_size',
				'calories'        => 'calories',
				'total_fat'       => 'fat',
				'saturated_fat'   => 'saturated_fat',
				'trans_fat'       => 'trans_fat',
				'unsaturated_fat' => 'unsaturated_fat',
				'cholesterol'     => 'cholesterol',
				'sodium'          => 'sodium',
				'carbohydrates'   => 'carbohydrates',
				'fiber'           => 'fiber',
				'sugar'           => 'sugar',
				'protein'         => 'protein',
			);
			foreach ( $map as $from => $to ) {
				if ( is_array( $n ) && isset( $n[ $from ] ) && '' !== (string) $n[ $from ] ) {
					$nutrition[ $to ] = (string) $n[ $from ];
				}
			}
			if ( is_array( $n ) && ! empty( $n['number_of_servings'] ) ) {
				$servings = (float) $n['number_of_servings'];
			}
		}

		list( $yield_n, $unit ) = self::yield_parts( $v( 'yield' ) );
		$servings               = $yield_n ? $yield_n : $servings;

		$post  = $v( 'object_id' ) ? get_post( (int) $v( 'object_id' ) ) : null;
		$terms = array(
			'scrumptious_course'  => array_filter( array( self::term_name( (int) $v( 'category' ) ) ) ),
			'scrumptious_cuisine' => array_filter( array( self::term_name( (int) $v( 'secondary_term' ) ) ) ),
			'scrumptious_diet'    => array_filter( array( Import_Tasty::diet_name( $v( 'suitable_for_diet' ) ) ) ),
		);

		$video = '';
		$ext   = json_decode( $v( 'external_video' ), true );
		if ( is_array( $ext ) && ! empty( $ext['contentUrl'] ) ) {
			$video = (string) $ext['contentUrl'];
		}

		return array(
			'title'    => html_entity_decode( $v( 'title' ), ENT_QUOTES, 'UTF-8' ),
			'recipe'   => array(
				'summary'            => $v( 'description' ),
				'author_name'        => self::text( $v( 'author' ) ),
				'servings'           => $servings,
				'servings_unit'      => $unit,
				'times'              => array(
					'prep'  => (int) round( (int) $v( 'prep_time' ) / 60 ),
					'cook'  => (int) round( (int) $v( 'active_time' ) / 60 ),
					'rest'  => (int) round( (int) $v( 'additional_time' ) / 60 ),
					'total' => (int) round( (int) $v( 'total_time' ) / 60 ),
				),
				'ingredient_groups'  => $ingredients,
				'instruction_groups' => self::groups( $v( 'instructions' ), 'steps' ),
				'notes'              => $v( 'notes' ),
				'video_url'          => esc_url_raw( $video ),
				'keywords'           => self::text( $v( 'keywords' ) ),
				'nutrition'          => $nutrition,
			),
			'image_id' => (int) $v( 'thumbnail_id' ),
			'status'   => '' !== $v( 'published' ) || ( $post && 'publish' === $post->post_status ) ? 'publish' : 'draft',
			'date'     => $post ? $post->post_date : ( '' !== $v( 'created' ) ? $v( 'created' ) : '' ),
			'author'   => $post ? (int) $post->post_author : 0,
			'terms'    => $terms,
			'rating'   => array(
				'count' => (int) $v( 'rating_count' ),
				'total' => (float) $v( 'rating' ) * (int) $v( 'rating_count' ),
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
		$content = self::swap_block(
			$content,
			'mv/recipe',
			static function ( $a ) use ( $map ) {
				$id = isset( $a['id'] ) ? (int) $a['id'] : 0;
				return isset( $map[ $id ] ) ? self::recipe_block( $map[ $id ] ) : null;
			}
		);
		$content = self::swap_shortcode(
			$content,
			'mv_create',
			static function ( $a ) use ( $map ) {
				$id   = isset( $a['key'] ) ? (int) $a['key'] : 0;
				$type = isset( $a['type'] ) ? $a['type'] : 'recipe';
				return 'recipe' === $type && isset( $map[ $id ] ) ? self::recipe_block( $map[ $id ] ) : null;
			}
		);
		return self::swap_shortcode(
			$content,
			'mv_create_jtr',
			static function () {
				return self::jump_block();
			}
		);
	}
}
