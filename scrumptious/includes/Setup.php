<?php
/**
 * First-run setup wizard: Recipes → Setup.
 *
 * Opens once after the first activation (not on bulk activation or network activation),
 * and can be run again from Recipes → Settings → Run setup again.
 *
 * Steps: welcome (with import hint) → look → reader features → AI → done.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * The setup wizard.
 */
final class Setup {

	const PAGE     = 'scrumptious-setup';
	const REDIRECT = 'scrumptious_setup_redirect';
	const STEPS    = array( 'welcome', 'look', 'features', 'ai', 'done' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
		add_action( 'admin_post_scrumptious_setup', array( __CLASS__, 'save' ) );
	}

	/**
	 * Called on activation: open the wizard next time an admin screen loads,
	 * but only on a fresh install (no saved settings yet).
	 */
	public static function on_activate() {
		if ( false === get_option( Settings::OPTION, false ) ) {
			update_option( self::REDIRECT, 1, false );
		}
	}

	/**
	 * Wizard URL.
	 *
	 * @param string $step Step.
	 * @return string
	 */
	public static function url( $step = 'welcome' ) {
		return admin_url( 'edit.php?post_type=' . Post_Type::SLUG . '&page=' . self::PAGE . '&step=' . rawurlencode( $step ) );
	}

