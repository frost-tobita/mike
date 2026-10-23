<?php
/**
 * マイリンク（SNSのプロフィール欄に貼る「リンクまとめ」ページ）。
 *
 *   /@ID  （IDは発信者が決める。審査あり。決めるまでは紹介コードで /@紹介コード）
 *
 * - スマホで見やすい1枚のページ（テーマの見た目を使わない）に、SNS・note/Brain・その他のリンク・
 *   最新の記事・お仕事の相談を大きなボタンで並べる。デザインは6種類から選べる（選ぶだけなので審査なし）
 * - 開かれたら紹介リンク流入として数え（表示順位に効く）、閲覧・滞在時間・リンクのクリックも数える
 * - どのリンクが何回押されたかを、発信者のマイページに表示する
 * - ページの下に「ほかの発信者を探す」「あなたもマイリンクを作る（無料）」を出し、サイトへの入口にする
 *   （後者は発信者の招待リンクなので、登録されれば招待として数える）
 * - 1.16: いちばん見てほしいリンクを上に大きく出す（「新着」などの札は選ぶだけ）、ボタンの形、受賞・順位のバッジ、
 *   似ている発信者（発信者が消せる）、どのSNSから来たか（?s=ig のような印、無ければ参照元）と開かれた数・押された率、
 *   日本語以外の閲覧者には英語の表示（英訳があれば紹介文も英語。SNSCS_English）
 * - 1.17: 名刺・チラシ用のQRコード（SNSCS_QR。?s=qr で数える）、読者が「このサイトでフォロー」（未登録なら登録して戻る）、
 *   シェアボタン、ProfilePage の構造化データ、IDを決めた人のマイリンクをサイトマップへ、
 *   週に1回の成績のお知らせと、まだ使っていない人への案内、紹介ページの「今週よく見られたマイリンク」、
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
			return 'マイリンクのIDは、半角英数字・ハイフン・アンダーバーで3〜30文字にしてください。';
		}
		if ( in_array( $name, self::reserved(), true ) ) {
			return 'そのマイリンクのIDは使えません。別のIDにしてください。';
		}
		$taken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s AND post_id <> %d LIMIT 1", self::META_NAME, $name, (int) $creator_id ) );
		// 紹介コード（決める前のマイリンク）とも重ならないようにする。
		$code = SNSCS_Scores::creator_for_code( $name );
		if ( $taken || ( $code && $code !== (int) $creator_id ) ) {
			return 'そのマイリンクのIDは、ほかの発信者が使っています。別のIDにしてください。';
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
		return apply_filters(
			'snscs_link_themes',
			array(
				'simple'  => array( 'label' => 'シンプル', 'bg' => '#f5f7fb', 'text' => '#111827', 'muted' => '#4b5563', 'button' => '#ffffff', 'button_text' => '#111827', 'border' => '#e5e7eb' ),
				'dark'    => array( 'label' => 'ダーク', 'bg' => '#0f172a', 'text' => '#f8fafc', 'muted' => '#cbd5e1', 'button' => '#1e293b', 'button_text' => '#f8fafc', 'border' => '#334155' ),
				'sakura'  => array( 'label' => 'さくら', 'bg' => 'linear-gradient(180deg,#fff1f5,#ffe4ec)', 'text' => '#4a1d2f', 'muted' => '#7a4a5d', 'button' => '#ffffff', 'button_text' => '#9d174d', 'border' => '#fbcfe8' ),
				'forest'  => array( 'label' => 'フォレスト', 'bg' => 'linear-gradient(180deg,#ecfdf5,#d1fae5)', 'text' => '#064e3b', 'muted' => '#336b57', 'button' => '#065f46', 'button_text' => '#ffffff', 'border' => '#065f46' ),
				'ocean'   => array( 'label' => 'オーシャン', 'bg' => 'linear-gradient(180deg,#1e3a8a,#2563eb)', 'text' => '#ffffff', 'muted' => '#dbeafe', 'button' => 'rgba(255,255,255,.14)', 'button_text' => '#ffffff', 'border' => 'rgba(255,255,255,.5)' ),
				'sunset'  => array( 'label' => 'サンセット', 'bg' => 'linear-gradient(160deg,#f97316,#db2777)', 'text' => '#ffffff', 'muted' => '#ffedd5', 'button' => '#ffffff', 'button_text' => '#9a3412', 'border' => '#ffffff' ),
				'lavender' => array( 'label' => 'ラベンダー', 'bg' => 'linear-gradient(180deg,#f5f3ff,#ede9fe)', 'text' => '#2e1065', 'muted' => '#5b4a86', 'button' => '#7c3aed', 'button_text' => '#ffffff', 'border' => '#7c3aed' ),
				'mono'     => array( 'label' => 'モノクロ', 'bg' => '#ffffff', 'text' => '#000000', 'muted' => '#404040', 'button' => '#000000', 'button_text' => '#ffffff', 'border' => '#000000' ),
				'citrus'   => array( 'label' => 'シトラス', 'bg' => 'linear-gradient(180deg,#fefce8,#fef08a)', 'text' => '#422006', 'muted' => '#6b4f1d', 'button' => '#ffffff', 'button_text' => '#854d0e', 'border' => '#ca8a04' ),
			)
		);
	}

	public static function theme_for( $creator_id ) {
		$theme = (string) get_post_meta( $creator_id, self::META_THEME, true );
		return array_key_exists( $theme, self::themes() ) ? $theme : 'simple';
	}

	/**
	 * ボタンの形 => [表示名, 角の丸み]
	 */
	public static function shapes() {
		return array(
			'round'  => array( '角丸', '14px' ),
			'pill'   => array( 'まる', '999px' ),
			'square' => array( '四角', '4px' ),
		);
	}

	public static function shape_for( $creator_id ) {
		$shape = (string) get_post_meta( $creator_id, self::META_SHAPE, true );
		return array_key_exists( $shape, self::shapes() ) ? $shape : 'round';
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
		$theme      = isset( $_POST['theme'] ) ? sanitize_key( $_POST['theme'] ) : '';
		$shape      = isset( $_POST['shape'] ) ? sanitize_key( $_POST['shape'] ) : '';
		if ( $creator_id && array_key_exists( $theme, self::themes() ) ) {
			update_post_meta( $creator_id, self::META_THEME, $theme );
		}
		if ( $creator_id && array_key_exists( $shape, self::shapes() ) ) {
			update_post_meta( $creator_id, self::META_SHAPE, $shape );
		}
		if ( $creator_id ) {
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
	 * マイリンクが開かれたことを数える（同じ人の同じ日は1回）。どのSNSから来たかも数える。
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
	 * マイリンクからのフォロー（会員だけ。フォローしたら、マイリンクに戻る）。
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
	 * QRコード（?snscs_bio_qr=発信者ID&fmt=svg|png&dl=1）。中身はマイリンクのURL（?s=qr 付き）。
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
	 * 今週（直近7日）よく開かれたマイリンク。
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
	 * 週に1回（月曜の朝）: 先週の成績のお知らせと、まだマイリンクを使っていない人への案内。
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
			return array( 'text' => 'マイリンクをSNSのプロフィールに貼ってみませんか？開かれるほど、このサイトのおすすめ順でも上に出やすくなります。', 'url' => snscs_portal_url() . '#snscs-biolink' );
		}
		$s = self::stats( $creator_id, 7 );
		if ( ! $s['views'] ) {
			return null;
		}
		$top = $s['sources'] ? self::SOURCES[ array_search( max( $s['sources'] ), $s['sources'], true ) ] ?? '' : '';
		return array(
			'text' => sprintf( 'この1週間で、マイリンクが%d人に開かれ、リンクが%d回押されました。%s', $s['views'], $s['clicks'], '' !== $top ? 'いちばん多く来たのは「' . $top . '」からです。' : '' ),
			'url'  => snscs_portal_url() . '#snscs-biolink',
		);
	}

	/**
	 * サイトマップ（自分でIDを決めた人のマイリンク。使っている人のページだけ）。
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
		return 0 === strpos( $key, 'article:' ) ? '記事' : $key;
	}

	/**
	 * /@ID を開いたとき: 数えて、マイリンクのページを出す。
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
		echo self::page_html( $creator_id, $tracking, '', SNSCS_English::wants_english() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- page_html() の中でエスケープ済み。
		exit;
	}

	/**
	 * マイリンクのページ全体のHTML。
	 *
	 * @param int        $creator_id 発信者。
	 * @param array|null $tracking   計測の設定（本人・運営が見るときは null）。
	 * @param string     $theme      デザイン（プレビュー用。省略時は保存したもの）。
	 * @param bool       $english    英語で表示するか（日本語以外の閲覧者）。
	 */
	public static function page_html( $creator_id, $tracking = null, $theme = '', $english = false ) {
		$post     = get_post( $creator_id );
		$themes   = self::themes();
		$theme    = array_key_exists( $theme, $themes ) ? $theme : self::theme_for( $creator_id );
		$t        = $themes[ $theme ];
		$radius   = self::shapes()[ self::shape_for( $creator_id ) ][1];
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
		$buttons = self::buttons( $creator_id, $english );
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
<script type="application/ld+json"><?php echo wp_json_encode( $ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ); ?></script>
<style>
*{box-sizing:border-box}
html{min-height:100%;background:<?php echo esc_html( $t['bg'] ); ?>}
body{margin:0;color:<?php echo esc_html( $t['text'] ); ?>;font-family:"Hiragino Sans","Noto Sans JP","Yu Gothic",Meiryo,system-ui,sans-serif;line-height:1.6}
.bio{max-width:560px;margin:0 auto;padding:40px 16px 32px;text-align:center}
.bio__photo{width:104px;height:104px;border-radius:50%;object-fit:cover;border:3px solid <?php echo esc_html( $t['border'] ); ?>}
.bio__initial{display:inline-flex;align-items:center;justify-content:center;width:104px;height:104px;border-radius:50%;background:<?php echo esc_html( $t['button'] ); ?>;color:<?php echo esc_html( $t['button_text'] ); ?>;font-size:44px;font-weight:700;border:3px solid <?php echo esc_html( $t['border'] ); ?>}
.bio__name{margin:12px 0 4px;font-size:1.4em}
.bio__genres{margin:0 0 8px;color:<?php echo esc_html( $t['muted'] ); ?>;font-size:.85em}
.bio__badges{display:flex;flex-wrap:wrap;justify-content:center;gap:6px;margin:0 0 12px;padding:0;list-style:none}
.bio__badges li{padding:2px 10px;border:1px solid <?php echo esc_html( $t['border'] ); ?>;border-radius:999px;font-size:.78em;font-weight:700}
.bio__text{margin:0 auto 24px;color:<?php echo esc_html( $t['muted'] ); ?>;font-size:.92em;text-align:left;max-width:30em}
.bio__list{display:grid;gap:12px;margin:0 0 24px;padding:0;list-style:none}
.bio__btn{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:58px;padding:10px 16px;border:2px solid <?php echo esc_html( $t['border'] ); ?>;border-radius:<?php echo esc_html( $radius ); ?>;background:<?php echo esc_html( $t['button'] ); ?>;color:<?php echo esc_html( $t['button_text'] ); ?>;text-decoration:none;font-weight:700;transition:transform .1s}
.bio__btn:hover,.bio__btn:focus-visible{transform:translateY(-2px)}
.bio__btn small{font-weight:400;font-size:.8em;opacity:.8;word-break:break-all}
.bio__btn--pinned{min-height:72px;font-size:1.08em;box-shadow:0 6px 18px rgba(0,0,0,.18);animation:bio-pop 2.4s ease-in-out 1s 2}
.bio__tag{position:absolute;top:-10px;left:14px;padding:1px 10px;border-radius:999px;background:#ef4444;color:#fff;font-size:.72em;line-height:1.7}
@keyframes bio-pop{0%,100%{transform:none}50%{transform:scale(1.03)}}
@media (prefers-reduced-motion:reduce){.bio__btn--pinned{animation:none}.bio__btn{transition:none}}
.bio__heading{margin:28px 0 10px;font-size:.95em;color:<?php echo esc_html( $t['muted'] ); ?>}
.bio__similar{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:0 0 8px;padding:0;list-style:none}
.bio__similar a{display:block;color:<?php echo esc_html( $t['text'] ); ?>;font-size:.78em;text-decoration:none;line-height:1.4}
.bio__similar img,.bio__similar span{display:block;width:56px;height:56px;margin:0 auto 4px;border-radius:50%;object-fit:cover;background:<?php echo esc_html( $t['button'] ); ?>;border:2px solid <?php echo esc_html( $t['border'] ); ?>}
.bio__foot{margin-top:36px;padding-top:20px;border-top:1px solid <?php echo esc_html( $t['border'] ); ?>;font-size:.85em}
.bio__foot a{color:<?php echo esc_html( $t['text'] ); ?>}
.bio__foot p{margin:6px 0}
.bio__make{display:inline-block;margin-top:8px;padding:6px 16px;border:1px solid <?php echo esc_html( $t['border'] ); ?>;border-radius:999px;text-decoration:none}
.bio__actions{display:flex;justify-content:center;gap:8px;margin:0 0 16px}
.bio__followform{margin:0}
.bio__pill{display:inline-flex;align-items:center;gap:6px;min-height:40px;padding:0 18px;border:2px solid <?php echo esc_html( $t['border'] ); ?>;border-radius:999px;background:<?php echo esc_html( $t['button'] ); ?>;color:<?php echo esc_html( $t['button_text'] ); ?>;font:inherit;font-size:.9em;font-weight:700;text-decoration:none;cursor:pointer}
.bio__pill--on{opacity:.75}
.bio__notice{margin:0 0 16px;padding:10px 14px;border-radius:12px;background:rgba(16,185,129,.15);font-size:.9em}
.bio__ask{position:fixed;left:12px;right:12px;bottom:12px;z-index:10;max-width:536px;margin:0 auto;padding:14px 16px;border-radius:16px;background:#111827;color:#fff;box-shadow:0 8px 24px rgba(0,0,0,.3);font-size:.92em;text-align:center}
.bio__ask p{margin:0 0 10px}
.bio__ask button{min-height:42px;padding:0 22px;border:0;border-radius:999px;background:#fff;color:#111827;font:inherit;font-weight:700;cursor:pointer}
</style>
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
				<li><span aria-hidden="true"><?php echo esc_html( $badge[0] ); ?></span> <?php echo esc_html( $badge[1] ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php if ( '' !== $bio ) : ?>
		<p class="bio__text"><?php echo esc_html( $bio ); ?></p>
	<?php endif; ?>

	<?php if ( $followed ) : ?>
		<p class="bio__notice" role="status"><?php echo esc_html( $l( 'フォローしました。新しい記事などを、会員ページでお知らせします。', 'Followed. You will get updates on your member page.' ) ); ?></p>
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
			<li><a class="bio__btn bio__btn--pinned bio__btn--<?php echo esc_attr( $pinned['kind'] ); ?>" href="<?php echo esc_url( $pinned['url'] ); ?>" target="_blank" rel="noopener nofollow ugc" data-snscs-out="<?php echo esc_attr( $pinned['key'] ); ?>"><?php if ( '' !== $pin_tag ) : ?><span class="bio__tag"><?php echo esc_html( $pin_tag ); ?></span><?php endif; ?><?php echo esc_html( $pinned['label'] ); ?><?php if ( '' !== $pinned['sub'] ) : ?><small><?php echo esc_html( $pinned['sub'] ); ?></small><?php endif; ?></a></li>
		<?php endif; ?>
		<?php foreach ( $buttons as $button ) : ?>
			<li><a class="bio__btn bio__btn--<?php echo esc_attr( $button['kind'] ); ?>" href="<?php echo esc_url( $button['url'] ); ?>" target="_blank" rel="noopener nofollow ugc" data-snscs-out="<?php echo esc_attr( $button['key'] ); ?>"><?php echo esc_html( $button['label'] ); ?><?php if ( '' !== $button['sub'] ) : ?><small><?php echo esc_html( $button['sub'] ); ?></small><?php endif; ?></a></li>
		<?php endforeach; ?>
		<?php if ( $inquiry ) : ?>
			<li><a class="bio__btn" href="<?php echo esc_url( get_permalink( $creator_id ) . '#snscs-inquiry' ); ?>" data-snscs-out="inquiry:form"><?php echo esc_html( $l( 'お仕事の相談', 'Business inquiries' ) ); ?><small><?php echo esc_html( $l( '企業・団体の方はこちら', 'For brands and organizations (form in Japanese)' ) ); ?></small></a></li>
		<?php endif; ?>
		<li><a class="bio__btn" href="<?php echo esc_url( $profile ); ?>" data-snscs-out="page:profile"><?php echo esc_html( $l( '詳しいプロフィール', 'Full profile' ) ); ?><small><?php echo esc_html( $site ); ?></small></a></li>
	</ul>

	<?php if ( $articles && ! $english ) : ?>
		<h2 class="bio__heading">最新の記事</h2>
		<ul class="bio__list">
			<?php foreach ( $articles as $article ) : ?>
				<li><a class="bio__btn" href="<?php echo esc_url( get_permalink( $article ) ); ?>" data-snscs-out="article:<?php echo (int) $article->ID; ?>"><?php echo esc_html( get_the_title( $article ) ); ?></a></li>
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
		<p><a href="<?php echo esc_url( $search ); ?>"><?php echo esc_html( $l( $site . 'でほかの発信者を探す', 'Discover Japanese creators on ' . $site ) ); ?></a></p>
		<?php if ( ! $english ) : ?>
			<a class="bio__make" href="<?php echo esc_url( $join ); ?>">あなたもマイリンクを作る（無料）</a>
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
			array( '本当に無料ですか？', 'はい。マイリンクの作成・デザインの変更・アクセスの分析まで、すべて無料です。' ),
			array( 'lit.link や Linktree と何が違いますか？', 'マイリンクは、発信者を探せるサイトの中にあります。開かれた数がサイトのおすすめ順に反映されるので、マイリンクを貼るほど、このサイトでもあなたが見つかりやすくなります。' ),
			array( 'どんなリンクを載せられますか？', 'X・Instagram・TikTok・YouTube・Threads などのSNS、note・Brain、ブログやお店のページなどです。いちばん見てほしいリンクを上に大きく出すこともできます。' ),
			array( 'どのSNSから見られたか分かりますか？', 'はい。SNSごとのURLを貼ると、どのSNSから何人が来たか、どのリンクが何回押されたかを、マイページで確かめられます。毎週の成績もお知らせします。' ),
			array( '名刺やチラシにも使えますか？', 'はい。マイページからQRコードの画像（PNG・印刷用のSVG）を保存できます。' ),
			array( '海外の人にも見てもらえますか？', '日本語以外の環境の人には、ボタンなどが自動で英語の表示になります。紹介文も英訳されたものが出ます。' ),
		);
	}

	public static function shortcode_landing() {
		SNSCS_Hub::remember_page( 'mylink' );
		wp_enqueue_style( 'snscs' );
		$creator  = is_user_logged_in() ? SNSCS_Submissions::creator_id_for_user( get_current_user_id() ) : 0;
		$cta_url  = $creator ? snscs_portal_url() . '#snscs-biolink' : snscs_portal_url();
		$cta      = $creator ? 'マイページでマイリンクを設定する' : '無料でマイリンクを作る';
		$examples = self::examples();
		$week     = self::top_week( 10 );
		$preview  = $examples ? add_query_arg( 'snscs_preview', 1, self::url( $examples[0] ) ) : '';
		$features = array(
			array( '🔗', 'SNSをまとめて1ページに', 'X・Instagram・TikTok・YouTube・note などを、大きなボタンで並べます。' ),
			array( '⭐', 'いちばん見てほしいリンクを目立たせる', '新作や販売中の商品を、「新着」「セール中」の札付きで一番上に出せます。' ),
			array( '📊', 'どのSNSから来たか分かる', '開かれた数・押されたリンク・来たSNSを、マイページで確かめられます。' ),
			array( '🎨', '9種類のデザイン', 'デザインとボタンの形を選ぶだけ。スマホで見やすく、表示も速いです。' ),
			array( '🏆', '受賞や順位のバッジ', 'アワードの受賞やジャンルでの順位が、自動でバッジになります。' ),
			array( '🌏', '海外の人には英語で表示', '日本語以外の環境の人には、自動で英語の表示になります。' ),
			array( '📱', 'QRコードで名刺やチラシにも', 'QRコードを保存して、名刺・チラシ・イベントのポップに。来た人数も数えます。' ),
			array( '🔔', 'ファンがフォローできる', '読者はマイリンクからこのサイトでフォローでき、あなたの新しい記事のお知らせを受け取れます。' ),
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
					<div class="snscs-mylink__phone" aria-hidden="true"><iframe src="<?php echo esc_url( $preview ); ?>" title="マイリンクの見本" loading="lazy" tabindex="-1"></iframe></div>
				<?php endif; ?>
			</section>

			<h2>マイリンクでできること</h2>
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
				<h2>今週よく見られたマイリンク</h2>
				<ol class="snscs-mylink__rank">
					<?php $snscs_r = 0; ?>
					<?php foreach ( $week as $id => $views ) : ?>
						<li><span class="snscs-mylink__no"><?php echo (int) ++$snscs_r; ?></span><a href="<?php echo esc_url( self::url( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a><span class="snscs-help">@<?php echo esc_html( self::name_for( $id ) ); ?></span><span class="snscs-mylink__views"><?php echo esc_html( number_format_i18n( $views ) . '人' ); ?></span></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>

			<?php if ( $examples ) : ?>
				<h2>人気の発信者のマイリンク</h2>
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
		$url     = self::url( $creator_id );
		$current = self::theme_for( $creator_id );
		$shape   = self::shape_for( $creator_id );
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
			<h2 class="snscs-panel__title">マイリンク（SNSのプロフィールに貼るリンク）</h2>
			<p>あなたのSNS・note・Brain・ブログなどを1ページにまとめた、スマホ向けのページです。各SNSのプロフィール欄に貼ってください。開かれた数は紹介リンク流入として数え、<strong>おすすめ順で上に表示されやすくなります</strong>。日本語以外の環境の人には、自動で英語の表示になります。</p>
			<div class="snscs-referral__link">
				<label for="snscs-bio-link" class="screen-reader-text">マイリンク</label>
				<input id="snscs-bio-link" type="text" readonly value="<?php echo esc_url( $url ); ?>" onclick="this.select();">
				<button type="button" class="snscs-button snscs-button--primary" onclick="var i=document.getElementById('snscs-bio-link');i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent='コピーしました';">コピー</button>
			</div>
			<p class="snscs-biolink__share">
				<a class="snscs-button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">マイリンクを見る</a>
				<a class="snscs-button snscs-biolink__x" href="<?php echo esc_url( 'https://x.com/intent/post?text=' . rawurlencode( $post_text ) . '&url=' . rawurlencode( add_query_arg( 's', 'x', $url ) ) ); ?>" target="_blank" rel="noopener">Xでお知らせ</a>
				<a class="snscs-button snscs-biolink__threads" href="<?php echo esc_url( 'https://www.threads.net/intent/post?text=' . rawurlencode( $post_text . ' ' . add_query_arg( 's', 'th', $url ) ) ); ?>" target="_blank" rel="noopener">Threadsでお知らせ</a>
			</p>
			<div class="snscs-biolink__qr">
				<div class="snscs-biolink__qrimg"><?php echo SNSCS_QR::svg( self::qr_target( $creator_id ), 132 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 自分で作ったSVG。 ?></div>
				<div>
					<p><strong>QRコード</strong>（名刺・チラシ・イベントのポップに）</p>
					<p class="snscs-help">読み取ると、あなたのマイリンクが開きます。QRコードから来た人は「どこから来たか」に別に数えます。</p>
					<p><a class="snscs-button" href="<?php echo esc_url( self::qr_url( $creator_id, 'png', true ) ); ?>">画像（PNG）を保存</a> <a class="snscs-button" href="<?php echo esc_url( self::qr_url( $creator_id, 'svg', true ) ); ?>">印刷用（SVG）を保存</a></p>
				</div>
			</div>
			<?php if ( '' === $own ) : ?>
				<p class="snscs-help">「@hanako」のような覚えやすいIDにしたいときは、下の掲載内容のフォームの「マイリンクのID」に入れて申請してください（審査のあとに切り替わります。今のURLもそのまま使えます）。</p>
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

			<h3 class="snscs-sharekit__heading">デザインと表示</h3>
			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
				<input type="hidden" name="action" value="snscs_link_theme">
				<?php wp_nonce_field( 'snscs_link_theme', 'snscs_nonce' ); ?>
				<div class="snscs-themes">
					<?php foreach ( self::themes() as $key => $theme ) : ?>
						<label class="snscs-theme">
							<input type="radio" name="theme" value="<?php echo esc_attr( $key ); ?>" <?php checked( $current, $key ); ?>>
							<span class="snscs-theme__swatch" style="background: <?php echo esc_attr( $theme['bg'] ); ?>"><span style="background: <?php echo esc_attr( $theme['button'] ); ?>; border-color: <?php echo esc_attr( $theme['border'] ); ?>"></span><span style="background: <?php echo esc_attr( $theme['button'] ); ?>; border-color: <?php echo esc_attr( $theme['border'] ); ?>"></span></span>
							<span class="snscs-theme__label"><?php echo esc_html( $theme['label'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<fieldset class="snscs-biolink__field">
					<legend>ボタンの形</legend>
					<?php foreach ( self::shapes() as $key => $info ) : ?>
						<label class="snscs-biolink__shape"><input type="radio" name="shape" value="<?php echo esc_attr( $key ); ?>" <?php checked( $shape, $key ); ?>> <span style="border-radius: <?php echo esc_attr( $info[1] ); ?>"><?php echo esc_html( $info[0] ); ?></span></label>
					<?php endforeach; ?>
				</fieldset>
				<fieldset class="snscs-biolink__field">
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
				<label class="snscs-biolink__check"><input type="checkbox" name="similar" value="1" <?php checked( self::shows_similar( $creator_id ) ); ?>> ページの下に「こちらの発信者もおすすめ」を出す（あなたも、ほかの人のマイリンクで紹介されやすくなります）</label>
				<button type="submit" class="snscs-button">保存</button>
			</form>

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
				<p class="snscs-help">まだありません。マイリンクを貼ると、どのリンクがよく押されたかがここに出ます。</p>
			<?php endif; ?>
		</section>
		<?php
	}
}
