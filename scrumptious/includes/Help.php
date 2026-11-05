<?php
/**
 * Links from the plugin to the help desk (https://scrumptiouswp.com/help/<slug>/).
 *
 * Slugs match the `slug` front matter in docs/help/*.md. Don't rename a slug once shipped.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Help desk URLs and the "?" icon link.
 */
final class Help {

	const BASE = 'https://scrumptiouswp.com/help/';

	/**
	 * Help article URL ('' slug = help home).
	 *
	 * @param string $slug Article slug.
	 * @return string
	 */
	public static function url( $slug = '' ) {
		$url = self::BASE . ( '' !== $slug ? sanitize_title( $slug ) . '/' : '' );
		/**
		 * Filter help links (e.g. to point at your own documentation).
		 *
		 * @param string $url  URL.
		 * @param string $slug Article slug.
		 */
		return (string) apply_filters( 'scrumptious_help_url', $url, $slug );
	}

	/**
	 * A small "?" icon linking to a help article (opens in a new tab).
	 *
	 * @param string $slug  Article slug.
	 * @param string $label Accessible label.
	 * @return string HTML.
	 */
	public static function icon( $slug, $label = '' ) {
		$label = '' !== $label ? $label : __( 'Help', 'scrumptious' );
		return sprintf(
			'<a class="swp-help-link" href="%1$s" target="_blank" rel="noopener" title="%2$s" style="text-decoration:none;vertical-align:middle;margin-left:4px"><span class="dashicons dashicons-editor-help" aria-hidden="true"></span><span class="screen-reader-text">%2$s</span></a>',
			esc_url( self::url( $slug ) ),
			esc_attr( $label . ' ' . __( '(opens in a new tab)', 'scrumptious' ) )
		);
	}
}
