<?php
/**
 * 表示順位（おすすめ順）のための点数計算。
 *
 * 9つの「信号」を、それぞれ全体の中での順位から 0〜100点にし、重みを付けて合わせる。
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
 *
 *   総合点 = Σ(信号の点 × 重み) ÷ Σ(重み)
 *   ※初期の重みは 25:25:15:15:10:10:5:5:10（紹介:フォロワー = 1:1 は変えない）
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
			$t = isset( $traffic[ $id ] ) ? $traffic[ $id ] : array( 'views' => 0, 'weighted_views' => 0, 'dwell_sum' => 0, 'dwell_count' => 0, 'out_clicks' => 0 );

			$values['referral'][ $id ]  = isset( $referral_w[ $id ] ) ? $referral_w[ $id ] : 0.0;
			$values['followers'][ $id ] = isset( $all_followers[ $id ] ) ? (int) $all_followers[ $id ] : 0;
			$values['views'][ $id ]     = $t['weighted_views'];
			// まだ誰にも見られていない人は、平均値の信号では最下位扱い（-1）。
			$values['dwell'][ $id ]    = $t['dwell_count'] ? ( $t['dwell_sum'] + self::PRIOR_DWELL * $avg_dwell ) / ( $t['dwell_count'] + self::PRIOR_DWELL ) : -1;
			$values['clicks'][ $id ]   = $t['views'] ? ( $t['out_clicks'] + self::PRIOR_CLICKS * $avg_click ) / ( $t['views'] + self::PRIOR_CLICKS ) : -1;
			$values['articles'][ $id ] = isset( $articles[ $id ] ) ? $articles[ $id ] : 0.0;
			$u                          = isset( $usage[ $id ] ) ? $usage[ $id ] : array( 'days' => 0, 'seconds' => 0, 'weighted_days' => 0.0, 'weighted_seconds' => 0.0 );
			$values['site_days'][ $id ] = $u['weighted_days'];
			$values['site_time'][ $id ] = $u['weighted_seconds'];

			$raw[ $id ] = array(
				'inflow'     => isset( $referral_raw[ $id ] ) ? $referral_raw[ $id ] : 0,
				'inflow_chk' => isset( $referral_check[ $id ] ) ? $referral_check[ $id ] : 0,
				'views'      => $t['views'],
				'dwell_avg'  => $t['dwell_count'] ? $t['dwell_sum'] / $t['dwell_count'] : 0,
				'click_rate' => $t['views'] ? $t['out_clicks'] / $t['views'] : 0,
				'usage_days' => $u['days'],
				'usage_time' => $u['seconds'],
			);
		}

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
		}

		$weights = $settings['weights'];
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
				$tiers = isset( $tier_counts[ $id ] ) ? $tier_counts[ $id ] : array_fill( 0, min( SNSCS_Invites::MAX_TIERS, max( 1, (int) $settings['tiers'] ) ), 0 );

				$rows[] = $wpdb->prepare(
					'(%d, %d, %d, %d, %f, %f, %f, %d, %d, %f, %d, %s, %f, %f, %s, %s, %f, %s)',
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
					wp_json_encode( $tiers ),
					$p['referral'],
					$p['followers'],
					wp_json_encode( array_map( 'floatval', $p ) ),
					implode( ',', $flags[ $id ] ),
					self::combine( $p, $weights ),
					$now
				);
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "INSERT INTO {$table} (creator_id, inflow, followers, views, dwell_avg, click_rate, articles, usage_days, usage_time, network, invites, tiers, inflow_points, follower_points, points, flags, score, scored_at) VALUES " . implode( ',', $rows ) . ' ON DUPLICATE KEY UPDATE inflow = VALUES(inflow), followers = VALUES(followers), views = VALUES(views), dwell_avg = VALUES(dwell_avg), click_rate = VALUES(click_rate), articles = VALUES(articles), usage_days = VALUES(usage_days), usage_time = VALUES(usage_time), network = VALUES(network), invites = VALUES(invites), tiers = VALUES(tiers), inflow_points = VALUES(inflow_points), follower_points = VALUES(follower_points), points = VALUES(points), flags = VALUES(flags), score = VALUES(score), scored_at = VALUES(scored_at)' );
		}

		SNSCS_Referrals::purge_old();
		SNSCS_Tracking::purge_old();
		SNSCS_Usage::purge_old( $days );
		update_option( 'snscs_scores_updated', time() );

		return count( $creators );
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
		);
	}
}
