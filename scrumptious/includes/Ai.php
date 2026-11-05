<?php
/**
 * Bring-your-own-key AI: OpenRouter (one-click connect), Google Gemini, OpenAI, Anthropic.
 *
 * Keys belong to the site owner, are stored encrypted, never leave the server and are
 * never sent to the browser. Requests go straight from this site to the provider.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Provider settings, key storage and a single JSON-completion call.
 */
final class Ai {

	/** Slug of the WordPress AI Client provider. A constant so code-style fixers can't "correct" its spelling. */
	const WP_PROVIDER = 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- a slug, not the brand name.


	const OPTION  = 'scrumptious_ai';
	const TIMEOUT = 60;

	/**
	 * Providers. `prefer` lists regexes (best first) used to pick a fast, low-cost model
	 * from the provider's live model list, so defaults never go stale. `fallback` is used
	 * only when the list can't be loaded.
	 */
	const PROVIDERS = array(
		'wordpress'  => array(
			'label'    => 'WordPress AI (Settings → Connectors)',
			'key_url'  => '',
			'prefer'   => array(),
			'fallback' => '',
		),
		'openrouter' => array(
			'label'    => 'OpenRouter',
			'key_url'  => 'https://openrouter.ai/settings/keys',
			'prefer'   => array( '#^google/gemini-[\d.]+-flash-lite$#', '#^google/gemini-[\d.]+-flash$#', '#^openai/gpt-[\d.]+-mini$#', '#^anthropic/claude-haiku#' ),
			'fallback' => 'google/gemini-2.5-flash-lite',
		),
		'gemini'     => array(
			'label'    => 'Google Gemini',
			'key_url'  => 'https://aistudio.google.com/app/apikey',
			'prefer'   => array( '#^gemini-[\d.]+-flash-lite$#', '#^gemini-[\d.]+-flash$#' ),
			'fallback' => 'gemini-2.5-flash-lite',
		),
		'openai'     => array(
			'label'    => 'OpenAI',
			'key_url'  => 'https://platform.openai.com/api-keys',
			'prefer'   => array( '#^gpt-[\d.]+-mini$#', '#^gpt-[\d.o]+-mini$#' ),
			'fallback' => 'gpt-4.1-mini',
		),
		'anthropic'  => array(
			'label'    => 'Anthropic (Claude)',
			'key_url'  => 'https://console.anthropic.com/settings/keys',
			'prefer'   => array( '#^claude-haiku-[\d-]+$#', '#^claude-[\d-]+-haiku#' ),
			'fallback' => 'claude-haiku-4-5',
		),
	);

	/**
	 * Stored AI settings: provider, model, encrypted keys.
	 *
	 * @return array{provider:string,model:string,keys:array<string,string>}
	 */
	public static function settings() {
		$s = get_option( self::OPTION, null );
		if ( ! is_array( $s ) ) {
			// Never configured: use WordPress's own AI connection when the site has one.
			$s = array( 'provider' => self::wp_ai_ready() ? 'wordpress' : '' );
		}
		return array(
			'provider' => isset( $s['provider'] ) && isset( self::PROVIDERS[ $s['provider'] ] ) ? (string) $s['provider'] : '',
			'model'    => isset( $s['model'] ) ? sanitize_text_field( (string) $s['model'] ) : '',
			'keys'     => isset( $s['keys'] ) && is_array( $s['keys'] ) ? array_map( 'strval', $s['keys'] ) : array(),
		);
	}

	/**
	 * Save AI settings (autoload off: only needed when AI runs).
	 *
	 * @param array<string,mixed> $s Settings.
	 */
	private static function save( array $s ) {
		update_option( self::OPTION, $s, false );
	}

	/**
	 * Active provider ('' when none).
	 *
	 * @return string
	 */
	public static function provider() {
		return self::settings()['provider'];
	}

