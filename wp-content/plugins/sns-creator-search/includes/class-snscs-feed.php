<?php
/**
 * 記事の「おすすめ」アルゴリズム（Google・X・note を合わせた形）。
 *
 * ■ 1. 候補集め（X の「フォロー中」と「おすすめ」の両方から、最大400本）
 *   フォロー中   : フォローしている発信者の記事（30日）、フォローしている人のリポスト（14日）
 *   一緒に読まれた: あなたが反応・読了した記事に反応した人が、ほかに反応した記事（協調フィルタリング）
 *   好みの話題   : 閲覧の傾向（ジャンル）と、反応した記事のハッシュタグに合う記事（60日）
 *   話題         : いま反応が集まっている記事（SNSCS_Stories::trending）
 *   質の高い記事 : 最後まで読まれ、反応される割合の高い記事（note の「読了率」「スキ」に近い考え方）
 *   新着         : 新しい記事（まだ反応の少ない記事にも機会を）
 *
 * ■ 2. 点数（「この人が最後まで読む確率」の予測。ロジスティック回帰）
 *   p = 1 ÷ (1 + e^−(b + Σ 重み × 特徴))
 *   特徴（0〜1）: フォロー・好みの近さ・一緒に読まれた強さ・質（読了率と反応率）・クリック率（表示のわりに開かれるか）・
 *                 話題の勢い・新しさ（72時間で半分）・書き手の信頼（総合点。Google の権威性）・記事の充実度
 *                 （字数・見出し・画像）・新しい書き手
 *   最終点 = p × 不正対策の倍率（SNSCS_Integrity）× 既読なら0.15 ×「興味がない」を押した書き手なら0.3
 *   重みは毎晩、表示 → 開いた → 読んだ の記録から学習し、初期の重みより予測が良いときだけ使う（SNSCS_Feed_Admin）。
 *
 * ■ 3. 並べ方（X の「多様さ」と探索）
 *   同じ書き手が続かないよう、2本目からは0.6倍ずつ。同じジャンルは4本目から0.93倍ずつ（好みのジャンルは残しつつ、偏りすぎないように）。
 *   3・8・13番目は「お試し枠」: 表示の少ない記事から、読了率の「ありえる範囲」でくじを引いて選ぶ（トンプソン・サンプリング）。
 *   それぞれの記事に「フォロー中」「○○さんがリポスト」「料理が好きなあなたに」などの理由を出す。
 *
 * ■ 記録（誰かは残さない。日付入りのハッシュで同じ人の同じ日を1回に数える）
 *   表示（おすすめに出した）・開いた（おすすめから開いた）・閲覧・読了（40秒以上、または80%以上スクロール）
 *   学習用の記録（検索ごとのランダムな番号・位置・特徴）は30日で消す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Feed {

	const MODEL       = 'snscs_feed_model';
	const STATS       = 'snscs_feed_stats';
	const HIDE_META   = 'snscs_feed_hidden';
	const HIDE_COOKIE = 'snscs_hd';
	const READ_COOKIE = 'snscs_rd';
	const SIZE        = 60;
	const EXPLORE     = array( 2, 7, 12 );
	const KEEP_LOG    = 30;
	const PRIOR       = 20;

	/** 特徴の名前（学習・管理画面でも使う順番）。 */
	const FEATURES = array(
		'follow'    => 'フォロー中',
		'affinity'  => '好みの近さ',
		'coeng'     => '一緒に読まれた',
		'quality'   => '質（読了率・反応率）',
		'ctr'       => 'クリック率',
		'hot'       => '話題の勢い',
		'fresh'     => '新しさ',
		'authority' => '書き手の信頼',
		'depth'     => '記事の充実度',
		'newbie'    => '新しい書き手',
	);

	/** 初期の重み（学習が勝つまでこれを使う）。 */
	const DEFAULT_WEIGHTS = array(
		'bias'      => -3.0,
		'follow'    => 2.0,
		'affinity'  => 1.8,
		'coeng'     => 1.5,
		'quality'   => 1.6,
		'ctr'       => 0.8,
		'hot'       => 0.8,
		'fresh'     => 1.0,
		'authority' => 0.6,
		'depth'     => 0.5,
		'newbie'    => 0.3,
	);

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'template_redirect', array( __CLASS__, 'count_open' ), 4 );
		add_action( 'snscs_nightly_engine', array( __CLASS__, 'purge' ), 45 );
		add_action( 'snscs_hourly_engine', array( __CLASS__, 'clear_stats' ) );
		add_action( 'snscs_social_changed', array( __CLASS__, 'clear_stats' ) );
		add_shortcode( 'sns_for_you_articles', array( __CLASS__, 'shortcode' ) );
	}

	/* --------------------------------------------------------------------
	 * テーブル
	 * ------------------------------------------------------------------ */

	public static function daily_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_article_daily';
	}

	public static function seen_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_feed_seen';
	}

	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_feed_log';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$daily   = self::daily_table();
		$seen    = self::seen_table();
		$log     = self::log_table();
		dbDelta(
			"CREATE TABLE {$daily} (
  article_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  imps int(10) unsigned NOT NULL DEFAULT 0,
  opens int(10) unsigned NOT NULL DEFAULT 0,
  views int(10) unsigned NOT NULL DEFAULT 0,
  completes int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (article_id,day),
  KEY day (day)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$seen} (
  day date NOT NULL,
  visitor char(16) NOT NULL,
  article_id bigint(20) unsigned NOT NULL,
  kind tinyint(3) unsigned NOT NULL,
  PRIMARY KEY  (day,visitor,article_id,kind)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  qid char(16) NOT NULL,
  day date NOT NULL,
  article_id bigint(20) unsigned NOT NULL,
  pos smallint(5) unsigned NOT NULL DEFAULT 0,
  x varchar(255) NOT NULL DEFAULT '',
  opened tinyint(1) unsigned NOT NULL DEFAULT 0,
  readed tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY qid_article (qid,article_id),
  KEY day (day)
) {$charset};"
		);
	}

	public static function purge() {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::seen_table() . ' WHERE day < %s', gmdate( 'Y-m-d', strtotime( $today . ' -2 days' ) ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::log_table() . ' WHERE day < %s', gmdate( 'Y-m-d', strtotime( $today . ' -' . self::KEEP_LOG . ' days' ) ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::daily_table() . ' WHERE day < %s', gmdate( 'Y-m-d', strtotime( $today . ' -180 days' ) ) ) );
		// phpcs:enable
	}

	/* --------------------------------------------------------------------
	 * 記録
	 * ------------------------------------------------------------------ */

	/**
	 * 数えてよい訪問か（ロボット・運営は数えない）。
	 */
	private static function countable() {
		return ! SNSCS_Referrals::is_bot_request() && ! current_user_can( 'edit_others_posts' );
	}

	private static function visitor() {
		return substr( SNSCS_Referrals::visitor_hash(), 0, 16 );
	}

	/**
	 * 同じ人・同じ日・同じ記事・同じ種類を1回だけ数える。
	 *
	 * @param int $kind 1 表示 / 2 開いた / 3 閲覧 / 4 読了
	 */
	private static function bump( $article_id, $kind ) {
		global $wpdb;
		$day = current_time( 'Y-m-d' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$new = (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::seen_table() . ' (day, visitor, article_id, kind) VALUES (%s, %s, %d, %d)', $day, self::visitor(), $article_id, $kind ) );
		if ( ! $new ) {
			return false;
		}
		$col = array( 1 => 'imps', 2 => 'opens', 3 => 'views', 4 => 'completes' )[ $kind ];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::daily_table() . " (article_id, day, {$col}) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE {$col} = {$col} + 1", $article_id, $day ) );
		return true;
	}

	/**
	 * おすすめから開いたとき（URLの fq・fp）。
	 */
	public static function count_open() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['fq'] ) || ! is_singular( self::post_types() ) || ! self::countable() ) {
			return;
		}
		$qid = preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_GET['fq'] ) );
		// phpcs:enable
		$id = get_queried_object_id();
		if ( 16 !== strlen( $qid ) || self::is_own( $id ) ) {
			return;
		}
		if ( self::bump( $id, 2 ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::log_table() . ' SET opened = 1 WHERE qid = %s AND article_id = %d', $qid, $id ) );
		}
	}

	public static function register_routes() {
		register_rest_route(
			'snscs/v1',
			'/feed/event',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_event' ),
				'args'                => array(
					'article' => array( 'type' => 'integer', 'required' => true ),
					'event'   => array( 'type' => 'string', 'required' => true, 'enum' => array( 'view', 'read' ) ),
					'qid'     => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
		register_rest_route(
			'snscs/v1',
			'/feed/hide',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_hide' ),
				'args'                => array( 'article' => array( 'type' => 'integer', 'required' => true ) ),
			)
		);
	}

	public static function rest_event( WP_REST_Request $request ) {
		$id = (int) $request['article'];
		if ( ! in_array( get_post_type( $id ), self::post_types(), true ) || 'publish' !== get_post_status( $id ) || ! self::countable() || self::is_own( $id ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}
		if ( 'view' === $request['event'] ) {
			self::bump( $id, 3 );
		} elseif ( self::bump( $id, 4 ) ) {
			$qid = preg_replace( '/[^a-f0-9]/', '', (string) $request['qid'] );
			if ( 16 === strlen( $qid ) ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::log_table() . ' SET opened = 1, readed = 1 WHERE qid = %s AND article_id = %d', $qid, $id ) );
			}
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * 「興味がない」（ログイン中は会員情報に、そうでなければブラウザのクッキーに残す）。
	 */
	public static function rest_hide( WP_REST_Request $request ) {
		$id      = (int) $request['article'];
		$user_id = get_current_user_id();
		if ( $user_id && in_array( get_post_type( $id ), self::post_types(), true ) ) {
			$hidden = array_slice( array_values( array_unique( array_merge( array( $id ), self::hidden() ) ) ), 0, 200 );
			update_user_meta( $user_id, self::HIDE_META, $hidden );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * @return int[]
	 */
	private static function cookie_ids( $name ) {
		if ( empty( $_COOKIE[ $name ] ) ) {
			return array();
		}
		return array_slice( array_filter( array_map( 'absint', explode( '.', substr( (string) wp_unslash( $_COOKIE[ $name ] ), 0, 1200 ) ) ) ), 0, 100 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 数字だけを取り出す。
	}

	/**
	 * 「興味がない」を押した記事。
	 *
	 * @return int[]
	 */
	public static function hidden() {
		$ids = self::cookie_ids( self::HIDE_COOKIE );
		if ( is_user_logged_in() ) {
			$ids = array_merge( $ids, array_map( 'intval', (array) get_user_meta( get_current_user_id(), self::HIDE_META, true ) ) );
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * 読んだ記事（ブラウザに残した最近の記事と、会員なら80%以上読んだ記事）。
	 *
	 * @return int[]
	 */
	public static function read_ids() {
		$ids = self::cookie_ids( self::READ_COOKIE );
		if ( is_user_logged_in() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = array_merge( $ids, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT article_id FROM ' . SNSCS_Social::reactions_table() . ' WHERE user_id = %d AND (progress >= 80 OR read_at IS NOT NULL) LIMIT 300', get_current_user_id() ) ) ) );
		}
		return array_values( array_unique( $ids ) );
	}

	public static function post_types() {
		return array( SNSCS_Articles::POST_TYPE );
	}

	private static function is_own( $article_id ) {
		return is_user_logged_in() && SNSCS_Articles::creator_of( $article_id ) && SNSCS_Social::own_creator( get_current_user_id() ) === SNSCS_Articles::creator_of( $article_id );
	}

	/**
	 * 記事のページ（閲覧・読了を送る）と、おすすめの一覧（「興味がない」）で使うスクリプト。
	 */
	public static function enqueue() {
		if ( is_singular( self::post_types() ) ) {
			self::enqueue_script();
		}
	}

	private static function enqueue_script() {
		if ( wp_script_is( 'snscs-feed', 'enqueued' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$qid     = isset( $_GET['fq'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_GET['fq'] ) ) : '';
		$article = is_singular( self::post_types() ) ? get_queried_object_id() : 0;
		wp_enqueue_script( 'snscs-feed', SNSCS_URL . 'assets/js/snscs-feed.js', array(), SNSCS_VERSION, true );
		wp_localize_script(
			'snscs-feed',
			'snscsFeed',
			array(
				'rest'    => esc_url_raw( rest_url( 'snscs/v1/feed' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'article' => $article,
				'qid'     => 16 === strlen( $qid ) ? $qid : '',
			)
		);
	}

	/* --------------------------------------------------------------------
	 * 記事ごとの数字（1時間ごとに数え直す）
	 * ------------------------------------------------------------------ */

	public static function clear_stats() {
		delete_transient( self::STATS );
	}

	/**
	 * 直近120日の記事の数字と、質・クリック率の点。
	 *
	 * @return array{articles:array<int,array>,global:array}
	 */
	public static function stats() {
		$cached = get_transient( self::STATS );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$since  = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -60 days' ) );
		$posted = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 120 * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_date, CHAR_LENGTH(p.post_content) AS len, (p.post_content LIKE '%%<h2%%') AS h2, (p.post_content LIKE '%%<img%%') AS img, pm.meta_value AS creator
				FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_snscs_creator_id'
				WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_date >= %s ORDER BY p.post_date DESC LIMIT 3000",
				SNSCS_Articles::POST_TYPE,
				$posted
			),
			ARRAY_A
		);
		$daily = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT article_id, SUM(imps) i, SUM(opens) o, SUM(views) v, SUM(completes) r FROM ' . self::daily_table() . ' WHERE day >= %s GROUP BY article_id', $since ), ARRAY_A ) as $row ) {
			$daily[ (int) $row['article_id'] ] = $row;
		}
		$react = array();
		foreach ( $wpdb->get_results( 'SELECT article_id, type, COUNT(*) n FROM ' . SNSCS_Social::reactions_table() . ' WHERE type IN (1,2,3) GROUP BY article_id, type', ARRAY_A ) as $row ) {
			$react[ (int) $row['article_id'] ][ (int) $row['type'] ] = (int) $row['n'];
		}
		// phpcs:enable

		// 全体の平均（データの少ない記事は、ここに寄せる）。
		$sum = array( 'i' => 0, 'o' => 0, 'v' => 0, 'r' => 0, 'e' => 0 );
		foreach ( $posts as $p ) {
			$d  = $daily[ (int) $p['ID'] ] ?? array( 'i' => 0, 'o' => 0, 'v' => 0, 'r' => 0 );
			$rx = $react[ (int) $p['ID'] ] ?? array();
			$sum['i'] += (int) $d['i'];
			$sum['o'] += (int) $d['o'];
			$sum['v'] += (int) $d['v'];
			$sum['r'] += (int) $d['r'];
			$sum['e'] += ( $rx[ SNSCS_Social::LIKE ] ?? 0 ) + 1.5 * ( $rx[ SNSCS_Social::BOOKMARK ] ?? 0 ) + 3 * ( $rx[ SNSCS_Social::REPOST ] ?? 0 );
		}
		$global = array(
			'ctr'  => $sum['i'] >= 50 ? max( 0.01, $sum['o'] / $sum['i'] ) : 0.1,
			'read' => $sum['v'] >= 50 ? max( 0.02, $sum['r'] / $sum['v'] ) : 0.35,
			'eng'  => $sum['v'] >= 50 ? max( 0.01, $sum['e'] / $sum['v'] ) : 0.1,
			'imps' => $sum['i'],
			'opens' => $sum['o'],
			'views' => $sum['v'],
			'reads' => $sum['r'],
		);

		$counts = array();
		foreach ( $posts as $p ) {
			$counts[ (int) $p['creator'] ] = ( $counts[ (int) $p['creator'] ] ?? 0 ) + 1;
		}
		$k   = self::PRIOR;
		$out = array();
		foreach ( $posts as $p ) {
			$id   = (int) $p['ID'];
			$d    = $daily[ $id ] ?? array( 'i' => 0, 'o' => 0, 'v' => 0, 'r' => 0 );
			$rx   = $react[ $id ] ?? array();
			$eng  = ( $rx[ SNSCS_Social::LIKE ] ?? 0 ) + 1.5 * ( $rx[ SNSCS_Social::BOOKMARK ] ?? 0 ) + 3 * ( $rx[ SNSCS_Social::REPOST ] ?? 0 );
			$v    = max( (int) $d['v'], (int) $d['r'] );
			$read = ( (int) $d['r'] + $k * $global['read'] ) / ( $v + $k );
			$erat = ( $eng + $k * $global['eng'] ) / ( $v + $k );
			$ctr  = ( (int) $d['o'] + $k * $global['ctr'] ) / ( (int) $d['i'] + $k );
			// 平均と同じなら0.5。平均の2倍で約0.67、半分で約0.33。
			$rel  = function ( $x, $avg ) {
				return $x / ( $x + $avg );
			};
			$out[ $id ] = array(
				'creator' => (int) $p['creator'],
				'date'    => $p['post_date'],
				'views'   => $v,
				'reads'   => (int) $d['r'],
				'imps'    => (int) $d['i'],
				'quality' => 0.6 * $rel( $read, $global['read'] ) + 0.4 * $rel( $erat, $global['eng'] ),
				'ctr'     => $rel( $ctr, $global['ctr'] ),
				'read'    => $read,
				'depth'   => min( 1, (int) $p['len'] / 3000 ) * 0.7 + ( (int) $p['h2'] ? 0.15 : 0 ) + ( (int) $p['img'] || has_post_thumbnail( $id ) ? 0.15 : 0 ),
				'newbie'  => ( $counts[ (int) $p['creator'] ] ?? 0 ) <= 3 ? 1 : 0,
			);
		}
		$stats = array( 'articles' => $out, 'global' => $global );
		set_transient( self::STATS, $stats, HOUR_IN_SECONDS );
		return $stats;
	}

	/* --------------------------------------------------------------------
	 * 重み
	 * ------------------------------------------------------------------ */

	/**
	 * いま使う重み（学習した重みが採用されていればそれ）。
	 *
	 * @return array<string,float>
	 */
	public static function weights() {
		$model = (array) get_option( self::MODEL, array() );
		if ( ! empty( $model['use'] ) && ! empty( $model['weights'] ) && 'manual' !== ( $model['mode'] ?? 'auto' ) ) {
			return array_merge( self::DEFAULT_WEIGHTS, array_map( 'floatval', (array) $model['weights'] ) );
		}
		return self::DEFAULT_WEIGHTS;
	}

	public static function predict( array $x, array $w ) {
		$z = $w['bias'];
		foreach ( self::FEATURES as $key => $label ) {
			$z += $w[ $key ] * ( $x[ $key ] ?? 0 );
		}
		return 1 / ( 1 + exp( -$z ) );
	}

	/* --------------------------------------------------------------------
	 * 1. 候補集め
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $context user（会員ID）、seed（この記事を起点に）、profile（閲覧の傾向）。
	 * @return array<int,array> 記事ID => [follow, coeng, repost_by, sources]
	 */
	private static function candidates( array $context, array $stats ) {
		global $wpdb;
		$user  = (int) $context['user'];
		$cands = array();
		$add   = function ( $id, $source, array $extra = array() ) use ( &$cands, $stats ) {
			$id = (int) $id;
			if ( ! isset( $stats['articles'][ $id ] ) ) {
				return;
			}
			$cands[ $id ]              = array_merge( $cands[ $id ] ?? array( 'follow' => 0, 'coeng' => 0, 'by' => 0, 'sources' => array() ), $extra );
			$cands[ $id ]['sources'][] = $source;
		};

		// フォロー中: 発信者の記事と、フォローしている人のリポスト。
		if ( $user ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$creators = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT creator_id FROM ' . SNSCS_Social::follows_table() . ' WHERE user_id = %d', $user ) ) );
			foreach ( $stats['articles'] as $id => $a ) {
				if ( in_array( $a['creator'], $creators, true ) && strtotime( $a['date'] ) >= strtotime( current_time( 'mysql' ) ) - 30 * DAY_IN_SECONDS ) {
					$add( $id, 'follow', array( 'follow' => 1 ) );
				}
			}
			$people = SNSCS_People::following_ids( $user );
			if ( $creators ) {
				$people = array_merge( $people, array_map( 'intval', $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'snscs_creator_id' AND meta_value IN (" . implode( ',', $creators ) . ')' ) ) );
			}
			if ( $people ) {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, article_id FROM ' . SNSCS_Social::reactions_table() . ' WHERE type = %d AND user_id IN (' . implode( ',', array_map( 'intval', array_unique( $people ) ) ) . ') AND created_at >= %s ORDER BY created_at DESC LIMIT 200', SNSCS_Social::REPOST, gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 14 * DAY_IN_SECONDS ) ), ARRAY_A );
				foreach ( $rows as $row ) {
					if ( empty( $cands[ (int) $row['article_id'] ]['follow'] ) ) {
						$add( $row['article_id'], 'repost', array( 'follow' => 0.7, 'by' => (int) $row['user_id'] ) );
					}
				}
			}
			// phpcs:enable
		}

		// 一緒に読まれた（協調フィルタリング）: 起点の記事に反応した人が、ほかに反応した記事。
		$seeds = $context['seed'] ? array( (int) $context['seed'] ) : array();
		if ( $user ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$seeds = array_merge( $seeds, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT article_id FROM ' . SNSCS_Social::reactions_table() . ' WHERE user_id = %d AND (type IN (1,2,3) OR progress >= 80) ORDER BY created_at DESC LIMIT 50', $user ) ) ) );
		}
		$seeds = array_slice( array_values( array_unique( array_merge( $seeds, self::cookie_ids( self::READ_COOKIE ) ) ) ), 0, 60 );
		if ( $seeds ) {
			$in    = implode( ',', $seeds );
			$table = SNSCS_Social::reactions_table();
			// 似ている人: 起点の記事に多く反応している人ほど似ている（コサイン類似度 = 重なり ÷ √(自分の数 × 相手の数)）。
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$overlap = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, COUNT(DISTINCT article_id) AS n FROM {$table} WHERE article_id IN ({$in}) AND user_id <> %d GROUP BY user_id ORDER BY n DESC LIMIT 300", $user ), ARRAY_A );
			if ( $overlap ) {
				$ids  = implode( ',', array_map( 'intval', wp_list_pluck( $overlap, 'user_id' ) ) );
				$size = wp_list_pluck( $wpdb->get_results( "SELECT user_id, COUNT(DISTINCT article_id) AS n FROM {$table} WHERE user_id IN ({$ids}) GROUP BY user_id", ARRAY_A ), 'n', 'user_id' );
				$sim  = array();
				foreach ( $overlap as $row ) {
					$sim[ (int) $row['user_id'] ] = (int) $row['n'] / sqrt( count( $seeds ) * max( 1, (int) ( $size[ $row['user_id'] ] ?? 1 ) ) );
				}
				$rows  = $wpdb->get_results( "SELECT user_id, article_id FROM {$table} WHERE user_id IN ({$ids}) AND article_id NOT IN ({$in}) LIMIT 20000", ARRAY_A );
				$votes = array();
				$pop   = array();
				foreach ( $rows as $row ) {
					$a = (int) $row['article_id'];
					$votes[ $a ] = ( $votes[ $a ] ?? 0 ) + $sim[ (int) $row['user_id'] ];
					$pop[ $a ]   = ( $pop[ $a ] ?? 0 ) + 1;
				}
				// 誰にでも読まれる記事が上に来すぎないよう、人気で少しだけ割る。
				foreach ( $votes as $a => $v ) {
					$votes[ $a ] = $v / pow( $pop[ $a ], 0.25 );
				}
				arsort( $votes );
				$votes = array_slice( $votes, 0, 150, true );
				$max   = $votes ? max( $votes ) : 0;
				foreach ( $votes as $a => $v ) {
					$add( $a, 'coeng', array( 'coeng' => $max > 0 ? $v / $max : 0 ) );
				}
			}
			// phpcs:enable
		}

		// 好みの話題・話題・質の高い記事・新着（全体の数字から）。
		$genres = $context['genres'];
		$byq    = $stats['articles'];
		uasort(
			$byq,
			function ( $a, $b ) {
				return $b['quality'] <=> $a['quality'];
			}
		);
		$n = 0;
		foreach ( $byq as $id => $a ) {
			if ( $n++ >= 80 ) {
				break;
			}
			$add( $id, 'quality' );
		}
		foreach ( array_slice( array_keys( SNSCS_Stories::trending( 60 ) ), 0, 60 ) as $id ) {
			$add( $id, 'hot' );
		}
		$n = 0;
		foreach ( $stats['articles'] as $id => $a ) {
			if ( $n++ >= 80 ) {
				break;
			}
			$add( $id, 'new' );
		}
		if ( $genres ) {
			$genre_of = self::creator_genres( array_unique( wp_list_pluck( $stats['articles'], 'creator' ) ) );
			$n        = 0;
			foreach ( $stats['articles'] as $id => $a ) {
				if ( array_intersect( $genre_of[ $a['creator'] ] ?? array(), array_keys( $genres ) ) ) {
					$add( $id, 'topic' );
					if ( ++$n >= 120 ) {
						break;
					}
				}
			}
		}
		return $cands;
	}

	/**
	 * 発信者ごとのジャンル（term_id）。
	 *
	 * @return array<int,int[]>
	 */
	private static function creator_genres( array $creators ) {
		static $memo = array();
		$need = array_values( array_diff( array_filter( array_map( 'intval', $creators ) ), array_keys( $memo ) ) );
		if ( $need ) {
			global $wpdb;
			foreach ( $need as $id ) {
				$memo[ $id ] = array();
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s WHERE tr.object_id IN (" . implode( ',', $need ) . ')', SNSCS_Post_Types::TAX_GENRE ), ARRAY_A );
			foreach ( $rows as $row ) {
				$memo[ (int) $row['object_id'] ][] = (int) $row['term_id'];
			}
		}
		return array_intersect_key( $memo, array_flip( array_map( 'intval', $creators ) ) );
	}

	/**
	 * 閲覧の傾向のジャンル（term_id => 0〜1）。会員なら、反応した記事の書き手のジャンルも足す。
	 */
	private static function genre_affinity( $user ) {
		$weights = array();
		$profile = SNSCS_Personalize::profile();
		if ( $profile ) {
			foreach ( $profile['genres'] as $id => $w ) {
				$weights[ (int) $id ] = (float) $w;
			}
		}
		if ( $user ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$articles = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT article_id FROM ' . SNSCS_Social::reactions_table() . ' WHERE user_id = %d ORDER BY created_at DESC LIMIT 100', $user ) ) );
			$creators = array_filter( array_map( array( 'SNSCS_Articles', 'creator_of' ), $articles ) );
			foreach ( self::creator_genres( $creators ) as $genres ) {
				foreach ( $genres as $g ) {
					$weights[ $g ] = ( $weights[ $g ] ?? 0 ) + 1;
				}
			}
			$followed = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT creator_id FROM ' . SNSCS_Social::follows_table() . ' WHERE user_id = %d', $user ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( self::creator_genres( $followed ) as $genres ) {
				foreach ( $genres as $g ) {
					$weights[ $g ] = ( $weights[ $g ] ?? 0 ) + 2;
				}
			}
		}
		$max = $weights ? max( $weights ) : 0;
		return $max > 0 ? array_map(
			function ( $w ) use ( $max ) {
				return $w / $max;
			},
			$weights
		) : array();
	}

	/* --------------------------------------------------------------------
	 * 2・3. 点数と並べ方
	 * ------------------------------------------------------------------ */

	/**
	 * おすすめの記事（並べ終わったもの）。
	 *
	 * @param array $args user（会員ID。初期は今の人）、seed（この記事に続けて読むもの）、limit、log（表示を記録するか）。
	 * @return array<int,array{id:int,score:float,reason:string,x:array,rp:int,ru:int,fq:string,fp:int}>
	 */
	public static function items( array $args = array() ) {
		$args    = wp_parse_args( $args, array( 'user' => get_current_user_id(), 'seed' => 0, 'limit' => self::SIZE, 'log' => true ) );
		$user    = (int) $args['user'];
		$stats   = self::stats();
		$genres  = self::genre_affinity( $user );
		// 記事の下のおすすめでは、その記事のジャンルを好みとして強く使う。
		if ( $args['seed'] ) {
			foreach ( self::creator_genres( array( SNSCS_Articles::creator_of( (int) $args['seed'] ) ) ) as $list ) {
				foreach ( $list as $g ) {
					$genres[ $g ] = 1.0;
				}
			}
		}
		$cands   = self::candidates( array( 'user' => $user, 'seed' => (int) $args['seed'], 'genres' => $genres ), $stats );
		$w       = self::weights();
		$hidden  = array_flip( self::hidden() );
		$read    = array_flip( self::read_ids() );
		$hot     = SNSCS_Stories::trending( 60 );
		$hot_max = $hot ? max( $hot ) : 0;
		$top_hot = array_flip( array_slice( array_keys( $hot ), 0, 10 ) );
		$creators = array_unique( wp_list_pluck( array_intersect_key( $stats['articles'], $cands ), 'creator' ) );
		$genre_of = self::creator_genres( $creators );
		$authority = self::authority( $creators );
		$penalties = SNSCS_Integrity::penalties();
		$own       = $user ? SNSCS_Social::own_creator( $user ) : 0;
		// 「興味がない」を押した記事の書き手は、ほかの記事も控えめに。
		$muted = array();
		foreach ( array_keys( $hidden ) as $h ) {
			$c = SNSCS_Articles::creator_of( $h );
			if ( $c ) {
				$muted[ $c ] = true;
			}
		}
		$now = strtotime( current_time( 'mysql' ) );

		$scored = array();
		foreach ( $cands as $id => $c ) {
			$a = $stats['articles'][ $id ];
			if ( isset( $hidden[ $id ] ) || ( $own && $a['creator'] === $own ) || $id === (int) $args['seed'] ) {
				continue;
			}
			$aff = 0;
			foreach ( $genre_of[ $a['creator'] ] ?? array() as $g ) {
				$aff = max( $aff, $genres[ $g ] ?? 0 );
			}
			$x = array(
				'follow'    => (float) $c['follow'],
				'affinity'  => $aff,
				'coeng'     => (float) $c['coeng'],
				'quality'   => $a['quality'],
				'ctr'       => $a['ctr'],
				'hot'       => $hot_max > 0 && isset( $hot[ $id ] ) ? $hot[ $id ] / $hot_max : 0,
				'fresh'     => pow( 0.5, max( 0, $now - strtotime( $a['date'] ) ) / HOUR_IN_SECONDS / 72 ),
				'authority' => $authority[ $a['creator'] ] ?? 0,
				'depth'     => $a['depth'],
				'newbie'    => (float) $a['newbie'],
			);
			$score = self::predict( $x, $w ) * ( $penalties[ $a['creator'] ] ?? 1 );
			if ( isset( $read[ $id ] ) ) {
				$score *= 0.15;
			}
			if ( isset( $muted[ $a['creator'] ] ) ) {
				$score *= 0.3;
			}
			$scored[ $id ] = array(
				'id'      => $id,
				'score'   => $score,
				'x'       => $x,
				'creator' => $a['creator'],
				'genre'   => ( $genre_of[ $a['creator'] ] ?? array( 0 ) )[0] ?? 0,
				'by'      => (int) $c['by'],
				'hot'     => isset( $top_hot[ $id ] ),
				'views'   => $a['views'],
				'reads'   => $a['reads'],
				'read'    => $a['read'],
				'sources' => $c['sources'],
			);
		}

		// 並べる: 同じ書き手・同じジャンルが続かないように、点を割り引きながら1本ずつ選ぶ。お試し枠はくじで。
		$picked  = array();
		$authors = array();
		$gcount  = array();
		$limit   = min( (int) $args['limit'], count( $scored ) );
		while ( count( $picked ) < $limit ) {
			$explore = in_array( count( $picked ), self::EXPLORE, true );
			$best    = null;
			$best_v  = -1;
			foreach ( $scored as $id => $s ) {
				if ( $explore ) {
					if ( $s['views'] >= 30 || isset( $read[ $id ] ) ) {
						continue;
					}
					$v = self::beta_sample( $s['reads'] + 1, max( 0, $s['views'] - $s['reads'] ) + 1 ) * ( $penalties[ $s['creator'] ] ?? 1 );
				} else {
					$v = $s['score'] * pow( 0.6, $authors[ $s['creator'] ] ?? 0 ) * pow( 0.93, max( 0, ( $gcount[ $s['genre'] ] ?? 0 ) - 2 ) );
				}
				if ( $v > $best_v ) {
					$best   = $id;
					$best_v = $v;
				}
			}
			if ( null === $best ) {
				if ( $explore ) {
					// お試しできる記事が無ければ、ふつうに選ぶ（その位置を飛ばさない）。
					$explore = false;
					foreach ( $scored as $id => $s ) {
						$v = $s['score'] * pow( 0.6, $authors[ $s['creator'] ] ?? 0 ) * pow( 0.93, max( 0, ( $gcount[ $s['genre'] ] ?? 0 ) - 2 ) );
						if ( $v > $best_v ) {
							$best   = $id;
							$best_v = $v;
						}
					}
				}
				if ( null === $best ) {
					break;
				}
			}
			$s                        = $scored[ $best ];
			$s['explore']             = $explore;
			$picked[]                 = $s;
			$authors[ $s['creator'] ] = ( $authors[ $s['creator'] ] ?? 0 ) + 1;
			$gcount[ $s['genre'] ]    = ( $gcount[ $s['genre'] ] ?? 0 ) + 1;
			unset( $scored[ $best ] );
		}

		$qid = $args['log'] && self::countable() ? substr( bin2hex( random_bytes( 8 ) ), 0, 16 ) : '';
		$out = array();
		foreach ( $picked as $pos => $s ) {
			$out[] = array(
				'id'     => $s['id'],
				'score'  => $s['score'],
				'x'      => $s['x'],
				'reason' => self::reason( $s, $genres ),
				'rp'     => $s['by'] ? (int) get_user_meta( $s['by'], 'snscs_creator_id', true ) : 0,
				'ru'     => $s['by'] && ! get_user_meta( $s['by'], 'snscs_creator_id', true ) ? $s['by'] : 0,
				'by'     => $s['by'],
				'fq'     => $qid,
				'fp'     => $pos + 1,
			);
		}
		return $out;
	}

	/**
	 * 表示した分を記録する（学習用の特徴と、表示回数）。
	 */
	public static function log_impressions( array $items ) {
		if ( ! $items || '' === $items[0]['fq'] ) {
			return;
		}
		global $wpdb;
		$day    = current_time( 'Y-m-d' );
		$values = array();
		foreach ( $items as $item ) {
			self::bump( $item['id'], 1 );
			$x        = implode( ',', array_map( function ( $v ) { return round( $v, 3 ); }, array_values( $item['x'] ) ) );
			$values[] = $wpdb->prepare( '(%s, %s, %d, %d, %s)', $item['fq'], $day, $item['id'], $item['fp'], $x );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'INSERT INTO ' . self::log_table() . ' (qid, day, article_id, pos, x) VALUES ' . implode( ',', $values ) );
	}

	/**
	 * 書き手の信頼（総合点 0〜1）。
	 *
	 * @return array<int,float>
	 */
	private static function authority( array $creators ) {
		$creators = array_filter( array_map( 'intval', $creators ) );
		if ( ! $creators ) {
			return array();
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( 'SELECT creator_id, score FROM ' . SNSCS_Scores::table() . ' WHERE creator_id IN (' . implode( ',', $creators ) . ')', ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['creator_id'] ] = max( 0, min( 1, (float) $row['score'] / 100 ) );
		}
		return $out;
	}

	/**
	 * ベータ分布からのくじ（ガンマ分布2つの比。Marsaglia–Tsang 法）。
	 */
	private static function beta_sample( $a, $b ) {
		$x = self::gamma_sample( $a );
		$y = self::gamma_sample( $b );
		return $x + $y > 0 ? $x / ( $x + $y ) : 0.5;
	}

	private static function gamma_sample( $shape ) {
		if ( $shape < 1 ) {
			return self::gamma_sample( $shape + 1 ) * pow( mt_rand() / mt_getrandmax(), 1 / $shape );
		}
		$d = $shape - 1 / 3;
		$c = 1 / sqrt( 9 * $d );
		while ( true ) {
			do {
				$u1 = max( 1e-12, mt_rand() / mt_getrandmax() );
				$u2 = mt_rand() / mt_getrandmax();
				$x  = sqrt( -2 * log( $u1 ) ) * cos( 2 * M_PI * $u2 );
				$v  = 1 + $c * $x;
			} while ( $v <= 0 );
			$v = $v * $v * $v;
			$u = max( 1e-12, mt_rand() / mt_getrandmax() );
			if ( log( $u ) < 0.5 * $x * $x + $d - $d * $v + $d * log( $v ) ) {
				return $d * $v;
			}
		}
	}

	/**
	 * 記事を出す理由（いちばん効いているもの）。
	 */
	private static function reason( array $s, array $genres ) {
		$x = $s['x'];
		if ( $s['by'] && $x['follow'] < 1 ) {
			$creator = (int) get_user_meta( $s['by'], 'snscs_creator_id', true );
			return ( $creator ? html_entity_decode( get_the_title( $creator ), ENT_QUOTES, 'UTF-8' ) : SNSCS_People::nickname( $s['by'] ) ) . 'さんがリポスト';
		}
		if ( $x['follow'] >= 1 ) {
			return 'フォロー中';
		}
		if ( ! empty( $s['explore'] ) ) {
			return '新着のおすすめ';
		}
		if ( $x['coeng'] >= 0.3 ) {
			return 'あなたが読んだ記事と一緒に読まれています';
		}
		if ( $s['hot'] ) {
			return '急上昇';
		}
		if ( $x['affinity'] >= 0.5 && $s['genre'] ) {
			$term = get_term( $s['genre'], SNSCS_Post_Types::TAX_GENRE );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->name . 'が好きなあなたに';
			}
		}
		if ( $x['quality'] >= 0.6 && $s['views'] >= 20 ) {
			return '最後まで読まれている記事';
		}
		if ( $x['newbie'] ) {
			return '新しい書き手';
		}
		// いちばん効いている特徴から。
		$labels = array( 'affinity' => '好みに近い記事', 'quality' => '最後まで読まれている記事', 'ctr' => 'よく開かれている記事', 'hot' => '話題の記事', 'fresh' => '新着', 'authority' => '人気の書き手', 'depth' => '読みごたえのある記事' );
		$w      = self::weights();
		$best   = '';
		$best_v = 0;
		foreach ( $labels as $key => $label ) {
			if ( $w[ $key ] * $x[ $key ] > $best_v ) {
				$best   = $label;
				$best_v = $w[ $key ] * $x[ $key ];
			}
		}
		return $best;
	}

	/* --------------------------------------------------------------------
	 * 表示
	 * ------------------------------------------------------------------ */

	/**
	 * おすすめの一覧（SNSCS_Social::render_list の形にして出す）。
	 */
	public static function render( array $items, $empty = '' ) {
		self::log_impressions( $items );
		$list = array();
		foreach ( $items as $item ) {
			$list[] = array(
				'id'     => $item['id'],
				'note'   => '',
				'reason' => $item['reason'],
				'rp'     => $item['rp'],
				'ru'     => $item['ru'],
				'track'  => '' !== $item['fq'] ? array( 'fq' => $item['fq'], 'fp' => $item['fp'] ) : array(),
				'hide'   => true,
			);
		}
		self::enqueue_script();
		return SNSCS_Social::render_list( $list, $empty );
	}

	/* --------------------------------------------------------------------
	 * 記事の検索（Google の考え方: 言葉との一致 × 質 × 書き手の信頼）
	 * ------------------------------------------------------------------ */

	/**
	 * キーワードに合う記事（発信者の記事とコラム）。
	 *
	 * 関連度は BM25（題名 3・ハッシュタグ 2・本文 1。単語は検索と同じ2文字ずつの区切り）。
	 * 最終点 = 関連度（いちばん高い記事を1） × (0.55 + 0.45 × 質) × (0.8 + 0.2 × 書き手の信頼) × 不正対策の倍率 × (0.9 + 0.1 × 新しさ)
	 *
	 * @return int[] 記事ID（高い順）
	 */
	public static function search_articles( $q, $limit = 5 ) {
		$q     = trim( (string) $q );
		$terms = SNSCS_Text::tokens( $q );
		if ( '' === $q || ! $terms ) {
			return array();
		}
		$key    = 'snscs_artsearch_' . md5( $q . '|' . $limit );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$cat   = SNSCS_Columns::category_id();
		$found = array();
		// まず全部の言葉を含む記事、足りなければ言葉ごとに（どれかを含む記事）。
		$words = array_values( array_unique( array_merge( array( $q ), preg_split( '/\s+/u', $q ) ) ) );
		foreach ( $words as $i => $word ) {
			if ( $i > 0 && ( count( $found ) >= $limit * 4 && 1 === $i || 1 === count( $words ) - 1 ) ) {
				break;
			}
			foreach ( array( SNSCS_Articles::POST_TYPE, 'post' ) as $type ) {
				$args = array( 'post_type' => $type, 'post_status' => 'publish', 's' => $word, 'posts_per_page' => $i ? 30 : 60, 'fields' => 'ids', 'no_found_rows' => true, 'ignore_sticky_posts' => true );
				if ( 'post' === $type ) {
					if ( ! $cat ) {
						continue;
					}
					$args['cat'] = $cat;
				}
				$found = array_merge( $found, array_map( 'intval', get_posts( $args ) ) );
			}
		}
		$found = array_values( array_unique( $found ) );
		if ( ! $found ) {
			set_transient( $key, array(), 10 * MINUTE_IN_SECONDS );
			return array();
		}
		$fields = array();
		$len    = array();
		foreach ( $found as $id ) {
			$tags          = wp_get_post_terms( $id, 'post' === get_post_type( $id ) ? 'post_tag' : SNSCS_Stories::TAX, array( 'fields' => 'names' ) );
			$fields[ $id ] = array(
				3.0 => SNSCS_Text::tokens( get_the_title( $id ) ),
				2.0 => SNSCS_Text::tokens( is_wp_error( $tags ) ? '' : implode( ' ', $tags ) ),
				1.0 => SNSCS_Text::tokens( wp_strip_all_tags( (string) get_post_field( 'post_content', $id ) ) ),
			);
			$len[ $id ] = max( 1, array_sum( $fields[ $id ][ 1.0 ] ) );
		}
		$avg   = array_sum( $len ) / count( $len );
		$total = max( count( $found ), (int) wp_count_posts( SNSCS_Articles::POST_TYPE )->publish + (int) wp_count_posts( 'post' )->publish );
		$rel   = array();
		foreach ( array_keys( $terms ) as $term ) {
			$df = 0;
			foreach ( $fields as $f ) {
				if ( isset( $f[3.0][ $term ] ) || isset( $f[2.0][ $term ] ) || isset( $f[1.0][ $term ] ) ) {
					++$df;
				}
			}
			$idf = log( 1 + ( $total - $df + 0.5 ) / ( $df + 0.5 ) );
			foreach ( $fields as $id => $f ) {
				$score = 0;
				foreach ( $f as $weight => $tokens ) {
					$tf = $tokens[ $term ] ?? 0;
					if ( $tf ) {
						$norm   = 1.0 === $weight ? ( 1 - 0.75 + 0.75 * $len[ $id ] / $avg ) : 1;
						$score += $weight * $tf * 2.2 / ( $tf + 1.2 * $norm );
					}
				}
				$rel[ $id ] = ( $rel[ $id ] ?? 0 ) + $idf * $score;
			}
		}
		$max       = $rel ? max( $rel ) : 0;
		$stats     = self::stats();
		$creators  = array_filter( array_map( array( 'SNSCS_Articles', 'creator_of' ), $found ) );
		$authority = self::authority( $creators );
		$penalties = SNSCS_Integrity::penalties();
		$now       = strtotime( current_time( 'mysql' ) );
		$final     = array();
		foreach ( $rel as $id => $r ) {
			if ( $max <= 0 || $r <= 0 ) {
				continue;
			}
			$creator = SNSCS_Articles::POST_TYPE === get_post_type( $id ) ? SNSCS_Articles::creator_of( $id ) : 0;
			$quality = $stats['articles'][ $id ]['quality'] ?? 0.5;
			$auth    = $creator ? ( $authority[ $creator ] ?? 0.3 ) : 0.7;
			$fresh   = pow( 0.5, max( 0, $now - (int) get_post_time( 'U', false, $id ) ) / DAY_IN_SECONDS / 180 );
			$final[ $id ] = ( $r / $max ) * ( 0.55 + 0.45 * $quality ) * ( 0.8 + 0.2 * $auth ) * ( $penalties[ $creator ] ?? 1 ) * ( 0.9 + 0.1 * $fresh );
		}
		arsort( $final );
		$ids = array_slice( array_keys( $final ), 0, $limit );
		set_transient( $key, $ids, 10 * MINUTE_IN_SECONDS );
		return $ids;
	}

	/**
	 * 検索結果の下: キーワードに合う記事。
	 */
	public static function render_search( $q ) {
		$ids = self::search_articles( $q, 5 );
		if ( ! $ids ) {
			return '';
		}
		$items = array();
		foreach ( $ids as $id ) {
			$items[] = array( 'id' => $id, 'note' => '' );
		}
		return '<section class="snscs-stories-box snscs-article-results"><h2 class="snscs-single__heading">「' . esc_html( $q ) . '」の記事</h2>' . SNSCS_Social::render_list( $items ) . '</section>';
	}

	/**
	 * [sns_for_you_articles count="5" title="あなたへのおすすめ記事"]
	 */
	public static function shortcode( $atts ) {
		$atts  = shortcode_atts( array( 'count' => 5, 'title' => 'あなたへのおすすめ記事' ), $atts, 'sns_for_you_articles' );
		$items = self::items( array( 'limit' => max( 1, min( 20, (int) $atts['count'] ) ) ) );
		if ( ! $items ) {
			return '';
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		wp_enqueue_style( 'snscs' );
		return '<section class="snscs-stories-box"><h2 class="snscs-single__heading">' . esc_html( $atts['title'] ) . '</h2>' . self::render( $items ) . '<p><a href="' . esc_url( SNSCS_Stories::stories_url() ) . '">おすすめをもっと見る →</a></p></section>';
	}

	/**
	 * 記事の下: この記事のあとに読むおすすめ。
	 */
	public static function next_reads( $article_id, $limit = 5 ) {
		$items = self::items( array( 'seed' => $article_id, 'limit' => $limit ) );
		if ( ! $items ) {
			return '';
		}
		wp_enqueue_style( 'snscs' );
		return '<section class="snscs-stories-box snscs-next-reads"><h2 class="snscs-single__heading">次に読むおすすめ</h2>' . self::render( $items ) . '</section>';
	}
}
