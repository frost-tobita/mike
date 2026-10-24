<?php
/**
 * ログイン状態の保持（1.25）。
 *
 * - 登録・ログインのとき「ログイン状態を保持する」を選べる（初めからチェック。共用の端末では外す）
 * - 保持を選んだ人: Yahoo! JAPAN のように、ログアウトするまでずっとログインしたまま。
 *   ログインの記録（クッキー）は1年もち、使っているあいだは月に1回ほど自動で延ばす
 * - 保持しない人: ブラウザを閉じるとログアウト（WordPress の標準どおり）
 * - ログイン中は、ページの上に目立たない「○○さん」と、右の列に「ログイン中」を出す
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Session {

	const KEEP_META = 'snscs_keep_login';
	const LIFETIME  = YEAR_IN_SECONDS;
	// 残りがこれより短くなったら延ばす（= 前回から約1か月）。
	const REFRESH = 335 * DAY_IN_SECONDS;

	public static function init() {
		add_filter( 'auth_cookie_expiration', array( __CLASS__, 'expiration' ), 20, 3 );
		add_action( 'set_auth_cookie', array( __CLASS__, 'remember_choice' ), 10, 4 );
		add_action( 'init', array( __CLASS__, 'extend' ), 20 );
		add_filter( 'login_form_defaults', array( __CLASS__, 'login_form_defaults' ) );
	}

	/**
	 * 「保持する」を選んだログインは1年もつようにする。
	 */
	public static function expiration( $length, $user_id, $remember ) {
		return $remember ? self::LIFETIME : $length;
	}

	/**
	 * ログインのたびに、保持を選んだかどうかを覚える（あとで自動で延ばすかを決めるため）。
	 * $expire が 0 = ブラウザを閉じるまで（保持しない）。
	 */
	public static function remember_choice( $cookie, $expire, $expiration, $user_id ) {
		update_user_meta( (int) $user_id, self::KEEP_META, $expire > 0 ? 1 : 0 );
	}

	/**
	 * 保持を選んだ人がサイトを使っているあいだは、ログインの期限を延ばし続ける。
	 */
	public static function extend() {
		if ( ! is_user_logged_in() || headers_sent() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! get_user_meta( $user_id, self::KEEP_META, true ) ) {
			return;
		}
		$cookie = wp_parse_auth_cookie( '', 'logged_in' );
		if ( ! $cookie || (int) $cookie['expiration'] - time() > self::REFRESH ) {
			return;
		}
		// 同じログインのまま期限だけを延ばす（ページの中の確認用の鍵＝ノンスが変わらないよう、同じ記録を使う）。
		$manager = WP_Session_Tokens::get_instance( $user_id );
		$session = empty( $cookie['token'] ) ? null : $manager->get( $cookie['token'] );
		if ( ! $session ) {
			return;
		}
		$session['expiration'] = time() + self::LIFETIME;
		$manager->update( $cookie['token'], $session );
		wp_set_auth_cookie( $user_id, true, is_ssl(), $cookie['token'] );
	}

	/**
	 * 「ログイン状態を保持する」の欄（登録・ログインのフォームに入れる）。
	 */
	public static function field( $name = 'remember' ) {
		return '<label class="snscs-check snscs-keep-login"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" checked> <span>ログイン状態を保持する<small>（ログアウトするまで、ずっとログインしたままになります。共用のパソコンではチェックを外してください）</small></span></label>';
	}

	/**
	 * フォームで「保持する」が選ばれたか。
	 */
	public static function wants_keep( $name = 'remember' ) {
		return ! empty( $_POST[ $name ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 呼び出し元でノンスを確かめている。
	}

	/**
	 * WordPress のログインフォーム（発信者マイページ）: 初めからチェックを入れ、文言をそろえる。
	 */
	public static function login_form_defaults( $defaults ) {
		$defaults['value_remember'] = true;
		$defaults['label_remember'] = 'ログイン状態を保持する';
		return $defaults;
	}

	/**
	 * ページの上に出す「○○さん」（ログイン中だけ）。
	 *
	 * @return array{name:string,role:string}|null
	 */
	public static function who() {
		if ( ! is_user_logged_in() ) {
			return null;
		}
		$user = wp_get_current_user();
		$name = $user->display_name;
		$role = '会員';
		if ( snscs_is_creator_user( $user ) ) {
			$role    = '発信者';
			$creator = SNSCS_Submissions::creator_id_for_user( $user->ID );
			if ( $creator && 'publish' === get_post_status( $creator ) ) {
				$name = html_entity_decode( get_the_title( $creator ), ENT_QUOTES, 'UTF-8' );
				$no   = SNSCS_Founder::number( $creator );
				if ( $no ) {
					$role = SNSCS_Founder::name( SNSCS_Founder::design( $no ) );
				}
			}
		}
		return array(
			'name' => '' !== trim( (string) $name ) ? $name : 'ゲスト',
			'role' => $role,
		);
	}
}
