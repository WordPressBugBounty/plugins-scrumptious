<?php
/**
 * Moves recipes from other plugins into Scrumptious and swaps their cards in posts.
 *
 * Steps (run in small batches from Recipes → Import so big sites never time out):
 * 1. Import recipes — each source recipe becomes a Scrumptious recipe (skipped if already imported).
 * 2. Update posts — the other plugin's blocks/shortcodes become Scrumptious blocks.
 *    The original content is kept so this can be undone.
 * Source recipes are never changed or deleted.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Importer registry, REST endpoints and the import itself.
 */
final class Import_Manager {

	const SOURCE_META   = '_scrumptious_import_source';
	const ORIGINAL_META = '_scrumptious_import_original_content';
	const BATCH         = 10;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * All importers, keyed by slug.
	 *
	 * @return array<string,Importer>
	 */
	public static function importers() {
		$list = array();
		foreach ( array( new Import_Wprm(), new Import_Tasty(), new Import_Create() ) as $imp ) {
			$list[ $imp->slug() ] = $imp;
		}
		/**
		 * Add or remove importers. Each must extend Scrumptious\Importer.
		 *
		 * @param array<string,Importer> $list Importers by slug.
		 */
		$list = (array) apply_filters( 'scrumptious_importers', $list );
		return array_filter(
			$list,
			static function ( $imp ) {
				return $imp instanceof Importer;
			}
		);
	}

	/**
	 * Importer by slug.
	 *
	 * @param string $slug Slug.
	 * @return Importer|null
	 */
	public static function get( $slug ) {
		$all = self::importers();
		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}

