<?php
/**
 * FolloLink（SNSのプロフィール欄に貼る「リンクまとめ」ページ）。
 *
 *   /@ID  （IDは発信者が決める。審査あり。決めるまでは紹介コードで /@紹介コード）
 *
 * - スマホで見やすい1枚のページ（テーマの見た目を使わない）に、SNS・note/Brain・その他のリンク・
 *   最新の記事・お仕事の相談を大きなボタンで並べる。デザインは6種類から選べる（選ぶだけなので審査なし）
 * - 開かれたら紹介リンク流入として数え（表示順位に効く）、閲覧・滞在時間・リンクのクリックも数える
 * - どのリンクが何回押されたかを、発信者のマイページに表示する
 * - ページの下に「ほかの発信者を探す」「あなたもFolloLinkを作る（無料）」を出し、サイトへの入口にする
 *   （後者は発信者の招待リンクなので、登録されれば招待として数える）
 * - 1.16: いちばん見てほしいリンクを上に大きく出す（「新着」などの札は選ぶだけ）、ボタンの形、受賞・順位のバッジ、
 *   似ている発信者（発信者が消せる）、どのSNSから来たか（?s=ig のような印、無ければ参照元）と開かれた数・押された率、
 *   日本語以外の閲覧者には英語の表示（英訳があれば紹介文も英語。SNSCS_English）
 * - 1.17: 名刺・チラシ用のQRコード（SNSCS_QR。?s=qr で数える）、読者が「このサイトでフォロー」（未登録なら登録して戻る）、
 *   シェアボタン、ProfilePage の構造化データ、IDを決めた人のFolloLinkをサイトマップへ、
 *   週に1回の成績のお知らせと、まだ使っていない人への案内、紹介ページの「今週よく見られたFolloLink」、
 *   マイページからXやThreadsにそのまま投稿できるボタン
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Biolink {

	const QUERY_VAR    = 'snscs_bio';
	const META_NAME    = '_snscs_linkname';
	const META_THEME   = '_snscs_link_theme';
	const META_SHAPE   = '_snscs_link_shape';
	const META_PIN     = '_snscs_link_pin';
	const META_SIMILAR = '_snscs_link_similar';
	const MAX_NAME     = 30;
	const KEEP_DAYS    = 90;
	const SEEN_KIND    = 11;

	/** 来たSNSの印（?s=）=> 表示名。参照元のドメインからも決める。 */
	const SOURCES = array(
		'x'         => 'X',
		'ig'        => 'Instagram',
		'tt'        => 'TikTok',
		'yt'        => 'YouTube',
		'th'        => 'Threads',
		'fb'        => 'Facebook',
		'line'      => 'LINE',
		'note'      => 'note',
		'qr'        => 'QRコード（名刺・チラシ）',
		'search'    => '検索エンジン',
		'other'     => 'そのほかのサイト',
		'direct'    => '直接（アプリ内など）',
	);

	/** 目立たせるリンクに付ける札（選ぶだけ。自由な文は審査が要るので使わない）。 */
	const PIN_TAGS = array(
		''        => array( 'なし', '' ),
		'new'     => array( '新着', 'New' ),
		'pick'    => array( 'おすすめ', 'Featured' ),
		'limited' => array( '期間限定', 'Limited' ),
		'sale'    => array( 'セール中', 'On sale' ),
		'live'    => array( '配信中', 'Live' ),
		'event'   => array( 'イベント', 'Event' ),
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'render' ), 0 );
		add_action( 'admin_post_snscs_link_theme', array( __CLASS__, 'handle_theme' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_creator' ) );
		add_action( SNSCS_Scores::CRON_HOOK, array( __CLASS__, 'purge_old' ) );
		add_shortcode( 'sns_mylink', array( __CLASS__, 'shortcode_landing' ) );
		add_action( 'init', array( __CLASS__, 'serve_qr' ), 1 );
		add_action( 'admin_post_snscs_bio_follow', array( __CLASS__, 'handle_follow' ) );
		add_action( SNSCS_Scores::CRON_HOOK, array( __CLASS__, 'weekly_notices' ) );
		SNSCS_Subpages::sitemap( 'snscsbio', array( __CLASS__, 'sitemap_urls' ), array( __CLASS__, 'sitemap_count' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_link_clicks';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  creator_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  link_key varchar(40) NOT NULL,
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (creator_id,day,link_key)
) {$charset};"
		);
	}

	public static function add_rewrite_rule() {
		add_rewrite_rule( '^@([A-Za-z0-9_-]{3,30})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/* --------------------------------------------------------------------
	 * ID とURL
	 * ------------------------------------------------------------------ */

	/**
	 * IDに使えない言葉（サイトの機能や、運営と間違えられやすいもの）。
	 */
	public static function reserved() {
		return apply_filters( 'snscs_reserved_linknames', array( 'admin', 'administrator', 'root', 'support', 'help', 'info', 'official', 'staff', 'system', 'wp', 'wordpress', 'api', 'login', 'mypage', 'search', 'ranking', 'find', 'tools', 'favorites', 'newsletter', 'business', 'calendar', 'join', 'snscs', 'unei', 'koushiki', 'test' ) );
	}

	/**
	 * IDとして使えるか。使えなければ理由を返す。
	 *
	 * @return string エラーの文。使えるなら空文字。
	 */
	public static function validate_name( $name, $creator_id = 0 ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-z0-9_-]{3,' . self::MAX_NAME . '}$/', $name ) ) {
			return 'FolloLinkのIDは、半角英数字・ハイフン・アンダーバーで3〜30文字にしてください。';
		}
		if ( in_array( $name, self::reserved(), true ) ) {
			return 'そのFolloLinkのIDは使えません。別のIDにしてください。';
		}
		$taken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s AND post_id <> %d LIMIT 1", self::META_NAME, $name, (int) $creator_id ) );
		// 紹介コード（決める前のFolloLink）とも重ならないようにする。
		$code = SNSCS_Scores::creator_for_code( $name );
		if ( $taken || ( $code && $code !== (int) $creator_id ) ) {
			return 'そのFolloLinkのIDは、ほかの発信者が使っています。別のIDにしてください。';
		}
		return '';
	}

	/**
	 * IDから発信者（掲載中の人だけ）。決めたIDが無ければ紹介コードとしても探す。
	 */
	public static function creator_for( $name ) {
		global $wpdb;
		$name = (string) $name;
		$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", self::META_NAME, strtolower( $name ) ) );
		if ( ! $id ) {
			$id = SNSCS_Scores::creator_for_code( $name );
		}
		return ( $id && 'publish' === get_post_status( $id ) && SNSCS_Post_Types::POST_TYPE === get_post_type( $id ) ) ? $id : 0;
	}

	public static function name_for( $creator_id ) {
		$name = (string) get_post_meta( $creator_id, self::META_NAME, true );
		return '' !== $name ? $name : SNSCS_Scores::ref_code( $creator_id );
	}

	/**
	 * 見せるためのアドレス（ブランドの表記）: FolloFind/@名前。実際に開くアドレスは url()。
	 */
	public static function display_address( $creator_id ) {
		return 'FolloFind/@' . self::name_for( $creator_id );
	}

	public static function url( $creator_id ) {
		$name = self::name_for( $creator_id );
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/@' . $name );
		}
		return add_query_arg( self::QUERY_VAR, $name, home_url( '/' ) );
	}

	/* --------------------------------------------------------------------
	 * デザイン
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,array{label:string,bg:string,text:string,muted:string,button:string,button_text:string,border:string}>
	 */
	public static function themes() {
		return SNSCS_Biolink_Design::themes();
	}

	public static function theme_for( $creator_id ) {
		return SNSCS_Biolink_Design::style_for( $creator_id )['theme'];
	}

	/**
	 * ボタンの形 => [表示名, 角の丸み]
	 */
	public static function shapes() {
		return SNSCS_Biolink_Design::shapes();
	}

	public static function shape_for( $creator_id ) {
		return SNSCS_Biolink_Design::style_for( $creator_id )['shape'];
	}

	/**
	 * 目立たせるリンク（いま並んでいるボタンにあるものだけ）。
	 *
	 * @return array{key:string,tag:string}|null
	 */
	public static function pin_for( $creator_id ) {
		$pin = get_post_meta( $creator_id, self::META_PIN, true );
		if ( ! is_array( $pin ) || empty( $pin['key'] ) ) {
			return null;
		}
		$tag = isset( $pin['tag'] ) && array_key_exists( $pin['tag'], self::PIN_TAGS ) ? $pin['tag'] : '';
		return array( 'key' => (string) $pin['key'], 'tag' => $tag );
	}

	public static function shows_similar( $creator_id ) {
		return 'off' !== get_post_meta( $creator_id, self::META_SIMILAR, true );
	}

	public static function handle_theme() {
		if ( ! is_user_logged_in() || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_link_theme' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$creator_id = SNSCS_Submissions::creator_id_for_user( get_current_user_id() );
		if ( $creator_id ) {
			$in = array();
			foreach ( array( 'theme', 'shape', 'button', 'font', 'ring', 'layout', 'bg', 'btn' ) as $key ) {
				$in[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			}
			SNSCS_Biolink_Design::save( $creator_id, $in );
			$pin  = isset( $_POST['pin'] ) ? sanitize_text_field( wp_unslash( $_POST['pin'] ) ) : '';
			$tag  = isset( $_POST['pin_tag'] ) ? sanitize_key( $_POST['pin_tag'] ) : '';
			$keys = wp_list_pluck( self::buttons( $creator_id ), 'key' );
			if ( in_array( $pin, $keys, true ) ) {
				update_post_meta( $creator_id, self::META_PIN, array( 'key' => $pin, 'tag' => array_key_exists( $tag, self::PIN_TAGS ) ? $tag : '' ) );
			} else {
				delete_post_meta( $creator_id, self::META_PIN );
			}
			update_post_meta( $creator_id, self::META_SIMILAR, empty( $_POST['similar'] ) ? 'off' : 'on' );
		}
		wp_safe_redirect( add_query_arg( 'snscs_notice', 'link_theme_saved', snscs_portal_url() ) . '#snscs-biolink' );
		exit;
	}

	/* --------------------------------------------------------------------
	 * クリックの記録
	 * ------------------------------------------------------------------ */

	/**
	 * リンクのクリックを1回数える（どのリンクかの分析用。表示順位には使わない）。
	 */
	public static function record_click( $creator_id, $key ) {
		global $wpdb;
		if ( ! preg_match( '/^(sns|store|link|page|inquiry|article|similar|view|src|follow):[a-z0-9_]{1,20}$/', $key ) ) {
			return;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (creator_id, day, link_key, clicks) VALUES (%d, %s, %s, 1) ON DUPLICATE KEY UPDATE clicks = clicks + 1", $creator_id, current_time( 'Y-m-d' ), $key ) );
	}

	/**
	 * 直近の日数のリンクごとのクリック数（多い順）。
	 *
	 * @return array<string,int>
	 */
	public static function clicks( $creator_id, $days = 30 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . ( $days - 1 ) . ' days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT link_key, SUM(clicks) AS n FROM {$table} WHERE creator_id = %d AND day >= %s GROUP BY link_key ORDER BY n DESC", $creator_id, $since ), ARRAY_A );
		return array_map( 'intval', wp_list_pluck( $rows, 'n', 'link_key' ) );
	}

	/**
	 * FolloLinkが開かれたことを数える（同じ人の同じ日は1回）。どのSNSから来たかも数える。
	 */
	public static function record_visit( $creator_id ) {
		global $wpdb;
		if ( SNSCS_Referrals::is_bot_request() ) {
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$new = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . SNSCS_Feed::seen_table() . ' (day, visitor, article_id, kind) VALUES (%s, %s, %d, %d)', current_time( 'Y-m-d' ), substr( SNSCS_Referrals::visitor_hash(), 0, 16 ), $creator_id, self::SEEN_KIND ) );
		if ( $new ) {
			self::record_click( $creator_id, 'view:page' );
			self::record_click( $creator_id, 'src:' . self::source() );
		}
	}

	/**
	 * 来たところ。URLの印（?s=ig）がいちばん確か（アプリの中のブラウザは参照元を送らないことが多い）。
	 */
	public static function source() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mark = isset( $_GET['s'] ) ? sanitize_key( wp_unslash( $_GET['s'] ) ) : '';
		if ( isset( self::SOURCES[ $mark ] ) && ! in_array( $mark, array( 'other', 'direct', 'search' ), true ) ) {
			return $mark;
		}
		$ref  = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$host = strtolower( (string) wp_parse_url( $ref, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return 'direct';
		}
		$map = array(
			'x'      => '/(^|\.)(x\.com|twitter\.com|t\.co)$/',
			'ig'     => '/(^|\.)instagram\.com$/',
			'tt'     => '/(^|\.)tiktok\.com$/',
			'yt'     => '/(^|\.)(youtube\.com|youtu\.be)$/',
			'th'     => '/(^|\.)threads\.(net|com)$/',
			'fb'     => '/(^|\.)(facebook\.com|fb\.me)$/',
			'line'   => '/(^|\.)line\.me$/',
			'note'   => '/(^|\.)note\.com$/',
			'search' => '/(^|\.)(google\.[a-z.]+|bing\.com|search\.yahoo\.co\.jp|duckduckgo\.com)$/',
		);
		foreach ( $map as $key => $re ) {
			if ( preg_match( $re, $host ) ) {
				return $key;
			}
		}
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $host ? 'direct' : 'other';
	}

	/**
	 * 直近の日数の、開かれた数・押された数・来たSNS。
	 *
	 * @return array{views:int,clicks:int,sources:array<string,int>,links:array<string,int>}
	 */
	public static function stats( $creator_id, $days = 30 ) {
		$all   = self::clicks( $creator_id, $days );
		$stats = array( 'views' => 0, 'clicks' => 0, 'sources' => array(), 'links' => array() );
		foreach ( $all as $key => $n ) {
			if ( 'view:page' === $key ) {
				$stats['views'] = $n;
			} elseif ( 0 === strpos( $key, 'src:' ) ) {
				$stats['sources'][ substr( $key, 4 ) ] = $n;
			} else {
				$stats['links'][ $key ] = $n;
				$stats['clicks']       += $n;
			}
		}
		return $stats;
	}

	/**
	 * FolloLinkからのフォロー（メンバーだけ。フォローしたら、FolloLinkに戻る）。
	 */
	public static function handle_follow() {
		$creator_id = isset( $_POST['creator'] ) ? absint( $_POST['creator'] ) : 0;
		if ( ! is_user_logged_in() || ! $creator_id || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_bio_follow_' . $creator_id ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$done = SNSCS_Social::toggle( get_current_user_id(), 'follow', $creator_id, true );
		if ( ! is_wp_error( $done ) ) {
			self::record_click( $creator_id, 'follow:site' );
		}
		wp_safe_redirect( add_query_arg( 'snscs_followed', is_wp_error( $done ) ? 0 : 1, self::url( $creator_id ) ) );
		exit;
	}

	/**
	 * QRコード（?snscs_bio_qr=発信者ID&fmt=svg|png&dl=1）。中身はFolloLinkのURL（?s=qr 付き）。
	 */
	public static function qr_target( $creator_id ) {
		return add_query_arg( 's', 'qr', self::url( $creator_id ) );
	}

	public static function qr_url( $creator_id, $fmt = 'svg', $download = false ) {
		return add_query_arg( array_filter( array( 'snscs_bio_qr' => (int) $creator_id, 'fmt' => $fmt, 'dl' => $download ? 1 : 0 ) ), home_url( '/' ) );
	}

	public static function serve_qr() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['snscs_bio_qr'] ) ) {
			return;
		}
		$creator_id = absint( $_GET['snscs_bio_qr'] );
		$fmt        = isset( $_GET['fmt'] ) && 'png' === $_GET['fmt'] ? 'png' : 'svg';
		$download   = ! empty( $_GET['dl'] );
		// phpcs:enable
		if ( ! $creator_id || 'publish' !== get_post_status( $creator_id ) || SNSCS_Post_Types::POST_TYPE !== get_post_type( $creator_id ) ) {
			status_header( 404 );
			exit;
		}
		$body = 'png' === $fmt ? SNSCS_QR::png( self::qr_target( $creator_id ), 16 ) : SNSCS_QR::svg( self::qr_target( $creator_id ), 480 );
		if ( ! $body ) {
			status_header( 404 );
			exit;
		}
		header( 'Content-Type: ' . ( 'png' === $fmt ? 'image/png' : 'image/svg+xml' ) );
		header( 'Cache-Control: public, max-age=86400' );
		header( 'X-Robots-Tag: noindex' );
		if ( $download ) {
			header( 'Content-Disposition: attachment; filename="mylink-' . self::name_for( $creator_id ) . '.' . $fmt . '"' );
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 自分で作った画像。
		exit;
	}

	/**
	 * 今週（直近7日）よく開かれたFolloLink。
	 *
	 * @return array<int,int> 発信者ID => 開いた人数
	 */
	public static function top_week( $limit = 10 ) {
		$cached = get_transient( 'snscs_biolink_top_week' );
		if ( ! is_array( $cached ) ) {
			global $wpdb;
			$table = self::table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT c.creator_id, SUM(c.clicks) AS n FROM {$table} c INNER JOIN {$wpdb->posts} p ON p.ID = c.creator_id AND p.post_status = 'publish' WHERE c.link_key = 'view:page' AND c.day >= %s GROUP BY c.creator_id ORDER BY n DESC LIMIT 30", gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -6 days' ) ) ), ARRAY_A );
			$penalties = SNSCS_Integrity::penalties();
			$cached    = array();
			foreach ( $rows as $row ) {
				if ( ! isset( $penalties[ (int) $row['creator_id'] ] ) ) {
					$cached[ (int) $row['creator_id'] ] = (int) $row['n'];
				}
			}
			set_transient( 'snscs_biolink_top_week', $cached, HOUR_IN_SECONDS );
		}
		return array_slice( $cached, 0, $limit, true );
	}

	/**
	 * 週に1回（月曜の朝）: 先週の成績のお知らせと、まだFolloLinkを使っていない人への案内。
	 */
	public static function weekly_notices() {
		$week = current_time( 'o-W' );
		if ( (int) current_time( 'G' ) < 9 || get_option( 'snscs_biolink_week' ) === $week ) {
			return;
		}
		update_option( 'snscs_biolink_week', $week, false );
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -7 days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT creator_id FROM {$table} WHERE link_key = 'view:page' AND day >= %s AND day < %s", $since, current_time( 'Y-m-d' ) ) ) );
		foreach ( $active as $creator_id ) {
			$user = (int) get_post_field( 'post_author', $creator_id );
			if ( $user && 'publish' === get_post_status( $creator_id ) ) {
				SNSCS_Inbox::add( $user, 'biolink_week', $creator_id );
			}
		}
		// 掲載から3日以上たっても、2週間だれにも開かれていない人へ（1人に月1回まで）。
		$quiet = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -14 days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$table} c ON c.creator_id = p.ID AND c.link_key = 'view:page' AND c.day >= %s LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_snscs_bio_nudged' WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_author > 0 AND p.post_date < %s AND c.creator_id IS NULL AND (m.meta_value IS NULL OR m.meta_value < %s) LIMIT 500", $quiet, SNSCS_Post_Types::POST_TYPE, gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' -3 days' ) ), gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -30 days' ) ) ) );
		foreach ( array_map( 'intval', $ids ) as $creator_id ) {
			SNSCS_Inbox::add( (int) get_post_field( 'post_author', $creator_id ), 'biolink_nudge', $creator_id );
			update_post_meta( $creator_id, '_snscs_bio_nudged', current_time( 'Y-m-d' ) );
		}
	}

	/**
	 * お知らせの文（SNSCS_Inbox::describe から）。
	 */
	public static function describe( array $row ) {
		$creator_id = (int) $row['object_id'];
		if ( 'publish' !== get_post_status( $creator_id ) ) {
			return null;
		}
		if ( 'biolink_nudge' === $row['type'] ) {
			return array( 'text' => 'FolloLinkをSNSのプロフィールに貼ってみませんか？開かれるほど、このサイトのおすすめ順でも上に出やすくなります。', 'url' => snscs_portal_url() . '#snscs-biolink' );
		}
		$s = self::stats( $creator_id, 7 );
		if ( ! $s['views'] ) {
			return null;
		}
		$top = $s['sources'] ? self::SOURCES[ array_search( max( $s['sources'] ), $s['sources'], true ) ] ?? '' : '';
		return array(
			'text' => sprintf( 'この1週間で、FolloLinkが%d人に開かれ、リンクが%d回押されました。%s', $s['views'], $s['clicks'], '' !== $top ? 'いちばん多く来たのは「' . $top . '」からです。' : '' ),
			'url'  => snscs_portal_url() . '#snscs-biolink',
		);
	}

	/**
	 * サイトマップ（自分でIDを決めた人のFolloLink。使っている人のページだけ）。
	 */
	public static function sitemap_urls( $offset, $limit ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = %s AND p.post_status = 'publish' ORDER BY p.ID LIMIT %d, %d", self::META_NAME, SNSCS_Post_Types::POST_TYPE, $offset, $limit ) );
		return array_map( array( __CLASS__, 'url' ), array_map( 'intval', $ids ) );
	}

	public static function sitemap_count() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = %s AND p.post_status = 'publish'", self::META_NAME, SNSCS_Post_Types::POST_TYPE ) );
	}

	public static function purge_old() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . self::KEEP_DAYS . ' days' ) ) ) );
	}

	public static function delete_for_creator( $post_id ) {
		global $wpdb;
		if ( SNSCS_Post_Types::POST_TYPE === get_post_type( $post_id ) ) {
			$wpdb->delete( self::table(), array( 'creator_id' => $post_id ), array( '%d' ) );
		}
	}

	/* --------------------------------------------------------------------
	 * ページ
	 * ------------------------------------------------------------------ */

	/**
	 * ページに並べるボタン。
	 *
	 * @return array<int,array{key:string,label:string,sub:string,url:string,kind:string}>
	 */
	public static function buttons( $creator_id, $english = false ) {
		$buttons = array();
		foreach ( SNSCS_Search::format_accounts( SNSCS_Accounts::get_for_creator( $creator_id ) ) as $account ) {
			if ( $account['url'] ) {
				$count     = $english ? SNSCS_English::count( $account['followers'] ) . ' followers' : $account['followers_label'];
				$buttons[] = array(
					'key'   => 'sns:' . $account['platform'],
					'label' => $account['platform_label'],
					'sub'   => snscs_display_handle( $account['platform'], $account['handle'] ) . ( $account['followers'] ? ( $english ? ' · ' : '・' ) . $count : '' ),
					'url'   => $account['url'],
					'kind'  => 'sns sns--' . $account['platform'],
				);
			}
		}
		$stores = snscs_profile_stores();
		foreach ( SNSCS_Submissions::stores_for( $creator_id ) as $key => $handle ) {
			$buttons[] = array(
				'key'   => 'store:' . $key,
				'label' => $stores[ $key ]['label'],
				'sub'   => $handle,
				'url'   => sprintf( $stores[ $key ]['url'], rawurlencode( $handle ) ),
				'kind'  => 'store store--' . $key,
			);
		}
		foreach ( SNSCS_Submissions::links_for( $creator_id ) as $i => $link ) {
			$buttons[] = array(
				'key'   => 'link:' . $i,
				'label' => $link['label'],
				'sub'   => (string) wp_parse_url( $link['url'], PHP_URL_HOST ),
				'url'   => $link['url'],
				'kind'  => 'link',
			);
		}
		return $buttons;
	}

	/**
	 * クリックの記録のキーを、人が読める名前に。
	 */
	public static function key_label( $creator_id, $key ) {
		foreach ( self::buttons( $creator_id ) as $button ) {
			if ( $button['key'] === $key ) {
				return $button['label'];
			}
		}
		$fixed = array( 'page:profile' => '詳しいプロフィール', 'inquiry:form' => 'お仕事の相談', 'similar:creator' => '似ている発信者', 'follow:site' => 'このサイトでフォロー' );
		if ( isset( $fixed[ $key ] ) ) {
			return $fixed[ $key ];
		}
		$extra = SNSCS_Biolink_Extra::key_label( $key );
		if ( '' !== $extra ) {
			return $extra;
		}
		return 0 === strpos( $key, 'article:' ) ? '記事' : $key;
	}

	/**
	 * /@ID を開いたとき: 数えて、FolloLinkのページを出す。
	 */
	public static function render() {
		$name = get_query_var( self::QUERY_VAR );
		if ( '' === $name || ! is_string( $name ) ) {
			return;
		}
		$creator_id = self::creator_for( $name );
		if ( ! $creator_id ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		// 紹介コードのURLで来て、IDが決まっていれば、IDのURLにそろえる。
		$own = (string) get_post_meta( $creator_id, self::META_NAME, true );
		if ( '' !== $own && strtolower( $name ) !== $own && get_option( 'permalink_structure' ) ) {
			wp_safe_redirect( self::url( $creator_id ), 301 );
			exit;
		}

		// 紹介ページ（/mylink/）の見本の表示は数えない。
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$preview  = ! empty( $_GET['snscs_preview'] );
		$own_view = is_user_logged_in() && (int) get_post_field( 'post_author', $creator_id ) === get_current_user_id();
		if ( ! $preview ) {
			// SNSのプロフィールから来た人 = 発信者が連れてきた人なので、紹介リンク流入として数える。
			SNSCS_Referrals::count_visit( $creator_id, SNSCS_Scores::ref_code( $creator_id ) );
			if ( ! $own_view ) {
				self::record_visit( $creator_id );
			}
		}
		$tracking = ( $preview || $own_view || current_user_can( 'edit_others_posts' ) ) ? null : SNSCS_Tracking::config( $creator_id, 'profile' );

		nocache_headers();
		status_header( 200 );
		if ( $preview ) {
			header( 'X-Robots-Tag: noindex' );
		}
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Vary: Accept-Language' );
		echo self::page_html( $creator_id, $tracking, '', SNSCS_English::wants_english(), $preview ? self::preview_style( $creator_id ) : null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- page_html() の中でエスケープ済み。
		exit;
	}

	/**
	 * 見本・マイページのプレビュー用に、URLで渡したデザインを重ねる（保存はしない）。
	 */
	public static function preview_style( $creator_id ) {
		$style = SNSCS_Biolink_Design::style_for( $creator_id );
		$map   = array( 'th' => 'theme', 'sh' => 'shape', 'bs' => 'button', 'fo' => 'font', 'ri' => 'ring', 'ly' => 'layout', 'cb' => 'bg', 'cc' => 'btn' );
		foreach ( $map as $param => $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET[ $param ] ) && '' !== $_GET[ $param ] ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$style[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
			}
		}
		return SNSCS_Biolink_Design::normalize( $style );
	}

	/**
	 * FolloLinkのページ全体のHTML。
	 *
	 * @param int        $creator_id 発信者。
	 * @param array|null $tracking   計測の設定（本人・運営が見るときは null）。
	 * @param string     $theme      デザイン（プレビュー用。省略時は保存したもの）。
	 * @param bool       $english    英語で表示するか（日本語以外の閲覧者）。
	 */
	public static function page_html( $creator_id, $tracking = null, $theme = '', $english = false, $style = null ) {
		$post     = get_post( $creator_id );
		$style    = is_array( $style ) ? $style : SNSCS_Biolink_Design::style_for( $creator_id );
		if ( '' !== $theme && array_key_exists( $theme, self::themes() ) ) {
			$style['theme'] = $theme;
		}
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$name     = get_the_title( $creator_id );
		$photo    = get_the_post_thumbnail_url( $creator_id, 'medium' );
		$bio      = wp_trim_words( SNSCS_Search::excerpt( $post ), 80, '…' );
		$terms    = wp_get_object_terms( $creator_id, SNSCS_Post_Types::TAX_GENRE );
		$terms    = is_wp_error( $terms ) ? array() : $terms;
		$genres   = wp_list_pluck( $terms, 'name' );
		$articles = SNSCS_Articles::for_creator( $creator_id, 'publish', 3 );
		$inquiry  = SNSCS_Inquiries::settings_for( $creator_id )['on'];
		$profile  = get_permalink( $creator_id );
		$join     = SNSCS_Invites::join_url( $creator_id );
		$search   = snscs_search_url();
		$desc     = sprintf( '%sのSNS・リンクまとめ', $name );
		$lang     = 'ja';
		$en_text  = $english ? SNSCS_English::creator_text( $creator_id ) : null;
		// 英語の表示: 英訳があれば紹介文も英語。無ければ日本語のまま（ボタンなどの言葉だけ英語）。
		$l = function ( $ja, $en ) use ( $english ) {
			return $english ? $en : $ja;
		};
		if ( $english ) {
			$lang    = 'en';
			$genres  = array_map( array( 'SNSCS_English', 'genre_name' ), $terms );
			$desc    = sprintf( '%s — social media links', $en_text ? $en_text['name'] : $name );
			$profile = SNSCS_English::creator_url( $creator_id ) ? SNSCS_English::creator_url( $creator_id ) : $profile;
			$search  = SNSCS_English::home_url() ? SNSCS_English::home_url() : $search;
			if ( $en_text ) {
				$bio = wp_trim_words( $en_text['bio'], 60, '…' );
			}
		}

		// バッジ: 最近の受賞・ジャンルでの順位、公式の数で確かめたSNS。
		$badges = array();
		$rank   = SNSCS_Share::badge_rank_label( $creator_id );
		if ( '' !== $rank && ! $english ) {
			$badges[] = array( '🏆', $rank );
		}
		if ( SNSCS_Portal_Top::is_founder( $creator_id ) ) {
			$founder_no = SNSCS_Founder::number( $creator_id );
			$badges[]   = array( 'founder', $l( '創設メンバー Nº ', 'Founding member Nº ' ) . SNSCS_Founder::serial( $founder_no ), SNSCS_Founder::mini_url( $founder_no ) );
		}
		// 期間の外のボタンを外し、有料リンクを「販売中」に分ける（SNSCS_Biolink_Extra）。
		$arranged   = SNSCS_Biolink_Extra::arrange( $creator_id, self::buttons( $creator_id, $english ), $english );
		$buttons    = $arranged['buttons'];
		$paid       = $arranged['paid'];
		$extra_btns = SNSCS_Biolink_Extra::extra_buttons( $creator_id, $english );
		$feed       = SNSCS_Biolink_Extra::feed_items( $creator_id );
		$mail_on    = SNSCS_Biolink_Extra::mail_on( $creator_id );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sub_state  = isset( $_GET['snscs_sub'] ) ? sanitize_key( wp_unslash( $_GET['snscs_sub'] ) ) : '';
		foreach ( SNSCS_Search::format_accounts( SNSCS_Accounts::get_for_creator( $creator_id ) ) as $account ) {
			if ( $account['verified'] ) {
				$badges[] = array( '✓', $l( 'フォロワー数を公式に確認済み', 'Verified follower count' ) );
				break;
			}
		}

		// 目立たせるリンクを、ほかのボタンから外して上に出す。
		$pin     = self::pin_for( $creator_id );
		$pinned  = null;
		if ( $pin ) {
			foreach ( $buttons as $i => $button ) {
				if ( $button['key'] === $pin['key'] ) {
					$pinned = $button;
					unset( $buttons[ $i ] );
					break;
				}
			}
		}
		$pin_tag = $pinned && '' !== $pin['tag'] ? self::PIN_TAGS[ $pin['tag'] ][ $english ? 1 : 0 ] : '';

		// 並べ方「SNSはアイコンで上に並べる」: SNSのボタンを、名前の下の丸いアイコンにする。
		$icons = array();
		if ( 'icons' === $style['layout'] ) {
			foreach ( $buttons as $i => $button ) {
				if ( 0 === strpos( $button['key'], 'sns:' ) ) {
					$icons[] = $button;
					unset( $buttons[ $i ] );
				}
			}
		}
		$btn_inner = function ( $label, $sub, $key, $ends = '' ) {
			return '<span class="bio__btn__ico">' . SNSCS_Biolink_Design::icon( $key ) . '</span><span class="bio__btn__txt">' . esc_html( $label ) . ( '' !== $sub ? '<small>' . esc_html( $sub ) . '</small>' : '' ) . '</span>' . ( '' !== $ends ? '<span class="bio__ends">' . esc_html( $ends ) . '</span>' : '' );
		};

		$similar = array();
		if ( self::shows_similar( $creator_id ) ) {
			foreach ( array_keys( SNSCS_Similar::find( $creator_id, 3 ) ) as $id ) {
				$en_other  = $english ? SNSCS_English::creator_text( $id ) : null;
				$similar[] = array(
					'name'  => $en_other ? $en_other['name'] : get_the_title( $id ),
					'url'   => $english && SNSCS_English::creator_url( $id ) ? SNSCS_English::creator_url( $id ) : get_permalink( $id ),
					'photo' => (string) get_the_post_thumbnail_url( $id, 'thumbnail' ),
				);
			}
		}
		$display_name = $en_text ? $en_text['name'] : $name;

		// このサイトでフォロー（読者の登録の入口）。
		$user       = get_current_user_id();
		$is_owner   = $user && (int) get_post_field( 'post_author', $creator_id ) === $user;
		$following  = $user && ! empty( SNSCS_Social::state( $user, array( $creator_id ) )['follows'][ $creator_id ] );
		$can_follow = ! $is_owner && SNSCS_Hub::page_id( 'member' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$ask_follow = $can_follow && $user && ! $following && ! empty( $_GET['snscs_follow'] );
		$followed   = ! empty( $_GET['snscs_followed'] );
		// phpcs:enable
		$follow_url = $user ? '' : SNSCS_Social::member_url( array( 'redirect_to' => rawurlencode( add_query_arg( 'snscs_follow', 1, self::url( $creator_id ) ) ) ) );
		$follow_form = function ( $label, $class ) use ( $creator_id ) {
			return '<form method="post" action="' . esc_url( snscs_post_url() ) . '" class="bio__followform"><input type="hidden" name="action" value="snscs_bio_follow"><input type="hidden" name="creator" value="' . (int) $creator_id . '">' . wp_nonce_field( 'snscs_bio_follow_' . $creator_id, 'snscs_nonce', true, false ) . '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
		};

		// 構造化データ（この人のプロフィールのページ）。
		$same = array_values( array_unique( array_filter( wp_list_pluck( $buttons, 'url' ) ) ) );
		if ( $pinned ) {
			$same[] = $pinned['url'];
		}
		$ld = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'ProfilePage',
			'url'        => self::url( $creator_id ),
			'mainEntity' => array_filter(
				array(
					'@type'       => 'Person',
					'name'        => $display_name,
					'description' => $bio,
					'image'       => $photo ? $photo : null,
					'url'         => get_permalink( $creator_id ),
					'sameAs'      => array_values( array_unique( $same ) ),
				)
			),
		);

		ob_start();
		?>
<!doctype html>
<html lang="<?php echo esc_attr( $lang ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $display_name . ' | ' . $site ); ?></title>
<meta name="description" content="<?php echo esc_attr( $desc . ( $english ? '. ' : '。' ) . $bio ); ?>">
<link rel="canonical" href="<?php echo esc_url( self::url( $creator_id ) ); ?>">
<meta property="og:type" content="profile">
<meta property="og:title" content="<?php echo esc_attr( $display_name . ' | ' . $site ); ?>">
<meta property="og:description" content="<?php echo esc_attr( $desc ); ?>">
<meta property="og:url" content="<?php echo esc_url( self::url( $creator_id ) ); ?>">
<meta property="og:image" content="<?php echo esc_url( SNSCS_Share::share_url( $creator_id ) ); ?>">
<meta name="twitter:card" content="summary_large_image">
<?php echo SNSCS_Brand::on() ? SNSCS_Brand::icons_html() : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>
<script type="application/ld+json"><?php echo wp_json_encode( $ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ); ?></script>
<?php echo SNSCS_Biolink_Design::font_link( $style ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>
<style><?php echo SNSCS_Biolink_Design::css( $style ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 決まった選択肢と、確かめた色だけで作ったCSS。 ?></style>
</head>
<body>
<main class="bio">
	<?php if ( $photo ) : ?>
		<img class="bio__photo" src="<?php echo esc_url( $photo ); ?>" alt="" width="104" height="104">
	<?php else : ?>
		<span class="bio__initial" aria-hidden="true"><?php echo esc_html( mb_substr( $display_name, 0, 1 ) ); ?></span>
	<?php endif; ?>
	<h1 class="bio__name"><?php echo esc_html( $display_name ); ?></h1>
	<?php if ( $genres ) : ?>
		<p class="bio__genres"><?php echo esc_html( implode( $english ? ' · ' : '・', $genres ) ); ?></p>
	<?php endif; ?>
	<?php if ( $badges ) : ?>
		<ul class="bio__badges">
			<?php foreach ( $badges as $badge ) : ?>
				<li<?php echo 'founder' === $badge[0] ? ' class="bio__badge--founder"' : ''; ?>><?php if ( 'founder' === $badge[0] ) : ?><img src="<?php echo esc_url( $badge[2] ); ?>" alt="" width="18" height="18"><?php else : ?><span aria-hidden="true"><?php echo esc_html( $badge[0] ); ?></span><?php endif; ?> <?php echo esc_html( $badge[1] ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php if ( '' !== $bio ) : ?>
		<p class="bio__text"><?php echo esc_html( $bio ); ?></p>
	<?php endif; ?>

	<?php if ( $icons ) : ?>
		<ul class="bio__icons">
			<?php foreach ( $icons as $button ) : ?>
				<li><a class="bio__icon" href="<?php echo esc_url( $button['url'] ); ?>" target="_blank" rel="noopener nofollow ugc" data-snscs-out="<?php echo esc_attr( $button['key'] ); ?>" aria-label="<?php echo esc_attr( $button['label'] . ' ' . $button['sub'] ); ?>" title="<?php echo esc_attr( $button['label'] . ' ' . $button['sub'] ); ?>"><?php echo SNSCS_Biolink_Design::icon( $button['key'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 決まったSVG。 ?></a></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php if ( $followed ) : ?>
		<p class="bio__notice" role="status"><?php echo esc_html( $l( 'フォローしました。新しい記事などを、メンバーページでお知らせします。', 'Followed. You will get updates on your member page.' ) ); ?></p>
	<?php endif; ?>
	<div class="bio__actions">
		<?php if ( $can_follow ) : ?>
			<?php if ( $following ) : ?>
				<span class="bio__pill bio__pill--on">✓ <?php echo esc_html( $l( 'フォロー中', 'Following' ) ); ?></span>
			<?php elseif ( $user ) : ?>
				<?php echo $follow_form( $l( '＋ フォロー', '+ Follow' ), 'bio__pill' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 中でエスケープ済み。 ?>
			<?php else : ?>
				<a class="bio__pill" href="<?php echo esc_url( $follow_url ); ?>" rel="nofollow"><?php echo esc_html( $l( '＋ フォロー', '+ Follow' ) ); ?></a>
			<?php endif; ?>
		<?php endif; ?>
		<button type="button" class="bio__pill" id="bio-share" data-title="<?php echo esc_attr( $display_name ); ?>" data-url="<?php echo esc_url( self::url( $creator_id ) ); ?>" data-done="<?php echo esc_attr( $l( 'リンクをコピーしました', 'Link copied' ) ); ?>"><?php echo esc_html( $l( 'シェア', 'Share' ) ); ?></button>
	</div>

	<ul class="bio__list">
		<?php if ( $pinned ) : ?>
			<li><a class="bio__btn bio__btn--pinned bio__btn--<?php echo esc_attr( $pinned['kind'] ); ?>" href="<?php echo esc_url( $pinned['url'] ); ?>" target="_blank" rel="noopener nofollow ugc" data-snscs-out="<?php echo esc_attr( $pinned['key'] ); ?>"><?php if ( '' !== $pin_tag ) : ?><span class="bio__tag"><?php echo esc_html( $pin_tag ); ?></span><?php endif; ?><?php echo $btn_inner( $pinned['label'], $pinned['sub'], $pinned['key'], $pinned['ends'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></li>
		<?php endif; ?>
		<?php foreach ( array_merge( $extra_btns, $buttons ) as $button ) : ?>
			<li><a class="bio__btn bio__btn--<?php echo esc_attr( $button['kind'] ); ?>" href="<?php echo esc_url( $button['url'] ); ?>" target="_blank" rel="noopener nofollow ugc" data-snscs-out="<?php echo esc_attr( $button['key'] ); ?>"><?php echo $btn_inner( $button['label'], $button['sub'], $button['key'], $button['ends'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></li>
		<?php endforeach; ?>
		<?php if ( $inquiry ) : ?>
			<li><a class="bio__btn" href="<?php echo esc_url( get_permalink( $creator_id ) . '#snscs-inquiry' ); ?>" data-snscs-out="inquiry:form"><?php echo $btn_inner( $l( 'お仕事の相談', 'Business inquiries' ), $l( '企業・団体の方はこちら', 'For brands and organizations (form in Japanese)' ), 'inquiry:form' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></li>
		<?php endif; ?>
		<li><a class="bio__btn" href="<?php echo esc_url( $profile ); ?>" data-snscs-out="page:profile"><?php echo $btn_inner( $l( '詳しいプロフィール', 'Full profile' ), $site, 'page:profile' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></li>
	</ul>

	<?php if ( $paid ) : ?>
		<h2 class="bio__heading"><?php echo esc_html( $l( '販売中', 'For sale' ) ); ?></h2>
		<ul class="bio__paid">
			<?php foreach ( $paid as $item ) : ?>
				<li><a class="bio__card" href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener nofollow ugc" data-snscs-out="<?php echo esc_attr( $item['key'] ); ?>">
					<span class="bio__card__ico"><?php echo SNSCS_Biolink_Design::icon( $item['key'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 決まったSVG。 ?></span>
					<span class="bio__card__body"><span class="bio__card__kind"><?php echo esc_html( $item['kind'] ); ?></span><strong><?php echo esc_html( $item['label'] ); ?></strong><small><?php echo esc_html( $item['sub'] ); ?></small></span>
					<span class="bio__card__price"><?php echo esc_html( SNSCS_Biolink_Extra::price( $item['price'], $english ) ); ?><?php if ( '' !== $item['ends'] ) : ?><span class="bio__ends"><?php echo esc_html( $item['ends'] ); ?></span><?php endif; ?></span>
				</a></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $feed ) : ?>
		<h2 class="bio__heading"><?php echo esc_html( $l( '最新の投稿', 'Latest posts' ) ); ?></h2>
		<ul class="bio__feed">
			<?php foreach ( $feed as $i => $item ) : ?>
				<?php $src_label = array( 'yt' => 'YouTube', 'note' => 'note', 'blog' => $l( 'ブログ', 'Blog' ) )[ $item['src'] ] ?? ''; ?>
				<li class="<?php echo 0 === $i ? 'bio__feed--top' : ''; ?>"><a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener nofollow ugc" data-snscs-out="<?php echo esc_attr( 'feed:' . $item['src'] ); ?>">
					<?php if ( '' !== $item['thumb'] ) : ?>
						<img src="<?php echo esc_url( $item['thumb'] ); ?>" alt="" loading="lazy" width="320" height="180"<?php echo 'yt' === $item['src'] ? ' class="is-video"' : ''; ?>>
					<?php else : ?>
						<span class="bio__feed__noimg" aria-hidden="true"><?php echo SNSCS_Biolink_Design::icon( 'feed:' . $item['src'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 決まったSVG。 ?></span>
					<?php endif; ?>
					<span class="bio__feed__txt"><span class="bio__feed__src"><?php echo esc_html( $src_label ); ?><?php echo $item['date'] ? esc_html( ' · ' . wp_date( $english ? 'M j' : 'n月j日', (int) $item['date'] ) ) : ''; ?></span><strong><?php echo esc_html( $item['title'] ); ?></strong></span>
				</a></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $mail_on ) : ?>
		<section class="bio__mail" id="bio-mail" aria-labelledby="bio-mail-title">
			<h2 id="bio-mail-title"><?php echo esc_html( $l( 'メールで最新情報を受け取る', 'Get updates by email' ) ); ?></h2>
			<?php
			$sub_msgs = array(
				'check'   => $l( '確認のメールを送りました。メールのURLを開くと登録が完了します。', 'Check your inbox and open the link to confirm.' ),
				'ok'      => $l( '登録しました。ありがとうございます！', 'You are subscribed. Thank you!' ),
				'bye'     => $l( '登録をやめました。', 'You have unsubscribed.' ),
				'invalid' => $l( 'メールアドレスと同意のチェックを確かめてください。', 'Please check your email address and the consent box.' ),
				'expired' => $l( 'もう一度お試しください。', 'Please try again.' ),
				'limit'   => $l( '短い時間に何度も送られました。時間をおいてお試しください。', 'Too many attempts. Please try again later.' ),
			);
			?>
			<?php if ( isset( $sub_msgs[ $sub_state ] ) ) : ?>
				<p class="bio__notice" role="status"><?php echo esc_html( $sub_msgs[ $sub_state ] ); ?></p>
			<?php endif; ?>
			<?php if ( 'ok' !== $sub_state && 'check' !== $sub_state ) : ?>
				<p><?php echo esc_html( sprintf( $l( '新しい投稿やお知らせを、%sさんからメールで受け取れます。', 'Get new posts and news from %s by email.' ), $display_name ) ); ?></p>
				<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>" class="bio__mailform">
					<input type="hidden" name="action" value="snscs_bio_sub">
					<input type="hidden" name="creator" value="<?php echo (int) $creator_id; ?>">
					<input type="hidden" name="snscs_form" value="<?php echo esc_attr( snscs_form_token() ); ?>">
					<p class="bio__hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
					<div class="bio__mailrow"><label for="bio-mail-email" class="bio__sr"><?php echo esc_html( $l( 'メールアドレス', 'Email' ) ); ?></label><input id="bio-mail-email" type="email" name="email" required autocomplete="email" placeholder="you@example.com"><button type="submit"><?php echo esc_html( $l( '登録する', 'Subscribe' ) ); ?></button></div>
					<label class="bio__agree"><input type="checkbox" name="agree" value="1" required> <span><?php echo esc_html( sprintf( $l( 'メールアドレスが%sさんに知らされ、お知らせのメールが届くことに同意します。', 'I agree that my email will be shared with %s to receive updates.' ), $display_name ) ); ?> <?php echo wp_kses_post( SNSCS_Legal::link_or_text( 'privacy' ) ); ?></span></label>
				</form>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php if ( $articles && ! $english ) : ?>
		<h2 class="bio__heading">最新の記事</h2>
		<ul class="bio__list">
			<?php foreach ( $articles as $article ) : ?>
				<li><a class="bio__btn" href="<?php echo esc_url( get_permalink( $article ) ); ?>" data-snscs-out="article:<?php echo (int) $article->ID; ?>"><?php echo $btn_inner( get_the_title( $article ), '', 'article:' . $article->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $similar ) : ?>
		<h2 class="bio__heading"><?php echo esc_html( $l( 'こちらの発信者もおすすめ', 'You may also like' ) ); ?></h2>
		<ul class="bio__similar">
			<?php foreach ( $similar as $other ) : ?>
				<li><a href="<?php echo esc_url( $other['url'] ); ?>" data-snscs-out="similar:creator"><?php if ( '' !== $other['photo'] ) : ?><img src="<?php echo esc_url( $other['photo'] ); ?>" alt="" width="56" height="56" loading="lazy"><?php else : ?><span aria-hidden="true"></span><?php endif; ?><?php echo esc_html( $other['name'] ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<footer class="bio__foot">
		<?php if ( SNSCS_Brand::on() ) : ?>
			<p class="bio__brand"><a href="<?php echo esc_url( $search ); ?>"><img src="<?php echo esc_url( SNSCS_Brand::url( 'follofind-icon.svg' ) ); ?>" alt="" width="22" height="22"><?php echo esc_html( $l( SNSCS_Brand::NAME . 'でほかの発信者を探す', 'Discover more creators on ' . SNSCS_Brand::NAME ) ); ?></a></p>
		<?php else : ?>
			<p><a href="<?php echo esc_url( $search ); ?>"><?php echo esc_html( $l( $site . 'でほかの発信者を探す', 'Discover Japanese creators on ' . $site ) ); ?></a></p>
		<?php endif; ?>
		<?php if ( ! $english ) : ?>
			<a class="bio__make" href="<?php echo esc_url( $join ); ?>">あなたもFolloLinkを作る（無料）</a>
		<?php endif; ?>
	</footer>
</main>
<?php if ( $ask_follow ) : ?>
	<div class="bio__ask" role="dialog" aria-label="<?php echo esc_attr( $l( 'フォローの確認', 'Confirm follow' ) ); ?>">
		<p><?php echo esc_html( sprintf( $l( '%sさんをフォローしますか？', 'Follow %s?' ), $display_name ) ); ?></p>
		<?php echo $follow_form( $l( 'フォローする', 'Follow' ), '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 中でエスケープ済み。 ?>
	</div>
<?php endif; ?>
<script>
( function () {
	var b = document.getElementById( 'bio-share' );
	if ( ! b ) { return; }
	b.addEventListener( 'click', function () {
		var url = b.getAttribute( 'data-url' );
		if ( navigator.share ) {
			navigator.share( { title: b.getAttribute( 'data-title' ), url: url } ).catch( function () {} );
		} else if ( navigator.clipboard ) {
			navigator.clipboard.writeText( url ).then( function () { b.textContent = b.getAttribute( 'data-done' ); } );
		} else {
			window.prompt( '', url );
		}
	} );
} )();
</script>
<?php if ( $tracking ) : ?>
<script>window.snscsPageview = <?php echo wp_json_encode( $tracking ); ?>;</script>
<script src="<?php echo esc_url( SNSCS_URL . 'assets/js/snscs-pageview.js?ver=' . SNSCS_VERSION ); ?>" defer></script>
<?php endif; ?>
</body>
</html>
		<?php
		return ob_get_clean();
	}

	/* --------------------------------------------------------------------
	 * 紹介ページ [sns_mylink]（「リンクまとめ 無料」で探す人の入口）
	 * ------------------------------------------------------------------ */

	/**
	 * 見本にする発信者（総合点の高い順で、ボタンが3つ以上ある人。1時間ごとに選び直す）。
	 *
	 * @return int[]
	 */
	public static function examples( $limit = 6 ) {
		$cached = get_transient( 'snscs_biolink_examples' );
		if ( ! is_array( $cached ) ) {
			$cached    = array();
			$penalties = SNSCS_Integrity::penalties();
			foreach ( SNSCS_Scores::top( 40 ) as $row ) {
				if ( ! isset( $penalties[ (int) $row['creator_id'] ] ) && count( self::buttons( (int) $row['creator_id'] ) ) >= 3 ) {
					$cached[] = (int) $row['creator_id'];
				}
				if ( count( $cached ) >= 12 ) {
					break;
				}
			}
			set_transient( 'snscs_biolink_examples', $cached, HOUR_IN_SECONDS );
		}
		return array_slice( $cached, 0, $limit );
	}

	public static function faq() {
		return array(
			array( '本当に無料ですか？', 'はい。FolloLinkの作成・デザインの変更・アクセスの分析まで、すべて無料です。' ),
			array( 'lit.link や Linktree と何が違いますか？', 'FolloLinkは、発信者を探せるサイトの中にあります。開かれた数がサイトのおすすめ順に反映されるので、FolloLinkを貼るほど、このサイトでもあなたが見つかりやすくなります。' ),
			array( 'どんなリンクを載せられますか？', 'X・Instagram・TikTok・YouTube・Threads などのSNS、note・Brain、ブログやお店のページなどです。いちばん見てほしいリンクを上に大きく出すこともできます。' ),
			array( 'どのSNSから見られたか分かりますか？', 'はい。SNSごとのURLを貼ると、どのSNSから何人が来たか、どのリンクが何回押されたかを、マイページで確かめられます。毎週の成績もお知らせします。' ),
			array( '名刺やチラシにも使えますか？', 'はい。マイページからQRコードの画像（PNG・印刷用のSVG）を保存できます。' ),
			array( '投げ銭や有料のコンテンツも載せられますか？', 'はい。OFUSE・Ko-fi・PayPal.me・pixivFANBOX などの応援ボタンと、有料記事や講座を値段付きで並べる「販売中」の欄を使えます（お支払いは各サービスで行います）。' ),
			array( '海外の人にも見てもらえますか？', '日本語以外の環境の人には、ボタンなどが自動で英語の表示になります。紹介文も英訳されたものが出ます。' ),
		);
	}

	public static function shortcode_landing() {
		SNSCS_Hub::remember_page( 'mylink' );
		wp_enqueue_style( 'snscs' );
		$creator  = is_user_logged_in() ? SNSCS_Submissions::creator_id_for_user( get_current_user_id() ) : 0;
		$cta_url  = $creator ? snscs_portal_url() . '#snscs-biolink' : snscs_portal_url();
		$cta      = $creator ? 'マイページでFolloLinkを設定する' : '無料でFolloLinkを作る';
		$examples = self::examples();
		$week     = self::top_week( 10 );
		$preview  = $examples ? add_query_arg( 'snscs_preview', 1, self::url( $examples[0] ) ) : '';
		$features = array(
			array( '🔗', 'SNSをまとめて1ページに', 'X・Instagram・TikTok・YouTube・note などを、大きなボタンで並べます。' ),
			array( '⭐', 'いちばん見てほしいリンクを目立たせる', '新作や販売中の商品を、「新着」「セール中」の札付きで一番上に出せます。' ),
			array( '📊', 'どのSNSから来たか分かる', '開かれた数・押されたリンク・来たSNSを、マイページで確かめられます。' ),
			array( '🎨', '36種類のデザインとテンプレート', '「シンプル」「かわいい」「クール」「ナチュラル」「プロ」から選ぶだけ。好きな色も使えます。プレビューで確かめながら作れます。' ),
			array( '💝', '応援ボタンと販売中のコンテンツ', 'OFUSE・Ko-fi などの応援ボタンや、有料記事・講座・商品を値段付きのカードで出せます。' ),
			array( '📨', 'ファンとつながる', 'LINEの友だち追加と、メールで最新情報を受け取る登録の欄を置けます。登録した人の一覧は保存できます。' ),
			array( '🆕', '最新の投稿を自動で表示', 'YouTube・note・ブログの新しい投稿を自動で並べます。更新しなくても、いつも新しいページに。' ),
			array( '⏰', '期間限定のリンク', 'キャンペーンやセールのリンクを、決めた期間だけ出せます。終わりが近いと「あと3日」と出ます。' ),
			array( '🖼️', 'ストーリーズ用の画像も自動で', 'あなたの色・写真・QRコード入りの画像（ストーリーズ用・シェア用・印刷用）を、ボタン1つで保存できます。' ),
			array( '🏆', '受賞や順位のバッジ', 'アワードの受賞やジャンルでの順位が、自動でバッジになります。' ),
			array( '🌏', '海外の人には英語で表示', '日本語以外の環境の人には、自動で英語の表示になります。' ),
			array( '📱', 'QRコードで名刺やチラシにも', 'QRコードを保存して、名刺・チラシ・イベントのポップに。来た人数も数えます。' ),
			array( '🔔', 'ファンがフォローできる', '読者はFolloLinkからこのサイトでフォローでき、あなたの新しい記事のお知らせを受け取れます。' ),
		);
		$ld = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => array_map(
				function ( $qa ) {
					return array( '@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $qa[1] ) );
				},
				self::faq()
			),
		);
		ob_start();
		?>
		<div class="snscs-mylink">
			<section class="snscs-mylink__hero">
				<div>
					<p class="snscs-mylink__eyebrow">発信者のための無料ツール</p>
					<p class="snscs-mylink__catch">SNSのプロフィールに貼るリンクまとめを、無料で。</p>
					<p>あなたのSNS・note・ブログ・お店のページを、スマホで見やすい1枚のページにまとめます。開かれるほど、このサイトのおすすめ順でも上に出やすくなります。</p>
					<p><a class="snscs-button snscs-button--primary snscs-mylink__cta" href="<?php echo esc_url( $cta_url ); ?>"><?php echo esc_html( $cta ); ?></a></p>
					<p class="snscs-help">URLは「<?php echo esc_html( untrailingslashit( preg_replace( '#^https?://#', '', home_url( '/' ) ) ) ); ?>/@あなたのID」になります。</p>
				</div>
				<?php if ( $preview ) : ?>
					<div class="snscs-mylink__phone" aria-hidden="true"><iframe src="<?php echo esc_url( $preview ); ?>" title="FolloLinkの見本" loading="lazy" tabindex="-1"></iframe></div>
				<?php endif; ?>
			</section>

			<h2>FolloLink（フォロリンク）でできること</h2>
			<ul class="snscs-mylink__features">
				<?php foreach ( $features as $f ) : ?>
					<li><span class="snscs-mylink__icon" aria-hidden="true"><?php echo esc_html( $f[0] ); ?></span><strong><?php echo esc_html( $f[1] ); ?></strong><span><?php echo esc_html( $f[2] ); ?></span></li>
				<?php endforeach; ?>
			</ul>

			<h2>作り方（3分）</h2>
			<ol class="snscs-mylink__steps">
				<li><strong>無料で登録</strong>して、SNSのアカウントを入れます。</li>
				<li>マイページで<strong>デザインといちばん見てほしいリンク</strong>を選びます。</li>
				<li>できたURLを、各SNSの<strong>プロフィール欄に貼ります</strong>。</li>
			</ol>

			<?php if ( count( $week ) >= 3 ) : ?>
				<h2>今週よく見られたFolloLink</h2>
				<ol class="snscs-mylink__rank">
					<?php $snscs_r = 0; ?>
					<?php foreach ( $week as $id => $views ) : ?>
						<li><span class="snscs-mylink__no"><?php echo (int) ++$snscs_r; ?></span><a href="<?php echo esc_url( self::url( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a><span class="snscs-help">@<?php echo esc_html( self::name_for( $id ) ); ?></span><span class="snscs-mylink__views"><?php echo esc_html( number_format_i18n( $views ) . '人' ); ?></span></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>

			<?php if ( $examples ) : ?>
				<h2>人気の発信者のFolloLink</h2>
				<ul class="snscs-mylink__examples">
					<?php foreach ( $examples as $id ) : ?>
						<li><a href="<?php echo esc_url( self::url( $id ) ); ?>">
							<?php if ( get_the_post_thumbnail_url( $id, 'thumbnail' ) ) : ?>
								<img src="<?php echo esc_url( get_the_post_thumbnail_url( $id, 'thumbnail' ) ); ?>" alt="" width="48" height="48" loading="lazy">
							<?php else : ?>
								<span class="snscs-mylink__initial" aria-hidden="true"><?php echo esc_html( mb_substr( get_the_title( $id ), 0, 1 ) ); ?></span>
							<?php endif; ?>
							<span><?php echo esc_html( get_the_title( $id ) ); ?><small>@<?php echo esc_html( self::name_for( $id ) ); ?></small></span>
						</a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2>よくある質問</h2>
			<dl class="snscs-mylink__faq">
				<?php foreach ( self::faq() as $qa ) : ?>
					<dt><?php echo esc_html( $qa[0] ); ?></dt>
					<dd><?php echo esc_html( $qa[1] ); ?></dd>
				<?php endforeach; ?>
			</dl>
			<p class="snscs-mylink__bottom"><a class="snscs-button snscs-button--primary snscs-mylink__cta" href="<?php echo esc_url( $cta_url ); ?>"><?php echo esc_html( $cta ); ?></a></p>
			<script type="application/ld+json"><?php echo wp_json_encode( $ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ); ?></script>
		</div>
		<?php
		return ob_get_clean();
	}

	/* --------------------------------------------------------------------
	 * マイページ
	 * ------------------------------------------------------------------ */

	public static function render_portal( $creator_id ) {
		wp_enqueue_script( 'snscs-bioed', SNSCS_URL . 'assets/js/snscs-bioed.js', array(), SNSCS_VERSION, true );
		$url     = self::url( $creator_id );
		$pin     = self::pin_for( $creator_id );
		$own     = (string) get_post_meta( $creator_id, self::META_NAME, true );
		$stats   = self::stats( $creator_id );
		$clicks  = $stats['links'];
		arsort( $clicks );
		$buttons = self::buttons( $creator_id );
		$where   = array(
			'x'  => array( 'X', 'プロフィールを編集 →「ウェブサイト」' ),
			'ig' => array( 'Instagram', 'プロフィールを編集 →「リンク」→「外部リンクを追加」' ),
			'tt' => array( 'TikTok', 'プロフィールを編集 →「ウェブサイト」（表示できる条件があります）' ),
			'yt' => array( 'YouTube', 'チャンネルをカスタマイズ →「リンク」' ),
			'th' => array( 'Threads', 'プロフィールを編集 →「リンク」' ),
		);
		$rate    = $stats['views'] ? round( min( 1, $stats['clicks'] / $stats['views'] ) * 100 ) : 0;
		$post_text = 'SNSやブログのリンクを1ページにまとめました。ぜひのぞいてみてください！';
		?>
		<section class="snscs-panel snscs-biolink" id="snscs-biolink">
			<h2 class="snscs-panel__title">FolloLink（フォロリンク）— SNSのプロフィールに貼るリンク</h2>
			<p>あなたのSNS・note・Brain・ブログなどを1ページにまとめた、スマホ向けのページです。各SNSのプロフィール欄に貼ってください。開かれた数は紹介リンク流入として数え、<strong>おすすめ順で上に表示されやすくなります</strong>。日本語以外の環境の人には、自動で英語の表示になります。</p>
			<p class="snscs-biolink__brand"><strong><?php echo esc_html( self::display_address( $creator_id ) ); ?></strong> <span class="snscs-help">（貼るときは下のアドレスをコピー）</span></p>
			<div class="snscs-referral__link">
				<label for="snscs-bio-link" class="screen-reader-text">FolloLink</label>
				<input id="snscs-bio-link" type="text" readonly value="<?php echo esc_url( $url ); ?>" onclick="this.select();">
				<button type="button" class="snscs-button snscs-button--primary" onclick="var i=document.getElementById('snscs-bio-link');i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent='コピーしました';">コピー</button>
			</div>
			<p class="snscs-biolink__share">
				<a class="snscs-button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">FolloLinkを見る</a>
				<a class="snscs-button snscs-biolink__x" href="<?php echo esc_url( 'https://x.com/intent/post?text=' . rawurlencode( $post_text ) . '&url=' . rawurlencode( add_query_arg( 's', 'x', $url ) ) ); ?>" target="_blank" rel="noopener">Xでお知らせ</a>
				<a class="snscs-button snscs-biolink__threads" href="<?php echo esc_url( 'https://www.threads.net/intent/post?text=' . rawurlencode( $post_text . ' ' . add_query_arg( 's', 'th', $url ) ) ); ?>" target="_blank" rel="noopener">Threadsでお知らせ</a>
			</p>
			<div class="snscs-biolink__qr">
				<div class="snscs-biolink__qrimg"><?php echo SNSCS_QR::svg( self::qr_target( $creator_id ), 132 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 自分で作ったSVG。 ?></div>
				<div>
					<p><strong>QRコード</strong>（名刺・チラシ・イベントのポップに）</p>
					<p class="snscs-help">読み取ると、あなたのFolloLinkが開きます。QRコードから来た人は「どこから来たか」に別に数えます。</p>
					<p><a class="snscs-button" href="<?php echo esc_url( self::qr_url( $creator_id, 'png', true ) ); ?>">画像（PNG）を保存</a> <a class="snscs-button" href="<?php echo esc_url( self::qr_url( $creator_id, 'svg', true ) ); ?>">印刷用（SVG）を保存</a></p>
				</div>
			</div>
			<h3 class="snscs-sharekit__heading">素材（FolloLinkを広める画像と文）</h3>
			<p class="snscs-help">あなたのデザインの色・写真・QRコードで、自動で作ります。デザインを変えると、画像も新しくなります。</p>
			<div class="snscs-biokit">
				<?php foreach ( SNSCS_Biolink_Kit::types() as $kit_type => $kit ) : ?>
					<figure class="snscs-biokit__item snscs-biokit__item--<?php echo esc_attr( $kit_type ); ?>">
						<a href="<?php echo esc_url( SNSCS_Biolink_Kit::url( $creator_id, $kit_type ) ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( SNSCS_Biolink_Kit::url( $creator_id, $kit_type ) ); ?>" alt="<?php echo esc_attr( $kit['label'] . 'の画像' ); ?>" loading="lazy" width="<?php echo (int) $kit['size'][0]; ?>" height="<?php echo (int) $kit['size'][1]; ?>"></a>
						<figcaption><strong><?php echo esc_html( $kit['label'] ); ?></strong><span><?php echo esc_html( $kit['size'][0] . '×' . $kit['size'][1] . '・' . $kit['note'] ); ?></span><a class="snscs-button" href="<?php echo esc_url( SNSCS_Biolink_Kit::url( $creator_id, $kit_type, true ) ); ?>">保存</a></figcaption>
					</figure>
				<?php endforeach; ?>
			</div>
			<?php
			$copies = array(
				'プロフィールの一言（日本語）' => '▼SNS・お仕事のご依頼はこちら',
				'リンクの前に置く一言'       => 'リンクまとめ👇 ' . $url,
				'投稿で紹介するとき'         => 'SNSやブログのリンクを1ページにまとめました。ぜひのぞいてみてください！ ' . $url,
				'ストーリーズの文字'         => 'リンクはここから👆 プロフィールのリンクにもあります',
				'英語のプロフィール'         => 'All my links 👇 ' . $url,
			);
			?>
			<ul class="snscs-biokit__copies">
				<?php foreach ( $copies as $copy_label => $copy_text ) : ?>
					<li><span class="snscs-biokit__clabel"><?php echo esc_html( $copy_label ); ?></span><input type="text" readonly value="<?php echo esc_attr( $copy_text ); ?>" onclick="this.select();" aria-label="<?php echo esc_attr( $copy_label ); ?>"><button type="button" class="snscs-button" onclick="var i=this.previousElementSibling;i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent='コピーしました';">コピー</button></li>
				<?php endforeach; ?>
			</ul>

			<?php if ( '' === $own ) : ?>
				<p class="snscs-help">「@hanako」のような覚えやすいIDにしたいときは、下の掲載内容のフォームの「FolloLinkのID」に入れて申請してください（審査のあとに切り替わります。今のURLもそのまま使えます）。</p>
			<?php endif; ?>

			<h3 class="snscs-sharekit__heading">直近30日の成績</h3>
			<ul class="snscs-biolink__stats">
				<li><strong><?php echo esc_html( number_format_i18n( $stats['views'] ) ); ?></strong>人が開いた</li>
				<li><strong><?php echo esc_html( number_format_i18n( $stats['clicks'] ) ); ?></strong>回リンクが押された</li>
				<li><strong><?php echo esc_html( $stats['views'] ? $rate . '%' : '—' ); ?></strong>押された率</li>
			</ul>

			<h3 class="snscs-sharekit__heading">どこから来たか</h3>
			<?php if ( $stats['sources'] ) : ?>
				<?php $max = max( $stats['sources'] ); ?>
				<ul class="snscs-analysis__list">
					<?php foreach ( $stats['sources'] as $key => $count ) : ?>
						<li>
							<span class="snscs-analysis__label"><?php echo esc_html( self::SOURCES[ $key ] ?? $key ); ?></span>
							<span class="snscs-analysis__bar" role="img" aria-label="<?php echo esc_attr( $count . '人' ); ?>"><span style="width: <?php echo esc_attr( max( 2, round( $count / $max * 100 ) ) ); ?>%"></span></span>
							<span class="snscs-analysis__point"><?php echo esc_html( number_format_i18n( $count ) ); ?>人</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="snscs-help">まだありません。</p>
			<?php endif; ?>
			<details class="snscs-invite__template">
				<summary>SNSごとのURL（どのSNSから来たかを正しく数えられます）</summary>
				<p class="snscs-help">SNSのアプリの中で開かれると、どこから来たかが分からないことがあります。SNSごとに、次のURLを貼ってください。</p>
				<ul class="snscs-biolink__urls">
					<?php foreach ( $where as $mark => $sns ) : ?>
						<li><strong><?php echo esc_html( $sns[0] ); ?></strong>: <input type="text" readonly value="<?php echo esc_url( add_query_arg( 's', $mark, $url ) ); ?>" onclick="this.select();" aria-label="<?php echo esc_attr( $sns[0] . '用のURL' ); ?>"><br><span class="snscs-help"><?php echo esc_html( '貼る場所: ' . $sns[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
				<p class="snscs-help">SNSのアプリの画面は変わることがあります。見つからないときは「プロフィールを編集」の中を探してみてください。</p>
			</details>

			<h3 class="snscs-sharekit__heading">デザイン</h3>
			<?php
			$style   = SNSCS_Biolink_Design::style_for( $creator_id );
			$preview = add_query_arg( 'snscs_preview', 1, $url );
			$design  = array(
				'button' => SNSCS_Biolink_Design::buttons(),
				'font'   => wp_list_pluck( SNSCS_Biolink_Design::fonts(), 0 ),
				'ring'   => SNSCS_Biolink_Design::rings(),
				'layout' => SNSCS_Biolink_Design::layouts(),
			);
			$names   = array( 'button' => 'ボタンの見た目', 'font' => '書体', 'ring' => '写真の枠', 'layout' => '並べ方' );
			?>
			<div class="snscs-bioed" data-preview="<?php echo esc_url( $preview ); ?>">
				<form class="snscs-bioed__form" method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
					<input type="hidden" name="action" value="snscs_link_theme">
					<?php wp_nonce_field( 'snscs_link_theme', 'snscs_nonce' ); ?>

					<fieldset class="snscs-bioed__group">
						<legend>テンプレート（押すだけでまとめて変わります）</legend>
						<div class="snscs-bioed__tpls">
							<?php foreach ( SNSCS_Biolink_Design::templates() as $key => $tpl ) : ?>
								<?php $tp = SNSCS_Biolink_Design::palette( array_merge( SNSCS_Biolink_Design::defaults(), $tpl ) ); ?>
								<button type="button" class="snscs-bioed__tpl" data-tpl="<?php echo esc_attr( wp_json_encode( array_intersect_key( $tpl, SNSCS_Biolink_Design::defaults() ) ) ); ?>">
									<span class="snscs-bioed__tplsw" style="background: <?php echo esc_attr( isset( $tp['pattern'] ) ? $tp['pattern'] . ',' . $tp['bg'] : $tp['bg'] ); ?>; background-size: <?php echo esc_attr( $tp['size'] ?? 'auto' ); ?>"><span style="background: <?php echo esc_attr( $tp['button'] ); ?>; border-color: <?php echo esc_attr( $tp['border'] ); ?>"></span><span style="background: <?php echo esc_attr( $tp['button'] ); ?>; border-color: <?php echo esc_attr( $tp['border'] ); ?>"></span></span>
									<?php echo esc_html( $tpl['label'] ); ?>
								</button>
							<?php endforeach; ?>
						</div>
					</fieldset>

					<fieldset class="snscs-bioed__group">
						<legend>背景とテーマ</legend>
						<?php foreach ( SNSCS_Biolink_Design::categories() as $cat => $cat_label ) : ?>
							<p class="snscs-bioed__cat"><?php echo esc_html( $cat_label ); ?></p>
							<div class="snscs-themes">
								<?php foreach ( SNSCS_Biolink_Design::themes() as $key => $theme ) : ?>
									<?php
									if ( $cat !== $theme['cat'] ) {
										continue;
									}
									?>
									<label class="snscs-theme">
										<input type="radio" name="theme" value="<?php echo esc_attr( $key ); ?>" <?php checked( $style['theme'], $key ); ?>>
										<span class="snscs-theme__swatch" style="background: <?php echo esc_attr( 'custom' === $key ? 'conic-gradient(#f87171,#fbbf24,#34d399,#60a5fa,#a78bfa,#f87171)' : ( isset( $theme['pattern'] ) ? $theme['pattern'] . ',' . $theme['bg'] : $theme['bg'] ) ); ?>; background-size: <?php echo esc_attr( $theme['size'] ?? 'auto' ); ?>"><span style="background: <?php echo esc_attr( $theme['button'] ); ?>; border-color: <?php echo esc_attr( $theme['border'] ); ?>"></span><span style="background: <?php echo esc_attr( $theme['button'] ); ?>; border-color: <?php echo esc_attr( $theme['border'] ); ?>"></span></span>
										<span class="snscs-theme__label"><?php echo esc_html( $theme['label'] ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						<?php endforeach; ?>
						<div class="snscs-bioed__custom"<?php echo 'custom' === $style['theme'] ? '' : ' hidden'; ?>>
							<label>背景の色 <input type="color" name="bg" value="<?php echo esc_attr( $style['bg'] ); ?>"></label>
							<label>ボタンの色 <input type="color" name="btn" value="<?php echo esc_attr( $style['btn'] ); ?>"></label>
							<span class="snscs-help">文字の色は、見やすいように自動で白か黒になります。</span>
						</div>
					</fieldset>

					<fieldset class="snscs-bioed__group">
						<legend>ボタンの形</legend>
						<?php foreach ( SNSCS_Biolink_Design::shapes() as $key => $info ) : ?>
							<label class="snscs-biolink__shape"><input type="radio" name="shape" value="<?php echo esc_attr( $key ); ?>" <?php checked( $style['shape'], $key ); ?>> <span style="border-radius: <?php echo esc_attr( $info[1] ); ?>"><?php echo esc_html( $info[0] ); ?></span></label>
						<?php endforeach; ?>
					</fieldset>

					<?php foreach ( $design as $field => $choices ) : ?>
						<fieldset class="snscs-bioed__group">
							<legend><?php echo esc_html( $names[ $field ] ); ?></legend>
							<div class="snscs-bioed__chips">
								<?php foreach ( $choices as $key => $label ) : ?>
									<label class="snscs-bioed__chip"><input type="radio" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $style[ $field ], $key ); ?>><span><?php echo esc_html( $label ); ?></span></label>
								<?php endforeach; ?>
							</div>
						</fieldset>
					<?php endforeach; ?>

					<fieldset class="snscs-bioed__group">
						<legend>いちばん見てほしいリンク</legend>
						<p class="snscs-help">選んだリンクを、いちばん上に大きく出します。新しい動画や、販売中の商品のリンクにおすすめです。</p>
						<label>リンク
							<select name="pin">
								<option value="">選ばない</option>
								<?php foreach ( $buttons as $button ) : ?>
									<option value="<?php echo esc_attr( $button['key'] ); ?>" <?php selected( $pin ? $pin['key'] : '', $button['key'] ); ?>><?php echo esc_html( $button['label'] . ( '' !== $button['sub'] ? '（' . $button['sub'] . '）' : '' ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label>札
							<select name="pin_tag">
								<?php foreach ( self::PIN_TAGS as $key => $labels ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $pin ? $pin['tag'] : '', $key ); ?>><?php echo esc_html( $labels[0] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</fieldset>
					<label class="snscs-biolink__check"><input type="checkbox" name="similar" value="1" <?php checked( self::shows_similar( $creator_id ) ); ?>> ページの下に「こちらの発信者もおすすめ」を出す（あなたも、ほかの人のFolloLinkで紹介されやすくなります）</label>
					<p class="snscs-bioed__save"><button type="submit" class="snscs-button snscs-button--primary">このデザインで保存</button> <span class="snscs-help">右の画面は見本です。保存するまで、公開中のFolloLinkは変わりません。</span></p>
				</form>
				<div class="snscs-bioed__preview" aria-label="プレビュー">
					<div class="snscs-bioed__phone"><iframe src="<?php echo esc_url( $preview ); ?>" title="FolloLinkのプレビュー" loading="lazy"></iframe></div>
					<p class="snscs-help">プレビュー</p>
				</div>
			</div>

			<?php SNSCS_Biolink_Extra::render_portal( $creator_id ); ?>

			<h3 class="snscs-sharekit__heading">押されたリンク（直近30日）</h3>
			<?php if ( $clicks ) : ?>
				<?php $max = max( $clicks ); ?>
				<ul class="snscs-analysis__list">
					<?php foreach ( array_slice( $clicks, 0, 10, true ) as $key => $count ) : ?>
						<li>
							<span class="snscs-analysis__label"><?php echo esc_html( self::key_label( $creator_id, $key ) ); ?></span>
							<span class="snscs-analysis__bar" role="img" aria-label="<?php echo esc_attr( $count . '回' ); ?>"><span style="width: <?php echo esc_attr( max( 2, round( $count / $max * 100 ) ) ); ?>%"></span></span>
							<span class="snscs-analysis__point"><?php echo esc_html( number_format_i18n( $count ) ); ?>回</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="snscs-help">まだありません。FolloLinkを貼ると、どのリンクがよく押されたかがここに出ます。</p>
			<?php endif; ?>
		</section>
		<?php
	}
}