	/**
	 * Submenu (last item under Recipes).
	 */
	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Post_Type::SLUG,
			__( 'Scrumptious Setup', 'scrumptious' ),
			__( 'Setup', 'scrumptious' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * One-time redirect after activation.
	 */
	public static function maybe_redirect() {
		if ( ! get_option( self::REDIRECT ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of WordPress's own bulk-activation flag.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		delete_option( self::REDIRECT );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Current step from the URL.
	 *
	 * @return string
	 */
	private static function step() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'welcome';
		return in_array( $step, self::STEPS, true ) ? $step : 'welcome';
	}

	/**
	 * Save a step and go to the next one.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'scrumptious' ), 403 );
		}
		check_admin_referer( 'scrumptious_setup' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		$s    = Settings::all();

		if ( 'look' === $step ) {
			$color = isset( $_POST['accent_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['accent_color'] ) ) : '';
			if ( $color ) {
				$s['accent_color'] = $color;
			}
			$style = isset( $_POST['card_style'] ) ? sanitize_key( wp_unslash( $_POST['card_style'] ) ) : '';
			if ( in_array( $style, Settings::STYLES, true ) ) {
				$s['card_style'] = $style;
			}
		} elseif ( 'features' === $step ) {
			foreach ( array( 'ratings', 'scaling', 'units', 'cook_mode', 'show_print', 'auto_jump', 'public_pages' ) as $key ) {
				$s[ $key ] = ! empty( $_POST[ $key ] );
			}
			$s['sections']['share'] = ! empty( $_POST['share'] );
		}
		// phpcs:enable

		update_option( Settings::OPTION, Settings::sanitize( $s ) );

		$next = array_search( $step, self::STEPS, true );
		$next = false === $next ? 'welcome' : self::STEPS[ min( $next + 1, count( self::STEPS ) - 1 ) ];
		wp_safe_redirect( self::url( $next ) );
		exit;
	}

	/**
	 * Step form wrapper.
	 *
	 * @param string   $step   Step.
	 * @param callable $fields Prints the fields.
	 */
	private static function form( $step, $fields ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="scrumptious_setup" />
			<input type="hidden" name="step" value="<?php echo esc_attr( $step ); ?>" />
			<?php wp_nonce_field( 'scrumptious_setup' ); ?>
			<?php $fields(); ?>
			<p class="swp-setup__nav">
				<?php submit_button( __( 'Save and continue', 'scrumptious' ), 'primary', 'submit', false ); ?>
				<a class="button-link" style="margin-left:12px" href="<?php echo esc_url( self::url( self::STEPS[ array_search( $step, self::STEPS, true ) + 1 ] ) ); ?>"><?php esc_html_e( 'Skip this step', 'scrumptious' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * Render the wizard.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$step   = self::step();
		$s      = Settings::all();
		$labels = array(
			'welcome'  => __( 'Welcome', 'scrumptious' ),
			'look'     => __( 'Look', 'scrumptious' ),
			'features' => __( 'Features', 'scrumptious' ),
			'ai'       => __( 'AI (optional)', 'scrumptious' ),
			'done'     => __( 'Done', 'scrumptious' ),
		);
		$index  = array_search( $step, self::STEPS, true );
		?>
		<div class="wrap swp-setup" style="max-width:760px">
			<h1><?php esc_html_e( 'Set up Scrumptious', 'scrumptious' ); ?></h1>
			<ol class="swp-setup__steps">
				<?php foreach ( self::STEPS as $i => $key ) : ?>
					<li class="<?php echo esc_attr( $i < $index ? 'is-done' : ( $i === $index ? 'is-current' : '' ) ); ?>"><?php echo esc_html( $labels[ $key ] ); ?></li>
				<?php endforeach; ?>
			</ol>
			<div class="swp-setup__card">
				<?php
				switch ( $step ) {
					case 'look':
						self::step_look( $s );
						break;
					case 'features':
						self::step_features( $s );
						break;
					case 'ai':
						self::step_ai();
						break;
					case 'done':
						self::step_done();
						break;
					default:
						self::step_welcome();
				}
				?>
			</div>
			<p style="margin-top:16px"><a href="<?php echo esc_url( Help::url( 'first-recipe' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Read the getting-started guide', 'scrumptious' ); ?></a> · <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Post_Type::SLUG ) ); ?>"><?php esc_html_e( 'Exit setup', 'scrumptious' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Step 1.
	 */
	private static function step_welcome() {
		?>
		<h2><?php esc_html_e( 'Welcome! Let’s get your recipes looking great.', 'scrumptious' ); ?></h2>
		<p><?php esc_html_e( 'This takes about a minute. Every choice can be changed later under Recipes → Settings, and each recipe card can override them.', 'scrumptious' ); ?></p>
		<?php
		foreach ( Import_Manager::importers() as $imp ) {
			if ( $imp->available() ) {
				$count = count( $imp->ids() );
				?>
				<div class="notice notice-info inline" style="margin:16px 0">
					<p>
						<?php
						printf(
							/* translators: 1: number of recipes, 2: plugin name */
							esc_html( _n( 'We found %1$d recipe from %2$s on this site.', 'We found %1$d recipes from %2$s on this site.', $count, 'scrumptious' ) ),
							(int) $count,
							esc_html( $imp->label() )
						);
						?>
						<a href="<?php echo esc_url( Import_Admin::url() ); ?>"><?php esc_html_e( 'You can import them after setup', 'scrumptious' ); ?></a>.
					</p>
				</div>
				<?php
				break;
			}
		}
		?>
		<p class="swp-setup__nav"><a class="button button-primary button-hero" href="<?php echo esc_url( self::url( 'look' ) ); ?>"><?php esc_html_e( 'Let’s go', 'scrumptious' ); ?></a></p>
		<?php
	}

	/**
	 * Step 2.
	 *
	 * @param array<string,mixed> $s Settings.
	 */
	private static function step_look( array $s ) {
		self::form(
			'look',
			static function () use ( $s ) {
				?>
				<h2><?php esc_html_e( 'How should recipe cards look?', 'scrumptious' ); ?></h2>
				<p><?php esc_html_e( 'Cards use your theme’s fonts automatically. Pick an accent colour for headings, buttons, step numbers and stars.', 'scrumptious' ); ?></p>
				<label><?php esc_html_e( 'Accent colour', 'scrumptious' ); ?> <input type="color" name="accent_color" value="<?php echo esc_attr( $s['accent_color'] ); ?>" style="vertical-align:middle;margin-left:8px" /></label>
				<p><strong><?php esc_html_e( 'Card style', 'scrumptious' ); ?></strong></p>
				<label><input type="radio" name="card_style" value="full" <?php checked( $s['card_style'], 'full' ); ?> /> <?php esc_html_e( 'Full — a boxed card with the photo beside the title (most popular)', 'scrumptious' ); ?></label>
				<label><input type="radio" name="card_style" value="compact" <?php checked( $s['card_style'], 'compact' ); ?> /> <?php esc_html_e( 'Compact — ingredients beside the steps on wide screens', 'scrumptious' ); ?></label>
				<label><input type="radio" name="card_style" value="minimal" <?php checked( $s['card_style'], 'minimal' ); ?> /> <?php esc_html_e( 'Minimal — no box or photo; blends into your theme', 'scrumptious' ); ?></label>
				<?php
			}
		);
	}

	/**
	 * Step 3.
	 *
	 * @param array<string,mixed> $s Settings.
	 */
	private static function step_features( array $s ) {
		self::form(
			'features',
			static function () use ( $s ) {
				$rows = array(
					'ratings'      => array( __( 'Star ratings', 'scrumptious' ), __( 'Readers rate recipes; stars can show in Google results.', 'scrumptious' ), ! empty( $s['ratings'] ) ),
					'scaling'      => array( __( 'Servings scaling', 'scrumptious' ), __( 'Readers can double or halve a recipe.', 'scrumptious' ), ! empty( $s['scaling'] ) ),
					'units'        => array( __( 'US / Metric switch', 'scrumptious' ), __( 'Converts cups and ounces to millilitres and grams, and back.', 'scrumptious' ), ! empty( $s['units'] ) ),
					'cook_mode'    => array( __( 'Cook mode', 'scrumptious' ), __( 'Keeps the reader’s screen awake while cooking.', 'scrumptious' ), ! empty( $s['cook_mode'] ) ),
					'show_print'   => array( __( 'Print button', 'scrumptious' ), __( 'A clean, ad-free print page.', 'scrumptious' ), ! empty( $s['show_print'] ) ),
					'share'        => array( __( 'Share buttons', 'scrumptious' ), __( 'Pinterest, Facebook, WhatsApp, email and copy link. No tracking scripts.', 'scrumptious' ), ! empty( $s['sections']['share'] ) ),
					'auto_jump'    => array( __( 'Jump to Recipe at the top of posts', 'scrumptious' ), __( 'Added automatically to every post with a recipe.', 'scrumptious' ), ! empty( $s['auto_jump'] ) ),
					'public_pages' => array( __( 'Give each recipe its own page', 'scrumptious' ), __( 'Adds /recipe/… pages and a /recipes/ index. Leave off if recipes only live in your blog posts.', 'scrumptious' ), ! empty( $s['public_pages'] ) ),
				);
				?>
				<h2><?php esc_html_e( 'What should readers be able to do?', 'scrumptious' ); ?></h2>
				<p><?php esc_html_e( 'The recommended options are already ticked.', 'scrumptious' ); ?></p>
				<?php foreach ( $rows as $key => $row ) : ?>
					<label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $row[2] ); ?> /> <strong><?php echo esc_html( $row[0] ); ?></strong></label>
					<p class="description"><?php echo esc_html( $row[1] ); ?></p>
				<?php endforeach; ?>
				<?php
			}
		);
	}

	/**
	 * Step 4.
	 */
	private static function step_ai() {
		$connect = wp_nonce_url( add_query_arg( 'action', 'scrumptious_openrouter_connect', admin_url( 'admin-post.php' ) ), 'scrumptious_openrouter_connect' );
		?>
		<h2><?php esc_html_e( 'Want AI to read messy recipes for you? (optional)', 'scrumptious' ); ?></h2>
		<p><?php esc_html_e( 'Paste & Parse already works without AI: paste a recipe or a link and the card fills itself in. Connecting AI makes it much better with long blog stories and unusual layouts.', 'scrumptious' ); ?></p>
		<p><?php esc_html_e( 'You use your own AI account and pay the provider directly — usually well under a cent per recipe. Nothing goes through ScrumptiousWP.', 'scrumptious' ); ?></p>
		<?php if ( Ai::configured() ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'AI is already set up.', 'scrumptious' ); ?></p></div>
		<?php endif; ?>
		<div class="swp-setup__big">
			<a class="button button-primary button-hero" href="<?php echo esc_url( $connect ); ?>"><?php esc_html_e( 'Connect OpenRouter (one click)', 'scrumptious' ); ?></a>
			<a class="button button-hero" href="<?php echo esc_url( Settings::url() . '#swp-ai' ); ?>"><?php esc_html_e( 'Use a Google, OpenAI or Anthropic key', 'scrumptious' ); ?></a>
		</div>
		<p><?php esc_html_e( 'Connecting takes you to OpenRouter and back to Settings. Come back to this wizard any time from Recipes → Setup.', 'scrumptious' ); ?> <a href="<?php echo esc_url( Help::url( 'ai-setup' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Learn more', 'scrumptious' ); ?></a></p>
		<p class="swp-setup__nav"><a class="button" href="<?php echo esc_url( self::url( 'done' ) ); ?>"><?php esc_html_e( 'Skip for now', 'scrumptious' ); ?></a></p>
		<?php
	}

	/**
	 * Step 5.
	 */
	private static function step_done() {
		?>
		<h2><?php esc_html_e( 'You’re all set!', 'scrumptious' ); ?></h2>
		<p><?php esc_html_e( 'Add a Recipe Card block to any post, click Create new recipe, and type — or paste a whole recipe (or a link to one) with Paste & Parse.', 'scrumptious' ); ?></p>
		<div class="swp-setup__big">
			<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'post-new.php' ) ); ?>"><?php esc_html_e( 'Write a post with a recipe', 'scrumptious' ); ?></a>
			<a class="button button-hero" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Post_Type::SLUG ) ); ?>"><?php esc_html_e( 'Add a recipe on its own', 'scrumptious' ); ?></a>
			<a class="button button-hero" href="<?php echo esc_url( Import_Admin::url() ); ?>"><?php esc_html_e( 'Import recipes from another plugin', 'scrumptious' ); ?></a>
		</div>
		<p>
			<a href="<?php echo esc_url( Help::url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Browse the help docs', 'scrumptious' ); ?></a> ·
			<a href="<?php echo esc_url( Settings::url() ); ?>"><?php esc_html_e( 'All settings', 'scrumptious' ); ?></a>
		</p>
		<?php
		echo Newsletter::result_message(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		if ( Newsletter::show() ) {
			echo '<div class="swp-setup__newsletter">';
			Newsletter::form( 'plugin-setup' );
			echo '</div>';
		}
		?>
		<?php
	}
}
