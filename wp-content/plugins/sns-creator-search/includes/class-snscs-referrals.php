<?php
/**
 * 発信者ごとの「紹介リンク」と、リンク経由の流入の記録。
 *
 *   紹介リンク: https://サイト/r/コード/
 *   アクセスすると流入を1件記録し、その発信者のページへ移動する。
 *
 * 表示順位に影響するため、次のものは数えない:
 *   - 同じ人が同じ日に何度もアクセスした分（1日1人1回まで）
 *   - 検索エンジンやSNSのリンク確認用ロボット
 *   - ブラウザの先読み（プリフェッチ）
 *   - 発信者本人のアクセス
 * 訪問者のIPアドレスは保存せず、元に戻せない文字列（ハッシュ）にして記録する。
 * ハッシュには日付も混ぜるため、同じ人の別の日の訪問を結びつけることもできない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Referrals {

	const QUERY_VAR = 'snscs_ref';
	const PATH      = 'r';

	/** 記録を残す日数（集計期間より長めに残す）。 */
	const KEEP_DAYS = 90;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 1 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_clicks';
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta の書式に合わせ、各行の区切りや空白は崩さないこと。
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  creator_id bigint(20) unsigned NOT NULL,
  visitor_hash char(64) NOT NULL,
  clicked_on date NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY creator_visitor_day (creator_id,visitor_hash,clicked_on),
  KEY clicked_on_creator (clicked_on,creator_id)
) {$charset};";

		dbDelta( $sql );

		// 紹介リンクのURLを有効にするため、次の読み込みでパーマリンクを更新する。
		update_option( 'snscs_flush_rewrite', 1 );
	}

	public static function add_rewrite_rule() {
		add_rewrite_rule( '^' . self::PATH . '/([A-Za-z0-9]{4,16})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public static function maybe_flush_rewrite() {
		if ( get_option( 'snscs_flush_rewrite' ) ) {
			delete_option( 'snscs_flush_rewrite' );
			flush_rewrite_rules( false );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * 発信者の紹介リンク（まだコードが無ければ作る）。
	 */
	public static function link_for( $creator_id ) {
		$code = SNSCS_Scores::ref_code( $creator_id );
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/' . self::PATH . '/' . $code . '/' );
		}
		return add_query_arg( self::QUERY_VAR, $code, home_url( '/' ) );
	}

	/**
	 * 紹介リンクへのアクセスを処理する: 記録して発信者ページへ移動。
	 */
	public static function handle() {
		$code = get_query_var( self::QUERY_VAR );
		if ( '' === $code || ! is_string( $code ) ) {
			return;
		}

		$creator_id = SNSCS_Scores::creator_for_code( $code );
		if ( ! $creator_id || 'publish' !== get_post_status( $creator_id ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		self::count_visit( $creator_id, $code );

		nocache_headers();
		wp_safe_redirect( get_permalink( $creator_id ), 302 );
		exit;
	}

	/**
	 * 発信者が配ったリンク（紹介リンク・マイリンク）からの訪問を数える。
	 */
	public static function count_visit( $creator_id, $code ) {
		if ( self::should_count( $creator_id ) ) {
			self::record( $creator_id, self::visitor_hash() );
		}
		// この人がのちにメンバーとして登録したときに「招待」として結びつけるため（SNSCS_Invites）。
		do_action( 'snscs_referral_visit', $creator_id, $code );
	}

	/**
	 * 流入を1件記録する。同じ訪問者・同じ日の2回目以降は無視される。
	 *
	 * @return bool 新しく記録されたか。
	 */
	public static function record( $creator_id, $visitor_hash, $date = null ) {
		global $wpdb;

		$table = self::table();
		$date  = $date ? $date : current_time( 'Y-m-d' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (creator_id, visitor_hash, clicked_on, created_at) VALUES (%d, %s, %s, %s)", $creator_id, $visitor_hash, $date, current_time( 'mysql', true ) ) );

		return 1 === (int) $inserted;
	}

	/**
	 * 期間内の流入人数（発信者ID => 人数）。
	 *
	 * @param int $days 直近何日分か。
	 * @return array<int,int>
	 */
	public static function counts( $days ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT creator_id, COUNT(*) AS total FROM {$table} WHERE clicked_on >= %s GROUP BY creator_id", self::since( $days ) ), ARRAY_A );

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (int) $row['creator_id'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * 期間内の流入人数を、最近の日ほど重く数えたもの（発信者ID => 重み付きの人数）。
	 *
	 * @param int   $days      直近何日分か。
	 * @param float $half_life 何日前の流入を半分に数えるか。
	 * @return array<int,float>
	 */
	public static function weighted_counts( $days, $half_life ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT creator_id, SUM(POW(0.5, DATEDIFF(%s, clicked_on) / %f)) AS total FROM {$table} WHERE clicked_on >= %s GROUP BY creator_id", current_time( 'Y-m-d' ), max( 1, (float) $half_life ), self::since( $days ) ), ARRAY_A );

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (int) $row['creator_id'] ] = (float) $row['total'];
		}
		return $counts;
	}

	/**
	 * 1人の発信者の流入人数（直近 $days 日）。
	 */
	public static function count_for( $creator_id, $days ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE creator_id = %d AND clicked_on >= %s", $creator_id, self::since( $days ) ) );
	}

	/**
	 * 古い記録を消す（個人に結びつかない形とはいえ、必要以上に残さない）。
	 */
	public static function purge_old() {
		global $wpdb;
		$table = self::table();
		$keep  = max( self::KEEP_DAYS, SNSCS_Scores::inflow_days() );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE clicked_on < %s", self::since( $keep ) ) );
	}

	/**
	 * 直近 $days 日の初日（今日を含めて $days 日）。
	 */
	private static function since( $days ) {
		$today = new DateTimeImmutable( current_time( 'Y-m-d' ) );
		return $today->modify( '-' . max( 0, (int) $days - 1 ) . ' days' )->format( 'Y-m-d' );
	}

	private static function should_count( $creator_id ) {
		$count = true;

		// ブラウザの先読み。
		$purpose = isset( $_SERVER['HTTP_SEC_PURPOSE'] ) ? $_SERVER['HTTP_SEC_PURPOSE'] : ( isset( $_SERVER['HTTP_PURPOSE'] ) ? $_SERVER['HTTP_PURPOSE'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( false !== stripos( (string) $purpose, 'prefetch' ) ) {
			$count = false;
		}

		// ロボット（SNSのリンクカード作成用を含む）。
		if ( self::is_bot_request() ) {
			$count = false;
		}

		// 発信者本人。
		if ( is_user_logged_in() && (int) get_post_field( 'post_author', $creator_id ) === get_current_user_id() ) {
			$count = false;
		}

		return (bool) apply_filters( 'snscs_count_referral', $count, $creator_id );
	}

	/**
	 * ロボット（検索エンジン、SNSのリンクカード作成、自動ツールなど）からのアクセスか。
	 */
	public static function is_bot_request() {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return '' === $agent || (bool) preg_match( '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|headless|curl|wget|python|httpclient|java\//i', $agent );
	}

	/**
	 * 訪問者を区別するための、元に戻せない文字列（日付入り）。
	 */
	public static function visitor_hash() {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ip    = (string) apply_filters( 'snscs_visitor_ip', $ip );
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// 日付も混ぜて、別の日の訪問どうしを結びつけられないようにする。
		return hash_hmac( 'sha256', $ip . '|' . $agent . '|' . current_time( 'Y-m-d' ), wp_salt( 'auth' ) );
	}
}
