<?php
/**
 * 表示順位（おすすめ順）のための点数計算。
 *
 * 15の「信号」を、それぞれ全体の中での順位から 0〜100点にし、重みを付けて合わせる。
 *
 *   紹介リンク     : 紹介リンクから来た人数（最近の日ほど重く数える）
 *   フォロワー     : 全SNSの合計フォロワー数
 *   ページ閲覧     : 発信者ページと記事を見た人数（最近の日ほど重く数える）
 *   滞在時間       : ページを実際に見ていた時間の平均
 *   リンクのクリック率 : ページを見た人のうち、SNSや外部リンクを押した人の割合
 *   記事の活動     : 最近公開した記事の数（新しい記事ほど重く数える）
 *   サイトの利用日数 : 発信者がログインしてこのサイトを使った日数（最近の日ほど重く数える）
 *   サイトの利用時間 : 発信者がログイン中にこのサイトを使っていた時間（1日30分まで、最近の日ほど重く数える）
 *   紹介ネットワーク : 招待して掲載された発信者（初期3段目まで）の人数と紹介リンク流入（SNSCS_Invites）
 *   お気に入り     : ページを見てお気に入りに登録した人数（最近の日ほど重く数える）
 *   検索でのクリック率 : 検索結果で、表示された位置のわりによく押されるか（SNSCS_Feedback。位置の補正つき）
 *   満足度         : 検索結果から押した人が、じっくり見たかすぐ戻ったか（SNSCS_Feedback）
 *   つながりの信頼度 : 招待・一緒に見られている関係を流れる信頼の量（SNSCS_Graph。PageRank/TrustRank）
 *   プロフィールの充実度 : 写真・紹介文・複数のSNS・リンク・記事・地域がそろっているか
 *   更新の新しさ   : 掲載内容や記事を最近更新したか（60日で半分）
 *   重みは、学習した重み（SNSCS_Learner）が良ければそれと混ぜて使う。
 *
 *   総合点 = Σ(信号の点 × 重み) ÷ Σ(重み)
 *   ※初期の重みは 25:25:15:15:10:10:5:5:10:5（紹介:フォロワー = 1:1 は変えない）
 *
 * 順位の換算: 1位 = 100点、最下位 = 0点、同じ値の人は同じ点。
 *   点 = 自分より値が小さい人の人数 ÷ (全体の人数 − 1) × 100
 *
 * 平均値（滞在時間・クリック率）は、見た人が少ないと偶然に左右されるため、
 * 全体の平均に寄せて計算する（ベイズ平均）。まだ誰にも見られていない人は、この2つは最下位扱い。
 *
 * 不正の疑い（点数を下げ、管理画面に「要確認」と出す）:
 *   - 紹介リンクのクリックは多いのに、ページがほとんど表示されていない（機械的なクリックの疑い）
 *   - 閲覧は多いのに、滞在時間が極端に短い（機械的な閲覧の疑い）
 *
 * 計算は1時間ごと（WP-Cron）に全員分をまとめて行い、結果を専用テーブルに保存する。
 * 検索はこのテーブルの点数で並べるだけなので、人数が増えても速い。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Scores {

	const CRON_HOOK = 'snscs_recalculate_scores';

	/** 平均を全体に寄せるときの「仮の人数」。 */
	const PRIOR_DWELL  = 10;
	const PRIOR_CLICKS = 20;

	/** 不正の疑いを判断する目安。 */
	const SUSPECT_MIN_REFERRALS = 20;
	const SUSPECT_VIEW_RATIO    = 0.2;
	const SUSPECT_MIN_VIEWS     = 30;
	const SUSPECT_MIN_DWELL     = 5;
	const SUSPECT_PENALTY       = 0.5;

	/** 急増の検知: 直近の1日がこの人数以上で、ふだん（中央値）から離れた度合いがこの値以上なら「要確認」。 */
	const SPIKE_MIN   = 30;
	const SPIKE_SCORE = 8;

	/** 記事の活動を数える期間（日）。 */
	const ARTICLE_DAYS = 90;

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
  views int(10) unsigned NOT NULL DEFAULT 0,
  dwell_avg double NOT NULL DEFAULT 0,
  click_rate double NOT NULL DEFAULT 0,
  articles double NOT NULL DEFAULT 0,
  usage_days int(10) unsigned NOT NULL DEFAULT 0,
  usage_time int(10) unsigned NOT NULL DEFAULT 0,
  network double NOT NULL DEFAULT 0,
  invites int(10) unsigned NOT NULL DEFAULT 0,
  favs int(10) unsigned NOT NULL DEFAULT 0,
  growth double NOT NULL DEFAULT 0,
  score_b double DEFAULT NULL,
  tiers varchar(64) NOT NULL DEFAULT '',
  inflow_points double NOT NULL DEFAULT 0,
  follower_points double NOT NULL DEFAULT 0,
  points text,
  flags varchar(100) NOT NULL DEFAULT '',
  score double NOT NULL DEFAULT 0,
  scored_at datetime DEFAULT NULL,
  PRIMARY KEY  (creator_id),
  UNIQUE KEY ref_code (ref_code),
  KEY score (score)
) {$charset};";

		dbDelta( $sql );
	}

	/* --------------------------------------------------------------------
	 * 信号と設定
	 * ------------------------------------------------------------------ */

	/**
	 * 信号の一覧（並び順のまま画面に出る）。
	 *
	 * @return array<string,array{label:string,desc:string,tip:string,default:int}>
	 */
	public static function signals() {
		return array(
			'referral'  => array(
				'label'   => '紹介リンク',
				'desc'    => '紹介リンクから来た人数',
				'tip'     => '紹介リンクを、SNSのプロフィール欄や投稿に貼りましょう。',
				'default' => 25,
			),
			'followers' => array(
				'label'   => 'フォロワー',
				'desc'    => '全SNSの合計フォロワー数',
				'tip'     => 'フォロワー数が増えたら、変更を申請して最新の数にしましょう。',
				'default' => 25,
			),
			'growth'    => array(
				'label'   => '伸び率',
				'desc'    => 'ここ1か月ほどの、フォロワーの増え方（増えた割合。少ない人の小さな増減は控えめに数える）',
				'tip'     => '投稿を続けてフォロワーが増えたら、変更を申請して最新の数にしましょう（SNSを連携すると毎日自動で更新されます）。',
				'default' => 5,
			),
			'views'     => array(
				'label'   => 'ページ閲覧',
				'desc'    => '発信者ページと記事を見た人数',
				'tip'     => '記事を書いてSNSで紹介すると、ページを見る人が増えます。',
				'default' => 15,
			),
			'dwell'     => array(
				'label'   => '滞在時間',
				'desc'    => 'ページを実際に見ていた時間の平均',
				'tip'     => '紹介文を充実させ、読み応えのある記事を書くと、じっくり見てもらえます。',
				'default' => 15,
			),
			'clicks'    => array(
				'label'   => 'リンクのクリック率',
				'desc'    => 'ページを見た人がSNSや外部リンクを押した割合',
				'tip'     => 'ブログやほかのSNSなど「その他のリンク」を登録すると、押してもらえる機会が増えます。',
				'default' => 10,
			),
			'articles'  => array(
				'label'   => '記事の活動',
				'desc'    => '最近公開した記事の数（新しいほど高く数える）',
				'tip'     => '定期的に記事を公開しましょう。最近の記事ほど高く数えます。',
				'default' => 10,
			),
			'site_days' => array(
				'label'   => 'サイトの利用日数',
				'desc'    => '発信者がログインしてこのサイトを使った日数（最近ほど高く数える）',
				'tip'     => 'こまめにログインして、ほかの発信者やコラムを見てみましょう。毎日少しずつ使うと点数が上がります。',
				'default' => 5,
			),
			'site_time' => array(
				'label'   => 'サイトの利用時間',
				'desc'    => '発信者がログイン中にこのサイトを使っていた時間（1日30分まで）',
				'tip'     => 'コラムを読んだり、記事を書いたりして、このサイトを活用しましょう（1日30分まで数えます）。',
				'default' => 5,
			),
			'network'   => array(
				'label'   => '紹介ネットワーク',
				'desc'    => '招待して掲載された発信者（設定した段数まで）の人数と、その人たちの紹介リンク流入',
				'tip'     => '「発信者仲間を招待する」のリンクを、発信している友人に送ってみましょう。',
				'default' => 10,
			),
			'favorites' => array(
				'label'   => 'お気に入り',
				'desc'    => 'ページを見た人がお気に入りに登録した人数（最近ほど高く数える）',
				'tip'     => 'SNSの投稿で「プロフィールを☆お気に入りしておいてね」と呼びかけてみましょう。',
				'default' => 5,
			),
			'social' => array(
				'label'   => 'このサイトでの反応',
				'desc'    => 'このサイトのメンバーからのフォローと、記事へのいいね・リポスト・ブックマーク',
				'tip'     => 'SNSで「このサイトでフォローしてね」と呼びかけ、読者の役に立つ記事を書きましょう。',
				'default' => 10,
			),
			'search_ctr' => array(
				'label'   => '検索でのクリック率',
				'desc'    => '検索結果で、表示された位置のわりによく押されるか',
				'tip'     => '写真と、紹介文の最初の一文で「何を発信している人か」が伝わるようにしましょう（検索結果にはそこが表示されます）。',
				'default' => 10,
			),
			'satisfaction' => array(
				'label'   => '満足度',
				'desc'    => '検索結果から来た人が、じっくり見たか（すぐ戻らなかったか）',
				'tip'     => 'ページを開いた人が知りたいこと（発信のテーマ、どのSNSで何を出しているか）を、紹介文と記事で分かりやすく伝えましょう。',
				'default' => 10,
			),
			'authority' => array(
				'label'   => 'つながりの信頼度',
				'desc'    => '招待や「一緒に見られている」つながりの中で、信頼できる発信者に近いか',
				'tip'     => '同じジャンルの発信者と交流し、仲間を招待すると、つながりの中での信頼が高まります。',
				'default' => 5,
			),
			'quality' => array(
				'label'   => 'プロフィールの充実度',
				'desc'    => '写真・紹介文・複数のSNS・リンク・記事・地域がそろっているか',
				'tip'     => '写真と80字以上の紹介文、2つ以上のSNS、ブログなどのリンク、地域を登録し、記事を1本書いてみましょう。',
				'default' => 5,
			),
			'freshness' => array(
				'label'   => '更新の新しさ',
				'desc'    => '掲載内容や記事を最近更新したか',
				'tip'     => 'フォロワー数の更新や記事の公開など、2か月に1回は何かを更新しましょう。',
				'default' => 5,
			),
		);
	}

	/**
	 * @return array{weights:array<string,int>,days:int,half_life:int}
	 */
	public static function settings() {
		$saved    = get_option( 'snscs_score_settings', array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$defaults = array(
			'weights'   => wp_list_pluck( self::signals(), 'default' ),
			'days'      => (int) get_option( 'snscs_inflow_days', 30 ),
			'half_life' => 14,
			// 紹介ネットワーク: 何段目まで数えるか、1段深くなるごとの割合（%）、招待1人あたりのボーナス。
			'tiers'      => 3,
			'tier_decay' => 50,
			'join_bonus' => 10,
		);

		// 以前の「紹介:フォロワー」の重み設定を引き継ぐ（1:1 以外に変えていた場合）。
		$old_wi = get_option( 'snscs_weight_inflow' );
		$old_wf = get_option( 'snscs_weight_followers' );
		if ( ! isset( $saved['weights'] ) && false !== $old_wi && false !== $old_wf && (int) $old_wi !== (int) $old_wf ) {
			$defaults['weights']['referral']  = min( 100, 25 * (int) $old_wi );
			$defaults['weights']['followers'] = min( 100, 25 * (int) $old_wf );
		}

		$settings            = array_merge( $defaults, $saved );
		$settings['weights'] = array_merge( $defaults['weights'], isset( $saved['weights'] ) ? (array) $saved['weights'] : array() );
		return $settings;
	}

	/**
	 * 紹介リンクの流入を数える期間（日）。規約ページなどの文面にも使う。
	 */
	public static function inflow_days() {
		return max( 1, (int) self::settings()['days'] );
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
			$code  = '';
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
	 * 新しく掲載された人・記事にもすぐ点数が付くよう、1分後に計算し直す。
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
		// 発信者が多いと配列が大きくなるため、WordPress の「重い処理」用のメモリ上限まで使えるようにする。
		wp_raise_memory_limit( 'snscs_scores' );

		$settings  = self::settings();
		$days      = max( 1, (int) $settings['days'] );
		$half_life = max( 1, (int) $settings['half_life'] );

		$creators = array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", SNSCS_Post_Types::POST_TYPE ) )
		);

		// フォロワー（対応SNSの合計）。
		$accounts     = SNSCS_Accounts::table();
		$platforms    = array_keys( snscs_platforms() );
		$placeholders = implode( ',', array_fill( 0, count( $platforms ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows          = $wpdb->get_results( $wpdb->prepare( "SELECT creator_id, SUM(followers) AS total FROM {$accounts} WHERE platform IN ({$placeholders}) GROUP BY creator_id", $platforms ), ARRAY_A );
		$all_followers = wp_list_pluck( $rows, 'total', 'creator_id' );

		$referral_raw = SNSCS_Referrals::counts( $days );
		// 「要確認」の判定では、ページの計測を始める前の紹介リンクは比べない（正直な人が疑われないように）。
		$started        = (int) get_option( 'snscs_tracking_started', time() );
		$check_days     = min( $days, max( 1, (int) floor( ( time() - $started ) / DAY_IN_SECONDS ) + 1 ) );
		$referral_check = $check_days < $days ? SNSCS_Referrals::counts( $check_days ) : $referral_raw;
		$referral_w   = SNSCS_Referrals::weighted_counts( $days, $half_life );
		$traffic      = SNSCS_Tracking::totals( $days, $half_life );
		$articles     = SNSCS_Articles::activity( self::ARTICLE_DAYS, $half_life * 2 );
		$usage        = SNSCS_Usage::totals( $days, $half_life );
		$feedback     = SNSCS_Feedback::for_creators( $creators );
		$social       = SNSCS_Social::creator_values();
		$avg_sat      = (float) get_option( 'snscs_avg_sat', 0.5 );
		list( $quality, $freshness ) = self::profile_values( $creators );
		$spikes       = SNSCS_Learner::setting( 'spike' ) ? self::spikes() : array();
		$penalties    = SNSCS_Integrity::detect( $creators );
		$growth       = self::growth_values();

		// 全体の平均（少ない人数の平均を寄せる先）。
		$sum_dwell  = 0;
		$sum_dwells = 0;
		$sum_out    = 0;
		$sum_views  = 0;
		foreach ( $creators as $id ) {
			if ( isset( $traffic[ $id ] ) ) {
				$sum_dwell  += $traffic[ $id ]['dwell_sum'];
				$sum_dwells += $traffic[ $id ]['dwell_count'];
				$sum_out    += $traffic[ $id ]['out_clicks'];
				$sum_views  += $traffic[ $id ]['views'];
			}
		}
		$avg_dwell = $sum_dwells ? $sum_dwell / $sum_dwells : 30.0;
		$avg_click = $sum_views ? $sum_out / $sum_views : 0.05;

		$values = array_fill_keys( array_keys( self::signals() ), array() );
		$raw    = array();
		foreach ( $creators as $id ) {
			$t = isset( $traffic[ $id ] ) ? $traffic[ $id ] : array( 'views' => 0, 'weighted_views' => 0, 'dwell_sum' => 0, 'dwell_count' => 0, 'out_clicks' => 0, 'favs' => 0, 'weighted_favs' => 0 );

			$values['referral'][ $id ]  = isset( $referral_w[ $id ] ) ? $referral_w[ $id ] : 0.0;
			$values['followers'][ $id ] = isset( $all_followers[ $id ] ) ? (int) $all_followers[ $id ] : 0;
			$values['growth'][ $id ]    = isset( $growth[ $id ] ) ? $growth[ $id ] : 0.0;
			$values['views'][ $id ]     = $t['weighted_views'];
			// まだ誰にも見られていない人は、平均値の信号では最下位扱い（-1）。
			$values['dwell'][ $id ]    = $t['dwell_count'] ? ( $t['dwell_sum'] + self::PRIOR_DWELL * $avg_dwell ) / ( $t['dwell_count'] + self::PRIOR_DWELL ) : -1;
			$values['clicks'][ $id ]   = $t['views'] ? ( $t['out_clicks'] + self::PRIOR_CLICKS * $avg_click ) / ( $t['views'] + self::PRIOR_CLICKS ) : -1;
			$values['articles'][ $id ] = isset( $articles[ $id ] ) ? $articles[ $id ] : 0.0;
			$u                          = isset( $usage[ $id ] ) ? $usage[ $id ] : array( 'days' => 0, 'seconds' => 0, 'weighted_days' => 0.0, 'weighted_seconds' => 0.0 );
			$values['site_days'][ $id ] = $u['weighted_days'];
			$values['site_time'][ $id ] = $u['weighted_seconds'];
			$values['favorites'][ $id ] = $t['weighted_favs'];
			$f                           = isset( $feedback[ $id ] ) ? $feedback[ $id ] : null;
			$values['social'][ $id ]       = isset( $social[ $id ] ) ? $social[ $id ] : 0.0;
			$values['search_ctr'][ $id ]   = $f ? $f['coec'] : 1.0;
			$values['satisfaction'][ $id ] = ( $f && $f['clicks'] > 0 ) ? $f['sat'] : $avg_sat;
			$values['authority'][ $id ]    = $f ? $f['authority'] : 1.0;
			$values['quality'][ $id ]      = isset( $quality[ $id ] ) ? $quality[ $id ] : 0;
			$values['freshness'][ $id ]    = isset( $freshness[ $id ] ) ? $freshness[ $id ] : 0;

			$raw[ $id ] = array(
				'inflow'     => isset( $referral_raw[ $id ] ) ? $referral_raw[ $id ] : 0,
				'inflow_chk' => isset( $referral_check[ $id ] ) ? $referral_check[ $id ] : 0,
				'views'      => $t['views'],
				'dwell_avg'  => $t['dwell_count'] ? $t['dwell_sum'] / $t['dwell_count'] : 0,
				'click_rate' => $t['views'] ? $t['out_clicks'] / $t['views'] : 0,
				'usage_days' => $u['days'],
				'usage_time' => $u['seconds'],
				'favs'       => $t['favs'],
			);
		}

		// ここから先で使わないものは、早めにメモリから外す。
		unset( $referral_raw, $referral_check, $referral_w, $traffic, $articles, $usage, $feedback, $quality, $freshness, $all_followers, $rows );

		// 不正の疑いを先に判定する（紹介ネットワークでも使うため）。
		$flags = array();
		foreach ( $creators as $id ) {
			$flags[ $id ] = array();
			// 紹介リンクのクリックは多いのに、ページがほとんど表示されていない。
			if ( $raw[ $id ]['inflow_chk'] >= self::SUSPECT_MIN_REFERRALS && $raw[ $id ]['views'] < $raw[ $id ]['inflow_chk'] * self::SUSPECT_VIEW_RATIO ) {
				$flags[ $id ][] = 'referral_without_views';
			}
			// 閲覧は多いのに、滞在時間が極端に短い。
			if ( $raw[ $id ]['views'] >= self::SUSPECT_MIN_VIEWS && $raw[ $id ]['dwell_avg'] < self::SUSPECT_MIN_DWELL ) {
				$flags[ $id ][] = 'short_dwell';
			}
			// ふだんより極端な急増（紹介リンク・閲覧）。
			if ( isset( $spikes[ $id ] ) ) {
				$flags[ $id ][] = 'spike';
			}
			// 不正対策で順位を下げている（倍率は最後に総合点へ掛ける）。
			if ( isset( $penalties[ $id ] ) ) {
				$flags[ $id ][] = 'penalized';
			}
		}

		// 紹介ネットワーク: 招待して掲載された人を、設定した段数までさかのぼって足す。
		list( $network, $tier_counts ) = SNSCS_Invites::network_values(
			SNSCS_Invites::parents(),
			$values['referral'],
			array_map( 'boolval', $flags ),
			(int) $settings['tiers'],
			min( 100, max( 0, (int) $settings['tier_decay'] ) ) / 100,
			max( 0, (float) $settings['join_bonus'] )
		);
		foreach ( $creators as $id ) {
			$values['network'][ $id ] = isset( $network[ $id ] ) ? $network[ $id ] : 0.0;
		}

		$points = array();
		foreach ( $values as $signal => $by_creator ) {
			$points[ $signal ] = self::rank_points( $by_creator );
			// 保存に使う値（フォロワー・記事・紹介ネットワーク）だけ残す。
			if ( ! in_array( $signal, array( 'followers', 'articles', 'network', 'growth' ), true ) ) {
				unset( $values[ $signal ] );
			}
		}

		// 学習した重みが良ければ混ぜて使う。A/Bテスト中は B組用の点数も計算する。
		$weights   = SNSCS_Learner::active_weights( $settings['weights'] );
		$weights_b = SNSCS_Learner::candidate_weights( $settings['weights'] );
		$now     = current_time( 'mysql', true );
		$table   = self::table();

		foreach ( array_chunk( $creators, 500 ) as $chunk ) {
			$rows = array();
			foreach ( $chunk as $id ) {
				$p = array();
				foreach ( array_keys( self::signals() ) as $signal ) {
					$p[ $signal ] = $points[ $signal ][ $id ];
				}
				if ( in_array( 'referral_without_views', $flags[ $id ], true ) ) {
					$p['referral'] = $p['referral'] * self::SUSPECT_PENALTY;
				}
				if ( in_array( 'short_dwell', $flags[ $id ], true ) ) {
					$p['views']    = $p['views'] * self::SUSPECT_PENALTY;
					$p['referral'] = $p['referral'] * self::SUSPECT_PENALTY;
				}
				if ( in_array( 'spike', $flags[ $id ], true ) ) {
					$p['views']      = $p['views'] * self::SUSPECT_PENALTY;
					$p['referral']   = $p['referral'] * self::SUSPECT_PENALTY;
					$p['search_ctr'] = $p['search_ctr'] * self::SUSPECT_PENALTY;
				}
				$factor = isset( $penalties[ $id ] ) ? $penalties[ $id ] : 1.0;
				$tiers  = isset( $tier_counts[ $id ] ) ? $tier_counts[ $id ] : array_fill( 0, min( SNSCS_Invites::MAX_TIERS, max( 1, (int) $settings['tiers'] ) ), 0 );

				$rows[] = $wpdb->prepare(
					'(%d, %d, %d, %d, %f, %f, %f, %d, %d, %f, %d, %d, %s, %f, %f, %s, %s, %f, %s, %f, %f)',
					$id,
					$raw[ $id ]['inflow'],
					$values['followers'][ $id ],
					$raw[ $id ]['views'],
					$raw[ $id ]['dwell_avg'],
					$raw[ $id ]['click_rate'],
					$values['articles'][ $id ],
					$raw[ $id ]['usage_days'],
					$raw[ $id ]['usage_time'],
					$values['network'][ $id ],
					$tiers[0],
					$raw[ $id ]['favs'],
					wp_json_encode( $tiers ),
					$p['referral'],
					$p['followers'],
					wp_json_encode( array_map( 'floatval', $p ) ),
					implode( ',', $flags[ $id ] ),
					self::combine( $p, $weights ) * $factor,
					$now,
					self::combine( $p, null === $weights_b ? $weights : $weights_b ) * $factor,
					$values['growth'][ $id ]
				);
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "INSERT INTO {$table} (creator_id, inflow, followers, views, dwell_avg, click_rate, articles, usage_days, usage_time, network, invites, favs, tiers, inflow_points, follower_points, points, flags, score, scored_at, score_b, growth) VALUES " . implode( ',', $rows ) . ' ON DUPLICATE KEY UPDATE score_b = VALUES(score_b), growth = VALUES(growth), inflow = VALUES(inflow), followers = VALUES(followers), views = VALUES(views), dwell_avg = VALUES(dwell_avg), click_rate = VALUES(click_rate), articles = VALUES(articles), usage_days = VALUES(usage_days), usage_time = VALUES(usage_time), network = VALUES(network), invites = VALUES(invites), favs = VALUES(favs), tiers = VALUES(tiers), inflow_points = VALUES(inflow_points), follower_points = VALUES(follower_points), points = VALUES(points), flags = VALUES(flags), score = VALUES(score), scored_at = VALUES(scored_at)' );
		}

		SNSCS_Referrals::purge_old();
		SNSCS_Tracking::purge_old();
		SNSCS_Usage::purge_old( $days );
		update_option( 'snscs_scores_updated', time() );

		return count( $creators );
	}

	/**
	 * フォロワーの伸び率（1.21）: いまのフォロワー数を、7〜60日前のいちばん新しい記録と比べ、30日あたりの増えた割合にする。
	 * 比べるのは、その記録にあったSNSだけ（あとから足したSNSで伸びたように見えないように）。
	 * 少ない人の小さな増減で大きく動かないよう、割る数は 300人 以上にする。
	 * 自動更新していないSNSの記録も残るよう、週に1回、全員のいまの数を記録する（SNSCS_Report::snapshot_followers）。
	 *
	 * @return array<int,float> 発信者ID => 30日あたりの伸び率（0.1 = 10%）。
	 */
	public static function growth_values() {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		if ( (string) get_option( 'snscs_growth_snap' ) < gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) ) ) {
			SNSCS_Report::snapshot_followers();
			update_option( 'snscs_growth_snap', $today, false );
		}
		$history  = SNSCS_Sync::history_table();
		$accounts = SNSCS_Accounts::table();
		$from     = gmdate( 'Y-m-d', strtotime( $today . ' -60 days' ) );
		$to       = gmdate( 'Y-m-d', strtotime( $today . ' -7 days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT h.creator_id, m.d AS day, SUM(h.followers) AS base, SUM(COALESCE(a.followers, 0)) AS now FROM {$history} h INNER JOIN (SELECT creator_id, MAX(day) AS d FROM {$history} WHERE day BETWEEN %s AND %s GROUP BY creator_id) m ON m.creator_id = h.creator_id AND m.d = h.day LEFT JOIN {$accounts} a ON a.creator_id = h.creator_id AND a.platform = h.platform GROUP BY h.creator_id, m.d", $from, $to ), ARRAY_A );
		$out = array();
		foreach ( $rows as $r ) {
			$days = max( 7, ( strtotime( $today ) - strtotime( $r['day'] ) ) / DAY_IN_SECONDS );
			$rate = ( (float) $r['now'] - (float) $r['base'] ) / max( 300.0, (float) $r['base'] ) * 30 / $days;
			$out[ (int) $r['creator_id'] ] = round( max( -1.0, min( 5.0, $rate ) ), 5 );
		}
		return $out;
	}

	/**
	 * プロフィールの充実度（0〜6）と更新の新しさ（0〜1。60日で半分）。
	 *
	 * @param int[] $creators 発信者。
	 * @return array{0:array<int,float>,1:array<int,float>}
	 */
	public static function profile_values( array $creators ) {
		global $wpdb;
		$quality   = array_fill_keys( $creators, 0.0 );
		$freshness = array_fill_keys( $creators, 0.0 );
		if ( ! $creators ) {
			return array( $quality, $freshness );
		}
		$type     = SNSCS_Post_Types::POST_TYPE;
		$accounts = SNSCS_Accounts::table();
		$now      = strtotime( current_time( 'mysql' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, CHAR_LENGTH(post_content) AS bio, post_modified FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", $type ), ARRAY_A );
		foreach ( $posts as $row ) {
			$id = (int) $row['ID'];
			if ( isset( $quality[ $id ] ) ) {
				$quality[ $id ]  += (int) $row['bio'] >= 80 ? 1 : ( (int) $row['bio'] >= 30 ? 0.5 : 0 );
				$freshness[ $id ] = strtotime( $row['post_modified'] );
			}
		}
		$photo = $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value > 0", $type ) );
		$links = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s WHERE pm.meta_key IN ('_snscs_links', '_snscs_stores') AND pm.meta_value NOT IN ('', 'a:0:{}')", $type ) );
		$multi = $wpdb->get_col( "SELECT creator_id FROM {$accounts} GROUP BY creator_id HAVING COUNT(DISTINCT platform) >= 2" );
		$region = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s", SNSCS_Post_Types::TAX_REGION ) );
		$latest = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_value AS creator_id, MAX(p.post_date) AS last FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s AND p.post_status = 'publish' WHERE pm.meta_key = '_snscs_creator_id' GROUP BY pm.meta_value", SNSCS_Articles::POST_TYPE ), ARRAY_A );
		// phpcs:enable
		foreach ( array( $photo, $links, $multi, $region ) as $list ) {
			foreach ( $list as $id ) {
				if ( isset( $quality[ (int) $id ] ) ) {
					$quality[ (int) $id ] += 1;
				}
			}
		}
		foreach ( $latest as $row ) {
			$id = (int) $row['creator_id'];
			if ( isset( $quality[ $id ] ) ) {
				$quality[ $id ]  += 1;
				$freshness[ $id ] = max( $freshness[ $id ], strtotime( $row['last'] ) );
			}
		}
		foreach ( $freshness as $id => $time ) {
			$days             = $time ? max( 0, ( $now - $time ) / DAY_IN_SECONDS ) : 365;
			$freshness[ $id ] = pow( 0.5, $days / 60 );
		}
		return array( $quality, $freshness );
	}

	/**
	 * 急増の検知: 直近の1日の紹介リンク流入・閲覧が、ふだん（前の27日の中央値）から極端に離れた発信者。
	 * 離れ具合 = (直近 − 中央値) ÷ (1.4826 × 中央値からのずれの中央値 + 1)
	 *
	 * @return array<int,bool>
	 */
	public static function spikes() {
		global $wpdb;
		$today  = current_time( 'Y-m-d' );
		$since  = gmdate( 'Y-m-d', strtotime( $today . ' -27 days' ) );
		$recent = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );
		$ref    = SNSCS_Referrals::table();
		$daily  = SNSCS_Tracking::daily_table();
		$series = array();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// 記録が14日分そろうまでは「ふだん」が分からないので調べない（公開直後に全員が急増あつかいになるのを防ぐ）。
		$first = $wpdb->get_var( "SELECT MIN(clicked_on) FROM {$ref}" );
		if ( ! $first || $first > gmdate( 'Y-m-d', strtotime( $today . ' -14 days' ) ) ) {
			return array();
		}
		$hot = $wpdb->get_col( $wpdb->prepare( "SELECT creator_id FROM {$ref} WHERE clicked_on >= %s GROUP BY creator_id HAVING COUNT(*) >= %d UNION SELECT creator_id FROM {$daily} WHERE day >= %s GROUP BY creator_id HAVING MAX(views) >= %d", $recent, self::SPIKE_MIN, $recent, self::SPIKE_MIN ) );
		if ( ! $hot ) {
			return array();
		}
		$in   = implode( ',', array_map( 'intval', $hot ) );
		$rows = array_merge(
			$wpdb->get_results( $wpdb->prepare( "SELECT creator_id, clicked_on AS day, COUNT(*) AS n, 'r' AS k FROM {$ref} WHERE creator_id IN ({$in}) AND clicked_on >= %s GROUP BY creator_id, clicked_on", $since ), ARRAY_A ),
			$wpdb->get_results( $wpdb->prepare( "SELECT creator_id, day, views AS n, 'v' AS k FROM {$daily} WHERE creator_id IN ({$in}) AND day >= %s", $since ), ARRAY_A )
		);
		// phpcs:enable
		foreach ( $rows as $row ) {
			$series[ (int) $row['creator_id'] ][ $row['k'] ][ $row['day'] ] = (int) $row['n'];
		}
		$out    = array();
		$median = function ( array $values ) {
			sort( $values );
			$n = count( $values );
			return $n ? ( $n % 2 ? $values[ intdiv( $n, 2 ) ] : ( $values[ $n / 2 - 1 ] + $values[ $n / 2 ] ) / 2 ) : 0;
		};
		foreach ( $series as $id => $kinds ) {
			foreach ( $kinds as $days ) {
				$latest = max( isset( $days[ $today ] ) ? $days[ $today ] : 0, isset( $days[ $recent ] ) ? $days[ $recent ] : 0 );
				if ( $latest < self::SPIKE_MIN ) {
					continue;
				}
				$history = array();
				for ( $i = 2; $i <= 27; $i++ ) {
					$d         = gmdate( 'Y-m-d', strtotime( $today . ' -' . $i . ' days' ) );
					$history[] = isset( $days[ $d ] ) ? $days[ $d ] : 0;
				}
				$m   = $median( $history );
				$mad = $median(
					array_map(
						function ( $v ) use ( $m ) {
							return abs( $v - $m );
						},
						$history
					)
				);
				if ( ( $latest - $m ) / ( 1.4826 * $mad + 1 ) >= self::SPIKE_SCORE ) {
					$out[ $id ] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * 値の「順位」を 0〜100点に換算する。
	 * 点 = 自分より値が小さい人の人数 ÷ (全体の人数 − 1) × 100
	 *
	 * @param array<int,float|int> $values 発信者ID => 値。
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

		// 値ごとに「それより小さい人の人数」を求める（同じ値なら同じ点）。小数はキーにできないため文字列にする。
		$below = array();
		foreach ( $sorted as $index => $value ) {
			$key = (string) $value;
			if ( ! isset( $below[ $key ] ) ) {
				$below[ $key ] = $index;
			}
		}

		$points = array();
		foreach ( $values as $id => $value ) {
			$points[ $id ] = round( $below[ (string) $value ] / ( $n - 1 ) * 100, 4 );
		}
		return $points;
	}

	/**
	 * 信号の点を重み付きで合わせる。
	 *
	 * @param array<string,float> $points  信号 => 点。
	 * @param array<string,int>   $weights 信号 => 重み。
	 * @return float
	 */
	public static function combine( array $points, array $weights ) {
		$total  = 0.0;
		$weight = 0;
		foreach ( $points as $signal => $point ) {
			$w       = isset( $weights[ $signal ] ) ? max( 0, (int) $weights[ $signal ] ) : 0;
			$total  += $point * $w;
			$weight += $w;
		}
		return $weight ? round( $total / $weight, 4 ) : 0.0;
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
		$points = json_decode( (string) $row['points'], true );

		return array(
			'inflow'          => (int) $row['inflow'],
			'followers'       => (int) $row['followers'],
			'views'           => (int) $row['views'],
			'dwell_avg'       => (float) $row['dwell_avg'],
			'click_rate'      => (float) $row['click_rate'],
			'articles'        => (float) $row['articles'],
			'usage_days'      => (int) $row['usage_days'],
			'usage_time'      => (int) $row['usage_time'],
			'network'         => (float) $row['network'],
			'invites'         => (int) $row['invites'],
			'favs'            => (int) $row['favs'],
			'tiers'           => array_map( 'intval', (array) json_decode( (string) $row['tiers'], true ) ),
			'inflow_points'   => (float) $row['inflow_points'],
			'follower_points' => (float) $row['follower_points'],
			'points'          => is_array( $points ) ? $points : array(),
			'flags'           => array_filter( explode( ',', (string) $row['flags'] ) ),
			'score'           => (float) $row['score'],
			'rank'            => $rank( 'score', $row['score'] ),
			'inflow_rank'     => $rank( 'inflow', $row['inflow'] ),
			'followers_rank'  => $rank( 'followers', $row['followers'] ),
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			'total'           => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", SNSCS_Post_Types::POST_TYPE ) ),
		);
	}

	/**
	 * 総合点の上位（設定画面の確認用、おすすめ表示用）。
	 *
	 * @param int  $limit         人数。
	 * @param bool $only_flagged  「要確認」の人だけにするか。
	 * @return array
	 */
	public static function top( $limit = 20, $only_flagged = false ) {
		global $wpdb;
		$table = self::table();
		$where = $only_flagged ? " AND st.flags <> ''" : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT st.*, p.post_title FROM {$table} st INNER JOIN {$wpdb->posts} p ON p.ID = st.creator_id AND p.post_status = 'publish' AND p.post_type = %s WHERE st.scored_at IS NOT NULL{$where} ORDER BY st.score DESC, st.followers DESC, st.creator_id DESC LIMIT %d", SNSCS_Post_Types::POST_TYPE, $limit ), ARRAY_A );
	}

	/**
	 * 「要確認」の理由を人が読める形に。
	 */
	public static function flag_labels() {
		return array(
			'referral_without_views' => '紹介リンクのクリックに対して、ページの表示が少なすぎる',
			'short_dwell'            => '閲覧は多いのに、滞在時間が極端に短い',
			'spike'                  => '紹介リンクや閲覧が、ふだんより極端に急に増えた',
			'penalized'              => '不正対策で順位を下げている（発信者 → 不正対策）',
		);
	}
}
