<?php
/**
 * FolloLinkの追加機能（1.20）。
 *
 * - 期間限定リンク: どのボタンにも「いつから・いつまで」を付けられる。期間の外は出さず、終わりが近いと「あと3日」と出す
 * - 応援ボタン: OFUSE・Ko-fi・PayPal.me などの応援のページ（決まったサービスのURLだけ。文言は選ぶだけ）
 * - 有料リンク: 載せているリンク（審査済み）に、値段と種類を付けて「販売中」として商品のカードで出す
 * - LINE友だち追加: LINE公式アカウントの友だち追加のURL（lin.ee・line.me だけ）
 * - メールで最新情報を受け取る: 読者のメールアドレスを集める（確認メールで本人が確かめてから登録。
 *   発信者はマイページから一覧（CSV）を保存して、自分のメールの道具で送る。登録をやめるURLも一覧に入れる）
 * - 最新の投稿を自動で表示: YouTube（チャンネルのRSS）・note（RSS）・ブログ（載せているリンクのRSS）の新しい投稿を、
 *   3時間ごとに読み直して並べる（X・Instagram・TikTok は外から読める仕組みが無いので対象外）
 *
 * 設定は投稿メタ _snscs_link_extra に1つにまとめる。自由に書ける文は無い（URLは決まったサービスか、審査済みのリンクだけ）ので審査は要らない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Biolink_Extra {

	const META       = '_snscs_link_extra';
	const META_CACHE = '_snscs_link_feedcache';
	const META_YTID  = '_snscs_link_ytid';
	const FEED_HOOK  = 'snscs_bio_feed_refresh';
	const FEED_TTL   = 3 * HOUR_IN_SECONDS;
	const MAX_PAID   = 6;

	/** 読み直しのあいだに、読み込めなかった先があったか。 */
	private static $fetch_failed = false;

	public static function init() {
		add_action( 'admin_post_snscs_link_extra', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_snscs_bio_sub', array( __CLASS__, 'handle_subscribe' ) );
		add_action( 'admin_post_nopriv_snscs_bio_sub', array( __CLASS__, 'handle_subscribe' ) );
		add_action( 'admin_post_snscs_bio_unsub', array( __CLASS__, 'handle_unsubscribe' ) );
		add_action( 'admin_post_nopriv_snscs_bio_unsub', array( __CLASS__, 'handle_unsubscribe' ) );
		add_action( 'admin_post_snscs_bio_subs_csv', array( __CLASS__, 'handle_csv' ) );
		add_action( 'init', array( __CLASS__, 'handle_links' ), 2 );
		add_action( self::FEED_HOOK, array( __CLASS__, 'refresh_feed' ) );
		add_action( SNSCS_Scores::CRON_HOOK, array( __CLASS__, 'purge_pending' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_creator' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_bio_subs';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  creator_id bigint(20) unsigned NOT NULL,
  email varchar(190) NOT NULL,
  token char(32) NOT NULL,
  status tinyint(1) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  confirmed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY creator_email (creator_id,email),
  KEY token (token)
) {$charset};"
		);
	}

	/* --------------------------------------------------------------------
	 * 選択肢
	 * ------------------------------------------------------------------ */

	/**
	 * 応援のサービス => [表示名, 使えるホスト（. で始まるものはそのサブドメイン）, パスの決まり（正規表現。空なら何でも）]
	 */
	public static function tip_services() {
		return apply_filters(
			'snscs_tip_services',
			array(
				'ofuse'    => array( 'OFUSE', array( 'ofuse.me' ), '' ),
				'kofi'     => array( 'Ko-fi', array( 'ko-fi.com' ), '' ),
				'bmc'      => array( 'Buy Me a Coffee', array( 'buymeacoffee.com', 'www.buymeacoffee.com' ), '' ),
				'paypal'   => array( 'PayPal.me', array( 'paypal.me', 'www.paypal.me', 'www.paypal.com' ), '#^/(paypalme/)?[A-Za-z0-9_.-]+#' ),
				'fanbox'   => array( 'pixivFANBOX', array( '.fanbox.cc' ), '' ),
				'patreon'  => array( 'Patreon', array( 'patreon.com', 'www.patreon.com' ), '' ),
				'stripe'   => array( 'Stripe', array( 'buy.stripe.com', 'donate.stripe.com' ), '' ),
				'wishlist' => array( 'Amazon ほしい物リスト', array( 'www.amazon.co.jp', 'amazon.co.jp' ), '#/(hz/)?wishlist/#' ),
			)
		);
	}

	/** 応援ボタンの文言 => [日本語, 英語] */
	public static function tip_labels() {
		return array(
			'support' => array( '応援する', 'Support me' ),
			'tip'     => array( '投げ銭で応援する', 'Send a tip' ),
			'coffee'  => array( 'コーヒーをおごる', 'Buy me a coffee' ),
			'wish'    => array( 'ほしい物リストを見る', 'See my wishlist' ),
			'member'  => array( 'メンバーになる', 'Become a member' ),
		);
	}

	/** 有料リンクの種類 => [日本語, 英語] */
	public static function paid_kinds() {
		return array(
			'article' => array( '有料記事', 'Paid post' ),
			'course'  => array( '講座', 'Course' ),
			'product' => array( '商品', 'Product' ),
			'service' => array( '相談・サービス', 'Service' ),
			'member'  => array( 'メンバーシップ', 'Membership' ),
			'ticket'  => array( 'チケット', 'Ticket' ),
		);
	}

	public static function defaults() {
		return array(
			'tip'   => array( 'svc' => '', 'url' => '', 'label' => 'support' ),
			'line'  => '',
			'mail'  => 0,
			'paid'  => array(),
			'feeds' => array( 'yt' => 0, 'note' => 0, 'blog' => '', 'count' => 3 ),
			'sched' => array(),
		);
	}

	public static function get( $creator_id ) {
		$saved = get_post_meta( $creator_id, self::META, true );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = self::defaults();
		foreach ( $out as $key => $value ) {
			if ( isset( $saved[ $key ] ) ) {
				$out[ $key ] = is_array( $value ) && is_array( $saved[ $key ] ) ? array_merge( $value, $saved[ $key ] ) : $saved[ $key ];
			}
		}
		return $out;
	}

	/**
	 * URLのホストが、決まったホストのどれかに合うか。
	 */
	public static function host_ok( $url, array $hosts, $path_rule = '' ) {
		$url = esc_url_raw( trim( (string) $url ), array( 'https', 'http' ) );
		if ( '' === $url ) {
			return '';
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$ok   = false;
		foreach ( $hosts as $h ) {
			if ( '.' === $h[0] ? substr( $host, -strlen( $h ) ) === $h && strlen( $host ) > strlen( $h ) : $host === $h ) {
				$ok = true;
				break;
			}
		}
		if ( ! $ok || ( '' !== $path_rule && ! preg_match( $path_rule, (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) ) {
			return '';
		}
		return 0 === strpos( $url, 'http://' ) ? 'https://' . substr( $url, 7 ) : $url;
	}

	public static function line_url( $url ) {
		$u = self::host_ok( $url, array( 'lin.ee', 'page.line.me' ) );
		return '' !== $u ? $u : self::host_ok( $url, array( 'line.me' ), '#^/(R/)?ti/p/#' );
	}

	/* --------------------------------------------------------------------
	 * 期間
	 * ------------------------------------------------------------------ */

	/**
	 * いまの状態: 'on'（出す）・'soon'（まだ）・'over'（終わった）。
	 */
	public static function window_state( array $extra, $key, $now = null ) {
		$now = null === $now ? time() : $now;
		$w   = $extra['sched'][ $key ] ?? null;
		if ( ! $w ) {
			return 'on';
		}
		if ( ! empty( $w['from'] ) && $now < (int) $w['from'] ) {
			return 'soon';
		}
		if ( ! empty( $w['to'] ) && $now >= (int) $w['to'] ) {
			return 'over';
		}
		return 'on';
	}

	public static function visible( array $extra, $key ) {
		return 'on' === self::window_state( $extra, $key );
	}

	/**
	 * 終わりが近いときの短い札（「あと3日」「今日まで」）。7日より先なら空。
	 */
	public static function ends_label( array $extra, $key, $english = false ) {
		$to = (int) ( $extra['sched'][ $key ]['to'] ?? 0 );
		if ( ! $to || $to <= time() ) {
			return '';
		}
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$end   = ( new DateTimeImmutable( '@' . ( $to - 1 ) ) )->setTimezone( $tz )->setTime( 0, 0 );
		$days  = (int) $today->diff( $end )->format( '%r%a' );
		if ( $days > 7 ) {
			return '';
		}
		if ( $days <= 0 ) {
			return $english ? 'Ends today' : '今日まで';
		}
		return $english ? sprintf( '%d days left', $days ) : sprintf( 'あと%d日', $days );
	}

	private static function parse_local( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $value, wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}

	private static function local( $ts ) {
		return $ts ? wp_date( 'Y-m-d\TH:i', (int) $ts ) : '';
	}

	/* --------------------------------------------------------------------
	 * 保存（マイページ）
	 * ------------------------------------------------------------------ */

	/**
	 * 期間を付けられるもの（並んでいるボタンと、追加のボタン）。キー => 表示名
	 */
	public static function schedulable( $creator_id, ?array $extra = null ) {
		$extra = null === $extra ? self::get( $creator_id ) : $extra;
		$out   = array();
		foreach ( SNSCS_Biolink::buttons( $creator_id ) as $b ) {
			$out[ $b['key'] ] = $b['label'] . ( '' !== $b['sub'] ? '（' . $b['sub'] . '）' : '' );
		}
		if ( '' !== $extra['tip']['url'] ) {
			$out['tip'] = '応援ボタン';
		}
		if ( '' !== $extra['line'] ) {
			$out['line'] = 'LINE友だち追加';
		}
		if ( $extra['mail'] ) {
			$out['mail'] = 'メールで最新情報を受け取る';
		}
		return $out;
	}

	public static function handle_save() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 下で確かめる。
		if ( ! is_user_logged_in() || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_link_extra' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$creator_id = SNSCS_Submissions::creator_id_for_user( get_current_user_id() );
		$back       = function ( $notice ) {
			wp_safe_redirect( add_query_arg( 'snscs_notice', $notice, snscs_portal_url() ) . '#snscs-bioextra' );
			exit;
		};
		if ( ! $creator_id ) {
			$back( 'link_extra_saved' );
		}
		$post  = function ( $key ) {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		};
		$extra = self::defaults();
		$prev  = self::get( $creator_id );
		$bad   = false;

		// 応援ボタン。
		$svc      = sanitize_key( $post( 'tip_svc' ) );
		$services = self::tip_services();
		$tip_url  = isset( $_POST['tip_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['tip_url'] ) ) ) : '';
		if ( isset( $services[ $svc ] ) && '' !== $tip_url ) {
			$ok = self::host_ok( $tip_url, $services[ $svc ][1], $services[ $svc ][2] );
			if ( '' === $ok ) {
				// 使えないURLのときは、前の設定のままにする。
				$bad          = true;
				$extra['tip'] = $prev['tip'];
			} else {
				$label        = sanitize_key( $post( 'tip_label' ) );
				$extra['tip'] = array( 'svc' => $svc, 'url' => $ok, 'label' => array_key_exists( $label, self::tip_labels() ) ? $label : 'support' );
			}
		}

		// LINE。
		$line = isset( $_POST['line_url'] ) ? trim( esc_url_raw( wp_unslash( $_POST['line_url'] ) ) ) : '';
		if ( '' !== $line ) {
			$extra['line'] = self::line_url( $line );
			if ( '' === $extra['line'] ) {
				$bad           = true;
				$extra['line'] = $prev['line'];
			}
		}

		$extra['mail'] = empty( $_POST['mail_on'] ) ? 0 : 1;

		// 有料リンク（載せているリンクだけ）。
		$keys  = wp_list_pluck( SNSCS_Biolink::buttons( $creator_id ), 'key' );
		$kinds = self::paid_kinds();
		$paid  = isset( $_POST['paid'] ) && is_array( $_POST['paid'] ) ? wp_unslash( $_POST['paid'] ) : array();
		foreach ( array_slice( $paid, 0, self::MAX_PAID ) as $row ) {
			$key   = isset( $row['key'] ) ? sanitize_text_field( $row['key'] ) : '';
			$price = isset( $row['price'] ) ? (int) preg_replace( '/[^0-9]/', '', (string) $row['price'] ) : 0;
			$kind  = isset( $row['kind'] ) ? sanitize_key( $row['kind'] ) : '';
			if ( in_array( $key, $keys, true ) && ! isset( $extra['paid'][ $key ] ) ) {
				$extra['paid'][ $key ] = array( 'price' => min( 9999999, max( 0, $price ) ), 'kind' => isset( $kinds[ $kind ] ) ? $kind : 'product' );
			}
		}

		// 最新の投稿。
		$links                 = SNSCS_Submissions::links_for( $creator_id );
		$blog                  = $post( 'feed_blog' );
		$extra['feeds']['yt']   = empty( $_POST['feed_yt'] ) ? 0 : 1;
		$extra['feeds']['note'] = empty( $_POST['feed_note'] ) ? 0 : 1;
		$extra['feeds']['blog'] = preg_match( '/^link:(\d+)$/', $blog, $m ) && isset( $links[ (int) $m[1] ] ) ? $blog : '';
		$extra['feeds']['count'] = min( 6, max( 1, absint( $post( 'feed_count' ) ) ) );

		// 期間。
		$allowed = self::schedulable( $creator_id, $extra );
		$sched   = isset( $_POST['sched'] ) && is_array( $_POST['sched'] ) ? wp_unslash( $_POST['sched'] ) : array();
		foreach ( $sched as $key => $w ) {
			$key = sanitize_text_field( (string) $key );
			if ( ! isset( $allowed[ $key ] ) || ! is_array( $w ) ) {
				continue;
			}
			$from = self::parse_local( $w['from'] ?? '' );
			$to   = self::parse_local( $w['to'] ?? '' );
			if ( $from && $to && $to <= $from ) {
				$bad = true;
				continue;
			}
			if ( $from || $to ) {
				$extra['sched'][ $key ] = array( 'from' => $from, 'to' => $to );
			}
		}
		// phpcs:enable

		$before = $prev['feeds'];
		update_post_meta( $creator_id, self::META, $extra );
		if ( $before != $extra['feeds'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- 中身が同じかだけを見る。
			self::refresh_feed( $creator_id );
		}
		$back( $bad ? 'link_extra_partly' : 'link_extra_saved' );
	}

	/* --------------------------------------------------------------------
	 * メールで最新情報を受け取る
	 * ------------------------------------------------------------------ */

	public static function subscriber_count( $creator_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE creator_id = %d AND status = 1', $creator_id ) );
	}

	private static function back_to_bio( $creator_id, $code ) {
		wp_safe_redirect( add_query_arg( 'snscs_sub', $code, SNSCS_Biolink::url( $creator_id ) ) . '#bio-mail' );
		exit;
	}

	public static function handle_subscribe() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 合言葉（snscs_form_token）で確認する。
		$creator_id = isset( $_POST['creator'] ) ? absint( $_POST['creator'] ) : 0;
		if ( ! $creator_id || 'publish' !== get_post_status( $creator_id ) || SNSCS_Post_Types::POST_TYPE !== get_post_type( $creator_id ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$extra = self::get( $creator_id );
		if ( ! $extra['mail'] || ! self::visible( $extra, 'mail' ) ) {
			self::back_to_bio( $creator_id, 'invalid' );
		}
		if ( ! empty( $_POST['website'] ) || ! snscs_check_form_token( isset( $_POST['snscs_form'] ) ? sanitize_text_field( wp_unslash( $_POST['snscs_form'] ) ) : '', 2 ) ) {
			self::back_to_bio( $creator_id, 'expired' );
		}
		$email = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : '';
		if ( ! is_email( $email ) || empty( $_POST['agree'] ) || strlen( $email ) > 190 ) {
			self::back_to_bio( $creator_id, 'invalid' );
		}
		// phpcs:enable
		if ( snscs_rate_limited( 'bio-sub', 5 ) || snscs_rate_limited( 'bio-sub-mail-' . md5( $email ), 3, DAY_IN_SECONDS ) ) {
			self::back_to_bio( $creator_id, 'limit' );
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, token FROM {$table} WHERE creator_id = %d AND email = %s", $creator_id, $email ), ARRAY_A );
		if ( $row && 1 === (int) $row['status'] ) {
			// 登録済みでも、同じ表示にする（ほかの人がアドレスの登録の有無を調べられないように）。
			self::back_to_bio( $creator_id, 'check' );
		}
		$token = $row ? $row['token'] : bin2hex( random_bytes( 16 ) );
		if ( ! $row ) {
			$wpdb->insert( $table, array( 'creator_id' => $creator_id, 'email' => $email, 'token' => $token, 'status' => 0, 'created_at' => current_time( 'mysql', true ) ) );
		}
		self::send_confirm( $creator_id, $email, $token );
		self::back_to_bio( $creator_id, 'check' );
	}

	public static function confirm_url( $token ) {
		return add_query_arg( 'snscs_bio_confirm', rawurlencode( $token ), home_url( '/' ) );
	}

	public static function unsub_url( $token ) {
		return add_query_arg( 'snscs_bio_unsub', rawurlencode( $token ), home_url( '/' ) );
	}

	private static function send_confirm( $creator_id, $email, $token ) {
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$name    = get_the_title( $creator_id );
		$op      = snscs_operator_info();
		$body    = array(
			$name . 'さんの最新情報をメールで受け取る登録を受け付けました。',
			'下のURLを開くと、登録が完了します（7日以内）。',
			'',
			self::confirm_url( $token ),
			'',
			'登録すると、このメールアドレスが ' . $name . ' さんに知らされ、' . $name . ' さんからお知らせのメールが届きます。',
			'心当たりがない場合は、このメールを無視してください（登録されません）。',
			'',
			'登録をやめるとき: ' . self::unsub_url( $token ),
			'',
			'---',
			$op['operator'] . '（' . $site . '）',
			home_url( '/' ),
			'お問い合わせ: ' . $op['email'],
		);
		wp_mail( $email, '[' . $site . '] ' . $name . 'さんのお知らせの登録を確認してください', implode( "\n", $body ) );
	}

	/**
	 * 確認のURL・登録をやめるURLを開いたとき。
	 */
	public static function handle_links() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 合言葉はURLのトークン。
		$confirm = isset( $_GET['snscs_bio_confirm'] ) ? sanitize_key( wp_unslash( $_GET['snscs_bio_confirm'] ) ) : '';
		$unsub   = isset( $_GET['snscs_bio_unsub'] ) ? sanitize_key( wp_unslash( $_GET['snscs_bio_unsub'] ) ) : '';
		// phpcs:enable
		$token = '' !== $confirm ? $confirm : $unsub;
		if ( '' === $token ) {
			return;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = 32 === strlen( $token ) ? $wpdb->get_row( $wpdb->prepare( "SELECT id, creator_id, status FROM {$table} WHERE token = %s", $token ), ARRAY_A ) : null;
		if ( '' !== $confirm ) {
			if ( ! $row ) {
				wp_die( 'このURLは使えません。登録の期限（7日）が過ぎたか、登録をやめた可能性があります。もう一度登録してください。', '登録の確認', array( 'response' => 404 ) );
			}
			if ( 1 !== (int) $row['status'] ) {
				$wpdb->update( $table, array( 'status' => 1, 'confirmed_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $row['id'] ) );
			}
			self::back_to_bio( (int) $row['creator_id'], 'ok' );
		}
		// 登録をやめる: メールのソフトがURLを先に開いても消えないよう、ボタンを押してから消す。
		nocache_headers();
		if ( ! $row ) {
			wp_die( '登録はすでにありません。', '登録をやめる', array( 'response' => 200 ) );
		}
		$name = get_the_title( (int) $row['creator_id'] );
		$form = '<p>' . esc_html( $name ) . 'さんのお知らせのメールの登録をやめますか？</p><form method="post" action="' . esc_url( snscs_post_url() ) . '"><input type="hidden" name="action" value="snscs_bio_unsub"><input type="hidden" name="token" value="' . esc_attr( $token ) . '"><p><button type="submit" class="button button-primary">登録をやめる</button></p></form>';
		wp_die( $form, '登録をやめる', array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 上でエスケープ済み。
	}

	public static function handle_unsubscribe() {
		global $wpdb;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 合言葉はトークン。
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$creator = 32 === strlen( $token ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT creator_id FROM {$table} WHERE token = %s", $token ) ) : 0;
		if ( $creator ) {
			$wpdb->delete( $table, array( 'token' => $token ) );
			self::back_to_bio( $creator, 'bye' );
		}
		wp_die( '登録はすでにありません。', '登録をやめる', array( 'response' => 200 ) );
	}

	/**
	 * 発信者が、登録した人の一覧（CSV）を保存する。
	 */
	public static function handle_csv() {
		global $wpdb;
		if ( ! is_user_logged_in() || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'snscs_bio_subs_csv' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$creator_id = SNSCS_Submissions::creator_id_for_user( get_current_user_id() );
		if ( ! $creator_id ) {
			wp_die( 'FolloLinkがありません。' );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT email, token, confirmed_at FROM ' . self::table() . ' WHERE creator_id = %d AND status = 1 ORDER BY confirmed_at', $creator_id ), ARRAY_A );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mylink-subscribers-' . gmdate( 'Ymd' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // Excel で文字化けしないように。
		fputcsv( $out, array( 'メールアドレス', '登録日時', '登録をやめるURL' ) );
		foreach ( $rows as $row ) {
			fputcsv( $out, array( $row['email'], get_date_from_gmt( $row['confirmed_at'], 'Y-m-d H:i' ), self::unsub_url( $row['token'] ) ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	public static function purge_pending() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE status = 0 AND created_at < %s', gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
	}

	public static function delete_for_creator( $post_id ) {
		global $wpdb;
		if ( SNSCS_Post_Types::POST_TYPE === get_post_type( $post_id ) ) {
			$wpdb->delete( self::table(), array( 'creator_id' => (int) $post_id ) );
		}
	}

	/* --------------------------------------------------------------------
	 * 最新の投稿
	 * ------------------------------------------------------------------ */

	/**
	 * 使える読み込み先（いまの掲載内容から）。source => [表示名, 説明]
	 */
	public static function feed_sources( $creator_id ) {
		$out = array();
		foreach ( SNSCS_Accounts::get_for_creator( $creator_id ) as $a ) {
			if ( 'youtube' === $a['platform'] ) {
				$out['yt'] = array( 'YouTube', '@' . ltrim( $a['handle'], '@' ) . ' の新しい動画' );
				break;
			}
		}
		$stores = SNSCS_Submissions::stores_for( $creator_id );
		if ( isset( $stores['note'] ) ) {
			$out['note'] = array( 'note', 'note.com/' . $stores['note'] . ' の新しい記事' );
		}
		return $out;
	}

	/**
	 * 表示する投稿（読み込み済みのもの）。古ければ、裏で読み直しを予約する。
	 *
	 * @return array<int,array{src:string,title:string,url:string,thumb:string,date:int}>
	 */
	public static function feed_items( $creator_id ) {
		$extra = self::get( $creator_id );
		$f     = $extra['feeds'];
		if ( ! $f['yt'] && ! $f['note'] && '' === $f['blog'] ) {
			return array();
		}
		$cache = get_post_meta( $creator_id, self::META_CACHE, true );
		if ( ! is_array( $cache ) || (int) ( $cache['at'] ?? 0 ) < time() - self::FEED_TTL ) {
			if ( ! wp_next_scheduled( self::FEED_HOOK, array( (int) $creator_id ) ) ) {
				wp_schedule_single_event( time(), self::FEED_HOOK, array( (int) $creator_id ) );
			}
		}
		$items = is_array( $cache ) && isset( $cache['items'] ) ? (array) $cache['items'] : array();
		return array_slice( $items, 0, (int) $f['count'] );
	}

	/**
	 * 読み直して保存する。
	 */
	public static function refresh_feed( $creator_id ) {
		$creator_id         = (int) $creator_id;
		$f                  = self::get( $creator_id )['feeds'];
		self::$fetch_failed = false;
		$items      = array();
		$sources    = self::feed_sources( $creator_id );
		if ( $f['yt'] && isset( $sources['yt'] ) ) {
			$id = self::youtube_id( $creator_id );
			if ( '' !== $id ) {
				$items = array_merge( $items, self::parse_feed( self::fetch( 'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode( $id ) ), 'yt', array( 'www.youtube.com', 'youtube.com', 'youtu.be' ) ) );
			}
		}
		if ( $f['note'] && isset( $sources['note'] ) ) {
			$handle = SNSCS_Submissions::stores_for( $creator_id )['note'];
			$items  = array_merge( $items, self::parse_feed( self::fetch( 'https://note.com/' . rawurlencode( $handle ) . '/rss' ), 'note', array( 'note.com' ) ) );
		}
		if ( preg_match( '/^link:(\d+)$/', $f['blog'], $m ) ) {
			$links = SNSCS_Submissions::links_for( $creator_id );
			if ( isset( $links[ (int) $m[1] ] ) ) {
				$page = $links[ (int) $m[1] ]['url'];
				$host = strtolower( (string) wp_parse_url( $page, PHP_URL_HOST ) );
				$feed = self::discover( $page );
				if ( '' !== $feed ) {
					$items = array_merge( $items, self::parse_feed( self::fetch( $feed ), 'blog', array( $host, preg_replace( '/^www\./', '', $host ) ) ) );
				}
			}
		}
		usort(
			$items,
			function ( $a, $b ) {
				return $b['date'] <=> $a['date'];
			}
		);
		// 一時的に読み込めなかったときは、前に読んだ投稿を出し続ける。
		$prev = get_post_meta( $creator_id, self::META_CACHE, true );
		if ( ! $items && self::$fetch_failed && is_array( $prev ) && ! empty( $prev['items'] ) ) {
			$items = (array) $prev['items'];
		}
		update_post_meta( $creator_id, self::META_CACHE, array( 'at' => time(), 'items' => array_slice( $items, 0, 12 ) ) );
		return $items;
	}

	private static function fetch( $url ) {
		$res = wp_safe_remote_get( $url, array( 'timeout' => 8, 'redirection' => 3, 'limit_response_size' => 1024 * 1024, 'user-agent' => 'Mozilla/5.0 (compatible; ' . SNSCS_Brand::NAME . '/' . SNSCS_VERSION . '; +' . home_url( '/' ) . ')' ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			self::$fetch_failed = true;
			return '';
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	/**
	 * YouTube のチャンネルID（登録者数の取得で分かっていればそれ。無ければチャンネルのページから調べて覚える）。
	 */
	public static function youtube_id( $creator_id ) {
		$handle = '';
		foreach ( SNSCS_Accounts::get_for_creator( $creator_id ) as $a ) {
			if ( 'youtube' === $a['platform'] ) {
				if ( ! empty( $a['external_id'] ) && preg_match( '/^UC[A-Za-z0-9_-]{22}$/', $a['external_id'] ) ) {
					return $a['external_id'];
				}
				$handle = ltrim( $a['handle'], '@' );
				break;
			}
		}
		if ( '' === $handle ) {
			return '';
		}
		if ( preg_match( '/^UC[A-Za-z0-9_-]{22}$/', $handle ) ) {
			return $handle;
		}
		$saved = get_post_meta( $creator_id, self::META_YTID, true );
		// 見つからなかったときは、1日たってから調べ直す。
		if ( is_array( $saved ) && ( $saved['handle'] ?? '' ) === $handle && ( '' !== ( $saved['id'] ?? '' ) || (int) ( $saved['at'] ?? 0 ) > time() - DAY_IN_SECONDS ) ) {
			return (string) $saved['id'];
		}
		$id   = '';
		$html = self::fetch( 'https://www.youtube.com/@' . rawurlencode( $handle ) );
		if ( preg_match( '#<link rel="canonical" href="https://www\.youtube\.com/channel/(UC[A-Za-z0-9_-]{22})"#', $html, $m ) || preg_match( '#"(?:externalId|channelId)":"(UC[A-Za-z0-9_-]{22})"#', $html, $m ) ) {
			$id = $m[1];
		}
		if ( '' !== $html ) {
			// 読み込めなかった（通信の失敗）ときは覚えずに、次の読み直しで調べ直す。
			update_post_meta( $creator_id, self::META_YTID, array( 'handle' => $handle, 'id' => $id, 'at' => time() ) );
		}
		return $id;
	}

	/**
	 * ページのRSSを探す（<link rel="alternate">。無ければ /feed）。
	 */
	private static function discover( $page ) {
		$html = self::fetch( $page );
		if ( '' === $html ) {
			return '';
		}
		if ( preg_match( '/^\s*<\?xml|<rss[\s>]|<feed[\s>]/i', substr( $html, 0, 500 ) ) ) {
			return $page;
		}
		if ( preg_match_all( '#<link\b[^>]*>#i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '#type=["\']application/(rss|atom)\+xml["\']#i', $tag ) && preg_match( '#href=["\']([^"\']+)["\']#i', $tag, $h ) ) {
					$url = WP_Http::make_absolute_url( html_entity_decode( $h[1] ), $page );
					// 同じサイトのRSSだけ。
					if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( $page, PHP_URL_HOST ) ) ) {
						return $url;
					}
				}
			}
		}
		return trailingslashit( $page ) . 'feed/';
	}

	/**
	 * RSS 2.0・Atom を読む。リンクは決まったホストのものだけ使う。
	 */
	public static function parse_feed( $xml, $src, array $hosts ) {
		if ( '' === trim( (string) $xml ) || ! function_exists( 'simplexml_load_string' ) ) {
			return array();
		}
		$prev = libxml_use_internal_errors( true );
		$doc  = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $doc ) {
			return array();
		}
		$entries = isset( $doc->channel->item ) ? $doc->channel->item : ( isset( $doc->entry ) ? $doc->entry : array() );
		$out     = array();
		foreach ( $entries as $e ) {
			$title = trim( wp_strip_all_tags( (string) $e->title ) );
			$link  = (string) $e->link;
			if ( '' === $link && isset( $e->link ) ) {
				foreach ( $e->link as $l ) {
					$rel = (string) $l['rel'];
					if ( '' === $rel || 'alternate' === $rel ) {
						$link = (string) $l['href'];
						break;
					}
				}
			}
			$link = esc_url_raw( trim( $link ), array( 'https', 'http' ) );
			$host = strtolower( (string) wp_parse_url( $link, PHP_URL_HOST ) );
			$ok   = false;
			foreach ( $hosts as $h ) {
				if ( '' !== $h && ( $host === $h || substr( $host, -strlen( '.' . $h ) ) === '.' . $h ) ) {
					$ok = true;
				}
			}
			if ( '' === $title || ! $ok ) {
				continue;
			}
			$date  = strtotime( (string) ( $e->pubDate ?: $e->published ?: $e->updated ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$thumb = '';
			$yt    = $e->children( 'http://www.youtube.com/xml/schemas/2015' );
			if ( 'yt' === $src && isset( $yt->videoId ) && preg_match( '/^[A-Za-z0-9_-]{11}$/', (string) $yt->videoId ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$thumb = 'https://i.ytimg.com/vi/' . $yt->videoId . '/mqdefault.jpg'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			} else {
				$media = $e->children( 'http://search.yahoo.com/mrss/' );
				if ( isset( $media->thumbnail ) ) {
					$thumb = '' !== (string) $media->thumbnail['url'] ? (string) $media->thumbnail['url'] : trim( (string) $media->thumbnail );
				} elseif ( isset( $media->content ) && '' !== (string) $media->content['url'] ) {
					$thumb = (string) $media->content['url'];
				} elseif ( isset( $e->enclosure ) && 0 === strpos( (string) $e->enclosure['type'], 'image/' ) ) {
					$thumb = (string) $e->enclosure['url'];
				}
				$thumb = 0 === strpos( $thumb, 'https://' ) ? esc_url_raw( $thumb ) : '';
			}
			$out[] = array( 'src' => $src, 'title' => mb_substr( $title, 0, 80 ), 'url' => $link, 'thumb' => $thumb, 'date' => $date ? $date : 0 );
			if ( count( $out ) >= 6 ) {
				break;
			}
		}
		return $out;
	}

	/* --------------------------------------------------------------------
	 * FolloLinkのページ
	 * ------------------------------------------------------------------ */

	/**
	 * 期間の外のボタンを外し、終わりが近いものに札を付け、有料リンクを分ける。
	 *
	 * @return array{buttons:array,paid:array}
	 */
	public static function arrange( $creator_id, array $buttons, $english = false ) {
		$extra = self::get( $creator_id );
		$paid  = array();
		$kinds = self::paid_kinds();
		foreach ( $buttons as $i => $b ) {
			if ( ! self::visible( $extra, $b['key'] ) ) {
				unset( $buttons[ $i ] );
				continue;
			}
			$buttons[ $i ]['ends'] = self::ends_label( $extra, $b['key'], $english );
			if ( isset( $extra['paid'][ $b['key'] ] ) ) {
				$p      = $extra['paid'][ $b['key'] ];
				$paid[] = array_merge(
					$buttons[ $i ],
					array(
						'price' => (int) $p['price'],
						'kind'  => $kinds[ $p['kind'] ][ $english ? 1 : 0 ],
					)
				);
				unset( $buttons[ $i ] );
			}
		}
		return array( 'buttons' => array_values( $buttons ), 'paid' => $paid );
	}

	/**
	 * 一覧の上に足すボタン（応援・LINE）。
	 *
	 * @return array<int,array{key:string,label:string,sub:string,url:string,kind:string,ends:string}>
	 */
	public static function extra_buttons( $creator_id, $english = false ) {
		$extra = self::get( $creator_id );
		$out   = array();
		$svcs  = self::tip_services();
		if ( '' !== $extra['tip']['url'] && isset( $svcs[ $extra['tip']['svc'] ] ) && self::visible( $extra, 'tip' ) ) {
			$labels = self::tip_labels();
			$out[]  = array( 'key' => 'tip', 'label' => $labels[ $extra['tip']['label'] ][ $english ? 1 : 0 ], 'sub' => $svcs[ $extra['tip']['svc'] ][0], 'url' => $extra['tip']['url'], 'kind' => 'tip', 'ends' => self::ends_label( $extra, 'tip', $english ) );
		}
		if ( '' !== $extra['line'] && self::visible( $extra, 'line' ) ) {
			$out[] = array( 'key' => 'line', 'label' => $english ? 'Add on LINE' : 'LINEで友だち追加', 'sub' => $english ? 'Updates on LINE' : 'LINEで最新情報をお届けします', 'url' => $extra['line'], 'kind' => 'line', 'ends' => self::ends_label( $extra, 'line', $english ) );
		}
		return $out;
	}

	public static function mail_on( $creator_id ) {
		$extra = self::get( $creator_id );
		return $extra['mail'] && self::visible( $extra, 'mail' );
	}

	public static function price( $yen, $english = false ) {
		return $yen > 0 ? '¥' . number_format( $yen ) : ( $english ? 'Free' : '無料' );
	}

	/**
	 * クリックの記録のキーの名前。
	 */
	public static function key_label( $key ) {
		$map = array( 'tip' => '応援ボタン', 'line' => 'LINE友だち追加', 'feed:yt' => 'YouTubeの新しい動画', 'feed:note' => 'noteの新しい記事', 'feed:blog' => 'ブログの新しい記事' );
		return $map[ $key ] ?? '';
	}

	/* --------------------------------------------------------------------
	 * マイページ
	 * ------------------------------------------------------------------ */

	public static function render_portal( $creator_id ) {
		$extra    = self::get( $creator_id );
		$buttons  = SNSCS_Biolink::buttons( $creator_id );
		$services = self::tip_services();
		$sources  = self::feed_sources( $creator_id );
		$links    = SNSCS_Submissions::links_for( $creator_id );
		$subs     = self::subscriber_count( $creator_id );
		$cache    = get_post_meta( $creator_id, self::META_CACHE, true );
		$states   = array( 'on' => '表示中', 'soon' => '予約中', 'over' => '終了' );
		$paid     = array_values(
			array_map(
				function ( $key, $p ) {
					return array( 'key' => $key ) + $p;
				},
				array_keys( $extra['paid'] ),
				$extra['paid']
			)
		);
		$paid     = array_pad( $paid, min( self::MAX_PAID, max( 3, count( $paid ) + 1 ) ), array( 'key' => '', 'price' => '', 'kind' => 'product' ) );
		?>
		<div class="snscs-bioextra" id="snscs-bioextra">
			<h3 class="snscs-sharekit__heading">ボタンと機能を追加</h3>
			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
				<input type="hidden" name="action" value="snscs_link_extra">
				<?php wp_nonce_field( 'snscs_link_extra', 'snscs_nonce' ); ?>

				<fieldset class="snscs-bioed__group">
					<legend>💝 応援ボタン</legend>
					<p class="snscs-help">ファンがあなたを直接応援できるボタンを、一覧の上に目立つ色で出します。下のサービスのURLだけ使えます。</p>
					<div class="snscs-bioextra__row">
						<label>サービス
							<select name="tip_svc">
								<option value="">使わない</option>
								<?php foreach ( $services as $key => $svc ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $extra['tip']['svc'], $key ); ?>><?php echo esc_html( $svc[0] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label>ボタンの文字
							<select name="tip_label">
								<?php foreach ( self::tip_labels() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $extra['tip']['label'], $key ); ?>><?php echo esc_html( $label[0] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>
					<label class="snscs-bioextra__wide">応援のページのURL <input type="url" name="tip_url" value="<?php echo esc_attr( $extra['tip']['url'] ); ?>" placeholder="https://ofuse.me/あなたのID"></label>
				</fieldset>

				<fieldset class="snscs-bioed__group">
					<legend>🛒 有料リンク（販売中のコンテンツ）</legend>
					<p class="snscs-help">載せているリンクに値段を付けると、「販売中」の欄に商品のカードで出します（買うのはリンク先のサービスです）。</p>
					<?php if ( $buttons ) : ?>
						<table class="snscs-bioextra__table">
							<thead><tr><th>リンク</th><th>種類</th><th>値段（円）</th></tr></thead>
							<tbody>
								<?php foreach ( $paid as $i => $row ) : ?>
									<tr>
										<td><select name="paid[<?php echo (int) $i; ?>][key]" aria-label="リンク"><option value="">選ばない</option><?php foreach ( $buttons as $b ) : ?><option value="<?php echo esc_attr( $b['key'] ); ?>" <?php selected( $row['key'], $b['key'] ); ?>><?php echo esc_html( $b['label'] . ( '' !== $b['sub'] ? '（' . $b['sub'] . '）' : '' ) ); ?></option><?php endforeach; ?></select></td>
										<td><select name="paid[<?php echo (int) $i; ?>][kind]" aria-label="種類"><?php foreach ( self::paid_kinds() as $key => $kind ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row['kind'], $key ); ?>><?php echo esc_html( $kind[0] ); ?></option><?php endforeach; ?></select></td>
										<td><input type="text" inputmode="numeric" name="paid[<?php echo (int) $i; ?>][price]" value="<?php echo esc_attr( '' === $row['price'] ? '' : (string) $row['price'] ); ?>" placeholder="980" aria-label="値段（円）" size="8"></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p class="snscs-help">商品のリンクを増やすときは、掲載内容のフォームの「その他のリンク」に足してください（審査のあとに選べます）。</p>
					<?php else : ?>
						<p class="snscs-help">まだリンクがありません。</p>
					<?php endif; ?>
				</fieldset>

				<fieldset class="snscs-bioed__group">
					<legend>📨 ファンとつながる</legend>
					<label class="snscs-bioextra__wide">LINE公式アカウントの友だち追加のURL <input type="url" name="line_url" value="<?php echo esc_attr( $extra['line'] ); ?>" placeholder="https://lin.ee/xxxxxxx"></label>
					<p class="snscs-help">LINE公式アカウントの管理画面の「友だち追加ガイド」にあるURL（lin.ee または line.me）です。</p>
					<label class="snscs-biolink__check"><input type="checkbox" name="mail_on" value="1" <?php checked( $extra['mail'] ); ?>> 「メールで最新情報を受け取る」の欄を出す</label>
					<p class="snscs-help">読者がメールアドレスを入れると、確認のメールが届き、本人が確かめてから登録されます。いま <strong><?php echo esc_html( number_format_i18n( $subs ) ); ?>人</strong> が登録しています。<?php if ( $subs ) : ?> <a href="<?php echo esc_url( add_query_arg( array( 'action' => 'snscs_bio_subs_csv', '_wpnonce' => wp_create_nonce( 'snscs_bio_subs_csv' ) ), snscs_post_url() ) ); ?>">一覧（CSV）を保存</a><?php endif; ?></p>
					<p class="snscs-help">一覧は、お使いのメールの道具（Gmail・メルマガのサービスなど）に読み込んで使ってください。メールには、あなたの名前と連絡先、一覧にある「登録をやめるURL」を必ず入れてください（特定電子メール法で決まっています）。</p>
				</fieldset>

				<fieldset class="snscs-bioed__group">
					<legend>🆕 最新の投稿を自動で表示</legend>
					<p class="snscs-help">新しい動画や記事を、3時間ごとに読み直して「最新の投稿」に並べます。更新しなくても、FolloLinkがいつも新しくなります。</p>
					<?php if ( isset( $sources['yt'] ) ) : ?>
						<label class="snscs-biolink__check"><input type="checkbox" name="feed_yt" value="1" <?php checked( $extra['feeds']['yt'] ); ?>> YouTube（<?php echo esc_html( $sources['yt'][1] ); ?>）</label>
					<?php endif; ?>
					<?php if ( isset( $sources['note'] ) ) : ?>
						<label class="snscs-biolink__check"><input type="checkbox" name="feed_note" value="1" <?php checked( $extra['feeds']['note'] ); ?>> note（<?php echo esc_html( $sources['note'][1] ); ?>）</label>
					<?php endif; ?>
					<label>ブログ
						<select name="feed_blog">
							<option value="">使わない</option>
							<?php foreach ( $links as $i => $link ) : ?>
								<option value="<?php echo esc_attr( 'link:' . $i ); ?>" <?php selected( $extra['feeds']['blog'], 'link:' . $i ); ?>><?php echo esc_html( $link['label'] . '（' . wp_parse_url( $link['url'], PHP_URL_HOST ) . '）' ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>表示する数
						<select name="feed_count">
							<?php for ( $n = 1; $n <= 6; $n++ ) : ?>
								<option value="<?php echo (int) $n; ?>" <?php selected( (int) $extra['feeds']['count'], $n ); ?>><?php echo (int) $n; ?>件</option>
							<?php endfor; ?>
						</select>
					</label>
					<p class="snscs-help">X・Instagram・TikTok は、外から投稿を読める仕組みが無いため使えません。<?php if ( is_array( $cache ) && ! empty( $cache['at'] ) ) : ?>最後に読み込んだ日時: <?php echo esc_html( wp_date( 'n月j日 H:i', (int) $cache['at'] ) . '（' . count( (array) $cache['items'] ) . '件）' ); ?><?php endif; ?></p>
				</fieldset>

				<fieldset class="snscs-bioed__group">
					<legend>⏰ 期間限定で出す</legend>
					<p class="snscs-help">「いつから・いつまで」を入れたボタンは、その期間だけ出ます。終わりの7日前からは「あと◯日」と出します。空ならずっと出します。</p>
					<table class="snscs-bioextra__table">
						<thead><tr><th>ボタン</th><th>いつから</th><th>いつまで</th><th>いま</th></tr></thead>
						<tbody>
							<?php foreach ( self::schedulable( $creator_id, $extra ) as $key => $label ) : ?>
								<?php $w = $extra['sched'][ $key ] ?? array(); ?>
								<tr>
									<td><?php echo esc_html( $label ); ?></td>
									<td><input type="datetime-local" name="sched[<?php echo esc_attr( $key ); ?>][from]" value="<?php echo esc_attr( self::local( $w['from'] ?? 0 ) ); ?>" aria-label="<?php echo esc_attr( $label . 'をいつから出すか' ); ?>"></td>
									<td><input type="datetime-local" name="sched[<?php echo esc_attr( $key ); ?>][to]" value="<?php echo esc_attr( self::local( $w['to'] ?? 0 ) ); ?>" aria-label="<?php echo esc_attr( $label . 'をいつまで出すか' ); ?>"></td>
									<td><span class="snscs-bioextra__state snscs-bioextra__state--<?php echo esc_attr( self::window_state( $extra, $key ) ); ?>"><?php echo esc_html( $states[ self::window_state( $extra, $key ) ] ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</fieldset>
				<p class="snscs-bioed__save"><button type="submit" class="snscs-button snscs-button--primary">保存</button></p>
			</form>
		</div>
		<?php
	}
}
