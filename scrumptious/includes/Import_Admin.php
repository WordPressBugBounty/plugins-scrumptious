<?php
/**
 * Recipes → Import screen, plus a one-time hint when another recipe plugin's data is found.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Import admin UI.
 */
final class Import_Admin {

	const PAGE = 'scrumptious-import';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'hint' ) );
	}

	/**
	 * Screen URL.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'edit.php?post_type=' . Post_Type::SLUG . '&page=' . self::PAGE );
	}

	/**
	 * Submenu.
	 */
	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Post_Type::SLUG,
			__( 'Import Recipes', 'scrumptious' ),
			__( 'Import', 'scrumptious' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Script for the import screen.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_script( 'scrumptious-import', SCRUMPTIOUS_URL . 'assets/js/import.js', array( 'wp-api-fetch', 'wp-i18n' ), SCRUMPTIOUS_VERSION, true );
		wp_set_script_translations( 'scrumptious-import', 'scrumptious' );
		wp_add_inline_script( 'scrumptious-import', 'window.scrumptiousImportReview = ' . wp_json_encode( Upsell::import_review() ) . ';', 'before' );
	}

	/**
	 * "Moving from WP Recipe Maker?" hint on the Recipes screens until something is imported.
	 */
	public static function hint() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Post_Type::SLUG !== $screen->post_type || 'edit' !== $screen->base || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		foreach ( Import_Manager::importers() as $slug => $imp ) {
			if ( ! Import_Manager::map( $slug ) && $imp->available() ) {
				printf(
					'<div class="notice notice-info"><p>%s <a class="button button-primary" style="margin-left:8px" href="%s">%s</a></p></div>',
					/* translators: %s: plugin name */
					esc_html( sprintf( __( 'Moving from %s? Bring your recipes over in a couple of clicks — the originals stay untouched.', 'scrumptious' ), $imp->label() ) ),
					esc_url( self::url() ),
					esc_html__( 'Import recipes', 'scrumptious' )
				);
				return;
			}
		}
	}

	/**
	 * Render the screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$sources = Import_Manager::status();
		?>
		<div class="wrap scrumptious-import">
			<h1><?php esc_html_e( 'Import Recipes', 'scrumptious' ); ?><?php echo Help::icon( 'importing' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Help::icon(). ?></h1>
			<p style="max-width:760px"><?php esc_html_e( 'Bring your recipes over from another recipe plugin. Ingredients, steps, times, servings, nutrition, images, ratings, courses, cuisines and diets come across. Your posts are then updated to show the Scrumptious card in the same spot.', 'scrumptious' ); ?></p>
			<p style="max-width:760px"><?php esc_html_e( 'Nothing is deleted: the original recipes stay where they are, and post changes can be undone from this screen. It works even if the other plugin is already deactivated.', 'scrumptious' ); ?></p>

			<?php if ( ! $sources ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'No recipes from WP Recipe Maker, Tasty Recipes or Create by Mediavine were found on this site.', 'scrumptious' ); ?></p></div>
			<?php endif; ?>

			<?php foreach ( $sources as $s ) : ?>
				<div class="card swp-import" data-source="<?php echo esc_attr( $s['source'] ); ?>" data-total="<?php echo (int) $s['total']; ?>" style="max-width:760px;padding:16px 20px">
					<h2 style="margin-top:0"><?php echo esc_html( $s['label'] ); ?></h2>
					<p>
						<?php
						echo esc_html(
							implode(
								' · ',
								array(
									/* translators: %d: number of recipes */
									sprintf( _n( '%d recipe found', '%d recipes found', (int) $s['total'], 'scrumptious' ), (int) $s['total'] ),
									/* translators: %d: number of recipes */
									sprintf( _n( '%d already imported', '%d already imported', (int) $s['imported'], 'scrumptious' ), (int) $s['imported'] ),
									/* translators: %d: number of posts */
									sprintf( _n( '%d post shows them', '%d posts show them', (int) $s['posts'], 'scrumptious' ), (int) $s['posts'] ),
								)
							)
						);
						?>
					</p>
					<p>
						<label>
							<input type="checkbox" class="swp-import__swap" checked />
							<?php
							/* translators: %s: plugin name */
							printf( esc_html__( 'Replace %s cards in my posts with Scrumptious cards', 'scrumptious' ), esc_html( $s['label'] ) );
							?>
						</label>
					</p>
					<p>
						<button type="button" class="button button-primary swp-import__run">
							<?php
							if ( ! $s['imported'] ) {
								esc_html_e( 'Import recipes', 'scrumptious' );
							} elseif ( $s['imported'] < $s['total'] ) {
								esc_html_e( 'Import remaining recipes', 'scrumptious' );
							} else {
								esc_html_e( 'Check again (skips recipes already imported)', 'scrumptious' );
							}
							?>
						</button>
						<?php if ( $s['changed'] ) : ?>
							<button type="button" class="button swp-import__undo" style="margin-left:6px">
								<?php
								/* translators: %d: number of posts */
								printf( esc_html__( 'Undo post changes (%d)', 'scrumptious' ), (int) $s['changed'] );
								?>
							</button>
						<?php endif; ?>
					</p>
					<progress class="swp-import__progress" value="0" max="100" style="display:none;width:100%;height:14px"></progress>
					<p class="swp-import__status" aria-live="polite"></p>
					<ul class="swp-import__errors" style="color:#b32d2e"></ul>
				</div>
			<?php endforeach; ?>

			<?php
			if ( array_filter( wp_list_pluck( $sources, 'imported' ) ) ) {
				echo Upsell::hint_html( __( 'Imported. With Pro you can also import single recipes from a link.', 'scrumptious' ), __( 'How it works', 'scrumptious' ), Upsell::url( 'importer', Upsell::HELP . 'import-from-url/' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in hint_html().
			}
			?>
			<h2 style="margin-top:32px"><?php esc_html_e( 'After importing', 'scrumptious' ); ?></h2>
			<ol style="max-width:760px">
				<li><?php esc_html_e( 'Open a few posts and check the recipe cards look right.', 'scrumptious' ); ?></li>
				<li><?php esc_html_e( 'Deactivate the old recipe plugin. Keep it installed for a little while in case you want to undo.', 'scrumptious' ); ?></li>
				<li><?php esc_html_e( 'Check Google Search Console over the next few days: your recipe rich results should carry on as before.', 'scrumptious' ); ?></li>
			</ol>
			<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Post_Type::SLUG ) ); ?>"><?php esc_html_e( 'View your recipes →', 'scrumptious' ); ?></a></p>
		</div>
		<?php
	}
}
