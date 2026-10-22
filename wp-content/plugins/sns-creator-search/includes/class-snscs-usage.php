<?php
/**
 * 発信者自身の「このサイトの利用状況」（利用日数・利用時間）を測る。表示順位の信号に使う。
 *
 * 発信者がログインしてサイトのページを開いている間、小さなスクリプト（assets/js/snscs-usage.js）が
 *   ping : ページを開いた（その日の利用として数える）
 *   time : 前回からの利用秒数（画面に表示され、1分以内に操作があった時間だけ）
 * を送る。
 *
 * 自分の順位に関わるため、水増しできないように次の上限を設ける:
 *   - 1日に数える利用時間は DAILY_CAP 秒まで。利用日数は1日1回まで
 *   - 1回に送れる秒数は MAX_DELTA 秒まで
 *   - 前回の記録から実際に経った時間より多くは数えない（タブを何枚開いても増えない）
 *   - ログインした本人の記録しか受け付けない（WordPress の合言葉 nonce で確認）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Usage {

	const DAILY_CAP = 1800;
	const MAX_DELTA = 60;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_usage';
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta の書式に合わせ、各行の区切りや空白は崩さないこと。
		dbDelta(
			"CREATE TABLE {$table} (
  creator_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  page_views int(10) unsigned NOT NULL DEFAULT 0,
  active_seconds int(10) unsigned NOT NULL DEFAULT 0,
  last_seen int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (creator_id,day),
  KEY day (day)
) {$charset};"
		);
	}

	/**
	 * ログイン中の発信者で、発信者ページを持っている人のページにだけスクリプトを入れる。
	 */
	public static function enqueue() {
		if ( ! snscs_is_creator_user() || ! SNSCS_Submissions::creator_id_for_user( get_current_user_id() ) ) {
			return;
		}

		wp_enqueue_script( 'snscs-usage', SNSCS_URL . 'assets/js/snscs-usage.js', array(), SNSCS_VERSION, true );
		wp_add_inline_script(
			'snscs-usage',
			'window.snscsUsage = ' . wp_json_encode(
				array(
					'url'   => esc_url_raw( rest_url( 'snscs/v1/usage' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);
	}

	public static function register_route() {
		register_rest_route(
			'snscs/v1',
			'/usage',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive' ),
				'permission_callback' => function () {
					return snscs_is_creator_user();
				},
			)
		);
	}

	public static function receive( WP_REST_Request $request ) {
		$creator_id = SNSCS_Submissions::creator_id_for_user( get_current_user_id() );
		if ( $creator_id ) {
			if ( 'ping' === $request->get_param( 't' ) ) {
				self::record_ping( $creator_id );
			} elseif ( 'time' === $request->get_param( 't' ) ) {
				self::record_time( $creator_id, (int) $request->get_param( 's' ) );
			}
		}
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * ページを開いた: その日を「利用した日」にする。
	 */
	public static function record_ping( $creator_id, $now = null, $day = null ) {
		global $wpdb;

		$table = self::table();
		$now   = $now ? (int) $now : time();
		$day   = $day ? $day : current_time( 'Y-m-d' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (creator_id, day, page_views, last_seen) VALUES (%d, %s, 1, %d) ON DUPLICATE KEY UPDATE page_views = page_views + 1, last_seen = GREATEST(last_seen, %d)", $creator_id, $day, $now, $now ) );
	}

	/**
	 * 利用秒数を足す。1回に MAX_DELTA 秒まで、前回の記録から実際に経った時間まで、1日 DAILY_CAP 秒まで。
	 *
	 * @return int 実際に足した秒数。
	 */
	public static function record_time( $creator_id, $seconds, $now = null, $day = null ) {
		global $wpdb;

		$table = self::table();
		$now   = $now ? (int) $now : time();
		$day   = $day ? $day : current_time( 'Y-m-d' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT active_seconds, last_seen FROM {$table} WHERE creator_id = %d AND day = %s", $creator_id, $day ), ARRAY_A );
		if ( ! $row ) {
			return 0;
		}

		$elapsed = max( 0, $now - (int) $row['last_seen'] );
		$add     = min( max( 0, (int) $seconds ), self::MAX_DELTA, $elapsed, self::DAILY_CAP - (int) $row['active_seconds'] );
		if ( $add <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET active_seconds = active_seconds + %d, last_seen = %d WHERE creator_id = %d AND day = %s", $add, $now, $creator_id, $day ) );
		return $add;
	}

	/**
	 * 期間内の利用状況（発信者ID => 値）。最近の日ほど重く数えた値も返す。
	 *
	 * @param int   $days      直近何日分か。
	 * @param float $half_life 何日前の利用を半分に数えるか。
	 * @return array<int,array{days:int,seconds:int,weighted_days:float,weighted_seconds:float}>
	 */
	public static function totals( $days, $half_life ) {
		global $wpdb;

		$table = self::table();
		$today = current_time( 'Y-m-d' );
		$since = gmdate( 'Y-m-d', strtotime( $today . ' -' . ( max( 1, (int) $days ) - 1 ) . ' days' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT creator_id, COUNT(*) AS days, SUM(active_seconds) AS seconds,
					SUM(POW(0.5, DATEDIFF(%s, day) / %f)) AS weighted_days,
					SUM(active_seconds * POW(0.5, DATEDIFF(%s, day) / %f)) AS weighted_seconds
				FROM {$table} WHERE day >= %s AND page_views > 0 GROUP BY creator_id",
				$today,
				max( 1, (float) $half_life ),
				$today,
				max( 1, (float) $half_life ),
				$since
			),
			ARRAY_A
		);

		$totals = array();
		foreach ( $rows as $row ) {
			$totals[ (int) $row['creator_id'] ] = array(
				'days'             => (int) $row['days'],
				'seconds'          => (int) $row['seconds'],
				'weighted_days'    => (float) $row['weighted_days'],
				'weighted_seconds' => (float) $row['weighted_seconds'],
			);
		}
		return $totals;
	}

	/**
	 * 古い記録を消す（計算に使う期間より古いもの）。
	 */
	public static function purge_old( $days ) {
		global $wpdb;
		$table  = self::table();
		$before = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . max( 90, (int) $days ) . ' days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", $before ) );
	}

	public static function delete_for_creator( $creator_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'creator_id' => $creator_id ), array( '%d' ) );
	}
}
