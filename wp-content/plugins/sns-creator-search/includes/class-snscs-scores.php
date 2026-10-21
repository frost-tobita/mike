<?php
/**
 * 表示順位（おすすめ順）のための点数計算。
 *
 *   ① 紹介流入点   : 直近30日の紹介リンク経由の流入人数の「順位」を 0〜100点に換算
 *   ② フォロワー点 : 全SNSの合計フォロワー数の「順位」を 0〜100点に換算
 *   総合点 = (① × ①の重み + ② × ②の重み) ÷ (①の重み + ②の重み)   ※初期値は 1:1
 *
 * 順位の換算: 1位 = 100点、最下位 = 0点、同じ数の人は同じ点。
 *   点 = 自分より数が少ない人の人数 ÷ (全体の人数 − 1) × 100
 * 数そのものではなく順位を使うのは、フォロワー数の桁違いの差で
 * ①の効果が消えてしまわないようにするため。
 *
 * 計算は1時間ごと（WP-Cron）に全員分をまとめて行い、結果を専用テーブルに保存する。
 * 検索はこのテーブルの点数で並べるだけなので、人数が増えても速い。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Scores {

	const CRON_HOOK = 'snscs_recalculate_scores';

	const DEFAULT_WEIGHT_INFLOW    = 1;
	const DEFAULT_WEIGHT_FOLLOWERS = 1;
	const DEFAULT_INFLOW_DAYS      = 30;

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'recalculate' ) );
		add_action( self::CRON_HOOK . '_once', array( __CLASS__, 'recalculate' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'snscs_submission_approved', array( __CLASS__, 'schedule_soon' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_stats';
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta の書式に合わせ、各行の区切りや空白は崩さないこと。
		$sql = "CREATE TABLE {$table} (
  creator_id bigint(20) unsigned NOT NULL,
  ref_code varchar(16) DEFAULT NULL,
  inflow int(10) unsigned NOT NULL DEFAULT 0,
  followers bigint(20) unsigned NOT NULL DEFAULT 0,
  inflow_points double NOT NULL DEFAULT 0,
  follower_points double NOT NULL DEFAULT 0,
  score double NOT NULL DEFAULT 0,
  scored_at datetime DEFAULT NULL,
  PRIMARY KEY  (creator_id),
  UNIQUE KEY ref_code (ref_code),
  KEY score (score)
) {$charset};";

		dbDelta( $sql );
	}

	/* --------------------------------------------------------------------
	 * 設定値
	 * ------------------------------------------------------------------ */

	public static function weight_inflow() {
		return max( 0, (int) get_option( 'snscs_weight_inflow', self::DEFAULT_WEIGHT_INFLOW ) );
	}

	public static function weight_followers() {
		return max( 0, (int) get_option( 'snscs_weight_followers', self::DEFAULT_WEIGHT_FOLLOWERS ) );
	}

	public static function inflow_days() {
		return max( 1, (int) get_option( 'snscs_inflow_days', self::DEFAULT_INFLOW_DAYS ) );
	}

	/* --------------------------------------------------------------------
	 * 紹介コード
	 * ------------------------------------------------------------------ */

	/**
	 * 発信者の紹介コード。無ければ作る（一度作ったコードは変わらない）。
	 */
	public static function ref_code( $creator_id ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$code = $wpdb->get_var( $wpdb->prepare( "SELECT ref_code FROM {$table} WHERE creator_id = %d", $creator_id ) );
		if ( $code ) {
			return $code;
		}

		do {
			// 読み間違えやすい文字（0/O、1/l/I）は使わない。
			$code = '';
			$chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
			for ( $i = 0; $i < 8; $i++ ) {
				$code .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$taken = $wpdb->get_var( $wpdb->prepare( "SELECT creator_id FROM {$table} WHERE ref_code = %s", $code ) );
		} while ( $taken );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (creator_id, ref_code) VALUES (%d, %s) ON DUPLICATE KEY UPDATE ref_code = IF(ref_code IS NULL, VALUES(ref_code), ref_code)", $creator_id, $code ) );

		// 同時に作られた場合に備え、保存されている方を返す。
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_var( $wpdb->prepare( "SELECT ref_code FROM {$table} WHERE creator_id = %d", $creator_id ) );
	}

	public static function creator_for_code( $code ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT creator_id FROM {$table} WHERE ref_code = %s", $code ) );
	}

	/* --------------------------------------------------------------------
	 * 計算
	 * ------------------------------------------------------------------ */

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * 新しく掲載された人にもすぐ点数が付くよう、1分後に計算し直す。
	 */
	public static function schedule_soon() {
		if ( ! wp_next_scheduled( self::CRON_HOOK . '_once' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK . '_once' );
		}
	}

	/**
	 * 全員の点数を計算し直す。
	 *
	 * @return int 計算した人数。
	 */
	public static function recalculate() {
		global $wpdb;

		$creators = array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", SNSCS_Post_Types::POST_TYPE ) )
		);

		$accounts     = SNSCS_Accounts::table();
		$platforms    = array_keys( snscs_platforms() );
		$placeholders = implode( ',', array_fill( 0, count( $platforms ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT creator_id, SUM(followers) AS total FROM {$accounts} WHERE platform IN ({$placeholders}) GROUP BY creator_id", $platforms ), ARRAY_A );

		$all_followers = wp_list_pluck( $rows, 'total', 'creator_id' );
		$all_inflow    = SNSCS_Referrals::counts( self::inflow_days() );

		$followers = array();
		$inflow    = array();
		foreach ( $creators as $id ) {
			$followers[ $id ] = isset( $all_followers[ $id ] ) ? (int) $all_followers[ $id ] : 0;
			$inflow[ $id ]    = isset( $all_inflow[ $id ] ) ? (int) $all_inflow[ $id ] : 0;
		}

		$inflow_points   = self::rank_points( $inflow );
		$follower_points = self::rank_points( $followers );
		$wi              = self::weight_inflow();
		$wf              = self::weight_followers();
		$now             = current_time( 'mysql', true );
		$table           = self::table();

		foreach ( array_chunk( $creators, 500 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $id ) {
				$values[] = $wpdb->prepare(
					'(%d, %d, %d, %f, %f, %f, %s)',
					$id,
					$inflow[ $id ],
					$followers[ $id ],
					$inflow_points[ $id ],
					$follower_points[ $id ],
					self::combine( $inflow_points[ $id ], $follower_points[ $id ], $wi, $wf ),
					$now
				);
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "INSERT INTO {$table} (creator_id, inflow, followers, inflow_points, follower_points, score, scored_at) VALUES " . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE inflow = VALUES(inflow), followers = VALUES(followers), inflow_points = VALUES(inflow_points), follower_points = VALUES(follower_points), score = VALUES(score), scored_at = VALUES(scored_at)' );
		}

		SNSCS_Referrals::purge_old();
		update_option( 'snscs_scores_updated', time() );

		return count( $creators );
	}

	/**
	 * 数の「順位」を 0〜100点に換算する。
	 * 点 = 自分より数が少ない人の人数 ÷ (全体の人数 − 1) × 100
	 *
	 * @param array<int,int> $values 発信者ID => 数。
	 * @return array<int,float> 発信者ID => 点。
	 */
	public static function rank_points( array $values ) {
		$n = count( $values );
		if ( $n <= 1 ) {
			return array_map(
				function () {
					return 100.0;
				},
				$values
			);
		}

		$sorted = array_values( $values );
		sort( $sorted, SORT_NUMERIC );

		// 数ごとに「それより少ない人の人数」を求める（同じ数なら同じ点）。
		$below = array();
		foreach ( $sorted as $index => $value ) {
			if ( ! isset( $below[ $value ] ) ) {
				$below[ $value ] = $index;
			}
		}

		$points = array();
		foreach ( $values as $id => $value ) {
			$points[ $id ] = round( $below[ $value ] / ( $n - 1 ) * 100, 4 );
		}
		return $points;
	}

	/**
	 * ①と②を重み付きで合わせる。
	 */
	public static function combine( $inflow_points, $follower_points, $wi, $wf ) {
		if ( $wi + $wf <= 0 ) {
			return 0.0;
		}
		return round( ( $inflow_points * $wi + $follower_points * $wf ) / ( $wi + $wf ), 4 );
	}

	/* --------------------------------------------------------------------
	 * 表示用
	 * ------------------------------------------------------------------ */

	/**
	 * 発信者1人の点数と順位（掲載中の発信者の中で）。
	 *
	 * @return array|null
	 */
	public static function standing( $creator_id ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE creator_id = %d AND scored_at IS NOT NULL", $creator_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}

		$from = "FROM {$table} st INNER JOIN {$wpdb->posts} p ON p.ID = st.creator_id AND p.post_status = 'publish' AND p.post_type = %s WHERE st.scored_at IS NOT NULL";
		$rank = function ( $column, $value ) use ( $wpdb, $from ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			return 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from} AND st.{$column} > %f", SNSCS_Post_Types::POST_TYPE, $value ) );
		};

		return array(
			'inflow'          => (int) $row['inflow'],
			'followers'       => (int) $row['followers'],
			'inflow_points'   => (float) $row['inflow_points'],
			'follower_points' => (float) $row['follower_points'],
			'score'           => (float) $row['score'],
			'rank'            => $rank( 'score', $row['score'] ),
			'inflow_rank'     => $rank( 'inflow', $row['inflow'] ),
			'followers_rank'  => $rank( 'followers', $row['followers'] ),
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			'total'           => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", SNSCS_Post_Types::POST_TYPE ) ),
		);
	}

	/**
	 * 総合点の上位（設定画面の確認用）。
	 */
	public static function top( $limit = 20 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT st.*, p.post_title FROM {$table} st INNER JOIN {$wpdb->posts} p ON p.ID = st.creator_id AND p.post_status = 'publish' AND p.post_type = %s WHERE st.scored_at IS NOT NULL ORDER BY st.score DESC, st.followers DESC, st.creator_id DESC LIMIT %d", SNSCS_Post_Types::POST_TYPE, $limit ), ARRAY_A );
	}
}
