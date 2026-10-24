<?php
/**
 * 発信者の招待（紹介ネットワーク）。
 *
 * 流れ:
 *   1. 誰かが発信者Aの紹介リンク（/r/コード/）か招待リンク（/join/コード/）から来ると、
 *      「Aからの招待」をクッキーに30日覚える（あとから来た別の招待で上書きする）。
 *   2. その人がメンバーとしてメンバー登録すると、招待した発信者を記録する（招待コードの手入力もできる）。
 *   3. 運営の審査を通って掲載されたときに、はじめて「Aが招待した発信者」になる（架空の登録では増やせない）。
 *
 * 表示順位への影響（SNSCS_Scores の「紹介ネットワーク」）:
 *   招待した人（1段目）、その人が招待した人（2段目）… を設定した段数（初期3段、最大5段）までさかのぼり、
 *   「招待1人あたりのボーナス ＋ その人の紹介リンク流入」を、段が深いほど小さく（初期は半分ずつ）足す。
 *   「要確認」の人からは紹介リンク流入を足さない。
 *
 * この仕組みは検索順位にだけ使い、お金や物などの報酬は付けない（連鎖販売取引などの規制に触れないため）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Invites {

	const COOKIE         = 'snscs_invite';
	const COOKIE_DAYS    = 30;
	const QUERY_VAR      = 'snscs_join';
	const AMBASSADOR_MIN = 3;
	const MAX_TIERS      = 5;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_join' ), 1 );
		add_action( 'snscs_referral_visit', array( __CLASS__, 'remember' ), 10, 2 );
		add_action( 'snscs_submission_approved', array( __CLASS__, 'link_on_approval' ), 5 );
	}

	public static function add_rewrite_rule() {
		add_rewrite_rule( '^join/([A-Za-z0-9]{4,16})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * 発信者を招待するリンク（登録ページへ直接案内する）。
	 */
	public static function join_url( $creator_id ) {
		$code = SNSCS_Scores::ref_code( $creator_id );
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/join/' . $code . '/' );
		}
		return add_query_arg( self::QUERY_VAR, $code, home_url( '/' ) );
	}

	public static function handle_join() {
		$code = get_query_var( self::QUERY_VAR );
		if ( '' === $code || ! is_string( $code ) ) {
			return;
		}
		$creator_id = SNSCS_Scores::creator_for_code( $code );
		if ( $creator_id && 'publish' === get_post_status( $creator_id ) ) {
			self::remember( $creator_id, $code );
		}
		nocache_headers();
		wp_safe_redirect( add_query_arg( 'snscs_invited', 1, snscs_portal_url() ) );
		exit;
	}

	/**
	 * 「この人からの招待」をクッキーに覚える。本人やログイン中の発信者には付けない。
	 */
	public static function remember( $creator_id, $code ) {
		if ( headers_sent() || ( is_user_logged_in() && snscs_is_creator_user() ) || SNSCS_Referrals::is_bot_request() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$code,
			array(
				'expires'  => time() + self::COOKIE_DAYS * DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE ] = $code;
	}

	/**
	 * クッキーに覚えている招待コード（使えるものだけ）。
	 */
	public static function remembered_code() {
		$code = isset( $_COOKIE[ self::COOKIE ] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return self::inviter_for_code( $code ) ? $code : '';
	}

	/**
	 * 招待コードから、招待した発信者（掲載中の人だけ）。
	 */
	public static function inviter_for_code( $code ) {
		$code = preg_replace( '/[^A-Za-z0-9]/', '', (string) $code );
		if ( '' === $code ) {
			return 0;
		}
		$creator_id = SNSCS_Scores::creator_for_code( $code );
		return ( $creator_id && 'publish' === get_post_status( $creator_id ) ) ? $creator_id : 0;
	}

	/**
	 * メンバー登録したときに、招待した発信者を覚えておく（まだ掲載前なので、ここでは数えない）。
	 */
	public static function record_signup( $user_id, $code ) {
		$inviter = self::inviter_for_code( $code );
		if ( $inviter ) {
			update_user_meta( $user_id, 'snscs_invited_by', $inviter );
		}
	}

	/**
	 * 新しい発信者が承認されて掲載されたとき、はじめて「招待した発信者」として結びつける。
	 */
	public static function link_on_approval( $submission_id ) {
		$row = SNSCS_Submissions::get( $submission_id );
		if ( ! $row || SNSCS_Submissions::TYPE_NEW !== $row['type'] || ! $row['creator_id'] ) {
			return;
		}
		$inviter = (int) get_user_meta( $row['user_id'], 'snscs_invited_by', true );
		if ( ! $inviter || $inviter === $row['creator_id'] || 'publish' !== get_post_status( $inviter ) || get_post_meta( $row['creator_id'], '_snscs_referrer', true ) ) {
			return;
		}
		update_post_meta( $row['creator_id'], '_snscs_referrer', $inviter );
		update_post_meta( $row['creator_id'], '_snscs_joined_at', current_time( 'mysql' ) );
		do_action( 'snscs_invite_joined', $inviter, $row['creator_id'] );
	}

	/* --------------------------------------------------------------------
	 * 紹介ネットワークの計算
	 * ------------------------------------------------------------------ */

	/**
	 * 掲載中の発信者どうしの「招待された人 => 招待した人」。
	 *
	 * @return array<int,int>
	 */
	public static function parents() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id AS child, pm.meta_value AS parent FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} c ON c.ID = pm.post_id AND c.post_type = %s AND c.post_status = 'publish'
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.meta_value AND p.post_type = %s AND p.post_status = 'publish'
				WHERE pm.meta_key = '_snscs_referrer'",
				SNSCS_Post_Types::POST_TYPE,
				SNSCS_Post_Types::POST_TYPE
			),
			ARRAY_A
		);
		$parents = array();
		foreach ( $rows as $row ) {
			$parents[ (int) $row['child'] ] = (int) $row['parent'];
		}
		return $parents;
	}

	/**
	 * 紹介ネットワークの値を計算する。
	 *
	 * 招待された人それぞれについて、招待した人を最大 $tiers 段さかのぼり、
	 * 「ボーナス ＋ その人の紹介リンク流入（要確認の人は0）」に段ごとの割合（$decay の段数乗）を掛けて足す。
	 * 計算量は「掲載人数 × 段数」なので、人数が増えても重くなりにくい。
	 *
	 * @param array<int,int>   $parents  招待された人 => 招待した人。
	 * @param array<int,float> $referral 発信者 => 紹介リンク流入（最近ほど重く数えた値）。
	 * @param array<int,bool>  $flagged  発信者 => 要確認か。
	 * @param int              $tiers    何段目まで数えるか（1〜5）。
	 * @param float            $decay    1段深くなるごとに掛ける割合（0〜1）。
	 * @param float            $bonus    招待1人あたりのボーナス（紹介リンク流入の人数に換算）。
	 * @return array{0:array<int,float>,1:array<int,int[]>} [発信者 => 値, 発信者 => 段ごとの人数]
	 */
	public static function network_values( array $parents, array $referral, array $flagged, $tiers, $decay, $bonus ) {
		$tiers  = min( self::MAX_TIERS, max( 1, (int) $tiers ) );
		$values = array();
		$counts = array();

		foreach ( $parents as $child => $parent ) {
			$contribution = (float) $bonus + ( empty( $flagged[ $child ] ) && isset( $referral[ $child ] ) ? (float) $referral[ $child ] : 0.0 );
			$ancestor     = $parent;
			$seen         = array( $child => true );
			for ( $depth = 1; $depth <= $tiers && $ancestor && ! isset( $seen[ $ancestor ] ); $depth++ ) {
				$seen[ $ancestor ] = true;
				if ( ! isset( $values[ $ancestor ] ) ) {
					$values[ $ancestor ] = 0.0;
					$counts[ $ancestor ] = array_fill( 0, $tiers, 0 );
				}
				$values[ $ancestor ]               += $contribution * pow( $decay, $depth - 1 );
				$counts[ $ancestor ][ $depth - 1 ] += 1;
				$ancestor                           = isset( $parents[ $ancestor ] ) ? $parents[ $ancestor ] : 0;
			}
		}

		return array( $values, $counts );
	}

	/* --------------------------------------------------------------------
	 * マイページ: 招待の欄
	 * ------------------------------------------------------------------ */

	public static function render_portal( $creator_id, $standing ) {
		$settings = SNSCS_Scores::settings();
		$link     = self::join_url( $creator_id );
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$message  = sprintf( "SNSで発信している人を探せる「%s」に掲載されています。発信者は無料で掲載できて、記事も書けます。よかったら一緒に参加しませんか？\n%s", $site, $link );
		$share    = sprintf( '「%s」にメンバーとして掲載されています。発信者は無料で掲載できます。', $site );
		$tiers    = $standing && ! empty( $standing['tiers'] ) ? $standing['tiers'] : array_fill( 0, (int) $settings['tiers'], 0 );
		?>
		<section class="snscs-panel snscs-invite" id="snscs-invite">
			<h2 class="snscs-panel__title">発信者仲間を招待する</h2>
			<p>あなたの招待で登録した人が審査を通って掲載されると、あなたの「紹介ネットワーク」の点数が上がり、おすすめ順で上に表示されやすくなります。招待した人がさらに誰かを招待した場合も、<strong><?php echo (int) $settings['tiers']; ?>段目まで</strong>あなたの点数に加わります（深い段ほど小さく数えます）。</p>

			<div class="snscs-referral__link">
				<label for="snscs-invite-link" class="screen-reader-text">招待リンク</label>
				<input id="snscs-invite-link" type="text" readonly value="<?php echo esc_url( $link ); ?>" onclick="this.select();">
				<button type="button" class="snscs-button snscs-button--primary" onclick="var i=document.getElementById('snscs-invite-link');i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent='コピーしました';">コピー</button>
			</div>

			<div class="snscs-share">
				<a class="snscs-share__button snscs-share__button--x" href="<?php echo esc_url( 'https://x.com/intent/tweet?' . http_build_query( array( 'text' => $share, 'url' => $link ) ) ); ?>" target="_blank" rel="noopener">Xで招待する</a>
				<a class="snscs-share__button snscs-share__button--line" href="<?php echo esc_url( 'https://social-plugins.line.me/lineit/share?' . http_build_query( array( 'url' => $link ) ) ); ?>" target="_blank" rel="noopener">LINEで送る</a>
				<a class="snscs-share__button snscs-share__button--fb" href="<?php echo esc_url( 'https://www.facebook.com/sharer/sharer.php?' . http_build_query( array( 'u' => $link ) ) ); ?>" target="_blank" rel="noopener">Facebookで共有</a>
			</div>

			<details class="snscs-invite__template">
				<summary>そのまま使える招待文</summary>
				<label for="snscs-invite-text" class="screen-reader-text">招待文</label>
				<textarea id="snscs-invite-text" rows="4" readonly><?php echo esc_textarea( $message ); ?></textarea>
				<button type="button" class="snscs-button" onclick="var t=document.getElementById('snscs-invite-text');t.select();navigator.clipboard&&navigator.clipboard.writeText(t.value);this.textContent='コピーしました';">招待文をコピー</button>
			</details>

			<p class="snscs-help">招待コード: <strong><?php echo esc_html( SNSCS_Scores::ref_code( $creator_id ) ); ?></strong>（口頭で伝えるときは、登録画面の「招待コード」に入れてもらいます）</p>

			<dl class="snscs-referral__stats">
				<?php foreach ( $tiers as $i => $count ) : ?>
					<div>
						<dt><?php echo (int) $i + 1; ?>段目<?php echo 0 === $i ? '（あなたが招待した人）' : ''; ?></dt>
						<dd><?php echo esc_html( number_format_i18n( $count ) ); ?>人</dd>
					</div>
				<?php endforeach; ?>
			</dl>
			<p class="snscs-help">
				掲載された人だけを数えます（審査中・掲載停止中の人は数えません）。<?php echo (int) self::AMBASSADOR_MIN; ?>人以上を招待すると、発信者ページに「アンバサダー」のバッジが付きます。
				招待はおすすめ順にだけ使われ、お金などの報酬はありません。
			</p>
		</section>
		<?php
	}

	/**
	 * 発信者ページの「アンバサダー」バッジ。
	 */
	public static function badge( $creator_id ) {
		global $wpdb;
		$table   = SNSCS_Scores::table();
		$invites = (int) $wpdb->get_var( $wpdb->prepare( "SELECT invites FROM {$table} WHERE creator_id = %d", $creator_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $invites < self::AMBASSADOR_MIN ) {
			return '';
		}
		return sprintf( '<p class="snscs-ambassador"><span class="snscs-badge snscs-badge--ambassador">アンバサダー</span> %d人の発信者をこのサイトに招待しています</p>', $invites );
	}
}
