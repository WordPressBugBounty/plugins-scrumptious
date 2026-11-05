<?php
/**
 * Optional email signup: the setup wizard's last step and a small card on the Settings screen.
 *
 * Nothing is sent unless an admin ticks the box and clicks Sign me up. Then only the email,
 * where it was entered and the plugin version go to scrumptiouswp.com (newsletter sent with
 * Acumbamail, double opt-in). See "External services" in readme.txt.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Newsletter signup.
 */
final class Newsletter {

	const ENDPOINT = 'https://scrumptiouswp.com/wp-json/swp/v1/subscribe';
	const PRIVACY  = 'https://scrumptiouswp.com/privacy-policy/';
	const SOURCES  = array( 'plugin-setup', 'plugin-settings' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_scrumptious_newsletter', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_scrumptious_newsletter_dismiss', array( __CLASS__, 'dismiss' ) );
	}

	/**
	 * '' (not asked), 'done' or 'dismissed'.
	 *
	 * @return string
	 */
	public static function status() {
		$s = Upsell::state();
		return isset( $s['newsletter'] ) ? (string) $s['newsletter'] : '';
	}

	/**
	 * Whether the signup form should show.
	 *
	 * @return bool
	 */
	public static function show() {
		$show = current_user_can( 'manage_options' ) && '' === self::status();
		/**
		 * Filter whether the optional newsletter signup shows (setup wizard and Settings).
		 *
		 * @param bool $show Whether to show it.
		 */
		return (bool) apply_filters( 'scrumptious_show_newsletter', $show );
	}

	/**
	 * Send the signup. Only the email, its source and the plugin version; nothing else.
	 *
	 * @param string $email  Email address.
	 * @param string $source plugin-setup or plugin-settings.
	 * @return true|\WP_Error
	 */
	public static function subscribe( $email, $source ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'scrumptious_email', __( 'That email address doesn’t look right.', 'scrumptious' ) );
		}
		$res  = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 5,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'email'   => $email,
						'source'  => in_array( $source, self::SOURCES, true ) ? $source : 'plugin-settings',
						'version' => SCRUMPTIOUS_VERSION,
					)
				),
			)
		);
		$data = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) || ! is_array( $data ) || empty( $data['ok'] ) ) {
			return new \WP_Error( 'scrumptious_newsletter', __( 'Couldn’t sign you up just now, try again later.', 'scrumptious' ) );
		}
		return true;
	}

	/**
	 * Admin-post: the Sign me up button.
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'scrumptious' ), 403 );
		}
		check_admin_referer( 'scrumptious_newsletter' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$opt_in = ! empty( $_POST['newsletter_opt_in'] );
		$email  = isset( $_POST['newsletter_email'] ) ? sanitize_email( wp_unslash( $_POST['newsletter_email'] ) ) : '';
		$source = isset( $_POST['newsletter_source'] ) ? sanitize_key( wp_unslash( $_POST['newsletter_source'] ) ) : '';
		// phpcs:enable
		$back = wp_get_referer() ? wp_get_referer() : Settings::url();
		$back = remove_query_arg( array( 'swp_newsletter', 'swp_newsletter_msg' ), $back );

		if ( ! $opt_in ) {
			wp_safe_redirect( $back ); // Box not ticked: send nothing.
			exit;
		}
		$result = self::subscribe( $email, str_replace( '_', '-', $source ) );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'swp_newsletter'     => 'error',
						'swp_newsletter_msg' => 'scrumptious_email' === $result->get_error_code() ? 'email' : 'failed',
					),
					$back
				)
			);
			exit;
		}
		Upsell::set_state( array( 'newsletter' => 'done' ) );
		wp_safe_redirect( add_query_arg( 'swp_newsletter', 'done', $back ) );
		exit;
	}

	/**
	 * Admin-post: hide the Settings card for good.
	 */
	public static function dismiss() {
		check_admin_referer( 'scrumptious_newsletter_dismiss' );
		if ( current_user_can( 'manage_options' ) && '' === self::status() ) {
			Upsell::set_state( array( 'newsletter' => 'dismissed' ) );
		}
		wp_safe_redirect( wp_get_referer() ? remove_query_arg( array( 'swp_newsletter', 'swp_newsletter_msg' ), wp_get_referer() ) : Settings::url() );
		exit;
	}

	/**
	 * The result message after a signup attempt (from the redirect), or ''.
	 *
	 * @return string HTML.
	 */
	public static function result_message() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$result = isset( $_GET['swp_newsletter'] ) ? sanitize_key( wp_unslash( $_GET['swp_newsletter'] ) ) : '';
		$msg    = isset( $_GET['swp_newsletter_msg'] ) ? sanitize_key( wp_unslash( $_GET['swp_newsletter_msg'] ) ) : '';
		// phpcs:enable
		if ( 'done' === $result ) {
			return '<div class="notice notice-success inline swp-newsletter__result"><p>' . esc_html__( 'Thanks! Check your inbox for an email to confirm your subscription.', 'scrumptious' ) . '</p></div>';
		}
		if ( 'error' === $result ) {
			$text = 'email' === $msg ? __( 'That email address doesn’t look right.', 'scrumptious' ) : __( 'Couldn’t sign you up just now, try again later.', 'scrumptious' );
			return '<div class="notice notice-error inline swp-newsletter__result"><p>' . esc_html( $text ) . '</p></div>';
		}
		return '';
	}

	/**
	 * Show the email field only once the box is ticked (a tiny footer script, once per page).
	 */
	private static function script() {
		if ( wp_script_is( 'scrumptious-newsletter', 'enqueued' ) ) {
			return;
		}
		wp_register_script( 'scrumptious-newsletter', false, array(), SCRUMPTIOUS_VERSION, true );
		wp_enqueue_script( 'scrumptious-newsletter' );
		wp_add_inline_script(
			'scrumptious-newsletter',
			"document.addEventListener('change',function(e){var b=e.target;if(!b.classList||!b.classList.contains('swp-newsletter__opt')){return;}var f=b.form.querySelector('.swp-newsletter__fields');f.hidden=!b.checked;if(b.checked){f.querySelector('input').focus();}});"
		);
	}

	/**
	 * The signup form: an unticked box; the email field and button show once it's ticked.
	 *
	 * @param string $source  plugin-setup or plugin-settings.
	 * @param bool   $dismiss Whether to offer "No thanks" (Settings card).
	 */
	public static function form( $source, $dismiss = false ) {
		self::script();
		$id    = 'swp-newsletter-' . $source;
		$email = (string) get_option( 'admin_email', '' );
		?>
		<form class="swp-newsletter" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="scrumptious_newsletter" />
			<input type="hidden" name="newsletter_source" value="<?php echo esc_attr( $source ); ?>" />
			<?php wp_nonce_field( 'scrumptious_newsletter' ); ?>
			<p>
				<label>
					<input type="checkbox" name="newsletter_opt_in" value="1" class="swp-newsletter__opt" aria-controls="<?php echo esc_attr( $id ); ?>" />
					<?php esc_html_e( 'Email me recipe card tips, new features and the occasional offer (about once a month). Unsubscribe any time.', 'scrumptious' ); ?>
				</label>
			</p>
			<div class="swp-newsletter__fields" id="<?php echo esc_attr( $id ); ?>" hidden>
				<p>
					<label for="<?php echo esc_attr( $id ); ?>-email" class="screen-reader-text"><?php esc_html_e( 'Email address', 'scrumptious' ); ?></label>
					<input type="email" id="<?php echo esc_attr( $id ); ?>-email" name="newsletter_email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" autocomplete="email" />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Sign me up', 'scrumptious' ); ?></button>
				</p>
			</div>
			<p class="swp-newsletter__small">
				<?php esc_html_e( 'Only sent if you tick the box. Your email goes to scrumptiouswp.com and our email provider, Acumbamail.', 'scrumptious' ); ?>
				<a href="<?php echo esc_url( self::PRIVACY ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy policy', 'scrumptious' ); ?></a>
				<?php if ( $dismiss ) : ?>
					· <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=scrumptious_newsletter_dismiss' ), 'scrumptious_newsletter_dismiss' ) ); ?>"><?php esc_html_e( 'No thanks', 'scrumptious' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	/**
	 * The Settings screen card (until signed up or dismissed).
	 */
	public static function settings_card() {
		$message = self::result_message();
		if ( ! self::show() ) {
			echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
			return;
		}
		?>
		<aside class="swp-newsletter-card" aria-labelledby="swp-newsletter-title">
			<h2 id="swp-newsletter-title"><?php esc_html_e( 'Tips by email', 'scrumptious' ); ?></h2>
			<?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?>
			<?php self::form( 'plugin-settings', true ); ?>
		</aside>
		<?php
	}
}
