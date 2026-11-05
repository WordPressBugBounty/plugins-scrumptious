<?php
/**
 * Settings → AI: connect OpenRouter in one click (OAuth PKCE), or paste a key
 * for Google Gemini, OpenAI or Anthropic. Test the connection. Choose a model.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Admin handlers and the AI settings section.
 */
final class Ai_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_scrumptious_ai_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_scrumptious_openrouter_connect', array( __CLASS__, 'connect' ) );
		add_action( 'admin_post_scrumptious_ai_test', array( __CLASS__, 'test' ) );
		add_action( 'admin_init', array( __CLASS__, 'callback' ) );
	}

	/**
	 * Where OpenRouter sends the user back.
	 *
	 * @return string
	 */
	public static function callback_url() {
		return add_query_arg( 'scrumptious_openrouter', '1', Settings::url() );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $bin Binary.
	 * @return string
	 */
	public static function b64url( $bin ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE encoding.
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	/**
	 * PKCE code challenge for a verifier (S256).
	 *
	 * @param string $verifier Verifier.
	 * @return string
	 */
	public static function challenge( $verifier ) {
		return self::b64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Start "Connect OpenRouter": send the user to OpenRouter to approve a key for this site.
	 */
	public static function connect() {
		self::guard( 'scrumptious_openrouter_connect' );
		$verifier = self::b64url( random_bytes( 48 ) );
		set_transient( 'scrumptious_or_' . get_current_user_id(), $verifier, 15 * MINUTE_IN_SECONDS );
		$url = add_query_arg(
			array(
				'callback_url'          => rawurlencode( self::callback_url() ),
				'code_challenge'        => self::challenge( $verifier ),
				'code_challenge_method' => 'S256',
			),
			'https://openrouter.ai/auth'
		);
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed external host (openrouter.ai).
		wp_redirect( $url );
		exit;
	}

	/**
	 * OpenRouter redirected back with ?code=…: exchange it for a key.
	 * CSRF protection: the code only works with the PKCE verifier stored for this user.
	 */
	public static function callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth callback, protected by PKCE verifier.
		if ( ! isset( $_GET['scrumptious_openrouter'], $_GET['code'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		// phpcs:enable
		$verifier = get_transient( 'scrumptious_or_' . get_current_user_id() );
		delete_transient( 'scrumptious_or_' . get_current_user_id() );

		if ( ! is_string( $verifier ) || '' === $verifier ) {
			self::notice( 'error', __( 'The OpenRouter connection expired. Please click Connect again.', 'scrumptious' ) );
		} else {
			$res  = wp_remote_post(
				'https://openrouter.ai/api/v1/auth/keys',
				array(
					'timeout' => 30,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode(
						array(
							'code'                  => $code,
							'code_verifier'         => $verifier,
							'code_challenge_method' => 'S256',
						)
					),
				)
			);
			$data = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( is_array( $data ) && ! empty( $data['key'] ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
				Ai::set_key( 'openrouter', (string) $data['key'] );
				Ai::set_provider( 'openrouter' );
				self::notice( 'success', __( 'OpenRouter is connected. Paste & Parse and the other AI tools are ready.', 'scrumptious' ) );
			} else {
				$msg = is_wp_error( $res ) ? $res->get_error_message() : Ai::error_message( (int) wp_remote_retrieve_response_code( $res ), is_array( $data ) ? $data : array() );
				/* translators: %s: error detail */
				self::notice( 'error', sprintf( __( 'Could not connect OpenRouter: %s', 'scrumptious' ), $msg ) );
			}
		}
		wp_safe_redirect( Settings::url() . '#swp-ai' );
		exit;
	}

	/**
	 * Save provider, model and pasted keys.
	 */
	public static function save() {
		self::guard( 'scrumptious_ai_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$model    = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
		foreach ( array_keys( Ai::PROVIDERS ) as $p ) {
			if ( ! empty( $_POST['remove'][ $p ] ) ) {
				Ai::set_key( $p, '' );
			} elseif ( isset( $_POST['key'][ $p ] ) ) {
				$key = sanitize_text_field( wp_unslash( $_POST['key'][ $p ] ) );
				if ( '' !== $key ) {
					Ai::set_key( $p, $key );
				}
			}
		}
		// phpcs:enable
		$changed = Ai::provider() !== $provider;
		Ai::set_provider( $provider, $changed ? '' : $model );
		self::notice( 'success', __( 'AI settings saved.', 'scrumptious' ) );
		wp_safe_redirect( Settings::url() . '#swp-ai' );
		exit;
	}

	/**
	 * Send a tiny request to prove the key and model work.
	 */
	public static function test() {
		self::guard( 'scrumptious_ai_test' );
		$res = Ai::json( 'Reply with the JSON object {"ok": true} and nothing else.', 'Test' );
		if ( is_wp_error( $res ) ) {
			self::notice( 'error', $res->get_error_message() );
		} else {
			/* translators: 1: provider name, 2: model ID */
			self::notice( 'success', sprintf( __( 'It works! %1$s answered using %2$s.', 'scrumptious' ), Ai::PROVIDERS[ Ai::provider() ]['label'], Ai::model() ) );
		}
		wp_safe_redirect( Settings::url() . '#swp-ai' );
		exit;
	}

	/**
	 * Providers that use an API key (everything except WordPress AI).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function key_providers() {
		$list = Ai::PROVIDERS;
		unset( $list['wordpress'] );
		return $list;
	}

	/**
	 * Capability + nonce check for admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'scrumptious' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Queue a one-time notice for the settings screen.
	 *
	 * @param string $type    success|error.
	 * @param string $message Message.
	 */
	private static function notice( $type, $message ) {
		set_transient(
			'scrumptious_ai_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS * 5
		);
	}

	/**
	 * Render the AI section of the settings screen (its own form).
	 */
	public static function render() {
		$s        = Ai::settings();
		$notice   = get_transient( 'scrumptious_ai_notice_' . get_current_user_id() );
		$provider = $s['provider'];
		delete_transient( 'scrumptious_ai_notice_' . get_current_user_id() );

		$models = array();
		$auto   = '';
		if ( $provider && '' !== Ai::get_key( $provider ) ) {
			$list   = Ai::models( $provider );
			$models = is_wp_error( $list ) ? array() : $list;
			$auto   = Ai::pick_model( $provider, $models );
		}
		$post = admin_url( 'admin-post.php' );
		?>
		<hr id="swp-ai" style="margin:32px 0 8px" />
		<h2 class="title"><?php esc_html_e( 'AI (bring your own key)', 'scrumptious' ); ?><?php echo Help::icon( 'ai-setup' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Help::icon(). ?></h2>
		<?php if ( is_array( $notice ) && ! empty( $notice['message'] ) ) : ?>
			<div class="notice notice-<?php echo 'error' === $notice['type'] ? 'error' : 'success'; ?> inline"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
		<?php endif; ?>
		<p style="max-width:720px">
			<?php esc_html_e( 'AI powers Paste & Parse: paste any recipe text and get a finished card. You use your own account with an AI provider, so you pay the provider directly — usually well under a cent per recipe. Scrumptious never sees your key or your recipes; requests go straight from your site to the provider.', 'scrumptious' ); ?>
		</p>
		<p style="max-width:720px"><strong><?php esc_html_e( 'Easiest:', 'scrumptious' ); ?></strong> <?php esc_html_e( 'connect OpenRouter. One account works with Google, OpenAI, Anthropic and more, and connecting takes one click — no key to copy.', 'scrumptious' ); ?></p>

		<?php if ( Ai::wp_ai_available() ) : ?>
			<p style="max-width:720px"><?php esc_html_e( 'Already connected an AI provider in WordPress (Settings → Connectors)? Choose "WordPress AI" below and you are done.', 'scrumptious' ); ?></p>
		<?php endif; ?>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'scrumptious_openrouter_connect', $post ), 'scrumptious_openrouter_connect' ) ); ?>">
				<?php echo '' !== Ai::get_key( 'openrouter' ) ? esc_html__( 'Reconnect OpenRouter', 'scrumptious' ) : esc_html__( 'Connect OpenRouter', 'scrumptious' ); ?>
			</a>
			<span class="description" style="margin-left:8px">
				<?php
				printf(
					/* translators: %s: OpenRouter sign-up link */
					esc_html__( 'No account? You can create one on the next screen, or at %s. Add a few dollars of credit and you are set.', 'scrumptious' ),
					'<a href="https://openrouter.ai/" target="_blank" rel="noopener">openrouter.ai</a>'
				);
				?>
			</span>
		</p>

		<form method="post" action="<?php echo esc_url( $post ); ?>">
			<input type="hidden" name="action" value="scrumptious_ai_save" />
			<?php wp_nonce_field( 'scrumptious_ai_save' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Use', 'scrumptious' ); ?></th>
					<td>
						<fieldset>
							<label style="display:block;margin-bottom:6px">
								<input type="radio" name="provider" value="" <?php checked( $provider, '' ); ?> />
								<?php esc_html_e( 'No AI (Paste & Parse still works with the built-in parser)', 'scrumptious' ); ?>
							</label>
							<?php if ( Ai::wp_ai_available() ) : ?>
								<label style="display:block;margin-bottom:6px">
									<input type="radio" name="provider" value="wordpress" <?php checked( $provider, Ai::WP_PROVIDER ); ?> />
									<?php esc_html_e( 'WordPress AI — use the provider connected under Settings → Connectors', 'scrumptious' ); ?>
									<?php if ( Ai::wp_ai_ready() ) : ?>
										<span style="color:#1a7f37">— <?php esc_html_e( 'ready', 'scrumptious' ); ?></span>
									<?php else : ?>
										— <a href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>"><?php esc_html_e( 'connect a provider first', 'scrumptious' ); ?></a>
									<?php endif; ?>
								</label>
							<?php endif; ?>
							<?php foreach ( self::key_providers() as $slug => $p ) : ?>
								<label style="display:block;margin-bottom:6px">
									<input type="radio" name="provider" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $provider, $slug ); ?> />
									<?php echo esc_html( $p['label'] ); ?>
									<?php if ( '' !== Ai::get_key( $slug ) ) : ?>
										<span style="color:#1a7f37">— <?php esc_html_e( 'key saved', 'scrumptious' ); ?> (<code><?php echo esc_html( Ai::masked_key( $slug ) ); ?></code>)</span>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<?php foreach ( self::key_providers() as $slug => $p ) : ?>
					<tr>
						<th scope="row"><label for="swp-key-<?php echo esc_attr( $slug ); ?>">
							<?php
							/* translators: %s: provider name */
							printf( esc_html__( '%s API key', 'scrumptious' ), esc_html( $p['label'] ) );
							?>
						</label></th>
						<td>
							<input type="password" autocomplete="new-password" class="regular-text" id="swp-key-<?php echo esc_attr( $slug ); ?>" name="key[<?php echo esc_attr( $slug ); ?>]" value="" placeholder="<?php echo '' !== Ai::get_key( $slug ) ? esc_attr__( 'Saved — paste a new key to replace it', 'scrumptious' ) : ''; ?>" />
							<?php if ( '' !== Ai::get_key( $slug ) ) : ?>
								<label style="margin-left:8px"><input type="checkbox" name="remove[<?php echo esc_attr( $slug ); ?>]" value="1" /> <?php esc_html_e( 'Remove', 'scrumptious' ); ?></label>
							<?php endif; ?>
							<p class="description">
								<a href="<?php echo esc_url( $p['key_url'] ); ?>" target="_blank" rel="noopener">
									<?php
									/* translators: %s: provider name */
									printf( esc_html__( 'Get a %s key →', 'scrumptious' ), esc_html( $p['label'] ) );
									?>
								</a>
								<?php if ( 'openrouter' === $slug ) : ?>
									<?php esc_html_e( '(Only needed if you would rather paste a key than use Connect.)', 'scrumptious' ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( $provider && Ai::WP_PROVIDER !== $provider ) : ?>
					<tr>
						<th scope="row"><label for="swp-model"><?php esc_html_e( 'Model', 'scrumptious' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="swp-model" name="model" list="swp-models" value="<?php echo esc_attr( $s['model'] ); ?>" placeholder="<?php echo esc_attr( $auto ? sprintf( /* translators: %s: model ID */ __( 'Automatic: %s', 'scrumptious' ), $auto ) : __( 'Automatic', 'scrumptious' ) ); ?>" />
							<datalist id="swp-models">
								<?php foreach ( $models as $m ) : ?>
									<option value="<?php echo esc_attr( $m ); ?>"></option>
								<?php endforeach; ?>
							</datalist>
							<p class="description"><?php esc_html_e( 'Leave empty to use a fast, low-cost model that we pick for you and keep up to date. Type to search every model your key can use.', 'scrumptious' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
			</table>
			<?php submit_button( __( 'Save AI settings', 'scrumptious' ), 'secondary' ); ?>
		</form>
		<?php if ( Ai::configured() ) : ?>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'scrumptious_ai_test', $post ), 'scrumptious_ai_test' ) ); ?>"><?php esc_html_e( 'Test the connection', 'scrumptious' ); ?></a>
			</p>
		<?php endif; ?>
		<?php
	}
}
