<?php
/**
 * メンバーのレベル・連続記録・バッジ・友だちの招待（続けて使いたくなる仕組み）。
 *
 *   ポイント: 記事を読む・いいね・フォロー・アワードの投票・推しリスト・質問・回答などで増える。
 *             行動ごとに1日の上限があり、ためすぎ（水増し）はできない。ほかの機能は do_action( 'snscs_points', メンバーID, 行動 ) で知らせる。
 *   レベル  : 1.26 から SNSCS_Game（経験値・上限なし・カードランク）。ここは行動の回数と上限・連続記録・バッジ・招待を受け持つ。
 *   連続記録: 読む・反応・投票・投稿のどれかをした日が続いた日数（最高記録も残す）。3日以上続いている人には、
 *             その日まだ何もしていなければ20時ごろに「連続記録が途切れそう」とお知らせ（スマホ通知・LINEにも）。
 *   バッジ  : 「7日連続」「記事を10本読んだ」「推しリストを作った」など。手に入れるとお知らせ。
 *   招待    : メンバーごとの招待リンク（?rinv=コード）。招待された人が登録し、最初に読む・反応するなどをしたら、
 *             招待した人に50ポイントと「招待」バッジ、招待された人に20ポイント（登録だけでは数えない）。
 *   表示    : メンバーページの上にレベル・連続記録・バッジ、タブ「レベル・招待」に詳しく。公開プロフィールにもレベルとバッジ。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Gamify {

	const META_XP     = 'snscs_xp';
	const META_STREAK = 'snscs_streak';
	const META_LAST   = 'snscs_streak_last';
	const META_BEST   = 'snscs_streak_best';
	const META_BADGES = 'snscs_badges';
	const META_CODE   = 'snscs_rinv';
	const META_BY     = 'snscs_invited_by';
	const META_VISIT  = 'snscs_last_visit';
	const COOKIE      = 'snscs_rinv';

	/** 行動 => [ポイント, 1日の上限回数, 連続記録に数えるか] */
	const ACTIONS = array(
		'read'             => array( 3, 10, true ),
		'react'            => array( 1, 20, true ),
		'follow'           => array( 2, 10, true ),
		'vote'             => array( 5, 10, true ),
		'list'             => array( 10, 2, true ),
		'list_item'        => array( 2, 10, true ),
		'question'         => array( 5, 3, true ),
		'answer'           => array( 10, 10, true ),
		'helpful_received' => array( 3, 20, false ),
		'best_answer'      => array( 20, 5, false ),
		'visit'            => array( 2, 1, false ),
		'invite'           => array( 300, 5, false ),
		'welcome'          => array( 20, 1, false ),
	);

	public static function init() {
		add_action( 'snscs_points', array( __CLASS__, 'award' ), 10, 2 );
		add_action( 'snscs_social_changed', array( __CLASS__, 'on_social' ), 10, 4 );
		// 1.26: ログインしただけでは経験値にしない（何か行動した日を「訪問」として SNSCS_Game が数える）。
		add_action( 'template_redirect', array( __CLASS__, 'catch_invite' ), 1 );
		add_action( 'user_register', array( __CLASS__, 'on_register' ) );
		add_action( SNSCS_Inbox::CRON_HOOK, array( __CLASS__, 'remind_streaks' ), 40 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_points';
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
  action varchar(20) NOT NULL,
  n smallint(5) unsigned NOT NULL DEFAULT 0,
  points smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (user_id,day,action),
  KEY day (day)
) {$charset};"
		);
	}

	/* --------------------------------------------------------------------
	 * ポイント
	 * ------------------------------------------------------------------ */

	/**
	 * 行動にポイントを付ける（1日の上限まで）。
	 *
	 * @return int 増えたポイント。
	 */
	public static function award( $user_id, $action ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || ! isset( self::ACTIONS[ $action ] ) || user_can( $user_id, 'edit_others_posts' ) || ! snscs_is_full_member( $user_id ) ) {
			return 0;
		}
		global $wpdb;
		list( $points, $cap, $streak ) = self::ACTIONS[ $action ];
		$day   = current_time( 'Y-m-d' );
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT n FROM {$table} WHERE user_id = %d AND day = %s AND action = %s", $user_id, $day, $action ) );
		if ( $n >= $cap ) {
			return 0;
		}
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (user_id, day, action, n, points) VALUES (%d, %s, %s, 1, %d) ON DUPLICATE KEY UPDATE n = n + 1, points = points + VALUES(points)", $user_id, $day, $action, $points ) );
		// phpcs:enable
		// 1.26: 経験値は SNSCS_Game が付ける（レベル・上乗せ・イベントの倍率・訪問の記録もそこで）。
		SNSCS_Game::action( $user_id, $points, 'invite' === $action ? 'referral' : 'action' );
		if ( $streak ) {
			self::touch_streak( $user_id );
			self::credit_inviter( $user_id );
		}
		self::check_badges( $user_id );
		return $points;
	}

	public static function on_social( $user_id, $action, $id, $on ) {
		if ( ! $on ) {
			return;
		}
		if ( in_array( $action, array( 'follow', 'follow_user' ), true ) ) {
			self::award( $user_id, 'follow' );
		} elseif ( in_array( $action, array( 'like', 'repost', 'bookmark' ), true ) ) {
			self::award( $user_id, 'react' );
		}
	}

	/**
	 * ログインして来た日（1日1回）。
	 */
	public static function on_visit() {
		if ( ! is_user_logged_in() || is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$user  = get_current_user_id();
		$today = current_time( 'Y-m-d' );
		if ( get_user_meta( $user, self::META_VISIT, true ) !== $today ) {
			update_user_meta( $user, self::META_VISIT, $today );
			self::award( $user, 'visit' );
		}
	}

	public static function level( $xp ) {
		return SNSCS_Game::level( $xp );
	}

	/* --------------------------------------------------------------------
	 * 連続記録
	 * ------------------------------------------------------------------ */

	private static function touch_streak( $user_id ) {
		$today = current_time( 'Y-m-d' );
		$last  = (string) get_user_meta( $user_id, self::META_LAST, true );
		if ( $last === $today ) {
			return;
		}
		$yesterday = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );
		$streak    = $last === $yesterday ? (int) get_user_meta( $user_id, self::META_STREAK, true ) + 1 : 1;
		update_user_meta( $user_id, self::META_STREAK, $streak );
		update_user_meta( $user_id, self::META_LAST, $today );
		if ( $streak > (int) get_user_meta( $user_id, self::META_BEST, true ) ) {
			update_user_meta( $user_id, self::META_BEST, $streak );
		}
	}

	/**
	 * いま続いている日数（昨日までで止まっていれば、今日のうちはまだ続いているとみなす）。
	 */
	public static function streak( $user_id ) {
		$last  = (string) get_user_meta( $user_id, self::META_LAST, true );
		$today = current_time( 'Y-m-d' );
		if ( $last !== $today && $last !== gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) ) ) {
			return 0;
		}
		return (int) get_user_meta( $user_id, self::META_STREAK, true );
	}

	/**
	 * 20時以降の最初の見回りで、途切れそうな連続記録（3日以上）をお知らせする。
	 */
	public static function remind_streaks() {
		$today = current_time( 'Y-m-d' );
		if ( (int) current_time( 'G' ) < 20 || get_option( 'snscs_streak_remind' ) === $today ) {
			return 0;
		}
		update_option( 'snscs_streak_remind', $today, false );
		global $wpdb;
		$yesterday = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );
		$users     = $wpdb->get_col( $wpdb->prepare( "SELECT l.user_id FROM {$wpdb->usermeta} l INNER JOIN {$wpdb->usermeta} s ON s.user_id = l.user_id AND s.meta_key = %s AND CAST(s.meta_value AS UNSIGNED) >= 3 WHERE l.meta_key = %s AND l.meta_value = %s LIMIT 5000", self::META_STREAK, self::META_LAST, $yesterday ) );
		foreach ( $users as $user ) {
			SNSCS_Inbox::add( (int) $user, 'streak', (int) get_user_meta( (int) $user, self::META_STREAK, true ) );
		}
		return count( $users );
	}

	/* --------------------------------------------------------------------
	 * バッジ
	 * ------------------------------------------------------------------ */

	/**
	 * バッジ: キー => [名前, 説明, 行動の合計か連続記録の条件]
	 */
	public static function badges() {
		return array(
			'streak7'   => array( '🔥 7日連続', '7日続けて読む・反応するなどした', array( 'best', 7 ) ),
			'streak30'  => array( '🔥 30日連続', '30日続けた', array( 'best', 30 ) ),
			'read10'    => array( '📖 読書家', '記事を10本最後まで読んだ', array( 'read', 10 ) ),
			'read100'   => array( '📚 本の虫', '記事を100本最後まで読んだ', array( 'read', 100 ) ),
			'follow10'  => array( '👀 発見上手', '10人をフォローした', array( 'follow', 10 ) ),
			'voter'     => array( '🗳 応援団', 'アワードに投票した', array( 'vote', 1 ) ),
			'curator'   => array( '📝 キュレーター', '推しリストを作った', array( 'list', 1 ) ),
			'question'  => array( '❓ 質問者', '質問箱で質問した', array( 'question', 1 ) ),
			'helper'    => array( '💡 回答者', '質問箱で回答した', array( 'answer', 1 ) ),
			'helpful10' => array( '🙌 頼れる人', '回答が「参考になった」を10回もらった', array( 'helpful_received', 10 ) ),
			'inviter'   => array( '🤝 招待', '友だちを招待した', array( 'invite', 1 ) ),
			'inviter5'  => array( '🎉 人気者', '友だちを5人招待した', array( 'invite', 5 ) ),
		);
	}

	/**
	 * 行動ごとの、これまでの回数。
	 *
	 * @return array<string,int>
	 */
	public static function totals( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', wp_list_pluck( $wpdb->get_results( $wpdb->prepare( 'SELECT action, SUM(n) AS n FROM ' . self::table() . ' WHERE user_id = %d GROUP BY action', $user_id ), ARRAY_A ), 'n', 'action' ) );
	}

	public static function earned( $user_id ) {
		return array_values( array_intersect( (array) get_user_meta( $user_id, self::META_BADGES, true ), array_keys( self::badges() ) ) );
	}

	private static function check_badges( $user_id ) {
		$totals = self::totals( $user_id );
		$best   = (int) get_user_meta( $user_id, self::META_BEST, true );
		$have   = self::earned( $user_id );
		$new    = array();
		$i      = 0;
		foreach ( self::badges() as $key => $badge ) {
			++$i;
			list( $what, $need ) = $badge[2];
			$value = 'best' === $what ? $best : (int) ( $totals[ $what ] ?? 0 );
			if ( $value >= $need && ! in_array( $key, $have, true ) ) {
				$have[] = $key;
				$new[]  = $i;
			}
		}
		if ( $new ) {
			update_user_meta( $user_id, self::META_BADGES, $have );
			foreach ( $new as $index ) {
				SNSCS_Inbox::add( $user_id, 'badge', $index );
			}
		}
	}

	/* --------------------------------------------------------------------
	 * 招待
	 * ------------------------------------------------------------------ */

	public static function invite_code( $user_id ) {
		$code = (string) get_user_meta( $user_id, self::META_CODE, true );
		if ( '' === $code ) {
			$code = strtolower( wp_generate_password( 8, false ) );
			update_user_meta( $user_id, self::META_CODE, $code );
		}
		return $code;
	}

	public static function invite_url( $user_id ) {
		return add_query_arg( 'rinv', self::invite_code( $user_id ), home_url( '/' ) );
	}

	public static function catch_invite() {
		if ( empty( $_GET['rinv'] ) || is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$code = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) wp_unslash( $_GET['rinv'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 8 === strlen( $code ) ) {
			setcookie( self::COOKIE, $code, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		}
	}

	public static function on_register( $user_id ) {
		$code = isset( $_COOKIE[ self::COOKIE ] ) ? preg_replace( '/[^a-z0-9]/', '', (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' === $code ) {
			return;
		}
		$found = get_users( array( 'meta_key' => self::META_CODE, 'meta_value' => $code, 'fields' => 'ID', 'number' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $found && (int) $found[0] !== (int) $user_id ) {
			update_user_meta( $user_id, self::META_BY, (int) $found[0] );
		}
	}

	/**
	 * 招待された人が最初に何かしたら、招待した人にポイント（1回だけ）。
	 */
	private static function credit_inviter( $user_id ) {
		$by = (int) get_user_meta( $user_id, self::META_BY, true );
		if ( ! $by || get_user_meta( $user_id, self::META_BY . '_done', true ) ) {
			return;
		}
		update_user_meta( $user_id, self::META_BY . '_done', 1 );
		self::award( $user_id, 'welcome' );
		self::award( $by, 'invite' );
		SNSCS_Inbox::add( $by, 'invite_ok', $user_id );
	}

	public static function invited_count( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} a INNER JOIN {$wpdb->usermeta} b ON b.user_id = a.user_id AND b.meta_key = %s WHERE a.meta_key = %s AND a.meta_value = %d", self::META_BY . '_done', self::META_BY, $user_id ) );
	}

	/* --------------------------------------------------------------------
	 * お知らせの文
	 * ------------------------------------------------------------------ */

	public static function describe( array $row ) {
		$url = SNSCS_Social::member_url( array( 'tab' => 'level' ) );
		switch ( $row['type'] ) {
			case 'level_up':
				return SNSCS_Game::describe( $row );
			case 'badge':
				$keys = array_keys( self::badges() );
				$key  = $keys[ (int) $row['object_id'] - 1 ] ?? '';
				return '' !== $key ? array( 'text' => 'バッジ「' . self::badges()[ $key ][0] . '」を手に入れました（' . self::badges()[ $key ][1] . '）。', 'url' => $url ) : null;
			case 'streak':
				return array( 'text' => (int) $row['object_id'] . '日続いている連続記録が、今日で途切れそうです。記事を1本読んで記録をのばしましょう🔥', 'url' => SNSCS_Stories::stories_url() );
			case 'invite_ok':
				return array( 'text' => '紹介した友だちが使い始めました。経験値300を手に入れました！', 'url' => $url );
		}
		return null;
	}

	/* --------------------------------------------------------------------
	 * 表示
	 * ------------------------------------------------------------------ */

	/**
	 * レベル・連続記録・バッジの小さなまとめ（メンバーページの上・公開プロフィール）。
	 */
	public static function summary_html( $user_id, $compact = false ) {
		wp_enqueue_style( 'snscs' );
		$info   = SNSCS_Game::level_info( $user_id );
		$streak = self::streak( $user_id );
		$badges = self::earned( $user_id );
		$html   = '<div class="snscs-level' . ( $compact ? ' snscs-level--compact' : '' ) . '">';
		$html  .= '<p class="snscs-level__lv"><strong>Lv.' . $info['level'] . '</strong> ' . esc_html( $info['title'] ) . ' <span class="snscs-level__rank">' . esc_html( SNSCS_Game::rank_name( SNSCS_Game::rank_of( $user_id ) ) ) . '</span></p>';
		if ( ! $compact ) {
			$html .= '<div class="snscs-level__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $info['pct'] . '"><span style="width:' . $info['pct'] . '%"></span></div>';
			$html .= '<p class="snscs-level__xp">' . esc_html( '経験値 ' . number_format_i18n( $info['xp'] ) . '（次のレベルまで' . number_format_i18n( $info['next'] - $info['xp'] ) . '）' ) . '</p>';
			$html .= '<p class="snscs-level__streak">🔥 ' . esc_html( $streak ? $streak . '日連続' : '今日から連続記録をはじめよう' ) . '<small>（最高' . (int) get_user_meta( $user_id, self::META_BEST, true ) . '日）</small></p>';
		}
		if ( $badges ) {
			$html .= '<ul class="snscs-level__badges">';
			foreach ( $badges as $key ) {
				$html .= '<li title="' . esc_attr( self::badges()[ $key ][1] ) . '">' . esc_html( self::badges()[ $key ][0] ) . '</li>';
			}
			$html .= '</ul>';
		}
		return $html . '</div>';
	}

	/**
	 * メンバーページのタブ「レベル・招待」。
	 */
	public static function render_member( $user_id ) {
		$totals = self::totals( $user_id );
		$best   = (int) get_user_meta( $user_id, self::META_BEST, true );
		$have   = self::earned( $user_id );
		$link   = self::invite_url( $user_id );
		$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		// 紹介で経験値がもらえるので、投稿には「#PR」を付ける（ステルスマーケティングにならないように）。
		$text   = '#PR 「' . $site . '」で好きなSNS発信者を見つけています。いっしょに使ってみませんか？';
		SNSCS_Game::render_member( $user_id );
		?>
		<h3>友だちを紹介する</h3>
		<p>この紹介リンクから登録した友だちが使い始めると、あなたに経験値300、友だちに経験値20。紹介した友だちがメンバーとして掲載されると、経験値1000。いま<?php echo (int) self::invited_count( $user_id ); ?>人を紹介しています。</p>
		<p class="snscs-help">紹介の投稿には「#PR」を付けてください（紹介で経験値がもらえるため、広告であることを示す決まりがあります）。下のボタンの文には、はじめから入っています。</p>
		<p><input class="snscs-input" type="text" readonly value="<?php echo esc_attr( $link ); ?>" onclick="this.select();"></p>
		<div class="snscs-share">
			<a class="snscs-share__button snscs-share__button--line" href="<?php echo esc_url( 'https://social-plugins.line.me/lineit/share?' . http_build_query( array( 'url' => $link ) ) ); ?>" target="_blank" rel="noopener">LINEで送る</a>
			<a class="snscs-share__button snscs-share__button--x" href="<?php echo esc_url( 'https://x.com/intent/tweet?' . http_build_query( array( 'text' => $text, 'url' => $link ) ) ); ?>" target="_blank" rel="noopener">Xで招待</a>
		</div>
		<h3>バッジ</h3>
		<ul class="snscs-badgelist">
			<?php foreach ( self::badges() as $key => $badge ) : ?>
				<?php
				list( $what, $need ) = $badge[2];
				$value = min( $need, 'best' === $what ? $best : (int) ( $totals[ $what ] ?? 0 ) );
				$on    = in_array( $key, $have, true );
				?>
				<li class="<?php echo $on ? 'is-on' : ''; ?>"><strong><?php echo esc_html( $badge[0] ); ?></strong> <span><?php echo esc_html( $badge[1] ); ?></span> <small><?php echo esc_html( $on ? '獲得' : $value . ' / ' . $need ); ?></small></li>
			<?php endforeach; ?>
		</ul>
		<h3>ポイントのため方</h3>
		<table class="snscs-pointtable">
			<thead><tr><th>すること</th><th>ポイント</th><th>1日の上限</th></tr></thead>
			<tbody>
				<?php
				$labels = array( 'read' => '記事を最後まで読む', 'react' => 'いいね・リポスト・ブックマーク', 'follow' => 'フォローする', 'vote' => 'アワードに投票する', 'list' => '推しリストを作る', 'list_item' => '推しリストに発信者を入れる', 'question' => '質問箱で質問する', 'answer' => '質問箱で回答する（発信者）', 'visit' => 'ログインして来る', 'invite' => '友だちを招待する' );
				foreach ( $labels as $key => $label ) :
					?>
					<tr><td><?php echo esc_html( $label ); ?></td><td><?php echo (int) self::ACTIONS[ $key ][0]; ?></td><td><?php echo (int) self::ACTIONS[ $key ][1]; ?>回</td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
