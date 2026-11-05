<?php
/**
 * Where the free plugin mentions Scrumptious Pro: a "Get Pro" link on the Plugins screen and a
 * card on the settings screen. Links out only; no Pro code ships in the free plugin. Hidden once
 * Pro is installed, and only shown to users who can manage options.
 *
 * @package Scrumptious
 */

namespace Scrumptious;

defined( 'ABSPATH' ) || exit;

/**
 * Pro mentions.
 */
final class Upsell {

	const PRICING    = 'https://scrumptiouswp.com/#pricing';
	const HELP       = 'https://scrumptiouswp.com/help/';
	const REVIEW     = 'https://wordpress.org/support/plugin/scrumptious/reviews/#new-post';
	const HINTS_META = 'scrumptious_hide_pro_hints';
	const NOTICES    = 'scrumptious_notices';
	const BF_URL     = 'https://scrumptiouswp.com/black-friday/';
	const BF_META    = 'scrumptious_bf2026_dismissed';
	const BF_START   = '2026-11-24 00:00:00';
	const BF_END     = '2026-12-02 23:59:59';

	/**
	 * Hooks (all requests: the REST route is used from the block editor).
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		if ( is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'milestone_notice' ) );
			add_action( 'admin_post_scrumptious_notice', array( __CLASS__, 'notice_action' ) );
			add_action( 'admin_post_scrumptious_hide_hints', array( __CLASS__, 'hide_hints_action' ) );
		}
	}

	/**
	 * Whether to mention Pro at all.
	 *
	 * @return bool
	 */
	public static function show() {
		$show = ! defined( 'SCRUMPTIOUS_PRO_VERSION' ) && current_user_can( 'manage_options' );
		/**
		 * Filter whether the free plugin mentions Scrumptious Pro (Plugins screen link, settings card).
		 *
		 * @param bool $show Whether to show Pro mentions.
		 */
		return (bool) apply_filters( 'scrumptious_show_pro', $show );
	}

	/**
	 * A scrumptiouswp.com link tagged with where in the plugin it was clicked.
	 *
	 * @param string $spot Placement, e.g. plugins-list.
	 * @param string $url  Base URL. Default: pricing.
	 * @return string
	 */
	public static function url( $spot, $url = self::PRICING ) {
		$hash = '';
		$pos  = strpos( $url, '#' );
		if ( false !== $pos ) {
			$hash = substr( $url, $pos );
			$url  = substr( $url, 0, $pos );
		}
		return add_query_arg(
			array(
				'utm_source'   => 'plugin',
				'utm_medium'   => $spot,
				'utm_campaign' => 'free',
			),
			$url
		) . $hash;
	}

	/**
	 * "Get Pro" on the Plugins screen, after Settings.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_link( $links ) {
		if ( self::show() ) {
			$links[] = '<a href="' . esc_url( self::url( 'plugins-list' ) ) . '" target="_blank" rel="noopener" style="color:#e11d74;font-weight:600">' . esc_html__( 'Get Pro', 'scrumptious' ) . '</a>';
		}
		return $links;
	}

	/**
	 * The Pro card on the settings screen.
	 */
	public static function settings_card() {
		if ( ! self::show() ) {
			return;
		}
		?>
		<aside class="swp-pro-card" aria-labelledby="swp-pro-card-title">
			<h2 id="swp-pro-card-title"><?php esc_html_e( 'Scrumptious Pro', 'scrumptious' ); ?></h2>
			<p><?php esc_html_e( 'Everything you use today stays free. Pro adds:', 'scrumptious' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'Import from a link: pull a recipe in from another site\'s recipe data', 'scrumptious' ); ?></li>
				<li><?php esc_html_e( 'Bulk AI: tidy and tag all your recipes at once', 'scrumptious' ); ?></li>
				<li><?php esc_html_e( 'Meal planner and shopping lists for your readers', 'scrumptious' ); ?></li>
				<li><?php esc_html_e( 'Priority support from the person who built it', 'scrumptious' ); ?></li>
			</ul>
			<p class="swp-pro-card__small"><?php esc_html_e( 'From $59/year. 14-day refund, and if you stop paying, the plugin keeps working.', 'scrumptious' ); ?></p>
			<?php if ( self::bf_active( time() ) ) : ?>
				<p class="swp-pro-card__deal"><a href="<?php echo esc_url( self::url( 'settings-bf2026', self::BF_URL ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '50% off this week: see the Black Friday deal', 'scrumptious' ); ?></a></p>
			<?php endif; ?>
			<p><a class="button button-primary swp-pro-card__button" href="<?php echo esc_url( self::url( 'settings-card' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See plans', 'scrumptious' ); ?></a></p>
		</aside>
		<?php
	}

