<?php
/**
 * Plugin settings (one option array) and the Recipes → Settings screen.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Stores, sanitizes and renders plugin settings.
 */
final class Settings {

	const OPTION = 'scrumptious_settings';
	const PAGE   = 'scrumptious-settings';

	const SECTIONS = array( 'image', 'summary', 'times', 'share', 'gallery', 'notes', 'nutrition' );
	const SHARE    = array( 'pinterest', 'facebook', 'x', 'whatsapp', 'email', 'copy' );
	const STYLES   = array( 'full', 'compact', 'minimal' );

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'public_pages'   => false,
			'recipe_base'    => 'recipe',
			'archive_base'   => 'recipes',
			'archive_layout' => 'grid',
			'allow_comments' => true,
			'accent_color'   => '#e11d74',
			'card_style'     => 'full',
			'show_print'     => true,
			'auto_jump'      => false,
			'ratings'        => true,
			'scaling'        => true,
			'units'          => true,
			'cook_mode'      => true,
			'sections'       => array_fill_keys( self::SECTIONS, true ),
			'output_schema'  => true,
			'delete_data'    => false,
			'share_networks' => array( 'pinterest', 'facebook', 'whatsapp', 'email', 'copy' ),
			'share_style'    => 'icons',
			'share_position' => 'bottom',
		);
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return self::sanitize( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Whether a card section is enabled.
	 *
	 * @param string $section Section key.
	 * @return bool
	 */
	public static function show( $section ) {
		$sections = self::get( 'sections' );
		return ! isset( $sections[ $section ] ) || (bool) $sections[ $section ];
	}

	/**
	 * Sanitize a settings array (also used as the register_setting callback).
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$d     = self::defaults();
		$out   = $d;

		foreach ( array( 'public_pages', 'allow_comments', 'output_schema', 'delete_data', 'show_print', 'auto_jump', 'ratings', 'scaling', 'units', 'cook_mode' ) as $bool ) {
			$out[ $bool ] = isset( $input[ $bool ] ) ? (bool) $input[ $bool ] : ( isset( $input['_submitted'] ) ? false : $d[ $bool ] );
		}

		foreach ( array( 'recipe_base', 'archive_base' ) as $base ) {
			$v            = isset( $input[ $base ] ) ? sanitize_title( (string) $input[ $base ] ) : '';
			$out[ $base ] = '' !== $v ? $v : $d[ $base ];
		}
		if ( $out['recipe_base'] === $out['archive_base'] ) {
			$out['archive_base'] = $out['recipe_base'] . 's';
		}

		$out['archive_layout'] = isset( $input['archive_layout'] ) && 'theme' === $input['archive_layout'] ? 'theme' : 'grid';

		$style             = isset( $input['card_style'] ) ? (string) $input['card_style'] : '';
		$out['card_style'] = in_array( $style, self::STYLES, true ) ? $style : $d['card_style'];

		if ( isset( $input['share_networks'] ) && is_array( $input['share_networks'] ) ) {
			$out['share_networks'] = array_values( array_intersect( self::SHARE, array_map( 'strval', $input['share_networks'] ) ) );
		} elseif ( isset( $input['_submitted'] ) ) {
			$out['share_networks'] = array();
		}

		$out['share_style']    = isset( $input['share_style'] ) && 'labels' === $input['share_style'] ? 'labels' : 'icons';
		$out['share_position'] = isset( $input['share_position'] ) && 'top' === $input['share_position'] ? 'top' : 'bottom';

		$color               = isset( $input['accent_color'] ) ? sanitize_hex_color( (string) $input['accent_color'] ) : '';
		$out['accent_color'] = $color ? $color : $d['accent_color'];

		$sections = isset( $input['sections'] ) && is_array( $input['sections'] ) ? $input['sections'] : null;
		foreach ( self::SECTIONS as $s ) {
			if ( null === $sections ) {
				$out['sections'][ $s ] = isset( $input['_submitted'] ) ? false : true;
			} else {
				// A section added in a later version stays on until the settings are saved without it.
				$out['sections'][ $s ] = isset( $sections[ $s ] ) ? ! empty( $sections[ $s ] ) : ! isset( $input['_submitted'] );
			}
		}

		return $out;
	}

	/**
	 * Admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'maybe_flush' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( SCRUMPTIOUS_FILE ), array( __CLASS__, 'action_link' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCRUMPTIOUS_FILE ), array( Upsell::class, 'action_link' ), 11 );
	}

	/**
	 * Schedule a rewrite flush when URL-related settings change.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $new_value New value.
	 */
	public static function maybe_flush( $old_value, $new_value ) {
		$before = self::sanitize( is_array( $old_value ) ? $old_value : array() );
		$after  = self::sanitize( is_array( $new_value ) ? $new_value : array() );
		foreach ( array( 'public_pages', 'recipe_base', 'archive_base' ) as $k ) {
			if ( $before[ $k ] !== $after[ $k ] ) {
				update_option( 'scrumptious_flush_rewrite', 1, false );
				return;
			}
		}
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'scrumptious' ) . '</a>' );
		return $links;
	}

	/**
	 * Settings screen URL.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'edit.php?post_type=' . Post_Type::SLUG . '&page=' . self::PAGE );
	}

	/**
	 * Add submenu under Recipes.
	 */
	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Post_Type::SLUG,
			__( 'Scrumptious Settings', 'scrumptious' ),
			__( 'Settings', 'scrumptious' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register the option.
	 */
	public static function register() {
		register_setting(
			'scrumptious',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Render the settings screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = self::all();
		$name   = self::OPTION;
		$labels = array(
			'image'     => __( 'Image', 'scrumptious' ),
			'summary'   => __( 'Summary', 'scrumptious' ),
			'times'     => __( 'Times & servings', 'scrumptious' ),
			'notes'     => __( 'Notes', 'scrumptious' ),
			'share'     => __( 'Share buttons', 'scrumptious' ),
			'gallery'   => __( 'Gallery', 'scrumptious' ),
			'nutrition' => __( 'Nutrition', 'scrumptious' ),
		);
		?>
		<div class="wrap scrumptious-settings">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Scrumptious Settings', 'scrumptious' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( Help::url( 'settings' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Help & docs', 'scrumptious' ); ?></a>
			<a class="page-title-action" href="<?php echo esc_url( Setup::url() ); ?>"><?php esc_html_e( 'Run setup again', 'scrumptious' ); ?></a>
			<hr class="wp-header-end" />
			<div class="swp-settings-layout">
			<div class="swp-settings-main">
			<form method="post" action="options.php">
				<?php settings_fields( 'scrumptious' ); ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[_submitted]" value="1" />

				<h2 class="title"><?php esc_html_e( 'Recipe card', 'scrumptious' ); ?><?php echo Help::icon( 'recipe-card-block' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Help::icon(). ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="swp-accent"><?php esc_html_e( 'Accent color', 'scrumptious' ); ?></label></th>
						<td>
							<input type="color" id="swp-accent" name="<?php echo esc_attr( $name ); ?>[accent_color]" value="<?php echo esc_attr( $s['accent_color'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Used for headings, bullets and step numbers on the card.', 'scrumptious' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="swp-style"><?php esc_html_e( 'Default card style', 'scrumptious' ); ?></label></th>
						<td>
							<select id="swp-style" name="<?php echo esc_attr( $name ); ?>[card_style]">
								<option value="full" <?php selected( $s['card_style'], 'full' ); ?>><?php esc_html_e( 'Full', 'scrumptious' ); ?></option>
								<option value="compact" <?php selected( $s['card_style'], 'compact' ); ?>><?php esc_html_e( 'Compact (ingredients beside steps)', 'scrumptious' ); ?></option>
								<option value="minimal" <?php selected( $s['card_style'], 'minimal' ); ?>><?php esc_html_e( 'Minimal (no box, blends with your theme)', 'scrumptious' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Each Recipe Card block can override this in its sidebar.', 'scrumptious' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Buttons', 'scrumptious' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:6px">
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_print]" value="1" <?php checked( $s['show_print'] ); ?> />
								<?php esc_html_e( 'Show a Print button on recipe cards', 'scrumptious' ); ?>
							</label>
							<label style="display:block">
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[auto_jump]" value="1" <?php checked( $s['auto_jump'] ); ?> />
								<?php esc_html_e( 'Automatically add "Jump to Recipe" at the top of posts with a recipe', 'scrumptious' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Or place the Jump to Recipe block yourself wherever you want it. Posts that already have the block are left alone.', 'scrumptious' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show on the card', 'scrumptious' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $labels as $key => $label ) : ?>
									<label style="display:block;margin-bottom:6px">
										<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[sections][<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $s['sections'][ $key ] ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Ingredients and instructions are always shown. Hidden sections still appear in Google recipe data.', 'scrumptious' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Reader features', 'scrumptious' ); ?><?php echo Help::icon( 'reader-features' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Help::icon(). ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'On every recipe card', 'scrumptious' ); ?></th>
						<td>
							<fieldset>
								<?php
								$scrumptious_features = array(
									'ratings'   => __( 'Star ratings (readers can rate; average shows on the card and in Google results)', 'scrumptious' ),
									'scaling'   => __( 'Servings scaling (− / + and 1×, 2×, 3×)', 'scrumptious' ),
									'units'     => __( 'US / Metric conversion', 'scrumptious' ),
									'cook_mode' => __( 'Cook mode (keeps the screen on while cooking)', 'scrumptious' ),
								);
								foreach ( $scrumptious_features as $key => $label ) :
									?>
									<label style="display:block;margin-bottom:6px">
										<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'These load one small script (about 4 KB) only on pages that show a recipe.', 'scrumptious' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Sharing', 'scrumptious' ); ?><?php echo Help::icon( 'sharing' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Help::icon(). ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Share buttons', 'scrumptious' ); ?></th>
						<td>
							<fieldset>
								<?php
								$scrumptious_networks = array(
									'pinterest' => __( 'Pinterest ("Pin it")', 'scrumptious' ),
									'facebook'  => __( 'Facebook', 'scrumptious' ),
									'x'         => __( 'X (Twitter)', 'scrumptious' ),
									'whatsapp'  => __( 'WhatsApp', 'scrumptious' ),
									'email'     => __( 'Email', 'scrumptious' ),
									'copy'      => __( 'Copy link', 'scrumptious' ),
								);
								foreach ( $scrumptious_networks as $key => $label ) :
									?>
									<label style="display:block;margin-bottom:6px">
										<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[share_networks][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $s['share_networks'], true ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
								<p style="margin-top:12px"><strong><?php esc_html_e( 'Button style', 'scrumptious' ); ?></strong></p>
								<label style="display:block;margin-bottom:6px"><input type="radio" name="<?php echo esc_attr( $name ); ?>[share_style]" value="icons" <?php checked( 'labels' !== $s['share_style'] ); ?> /> <?php esc_html_e( 'Round brand icons', 'scrumptious' ); ?></label>
								<label style="display:block;margin-bottom:6px"><input type="radio" name="<?php echo esc_attr( $name ); ?>[share_style]" value="labels" <?php checked( 'labels', $s['share_style'] ); ?> /> <?php esc_html_e( 'Brand icons with names', 'scrumptious' ); ?></label>
								<p style="margin-top:12px"><strong><?php esc_html_e( 'Position on the card', 'scrumptious' ); ?></strong></p>
								<label style="display:block;margin-bottom:6px"><input type="radio" name="<?php echo esc_attr( $name ); ?>[share_position]" value="bottom" <?php checked( 'top' !== $s['share_position'] ); ?> /> <?php esc_html_e( 'At the bottom, after the recipe', 'scrumptious' ); ?></label>
								<label style="display:block;margin-bottom:6px"><input type="radio" name="<?php echo esc_attr( $name ); ?>[share_position]" value="top" <?php checked( 'top', $s['share_position'] ); ?> /> <?php esc_html_e( 'At the top, small, under the title', 'scrumptious' ); ?></label>
								<p class="description"><?php esc_html_e( 'Plain links, no third-party scripts: nothing loads from the networks until a reader clicks, so there\'s no tracking and no cookie banner needed. On phones a Share button also opens the phone\'s own share menu. Turn the buttons off under Show on the card.', 'scrumptious' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Recipe pages', 'scrumptious' ); ?><?php echo Help::icon( 'recipe-pages' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Help::icon(). ?></h2>
				<p><?php esc_html_e( 'By default, recipes live inside your blog posts (best for SEO and ads). Turn this on if you also want each recipe to have its own page and a recipe index.', 'scrumptious' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Public recipe pages', 'scrumptious' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[public_pages]" value="1" <?php checked( $s['public_pages'] ); ?> />
								<?php esc_html_e( 'Give every recipe its own page', 'scrumptious' ); ?>
							</label>
							<?php if ( $s['public_pages'] && get_post_type_archive_link( Post_Type::SLUG ) ) : ?>
								<p class="description">
									<?php
									/* translators: %s: link to the recipe index */
									printf( esc_html__( 'Recipe index: %s', 'scrumptious' ), '<a href="' . esc_url( (string) get_post_type_archive_link( Post_Type::SLUG ) ) . '" target="_blank">' . esc_html( (string) get_post_type_archive_link( Post_Type::SLUG ) ) . '</a>' );
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="swp-base"><?php esc_html_e( 'Recipe URL base', 'scrumptious' ); ?></label></th>
						<td>
							<code><?php echo esc_html( home_url( '/' ) ); ?></code><input type="text" id="swp-base" class="regular-text" style="width:12em" name="<?php echo esc_attr( $name ); ?>[recipe_base]" value="<?php echo esc_attr( $s['recipe_base'] ); ?>" /><code>/chewy-cookies/</code>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="swp-archive"><?php esc_html_e( 'Recipe index URL', 'scrumptious' ); ?></label></th>
						<td>
							<code><?php echo esc_html( home_url( '/' ) ); ?></code><input type="text" id="swp-archive" class="regular-text" style="width:12em" name="<?php echo esc_attr( $name ); ?>[archive_base]" value="<?php echo esc_attr( $s['archive_base'] ); ?>" /><code>/</code>
						</td>
					</tr>
					<?php if ( ! wp_is_block_theme() ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Recipe index layout', 'scrumptious' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[archive_layout]" value="grid" <?php checked( 'grid', $s['archive_layout'] ); ?> /> <?php esc_html_e( 'Scrumptious grid (photos, ratings and times, with course and cuisine filters)', 'scrumptious' ); ?></label><br />
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[archive_layout]" value="theme" <?php checked( 'theme', $s['archive_layout'] ); ?> /> <?php esc_html_e( "Your theme's archive layout", 'scrumptious' ); ?></label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Choose "Your theme\'s archive layout" if your theme has its own settings for custom post type archives.', 'scrumptious' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Comments', 'scrumptious' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[allow_comments]" value="1" <?php checked( $s['allow_comments'] ); ?> />
								<?php esc_html_e( 'Allow comments on recipe pages', 'scrumptious' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Applies to recipe pages only. Comments on your blog posts follow your normal WordPress discussion settings.', 'scrumptious' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'SEO & data', 'scrumptious' ); ?><?php echo Help::icon( 'google-recipe-data' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Help::icon(). ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Google recipe data', 'scrumptious' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[output_schema]" value="1" <?php checked( $s['output_schema'] ); ?> />
								<?php esc_html_e( 'Output Recipe structured data (JSON-LD)', 'scrumptious' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Leave this on unless another plugin already outputs Recipe schema for these recipes.', 'scrumptious' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'scrumptious' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_data]" value="1" <?php checked( $s['delete_data'] ); ?> />
								<?php esc_html_e( 'Delete all recipes and settings when the plugin is deleted', 'scrumptious' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default, so reinstalling never loses your recipes.', 'scrumptious' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
			<?php Ai_Admin::render(); ?>
			</div>
			<?php
			ob_start();
			Newsletter::settings_card();
			Upsell::settings_card();
			$scrumptious_side = trim( (string) ob_get_clean() );
			if ( '' !== $scrumptious_side ) {
				echo '<div class="swp-settings-side">' . $scrumptious_side . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the cards.
			}
			?>
			</div>
		</div>
		<?php
	}
}
