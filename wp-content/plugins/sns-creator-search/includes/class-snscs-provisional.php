<?php
/**
 * 仮登録のセイント（1.27）: 運営が承認して本登録になるまでは、見ることと申請の確認・修正だけ。
 *
 * - フォロー・いいね・リポスト・ブックマーク・推しリスト・質問箱・アワードの投票・コラボの申し込み・PR枠の申し込みなどは止める
 *   （REST と admin-post の入口で止めるので、各機能のコードはそのまま）
 * - 経験値（SNSCS_Game）も本登録から
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Provisional {

	/** 仮登録では使えない、画面からの送信（admin-post の action） */
	const BLOCKED_ACTIONS = array( 'snscs_bio_follow', 'snscs_collab_request', 'snscs_collab_respond', 'snscs_collab_settings', 'snscs_list_delete', 'snscs_list_save', 'snscs_qa_answer', 'snscs_qa_ask', 'snscs_qa_best', 'snscs_save_search', 'snscs_promo_apply', 'snscs_profile_save', 'snscs_article_save', 'snscs_article_delete', 'snscs_bulk_inquiry' );

	/** 仮登録では使えない REST（書き込み） */
	const BLOCKED_ROUTES = array( '/snscs/v1/social', '/snscs/v1/social/sync', '/snscs/v1/lists/add', '/snscs/v1/lists/like', '/snscs/v1/qa/helpful', '/snscs/v1/award/vote' );

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'guard_post' ), 1 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_rest' ), 10, 3 );
	}

	public static function is_provisional( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		return $user && $user->exists() && ! snscs_is_full_member( $user );
	}

	public static function guard_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( ! wp_doing_ajax() && in_array( $action, self::BLOCKED_ACTIONS, true ) && is_user_logged_in() && self::is_provisional() ) {
			wp_safe_redirect( add_query_arg( 'snscs_notice', 'provisional', snscs_portal_url() ) );
			exit;
		}
	}

	public static function guard_rest( $result, $server, $request ) {
		if ( null !== $result || 'GET' === $request->get_method() || ! is_user_logged_in() ) {
			return $result;
		}
		if ( in_array( untrailingslashit( $request->get_route() ), self::BLOCKED_ROUTES, true ) && self::is_provisional() ) {
			return new WP_Error( 'snscs_provisional', 'いまは仮登録です。運営の承認で本登録になると使えます。', array( 'status' => 403 ) );
		}
		return $result;
	}

	/**
	 * 仮登録の人への案内（会員ページ・マイページの上）。
	 */
	public static function banner_html() {
		if ( ! self::is_provisional() ) {
			return '';
		}
		$pending = SNSCS_Submissions::pending_for_user( get_current_user_id() );
		return '<div class="snscs-provisional"><p><strong>' . esc_html( $pending ? 'いまは仮登録です（本登録の審査中）。' : 'いまは仮登録です。' ) . '</strong>'
			. esc_html( $pending ? '運営が確認して承認すると本登録になり、検索結果に載ります。フォロー・いいね・経験値などは本登録から使えます。' : 'プロフィールとSNSアカウント（1つ以上）を入れて申請してください。運営が承認すると本登録になり、フォロー・いいね・経験値などが使えます。' ) . '</p>'
			. '<a class="snscs-button snscs-button--primary" href="' . esc_url( snscs_portal_url() ) . '">' . esc_html( $pending ? '申請内容を見る' : '本登録を申請する' ) . '</a></div>';
	}
}