	/**
	 * Whether the small Pro hints (editor, importer) show for the current user.
	 *
	 * @return bool
	 */
	public static function hints() {
		return self::show() && ! get_user_meta( get_current_user_id(), self::HINTS_META, true );
	}

	/**
	 * Data for the block editor's Pro hints, or false when they're off.
	 *
	 * @return array<string,string>|false
	 */
	public static function editor_hints() {
		if ( ! self::hints() ) {
			return false;
		}
		return array(
			'importUrl' => self::url( 'editor-import', self::HELP . 'import-from-url/' ),
			'bulkAiUrl' => self::url( 'editor-bulk-ai', self::HELP . 'bulk-ai/' ),
		);
	}

	/**
	 * REST: hide every Pro hint for the current user (one global dismiss).
	 */
	public static function rest_routes() {
		register_rest_route(
			'scrumptious/v1',
			'/hide-pro-hints',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function () {
					update_user_meta( get_current_user_id(), self::HINTS_META, 1 );
					return array( 'hidden' => true );
				},
			)
		);
	}

	/**
	 * A small grey hint with a link out and an "×" that hides all hints (PHP screens).
	 *
	 * @param string $text      Hint text.
	 * @param string $link_text Link text.
	 * @param string $url       Link URL (already UTM-tagged).
	 * @return string
	 */
	public static function hint_html( $text, $link_text, $url ) {
		if ( ! self::hints() ) {
			return '';
		}
		$hide = wp_nonce_url( admin_url( 'admin-post.php?action=scrumptious_hide_hints' ), 'scrumptious_hide_hints' );
		return '<p class="swp-pro-hint">' . esc_html( $text ) . ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $link_text ) . '</a>'
			. ' <a class="swp-pro-hint__hide" href="' . esc_url( $hide ) . '" aria-label="' . esc_attr__( 'Hide these Pro tips', 'scrumptious' ) . '" title="' . esc_attr__( 'Hide these Pro tips', 'scrumptious' ) . '">&times;</a></p>';
	}

	/**
	 * Admin-post handler: hide all Pro hints for the current user, then go back.
	 */
	public static function hide_hints_action() {
		check_admin_referer( 'scrumptious_hide_hints' );
		if ( current_user_can( 'manage_options' ) ) {
			update_user_meta( get_current_user_id(), self::HINTS_META, 1 );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Published posts that show a Scrumptious recipe card (cached for 12 hours).
	 *
	 * @return int
	 */
	public static function card_posts() {
		$n = get_transient( 'scrumptious_card_posts' );
		if ( false !== $n ) {
			return (int) $n;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- cached count for the milestone notice.
		$n = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_status = 'publish' AND m.meta_value NOT IN ('', 'a:0:{}')",
				Usage::HOST_META
			)
		);
		set_transient( 'scrumptious_card_posts', $n, 12 * HOUR_IN_SECONDS );
		return $n;
	}

	/**
	 * Notice state, stored once per site. Other keys in the option (e.g. newsletter) are kept as they are.
	 *
	 * @return array<string,mixed> At least review, review_until, review_acted, pro, installed.
	 */
	public static function state() {
		$s = get_option( self::NOTICES, array() );
		$s = is_array( $s ) ? $s : array();
		return array_merge(
			$s,
			array(
				'review'       => isset( $s['review'] ) ? (string) $s['review'] : '',
				'review_until' => isset( $s['review_until'] ) ? (int) $s['review_until'] : 0,
				'review_acted' => isset( $s['review_acted'] ) ? (int) $s['review_acted'] : 0,
				'pro'          => isset( $s['pro'] ) ? (string) $s['pro'] : '',
				'installed'    => isset( $s['installed'] ) ? (int) $s['installed'] : 0,
			)
		);
	}

	/**
	 * Change some notice state keys, keeping the rest.
	 *
	 * @param array<string,mixed> $changes Keys to set.
	 */
	public static function set_state( array $changes ) {
		$s = get_option( self::NOTICES, array() );
		$s = is_array( $s ) ? $s : array();
		update_option( self::NOTICES, array_merge( $s, $changes ), false );
	}

	/**
	 * Remember when Scrumptious was first set up (once). Sites that had it before 1.1.0 count
	 * as installed three days ago, so they can see the review request straight away.
	 *
	 * @param bool $existing Whether the site had Scrumptious before this version.
	 */
	public static function mark_installed( $existing ) {
		$s = self::state();
		if ( ! $s['installed'] ) {
			self::set_state( array( 'installed' => $existing ? time() - 3 * DAY_IN_SECONDS : time() ) );
		}
	}

	/**
	 * Whether the review request is waiting for an answer: never asked, or "Maybe later" has passed.
	 *
	 * @param array<string,mixed> $s   State.
	 * @param int                 $now Timestamp.
	 * @return bool
	 */
	public static function review_waiting( $s, $now ) {
		return '' === $s['review'] || ( 'later' === $s['review'] && $now >= $s['review_until'] );
	}

	/**
	 * The Black Friday 2026 window as timestamps (New York time).
	 *
	 * @return array{0:int,1:int} Start and end.
	 */
	public static function bf_window() {
		$tz     = new \DateTimeZone( 'America/New_York' );
		$window = array(
			( new \DateTimeImmutable( self::BF_START, $tz ) )->getTimestamp(),
			( new \DateTimeImmutable( self::BF_END, $tz ) )->getTimestamp(),
		);
		/**
		 * Filter the Black Friday notice window (for testing).
		 *
		 * @param array{0:int,1:int} $window Start and end timestamps.
		 */
		$window = apply_filters( 'scrumptious_bf_window', $window );
		return array( (int) $window[0], (int) $window[1] );
	}

	/**
	 * Whether the Black Friday deal is on.
	 *
	 * @param int $now Timestamp.
	 * @return bool
	 */
	public static function bf_active( $now ) {
		list( $start, $end ) = self::bf_window();
		return $now >= $start && $now <= $end;
	}

	/**
	 * Which milestone notice is due: 'review', 'pro' or '' (never both).
	 *
	 * Review: at least 1 published post with a card, and installed at least 3 days.
	 * Pro: at least 5, 14 days after the review request was answered, and Pro not installed.
	 *
	 * @param int                 $cards Published posts with a recipe card.
	 * @param array<string,mixed> $s     State.
	 * @param int                 $now   Timestamp.
	 * @param bool                $pro   Whether Scrumptious Pro is installed.
	 * @return string
	 */
	public static function due( $cards, $s, $now, $pro ) {
		$s       = array_merge( array( 'installed' => 0 ), $s );
		$waiting = self::review_waiting( $s, $now );
		if ( $cards >= 1 && $waiting && $s['installed'] && $now - $s['installed'] >= 3 * DAY_IN_SECONDS ) {
			return 'review';
		}
		if ( ! $pro && $cards >= 5 && '' === $s['pro'] && $s['review_acted'] && $now - $s['review_acted'] >= 14 * DAY_IN_SECONDS && ! $waiting ) {
			return 'pro';
		}
		return '';
	}

	/**
	 * Whether the Black Friday notice shows for the current user.
	 *
	 * @param int $now Timestamp.
	 * @return bool
	 */
	public static function bf_due( $now ) {
		return self::show() && self::bf_active( $now ) && ! get_user_meta( get_current_user_id(), self::BF_META, true );
	}

	/**
	 * A nonce'd admin-post link for a notice button.
	 *
	 * @param string $notice review|pro|bf.
	 * @param string $act    go|later|never|dismiss.
	 * @return string
	 */
	public static function notice_link( $notice, $act ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=scrumptious_notice&notice=' . $notice . '&do=' . $act ), 'scrumptious_notice' );
	}

	/**
	 * Review links for the import screen's "Imported N recipes" box, or false when the
	 * review request isn't waiting.
	 *
	 * @return array<string,string>|false
	 */
	public static function import_review() {
		if ( ! current_user_can( 'manage_options' ) || ! self::review_waiting( self::state(), time() ) ) {
			return false;
		}
		// Raw URLs: these are set as links from JavaScript, so no HTML escaping (wp_nonce_url gives &amp;).
		return array(
			'go'    => html_entity_decode( self::notice_link( 'review', 'go' ), ENT_QUOTES ),
			'later' => html_entity_decode( self::notice_link( 'review', 'later' ), ENT_QUOTES ),
		);
	}

	/**
	 * The one admin notice: Black Friday (during the sale), else the review request, else Pro. Scrumptious screens and the Posts list only.
	 */
	public static function milestone_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! ( Post_Type::SLUG === $screen->post_type || false !== strpos( (string) $screen->id, 'scrumptious' ) || 'edit-post' === $screen->id ) || 'post' === $screen->base ) {
			return;
		}
		$now = time();
		// One notice at a time: Black Friday first, then the review request, then Pro.
		if ( self::bf_due( $now ) ) {
			echo '<div class="notice notice-info swp-milestone swp-bf"><p>';
			echo '<strong>' . esc_html__( 'Black Friday:', 'scrumptious' ) . '</strong> ';
			echo esc_html__( 'Scrumptious Pro is 50% off your first year, until 11:59 PM ET on Wednesday, Dec 2. There\'s also a Pro Lifetime deal, limited to 100 licenses.', 'scrumptious' );
			echo '</p><p>';
			echo '<a class="button button-primary" href="' . esc_url( self::notice_link( 'bf', 'go' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'See the deal', 'scrumptious' ) . '</a> ';
			echo '<a class="button-link" href="' . esc_url( self::notice_link( 'bf', 'dismiss' ) ) . '">' . esc_html__( 'Dismiss', 'scrumptious' ) . '</a>';
			echo '</p></div>';
			return;
		}
		$s = self::state();
		if ( ! $s['installed'] ) {
			self::mark_installed( false ); // Option was cleared: start counting now.
			return;
		}
		$cards = self::card_posts();
		$due   = self::due( $cards, $s, $now, defined( 'SCRUMPTIOUS_PRO_VERSION' ) );
		if ( '' === $due ) {
			return;
		}
		$link = array( __CLASS__, 'notice_link' );
		echo '<div class="notice notice-info swp-milestone"><p>';
		if ( 'review' === $due ) {
			echo esc_html(
				sprintf(
					/* translators: %d: number of published posts with a recipe card */
					_n( "You've published %d post with a Scrumptious recipe card. If it's saving you time, a quick review on WordPress.org helps other food bloggers find it.", "You've published %d posts with Scrumptious recipe cards. If it's saving you time, a quick review on WordPress.org helps other food bloggers find it.", $cards, 'scrumptious' ),
					$cards
				)
			);
			echo '</p><p>';
			echo '<a class="button button-primary" href="' . esc_url( $link( 'review', 'go' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Leave a review', 'scrumptious' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $link( 'review', 'later' ) ) . '">' . esc_html__( 'Maybe later', 'scrumptious' ) . '</a> ';
			echo '<a class="button-link" href="' . esc_url( $link( 'review', 'never' ) ) . '">' . esc_html__( "Don't ask again", 'scrumptious' ) . '</a>';
		} else {
			echo esc_html(
				sprintf(
					/* translators: %d: number of published posts with a recipe card */
					__( '%d recipe cards and counting. When you\'re ready for more, Pro adds import from a link, bulk AI, a meal planner and shopping lists.', 'scrumptious' ),
					$cards
				)
			);
			echo '</p><p>';
			echo '<a class="button button-primary" href="' . esc_url( $link( 'pro', 'go' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'See Pro', 'scrumptious' ) . '</a> ';
			echo '<a class="button-link" href="' . esc_url( $link( 'pro', 'never' ) ) . '">' . esc_html__( 'No thanks', 'scrumptious' ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * Admin-post handler: act on a milestone notice button, then go back (or out to wordpress.org / pricing).
	 */
	public static function notice_action() {
		check_admin_referer( 'scrumptious_notice' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'scrumptious' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked above.
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		$do     = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		// phpcs:enable
		$s   = self::state();
		$now = time();
		$out = '';
		if ( 'bf' === $notice ) {
			update_user_meta( get_current_user_id(), self::BF_META, 1 );
			if ( 'go' === $do ) {
				wp_redirect( self::url( 'notice-bf2026', self::BF_URL ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed scrumptiouswp.com URL.
				exit;
			}
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
			exit;
		}
		if ( 'review' === $notice ) {
			$s['review_acted'] = $now;
			if ( 'later' === $do ) {
				$s['review']       = 'later';
				$s['review_until'] = $now + 14 * DAY_IN_SECONDS;
			} else {
				$s['review'] = 'done';
				$out         = 'go' === $do ? self::REVIEW : '';
			}
		} elseif ( 'pro' === $notice ) {
			$s['pro'] = 'done';
			$out      = 'go' === $do ? self::url( 'notice-15' ) : '';
		}
		self::set_state( $s );
		if ( $out ) {
			wp_redirect( $out ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed wordpress.org / scrumptiouswp.com URLs.
			exit;
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
