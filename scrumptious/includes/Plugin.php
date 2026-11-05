<?php
/**
 * Main plugin bootstrap.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin and wires WordPress hooks.
 */
final class Plugin {

	const DB_VERSION_OPTION = 'scrumptious_db_version';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get / boot the plugin.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function hooks() {
		add_action( 'init', array( $this, 'init' ) );
		Post_Type::init();
		Renderer::init();
		Usage::init();
		Recipe_Pages::init();
		Print_View::init();
		Ratings::init();
		Parse_Api::init();
		Tagger::init();
		Import_Manager::init();
		Upsell::init();
		add_action( 'rest_api_init', array( Admin::class, 'rest_fields' ) );
		add_filter( 'rest_prepare_scrumptious_recipe', array( Recipe::class, 'rest_protect' ), 10, 2 );
		if ( is_admin() ) {
			Settings::init();
			Admin::init();
			Ai_Admin::init();
			Import_Admin::init();
			Setup::init();
			Newsletter::init();
		}
	}

	/**
	 * Init: register types, meta and blocks. Translations load automatically (WP 4.6+).
	 */
	public function init() {
		Post_Type::register();
		Recipe::register_meta();
		Block::register();
		self::maybe_upgrade();
	}

	/**
	 * Run one-time upgrade routines when the stored version is older than the code.
	 */
	private static function maybe_upgrade() {
		$stored = (string) get_option( self::DB_VERSION_OPTION, '0' );
		if ( version_compare( $stored, SCRUMPTIOUS_VERSION, '>=' ) ) {
			return;
		}
		if ( '0' !== $stored && version_compare( $stored, '0.11.0', '<' ) ) {
			self::migrate_prefix();
		}
		if ( '0' !== $stored && version_compare( $stored, '1.0.0-dev', '<' ) ) {
			// Existing sites keep their theme's recipe index; new sites get the Scrumptious grid.
			$saved = get_option( Settings::OPTION, array() );
			if ( is_array( $saved ) && ! isset( $saved['archive_layout'] ) ) {
				$saved['archive_layout'] = 'theme';
				update_option( Settings::OPTION, $saved );
			}
		}
		if ( version_compare( $stored, '1.1.0-dev', '<' ) ) {
			// Start the clock for the review request; sites that already had Scrumptious count as 3 days in.
			Upsell::mark_installed( '0' !== $stored );
		}
		if ( version_compare( $stored, '0.4.0', '<' ) ) {
			Ratings::install();
		}
		if ( version_compare( $stored, '0.2.0', '<' ) ) {
			Usage::backfill();
			self::bind_existing_recipes();
			update_option( 'scrumptious_flush_rewrite', 1, false );
		}
		update_option( self::DB_VERSION_OPTION, SCRUMPTIOUS_VERSION, false );
	}

	/**
	 * 0.11.0 renamed everything stored from the short "swp_" prefix to "scrumptious_"
	 * (WordPress.org asks for prefixes of 4+ characters). Move existing data across.
	 */
	private static function migrate_prefix() {
		global $wpdb;
		$old = 'swp_'; // The pre-0.11.0 prefix.
		$new = 'scrumptious_';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery -- one-time upgrade; table names come from $wpdb.
		$wpdb->update( $wpdb->posts, array( 'post_type' => $new . 'recipe' ), array( 'post_type' => $old . 'recipe' ) );
		foreach ( array( 'course', 'cuisine', 'diet' ) as $tax ) {
			$wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => $new . $tax ), array( 'taxonomy' => $old . $tax ) );
		}
		$keys = array( 'recipe', 'recipe_ids', 'used_in', 'rating_avg', 'rating_count', 'rating_base', 'import_source', 'import_original_content', 'submission', 'submitter_email' );
		foreach ( $keys as $key ) {
			$wpdb->update( $wpdb->postmeta, array( 'meta_key' => '_' . $new . $key ), array( 'meta_key' => '_' . $old . $key ) );
		}
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_key = CONCAT( %s, SUBSTRING( meta_key, %d ) ) WHERE meta_key LIKE %s",
				'_' . $new . 'import_original_content_',
				strlen( '_' . $old . 'import_original_content_' ) + 1,
				$wpdb->esc_like( '_' . $old . 'import_original_content_' ) . '%'
			)
		);
		$wpdb->update( $wpdb->usermeta, array( 'meta_key' => '_' . $new . 'meal_plan' ), array( 'meta_key' => '_' . $old . 'meal_plan' ) );
		// Menu items pointing at recipes, courses, cuisines or diets.
		foreach ( array( 'recipe', 'course', 'cuisine', 'diet' ) as $name ) {
			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => $new . $name ),
				array(
					'meta_key'   => '_menu_item_object',
					'meta_value' => $old . $name,
				)
			);
		}
		// Ratings table.
		$from = $wpdb->prefix . $old . 'ratings';
		$to   = $wpdb->prefix . $new . 'ratings';
		$has  = static function ( $table ) use ( $wpdb ) {
			$suppress = $wpdb->suppress_errors( true );
			$found    = null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $table ) ) || '' === $wpdb->last_error;
			$wpdb->suppress_errors( $suppress );
			return $found;
		};
		Ratings::install(); // Creates the new table if it isn't there yet.
		if ( $from !== $to && $has( $from ) && '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $to ) ) ) {
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (recipe_id, rating, user_id, voter, created) SELECT recipe_id, rating, user_id, voter, created FROM %i', $to, $from ) );
			if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $to ) ) === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $from ) ) ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $from ) );
			}
		}
		// phpcs:enable
		wp_cache_flush();
		update_option( 'scrumptious_flush_rewrite', 1, false );
	}

	/**
	 * Give recipes created before 0.2.0 their Recipe Card block content.
	 */
	private static function bind_existing_recipes() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time upgrade scan.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_content NOT LIKE %s",
				Post_Type::SLUG,
				'%' . $wpdb->esc_like( 'wp:scrumptious/recipe' ) . '%'
			)
		);
		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bypasses save hooks on purpose (no revisions, no modified date change).
			$wpdb->update( $wpdb->posts, array( 'post_content' => Post_Type::CONTENT ), array( 'ID' => (int) $id ) );
			clean_post_cache( (int) $id );
		}
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		Post_Type::register();
		Ratings::install();
		update_option( 'scrumptious_flush_rewrite', 1, false );
		Setup::on_activate();
		// The version is recorded by maybe_upgrade() on the next load, so upgrade routines run after re-activation too.
	}

	/**
	 * Deactivation. Data is kept; see uninstall.php.
	 */
	public static function deactivate() {
		// Nothing to clean up yet (no cron, no rewrite rules).
	}
}