	/**
	 * Per-user hourly cap on AI requests from the editor, so a contributor can't run up the
	 * site owner's AI bill. Administrators aren't limited. Counts the request when allowed.
	 *
	 * @return bool
	 */
	public static function within_limit() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		/**
		 * AI requests per user per hour (Paste & Parse with AI, Suggest with AI).
		 *
		 * @param int $limit Default 60.
		 */
		$limit = (int) apply_filters( 'scrumptious_ai_hourly_limit', 60 );
		return Rate_Limit::hit( 'ai_' . get_current_user_id(), $limit );
	}

	/**
	 * Whether AI is ready to use.
	 *
	 * @return bool
	 */
	public static function configured() {
		$p = self::provider();
		if ( self::WP_PROVIDER === $p ) {
			return self::wp_ai_ready();
		}
		return '' !== $p && '' !== self::get_key( $p );
	}

	/**
	 * Whether WordPress's built-in AI client (WP 7.0+) exists on this site.
	 *
	 * @return bool
	 */
	public static function wp_ai_available() {
		return function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' );
	}

	/**
	 * Whether WordPress's AI client can generate text right now
	 * (AI allowed and a provider connected under Settings → Connectors).
	 *
	 * @return bool
	 */
	public static function wp_ai_ready() {
		static $ready = null;
		if ( null !== $ready ) {
			return $ready;
		}
		$ready = false;
		if ( self::wp_ai_available() && wp_supports_ai() ) {
			try {
				$ready = true === wp_ai_client_prompt( 'ping' )->is_supported_for_text_generation();
			} catch ( \Throwable $e ) {
				$ready = false;
			}
		}
		return $ready;
	}

	/**
	 * Set the active provider (and optionally a model; '' = automatic).
	 *
	 * @param string $provider Provider slug.
	 * @param string $model    Model ID.
	 */
	public static function set_provider( $provider, $model = '' ) {
		$s             = self::settings();
		$s['provider'] = isset( self::PROVIDERS[ $provider ] ) ? $provider : '';
		$s['model']    = sanitize_text_field( (string) $model );
		self::save( $s );
	}

	/**
	 * Decrypted key for a provider ('' when none).
	 *
	 * @param string $provider Provider.
	 * @return string
	 */
	public static function get_key( $provider ) {
		$keys = self::settings()['keys'];
		return isset( $keys[ $provider ] ) ? self::decrypt( $keys[ $provider ] ) : '';
	}

	/**
	 * Store (or clear with '') a provider key.
	 *
	 * @param string $provider Provider.
	 * @param string $key      API key.
	 */
	public static function set_key( $provider, $key ) {
		if ( ! isset( self::PROVIDERS[ $provider ] ) || self::WP_PROVIDER === $provider ) {
			return;
		}
		$s   = self::settings();
		$key = trim( preg_replace( '/[^\x21-\x7E]/', '', (string) $key ) );
		if ( '' === $key ) {
			unset( $s['keys'][ $provider ] );
		} else {
			$s['keys'][ $provider ] = self::encrypt( $key );
		}
		self::save( $s );
		delete_transient( 'scrumptious_ai_models_' . $provider );
	}

	/**
	 * Masked key for display, e.g. "sk-or-…9f2c".
	 *
	 * @param string $provider Provider.
	 * @return string
	 */
	public static function masked_key( $provider ) {
		$key = self::get_key( $provider );
		if ( '' === $key ) {
			return '';
		}
		return substr( $key, 0, 5 ) . '…' . substr( $key, -4 );
	}

	/* ---------- Encryption (AES-256-CTR keyed from the site's salts) ---------- */

	/**
	 * Encryption key derived from wp-config salts.
	 *
	 * @return string
	 */
	private static function secret() {
		$material = ( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '' ) . ( defined( 'LOGGED_IN_SALT' ) ? LOGGED_IN_SALT : '' );
		if ( '' === $material ) {
			$material = function_exists( 'wp_salt' ) ? wp_salt( 'logged_in' ) : 'scrumptious';
		}
		return hash( 'sha256', 'scrumptious-ai|' . $material, true );
	}

	/**
	 * Encrypt a value. Falls back to base64 (clearly prefixed) when OpenSSL is missing.
	 *
	 * @param string $value Plain value.
	 * @return string
	 */
	public static function encrypt( $value ) {
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv  = random_bytes( 16 );
			$enc = openssl_encrypt( $value, 'aes-256-ctr', self::secret(), OPENSSL_RAW_DATA, $iv );
			if ( false !== $enc ) {
				$mac = hash_hmac( 'sha256', $iv . $enc, self::secret(), true );
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext storage.
				return 'swp1:' . base64_encode( $iv . substr( $mac, 0, 16 ) . $enc );
			}
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- no OpenSSL available.
		return 'swp0:' . base64_encode( $value );
	}

	/**
	 * Decrypt a stored value ('' when it can't be read, e.g. salts changed).
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- stored ciphertext.
		if ( 0 === strpos( $stored, 'swp1:' ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $stored, 5 ), true );
			if ( false === $raw || strlen( $raw ) < 33 ) {
				return '';
			}
			$iv  = substr( $raw, 0, 16 );
			$mac = substr( $raw, 16, 16 );
			$enc = substr( $raw, 32 );
			if ( ! hash_equals( substr( hash_hmac( 'sha256', $iv . $enc, self::secret(), true ), 0, 16 ), $mac ) ) {
				return '';
			}
			$out = openssl_decrypt( $enc, 'aes-256-ctr', self::secret(), OPENSSL_RAW_DATA, $iv );
			return false === $out ? '' : $out;
		}
		if ( 0 === strpos( $stored, 'swp0:' ) ) {
			$out = base64_decode( substr( $stored, 5 ), true );
			return false === $out ? '' : $out;
		}
		// phpcs:enable
		return '';
	}

	/* ---------- Models ---------- */

	/**
	 * Model IDs available to the provider's key (cached for a day).
	 *
	 * @param string $provider Provider.
	 * @param bool   $refresh  Skip the cache.
	 * @return string[]|\WP_Error
	 */
	public static function models( $provider, $refresh = false ) {
		$cache = 'scrumptious_ai_models_' . $provider;
		if ( ! $refresh ) {
			$cached = get_transient( $cache );
			if ( is_array( $cached ) && $cached ) {
				return $cached;
			}
		}
		$key = self::get_key( $provider );
		switch ( $provider ) {
			case 'openrouter':
				$res = self::http( 'GET', 'https://openrouter.ai/api/v1/models', array() );
				$ids = is_wp_error( $res ) ? $res : wp_list_pluck( isset( $res['data'] ) ? (array) $res['data'] : array(), 'id' );
				break;
			case 'openai':
				$res = self::http( 'GET', 'https://api.openai.com/v1/models', array( 'Authorization' => 'Bearer ' . $key ) );
				$ids = is_wp_error( $res ) ? $res : wp_list_pluck( isset( $res['data'] ) ? (array) $res['data'] : array(), 'id' );
				if ( ! is_wp_error( $ids ) ) {
					$ids = array_filter(
						$ids,
						static function ( $id ) {
							return (bool) preg_match( '/^(gpt-|o\d|chatgpt-)/', $id ) && ! preg_match( '/(audio|realtime|image|tts|transcribe|search|embedding|instruct)/', $id );
						}
					);
				}
				break;
			case 'anthropic':
				$res = self::http( 'GET', 'https://api.anthropic.com/v1/models?limit=100', self::anthropic_headers( $key ) );
				$ids = is_wp_error( $res ) ? $res : wp_list_pluck( isset( $res['data'] ) ? (array) $res['data'] : array(), 'id' );
				break;
			case 'gemini':
				$res = self::http( 'GET', 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200', array( 'x-goog-api-key' => $key ) );
				$ids = array();
				if ( is_wp_error( $res ) ) {
					$ids = $res;
				} else {
					foreach ( isset( $res['models'] ) ? (array) $res['models'] : array() as $m ) {
						if ( isset( $m['name'], $m['supportedGenerationMethods'] ) && in_array( 'generateContent', (array) $m['supportedGenerationMethods'], true ) ) {
							$ids[] = preg_replace( '#^models/#', '', (string) $m['name'] );
						}
					}
				}
				break;
			default:
				return new \WP_Error( 'scrumptious_ai_provider', __( 'Choose an AI provider first.', 'scrumptious' ) );
		}
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		$ids = array_values( array_unique( array_map( 'strval', $ids ) ) );
		sort( $ids, SORT_NATURAL );
		if ( $ids ) {
			set_transient( $cache, $ids, DAY_IN_SECONDS );
		}
		return $ids;
	}

	/**
	 * Pick the recommended model from a list (newest fast/low-cost model, no previews).
	 *
	 * @param string   $provider Provider.
	 * @param string[] $ids      Model IDs.
	 * @return string
	 */
	public static function pick_model( $provider, array $ids ) {
		if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
			return '';
		}
		$ids = array_filter(
			$ids,
			static function ( $id ) {
				return ! preg_match( '/(preview|exp|latest|image|tts|audio|live|thinking|:free)/', $id );
			}
		);
		foreach ( self::PROVIDERS[ $provider ]['prefer'] as $re ) {
			$match = array_values( preg_grep( $re, $ids ) );
			if ( $match ) {
				usort(
					$match,
					static function ( $a, $b ) {
						return strnatcmp( $b, $a );
					}
				);
				return $match[0];
			}
		}
		return self::PROVIDERS[ $provider ]['fallback'];
	}

	/**
	 * The model to use: the saved choice, else the recommended one.
	 *
	 * @return string
	 */
	public static function model() {
		$s = self::settings();
		if ( self::WP_PROVIDER === $s['provider'] ) {
			return __( 'the model chosen by WordPress', 'scrumptious' );
		}
		if ( '' !== $s['model'] ) {
			return $s['model'];
		}
		$ids = self::models( $s['provider'] );
		return self::pick_model( $s['provider'], is_wp_error( $ids ) ? array() : $ids );
	}

	/* ---------- Completion ---------- */

	/**
	 * Ask the configured model for a JSON object.
	 *
	 * @param string $system Instructions.
	 * @param string $user   Input.
	 * @return array<string,mixed>|\WP_Error Decoded JSON object.
	 */
	public static function json( $system, $user ) {
		if ( ! self::configured() ) {
			return new \WP_Error( 'scrumptious_ai_off', __( 'AI is not set up yet. Connect a provider under Recipes → Settings → AI.', 'scrumptious' ) );
		}
		$provider = self::provider();
		if ( self::WP_PROVIDER === $provider ) {
			return self::wp_json( $system, $user );
		}
		$key   = self::get_key( $provider );
		$model = self::model();

		switch ( $provider ) {
			case 'openrouter':
			case 'openai':
				$url     = 'openrouter' === $provider ? 'https://openrouter.ai/api/v1/chat/completions' : 'https://api.openai.com/v1/chat/completions';
				$headers = array( 'Authorization' => 'Bearer ' . $key );
				if ( 'openrouter' === $provider ) {
					$headers['HTTP-Referer'] = home_url( '/' );
					$headers['X-Title']      = 'Scrumptious Recipe Cards';
				}
				$res  = self::http(
					'POST',
					$url,
					$headers,
					array(
						'model'           => $model,
						'messages'        => array(
							array(
								'role'    => 'system',
								'content' => $system,
							),
							array(
								'role'    => 'user',
								'content' => $user,
							),
						),
						'response_format' => array( 'type' => 'json_object' ),
					)
				);
				$text = is_wp_error( $res ) ? $res : ( isset( $res['choices'][0]['message']['content'] ) ? (string) $res['choices'][0]['message']['content'] : '' );
				break;
			case 'anthropic':
				$res  = self::http(
					'POST',
					'https://api.anthropic.com/v1/messages',
					self::anthropic_headers( $key ),
					array(
						'model'      => $model,
						'max_tokens' => 8000,
						'system'     => $system,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => $user,
							),
						),
					)
				);
				$text = is_wp_error( $res ) ? $res : ( isset( $res['content'][0]['text'] ) ? (string) $res['content'][0]['text'] : '' );
				break;
			case 'gemini':
				$res  = self::http(
					'POST',
					'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent',
					array( 'x-goog-api-key' => $key ),
					array(
						'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
						'contents'          => array(
							array(
								'role'  => 'user',
								'parts' => array( array( 'text' => $user ) ),
							),
						),
						'generationConfig'  => array( 'responseMimeType' => 'application/json' ),
					)
				);
				$text = is_wp_error( $res ) ? $res : ( isset( $res['candidates'][0]['content']['parts'][0]['text'] ) ? (string) $res['candidates'][0]['content']['parts'][0]['text'] : '' );
				break;
			default:
				return new \WP_Error( 'scrumptious_ai_provider', __( 'Unknown AI provider.', 'scrumptious' ) );
		}

		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$data = self::decode_json( $text );
		if ( null === $data ) {
			return new \WP_Error( 'scrumptious_ai_json', __( 'The AI reply could not be read. Try again, or pick a different model.', 'scrumptious' ) );
		}
		return $data;
	}

	/**
	 * JSON completion through WordPress's AI client (WP 7.0+).
	 *
	 * @param string $system Instructions.
	 * @param string $user   Input.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function wp_json( $system, $user ) {
		try {
			$text = wp_ai_client_prompt( $user )
				->using_system_instruction( $system )
				->as_json_response()
				->generate_text();
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'scrumptious_ai_wp', $e->getMessage() );
		}
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$data = self::decode_json( (string) $text );
		return null === $data ? new \WP_Error( 'scrumptious_ai_json', __( 'The AI reply could not be read. Try again.', 'scrumptious' ) ) : $data;
	}

	/**
	 * Decode a JSON object from model output (tolerates code fences and chatter).
	 *
	 * @param string $text Model output.
	 * @return array<string,mixed>|null
	 */
	public static function decode_json( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $text );
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			$start = strpos( $text, '{' );
			$end   = strrpos( $text, '}' );
			if ( false !== $start && false !== $end && $end > $start ) {
				$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			}
		}
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Anthropic headers.
	 *
	 * @param string $key Key.
	 * @return array<string,string>
	 */
	private static function anthropic_headers( $key ) {
		return array(
			'x-api-key'         => $key,
			'anthropic-version' => '2023-06-01',
		);
	}

	/**
	 * JSON HTTP call with readable errors.
	 *
	 * @param string                   $method  GET|POST.
	 * @param string                   $url     URL.
	 * @param array<string,string>     $headers Headers.
	 * @param array<string,mixed>|null $body    JSON body.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function http( $method, $url, array $headers, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => $headers + array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'scrumptious_ai_http', self::error_message( $code, is_array( $data ) ? $data : array() ), array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Friendly message for a provider error.
	 *
	 * @param int                 $code HTTP status.
	 * @param array<string,mixed> $data Decoded body.
	 * @return string
	 */
	public static function error_message( $code, array $data ) {
		$detail = '';
		if ( isset( $data['error']['message'] ) ) {
			$detail = (string) $data['error']['message'];
		} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
			$detail = $data['error'];
		} elseif ( isset( $data['message'] ) ) {
			$detail = (string) $data['message'];
		}
		switch ( $code ) {
			case 401:
			case 403:
				$msg = __( 'The AI provider rejected the API key. Check or reconnect it under Recipes → Settings → AI.', 'scrumptious' );
				break;
			case 402:
				$msg = __( 'Your AI account is out of credit. Add credit with your provider, then try again.', 'scrumptious' );
				break;
			case 404:
				$msg = __( 'That AI model is not available to your key. Pick another model under Recipes → Settings → AI.', 'scrumptious' );
				break;
			case 429:
				$msg = __( 'The AI provider is rate-limiting your key. Wait a minute and try again.', 'scrumptious' );
				break;
			default:
				/* translators: %d: HTTP status code */
				$msg = sprintf( __( 'The AI provider returned an error (%d).', 'scrumptious' ), (int) $code );
		}
		return '' !== $detail ? $msg . ' ' . wp_strip_all_tags( substr( $detail, 0, 200 ) ) : $msg;
	}
}
