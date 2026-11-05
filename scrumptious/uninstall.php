<?php
/**
 * Uninstall: removes plugin data only if the user opted in
 * (Recipes → Settings → "Delete all recipes and settings when the plugin is deleted").
 *
 * @package Scrumptious
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// AI keys are always removed when the plugin is deleted: they are credentials, not content.
delete_option( 'scrumptious_ai' );

$scrumptious_settings = get_option( 'scrumptious_settings', array() );
if ( empty( $scrumptious_settings['delete_data'] ) ) {
	return;
}

global $wpdb;

$scrumptious_ids = get_posts(
	array(
		'post_type'      => 'scrumptious_recipe',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);
foreach ( $scrumptious_ids as $scrumptious_id ) {
	wp_delete_post( $scrumptious_id, true );
}

foreach ( array( 'scrumptious_course', 'scrumptious_cuisine', 'scrumptious_diet' ) as $scrumptious_tax ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall cleanup, taxonomy not registered here.
	$scrumptious_terms = $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id, term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $scrumptious_tax ) );
	foreach ( $scrumptious_terms as $scrumptious_tt ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->term_taxonomy, array( 'term_taxonomy_id' => (int) $scrumptious_tt->term_taxonomy_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$scrumptious_in_use = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d", (int) $scrumptious_tt->term_id ) );
		if ( 0 === $scrumptious_in_use ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $wpdb->terms, array( 'term_id' => (int) $scrumptious_tt->term_id ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $wpdb->termmeta, array( 'term_id' => (int) $scrumptious_tt->term_id ) );
		}
	}
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- remove plugin table on opted-in uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scrumptious_ratings" );

// Undo copies kept on posts by the importer.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- uninstall cleanup.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_scrumptious_import_original_content_' ) . '%' ) );

delete_option( 'scrumptious_db_version' );
delete_option( 'scrumptious_settings' );
delete_option( 'scrumptious_flush_rewrite' );
