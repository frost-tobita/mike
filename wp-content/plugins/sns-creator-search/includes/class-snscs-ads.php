<?php
/**
 * 広告枠: 画像＋リンク／HTMLコード（アフィリエイト）／Googleアドセンス／
 * コンテンツ販売（Brain・Tips などの商品カード）を、
 * 決まった場所（広告枠）に控えめに表示する。
 *
 * - 1つの枠に出す広告は、1回の表示につき1つだけ。複数登録すると「表示の割合」に応じて入れ替わる。
 * - 同じページの中で、同じ広告は2回出さない。
 * - すべての広告に「広告」ラベルを付ける（ステルスマーケティング規制への対応）。
 * - 表示回数と、画像広告のクリック数を日ごとに数える（ロボットは数えない）。
 *
 * 置き場所:
 *   検索結果ページ → 自動で表示（一覧の上・一覧の途中・一覧の下）
 *   トップページなど → ショートコード [sns_ad slot="top_side"] を書いた場所に表示
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Ads {

	const POST_TYPE       = 'snscs_ad';
	const CLICK_QUERY_VAR = 'snscs_ad_click';
	const ADS_TXT_VAR     = 'snscs_ads_txt';
	const CACHE_KEY       = 'snscs_active_ads';

	const TYPE_IMAGE   = 'image';
	const TYPE_HTML    = 'html';
	const TYPE_ADSENSE = 'adsense';
	const TYPE_CONTENT = 'content';

	/** @var int[] このページですでに表示した広告。 */
	private static $shown = array();

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_requests' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'head_code' ) );
		add_shortcode( 'sns_ad', array( __CLASS__, 'shortcode' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'flush_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_cache' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'flush_cache' ) );
	}

	/**
	 * 広告枠の一覧。並び順のまま設定画面に出る。
	 *
	 * @return array<string,array{label:string,size:string,default:bool}>
	 */
	public static function slots() {
		return apply_filters(
			'snscs_ad_slots',
			array(
				'top_side'      => array( 'label' => 'トップページ：右上（四角）', 'size' => '300×250 推奨', 'default' => true ),
				'side_member'   => array( 'label' => '右の列：ログイン中の発信者向け（「発信者の方へ」の場所）', 'size' => '300×250 推奨（発信者向けのサービス・ツールがおすすめ）', 'default' => true ),
				'top_infeed'    => array( 'label' => 'トップページ：コンテンツの間（横長）', 'size' => '728×90 または横幅いっぱい', 'default' => true ),
				'search_top'    => array( 'label' => '検索結果：一覧の上', 'size' => '728×90 または横幅いっぱい', 'default' => true ),
				'search_infeed' => array( 'label' => '検索結果：一覧の途中', 'size' => '発信者カードと同じ横幅', 'default' => true ),
				'search_bottom' => array( 'label' => '検索結果：一覧の下', 'size' => '728×90 または横幅いっぱい', 'default' => false ),
				'column_bottom' => array( 'label' => 'コラム：記事の下', 'size' => '横幅いっぱい（コンテンツ販売のカードがおすすめ）', 'default' => true ),
				'article_bottom' => array( 'label' => '発信者の記事：記事の下', 'size' => '横幅いっぱい', 'default' => false ),
			)
		);
	}

	public static function types() {
		return array(
			self::TYPE_IMAGE   => '画像＋リンク',
			self::TYPE_HTML    => 'HTMLコード（アフィリエイトなど）',
			self::TYPE_ADSENSE => 'Googleアドセンス',
			self::TYPE_CONTENT => 'コンテンツ販売（Brain・Tips など）',
		);
	}

	/**
	 * コンテンツ販売プラットフォーム（キー => 表示名）。
	 */
	public static function content_platforms() {
		return apply_filters(
			'snscs_content_platforms',
			array(
				'brain' => 'Brain',
				'tips'  => 'Tips',
				'other' => 'その他',
			)
		);
	}

	/**
	 * クリック数を数える（リンク先へ中継する）種類か。
	 */
	public static function is_linked_type( $type ) {
		return in_array( $type, array( self::TYPE_IMAGE, self::TYPE_CONTENT ), true );
	}

	/* --------------------------------------------------------------------
	 * 設定
	 * ------------------------------------------------------------------ */

	public static function settings() {
		$defaults = array(
			'label'         => '広告',
			'enabled_slots' => array_keys( array_filter( wp_list_pluck( self::slots(), 'default' ) ) ),
			'infeed_after'  => 5,
			'ads_txt'       => '',
			'head_code'     => '',
		);
		$saved = get_option( 'snscs_ads_settings', array() );
		$saved = is_array( $saved ) ? $saved : array();

		// 設定を保存したあとに増えた枠は、その枠の初期設定（オン・オフ）に従う。
		if ( isset( $saved['enabled_slots'] ) ) {
			$known = isset( $saved['known_slots'] ) ? (array) $saved['known_slots'] : array( 'top_side', 'top_infeed', 'search_top', 'search_infeed', 'search_bottom' );
			foreach ( self::slots() as $key => $slot ) {
				if ( $slot['default'] && ! in_array( $key, $known, true ) && ! in_array( $key, (array) $saved['enabled_slots'], true ) ) {
					$saved['enabled_slots'][] = $key;
				}
			}
		}

		return array_merge( $defaults, $saved );
	}

	public static function slot_enabled( $slot ) {
		return in_array( $slot, (array) self::settings()['enabled_slots'], true );
	}

	/* --------------------------------------------------------------------
	 * 登録・テーブル
	 * ------------------------------------------------------------------ */

	public static function register() {
		// 広告にはスクリプトを含められるため、扱えるのはサイト管理者だけにする。
		$cap = 'manage_options';
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => '広告',
					'singular_name' => '広告',
					'menu_name'     => '広告枠',
					'all_items'     => '広告一覧',
					'add_new'       => '広告を追加',
					'add_new_item'  => '広告を追加',
					'edit_item'     => '広告を編集',
					'not_found'     => '広告はまだありません',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-megaphone',
				'supports'     => array( 'title' ),
				'map_meta_cap' => false,
				'capabilities' => array(
					'edit_post'              => $cap,
					'read_post'              => $cap,
					'delete_post'            => $cap,
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'edit_published_posts'   => $cap,
					'publish_posts'          => $cap,
					'delete_posts'           => $cap,
					'delete_others_posts'    => $cap,
					'delete_published_posts' => $cap,
					'read_private_posts'     => $cap,
					'create_posts'           => $cap,
				),
			)
		);
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_ad_stats';
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta の書式に合わせ、各行の区切りや空白は崩さないこと。
		$sql = "CREATE TABLE {$table} (
  ad_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  impressions int(10) unsigned NOT NULL DEFAULT 0,
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (ad_id,day)
) {$charset};";

		dbDelta( $sql );
		update_option( 'snscs_flush_rewrite', 1 );
	}

	public static function add_rewrite_rules() {
		add_rewrite_rule( '^ad-click/([0-9]+)/?$', 'index.php?' . self::CLICK_QUERY_VAR . '=$matches[1]', 'top' );
		add_rewrite_rule( '^ads\.txt$', 'index.php?' . self::ADS_TXT_VAR . '=1', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::CLICK_QUERY_VAR;
		$vars[] = self::ADS_TXT_VAR;
		return $vars;
	}

	/* --------------------------------------------------------------------
	 * 広告データ
	 * ------------------------------------------------------------------ */

	/**
	 * 1つの広告の中身。
	 */
	public static function get( $id ) {
		$id = (int) $id;
		return array(
			'id'       => $id,
			'name'     => get_the_title( $id ),
			'type'     => (string) get_post_meta( $id, '_snscs_ad_type', true ) ?: self::TYPE_IMAGE,
			'slots'    => array_values( (array) get_post_meta( $id, '_snscs_ad_slots', true ) ),
			'image_id' => (int) get_post_meta( $id, '_snscs_ad_image_id', true ),
			'link'     => (string) get_post_meta( $id, '_snscs_ad_link', true ),
			'alt'      => (string) get_post_meta( $id, '_snscs_ad_alt', true ),
			'code'     => (string) get_post_meta( $id, '_snscs_ad_code', true ),
			'start'    => (string) get_post_meta( $id, '_snscs_ad_start', true ),
			'end'      => (string) get_post_meta( $id, '_snscs_ad_end', true ),
			'weight'   => max( 1, (int) get_post_meta( $id, '_snscs_ad_weight', true ) ),
			// コンテンツ販売のカード用。
			'platform' => (string) get_post_meta( $id, '_snscs_ad_platform', true ),
			'title'    => (string) get_post_meta( $id, '_snscs_ad_title', true ),
			'desc'     => (string) get_post_meta( $id, '_snscs_ad_desc', true ),
			'price'    => (string) get_post_meta( $id, '_snscs_ad_price', true ),
			// 内容に合わせて出す（1.21）: 検索の言葉・ジャンルに合うときだけ出す（空ならどこでも）。
			'keywords' => array_values( array_filter( explode( ',', (string) get_post_meta( $id, '_snscs_ad_keywords', true ) ) ) ),
			'genres'   => array_values( array_filter( explode( ',', (string) get_post_meta( $id, '_snscs_ad_genres', true ) ) ) ),
		);
	}

	/**
	 * 掲載中（公開状態）の広告すべて。表示のたびに読み込まないよう、まとめて覚えておく。
	 */
	public static function active_ads() {
		$ads = get_transient( self::CACHE_KEY );
		if ( false === $ads ) {
			$ids = get_posts(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'publish',
					'numberposts' => 200,
					'fields'      => 'ids',
				)
			);
			$ads = array_map( array( __CLASS__, 'get' ), $ids );
			set_transient( self::CACHE_KEY, $ads, HOUR_IN_SECONDS );
		}
		return $ads;
	}

	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/** いまのページの内容（検索の言葉・ジャンル。内容に合う広告を選ぶため）。 */
	private static $context = array( 'words' => array(), 'genres' => array() );

	/**
	 * 内容を伝える（検索ページ・発信者ページから）。
	 *
	 * @param string[] $words  言葉。
	 * @param string[] $genres ジャンルのスラッグ。
	 */
	public static function set_context( array $words, array $genres ) {
		self::$context = array(
			'words'  => array_values( array_filter( array_map( array( 'SNSCS_Text', 'normalize' ), $words ) ) ),
			'genres' => array_values( array_filter( array_map( 'sanitize_title', $genres ) ) ),
		);
	}

	/**
	 * 広告が、いまのページの内容に合うか。
	 */
	private static function matches( array $ad ) {
		foreach ( $ad['genres'] as $genre ) {
			if ( in_array( $genre, self::$context['genres'], true ) ) {
				return true;
			}
		}
		foreach ( array_filter( array_map( array( 'SNSCS_Text', 'normalize' ), $ad['keywords'] ) ) as $kw ) {
			foreach ( self::$context['words'] as $w ) {
				if ( false !== mb_strpos( $w, $kw ) || ( mb_strlen( $w ) >= 2 && false !== mb_strpos( $kw, $w ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * 押された割合の効き目（直近30日。画像・コンテンツ販売の広告だけ。ふつうの広告と比べた倍率 0.5〜2）。
	 *
	 * @return array<int,float>
	 */
	private static function ctr_factors() {
		$factors = get_transient( 'snscs_ad_ctr' );
		if ( is_array( $factors ) ) {
			return $factors;
		}
		$factors = array();
		$stats   = self::stats( 30 );
		$imp     = array_sum( wp_list_pluck( $stats, 'impressions' ) );
		$clk     = array_sum( wp_list_pluck( $stats, 'clicks' ) );
		$avg     = ( $clk + 1 ) / ( $imp + 100 );
		foreach ( $stats as $id => $s ) {
			$factors[ $id ] = min( 2.0, max( 0.5, ( ( $s['clicks'] + 100 * $avg ) / ( $s['impressions'] + 100 ) ) / $avg ) );
		}
		set_transient( 'snscs_ad_ctr', $factors, HOUR_IN_SECONDS );
		return $factors;
	}

	/**
	 * 枠に出す広告を1つ選ぶ（表示の割合に応じてランダム。同じページで出したものは除く）。
	 *
	 * 1.21: 言葉・ジャンルを決めた広告は、内容が合うページにだけ出し、合うときは優先する（8割はその中から選ぶ）。
	 *       よく押される広告ほど少し多く出す（画像・コンテンツ販売の広告。0.5〜2倍）。
	 */
	public static function pick( $slot ) {
		$today    = current_time( 'Y-m-d' );
		$targeted = array();
		$general  = array();
		$factors  = self::ctr_factors();

		foreach ( self::active_ads() as $ad ) {
			if ( ! in_array( $slot, $ad['slots'], true ) || in_array( $ad['id'], self::$shown, true ) ) {
				continue;
			}
			if ( ( $ad['start'] && $today < $ad['start'] ) || ( $ad['end'] && $today > $ad['end'] ) ) {
				continue;
			}
			$ad['score'] = $ad['weight'] * ( self::is_linked_type( $ad['type'] ) ? ( $factors[ $ad['id'] ] ?? 1.0 ) : 1.0 );
			if ( ! empty( $ad['keywords'] ) || ! empty( $ad['genres'] ) ) {
				if ( self::matches( $ad ) ) {
					$targeted[] = $ad;
				}
				continue;
			}
			$general[] = $ad;
		}

		$candidates = $targeted && ( ! $general || wp_rand( 1, 100 ) <= 80 ) ? $targeted : $general;
		if ( ! $candidates ) {
			return null;
		}
		$total = array_sum( wp_list_pluck( $candidates, 'score' ) );
		$roll  = wp_rand( 1, max( 1, (int) round( $total * 1000 ) ) ) / 1000;
		foreach ( $candidates as $ad ) {
			$roll -= $ad['score'];
			if ( $roll <= 0 ) {
				return $ad;
			}
		}
		return end( $candidates );
	}

	/* --------------------------------------------------------------------
	 * 表示
	 * ------------------------------------------------------------------ */

	/**
	 * 広告枠のHTML。出す広告が無い・枠がオフのときは空文字。
	 *
	 * @param string $slot  枠のキー。
	 * @param string $class 追加のクラス名。
	 * @return string
	 */
	public static function render( $slot, $class = '' ) {
		if ( ! self::slot_enabled( $slot ) ) {
			return '';
		}
		$ad = self::pick( $slot );
		if ( ! $ad ) {
			return '';
		}

		$body = self::body( $ad );
		if ( '' === $body ) {
			return '';
		}

		self::$shown[] = $ad['id'];
		self::count( $ad['id'], 'impressions' );
		wp_enqueue_style( 'snscs' );

		return sprintf(
			'<aside class="snscs-ad snscs-ad--%1$s snscs-ad--%2$s %3$s" aria-label="%4$s"><span class="snscs-ad__label">%4$s</span><div class="snscs-ad__body">%5$s</div></aside>',
			esc_attr( $slot ),
			esc_attr( $ad['type'] ),
			esc_attr( $class ),
			esc_attr( self::settings()['label'] ),
			$body
		);
	}

	private static function body( array $ad ) {
		if ( self::TYPE_IMAGE === $ad['type'] ) {
			$image = $ad['image_id'] ? wp_get_attachment_image_src( $ad['image_id'], 'full' ) : null;
			if ( ! $image || ! $ad['link'] ) {
				return '';
			}
			return sprintf(
				'<a href="%s" target="_blank" rel="sponsored noopener nofollow"><img src="%s" width="%d" height="%d" alt="%s" loading="lazy" decoding="async"></a>',
				esc_url( self::click_url( $ad['id'] ) ),
				esc_url( $image[0] ),
				(int) $image[1],
				(int) $image[2],
				esc_attr( $ad['alt'] )
			);
		}

		if ( self::TYPE_CONTENT === $ad['type'] ) {
			return self::content_card( $ad );
		}

		// HTMLコード・アドセンスは、サイト管理者が貼り付けたコードをそのまま出す（保存時に権限を確認済み）。
		return trim( $ad['code'] );
	}

	/**
	 * コンテンツ販売（Brain・Tips など）の商品カード。
	 */
	private static function content_card( array $ad ) {
		if ( ! $ad['link'] || '' === $ad['title'] ) {
			return '';
		}
		$platforms = self::content_platforms();
		$image     = $ad['image_id'] ? wp_get_attachment_image_src( $ad['image_id'], 'medium' ) : null;

		$html  = '<a class="snscs-ad-content" href="' . esc_url( self::click_url( $ad['id'] ) ) . '" target="_blank" rel="sponsored noopener nofollow">';
		if ( $image ) {
			$html .= sprintf( '<img class="snscs-ad-content__image" src="%s" width="%d" height="%d" alt="" loading="lazy" decoding="async">', esc_url( $image[0] ), (int) $image[1], (int) $image[2] );
		}
		$html .= '<span class="snscs-ad-content__body">';
		if ( isset( $platforms[ $ad['platform'] ] ) && 'other' !== $ad['platform'] ) {
			$html .= '<span class="snscs-ad-content__platform">' . esc_html( $platforms[ $ad['platform'] ] ) . '</span>';
		}
		$html .= '<strong class="snscs-ad-content__title">' . esc_html( $ad['title'] ) . '</strong>';
		if ( '' !== $ad['desc'] ) {
			$html .= '<span class="snscs-ad-content__desc">' . esc_html( $ad['desc'] ) . '</span>';
		}
		$html .= '<span class="snscs-ad-content__foot">';
		if ( '' !== $ad['price'] ) {
			$html .= '<span class="snscs-ad-content__price">' . esc_html( $ad['price'] ) . '</span>';
		}
		$html .= '<span class="snscs-ad-content__button">詳しく見る</span></span></span></a>';

		return $html;
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'slot' => '' ), $atts, 'sns_ad' );
		return self::render( sanitize_key( $atts['slot'] ) );
	}

	public static function click_url( $id ) {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/ad-click/' . (int) $id . '/' );
		}
		return add_query_arg( self::CLICK_QUERY_VAR, (int) $id, home_url( '/' ) );
	}

	/**
	 * サイト全体の <head> に入れるコード（アドセンスの確認コード・自動広告など）。
	 */
	public static function head_code() {
		$code = trim( self::settings()['head_code'] );
		if ( '' !== $code ) {
			echo $code . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 管理者が保存したコード。
		}
	}

	/**
	 * 画像広告のクリック（記録してリンク先へ）と、ads.txt の配信。
	 */
	public static function handle_requests() {
		if ( get_query_var( self::ADS_TXT_VAR ) ) {
			$content = trim( self::settings()['ads_txt'] );
			if ( '' === $content ) {
				status_header( 404 );
			}
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo $content . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- テキストとして配信。
			exit;
		}

		$id = (int) get_query_var( self::CLICK_QUERY_VAR );
		if ( ! $id ) {
			return;
		}

		$ad = self::POST_TYPE === get_post_type( $id ) && 'publish' === get_post_status( $id ) ? self::get( $id ) : null;
		if ( ! $ad || ! self::is_linked_type( $ad['type'] ) || ! $ad['link'] ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		self::count( $id, 'clicks' );
		nocache_headers();
		// リンク先は外部サイト。サイト管理者が登録したURLなので wp_redirect を使う。
		wp_redirect( $ad['link'], 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/* --------------------------------------------------------------------
	 * 集計
	 * ------------------------------------------------------------------ */

	/**
	 * 表示回数・クリック数を1つ増やす。ロボットとサイト管理者は数えない。
	 */
	private static function count( $id, $column ) {
		global $wpdb;

		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $agent || preg_match( '/bot|crawl|spider|slurp|preview|headless|curl|wget|python/i', $agent ) || current_user_can( 'manage_options' ) ) {
			return;
		}

		$table  = self::table();
		$column = 'clicks' === $column ? 'clicks' : 'impressions';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (ad_id, day, {$column}) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE {$column} = {$column} + 1", $id, current_time( 'Y-m-d' ) ) );
	}

	/**
	 * 直近 $days 日の表示回数・クリック数（広告ID => [impressions, clicks]）。
	 */
	public static function stats( $days = 30 ) {
		global $wpdb;

		$table = self::table();
		$since = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . ( $days - 1 ) . ' days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ad_id, SUM(impressions) AS impressions, SUM(clicks) AS clicks FROM {$table} WHERE day >= %s GROUP BY ad_id", $since ), ARRAY_A );

		$stats = array();
		foreach ( $rows as $row ) {
			$stats[ (int) $row['ad_id'] ] = array(
				'impressions' => (int) $row['impressions'],
				'clicks'      => (int) $row['clicks'],
			);
		}
		return $stats;
	}
}
