<?php
/**
 * メンバーの冒険（1.26）: 経験値・レベル・カードランク・イベント。ゲームの仕組みで、FolloFind への集客の貢献に報いる。
 *
 * 経験値（金品とは交換できない。本サービスの中のゲームの記録）
 *   見せるもの: 訪問（何か行動した日。ログインだけでは数えない）・継続ボーナス（週に3日以上の訪問が続いた週数に応じて。
 *               どこまで増えるかは見せない）・行動（記事の公開・プロフィールの更新・読む・反応・質問箱など）・紹介（登録して使い始めた人数）
 *   見せないもの: 貢献度（リンク・FolloLinkからの流入、発信者ページ・記事の閲覧と滞在、読了と読み飛ばし、本人の利用時間、
 *               反応による閲覧の増加などから、毎日計算する。重みと換算の倍率は、ここだけの秘密。倍率は毎日自動で調整する）。
 *               本人には合計の経験値と「貢献度ゲージ（★1〜5・先週との比較）」だけを見せる
 *   レベル  : 上限なし。必要な経験値はレベルが上がるほど増える。10レベルごとに称号が変わる
 *   弱い敵  : 小さな行動（読む・反応など）の経験値は、レベルが上がるほど減る（集客・紹介・貢献度は減らない）
 *   創設メンバー: 経験値の上乗せ（FIRST 10 +60%、ノワール +50% … プラチナ +20%）。掲載から24か月かけて0%まで少しずつ減る
 *
 * カードランク（直近365日の経験値と、上位ランクは全体の中での順位で決まる。毎日見直す）
 *   ホワイト → ブルー → ブロンズ → シルバー → ゴールド → ダイヤモンド → レジェンド → ？？？ → ？？？
 *   はじめはホワイトとブルーだけ名前を見せ、だれかが次のランクの8割まで来たら、次の名前を明かす（サイト全体のお知らせ）
 *
 * イベント（運営者が管理画面から始める）
 *   ボーナス: 期間中の経験値が○倍 / ボス戦: 期間内にみんなで目標（メンバー数・発信者数）を倒すと、経験値の山を参加者で分ける。
 *   倒せなければ次のボスへキャリーオーバー / サンクチュアリ・飛び級: 期間中に（見せない）経験値の条件を満たすと、
 *   称号と経験値、または1年間ゴールド以上 / アテナの戦士: 運営者が選んだ人に称号と経験値
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Game {

	const OPTION      = 'snscs_game';
	const STATE       = 'snscs_game_state';
	const BOOSTS      = 'snscs_game_boosts';
	const META_DAY    = 'snscs_g_day';
	const META_WEEK   = 'snscs_g_week';
	const META_WEEKS  = 'snscs_g_weeks';
	const META_WLAST  = 'snscs_g_wlast';
	const META_RANK   = 'snscs_g_rank';
	const META_PROG   = 'snscs_g_prog';
	const META_FLOOR  = 'snscs_g_floor';
	const META_TITLES = 'snscs_g_titles';
	const META_GAUGE  = 'snscs_g_gauge';
	const ADMIN_PAGE  = 'snscs-game';
	const VISIT_XP    = 10;
	// 週の継続ボーナス: 1週あたり STREAK_XP。何週まで増えるか（STREAK_MAX）は見せない。
	const STREAK_XP  = 30;
	const STREAK_MAX = 52;
	// 1週に何日来たら「続いた週」とするか。
	const WEEK_DAYS = 3;

	/** 本人が自分で何かした（＝訪問に数える）経験値の種類 */
	const SELF_KINDS = array( 'action', 'article', 'profile' );

	/** 見せる区分 => [名前, 種類] */
	const GROUPS = array(
		'visit'    => array( '訪問・継続ボーナス', array( 'visit', 'streak' ) ),
		'action'   => array( '行動', array( 'action', 'article', 'profile' ) ),
		'referral' => array( '紹介', array( 'referral' ) ),
		'contrib'  => array( '貢献度', array( 'contrib' ) ),
		'bonus'    => array( '特典・イベント', array( 'founder', 'event', 'boss', 'rare' ) ),
	);

	public static function init() {
		add_action( 'snscs_invite_joined', array( __CLASS__, 'on_invite_joined' ), 10, 2 );
		add_action( 'snscs_article_approved', array( __CLASS__, 'on_article' ) );
		add_action( 'snscs_submission_approved', array( __CLASS__, 'on_profile' ) );
		add_action( SNSCS_Inbox::CRON_HOOK, array( __CLASS__, 'cron' ), 45 );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_snscs_game', array( __CLASS__, 'handle_admin' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_xp';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  user_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  kind varchar(12) NOT NULL,
  amount int(11) NOT NULL DEFAULT 0,
  n int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (user_id,day,kind),
  KEY day_kind (day,kind)
) {$charset};"
		);
	}

	/* --------------------------------------------------------------------
	 * 設定と状態
	 * ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			// 貢献度の重み（見せない）
			'w_ref'    => 4.0,   // 紹介リンクからの流入（1人）
			'w_bio'    => 1.5,   // FolloLinkが開かれた（1回）
			'w_view'   => 0.5,   // 発信者ページの閲覧（1人）
			'w_dwell'  => 0.15,  // 発信者ページの滞在（1分。1人3分まで）
			'w_fav'    => 2.0,   // お気に入り登録
			'w_read'   => 3.0,   // 記事の読了
			'w_aview'  => 0.4,   // 記事の閲覧
			'w_skip'   => 0.25,  // 読み飛ばし（閲覧したのに読み終えなかった）の減点
			'w_own'    => 0.1,   // 本人の利用時間（1分。1日30分まで）
			'w_react'  => 1.0,   // 記事への反応（いいね・リポストなど）
			'target'   => 40,    // 貢献のあった発信者1人あたりの、1日の貢献度の経験値の目安（倍率の自動調整に使う）
			// カードランクの条件: [直近365日の経験値, 上位○%以内（0 = 条件なし）]
			'ranks'    => array(
				'white'    => array( 0, 0 ),
				'blue'     => array( 300, 0 ),
				'bronze'   => array( 2000, 0 ),
				'silver'   => array( 8000, 0 ),
				'gold'     => array( 25000, 15 ),
				'diamond'  => array( 60000, 5 ),
				'legend'   => array( 120000, 1 ),
				'secret1'  => array( 250000, 0.3 ),
				'secret2'  => array( 500000, 0.1 ),
			),
			// 謎のランクの名前（運営者だけが知っている。明かされたときに出る）
			'secret1'  => 'アストラル',
			'secret2'  => 'ゼニス',
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$s     = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		$s['ranks'] = array_merge( self::defaults()['ranks'], (array) $s['ranks'] );
		return $s;
	}

	public static function state() {
		$state = get_option( self::STATE, array() );
		return array_merge(
			array(
				'reveal'    => 1,
				'k'         => 0,
				'carry'     => 0,
				'bonus'     => null,
				'boss'      => null,
				'sanctuary' => null,
				'skip'      => null,
				'news'      => array(),
				'done'      => '',
				'week'      => '',
			),
			is_array( $state ) ? $state : array()
		);
	}

	private static function save_state( array $state ) {
		update_option( self::STATE, $state, false );
	}

	/**
	 * サイト全体のお知らせ（トップページ・冒険の記録に出す。新しい順に5件）。
	 */
	private static function news( $text, $days = 7 ) {
		$state         = self::state();
		$state['news'] = array_slice( array_merge( array( array( time(), $text, time() + $days * DAY_IN_SECONDS ) ), (array) $state['news'] ), 0, 5 );
		self::save_state( $state );
	}

	public static function current_news() {
		return array_values( array_filter( (array) self::state()['news'], static function ( $n ) {
			return (int) $n[2] > time();
		} ) );
	}

	/* --------------------------------------------------------------------
	 * ランクと名前
	 * ------------------------------------------------------------------ */

	public static function rank_keys() {
		return array( 'white', 'blue', 'bronze', 'silver', 'gold', 'diamond', 'legend', 'secret1', 'secret2' );
	}

	public static function rank_name( $index ) {
		$names = array( 'ホワイト', 'ブルー', 'ブロンズ', 'シルバー', 'ゴールド', 'ダイヤモンド', 'レジェンド' );
		if ( $index < 7 ) {
			return $names[ max( 0, (int) $index ) ];
		}
		return (string) self::settings()[ 7 === (int) $index ? 'secret1' : 'secret2' ];
	}

	/**
	 * 見せてよい名前（まだ明かされていないランクは「？？？」）。
	 */
	public static function shown_rank_name( $index ) {
		return (int) $index <= (int) self::state()['reveal'] ? self::rank_name( $index ) : '？？？';
	}

	public static function rank_of( $user_id ) {
		$rank  = (int) get_user_meta( $user_id, self::META_RANK, true );
		$floor = self::floor_of( $user_id );
		return max( $rank, $floor );
	}

	private static function floor_of( $user_id ) {
		$floor = (string) get_user_meta( $user_id, self::META_FLOOR, true );
		if ( '' === $floor ) {
			return 0;
		}
		list( $rank, $until ) = array_map( 'intval', explode( '|', $floor ) + array( 0, 0 ) );
		return $until > time() ? $rank : 0;
	}

	/* --------------------------------------------------------------------
	 * レベル（上限なし）
	 * ------------------------------------------------------------------ */

	public static function xp_for( $level ) {
		return $level <= 1 ? 0 : (int) round( 40 * pow( $level - 1, 1.75 ) );
	}

	public static function level( $xp ) {
		$xp    = max( 0, (int) $xp );
		$level = (int) floor( 1 + pow( $xp / 40, 1 / 1.75 ) );
		while ( $level > 1 && self::xp_for( $level ) > $xp ) {
			--$level;
		}
		while ( self::xp_for( $level + 1 ) <= $xp ) {
			++$level;
		}
		return max( 1, $level );
	}

	public static function title( $level ) {
		$titles = array( 0 => 'ビギナー', 10 => 'ルーキー', 20 => '見習い勇者', 30 => '勇者', 40 => '英雄', 50 => '守護者', 60 => '覇者', 70 => '伝説', 80 => '神話', 90 => '星の守り手', 100 => '天空の覇者', 150 => '創世の勇者' );
		$out    = $titles[0];
		foreach ( $titles as $from => $name ) {
			if ( $level >= $from ) {
				$out = $name;
			}
		}
		return $out;
	}

	/**
	 * @return array{level:int,title:string,xp:int,from:int,next:int,pct:int}
	 */
	public static function level_info( $user_id ) {
		$xp    = (int) get_user_meta( $user_id, SNSCS_Gamify::META_XP, true );
		$level = self::level( $xp );
		$from  = self::xp_for( $level );
		$next  = self::xp_for( $level + 1 );
		return array(
			'level' => $level,
			'title' => self::title( $level ),
			'xp'    => $xp,
			'from'  => $from,
			'next'  => $next,
			'pct'   => (int) floor( ( $xp - $from ) / max( 1, $next - $from ) * 100 ),
		);
	}

	/* --------------------------------------------------------------------
	 * 経験値を付ける
	 * ------------------------------------------------------------------ */

	/**
	 * 経験値を付ける。創設メンバーの上乗せと、ボーナスイベントの倍率もここで付ける（別の種類として記録）。
	 * $day を指定すると、その日の記録にする（貢献度は前の日の分を、前の日の記録にする）。
	 *
	 * @return int 増えた経験値（上乗せを含む）。
	 */
	public static function grant( $user_id, $kind, $amount, $boost = true, $day = '' ) {
		$user_id = (int) $user_id;
		$amount  = (int) round( $amount );
		// 運営者と、仮登録（承認前）のメンバーには付けない。
		if ( ! $user_id || $amount <= 0 || user_can( $user_id, 'edit_others_posts' ) || ! snscs_is_full_member( $user_id ) ) {
			return 0;
		}
		$rows = array( $kind => $amount );
		if ( $boost ) {
			$rate = self::founder_rate( $user_id );
			if ( $rate > 0 ) {
				$rows['founder'] = (int) round( $amount * $rate );
			}
			$mult = self::bonus_multiplier();
			if ( $mult > 1 ) {
				$rows['event'] = (int) round( ( $amount + ( $rows['founder'] ?? 0 ) ) * ( $mult - 1 ) );
			}
		}
		$total = 0;
		foreach ( $rows as $k => $v ) {
			if ( $v > 0 ) {
				self::log( $user_id, $k, $v, $day );
				$total += $v;
			}
		}
		$before = self::level( (int) get_user_meta( $user_id, SNSCS_Gamify::META_XP, true ) );
		$xp     = (int) get_user_meta( $user_id, SNSCS_Gamify::META_XP, true ) + $total;
		update_user_meta( $user_id, SNSCS_Gamify::META_XP, $xp );
		$after = self::level( $xp );
		if ( $after > $before ) {
			SNSCS_Inbox::add( $user_id, 'level_up', $after );
		}
		if ( in_array( $kind, self::SELF_KINDS, true ) ) {
			self::visit( $user_id );
		}
		if ( $boost ) {
			self::check_quests( $user_id );
		}
		return $total;
	}

	private static function log( $user_id, $kind, $amount, $day = '' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::table() . ' (user_id, day, kind, amount, n) VALUES (%d, %s, %s, %d, 1) ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount), n = n + 1', $user_id, $day ? $day : current_time( 'Y-m-d' ), $kind, $amount ) );
	}

	/**
	 * 小さな行動（読む・反応など）。レベルが上がるほど少なくなる（弱い敵）。
	 */
	public static function action( $user_id, $points, $kind = 'action' ) {
		if ( 'referral' === $kind ) {
			return self::grant( $user_id, 'referral', $points );
		}
		$level = self::level( (int) get_user_meta( $user_id, SNSCS_Gamify::META_XP, true ) );
		return self::grant( $user_id, $kind, max( 1, $points * max( 0.25, 1 - ( $level - 1 ) / 80 ) ) );
	}

	/**
	 * 何か行動した日（1日1回）: 訪問の経験値と、週の継続ボーナス。
	 */
	public static function visit( $user_id ) {
		$today = current_time( 'Y-m-d' );
		if ( get_user_meta( $user_id, self::META_DAY, true ) === $today ) {
			return;
		}
		update_user_meta( $user_id, self::META_DAY, $today );
		self::grant( $user_id, 'visit', self::VISIT_XP );

		$week = current_time( 'o-W' );
		$data = explode( '|', (string) get_user_meta( $user_id, self::META_WEEK, true ) ) + array( '', 0 );
		$days = $data[0] === $week ? (int) $data[1] + 1 : 1;
		update_user_meta( $user_id, self::META_WEEK, $week . '|' . $days );
		if ( $days < self::WEEK_DAYS || get_user_meta( $user_id, self::META_WLAST, true ) === $week ) {
			return;
		}
		$prev  = gmdate( 'o-W', strtotime( $today . ' -7 days' ) );
		$weeks = get_user_meta( $user_id, self::META_WLAST, true ) === $prev ? (int) get_user_meta( $user_id, self::META_WEEKS, true ) + 1 : 1;
		update_user_meta( $user_id, self::META_WLAST, $week );
		update_user_meta( $user_id, self::META_WEEKS, $weeks );
		self::grant( $user_id, 'streak', self::STREAK_XP * min( $weeks, self::STREAK_MAX ) );
	}

	/**
	 * いま続いている週の数（先週か今週に条件を満たしていれば続いている）。
	 */
	public static function weeks( $user_id ) {
		$last = (string) get_user_meta( $user_id, self::META_WLAST, true );
		if ( current_time( 'o-W' ) !== $last && gmdate( 'o-W', strtotime( current_time( 'Y-m-d' ) . ' -7 days' ) ) !== $last ) {
			return 0;
		}
		return (int) get_user_meta( $user_id, self::META_WEEKS, true );
	}

	/**
	 * 創設メンバーの上乗せの割合（掲載から24か月で0まで減る）。
	 */
	public static function founder_rate( $user_id ) {
		static $cache = array();
		if ( isset( $cache[ $user_id ] ) ) {
			return $cache[ $user_id ];
		}
		$rate    = 0.0;
		$creator = SNSCS_Submissions::creator_id_for_user( $user_id );
		$no      = $creator && 'publish' === get_post_status( $creator ) ? SNSCS_Founder::number( $creator ) : 0;
		if ( $no ) {
			$base   = SNSCS_Founder::is_first( $no ) ? 0.6 : array( 'noir' => 0.5, 'midnight' => 0.4, 'emerald' => 0.3, 'platinum' => 0.2 )[ SNSCS_Founder::design( $no ) ];
			$months = ( time() - (int) get_post_time( 'U', true, $creator ) ) / ( 30 * DAY_IN_SECONDS );
			$rate   = round( $base * max( 0, 1 - $months / 24 ), 3 );
		}
		return $cache[ $user_id ] = $rate;
	}

	public static function bonus_multiplier() {
		$bonus = self::state()['bonus'];
		return $bonus && (int) $bonus['until'] > time() ? max( 1, (float) $bonus['mult'] ) : 1.0;
	}

	/* --------------------------------------------------------------------
	 * ほかの機能からの経験値
	 * ------------------------------------------------------------------ */

	private static function user_of_creator( $creator_id ) {
		return (int) get_post_field( 'post_author', (int) $creator_id );
	}

	/** 紹介した発信者が掲載された（強い敵） */
	public static function on_invite_joined( $inviter_creator, $new_creator ) {
		$user = self::user_of_creator( $inviter_creator );
		if ( $user && ! get_post_meta( $new_creator, '_snscs_g_ref', true ) ) {
			update_post_meta( $new_creator, '_snscs_g_ref', 1 );
			self::grant( $user, 'referral', 1000 );
		}
	}

	/** 記事が公開された（1本につき1回） */
	public static function on_article( $article_id ) {
		$creator = SNSCS_Articles::creator_of( $article_id );
		$user    = $creator ? self::user_of_creator( $creator ) : 0;
		if ( $user && ! get_post_meta( $article_id, '_snscs_g_xp', true ) ) {
			update_post_meta( $article_id, '_snscs_g_xp', 1 );
			self::grant( $user, 'article', 30 );
		}
	}

	/** プロフィールの更新が承認された（月1回まで） */
	public static function on_profile( $submission_id ) {
		$row  = SNSCS_Submissions::get( $submission_id );
		$user = $row ? (int) $row['user_id'] : 0;
		if ( $user && get_user_meta( $user, 'snscs_g_profile', true ) !== current_time( 'Y-m' ) ) {
			update_user_meta( $user, 'snscs_g_profile', current_time( 'Y-m' ) );
			self::grant( $user, 'profile', 50 );
		}
	}

	/* --------------------------------------------------------------------
	 * 集計
	 * ------------------------------------------------------------------ */

	/**
	 * 期間の経験値（区分ごと）。
	 *
	 * @return array<string,int> 区分 => 経験値（'total' も）
	 */
	public static function sums( $user_id, $from, $to ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT kind, SUM(amount) AS a FROM ' . self::table() . ' WHERE user_id = %d AND day >= %s AND day <= %s GROUP BY kind', $user_id, $from, $to ), ARRAY_A );
		$by   = wp_list_pluck( $rows, 'a', 'kind' );
		$out  = array( 'total' => 0 );
		foreach ( self::GROUPS as $key => $group ) {
			$out[ $key ] = 0;
			foreach ( $group[1] as $kind ) {
				$out[ $key ] += (int) ( $by[ $kind ] ?? 0 );
			}
			$out['total'] += $out[ $key ];
		}
		return $out;
	}

	/**
	 * 週（月曜〜日曜）。$ago = 0 で今週、1 で先週。
	 *
	 * @return string[] [最初の日, 最後の日]
	 */
	public static function week_range( $ago = 0 ) {
		$today  = current_time( 'Y-m-d' );
		$monday = gmdate( 'Y-m-d', strtotime( $today . ' -' . ( (int) gmdate( 'N', strtotime( $today ) ) - 1 ) . ' days' ) );
		$from   = gmdate( 'Y-m-d', strtotime( $monday . ' -' . ( 7 * $ago ) . ' days' ) );
		return array( $from, gmdate( 'Y-m-d', strtotime( $from . ' +6 days' ) ) );
	}

	/**
	 * 紹介で使い始めた人数（期間内）。
	 */
	public static function referral_count( $user_id, $from, $to ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(n) FROM ' . self::table() . " WHERE user_id = %d AND kind = 'referral' AND day >= %s AND day <= %s", $user_id, $from, $to ) );
	}

	/* --------------------------------------------------------------------
	 * 毎日の計算（見回りの中で1日1回、3時以降）
	 * ------------------------------------------------------------------ */

	public static function cron( $force = false ) {
		$state = self::state();
		$today = current_time( 'Y-m-d' );
		if ( ! $force && ( (int) current_time( 'G' ) < 3 || $state['done'] === $today ) ) {
			return;
		}
		$state['done'] = $today;
		self::save_state( $state );
		$yesterday = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );
		self::contribution( $yesterday );
		self::settle_boss();
		self::update_ranks();
		if ( '1' === current_time( 'N' ) || $force ) {
			self::weekly();
		}
		// 日ごとの記録は2年で消す（プライバシーポリシーのとおり）。
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE day < %s', gmdate( 'Y-m-d', strtotime( $today . ' -730 days' ) ) ) );
	}

	/**
	 * 貢献度（前の日の分）。重みと倍率は見せない。
	 *
	 * @return array<int,int> 発信者ID => 付けた経験値
	 */
	public static function contribution( $day ) {
		global $wpdb;
		$s = self::settings();
		if ( get_option( 'snscs_game_contrib_day' ) === $day ) {
			return array();
		}
		update_option( 'snscs_game_contrib_day', $day, false );
		$score = array();
		$add   = static function ( $rows, $weight ) use ( &$score ) {
			foreach ( (array) $rows as $row ) {
				$id           = (int) $row['c'];
				$score[ $id ] = ( $score[ $id ] ?? 0 ) + $weight * (float) $row['v'];
			}
		};
		$p = $wpdb->prefix;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$add( $wpdb->get_results( $wpdb->prepare( "SELECT creator_id AS c, COUNT(*) AS v FROM {$p}snscs_clicks WHERE clicked_on = %s GROUP BY creator_id", $day ), ARRAY_A ), $s['w_ref'] );
		$add( $wpdb->get_results( $wpdb->prepare( "SELECT creator_id AS c, SUM(clicks) AS v FROM {$p}snscs_link_clicks WHERE day = %s AND link_key = 'view:page' GROUP BY creator_id", $day ), ARRAY_A ), $s['w_bio'] );
		$add( $wpdb->get_results( $wpdb->prepare( "SELECT creator_id AS c, views AS v FROM {$p}snscs_daily WHERE day = %s", $day ), ARRAY_A ), $s['w_view'] );
		$add( $wpdb->get_results( $wpdb->prepare( "SELECT creator_id AS c, LEAST(dwell_sum / 60, views * 3) AS v FROM {$p}snscs_daily WHERE day = %s", $day ), ARRAY_A ), $s['w_dwell'] );
		$add( $wpdb->get_results( $wpdb->prepare( "SELECT creator_id AS c, favs AS v FROM {$p}snscs_daily WHERE day = %s", $day ), ARRAY_A ), $s['w_fav'] );
		$add( $wpdb->get_results( $wpdb->prepare( "SELECT creator_id AS c, LEAST(active_seconds / 60, 30) AS v FROM {$p}snscs_usage WHERE day = %s", $day ), ARRAY_A ), $s['w_own'] );
		$articles = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_value AS c, SUM(a.views) AS views, SUM(a.completes) AS done FROM {$p}snscs_article_daily a INNER JOIN {$wpdb->postmeta} m ON m.post_id = a.article_id AND m.meta_key = '_snscs_creator_id' WHERE a.day = %s GROUP BY m.meta_value", $day ), ARRAY_A );
		$reacts   = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_value AS c, COUNT(*) AS v FROM {$p}snscs_reactions r INNER JOIN {$wpdb->postmeta} m ON m.post_id = r.article_id AND m.meta_key = '_snscs_creator_id' WHERE r.created_at >= %s AND r.created_at < %s GROUP BY m.meta_value", $day . ' 00:00:00', gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) ) . ' 00:00:00' ), ARRAY_A );
		// phpcs:enable
		foreach ( (array) $articles as $row ) {
			$id           = (int) $row['c'];
			$skips        = max( 0, (int) $row['views'] - (int) $row['done'] );
			$score[ $id ] = ( $score[ $id ] ?? 0 ) + $s['w_read'] * (int) $row['done'] + $s['w_aview'] * (int) $row['views'] - $s['w_skip'] * $skips;
		}
		$add( $reacts, $s['w_react'] );

		$score = array_filter( $score, static function ( $v ) {
			return $v > 0;
		} );
		if ( ! $score ) {
			return array();
		}
		// 倍率の自動調整: 貢献のあった人の平均が、目安に近づくように少しずつ動かす。
		$powered = array_map( static function ( $v ) {
			return pow( $v, 0.9 );
		}, $score );
		$want    = (float) $s['target'] / max( 0.001, array_sum( $powered ) / count( $powered ) );
		$state   = self::state();
		$k       = $state['k'] > 0 ? 0.7 * $state['k'] + 0.3 * $want : $want;
		$k       = min( 50, max( 0.05, $k ) );
		$state['k'] = $k;
		self::save_state( $state );

		$given = array();
		foreach ( $powered as $creator => $v ) {
			$user = self::user_of_creator( $creator );
			if ( ! $user || 'publish' !== get_post_status( $creator ) ) {
				continue;
			}
			$xp = (int) round( $k * $v );
			if ( $xp > 0 ) {
				// 貢献度は、稼いだ日（前の日）の記録にする。
				$given[ $creator ] = self::grant( $user, 'contrib', $xp, true, $day );
			}
		}
		return $given;
	}

	/**
	 * カードランク・貢献度ゲージ・検索での加点を見直す（毎日）。
	 */
	public static function update_ranks() {
		global $wpdb;
		$s     = self::settings();
		$since = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -365 days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, SUM(amount) AS xp FROM ' . self::table() . ' WHERE day >= %s GROUP BY user_id ORDER BY xp DESC', $since ), ARRAY_A );
		$total = count( $rows );
		$keys  = self::rank_keys();
		$state = self::state();
		$near  = (int) $state['reveal'];
		$seen  = array();
		foreach ( $rows as $i => $row ) {
			$user = (int) $row['user_id'];
			$xp   = (int) $row['xp'];
			$top  = ( $i + 1 ) / max( 1, $total ) * 100;
			$rank = 0;
			foreach ( $keys as $index => $key ) {
				list( $need, $pct ) = $s['ranks'][ $key ];
				if ( $xp >= (int) $need && ( ! (float) $pct || $top <= (float) $pct ) ) {
					$rank = $index;
				}
			}
			$rank   = max( $rank, self::floor_of( $user ) );
			$next   = $keys[ $rank + 1 ] ?? null;
			$prog   = $next ? min( 1, $xp / max( 1, (int) $s['ranks'][ $next ][0] ) ) : 1;
			$before = (int) get_user_meta( $user, self::META_RANK, true );
			update_user_meta( $user, self::META_RANK, $rank );
			update_user_meta( $user, self::META_PROG, round( $prog, 3 ) );
			if ( $rank > $before ) {
				SNSCS_Inbox::add( $user, 'rank_up', $rank );
			}
			// 次のランクの8割まで来た人がいたら（または、もう届いた人がいたら）、次の名前を明かす。
			if ( $rank > $near ) {
				$near = $rank;
			} elseif ( $rank === $near && $prog >= 0.8 && $rank < 8 ) {
				$near = $rank + 1;
			}
			$seen[ $user ] = true;
		}
		// 1年間経験値の無い人はホワイトに戻す。
		$stale = get_users( array( 'meta_key' => self::META_RANK, 'fields' => 'ID', 'number' => 5000 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( $stale as $user ) {
			if ( ! isset( $seen[ (int) $user ] ) && ! self::floor_of( (int) $user ) ) {
				delete_user_meta( (int) $user, self::META_RANK );
			}
		}
		if ( $near > (int) $state['reveal'] ) {
			self::reveal( $near );
		}
		self::update_boosts();
	}

	/**
	 * ランクの名前を明かす（サイト全体のお知らせ）。
	 */
	public static function reveal( $upto ) {
		$state = self::state();
		$from  = (int) $state['reveal'];
		$upto  = min( 8, (int) $upto );
		if ( $upto <= $from ) {
			return;
		}
		$state['reveal'] = $upto;
		self::save_state( $state );
		for ( $i = $from + 1; $i <= $upto; $i++ ) {
			self::news( '新たなカードランク「' . self::rank_name( $i ) . '」の存在が明らかになった…', 14 );
		}
	}

	/**
	 * 貢献度ゲージ（★1〜5と先週との比較）と、検索での加点（毎日）。
	 */
	private static function update_boosts() {
		global $wpdb;
		$to    = current_time( 'Y-m-d' );
		$from  = gmdate( 'Y-m-d', strtotime( $to . ' -7 days' ) );
		$prev  = gmdate( 'Y-m-d', strtotime( $to . ' -14 days' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$now  = wp_list_pluck( $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, SUM(amount) AS a FROM ' . self::table() . " WHERE kind = 'contrib' AND day >= %s AND day < %s GROUP BY user_id ORDER BY a ASC", $from, $to ), ARRAY_A ), 'a', 'user_id' );
		$last = wp_list_pluck( $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, SUM(amount) AS a FROM ' . self::table() . " WHERE kind = 'contrib' AND day >= %s AND day < %s GROUP BY user_id", $prev, $from ), ARRAY_A ), 'a', 'user_id' );
		// phpcs:enable
		$n      = count( $now );
		$i      = 0;
		$boosts = array();
		foreach ( $now as $user => $value ) {
			$stars = min( 5, 1 + (int) floor( $i / max( 1, $n ) * 5 ) );
			++$i;
			$before = (int) ( $last[ $user ] ?? 0 );
			$trend  = $value > $before * 1.1 ? 'up' : ( $value < $before * 0.9 ? 'down' : 'flat' );
			update_user_meta( (int) $user, self::META_GAUGE, $stars . '|' . $trend . '|' . $to );
			$creator = SNSCS_Submissions::creator_id_for_user( (int) $user );
			if ( $creator ) {
				$boosts[ $creator ] = $stars;
			}
		}
		// 検索での加点: カードランクと貢献度で、最大6%（上限つき。検索の質を落とさない範囲）。
		$out = array();
		foreach ( get_users( array( 'meta_key' => self::META_RANK, 'fields' => 'ID', 'number' => 5000 ) ) as $user ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$creator = SNSCS_Submissions::creator_id_for_user( (int) $user );
			if ( $creator ) {
				$out[ $creator ] = 1 + min( 0.04, 0.005 * self::rank_of( (int) $user ) );
			}
		}
		foreach ( $boosts as $creator => $stars ) {
			$out[ $creator ] = min( 1.06, ( $out[ $creator ] ?? 1 ) + 0.004 * $stars );
		}
		update_option( self::BOOSTS, $out, false );
	}

	/**
	 * 検索の並びでの加点（発信者ID => 倍率）。
	 *
	 * @return array<int,float>
	 */
	public static function search_boosts() {
		static $boosts = null;
		if ( null === $boosts ) {
			$boosts = (array) get_option( self::BOOSTS, array() );
		}
		return $boosts;
	}

	/**
	 * @return array{stars:int,trend:string}|null
	 */
	public static function gauge( $user_id ) {
		$raw = (string) get_user_meta( $user_id, self::META_GAUGE, true );
		if ( '' === $raw ) {
			return null;
		}
		list( $stars, $trend, $day ) = explode( '|', $raw ) + array( 0, 'flat', '' );
		if ( strtotime( $day ) < strtotime( current_time( 'Y-m-d' ) . ' -14 days' ) ) {
			return null;
		}
		return array( 'stars' => (int) $stars, 'trend' => $trend );
	}

	/**
	 * 週に1回: 先週の実績をお知らせ（メールにも）。
	 */
	private static function weekly() {
		$state = self::state();
		list( $from, $to ) = self::week_range( 1 );
		if ( $state['week'] === $from ) {
			return;
		}
		$state['week'] = $from;
		self::save_state( $state );
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$users = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT user_id FROM ' . self::table() . ' WHERE day >= %s AND day <= %s LIMIT 20000', $from, $to ) );
		foreach ( $users as $user ) {
			SNSCS_Inbox::add( (int) $user, 'game_week', (int) str_replace( '-', '', $from ) );
		}
	}

	/* --------------------------------------------------------------------
	 * イベント
	 * ------------------------------------------------------------------ */

	/**
	 * サンクチュアリ・飛び級: 期間中に（見せない）経験値の条件を満たしたか。
	 */
	private static function check_quests( $user_id ) {
		$state = self::state();
		foreach ( array( 'sanctuary', 'skip' ) as $key ) {
			$ev = $state[ $key ];
			if ( ! $ev || (int) $ev['until'] < time() ) {
				continue;
			}
			$titles = (array) get_user_meta( $user_id, self::META_TITLES, true );
			if ( isset( $titles[ $key . '_' . $ev['id'] ] ) ) {
				continue;
			}
			$got = self::sums( $user_id, gmdate( 'Y-m-d', (int) $ev['start'] + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ), current_time( 'Y-m-d' ) )['total'];
			if ( $got < (int) $ev['need'] ) {
				continue;
			}
			$titles[ $key . '_' . $ev['id'] ] = current_time( 'Y-m-d' );
			update_user_meta( $user_id, self::META_TITLES, $titles );
			if ( 'sanctuary' === $key ) {
				self::grant( $user_id, 'rare', (int) $ev['reward'], false );
				SNSCS_Inbox::add( $user_id, 'game_rare', 1 );
				self::news( 'だれかがサンクチュアリにたどり着いた…', 3 );
			} else {
				update_user_meta( $user_id, self::META_FLOOR, '4|' . ( time() + YEAR_IN_SECONDS ) );
				update_user_meta( $user_id, self::META_RANK, max( 4, (int) get_user_meta( $user_id, self::META_RANK, true ) ) );
				SNSCS_Inbox::add( $user_id, 'game_rare', 2 );
				self::news( 'だれかが「飛び級」でゴールドに駆け上がった…', 3 );
				self::reveal( 4 );
			}
		}
	}

	/**
	 * 称号（サンクチュアリ・アテナの戦士・飛び級）。
	 *
	 * @return string[]
	 */
	public static function titles( $user_id ) {
		$names = array( 'sanctuary' => 'サンクチュアリの到達者', 'skip' => '飛び級', 'athena' => 'アテナの戦士' );
		$out   = array();
		foreach ( array_keys( (array) get_user_meta( $user_id, self::META_TITLES, true ) ) as $key ) {
			$base = strtok( (string) $key, '_' );
			if ( isset( $names[ $base ] ) ) {
				$out[ $base ] = $names[ $base ];
			}
		}
		return array_values( $out );
	}

	/**
	 * ボス戦の進み具合。
	 *
	 * @return array{title:string,target:int,now:int,pool:int,until:int,metric:string}|null
	 */
	public static function boss() {
		$boss = self::state()['boss'];
		if ( ! $boss ) {
			return null;
		}
		return array(
			'title'  => (string) $boss['title'],
			'target' => (int) $boss['target'],
			'now'    => self::boss_count( $boss ),
			'pool'   => (int) $boss['pool'],
			'until'  => (int) $boss['end'],
			'metric' => (string) $boss['metric'],
		);
	}

	private static function boss_count( array $boss ) {
		global $wpdb;
		$from = gmdate( 'Y-m-d H:i:s', (int) $boss['start'] );
		$to   = gmdate( 'Y-m-d H:i:s', min( time(), (int) $boss['end'] ) );
		if ( 'creators' === $boss['metric'] ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_date_gmt >= %s AND post_date_gmt <= %s", SNSCS_Post_Types::POST_TYPE, $from, $to ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered >= %s AND user_registered <= %s", $from, $to ) );
	}

	/**
	 * 期限が来たボス戦を締める: 倒したら参加者で山分け、倒せなければ次へキャリーオーバー。
	 */
	public static function settle_boss( $force = false ) {
		$state = self::state();
		$boss  = $state['boss'];
		if ( ! $boss || ( ! $force && (int) $boss['end'] > time() ) ) {
			return;
		}
		$count = self::boss_count( $boss );
		if ( $count >= (int) $boss['target'] ) {
			global $wpdb;
			$offset = (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$users = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT user_id FROM ' . self::table() . " WHERE day >= %s AND day <= %s AND kind IN ('visit','action','article','profile','referral','contrib','streak')", gmdate( 'Y-m-d', (int) $boss['start'] + $offset ), gmdate( 'Y-m-d', (int) $boss['end'] + $offset ) ) ) );
			$share = $users ? (int) floor( (int) $boss['pool'] / count( $users ) ) : 0;
			foreach ( $share ? $users : array() as $user ) {
				self::grant( $user, 'boss', $share, false );
				SNSCS_Inbox::add( $user, 'game_boss', $share );
			}
			$state = self::state();
			$state['carry'] = $users ? 0 : (int) $boss['pool'];
			$state['boss']  = null;
			self::save_state( $state );
			self::news( 'ボス「' . $boss['title'] . '」を倒した！ ' . number_format_i18n( count( $users ) ) . '人で' . number_format_i18n( (int) $boss['pool'] ) . '経験値を山分け', 7 );
		} else {
			$state['carry'] = (int) $boss['pool'];
			$state['boss']  = null;
			self::save_state( $state );
			self::news( 'ボス「' . $boss['title'] . '」は逃げた…' . number_format_i18n( (int) $boss['pool'] ) . '経験値は次のボスへキャリーオーバー', 14 );
		}
	}

	/* --------------------------------------------------------------------
	 * お知らせの文
	 * ------------------------------------------------------------------ */

	public static function describe( array $row ) {
		$url = SNSCS_Social::member_url( array( 'tab' => 'level' ) );
		switch ( $row['type'] ) {
			case 'level_up':
				$lv = (int) $row['object_id'];
				return array( 'text' => 'レベルが上がった！ Lv.' . $lv . '「' . self::title( $lv ) . '」になりました。', 'url' => $url );
			case 'rank_up':
				return array( 'text' => 'カードランクが「' . self::rank_name( (int) $row['object_id'] ) . '」に上がりました！', 'url' => $url );
			case 'game_week':
				$from = DateTime::createFromFormat( 'Ymd', (string) $row['object_id'] );
				if ( ! $from ) {
					return null;
				}
				$f    = $from->format( 'Y-m-d' );
				$sums = self::sums( (int) $row['user_id'], $f, gmdate( 'Y-m-d', strtotime( $f . ' +6 days' ) ) );
				$info = self::level_info( (int) $row['user_id'] );
				$parts = array();
				foreach ( self::GROUPS as $key => $group ) {
					if ( $sums[ $key ] ) {
						$parts[] = $group[0] . ' ' . number_format_i18n( $sums[ $key ] );
					}
				}
				return array(
					'text' => '先週の獲得経験値は ' . number_format_i18n( $sums['total'] ) . '（' . implode( '・', $parts ) . '）。いまは Lv.' . $info['level'] . ' ' . $info['title'] . '、カードランクは「' . self::rank_name( self::rank_of( (int) $row['user_id'] ) ) . '」です。',
					'url'  => $url,
				);
			case 'game_boss':
				return array( 'text' => 'みんなでボスを倒した！ ' . number_format_i18n( (int) $row['object_id'] ) . '経験値を手に入れました。', 'url' => $url );
			case 'game_rare':
				return array( 'text' => 1 === (int) $row['object_id'] ? 'サンクチュアリにたどり着いた…称号「サンクチュアリの到達者」を手に入れました。' : ( 2 === (int) $row['object_id'] ? '「飛び級」！ 1年間、カードランクがゴールド以上になりました。' : '称号「アテナの戦士」に選ばれました。' ), 'url' => $url );
		}
		return null;
	}

	/* --------------------------------------------------------------------
	 * 表示: 冒険の記録（メンバーページのタブ）
	 * ------------------------------------------------------------------ */

	public static function stars_html( $user_id ) {
		$g = self::gauge( $user_id );
		if ( ! $g ) {
			return '<span class="snscs-gauge is-empty">—</span>';
		}
		$arrow = array( 'up' => '↑', 'down' => '↓', 'flat' => '→' )[ $g['trend'] ] ?? '→';
		$label = array( 'up' => '先週より上がった', 'down' => '先週より下がった', 'flat' => '先週と同じくらい' )[ $g['trend'] ] ?? '';
		return '<span class="snscs-gauge" title="' . esc_attr( '貢献度 ★' . $g['stars'] . '（' . $label . '）' ) . '"><span class="snscs-gauge__stars">' . str_repeat( '★', $g['stars'] ) . '<span>' . str_repeat( '★', 5 - $g['stars'] ) . '</span></span><span class="snscs-gauge__trend is-' . esc_attr( $g['trend'] ) . '">' . $arrow . '</span></span>';
	}

	/**
	 * ランクのはしご（明かされていないランクは「？？？」）。
	 */
	public static function ladder_html( $user_id ) {
		$rank = self::rank_of( $user_id );
		$prog = (float) get_user_meta( $user_id, self::META_PROG, true );
		$out  = '<ol class="snscs-ladder">';
		foreach ( array_reverse( self::rank_keys(), true ) as $index => $key ) {
			$state = $index === $rank ? 'is-now' : ( $index < $rank ? 'is-done' : 'is-next' );
			$out  .= '<li class="snscs-ladder__step snscs-ladder__step--' . esc_attr( $index <= (int) self::state()['reveal'] ? $key : 'secret' ) . ' ' . $state . '"><span>' . esc_html( self::shown_rank_name( $index ) ) . '</span>'
				. ( $index === $rank ? '<em>いまここ</em>' : '' )
				. ( $index === $rank + 1 ? '<span class="snscs-ladder__bar"><span style="width:' . (int) round( $prog * 100 ) . '%"></span></span>' : '' ) . '</li>';
		}
		return $out . '</ol>';
	}

	public static function render_member( $user_id ) {
		wp_enqueue_style( 'snscs' );
		$info  = self::level_info( $user_id );
		$rank  = self::rank_of( $user_id );
		$weeks = self::weeks( $user_id );
		echo '<section class="snscs-quest">';
		echo '<div class="snscs-quest__head"><p class="snscs-quest__lv"><strong>Lv.' . (int) $info['level'] . '</strong> ' . esc_html( $info['title'] ) . '</p>';
		echo '<div class="snscs-mylevel__bar"><span style="width:' . max( 2, $info['pct'] ) . '%"></span></div>';
		echo '<p class="snscs-help">' . esc_html( '経験値 ' . number_format_i18n( $info['xp'] ) . '（次のレベルまで ' . number_format_i18n( $info['next'] - $info['xp'] ) . '）' ) . '</p>';
		echo '<p class="snscs-quest__meta">カードランク <strong>' . esc_html( self::rank_name( $rank ) ) . '</strong>　貢献度 ' . self::stars_html( $user_id ) . '　継続 <strong>' . ( $weeks ? (int) $weeks . '週' : '—' ) . '</strong></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		$titles = self::titles( $user_id );
		if ( $titles ) {
			echo '<p class="snscs-quest__titles">' . implode( '', array_map( static function ( $t ) {
				return '<span>' . esc_html( $t ) . '</span>';
			}, $titles ) ) . '</p>';
		}
		echo '</div>';

		foreach ( array( 0 => '今週', 1 => '先週' ) as $ago => $label ) {
			list( $from, $to ) = self::week_range( $ago );
			$sums = self::sums( $user_id, $from, $to );
			$refs = self::referral_count( $user_id, $from, $to );
			echo '<h3>' . esc_html( $label . 'の獲得経験値' ) . ' <span class="snscs-quest__total">' . esc_html( number_format_i18n( $sums['total'] ) ) . '</span></h3><table class="snscs-quest__table"><tbody>';
			foreach ( self::GROUPS as $key => $group ) {
				$note = '';
				if ( 'visit' === $key && 0 === $ago && $weeks ) {
					$note = '（' . $weeks . '週続いています）';
				} elseif ( 'referral' === $key && $refs ) {
					$note = '（' . $refs . '人）';
				} elseif ( 'contrib' === $key ) {
					$note = '（流入・閲覧などから計算）';
				}
				echo '<tr><th>' . esc_html( $group[0] ) . '<small>' . esc_html( $note ) . '</small></th><td>' . esc_html( number_format_i18n( $sums[ $key ] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h3>カードランク</h3>' . self::ladder_html( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo '<p class="snscs-help">カードランクは、直近1年間の経験値などで毎日見直されます。上のランクほど、たどり着けるメンバーはわずかです。</p>';

		echo self::events_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo '<details class="snscs-quest__how"><summary>経験値のもらい方</summary><ul>'
			. '<li>何か行動した日（読む・反応する・記事を公開する など）は「訪問」として経験値。週に3日以上続けると「継続ボーナス」。続くほど大きくなります（どこまで大きくなるかは、ひみつ）</li>'
			. '<li>記事の公開・プロフィールの更新・質問箱への回答など、行動ごとに経験値</li>'
			. '<li>紹介した人が登録して使い始めると、大きな経験値。紹介した人がメンバーとして掲載されると、さらに大きな経験値</li>'
			. '<li>「貢献度」: あなたの発信が、FolloFind にどれだけ人を連れてきたか・読まれたかを、運営者が定める方法で毎日計算します（計算方法は公開しません）</li>'
			. '<li>経験値は、本サービスの中のゲームの記録です。金銭や商品と交換することはできません</li></ul></details>';
		echo '</section>';
	}

	/**
	 * いま開いているイベント（ボーナス・ボス戦・ひみつのうわさ）と、サイト全体のお知らせ。
	 */
	public static function events_html( $compact = false ) {
		$state = self::state();
		$out   = '';
		if ( self::bonus_multiplier() > 1 ) {
			$b    = $state['bonus'];
			$out .= '<li class="snscs-event snscs-event--bonus"><strong>' . esc_html( '' !== (string) $b['title'] ? $b['title'] : 'ボーナスイベント' ) . '</strong> 経験値 ×' . esc_html( rtrim( rtrim( number_format( (float) $b['mult'], 1 ), '0' ), '.' ) ) . '<span>' . esc_html( wp_date( 'n/j G:i', (int) $b['until'] ) . 'まで' ) . '</span></li>';
		}
		$boss = self::boss();
		if ( $boss ) {
			$pct  = min( 100, (int) floor( $boss['now'] / max( 1, $boss['target'] ) * 100 ) );
			$out .= '<li class="snscs-event snscs-event--boss"><strong>' . esc_html( 'ボス戦「' . $boss['title'] . '」' ) . '</strong>'
				. esc_html( ( 'creators' === $boss['metric'] ? '新しい本登録メンバー' : '新しいメンバー' ) . ' ' . number_format_i18n( $boss['now'] ) . ' / ' . number_format_i18n( $boss['target'] ) . '人' )
				. '<span class="snscs-event__bar"><span style="width:' . $pct . '%"></span></span>'
				. '<span>' . esc_html( '倒せば ' . number_format_i18n( $boss['pool'] ) . ' 経験値を参加者で山分け・' . wp_date( 'n/j', $boss['until'] ) . 'まで' ) . '</span></li>';
		} elseif ( (int) $state['carry'] > 0 ) {
			$out .= '<li class="snscs-event snscs-event--carry"><strong>キャリーオーバー</strong>' . esc_html( number_format_i18n( (int) $state['carry'] ) . ' 経験値が、次のボスに積み上がっています' ) . '</li>';
		}
		foreach ( array( 'sanctuary' => 'どこかで、サンクチュアリの扉が開いている…', 'skip' => '「飛び級」のうわさが流れている…' ) as $key => $text ) {
			if ( $state[ $key ] && (int) $state[ $key ]['until'] > time() ) {
				$out .= '<li class="snscs-event snscs-event--rumor">' . esc_html( $text ) . '</li>';
			}
		}
		foreach ( array_slice( self::current_news(), 0, $compact ? 2 : 5 ) as $news ) {
			$out .= '<li class="snscs-event snscs-event--news">' . esc_html( $news[1] ) . '</li>';
		}
		if ( '' === $out ) {
			return '';
		}
		return ( $compact ? '' : '<h3>イベント</h3>' ) . '<ul class="snscs-events">' . $out . '</ul>';
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, 'ゲーム（経験値）', 'ゲーム（経験値）', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	public static function handle_admin() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'snscs_game' ) ) {
			wp_die( '権限がありません。' );
		}
		$do    = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$state = self::state();
		$msg   = 'saved';
		$days  = static function ( $key, $default ) {
			return max( 1, min( 365, absint( $_POST[ $key ] ?? $default ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		};
		switch ( $do ) {
			case 'bonus':
				$state['bonus'] = array(
					'mult'  => min( 10, max( 1.1, (float) ( $_POST['mult'] ?? 2 ) ) ),
					'until' => time() + max( 1, min( 720, absint( $_POST['hours'] ?? 48 ) ) ) * HOUR_IN_SECONDS,
					'title' => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
				);
				self::save_state( $state );
				break;
			case 'bonus_stop':
				$state['bonus'] = null;
				self::save_state( $state );
				break;
			case 'boss':
				$state['boss'] = array(
					'title'  => sanitize_text_field( wp_unslash( $_POST['title'] ?? 'ボス' ) ),
					'metric' => 'creators' === ( $_POST['metric'] ?? '' ) ? 'creators' : 'members',
					'target' => max( 1, absint( $_POST['target'] ?? 100 ) ),
					'start'  => time(),
					'end'    => time() + $days( 'days', 30 ) * DAY_IN_SECONDS,
					'pool'   => max( 1, absint( $_POST['pool'] ?? 1000000 ) ) + (int) $state['carry'],
				);
				$state['carry'] = 0;
				self::save_state( $state );
				self::news( 'ボス「' . $state['boss']['title'] . '」が現れた！', 3 );
				break;
			case 'boss_settle':
				self::settle_boss( true );
				break;
			case 'sanctuary':
			case 'skip':
				$state[ $do ] = array(
					'id'     => wp_generate_password( 6, false ),
					'start'  => time(),
					'until'  => time() + $days( 'days', 7 ) * DAY_IN_SECONDS,
					'need'   => max( 1, absint( $_POST['need'] ?? 1000 ) ),
					'reward' => absint( $_POST['reward'] ?? 5000 ),
				);
				self::save_state( $state );
				break;
			case 'close':
				$key = sanitize_key( wp_unslash( $_POST['key'] ?? '' ) );
				if ( in_array( $key, array( 'sanctuary', 'skip' ), true ) ) {
					$state[ $key ] = null;
					self::save_state( $state );
				}
				break;
			case 'athena':
				$user = get_user_by( 'email', sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) );
				if ( ! $user ) {
					$msg = 'nouser';
					break;
				}
				$titles = (array) get_user_meta( $user->ID, self::META_TITLES, true );
				$titles[ 'athena_' . current_time( 'Ymd' ) ] = current_time( 'Y-m-d' );
				update_user_meta( $user->ID, self::META_TITLES, $titles );
				self::grant( $user->ID, 'rare', absint( $_POST['reward'] ?? 5000 ), false );
				SNSCS_Inbox::add( $user->ID, 'game_rare', 3 );
				self::news( '新たな「アテナの戦士」が選ばれた…', 3 );
				break;
			case 'settings':
				$s = self::settings();
				foreach ( array( 'w_ref', 'w_bio', 'w_view', 'w_dwell', 'w_fav', 'w_read', 'w_aview', 'w_skip', 'w_own', 'w_react', 'target' ) as $key ) {
					$s[ $key ] = max( 0, (float) ( $_POST[ $key ] ?? $s[ $key ] ) );
				}
				foreach ( array( 'secret1', 'secret2' ) as $key ) {
					$name = sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) );
					if ( '' !== $name ) {
						$s[ $key ] = $name;
					}
				}
				update_option( self::OPTION, $s, false );
				break;
			case 'run':
				$state['done'] = '';
				self::save_state( $state );
				self::cron( true );
				break;
		}
		wp_safe_redirect( add_query_arg( array( 'post_type' => SNSCS_Post_Types::POST_TYPE, 'page' => self::ADMIN_PAGE, 'msg' => $msg ), admin_url( 'edit.php' ) ) );
		exit;
	}

	private static function form( $do, $inner, $button, $class = 'button' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="snscs-game-form"><input type="hidden" name="action" value="snscs_game"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">'
			. wp_nonce_field( 'snscs_game', '_wpnonce', true, false ) . $inner . ' <button class="' . esc_attr( $class ) . '">' . esc_html( $button ) . '</button></form>';
	}

	public static function render_admin() {
		$state = self::state();
		$s     = self::settings();
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$week = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(amount) FROM ' . self::table() . ' WHERE day >= %s', gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -7 days' ) ) ) );
		echo '<div class="wrap"><h1>ゲーム（経験値・カードランク・イベント）</h1>';
		if ( isset( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-' . ( 'nouser' === $_GET['msg'] ? 'error' : 'success' ) . '"><p>' . ( 'nouser' === $_GET['msg'] ? 'そのメールアドレスのメンバーが見つかりません。' : '保存しました。' ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		echo '<p>経験値は金銭や商品と交換できない、本サービスの中のゲームの記録です（利用規約 第7条の8）。直近7日の経験値の合計: <strong>' . esc_html( number_format_i18n( $week ) ) . '</strong>　貢献度の換算倍率（自動）: <strong>' . esc_html( round( (float) $state['k'], 3 ) ) . '</strong>　明かしたランク: <strong>' . esc_html( self::rank_name( (int) $state['reveal'] ) ) . 'まで</strong></p>';
		echo self::form( 'run', '', 'いま毎日の計算をする（貢献度・ランク）' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<h2>ボーナスイベント（不定期）</h2>';
		if ( self::bonus_multiplier() > 1 ) {
			echo '<p>開催中: ×' . esc_html( $state['bonus']['mult'] ) . '（' . esc_html( wp_date( 'n/j G:i', (int) $state['bonus']['until'] ) ) . 'まで）</p>' . self::form( 'bonus_stop', '', '終わらせる' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo self::form( 'bonus', '名前 <input name="title" placeholder="週末ボーナス"> 倍率 <input name="mult" type="number" step="0.1" min="1.1" max="10" value="2" class="small-text"> 時間 <input name="hours" type="number" min="1" max="720" value="48" class="small-text">', 'いますぐ始める', 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<h2>みんなで戦うボス戦</h2>';
		$boss = self::boss();
		if ( $boss ) {
			echo '<p>「' . esc_html( $boss['title'] ) . '」 ' . esc_html( number_format_i18n( $boss['now'] ) . ' / ' . number_format_i18n( $boss['target'] ) ) . '人・経験値の山 ' . esc_html( number_format_i18n( $boss['pool'] ) ) . '・' . esc_html( wp_date( 'n/j G:i', $boss['until'] ) ) . 'まで</p>' . self::form( 'boss_settle', '', 'いま締める（結果を出す）' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<p>キャリーオーバー: ' . esc_html( number_format_i18n( (int) $state['carry'] ) ) . ' 経験値（次のボスの山に加わります）</p>';
			echo self::form( 'boss', 'ボスの名前 <input name="title" value="暗黒の巨人"> 目標 <select name="metric"><option value="members">新しく登録したメンバーの人数</option><option value="creators">新しく本登録されたメンバーの人数</option></select> <input name="target" type="number" min="1" value="1000" class="small-text">人 期間 <input name="days" type="number" min="1" max="365" value="30" class="small-text">日 経験値の山 <input name="pool" type="number" min="1" value="1000000">', '出現させる', 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<h2>レアイベント（条件はメンバーには見せません）</h2>';
		foreach ( array( 'sanctuary' => 'サンクチュアリ（期間中に、この経験値を稼いだ人に称号と経験値）', 'skip' => '飛び級（期間中に、この経験値を稼いだ人を1年間ゴールド以上に）' ) as $key => $label ) {
			echo '<h3>' . esc_html( $label ) . '</h3>';
			if ( $state[ $key ] && (int) $state[ $key ]['until'] > time() ) {
				echo '<p>開催中: 条件 ' . esc_html( number_format_i18n( (int) $state[ $key ]['need'] ) ) . ' 経験値・' . esc_html( wp_date( 'n/j G:i', (int) $state[ $key ]['until'] ) ) . 'まで</p>' . self::form( 'close', '<input type="hidden" name="key" value="' . esc_attr( $key ) . '">', '閉じる' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo self::form( $key, '条件（期間中の経験値） <input name="need" type="number" min="1" value="' . ( 'skip' === $key ? 5000 : 1500 ) . '"> 期間 <input name="days" type="number" min="1" max="365" value="7" class="small-text">日' . ( 'sanctuary' === $key ? ' ごほうび <input name="reward" type="number" min="0" value="5000">経験値' : '' ), '開く' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
		echo '<h3>アテナの戦士（運営者が選ぶ）</h3>' . self::form( 'athena', 'メールアドレス <input name="email" type="email" required> ごほうび <input name="reward" type="number" min="0" value="5000">経験値', '称号を授ける' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<h2>アルゴリズムの設定（運営者だけが見られます）</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="snscs_game"><input type="hidden" name="do" value="settings">';
		wp_nonce_field( 'snscs_game' );
		$labels = array( 'w_ref' => '紹介リンクからの流入（1人）', 'w_bio' => 'FolloLinkが開かれた（1回）', 'w_view' => '発信者ページの閲覧（1人）', 'w_dwell' => '発信者ページの滞在（1分）', 'w_fav' => 'お気に入り登録', 'w_read' => '記事の読了', 'w_aview' => '記事の閲覧', 'w_skip' => '読み飛ばしの減点', 'w_own' => '本人の利用時間（1分）', 'w_react' => '記事への反応', 'target' => '貢献のあった人1人あたり・1日の貢献度の経験値の目安' );
		echo '<table class="form-table">';
		foreach ( $labels as $key => $label ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td><input name="' . esc_attr( $key ) . '" type="number" step="0.01" min="0" value="' . esc_attr( $s[ $key ] ) . '" class="small-text"></td></tr>';
		}
		echo '<tr><th>謎のランク（8番目・9番目）の名前</th><td><input name="secret1" value="' . esc_attr( $s['secret1'] ) . '"> <input name="secret2" value="' . esc_attr( $s['secret2'] ) . '"><p class="description">明かされるまで、メンバーには「？？？」と見えます。</p></td></tr>';
		echo '</table><p><button class="button button-primary">保存</button></p></form>';
		echo '<h2>カードランクの条件</h2><table class="widefat striped" style="max-width:640px"><thead><tr><th>ランク</th><th>直近365日の経験値</th><th>上位</th><th>公開</th></tr></thead><tbody>';
		foreach ( self::rank_keys() as $i => $key ) {
			echo '<tr><td>' . esc_html( self::rank_name( $i ) ) . '</td><td>' . esc_html( number_format_i18n( $s['ranks'][ $key ][0] ) ) . '</td><td>' . esc_html( $s['ranks'][ $key ][1] ? $s['ranks'][ $key ][1] . '%以内' : '—' ) . '</td><td>' . ( $i <= (int) $state['reveal'] ? '明かした' : '？？？' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
