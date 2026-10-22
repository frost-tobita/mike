<?php
/**
 * 発信者ページ・発信者の記事の「閲覧」「滞在時間」「リンクのクリック」を測る。
 *
 * ページを開くと、小さなスクリプト（assets/js/snscs-track.js）が次を送る:
 *   view  : ページを表示した（同じ人・同じ発信者は1日1回だけ数える）
 *   dwell : ページを実際に見ていた秒数（画面に表示され、操作があった時間だけ。最大600秒）
 *   out   : SNSや外部リンクを押した（同じ人・同じ発信者は1日1回だけ数える）
 *
 * 数えないもの: ロボット（スクリプトを動かさない）、発信者本人、運営のアカウント。
 * 訪問者はIPアドレスを保存せず、日付入りのハッシュで区別する（紹介リンクと同じ）。
 *
 * 大規模でも記録が膨らまないよう、集計は「発信者×日」の1行にまとめる（snscs_daily）。
 * 重複を防ぐための1人ごとの記録（snscs_visits）は2日で消す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Tracking {

	const MAX_DWELL   = 600;
	const TOKEN_HOURS = 3;
	const KEEP_VISITS = 2;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function visits_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_visits';
	}

	public static function daily_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_daily';
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$visits  = self::visits_table();
		$daily   = self::daily_table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta の書式に合わせ、各行の区切りや空白は崩さないこと。
		dbDelta(
			"CREATE TABLE {$visits} (
  creator_id bigint(20) unsigned NOT NULL,
  visitor_hash char(64) NOT NULL,
  day date NOT NULL,
  dwell smallint(5) unsigned NOT NULL DEFAULT 0,
  article tinyint(1) unsigned NOT NULL DEFAULT 0,
  out_clicked tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (creator_id,visitor_hash,day),
  KEY day (day)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$daily} (
  creator_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  views int(10) unsigned NOT NULL DEFAULT 0,
  article_views int(10) unsigned NOT NULL DEFAULT 0,
  dwell_sum int(10) unsigned NOT NULL DEFAULT 0,
  dwell_count int(10) unsigned NOT NULL DEFAULT 0,
  out_clicks int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (creator_id,day),
  KEY day (day)
) {$charset};"
		);
	}

	/* --------------------------------------------------------------------
	 * ページ側
	 * ------------------------------------------------------------------ */

	/**
	 * 今見ているページが、どの発信者のどんなページか。
	 *
	 * @return array{0:int,1:string}|null [発信者ID, 'profile' または 'article']
	 */
	private static function current_target() {
		if ( is_singular( SNSCS_Post_Types::POST_TYPE ) ) {
			return array( (int) get_queried_object_id(), 'profile' );
		}
		if ( is_singular( SNSCS_Articles::POST_TYPE ) ) {
			$creator_id = SNSCS_Articles::creator_of( get_queried_object_id() );
			return $creator_id ? array( $creator_id, 'article' ) : null;
		}
		return null;
	}

	public static function enqueue() {
		$target = self::current_target();
		if ( ! $target ) {
			return;
		}
		list( $creator_id, $kind ) = $target;

		// 本人と運営のアクセスは数えない。
		if ( current_user_can( 'edit_others_posts' ) || ( is_user_logged_in() && (int) get_post_field( 'post_author', $creator_id ) === get_current_user_id() ) ) {
			return;
		}

		$hour = (int) floor( time() / HOUR_IN_SECONDS );
		wp_enqueue_script( 'snscs-track', SNSCS_URL . 'assets/js/snscs-track.js', array(), SNSCS_VERSION, true );
		wp_add_inline_script(
			'snscs-track',
			'window.snscsTrack = ' . wp_json_encode(
				array(
					'url'     => esc_url_raw( rest_url( 'snscs/v1/track' ) ),
					'creator' => $creator_id,
					'kind'    => $kind,
					'hour'    => $hour,
					'token'   => self::token( $creator_id, $kind, $hour ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * ページを表示したときに発行する合言葉。外から適当に送られた記録を受け付けないため。
	 */
	private static function token( $creator_id, $kind, $hour ) {
		return substr( hash_hmac( 'sha256', $creator_id . '|' . $kind . '|' . $hour, wp_salt( 'nonce' ) ), 0, 20 );
	}

	/* --------------------------------------------------------------------
	 * 受け取り側
	 * ------------------------------------------------------------------ */

	public static function register_route() {
		register_rest_route(
			'snscs/v1',
			'/track',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function receive( WP_REST_Request $request ) {
		$creator_id = absint( $request->get_param( 'c' ) );
		$kind       = 'article' === $request->get_param( 'k' ) ? 'article' : 'profile';
		$hour       = (int) $request->get_param( 'h' );
		$type       = sanitize_key( (string) $request->get_param( 't' ) );
		$now_hour   = (int) floor( time() / HOUR_IN_SECONDS );

		$valid = $creator_id
			&& $hour <= $now_hour && $hour > $now_hour - self::TOKEN_HOURS
			&& hash_equals( self::token( $creator_id, $kind, $hour ), (string) $request->get_param( 'tok' ) )
			&& 'publish' === get_post_status( $creator_id )
			&& ! SNSCS_Referrals::is_bot_request();

		if ( $valid ) {
			$visitor = SNSCS_Referrals::visitor_hash();
			if ( 'view' === $type ) {
				self::record_view( $creator_id, $visitor, 'article' === $kind );
			} elseif ( 'dwell' === $type ) {
				self::record_dwell( $creator_id, $visitor, (int) $request->get_param( 's' ) );
			} elseif ( 'out' === $type ) {
				self::record_out( $creator_id, $visitor );
			}
		}

		// 結果は返さない（記録したかどうかを外から探れないようにする）。
		return new WP_REST_Response( null, 204 );
	}

	public static function record_view( $creator_id, $visitor, $is_article = false, $day = null ) {
		global $wpdb;

		$visits = self::visits_table();
		$day    = $day ? $day : current_time( 'Y-m-d' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$new = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$visits} (creator_id, visitor_hash, day, article) VALUES (%d, %s, %s, %d)", $creator_id, $visitor, $day, $is_article ? 1 : 0 ) );
		if ( $new ) {
			self::bump( $creator_id, $day, array( 'views' => 1, 'article_views' => $is_article ? 1 : 0 ) );
		} elseif ( $is_article ) {
			// 同じ日にプロフィールを見たあと記事を読んだ場合も、記事の閲覧として1回数える。
			$first = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$visits} SET article = 1 WHERE creator_id = %d AND visitor_hash = %s AND day = %s AND article = 0", $creator_id, $visitor, $day ) );
			if ( $first ) {
				self::bump( $creator_id, $day, array( 'article_views' => 1 ) );
			}
		}
		// phpcs:enable
	}

	public static function record_dwell( $creator_id, $visitor, $seconds, $day = null ) {
		global $wpdb;

		$visits  = self::visits_table();
		$day     = $day ? $day : current_time( 'Y-m-d' );
		$seconds = min( self::MAX_DWELL, max( 0, (int) $seconds ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT dwell FROM {$visits} WHERE creator_id = %d AND visitor_hash = %s AND day = %s", $creator_id, $visitor, $day ) );
		if ( null === $current || $seconds <= (int) $current ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$visits} SET dwell = %d WHERE creator_id = %d AND visitor_hash = %s AND day = %s", $seconds, $creator_id, $visitor, $day ) );
		self::bump(
			$creator_id,
			$day,
			array(
				'dwell_sum'   => $seconds - (int) $current,
				'dwell_count' => 0 === (int) $current ? 1 : 0,
			)
		);
	}

	public static function record_out( $creator_id, $visitor, $day = null ) {
		global $wpdb;

		$visits = self::visits_table();
		$day    = $day ? $day : current_time( 'Y-m-d' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$first = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$visits} SET out_clicked = 1 WHERE creator_id = %d AND visitor_hash = %s AND day = %s AND out_clicked = 0", $creator_id, $visitor, $day ) );
		if ( $first ) {
			self::bump( $creator_id, $day, array( 'out_clicks' => 1 ) );
		}
	}

	/**
	 * 「発信者×日」の集計に足す。
	 */
	private static function bump( $creator_id, $day, array $add ) {
		global $wpdb;

		$daily   = self::daily_table();
		$columns = array( 'views', 'article_views', 'dwell_sum', 'dwell_count', 'out_clicks' );
		$values  = array();
		$updates = array();
		foreach ( $columns as $column ) {
			$values[] = isset( $add[ $column ] ) ? (int) $add[ $column ] : 0;
			if ( ! empty( $add[ $column ] ) ) {
				$updates[] = "{$column} = {$column} + " . (int) $add[ $column ];
			}
		}
		if ( ! $updates ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$daily} (creator_id, day, " . implode( ', ', $columns ) . ') VALUES (%d, %s, %d, %d, %d, %d, %d) ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates ), array_merge( array( $creator_id, $day ), $values ) ) );
	}

	/* --------------------------------------------------------------------
	 * 集計の読み出し
	 * ------------------------------------------------------------------ */

	/**
	 * 期間内の集計（発信者ID => 値）。最近の日ほど重く数えた閲覧数も返す。
	 *
	 * @param int   $days      直近何日分か。
	 * @param float $half_life 何日前の閲覧を半分に数えるか。
	 * @return array<int,array{views:int,weighted_views:float,article_views:int,dwell_sum:int,dwell_count:int,out_clicks:int}>
	 */
	public static function totals( $days, $half_life ) {
		global $wpdb;

		$daily = self::daily_table();
		$today = current_time( 'Y-m-d' );
		$since = gmdate( 'Y-m-d', strtotime( $today . ' -' . ( max( 1, (int) $days ) - 1 ) . ' days' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT creator_id, SUM(views) AS views, SUM(views * POW(0.5, DATEDIFF(%s, day) / %f)) AS weighted_views, SUM(article_views) AS article_views, SUM(dwell_sum) AS dwell_sum, SUM(dwell_count) AS dwell_count, SUM(out_clicks) AS out_clicks FROM {$daily} WHERE day >= %s GROUP BY creator_id",
				$today,
				max( 1, (float) $half_life ),
				$since
			),
			ARRAY_A
		);

		$totals = array();
		foreach ( $rows as $row ) {
			$totals[ (int) $row['creator_id'] ] = array(
				'views'          => (int) $row['views'],
				'weighted_views' => (float) $row['weighted_views'],
				'article_views'  => (int) $row['article_views'],
				'dwell_sum'      => (int) $row['dwell_sum'],
				'dwell_count'    => (int) $row['dwell_count'],
				'out_clicks'     => (int) $row['out_clicks'],
			);
		}
		return $totals;
	}

	/**
	 * 重複防止用の1人ごとの記録を消す（集計は snscs_daily に残っている）。
	 */
	public static function purge_old() {
		global $wpdb;
		$visits = self::visits_table();
		$before = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . self::KEEP_VISITS . ' days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$visits} WHERE day < %s", $before ) );
	}

	public static function delete_for_creator( $creator_id ) {
		global $wpdb;
		$wpdb->delete( self::visits_table(), array( 'creator_id' => $creator_id ), array( '%d' ) );
		$wpdb->delete( self::daily_table(), array( 'creator_id' => $creator_id ), array( '%d' ) );
	}
}
