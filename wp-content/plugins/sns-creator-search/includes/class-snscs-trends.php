<?php
/**
 * 人気・急上昇の検索キーワード。
 *
 * 記録するのは「結果が1人以上あったキーワード」の「日ごとの人数」だけ（誰が調べたかは残さない）。
 * 同じ人が同じ日に同じ言葉を何度調べても1人として数える（重複防止の記録は2日で消す）。
 * 表に出すのは、設定した人数以上が調べた言葉だけ。メールアドレス・URL・長い数字（電話番号など）や
 * 管理画面で指定した言葉は出さない。
 *
 *   人気     : 直近7日に調べた人数の多い順
 *   急上昇   : 直近2日の人数 ÷ (その前5日の1日平均 × 2 ＋ 1) の大きい順
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Trends {

	const KEEP_DAYS = 60;
	const CACHE     = 'snscs_trends';

	public static function init() {
		add_shortcode( 'sns_trending', array( __CLASS__, 'shortcode' ) );
		add_action( SNSCS_Scores::CRON_HOOK, array( __CLASS__, 'purge_old' ) );
	}

	public static function terms_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_search_terms';
	}

	public static function seen_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_search_seen';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$terms   = self::terms_table();
		$seen    = self::seen_table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$terms} (
  day date NOT NULL,
  term varchar(64) NOT NULL,
  visitors int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,term),
  KEY term (term)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$seen} (
  day date NOT NULL,
  term_hash char(32) NOT NULL,
  visitor_hash char(64) NOT NULL,
  PRIMARY KEY  (day,term_hash,visitor_hash)
) {$charset};"
		);
	}

	/**
	 * キーワードをそろえる（全角英数字→半角、半角カナ→全角、小文字、空白を1つに）。
	 * 表に出すべきでない形なら空文字。
	 */
	public static function normalize( $q ) {
		$q = mb_convert_kana( (string) $q, 'asKV', 'UTF-8' );
		$q = mb_strtolower( trim( preg_replace( '/\s+/u', ' ', $q ) ), 'UTF-8' );
		if ( mb_strlen( $q ) < 2 || mb_strlen( $q ) > 30 ) {
			return '';
		}
		// メールアドレス・URL・長い数字（電話番号など）は記録しない。
		if ( preg_match( '/@|https?:|www\.|\.(com|jp|net)|\d{5,}/i', $q ) ) {
			return '';
		}
		return $q;
	}

	/**
	 * 検索を1件記録する（検索ページの1ページ目で、結果があったときだけ呼ぶ）。
	 */
	public static function record( $q, $total ) {
		global $wpdb;
		$term = self::normalize( $q );
		if ( '' === $term || $total < 1 || SNSCS_Referrals::is_bot_request() || current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		$day  = current_time( 'Y-m-d' );
		$seen = self::seen_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$new = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$seen} (day, term_hash, visitor_hash) VALUES (%s, %s, %s)", $day, md5( $term ), SNSCS_Referrals::visitor_hash() ) );
		if ( $new ) {
			$terms = self::terms_table();
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$terms} (day, term, visitors) VALUES (%s, %s, 1) ON DUPLICATE KEY UPDATE visitors = visitors + 1", $day, $term ) );
		}
		// phpcs:enable
	}

	public static function purge_old() {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::seen_table() . ' WHERE day < %s', gmdate( 'Y-m-d', strtotime( $today . ' -2 days' ) ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::terms_table() . ' WHERE day < %s', gmdate( 'Y-m-d', strtotime( $today . ' -' . self::KEEP_DAYS . ' days' ) ) ) );
		// phpcs:enable
	}

	/**
	 * 表に出してよい言葉か（管理画面の「表示しない言葉」を含まない）。
	 */
	private static function allowed( $term ) {
		foreach ( preg_split( '/\R/u', (string) SNSCS_Hub::setting( 'trend_block' ) ) as $word ) {
			$word = trim( mb_strtolower( $word ) );
			if ( '' !== $word && false !== mb_strpos( $term, $word ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 人気・急上昇のキーワード（10分ごとに計算し直す）。
	 *
	 * @return array{popular:string[],rising:string[]}
	 */
	public static function lists( $limit = 10 ) {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$table = self::terms_table();
		$today = current_time( 'Y-m-d' );
		$min   = max( 1, (int) SNSCS_Hub::setting( 'trend_min_visitors' ) );
		$since = function ( $days ) use ( $today ) {
			return gmdate( 'Y-m-d', strtotime( $today . ' -' . ( $days - 1 ) . ' days' ) );
		};

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$popular = $wpdb->get_col( $wpdb->prepare( "SELECT term FROM {$table} WHERE day >= %s GROUP BY term HAVING SUM(visitors) >= %d ORDER BY SUM(visitors) DESC, term ASC LIMIT 60", $since( 7 ), $min ) );
		$rising  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT term FROM {$table} WHERE day >= %s GROUP BY term
				HAVING SUM(CASE WHEN day >= %s THEN visitors ELSE 0 END) >= %d
				ORDER BY SUM(CASE WHEN day >= %s THEN visitors ELSE 0 END) / (SUM(CASE WHEN day < %s THEN visitors ELSE 0 END) / 5 * 2 + 1) DESC, term ASC LIMIT 60",
				$since( 7 ),
				$since( 2 ),
				$min,
				$since( 2 ),
				$since( 2 )
			)
		);
		// phpcs:enable

		$lists = array(
			'popular' => array_slice( array_values( array_filter( $popular, array( __CLASS__, 'allowed' ) ) ), 0, $limit ),
			'rising'  => array_slice( array_values( array_filter( $rising, array( __CLASS__, 'allowed' ) ) ), 0, $limit ),
		);
		set_transient( self::CACHE, $lists, 10 * MINUTE_IN_SECONDS );
		return $lists;
	}

	/**
	 * キーワードのリンク一覧。
	 */
	public static function render( $heading = '人気のキーワード' ) {
		$lists = self::lists();
		if ( ! $lists['popular'] && ! $lists['rising'] ) {
			return '';
		}
		wp_enqueue_style( 'snscs' );
		$chip = function ( $term, $class = '' ) {
			return sprintf( '<li><a class="snscs-chip%s" href="%s">%s</a></li>', $class, esc_url( add_query_arg( 'snscs_q', rawurlencode( $term ), snscs_search_url() ) ), esc_html( $term ) );
		};
		$html = '<section class="snscs-trends" aria-label="' . esc_attr( $heading ) . '">';
		if ( $lists['rising'] ) {
			$html .= '<h2 class="snscs-trends__title">急上昇のキーワード</h2><ul class="snscs-chips">';
			foreach ( array_slice( $lists['rising'], 0, 6 ) as $term ) {
				$html .= $chip( $term, ' snscs-chip--hot' );
			}
			$html .= '</ul>';
		}
		if ( $lists['popular'] ) {
			$html .= '<h2 class="snscs-trends__title">' . esc_html( $heading ) . '</h2><ul class="snscs-chips">';
			foreach ( $lists['popular'] as $term ) {
				$html .= $chip( $term );
			}
			$html .= '</ul>';
		}
		return $html . '</section>';
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'title' => '人気のキーワード' ), $atts, 'sns_trending' );
		return self::render( sanitize_text_field( $atts['title'] ) );
	}
}
