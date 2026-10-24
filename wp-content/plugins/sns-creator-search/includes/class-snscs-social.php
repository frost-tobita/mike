<?php
/**
 * フォロー・いいね・リポスト・ブックマークと、読者の会員登録。
 *
 * 使える人: ログインした会員（読者・発信者）。ブックマークだけは登録しなくても使える（このブラウザに保存し、
 *           登録・ログインしたときにアカウントへ移す）。
 *   フォロー     : 発信者をフォローすると、会員ページ「フォロー中」に、その人の新しい記事とリポストが並ぶ
 *   いいね       : 記事への「よかった」。数を記事に表示する
 *   リポスト     : 記事を自分のフォロワーに紹介する。発信者がリポストすると、その発信者のフォロワーの「フォロー中」と、
 *                  発信者ページの「リポストした記事」に出る（読者のリポストは数として数えるだけ）
 *   引用リポスト : ひとこと（140文字まで）を添えたリポスト。記事の下の「この記事を広めた発信者」とフォロワーの画面に出る
 *   ブックマーク : 自分だけの「あとで読む」。どこまで読んだかを覚え、「続きから読む」で戻れる。読み終えていないものは週1回お知らせ
 * 誰がフォロー・いいねしたかは公開しない（数だけ）。
 *
 * 不正対策: 自分自身へのフォロー・いいね等はできない。1人1時間300回まで。
 * 点数（「このサイトでの反応」）には、登録から3日以上たったアカウントの反応だけを数える。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Social {

	const ROLE         = 'snscs_reader';
	const LIKE         = 1;
	const REPOST       = 2;
	const BOOKMARK     = 3;
	const MIN_AGE_DAYS = 3;
	const PER_PAGE     = 20;

	public static function init() {
		add_shortcode( 'sns_member', array( __CLASS__, 'shortcode' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_post_nopriv_snscs_member_register', array( __CLASS__, 'handle_register' ) );
		add_action( 'admin_post_snscs_member_register', array( __CLASS__, 'handle_register' ) );
		add_action( 'admin_post_nopriv_snscs_member_login', array( __CLASS__, 'handle_login' ) );
		add_action( 'admin_post_snscs_member_login', array( __CLASS__, 'handle_login' ) );
		add_action( 'admin_post_snscs_member_withdraw', array( __CLASS__, 'handle_withdraw' ) );
		add_action( 'admin_init', array( __CLASS__, 'keep_out_of_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
		add_filter( 'the_content', array( __CLASS__, 'append_actions' ), 25 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_script' ) );
		add_action( 'deleted_user', array( __CLASS__, 'on_deleted_user' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
	}

	public static function add_role() {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, '読者', array( 'read' => true ) );
		}
	}

	public static function follows_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_follows';
	}

	public static function reactions_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_reactions';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset   = $wpdb->get_charset_collate();
		$follows   = self::follows_table();
		$reactions = self::reactions_table();
		dbDelta(
			"CREATE TABLE {$follows} (
  user_id bigint(20) unsigned NOT NULL,
  creator_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (user_id,creator_id),
  KEY creator_id (creator_id)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$reactions} (
  user_id bigint(20) unsigned NOT NULL,
  article_id bigint(20) unsigned NOT NULL,
  type tinyint(3) unsigned NOT NULL,
  comment varchar(280) NOT NULL DEFAULT '',
  progress tinyint(3) unsigned NOT NULL DEFAULT 0,
  read_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (user_id,article_id,type),
  KEY article_type (article_id,type),
  KEY type_time (type,created_at)
) {$charset};"
		);
		self::add_role();
	}

	/* --------------------------------------------------------------------
	 * 会員（読者）
	 * ------------------------------------------------------------------ */

	public static function is_reader( $user = null ) {
		$user = $user ? ( $user instanceof WP_User ? $user : get_userdata( $user ) ) : wp_get_current_user();
		// 発信者としても登録した人は、発信者として扱う（退会などは発信者マイページから）。
		return $user && $user->exists() && in_array( self::ROLE, (array) $user->roles, true ) && ! in_array( SNSCS_Portal::ROLE, (array) $user->roles, true ) && ! user_can( $user, 'edit_posts' );
	}

	public static function member_url( $args = array() ) {
		$url = SNSCS_Hub::page_url( 'member' );
		return $url ? add_query_arg( $args, $url ) : '';
	}

	public static function keep_out_of_admin() {
		global $pagenow;
		if ( self::is_reader() && ! wp_doing_ajax() && 'admin-post.php' !== $pagenow ) {
			wp_safe_redirect( self::member_url() ? self::member_url() : home_url( '/' ) );
			exit;
		}
	}

	public static function hide_admin_bar( $show ) {
		return self::is_reader() ? false : $show;
	}

	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && self::is_reader( $user ) && ( '' === $requested || 0 === strpos( $requested, admin_url() ) ) ) {
			return self::member_url() ? self::member_url() : home_url( '/' );
		}
		return $redirect_to;
	}

	/**
	 * 戻り先（このサイトの中だけ）。
	 */
	private static function return_to() {
		$to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $to ? wp_validate_redirect( $to, '' ) : '';
	}

	private static function back( $notice, $redirect = '' ) {
		$url = $redirect ? $redirect : self::member_url();
		wp_safe_redirect( add_query_arg( 'snscs_notice', $notice, $url ? $url : home_url( '/' ) ) );
		exit;
	}

	public static function handle_register() {
		if ( ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_member_register' ) ) {
			self::back( 'form_retry' );
		}
		$redirect = self::return_to();
		if ( is_user_logged_in() ) {
			self::back( 'logged_in', $redirect );
		}
		$token = isset( $_POST['snscs_ft'] ) ? sanitize_text_field( wp_unslash( $_POST['snscs_ft'] ) ) : '';
		if ( ! empty( $_POST['snscs_website'] ) ) {
			self::back( 'register_error' );
		}
		// 開いてから早すぎる送信・古すぎるページは、もう一度押してもらう（キャッシュされたページなど）。
		if ( ! snscs_check_form_token( $token, 2 ) ) {
			self::back( 'form_retry' );
		}
		if ( snscs_rate_limited( 'member_register', 5 ) ) {
			self::back( 'too_many' );
		}
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- パスワードは加工しない。
		if ( ! is_email( $email ) ) {
			self::back( 'email_invalid' );
		}
		if ( email_exists( $email ) ) {
			self::back( 'email_exists' );
		}
		if ( strlen( $password ) < SNSCS_Portal::MIN_PASSWORD ) {
			self::back( 'weak_password' );
		}
		if ( empty( $_POST['agree'] ) ) {
			self::back( 'agree' );
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => 'r' . strtolower( wp_generate_password( 11, false ) ),
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => strstr( $email, '@', true ),
				'role'         => self::ROLE,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			self::back( 'register_error' );
		}
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, SNSCS_Session::wants_keep() );
		self::back( 'registered', $redirect );
	}

	public static function handle_login() {
		if ( ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_member_login' ) ) {
			self::back( 'form_retry' );
		}
		$redirect = self::return_to();
		if ( snscs_rate_limited( 'member_login', 20 ) ) {
			self::back( 'login_error' );
		}
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$user  = $email ? get_user_by( 'email', $email ) : false;
		$creds = array(
			'user_login'    => $user ? $user->user_login : '',
			'user_password' => isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'remember'      => SNSCS_Session::wants_keep(),
		);
		$signed = $user ? wp_signon( $creds, is_ssl() ) : null;
		if ( ! $signed || is_wp_error( $signed ) ) {
			self::back( 'login_error' );
		}
		// 発信者は発信者マイページへ（戻り先の指定があればそちら）。
		if ( ! $redirect && snscs_is_creator_user( $signed ) ) {
			$redirect = snscs_portal_url();
		}
		self::back( 'logged_in', $redirect );
	}

	public static function handle_withdraw() {
		if ( ! self::is_reader() || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_member_withdraw' ) ) {
			self::back( 'error' );
		}
		$user     = wp_get_current_user();
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			self::back( 'withdraw_pass' );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_logout();
		wp_delete_user( $user->ID );
		self::back( 'withdrawn' );
	}

	/* --------------------------------------------------------------------
	 * データ
	 * ------------------------------------------------------------------ */

	/**
	 * 会員の状態（フォロー中の発信者・反応した記事）を、指定したIDについてまとめて調べる。
	 *
	 * @return array{follows:array<int,bool>,reactions:array<int,array<int,bool>>}
	 */
	public static function state( $user_id, array $creator_ids = array(), array $article_ids = array() ) {
		global $wpdb;
		$out = array( 'follows' => array(), 'reactions' => array() );
		if ( ! $user_id ) {
			return $out;
		}
		$creator_ids = array_filter( array_map( 'intval', $creator_ids ) );
		$article_ids = array_filter( array_map( 'intval', $article_ids ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		if ( $creator_ids ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT creator_id FROM ' . self::follows_table() . ' WHERE user_id = %d AND creator_id IN (' . implode( ',', $creator_ids ) . ')', $user_id ) ) as $id ) {
				$out['follows'][ (int) $id ] = true;
			}
		}
		if ( $article_ids ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT article_id, type FROM ' . self::reactions_table() . ' WHERE user_id = %d AND article_id IN (' . implode( ',', $article_ids ) . ')', $user_id ), ARRAY_A ) as $row ) {
				$out['reactions'][ (int) $row['article_id'] ][ (int) $row['type'] ] = true;
			}
		}
		// phpcs:enable
		return $out;
	}

	public static function follower_count( $creator_id ) {
		return (int) get_post_meta( $creator_id, '_snscs_follower_count', true );
	}

	public static function counts( $article_id ) {
		return array(
			self::LIKE     => (int) get_post_meta( $article_id, '_snscs_like_count', true ),
			self::REPOST   => (int) get_post_meta( $article_id, '_snscs_repost_count', true ),
			self::BOOKMARK => (int) get_post_meta( $article_id, '_snscs_bookmark_count', true ),
		);
	}

	private static function recount_creator( $creator_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::follows_table() . ' WHERE creator_id = %d', $creator_id ) );
		update_post_meta( $creator_id, '_snscs_follower_count', $n );
		return $n;
	}

	private static function recount_article( $article_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = wp_list_pluck( $wpdb->get_results( $wpdb->prepare( 'SELECT type, COUNT(*) AS n FROM ' . self::reactions_table() . ' WHERE article_id = %d GROUP BY type', $article_id ), ARRAY_A ), 'n', 'type' );
		$keys = array( self::LIKE => '_snscs_like_count', self::REPOST => '_snscs_repost_count', self::BOOKMARK => '_snscs_bookmark_count' );
		foreach ( $keys as $type => $key ) {
			update_post_meta( $article_id, $key, isset( $rows[ $type ] ) ? (int) $rows[ $type ] : 0 );
		}
		return self::counts( $article_id );
	}

	/**
	 * 反応できる記事か（発信者の記事・公開中の投稿）。
	 */
	public static function is_article( $post_id ) {
		$type = get_post_type( $post_id );
		return in_array( $type, array( SNSCS_Articles::POST_TYPE, 'post' ), true ) && 'publish' === get_post_status( $post_id );
	}

	/**
	 * 発信者である会員の、発信者ID（なければ 0）。
	 */
	public static function own_creator( $user_id ) {
		return (int) get_user_meta( $user_id, 'snscs_creator_id', true );
	}

	/**
	 * フォロー・反応の切り替え。
	 *
	 * @return array|WP_Error
	 */
	public static function toggle( $user_id, $action, $id, $on, $comment = '' ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		if ( 'follow_user' === $action ) {
			return SNSCS_People::toggle( (int) $user_id, (int) $id, $on );
		}
		if ( 'follow' === $action ) {
			if ( SNSCS_Post_Types::POST_TYPE !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
				return new WP_Error( 'not_found', '発信者が見つかりません。', array( 'status' => 404 ) );
			}
			if ( self::own_creator( $user_id ) === $id ) {
				return new WP_Error( 'self', '自分自身はフォローできません。', array( 'status' => 400 ) );
			}
			if ( $on ) {
				$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::follows_table() . ' (user_id, creator_id, created_at) VALUES (%d, %d, %s)', $user_id, $id, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$wpdb->delete( self::follows_table(), array( 'user_id' => $user_id, 'creator_id' => $id ), array( '%d', '%d' ) );
			}
			$count = self::recount_creator( $id );
			do_action( 'snscs_social_changed', $user_id, $action, $id, (bool) $on );
			return array( 'on' => (bool) $on, 'count' => $count );
		}
		$types = array( 'like' => self::LIKE, 'repost' => self::REPOST, 'bookmark' => self::BOOKMARK );
		if ( ! isset( $types[ $action ] ) ) {
			return new WP_Error( 'bad_action', '操作が正しくありません。', array( 'status' => 400 ) );
		}
		if ( ! self::is_article( $id ) ) {
			return new WP_Error( 'not_found', '記事が見つかりません。', array( 'status' => 404 ) );
		}
		$own = self::own_creator( $user_id );
		if ( self::BOOKMARK !== $types[ $action ] && $own && SNSCS_Articles::POST_TYPE === get_post_type( $id ) && SNSCS_Articles::creator_of( $id ) === $own ) {
			return new WP_Error( 'self', '自分の記事にはできません。', array( 'status' => 400 ) );
		}
		$comment = self::REPOST === $types[ $action ] ? mb_substr( trim( sanitize_textarea_field( (string) $comment ) ), 0, 140 ) : '';
		if ( $on ) {
			// ひとことを付け直したときは、ひとことだけ新しくする。
			$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::reactions_table() . ' (user_id, article_id, type, comment, created_at) VALUES (%d, %d, %d, %s, %s) ON DUPLICATE KEY UPDATE created_at = IF(VALUES(comment) <> \'\', VALUES(created_at), created_at), comment = IF(VALUES(comment) <> \'\', VALUES(comment), comment)', $user_id, $id, $types[ $action ], $comment, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$wpdb->delete( self::reactions_table(), array( 'user_id' => $user_id, 'article_id' => $id, 'type' => $types[ $action ] ), array( '%d', '%d', '%d' ) );
		}
		$counts = self::recount_article( $id );
		do_action( 'snscs_social_changed', $user_id, $action, $id, (bool) $on );
		return array( 'on' => (bool) $on, 'count' => $counts[ $types[ $action ] ] );
	}

	public static function on_deleted_user( $user_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$creators = $wpdb->get_col( $wpdb->prepare( 'SELECT creator_id FROM ' . self::follows_table() . ' WHERE user_id = %d', $user_id ) );
		$articles = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT article_id FROM ' . self::reactions_table() . ' WHERE user_id = %d', $user_id ) );
		// phpcs:enable
		$wpdb->delete( self::follows_table(), array( 'user_id' => $user_id ), array( '%d' ) );
		$wpdb->delete( self::reactions_table(), array( 'user_id' => $user_id ), array( '%d' ) );
		foreach ( $creators as $id ) {
			self::recount_creator( (int) $id );
		}
		foreach ( $articles as $id ) {
			self::recount_article( (int) $id );
		}
	}

	public static function on_delete_post( $post_id ) {
		global $wpdb;
		$type = get_post_type( $post_id );
		if ( SNSCS_Post_Types::POST_TYPE === $type ) {
			$wpdb->delete( self::follows_table(), array( 'creator_id' => $post_id ), array( '%d' ) );
		} elseif ( in_array( $type, array( SNSCS_Articles::POST_TYPE, 'post' ), true ) ) {
			$wpdb->delete( self::reactions_table(), array( 'article_id' => $post_id ), array( '%d' ) );
		}
	}

	/* --------------------------------------------------------------------
	 * REST
	 * ------------------------------------------------------------------ */

	public static function register_routes() {
		register_rest_route(
			'snscs/v1',
			'/social',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_toggle' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
		register_rest_route(
			'snscs/v1',
			'/social/progress',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_progress' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
		register_rest_route(
			'snscs/v1',
			'/social/sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_sync' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
		register_rest_route(
			'snscs/v1',
			'/social/cards',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_cards' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function rest_toggle( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( self::limited( $user_id ) ) {
			return new WP_Error( 'too_many', '操作が多すぎます。しばらくしてからお試しください。', array( 'status' => 429 ) );
		}
		$result = self::toggle( $user_id, sanitize_key( (string) $request->get_param( 'action' ) ), absint( $request->get_param( 'id' ) ), (bool) $request->get_param( 'on' ), (string) $request->get_param( 'comment' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * ブックマークした記事を、どこまで読んだか（0〜100%）。9割まで読んだら「読み終えた」。
	 */
	public static function rest_progress( WP_REST_Request $request ) {
		global $wpdb;
		$id       = absint( $request->get_param( 'id' ) );
		$progress = min( 100, max( 0, (int) $request->get_param( 'p' ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::reactions_table() . ' SET progress = GREATEST(progress, %d), read_at = IF(read_at IS NULL AND %d >= 90, %s, read_at) WHERE user_id = %d AND article_id = %d AND type = %d', $progress, $progress, current_time( 'mysql' ), get_current_user_id(), $id, self::BOOKMARK ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * ログイン前にこのブラウザでブックマークした記事を、アカウントへ移す。
	 */
	public static function rest_sync( WP_REST_Request $request ) {
		$ids  = array_slice( array_filter( array_map( 'absint', (array) $request->get_param( 'bookmarks' ) ) ), 0, 200 );
		$done = 0;
		foreach ( $ids as $id ) {
			if ( self::is_article( $id ) && ! is_wp_error( self::toggle( get_current_user_id(), 'bookmark', $id, true ) ) ) {
				$done++;
			}
		}
		return rest_ensure_response( array( 'synced' => $done ) );
	}

	/**
	 * 記事のカード（登録していない人のブックマーク一覧を表示するため）。
	 */
	public static function rest_cards( WP_REST_Request $request ) {
		$ids = array_slice( array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) ), 0, 100 );
		$out = array();
		foreach ( $ids as $id ) {
			if ( self::is_article( $id ) ) {
				$out[] = self::card_data( $id );
			}
		}
		return rest_ensure_response( $out );
	}

	private static function limited( $user_id ) {
		$key   = 'snscs_social_' . $user_id;
		$count = (int) get_transient( $key );
		if ( $count >= 300 ) {
			return true;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return false;
	}

	/* --------------------------------------------------------------------
	 * ボタン
	 * ------------------------------------------------------------------ */

	public static function register_script() {
		wp_register_script( 'snscs-social', SNSCS_URL . 'assets/js/snscs-social.js', array(), SNSCS_VERSION, true );
	}

	private static function enqueue() {
		static $done = false;
		wp_enqueue_style( 'snscs' );
		if ( $done ) {
			return;
		}
		$done = true;
		if ( ! wp_script_is( 'snscs-social', 'registered' ) ) {
			self::register_script();
		}
		wp_enqueue_script( 'snscs-social' );
		$current = isset( $_SERVER['REQUEST_URI'] ) ? home_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : home_url( '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		wp_add_inline_script(
			'snscs-social',
			'window.snscsSocial = ' . wp_json_encode(
				array(
					'rest'     => esc_url_raw( rest_url( 'snscs/v1/social' ) ),
					'article'  => is_singular( array( SNSCS_Articles::POST_TYPE, 'post' ) ) ? get_queried_object_id() : 0,
					'progress' => self::my_progress(),
					'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
					'loggedIn' => is_user_logged_in(),
					'join'     => self::member_url( array( 'redirect_to' => rawurlencode( $current ) ) ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * 表示中の記事をブックマークしていれば、前回どこまで読んだか（%）。していなければ -1。
	 */
	private static function my_progress() {
		global $wpdb;
		if ( ! is_user_logged_in() || ! is_singular( array( SNSCS_Articles::POST_TYPE, 'post' ) ) ) {
			return -1;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$p = $wpdb->get_var( $wpdb->prepare( 'SELECT progress FROM ' . self::reactions_table() . ' WHERE user_id = %d AND article_id = %d AND type = %d', get_current_user_id(), get_queried_object_id(), self::BOOKMARK ) );
		return null === $p ? -1 : (int) $p;
	}

	/**
	 * 発信者のフォローボタン。
	 */
	public static function follow_button( $creator_id ) {
		$creator_id = (int) $creator_id;
		$user_id    = get_current_user_id();
		if ( $user_id && self::own_creator( $user_id ) === $creator_id ) {
			return '';
		}
		self::enqueue();
		$on    = $user_id ? ! empty( self::state( $user_id, array( $creator_id ) )['follows'][ $creator_id ] ) : false;
		$count = self::follower_count( $creator_id );
		return sprintf(
			'<div class="snscs-follow"><button type="button" class="snscs-follow__button%s" data-snscs-social="follow" data-id="%d" aria-pressed="%s"><span data-label-on="フォロー中" data-label-off="フォローする">%s</span></button> <span class="snscs-follow__count"><span data-snscs-count>%s</span>人がフォロー</span></div>',
			$on ? ' is-on' : '',
			$creator_id,
			$on ? 'true' : 'false',
			$on ? 'フォロー中' : 'フォローする',
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * 記事の「いいね・リポスト・ブックマーク」の列。
	 */
	public static function action_bar( $article_id ) {
		$article_id = (int) $article_id;
		self::enqueue();
		$user_id = get_current_user_id();
		$state   = $user_id ? self::state( $user_id, array(), array( $article_id ) )['reactions'] : array();
		$mine    = isset( $state[ $article_id ] ) ? $state[ $article_id ] : array();
		$counts  = self::counts( $article_id );
		$own     = $user_id && SNSCS_Articles::POST_TYPE === get_post_type( $article_id ) && SNSCS_Articles::creator_of( $article_id ) === self::own_creator( $user_id ) && self::own_creator( $user_id );
		$buttons = array(
			'like'     => array( self::LIKE, '♡', '♥', 'いいね', true ),
			'repost'   => array( self::REPOST, '↻', '↻', 'リポスト', true ),
			'bookmark' => array( self::BOOKMARK, '☆', '★', 'ブックマーク', false ),
		);
		$html = '<div class="snscs-actions" role="group" aria-label="この記事への反応">';
		foreach ( $buttons as $action => $def ) {
			if ( $own && 'bookmark' !== $action ) {
				// 自分の記事には数だけ出す。
				$html .= sprintf( '<span class="snscs-actions__item is-static"><span aria-hidden="true">%s</span> %s <span>%s</span></span>', esc_html( $def[1] ), esc_html( $def[3] ), esc_html( number_format_i18n( $counts[ $def[0] ] ) ) );
				continue;
			}
			$on    = ! empty( $mine[ $def[0] ] );
			$html .= sprintf(
				'<button type="button" class="snscs-actions__item snscs-actions__item--%1$s%2$s" data-snscs-social="%1$s" data-id="%3$d" aria-pressed="%4$s" data-icon-on="%5$s" data-icon-off="%6$s"><span class="snscs-actions__icon" aria-hidden="true">%7$s</span> %8$s%9$s</button>',
				esc_attr( $action ),
				$on ? ' is-on' : '',
				$article_id,
				$on ? 'true' : 'false',
				esc_attr( $def[2] ),
				esc_attr( $def[1] ),
				esc_html( $on ? $def[2] : $def[1] ),
				esc_html( $def[3] ),
				$def[4] ? ' <span data-snscs-count>' . esc_html( number_format_i18n( $counts[ $def[0] ] ) ) . '</span>' : ''
			);
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * 記事（発信者の記事・コラム・自動投稿）の本文の前後に、反応のボタンを付ける。
	 */
	public static function append_actions( $content ) {
		if ( ! is_singular( array( SNSCS_Articles::POST_TYPE, 'post' ) ) || ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		$bar = self::action_bar( get_the_ID() );
		return $bar . $content . $bar;
	}

	/* --------------------------------------------------------------------
	 * 一覧
	 * ------------------------------------------------------------------ */

	public static function card_data( $article_id ) {
		$creator = SNSCS_Articles::POST_TYPE === get_post_type( $article_id ) ? SNSCS_Articles::creator_of( $article_id ) : 0;
		return array(
			'id'      => (int) $article_id,
			'title'   => html_entity_decode( get_the_title( $article_id ), ENT_QUOTES, 'UTF-8' ),
			'url'     => get_permalink( $article_id ),
			'date'    => get_the_date( 'Y年n月j日', $article_id ),
			'creator' => $creator ? html_entity_decode( get_the_title( $creator ), ENT_QUOTES, 'UTF-8' ) : '',
			'image'   => (string) get_the_post_thumbnail_url( $article_id, 'thumbnail' ),
		);
	}

	/**
	 * 記事の一覧（会員ページ・みんなの記事・発信者ページ用）。
	 *
	 * @param array $items [['id' => 記事ID, 'note' => 上に出す一言, 'comment' => ひとこと, 'rp' => リポストした発信者ID,
	 *                       'progress' => 読んだ割合（ブックマーク）, 'read' => 読み終えたか,
	 *                       'reason' => おすすめの理由, 'track' => URLに付ける記録用の値, 'hide' => 「興味がない」を出すか], ...]
	 */
	public static function render_list( array $items, $empty = '' ) {
		if ( ! $items ) {
			return $empty ? '<p class="snscs-help">' . esc_html( $empty ) . '</p>' : '';
		}
		self::enqueue();
		$ids     = wp_list_pluck( $items, 'id' );
		$user_id = get_current_user_id();
		$mine    = $user_id ? self::state( $user_id, array(), $ids )['reactions'] : array();
		$html    = '<ul class="snscs-feed">';
		foreach ( $items as $item ) {
			$d      = self::card_data( $item['id'] );
			$counts = self::counts( $item['id'] );
			$url    = ! empty( $item['rp'] ) ? add_query_arg( 'rp', (int) $item['rp'], $d['url'] ) : ( ! empty( $item['ru'] ) ? add_query_arg( 'ru', (int) $item['ru'], $d['url'] ) : $d['url'] );
			if ( ! empty( $item['track'] ) ) {
				$url = add_query_arg( $item['track'], $url );
			}
			$marked = ! empty( $mine[ $item['id'] ][ self::BOOKMARK ] );
			$html  .= '<li class="snscs-feed__item">';
			if ( ! empty( $item['note'] ) ) {
				$html .= '<p class="snscs-feed__note">↻ ' . esc_html( $item['note'] ) . '</p>';
			} elseif ( ! empty( $item['reason'] ) ) {
				$html .= '<p class="snscs-feed__note snscs-feed__reason">' . esc_html( ( false !== strpos( $item['reason'], 'リポスト' ) ? '↻ ' : '' ) . $item['reason'] ) . '</p>';
			}
			if ( ! empty( $item['comment'] ) ) {
				$html .= '<p class="snscs-feed__comment">' . esc_html( $item['comment'] ) . '</p>';
			}
			$html .= '<div class="snscs-feed__row"><a class="snscs-feed__link" href="' . esc_url( $url ) . '">';
			if ( $d['image'] ) {
				$html .= '<img class="snscs-feed__image" src="' . esc_url( $d['image'] ) . '" alt="" width="64" height="64" loading="lazy">';
			}
			$html .= '<span class="snscs-feed__body"><span class="snscs-feed__title">' . esc_html( $d['title'] ) . '</span>';
			$html .= '<span class="snscs-feed__meta">' . esc_html( trim( $d['creator'] . '　' . $d['date'] ) ) . '　♥ ' . esc_html( number_format_i18n( $counts[ self::LIKE ] ) ) . '　↻ ' . esc_html( number_format_i18n( $counts[ self::REPOST ] ) ) . '</span>';
			if ( isset( $item['progress'] ) ) {
				if ( ! empty( $item['read'] ) ) {
					$html .= '<span class="snscs-feed__progress is-done">読み終えました</span>';
				} elseif ( $item['progress'] > 0 ) {
					$html .= '<span class="snscs-feed__progress"><span class="snscs-feed__bar"><span style="width:' . (int) $item['progress'] . '%"></span></span>' . (int) $item['progress'] . '%まで読みました</span>';
				} else {
					$html .= '<span class="snscs-feed__progress is-new">未読</span>';
				}
			}
			$html .= '</span></a>';
			$html .= sprintf(
				'<button type="button" class="snscs-feed__mark%s" data-snscs-social="bookmark" data-id="%d" aria-pressed="%s" aria-label="ブックマーク" data-icon-on="★" data-icon-off="☆"><span class="snscs-actions__icon" aria-hidden="true">%s</span></button>',
				$marked ? ' is-on' : '',
				(int) $item['id'],
				$marked ? 'true' : 'false',
				$marked ? '★' : '☆'
			);
			$html .= '</div>';
			if ( isset( $item['progress'] ) && empty( $item['read'] ) && $item['progress'] > 0 ) {
				$html .= '<p class="snscs-feed__resume"><a href="' . esc_url( $d['url'] . '#snscs-resume' ) . '">続きから読む →</a></p>';
			}
			if ( ! empty( $item['hide'] ) ) {
				$html .= '<button type="button" class="snscs-feed__hide" data-snscs-hide="' . (int) $item['id'] . '">興味がない</button>';
			}
			$html .= '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * 「フォロー中」の新着: フォローしている発信者の記事と、その人たちのリポスト（新しい順）。
	 */
	public static function timeline( $user_id, $page = 1 ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$creators = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT creator_id FROM ' . self::follows_table() . ' WHERE user_id = %d', $user_id ) ) );
		$people   = SNSCS_People::following_ids( $user_id );
		if ( ! $creators && ! $people ) {
			return array();
		}
		$in    = $creators ? implode( ',', $creators ) : '0';
		$limit = self::PER_PAGE * $page + 1;
		$posts = ! $creators ? array() : $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, p.post_date AS at FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_snscs_creator_id' AND pm.meta_value IN ({$in})
				WHERE p.post_type = %s AND p.post_status = 'publish' ORDER BY p.post_date DESC LIMIT %d",
				SNSCS_Articles::POST_TYPE,
				$limit
			),
			ARRAY_A
		);
		$users = $creators ? wp_list_pluck( $wpdb->get_results( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'snscs_creator_id' AND meta_value IN ({$in})", ARRAY_A ), 'meta_value', 'user_id' ) : array();
		// リポストを流す人: フォローしている発信者のアカウントと、フォローしている会員。
		$reposters = array_unique( array_merge( array_map( 'intval', array_keys( $users ) ), $people ) );
		$reposts   = array();
		if ( $reposters ) {
			$reposts = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, article_id AS id, comment, created_at AS at FROM ' . self::reactions_table() . ' WHERE type = %d AND user_id IN (' . implode( ',', $reposters ) . ') ORDER BY created_at DESC LIMIT %d', self::REPOST, $limit ), ARRAY_A );
		}
		// phpcs:enable
		$items = array();
		foreach ( $posts as $row ) {
			$items[ (int) $row['id'] ] = array( 'id' => (int) $row['id'], 'at' => $row['at'], 'note' => '' );
		}
		foreach ( $reposts as $row ) {
			$id = (int) $row['id'];
			if ( ! self::is_article( $id ) ) {
				continue;
			}
			$creator = isset( $users[ $row['user_id'] ] ) ? (int) $users[ $row['user_id'] ] : 0;
			$name    = $creator ? get_the_title( $creator ) : SNSCS_People::nickname( (int) $row['user_id'] );
			$who     = $name . 'さんが' . ( '' !== $row['comment'] ? 'ひとこと付きで' : '' ) . 'リポスト';
			if ( ! isset( $items[ $id ] ) || $row['at'] > $items[ $id ]['at'] ) {
				$items[ $id ] = array( 'id' => $id, 'at' => $row['at'], 'note' => $who, 'comment' => $row['comment'], 'rp' => $creator, 'ru' => $creator ? 0 : (int) $row['user_id'] );
			}
		}
		usort(
			$items,
			function ( $a, $b ) {
				return strcmp( $b['at'], $a['at'] );
			}
		);
		return array_slice( $items, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE + 1 );
	}

	/**
	 * 会員が反応した記事（新しい順）。
	 */
	public static function reacted( $user_id, $type, $page = 1 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT article_id, comment, progress, read_at FROM ' . self::reactions_table() . ' WHERE user_id = %d AND type = %d ORDER BY created_at DESC LIMIT %d, %d', $user_id, $type, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE + 1 ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['article_id'];
			if ( ! self::is_article( $id ) ) {
				continue;
			}
			$item = array( 'id' => $id, 'note' => '', 'comment' => $row['comment'] );
			if ( self::BOOKMARK === (int) $type ) {
				$item['progress'] = (int) $row['progress'];
				$item['read']     = null !== $row['read_at'];
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * 発信者ページの「リポストした記事」。
	 */
	public static function reposts_html( $creator_id ) {
		global $wpdb;
		$user_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'snscs_creator_id' AND meta_value = %d LIMIT 1", $creator_id ) );
		if ( ! $user_id ) {
			return '';
		}
		$items = array_slice( self::reacted( $user_id, self::REPOST ), 0, 5 );
		if ( ! $items ) {
			return '';
		}
		return '<h2 class="snscs-single__heading">リポストした記事</h2>' . self::render_list( $items );
	}

	/* --------------------------------------------------------------------
	 * 会員ページ
	 * ------------------------------------------------------------------ */

	private static function notice() {
		$messages = array(
			'registered'     => array( 'success', '会員登録が完了しました。発信者をフォローしたり、記事にいいね・リポスト・ブックマークしたりできます。' ),
			'logged_in'      => array( 'success', 'ログインしました。' ),
			'withdrawn'      => array( 'success', '退会しました。フォロー・いいねなどの記録もすべて削除しました。' ),
			'inbox_saved'    => array( 'success', 'お知らせメールの設定を保存しました。' ),
			'profile_saved'  => array( 'success', 'プロフィールを保存しました。' ),
			'profile_name'   => array( 'error', 'プロフィールを公開するには、ニックネームを入力してください。' ),
			'login_error'    => array( 'error', 'メールアドレスかパスワードが違います。' ),
			'email_invalid'  => array( 'error', 'メールアドレスの形式が正しくありません。' ),
			'email_exists'   => array( 'error', 'このメールアドレスはすでに登録されています。ログインしてください。' ),
			'weak_password'  => array( 'error', sprintf( 'パスワードは%d文字以上にしてください。', SNSCS_Portal::MIN_PASSWORD ) ),
			'agree'          => array( 'error', '利用規約とプライバシーポリシーに同意してください。' ),
			'register_error' => array( 'error', '登録できませんでした。時間をおいてもう一度お試しください。' ),
			'form_retry'     => array( 'error', '確認のため、もう一度入力して送信してください（ページを開いてから時間がたっていたか、送信が早すぎました）。' ),
			'too_many'       => array( 'error', '短い時間に何度も送信されたため、いったん受け付けを止めています。1時間ほどたってからお試しください。' ),
			'withdraw_pass'  => array( 'error', 'パスワードが違うため、退会できませんでした。' ),
			'error'          => array( 'error', 'もう一度お試しください。' ),
		);
		$key      = isset( $_GET['snscs_notice'] ) ? sanitize_key( $_GET['snscs_notice'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $messages[ $key ] ) ) {
			printf( '<p class="snscs-notice snscs-notice--%s" role="status">%s</p>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
		}
	}

	public static function shortcode() {
		self::enqueue();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ( is_user_logged_in() && SNSCS_Inbox::unread_count( get_current_user_id() ) ? 'inbox' : 'timeline' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['pg'] ) ? max( 1, absint( $_GET['pg'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		ob_start();
		echo '<div class="snscs-member">';
		self::notice();
		$profile = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $profile ) {
			SNSCS_People::render_profile( $profile );
		} elseif ( ! is_user_logged_in() ) {
			self::render_auth();
		} else {
			self::render_member( get_current_user_id(), $tab, $page );
		}
		echo SNSCS_Legal::links_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo '</div>';
		return ob_get_clean();
	}

	private static function render_auth() {
		$redirect = self::return_to();
		?>
		<section class="snscs-panel">
			<h2 class="snscs-panel__title">会員登録（無料）</h2>
			<p>登録すると、気になる発信者をフォローして新しい記事をまとめて読めたり、記事にいいね・リポスト・ブックマークしたりできます。スマホとパソコンで同じ内容を使えます。</p>
			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>" class="snscs-form">
				<input type="hidden" name="action" value="snscs_member_register">
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>">
				<input type="hidden" name="snscs_ft" value="<?php echo esc_attr( snscs_form_token() ); ?>">
				<?php wp_nonce_field( 'snscs_member_register', 'snscs_nonce' ); ?>
				<p class="snscs-hp" aria-hidden="true"><input type="text" name="snscs_website" tabindex="-1" autocomplete="off"></p>
				<p><label>メールアドレス<br><input type="email" name="email" required autocomplete="email"></label></p>
				<p><label>パスワード（<?php echo (int) SNSCS_Portal::MIN_PASSWORD; ?>文字以上）<br><input type="password" name="password" required minlength="<?php echo (int) SNSCS_Portal::MIN_PASSWORD; ?>" autocomplete="new-password"></label></p>
				<p><?php echo SNSCS_Session::field(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 決まったHTML。 ?></p>
				<p><label><input type="checkbox" name="agree" value="1" required> 利用規約とプライバシーポリシーに同意する</label></p>
				<p><button type="submit" class="snscs-button snscs-button--primary">登録する</button></p>
			</form>
			<p class="snscs-help">発信者として掲載したい方は、<a href="<?php echo esc_url( snscs_portal_url() ); ?>">発信者マイページ</a>から登録してください（発信者のアカウントでも、フォロー・いいねなどが使えます）。</p>
		</section>
		<section class="snscs-panel">
			<h2 class="snscs-panel__title">ログイン</h2>
			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>" class="snscs-form">
				<input type="hidden" name="action" value="snscs_member_login">
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>">
				<?php wp_nonce_field( 'snscs_member_login', 'snscs_nonce' ); ?>
				<p><label>メールアドレス<br><input type="email" name="email" required autocomplete="email"></label></p>
				<p><label>パスワード<br><input type="password" name="password" required autocomplete="current-password"></label></p>
				<p><?php echo SNSCS_Session::field(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 決まったHTML。 ?></p>
				<p><button type="submit" class="snscs-button">ログイン</button> <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">パスワードを忘れた方</a></p>
			</form>
		</section>
		<section class="snscs-panel">
			<h2 class="snscs-panel__title">このブラウザのブックマーク</h2>
			<div data-snscs-local-bookmarks data-cards="<?php echo esc_url( rest_url( 'snscs/v1/social/cards' ) ); ?>"><p class="snscs-help">まだありません。記事の「☆ ブックマーク」を押すと、ここに保存されます（登録すると、どの端末でも見られます）。</p></div>
		</section>
		<?php
	}

	private static function render_member( $user_id, $tab, $page ) {
		$unread = SNSCS_Inbox::unread_count( $user_id );
		$tabs   = array(
			'inbox'     => 'お知らせ' . ( $unread ? '（' . $unread . '）' : '' ),
			'foryou'    => 'おすすめ',
			'timeline'  => 'フォロー中の新着',
			'lists'     => '推しリスト',
			'level'     => 'レベル・招待',
			'bookmarks' => 'ブックマーク',
			'likes'     => 'いいねした記事',
			'reposts'   => 'リポストした記事',
			'following' => 'フォロー中の発信者',
			'saved'     => '保存した検索',
			'account'   => 'アカウント',
		);
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'timeline';
		$user = wp_get_current_user();
		?>
		<p class="snscs-member__who"><?php echo esc_html( $user->user_email ); ?> でログイン中
			<?php if ( snscs_is_creator_user() ) : ?>
				・<a href="<?php echo esc_url( snscs_portal_url() ); ?>">発信者マイページ</a>
			<?php endif; ?>
			・<a href="<?php echo esc_url( wp_logout_url( self::member_url() ) ); ?>">ログアウト</a>
		</p>
		<?php echo 'level' === $tab ? '' : SNSCS_Gamify::summary_html( $user_id, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>
		<nav class="snscs-tabs snscs-member__tabs" aria-label="会員ページ">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a class="snscs-chip<?php echo $key === $tab ? ' is-current' : ''; ?>" href="<?php echo esc_url( self::member_url( array( 'tab' => $key ) ) ); ?>" <?php echo $key === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
		if ( 'inbox' === $tab ) {
			SNSCS_Inbox::render( $user_id );
			return;
		}
		if ( 'account' === $tab ) {
			SNSCS_People::render_settings( $user_id );
			echo SNSCS_App::install_box(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
			SNSCS_Push::render_settings();
			SNSCS_Line::render_settings( $user_id );
			SNSCS_Inbox::render_settings( $user_id );
			self::render_account();
			return;
		}
		if ( 'following' === $tab ) {
			self::render_following( $user_id );
			return;
		}
		if ( 'saved' === $tab ) {
			SNSCS_Search_UI::render_saved( $user_id );
			return;
		}
		if ( 'lists' === $tab ) {
			SNSCS_Lists::render_member( $user_id );
			return;
		}
		if ( 'level' === $tab ) {
			SNSCS_Gamify::render_member( $user_id );
			return;
		}
		if ( 'foryou' === $tab ) {
			$all = SNSCS_Feed::items( array( 'user' => $user_id ) );
			echo SNSCS_Feed::render( array_slice( $all, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ), 'まだおすすめできる記事がありません。' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
			echo '<p class="snscs-member__pager">';
			if ( $page > 1 ) {
				echo '<a href="' . esc_url( self::member_url( array( 'tab' => $tab, 'pg' => $page - 1 ) ) ) . '">← 前へ</a> ';
			}
			if ( count( $all ) > $page * self::PER_PAGE ) {
				echo '<a href="' . esc_url( self::member_url( array( 'tab' => $tab, 'pg' => $page + 1 ) ) ) . '">次へ →</a>';
			}
			echo '</p>';
			return;
		}
		if ( 'timeline' === $tab ) {
			$items = self::timeline( $user_id, $page );
			$empty = 'フォローしている発信者の新しい記事とリポストが、ここに並びます。気になる発信者のページで「フォローする」を押してみましょう。';
		} else {
			$types = array( 'bookmarks' => self::BOOKMARK, 'likes' => self::LIKE, 'reposts' => self::REPOST );
			$items = self::reacted( $user_id, $types[ $tab ], $page );
			$empty = 'まだありません。';
		}
		$more  = count( $items ) > self::PER_PAGE;
		$items = array_slice( $items, 0, self::PER_PAGE );
		echo self::render_list( $items, $empty ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		if ( 'timeline' === $tab && ! $items ) {
			echo '<p><a class="snscs-button snscs-button--primary" href="' . esc_url( snscs_search_url() ) . '">発信者を探す</a></p>';
		}
		echo '<p class="snscs-member__pager">';
		if ( $page > 1 ) {
			echo '<a href="' . esc_url( self::member_url( array( 'tab' => $tab, 'pg' => $page - 1 ) ) ) . '">← 新しい記事</a> ';
		}
		if ( $more ) {
			echo '<a href="' . esc_url( self::member_url( array( 'tab' => $tab, 'pg' => $page + 1 ) ) ) . '">前の記事 →</a>';
		}
		echo '</p>';
	}

	private static function render_following( $user_id ) {
		$people = SNSCS_People::following_ids( $user_id );
		if ( $people ) {
			echo '<h2 class="snscs-single__heading">フォロー中の会員</h2><ul class="snscs-people">';
			foreach ( $people as $person ) {
				echo '<li><a href="' . esc_url( SNSCS_People::profile_url( $person ) ) . '"><span class="snscs-profile__avatar snscs-profile__avatar--small" aria-hidden="true">' . esc_html( mb_substr( SNSCS_People::nickname( $person ), 0, 1 ) ) . '</span>' . esc_html( SNSCS_People::nickname( $person ) ) . '</a></li>';
			}
			echo '</ul><h2 class="snscs-single__heading">フォロー中の発信者</h2>';
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT f.creator_id FROM ' . self::follows_table() . " f INNER JOIN {$wpdb->posts} p ON p.ID = f.creator_id AND p.post_status = 'publish' WHERE f.user_id = %d ORDER BY f.created_at DESC LIMIT 500", $user_id ) ) );
		if ( ! $ids ) {
			echo '<p class="snscs-help">まだ誰もフォローしていません。</p>';
			return;
		}
		SNSCS_Frontend::render_cards( SNSCS_Personalize::items( $ids ) );
	}

	private static function render_account() {
		if ( ! self::is_reader() ) {
			echo '<p>アカウントの変更・退会は、<a href="' . esc_url( snscs_portal_url() ) . '">発信者マイページ</a>から行えます。</p>';
			return;
		}
		?>
		<section class="snscs-panel snscs-danger">
			<h2 class="snscs-panel__title">退会する</h2>
			<p>退会すると、アカウントと、フォロー・いいね・リポスト・ブックマークの記録がすべて削除され、元に戻せません。</p>
			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>" class="snscs-form">
				<input type="hidden" name="action" value="snscs_member_withdraw">
				<?php wp_nonce_field( 'snscs_member_withdraw', 'snscs_nonce' ); ?>
				<p><label>確認のため、パスワードを入力してください<br><input type="password" name="password" required autocomplete="current-password"></label></p>
				<p><button type="submit" class="snscs-button">退会する</button></p>
			</form>
		</section>
		<?php
	}

	/* --------------------------------------------------------------------
	 * 点数（SNSCS_Scores）と不正対策（SNSCS_Integrity）用
	 * ------------------------------------------------------------------ */

	/**
	 * 発信者ごとの「このサイトでの反応」: フォロワー ＋ 記事への反応（いいね1・ブックマーク1・リポスト2）。
	 * 登録から3日以上たったアカウントだけを数え、本人の反応は数えない。
	 *
	 * @return array<int,float>
	 */
	public static function creator_values() {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::MIN_AGE_DAYS * DAY_IN_SECONDS );
		$own    = "LEFT JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = 'snscs_creator_id'";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$follows = $wpdb->get_results( $wpdb->prepare( 'SELECT f.creator_id, COUNT(*) AS n FROM ' . self::follows_table() . " f INNER JOIN {$wpdb->users} u ON u.ID = f.user_id AND u.user_registered <= %s {$own} WHERE um.meta_value IS NULL OR um.meta_value <> f.creator_id GROUP BY f.creator_id", $cutoff ), ARRAY_A );
		$reacts  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT am.meta_value AS creator_id, SUM(IF(r.type = %d, 2, 1)) AS n FROM ' . self::reactions_table() . " r
				INNER JOIN {$wpdb->users} u ON u.ID = r.user_id AND u.user_registered <= %s
				INNER JOIN {$wpdb->postmeta} am ON am.post_id = r.article_id AND am.meta_key = '_snscs_creator_id'
				{$own} WHERE um.meta_value IS NULL OR um.meta_value <> am.meta_value GROUP BY am.meta_value",
				self::REPOST,
				$cutoff
			),
			ARRAY_A
		);
		// phpcs:enable
		$out = array();
		foreach ( $follows as $row ) {
			$out[ (int) $row['creator_id'] ] = (float) $row['n'];
		}
		foreach ( $reacts as $row ) {
			$id         = (int) $row['creator_id'];
			$out[ $id ] = ( isset( $out[ $id ] ) ? $out[ $id ] : 0 ) + 0.3 * (float) $row['n'];
		}
		return $out;
	}

	/**
	 * フォローの水増しの疑い: フォロワー20人以上のうち8割以上が、ほかに何もしていない（この人しかフォローせず、反応もしない）アカウント。
	 *
	 * @return int[]
	 */
	public static function follow_farms() {
		global $wpdb;
		$follows   = self::follows_table();
		$reactions = self::reactions_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map(
			'intval',
			$wpdb->get_col(
				"SELECT f.creator_id FROM {$follows} f
				LEFT JOIN (SELECT user_id, COUNT(*) AS n FROM {$follows} GROUP BY user_id) a ON a.user_id = f.user_id
				LEFT JOIN (SELECT DISTINCT user_id FROM {$reactions}) r ON r.user_id = f.user_id
				GROUP BY f.creator_id HAVING COUNT(*) >= 20 AND SUM(a.n = 1 AND r.user_id IS NULL) >= COUNT(*) * 0.8"
			)
		);
	}
}
