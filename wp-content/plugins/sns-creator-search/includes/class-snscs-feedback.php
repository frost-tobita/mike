<?php
/**
 * 検索結果での行動の記録と、そこから出す「クリック率」「満足度」（学習のもとになるデータ）。
 *
 * 記録すること（誰が検索したかは残さない。検索1回ごとのランダムな番号だけ）:
 *   表示   : 検索結果の何番目に、どの発信者を出したか（サーバー側で記録。ロボットは除く）
 *   クリック: どの発信者を押したか
 *   満足   : 押したあと、そのページを30秒以上見た、またはSNS・リンクへ進んだ
 *   すぐ戻る: 押して15秒以内に検索結果へ戻ってきた（期待と違った）
 *
 * 毎晩の集計:
 *   表示位置ごとのクリック率（上ほど押されやすい「位置のえこひいき」）を求め、
 *   発信者ごとに「期待クリック数 = Σ 各位置での表示回数 × その位置のクリック率」を出す。
 *   クリック率の点（COEC）= (クリック数 + 5) ÷ (期待クリック数 + 5)   … 1より大きければ、位置のわりによく押される
 *   満足度 = (満足したクリック + 5 × 全体の満足率) ÷ (クリック数 + 5)
 *   （＋5 は、データが少ない人を全体の平均に寄せるため）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Feedback {

	const KEEP_DAYS = 30;
	const PRIOR     = 5;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_creator' ), 30 );
		add_action( 'snscs_nightly_engine', array( __CLASS__, 'aggregate' ), 10 );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_creator' ) );
	}

	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_search_events';
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_creator_feedback';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$events  = self::events_table();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$events} (
  qid char(12) NOT NULL,
  day date NOT NULL,
  creator_id bigint(20) unsigned NOT NULL,
  pos smallint(5) unsigned NOT NULL,
  bucket char(1) NOT NULL DEFAULT 'a',
  explore tinyint(1) unsigned NOT NULL DEFAULT 0,
  rel float NOT NULL DEFAULT 0,
  has_q tinyint(1) unsigned NOT NULL DEFAULT 0,
  clicked tinyint(1) unsigned NOT NULL DEFAULT 0,
  good tinyint(1) unsigned NOT NULL DEFAULT 0,
  bounce tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (qid,creator_id),
  KEY day (day),
  KEY creator_day (creator_id,day)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$table} (
  creator_id bigint(20) unsigned NOT NULL,
  impressions int(10) unsigned NOT NULL DEFAULT 0,
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  expected double NOT NULL DEFAULT 0,
  good int(10) unsigned NOT NULL DEFAULT 0,
  bounce int(10) unsigned NOT NULL DEFAULT 0,
  coec double NOT NULL DEFAULT 1,
  sat double NOT NULL DEFAULT 0,
  authority double NOT NULL DEFAULT 0,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (creator_id)
) {$charset};"
		);
	}

	/* --------------------------------------------------------------------
	 * 記録
	 * ------------------------------------------------------------------ */

	public static function new_qid() {
		return substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
	}

	public static function token( $qid, $day = null ) {
		return substr( hash_hmac( 'sha256', 'sfb|' . $qid . '|' . ( $day ? $day : current_time( 'Y-m-d' ) ), wp_salt( 'nonce' ) ), 0, 16 );
	}

	/**
	 * 検索結果の表示を記録する。
	 *
	 * @param string $qid   検索1回の番号。
	 * @param array  $rows  [creator_id, pos, explore, rel] の配列。
	 * @param string $bucket A/Bテストの組（a / b）。
	 * @param bool   $has_q  キーワードがある検索か。
	 */
	public static function log_impressions( $qid, array $rows, $bucket, $has_q ) {
		global $wpdb;
		if ( ! $rows || SNSCS_Referrals::is_bot_request() || current_user_can( 'edit_others_posts' ) ) {
			return false;
		}
		$rate = (int) SNSCS_Learner::setting( 'log_rate' );
		if ( $rate < 100 && wp_rand( 1, 100 ) > $rate ) {
			return false;
		}
		$day    = current_time( 'Y-m-d' );
		$values = array();
		foreach ( $rows as $row ) {
			$values[] = $wpdb->prepare( '(%s, %s, %d, %d, %s, %d, %f, %d)', $qid, $day, $row[0], $row[1], $bucket, $row[2] ? 1 : 0, $row[3], $has_q ? 1 : 0 );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'INSERT IGNORE INTO ' . self::events_table() . ' (qid, day, creator_id, pos, bucket, explore, rel, has_q) VALUES ' . implode( ',', $values ) );
		return true;
	}

	public static function register_routes() {
		register_rest_route(
			'snscs/v1',
			'/sfb',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function receive( WP_REST_Request $request ) {
		global $wpdb;
		$qid  = preg_replace( '/[^a-f0-9]/', '', (string) $request->get_param( 'q' ) );
		$tok  = (string) $request->get_param( 'tok' );
		$cid  = absint( $request->get_param( 'c' ) );
		$type = sanitize_key( (string) $request->get_param( 't' ) );
		$ok   = 12 === strlen( $qid ) && $cid && ! SNSCS_Referrals::is_bot_request()
			&& ( hash_equals( self::token( $qid ), $tok ) || hash_equals( self::token( $qid, gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 day' ) ) ), $tok ) );
		if ( $ok ) {
			$table = self::events_table();
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( 'click' === $type ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET clicked = 1 WHERE qid = %s AND creator_id = %d", $qid, $cid ) );
			} elseif ( 'good' === $type ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET good = 1, bounce = 0 WHERE qid = %s AND creator_id = %d AND clicked = 1", $qid, $cid ) );
			} elseif ( 'bounce' === $type ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET bounce = 1 WHERE qid = %s AND creator_id = %d AND clicked = 1 AND good = 0", $qid, $cid ) );
			}
			// phpcs:enable
		}
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * 検索ページ用のスクリプト（サジェスト・クリックの記録）。
	 */
	public static function enqueue_search( $qid = '' ) {
		wp_enqueue_script( 'snscs-search', SNSCS_URL . 'assets/js/snscs-search.js', array(), SNSCS_VERSION, true );
		wp_add_inline_script(
			'snscs-search',
			'window.snscsSearch = ' . wp_json_encode(
				array(
					'sfb'     => esc_url_raw( rest_url( 'snscs/v1/sfb' ) ),
					'suggest' => esc_url_raw( rest_url( 'snscs/v1/suggest' ) ),
					'qid'     => $qid,
					'tok'     => $qid ? self::token( $qid ) : '',
				)
			) . ';',
			'before'
		);
	}

	/**
	 * 発信者ページ: 検索結果から来た人が満足したかを送るため、同じスクリプトを読み込む。
	 */
	public static function enqueue_creator() {
		if ( is_singular( SNSCS_Post_Types::POST_TYPE ) || is_singular( 'page' ) ) {
			// 固定ページは、検索窓（サジェスト）がある可能性があるため。中身が無ければ何もしない。
			if ( ! wp_script_is( 'snscs-search', 'enqueued' ) ) {
				self::enqueue_search();
			}
		}
	}

	/* --------------------------------------------------------------------
	 * 毎晩の集計
	 * ------------------------------------------------------------------ */

	/**
	 * 表示位置ごとのクリック率（位置のえこひいき）。
	 *
	 * @return array<int,float> 位置 => クリック率。
	 */
	public static function position_ctr() {
		$saved = get_option( 'snscs_pos_ctr' );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * @param int   $pos   表示位置（1から）。
	 * @param array $curve position_ctr()。
	 */
	public static function ctr_at( $pos, array $curve ) {
		if ( isset( $curve[ $pos ] ) ) {
			return $curve[ $pos ];
		}
		// データが無い位置は、1 ÷ 位置 の形で近づける（下ほど押されにくい）。
		$top = isset( $curve[1] ) ? $curve[1] : 0.2;
		return $top / max( 1, $pos );
	}

	/**
	 * @return int 集計した発信者の数。
	 */
	public static function aggregate() {
		global $wpdb;
		$events = self::events_table();
		$table  = self::table();
		$since  = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . self::KEEP_DAYS . ' days' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE day < %s", $since ) );

		// 位置ごとのクリック率（お試し表示の分は除く。位置が下ほど小さくなるようにならす）。
		$rows  = $wpdb->get_results( "SELECT pos, COUNT(*) AS imp, SUM(clicked) AS clk FROM {$events} WHERE explore = 0 AND pos <= 60 GROUP BY pos ORDER BY pos", ARRAY_A );
		$curve = array();
		$prev  = 1.0;
		foreach ( $rows as $row ) {
			$imp                         = (int) $row['imp'];
			$base                        = 0.2 / max( 1, (int) $row['pos'] );
			$ctr                         = ( (int) $row['clk'] + 20 * $base ) / ( $imp + 20 );
			$ctr                         = min( $prev, $ctr );
			$curve[ (int) $row['pos'] ]  = round( $ctr, 6 );
			$prev                        = $ctr;
		}
		update_option( 'snscs_pos_ctr', $curve, false );

		$avg_sat = (float) $wpdb->get_var( "SELECT SUM(good) / GREATEST(1, SUM(clicked)) FROM {$events}" );
		$agg     = $wpdb->get_results( "SELECT creator_id, pos, COUNT(*) AS imp, SUM(clicked) AS clk, SUM(good) AS good, SUM(bounce) AS bounce FROM {$events} GROUP BY creator_id, pos", ARRAY_A );
		$by      = array();
		foreach ( $agg as $row ) {
			$id = (int) $row['creator_id'];
			if ( ! isset( $by[ $id ] ) ) {
				$by[ $id ] = array( 'imp' => 0, 'clk' => 0, 'exp' => 0.0, 'good' => 0, 'bounce' => 0 );
			}
			$by[ $id ]['imp']    += (int) $row['imp'];
			$by[ $id ]['clk']    += (int) $row['clk'];
			$by[ $id ]['good']   += (int) $row['good'];
			$by[ $id ]['bounce'] += (int) $row['bounce'];
			$by[ $id ]['exp']    += (int) $row['imp'] * self::ctr_at( (int) $row['pos'], $curve );
		}

		$now    = current_time( 'mysql', true );
		$values = array();
		foreach ( $by as $id => $d ) {
			$coec     = ( $d['clk'] + self::PRIOR ) / ( $d['exp'] + self::PRIOR );
			$sat      = ( $d['good'] - 0.5 * $d['bounce'] + self::PRIOR * $avg_sat ) / ( $d['clk'] + self::PRIOR );
			$values[] = $wpdb->prepare( '(%d, %d, %d, %f, %d, %d, %f, %f, %s)', $id, $d['imp'], $d['clk'], $d['exp'], $d['good'], $d['bounce'], $coec, $sat, $now );
		}
		// 期間内に表示が無くなった人の数字は、ふつう（中立）に戻す。
		$wpdb->query( "UPDATE {$table} SET impressions = 0, clicks = 0, expected = 0, good = 0, bounce = 0, coec = 1, sat = " . sprintf( '%.5F', $avg_sat ) );
		foreach ( array_chunk( $values, 500 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$table} (creator_id, impressions, clicks, expected, good, bounce, coec, sat, updated_at) VALUES " . implode( ',', $chunk ) . ' ON DUPLICATE KEY UPDATE impressions = VALUES(impressions), clicks = VALUES(clicks), expected = VALUES(expected), good = VALUES(good), bounce = VALUES(bounce), coec = VALUES(coec), sat = VALUES(sat), updated_at = VALUES(updated_at)' );
		}
		// phpcs:enable
		update_option( 'snscs_avg_sat', $avg_sat, false );
		return count( $by );
	}

	/**
	 * 発信者ごとの数字（ランキング・お試し表示で使う）。
	 *
	 * @param int[] $ids 発信者。
	 * @return array<int,array{impressions:int,clicks:int,coec:float,sat:float,authority:float}>
	 */
	public static function for_creators( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return array();
		}
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT creator_id, impressions, clicks, coec, sat, authority FROM {$table} WHERE creator_id IN (" . implode( ',', $ids ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$out   = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['creator_id'] ] = array(
				'impressions' => (int) $row['impressions'],
				'clicks'      => (int) $row['clicks'],
				'coec'        => (float) $row['coec'],
				'sat'         => (float) $row['sat'],
				'authority'   => (float) $row['authority'],
			);
		}
		return $out;
	}

	/**
	 * 直近の検索の様子（管理画面用）。
	 */
	public static function summary( $days = 7 ) {
		global $wpdb;
		$events = self::events_table();
		$since  = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . ( $days - 1 ) . ' days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(DISTINCT qid) AS searches, COUNT(*) AS impressions, SUM(clicked) AS clicks, SUM(good) AS good, SUM(bounce) AS bounce FROM {$events} WHERE day >= %s", $since ), ARRAY_A );
	}

	public static function delete_for_creator( $post_id ) {
		global $wpdb;
		if ( SNSCS_Post_Types::POST_TYPE === get_post_type( $post_id ) ) {
			$wpdb->delete( self::events_table(), array( 'creator_id' => $post_id ), array( '%d' ) );
			$wpdb->delete( self::table(), array( 'creator_id' => $post_id ), array( '%d' ) );
		}
	}
}