	/**
	 * Routes (admins only).
	 */
	public static function routes() {
		$admin = static function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route(
			'scrumptious/v1',
			'/import',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_status' ),
				'permission_callback' => $admin,
			)
		);
		$args = array(
			'cursor' => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
		);
		register_rest_route(
			'scrumptious/v1',
			'/import/(?P<source>[a-z0-9_-]+)/recipes',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_recipes' ),
				'permission_callback' => $admin,
				'args'                => $args,
			)
		);
		register_rest_route(
			'scrumptious/v1',
			'/import/(?P<source>[a-z0-9_-]+)/posts',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_posts' ),
				'permission_callback' => $admin,
				'args'                => $args,
			)
		);
		register_rest_route(
			'scrumptious/v1',
			'/import/(?P<source>[a-z0-9_-]+)/undo',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_undo' ),
				'permission_callback' => $admin,
				'args'                => $args,
			)
		);
	}

	/**
	 * Status of every source that has recipes on this site.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function status() {
		$out = array();
		foreach ( self::importers() as $slug => $imp ) {
			$ids = $imp->ids();
			if ( ! $ids ) {
				continue;
			}
			$out[] = array(
				'source'   => $slug,
				'label'    => $imp->label(),
				'total'    => count( $ids ),
				'imported' => count( self::map( $slug ) ),
				'posts'    => count( self::candidate_posts( $imp, 0, 1000 ) ),
				'changed'  => count( self::changed_posts( $slug, 0, 1000 ) ),
			);
		}
		return $out;
	}

	/**
	 * GET /import.
	 *
	 * @return \WP_REST_Response
	 */
	public static function rest_status() {
		return rest_ensure_response( self::status() );
	}

	/**
	 * Resolve the importer for a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return Importer|\WP_Error
	 */
	private static function importer_for( $request ) {
		$imp = self::get( (string) $request['source'] );
		return $imp ? $imp : new \WP_Error( 'scrumptious_import_source', __( 'Unknown import source.', 'scrumptious' ), array( 'status' => 404 ) );
	}

	/**
	 * POST /import/{source}/recipes — import the next batch after `cursor` (a source ID).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_recipes( $request ) {
		$imp = self::importer_for( $request );
		if ( is_wp_error( $imp ) ) {
			return $imp;
		}
		$cursor  = (int) $request['cursor'];
		$ids     = array_values(
			array_filter(
				$imp->ids(),
				static function ( $id ) use ( $cursor ) {
					return $id > $cursor;
				}
			)
		);
		$batch   = array_slice( $ids, 0, self::BATCH );
		$results = array();
		foreach ( $batch as $id ) {
			$results[] = self::import_one( $imp, $id );
			$cursor    = $id;
		}
		return rest_ensure_response(
			array(
				'results' => $results,
				'cursor'  => $cursor,
				'done'    => count( $ids ) <= self::BATCH,
			)
		);
	}

	/**
	 * Import one source recipe.
	 *
	 * @param Importer $imp Importer.
	 * @param int      $id  Source ID.
	 * @return array{source_id:int,status:string,id:int,title:string,message:string}
	 */
	public static function import_one( Importer $imp, $id ) {
		$result = array(
			'source_id' => (int) $id,
			'status'    => 'error',
			'id'        => 0,
			'title'     => '',
			'message'   => '',
		);

		$map = self::map( $imp->slug() );
		if ( isset( $map[ (int) $id ] ) ) {
			$result['status'] = 'skipped';
			$result['id']     = $map[ (int) $id ];
			$result['title']  = get_the_title( $map[ (int) $id ] );
			return $result;
		}

		$data = $imp->load( $id );
		if ( null === $data ) {
			$result['message'] = __( 'Could not read this recipe.', 'scrumptious' );
			return $result;
		}

		/**
		 * Adjust imported data before the Scrumptious recipe is created.
		 *
		 * @param array    $data      Loaded data (title, recipe, image_id, status, date, author, terms, rating).
		 * @param Importer $imp       Importer.
		 * @param int      $source_id Source recipe ID.
		 */
		$data   = (array) apply_filters( 'scrumptious_import_data', $data, $imp, (int) $id );
		$recipe = Recipe::normalize( (array) $data['recipe'] );
		$status = in_array( $data['status'], array( 'draft', 'pending' ), true ) ? 'draft' : ( 'private' === $data['status'] ? 'private' : 'publish' );
		$author = (int) $data['author'] && get_userdata( (int) $data['author'] ) ? (int) $data['author'] : get_current_user_id();

		$post = array(
			'post_type'    => Post_Type::SLUG,
			'post_title'   => wp_slash( '' !== trim( $data['title'] ) ? $data['title'] : __( 'Imported recipe', 'scrumptious' ) ),
			'post_status'  => $status,
			'post_author'  => $author,
			'post_content' => Post_Type::CONTENT,
			'meta_input'   => array(
				Recipe::META_KEY  => wp_slash( Recipe::encode( $recipe ) ),
				self::SOURCE_META => $imp->slug() . ':' . (int) $id,
			),
		);
		if ( ! empty( $data['date'] ) && '0000-00-00 00:00:00' !== $data['date'] ) {
			$post['post_date'] = $data['date'];
		}
		$new_id = wp_insert_post( $post, true );
		if ( is_wp_error( $new_id ) ) {
			$result['message'] = $new_id->get_error_message();
			return $result;
		}

		if ( ! empty( $data['image_id'] ) && wp_attachment_is_image( (int) $data['image_id'] ) ) {
			set_post_thumbnail( $new_id, (int) $data['image_id'] );
		}
		foreach ( (array) $data['terms'] as $tax => $names ) {
			$names = array_values( array_filter( array_map( 'sanitize_text_field', (array) $names ) ) );
			if ( $names && taxonomy_exists( $tax ) ) {
				wp_set_object_terms( $new_id, $names, $tax );
			}
		}
		if ( ! empty( $data['rating']['count'] ) ) {
			Ratings::set_base( $new_id, (int) $data['rating']['count'], (float) $data['rating']['total'] );
		}

		/**
		 * Fires after a recipe was imported.
		 *
		 * @param int      $new_id    Scrumptious recipe ID.
		 * @param int      $source_id Source recipe ID.
		 * @param Importer $imp       Importer.
		 * @param array    $data      Loaded data.
		 */
		do_action( 'scrumptious_imported_recipe', $new_id, (int) $id, $imp, $data );

		$result['status'] = 'imported';
		$result['id']     = (int) $new_id;
		$result['title']  = get_the_title( $new_id );
		return $result;
	}

	/**
	 * Source ID => Scrumptious ID for everything imported from a source.
	 *
	 * @param string $slug Source slug.
	 * @return array<int,int>
	 */
	public static function map( $slug ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- one indexed meta_key lookup.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s", self::SOURCE_META, $wpdb->esc_like( $slug . ':' ) . '%' ) );
		$map  = array();
		foreach ( (array) $rows as $row ) {
			if ( 'trash' === get_post_status( (int) $row->post_id ) ) {
				continue;
			}
			$map[ (int) substr( $row->meta_value, strlen( $slug ) + 1 ) ] = (int) $row->post_id;
		}
		return $map;
	}

	/**
	 * Posts (any type) that contain this source's recipe cards, after ID $after.
	 *
	 * @param Importer $imp   Importer.
	 * @param int      $after Last ID seen.
	 * @param int      $limit Max.
	 * @return int[]
	 */
	public static function candidate_posts( Importer $imp, $after, $limit ) {
		global $wpdb;
		$ids = array();
		foreach ( $imp->markers() as $like ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- content search for another plugin's markup.
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','wprm_recipe','tasty_recipe','mv_create','scrumptious_recipe') AND post_status NOT IN ('trash','auto-draft') AND post_content LIKE %s AND ID > %d ORDER BY ID ASC LIMIT %d", $like, (int) $after, (int) $limit ) );
			$ids   = array_merge( $ids, array_map( 'intval', $found ) );
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return array_slice( $ids, 0, (int) $limit );
	}

	/**
	 * Posts changed by an import from this source (they keep their original content).
	 *
	 * @param string $slug  Source slug.
	 * @param int    $after Last ID seen.
	 * @param int    $limit Max.
	 * @return int[]
	 */
	public static function changed_posts( $slug, $after, $limit ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- indexed meta_key lookup.
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT %d", self::ORIGINAL_META . '_' . $slug, (int) $after, (int) $limit ) ) );
	}

	/**
	 * POST /import/{source}/posts — swap cards in the next batch of posts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_posts( $request ) {
		$imp = self::importer_for( $request );
		if ( is_wp_error( $imp ) ) {
			return $imp;
		}
		$map     = self::map( $imp->slug() );
		$ids     = self::candidate_posts( $imp, (int) $request['cursor'], self::BATCH + 1 );
		$batch   = array_slice( $ids, 0, self::BATCH );
		$results = array();
		$cursor  = (int) $request['cursor'];
		foreach ( $batch as $post_id ) {
			$results[] = self::update_post( $imp, $post_id, $map );
			$cursor    = $post_id;
		}
		return rest_ensure_response(
			array(
				'results' => $results,
				'cursor'  => $cursor,
				'done'    => count( $ids ) <= self::BATCH,
			)
		);
	}

	/**
	 * Swap one post's cards (keeps the original content for undo).
	 *
	 * @param Importer       $imp     Importer.
	 * @param int            $post_id Post ID.
	 * @param array<int,int> $map     Source => Scrumptious IDs.
	 * @return array{id:int,title:string,status:string}
	 */
	public static function update_post( Importer $imp, $post_id, array $map ) {
		$post   = get_post( $post_id );
		$result = array(
			'id'     => (int) $post_id,
			'title'  => $post ? get_the_title( $post ) : '',
			'status' => 'unchanged',
		);
		if ( ! $post ) {
			return $result;
		}
		$new = $imp->replace( $post->post_content, $map );
		if ( $new === $post->post_content ) {
			return $result;
		}
		if ( '' === (string) get_post_meta( $post_id, self::ORIGINAL_META . '_' . $imp->slug(), true ) ) {
			update_post_meta( $post_id, self::ORIGINAL_META . '_' . $imp->slug(), wp_slash( $post->post_content ) );
		}
		self::write_content( $post_id, $new );
		$result['status'] = 'updated';
		return $result;
	}

	/**
	 * POST /import/{source}/undo — put the original post content back.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_undo( $request ) {
		$imp = self::importer_for( $request );
		if ( is_wp_error( $imp ) ) {
			return $imp;
		}
		$key     = self::ORIGINAL_META . '_' . $imp->slug();
		$ids     = self::changed_posts( $imp->slug(), (int) $request['cursor'], self::BATCH + 1 );
		$results = array();
		$cursor  = (int) $request['cursor'];
		foreach ( array_slice( $ids, 0, self::BATCH ) as $post_id ) {
			$original = (string) get_post_meta( $post_id, $key, true );
			if ( '' !== $original ) {
				self::write_content( $post_id, $original );
			}
			delete_post_meta( $post_id, $key );
			$results[] = array(
				'id'     => $post_id,
				'title'  => get_the_title( $post_id ),
				'status' => 'restored',
			);
			$cursor    = $post_id;
		}
		return rest_ensure_response(
			array(
				'results' => $results,
				'cursor'  => $cursor,
				'done'    => count( $ids ) <= self::BATCH,
			)
		);
	}

	/**
	 * Save content without kses re-filtering (only block/shortcode markup changed)
	 * and without touching the post's modified date semantics beyond a normal update.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Content.
	 */
	private static function write_content( $post_id, $content ) {
		$kses = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		wp_update_post(
			array(
				'ID'           => (int) $post_id,
				'post_content' => wp_slash( $content ),
			)
		);
		if ( $kses ) {
			kses_init_filters();
		}
	}
}
