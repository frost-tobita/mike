<?php
/**
 * 人気・急上昇の検索キーワード（全体とジャンルごと）。
 *
 * 記録するのは「結果が1人以上あったキーワード」の「日ごとの人数」だけ（誰が調べたかは残さない）。
 * 同じ人が同じ日に同じ言葉を何度調べても1人として数える（重複防止の記録は2日で消す）。
 * 表に出すのは、設定した人数以上が調べた言葉だけ。メールアドレス・URL・長い数字（電話番号など）や
 * 管理画面で指定した言葉は出さない。
 *
 *   人気     : 直近7日に調べた人数の多い順
 *   急上昇   : 直近2日の人数 ÷ (その前5日の1日平均 × 2 ＋ 1) の大きい順
 *
 * ジャンルごとのトレンド: 検索でジャンルを選んでいればそのジャンル、選んでいなければ
 * 検索結果の上位10人の半分以上（3人以上。結果が3人未満なら全員）が同じジャンルのとき、そのジャンルの言葉としても数える。
 * トレンドのページ（[sns_trends]）では、X の「トレンド」のように総合とジャンル別の順位を出す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Trends {

	const KEEP_DAYS = 60;
	const CACHE     = 'snscs_trends';
	const VERSION   = 2; // キャッシュの形を変えたら上げる。
	const TAB_PARAM = 'snscs_tg';

	public static function init() {
		add_shortcode( 'sns_trending', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'sns_trends', array( __CLASS__, 'page_shortcode' ) );
		add_action( SNSCS_Scores::CRON_HOOK, array( __CLASS__, 'purge_old' ) );
	}

	public static function terms_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_search_terms';
	}

	public static function genre_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_search_genre_terms';
	}

	public static function seen_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_search_seen';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$terms   = self::terms_table();
		$genre   = self::genre_table();
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
			"CREATE TABLE {$genre} (
  day date NOT NULL,
  genre varchar(200) NOT NULL,
  term varchar(64) NOT NULL,
  visitors int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,genre,term),
  KEY genre_day (genre,day)
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
	 * 検索がどのジャンルの話題か（選んだジャンル、なければ結果の上位の発信者に多いジャンル）。
	 *
	 * @param array $args  SNSCS_Search::normalize_args() の結果。
	 * @param array $items 検索結果（SNSCS_Search::format_creator() の配列）。
	 * @return string ジャンルのスラッグ（決まらなければ空文字）。
	 */
	public static function genre_of( $args, $items ) {
		if ( '' !== $args['genre'] ) {
			return get_term_by( 'slug', $args['genre'], SNSCS_Post_Types::TAX_GENRE ) ? $args['genre'] : '';
		}
		$ids = array_slice( array_map( 'intval', wp_list_pluck( (array) $items, 'id' ) ), 0, 10 );
		if ( ! $ids ) {
			return '';
		}
		$terms = wp_get_object_terms( $ids, SNSCS_Post_Types::TAX_GENRE, array( 'fields' => 'all_with_object_id' ) );
		if ( is_wp_error( $terms ) ) {
			return '';
		}
		$owners = array();
		foreach ( $terms as $term ) {
			$owners[ $term->slug ][ $term->object_id ] = true;
		}
		$best  = '';
		$count = 0;
		foreach ( $owners as $slug => $people ) {
			if ( count( $people ) > $count ) {
				$best  = $slug;
				$count = count( $people );
			}
		}
		return ( $count >= min( 3, count( $ids ) ) && $count * 2 >= count( $ids ) ) ? $best : '';
	}

	/**
	 * 検索を1件記録する（検索ページの1ページ目で、結果があったときだけ呼ぶ）。
	 *
	 * @param string $genre この検索のジャンル（genre_of() の結果）。
	 */
	public static function record( $q, $total, $genre = '' ) {
		global $wpdb;
		$term = self::normalize( $q );
		if ( '' === $term || $total < 1 || SNSCS_Referrals::is_bot_request() || current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		$day     = current_time( 'Y-m-d' );
		$seen    = self::seen_table();
		$visitor = SNSCS_Referrals::visitor_hash();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$new = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$seen} (day, term_hash, visitor_hash) VALUES (%s, %s, %s)", $day, md5( $term ), $visitor ) );
		if ( $new ) {
			$terms = self::terms_table();
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$terms} (day, term, visitors) VALUES (%s, %s, 1) ON DUPLICATE KEY UPDATE visitors = visitors + 1", $day, $term ) );
		}
		// ジャンルごとの人数も、ジャンルごとに1日1人として数える。
		if ( '' !== $genre ) {
			$genre = sanitize_title( $genre );
			$new   = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$seen} (day, term_hash, visitor_hash) VALUES (%s, %s, %s)", $day, md5( $genre . '|' . $term ), $visitor ) );
			if ( $new ) {
				$table = self::genre_table();
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (day, genre, term, visitors) VALUES (%s, %s, %s, 1) ON DUPLICATE KEY UPDATE visitors = visitors + 1", $day, $genre, $term ) );
			}
		}
		// phpcs:enable
	}

	public static function purge_old() {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		$old   = gmdate( 'Y-m-d', strtotime( $today . ' -' . self::KEEP_DAYS . ' days' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::seen_table() . ' WHERE day < %s', gmdate( 'Y-m-d', strtotime( $today . ' -2 days' ) ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::terms_table() . ' WHERE day < %s', $old ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::genre_table() . ' WHERE day < %s', $old ) );
		// phpcs:enable
	}

	/**
	 * 表に出してよい言葉か（管理画面の「表示しない言葉」を含まない）。
	 */
	public static function allowed( $term ) {
		foreach ( preg_split( '/\R/u', (string) SNSCS_Hub::setting( 'trend_block' ) ) as $word ) {
			$word = trim( mb_strtolower( $word ) );
			if ( '' !== $word && false !== mb_strpos( $term, $word ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 人気・急上昇の言葉と人数（10分ごとに計算し直す）。
	 *
	 * @param string $genre ジャンルのスラッグ（空文字なら全体）。
	 * @return array{popular:array<string,int>,rising:array<string,int>,hot:string[]} 言葉 => 直近7日の人数
	 */
	public static function stats( $genre = '' ) {
		$key    = self::CACHE . ( '' === $genre ? '' : '_g_' . md5( $genre ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['v'] ) && self::VERSION === $cached['v'] ) {
			return $cached['lists'];
		}
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		$min   = max( 1, (int) SNSCS_Hub::setting( 'trend_min_visitors' ) );
		$since = function ( $days ) use ( $today ) {
			return gmdate( 'Y-m-d', strtotime( $today . ' -' . ( $days - 1 ) . ' days' ) );
		};
		if ( '' === $genre ) {
			$from  = self::terms_table() . ' WHERE day >= %s';
			$extra = array();
		} else {
			$from  = self::genre_table() . ' WHERE genre = %s AND day >= %s';
			$extra = array( $genre );
		}
		$recent = 'SUM(CASE WHEN day >= %s THEN visitors ELSE 0 END)';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$popular = $wpdb->get_results(
			$wpdb->prepare( "SELECT term, SUM(visitors) AS n FROM {$from} GROUP BY term HAVING n >= %d ORDER BY n DESC, term ASC LIMIT 60", array_merge( $extra, array( $since( 7 ), $min ) ) ),
			ARRAY_A
		);
		$rising  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term, SUM(visitors) AS n, {$recent} AS r FROM {$from} GROUP BY term
				HAVING r >= %d
				ORDER BY {$recent} / (SUM(CASE WHEN day < %s THEN visitors ELSE 0 END) / 5 * 2 + 1) DESC, term ASC LIMIT 60",
				array_merge( array( $since( 2 ) ), $extra, array( $since( 7 ), $min, $since( 2 ), $since( 2 ) ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		$pick  = function ( $rows ) {
			$out = array();
			foreach ( (array) $rows as $row ) {
				if ( self::allowed( $row['term'] ) ) {
					$out[ (string) $row['term'] ] = (int) $row['n'];
				}
			}
			return $out;
		};
		// 本当に増えている言葉（ここ2日の人数が、ふだんの2日分の1.5倍＋1人より多い）に「急上昇」の印を付ける。
		$hot = array();
		foreach ( (array) $rising as $row ) {
			if ( (int) $row['r'] > ( (int) $row['n'] - (int) $row['r'] ) / 5 * 2 * 1.5 + 1 && self::allowed( $row['term'] ) ) {
				$hot[] = (string) $row['term'];
			}
		}
		$lists = array(
			'popular' => $pick( $popular ),
			'rising'  => $pick( $rising ),
			'hot'     => $hot,
		);
		set_transient( $key, array( 'v' => self::VERSION, 'lists' => $lists ), 10 * MINUTE_IN_SECONDS );
		return $lists;
	}

	/**
	 * 人気・急上昇のキーワード。
	 *
	 * @param string $genre ジャンルのスラッグ（空文字なら全体）。
	 * @return array{popular:string[],rising:string[]}
	 */
	public static function lists( $limit = 10, $genre = '' ) {
		$stats = self::stats( $genre );
		return array(
			'popular' => array_slice( array_keys( $stats['popular'] ), 0, $limit ),
			'rising'  => array_slice( array_keys( $stats['rising'] ), 0, $limit ),
		);
	}

	/**
	 * X のトレンドのような順位（ここ2日に調べられた言葉を伸びの大きい順に、足りなければ7日間の人気の順で埋める）。
	 *
	 * @return array<int,array{term:string,visitors:int,hot:bool}>
	 */
	public static function ranking( $genre = '', $limit = 20 ) {
		$stats = self::stats( $genre );
		$rows  = array();
		foreach ( array( $stats['rising'], $stats['popular'] ) as $list ) {
			foreach ( $list as $term => $visitors ) {
				if ( count( $rows ) >= $limit ) {
					break 2;
				}
				if ( ! isset( $rows[ $term ] ) ) {
					$rows[ $term ] = array( 'term' => (string) $term, 'visitors' => $visitors, 'hot' => in_array( (string) $term, $stats['hot'], true ) );
				}
			}
		}
		return array_values( $rows );
	}

	/**
	 * トレンドのあるジャンル（トレンドの多い順）。
	 *
	 * @return WP_Term[]
	 */
	public static function genres_with_trends() {
		$terms = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$found = array();
		foreach ( $terms as $term ) {
			$stats = self::stats( $term->slug );
			$total = array_sum( $stats['popular'] );
			if ( $total ) {
				$found[] = array( $total, $term );
			}
		}
		usort(
			$found,
			function ( $a, $b ) {
				return $b[0] - $a[0];
			}
		);
		return wp_list_pluck( $found, 1 );
	}

	/**
	 * キーワードで探すURL（ジャンルを付けると、そのジャンルの中で探す）。
	 */
	public static function search_link( $term, $genre = '' ) {
		$args = array( 'snscs_q' => rawurlencode( $term ) );
		if ( '' !== $genre ) {
			$args['snscs_genre'] = $genre;
		}
		return add_query_arg( $args, snscs_search_url() );
	}

	/**
	 * トレンドのページのURL（ページが無ければ空文字）。
	 */
	public static function page_url( $genre = '' ) {
		$url = SNSCS_Hub::page_url( 'trends' );
		if ( '' === $url ) {
			return '';
		}
		return '' === $genre ? $url : add_query_arg( self::TAB_PARAM, $genre, $url );
	}

	/**
	 * キーワードのリンク一覧。
	 *
	 * @param string $genre ジャンルのスラッグ（空文字なら全体）。
	 */
	public static function render( $heading = '人気のキーワード', $genre = '' ) {
		$lists = self::lists( 10, $genre );
		// 急上昇の欄には、本当に増えている言葉だけを出す。
		$lists['rising'] = self::stats( $genre )['hot'];
		if ( ! $lists['popular'] && ! $lists['rising'] ) {
			return '';
		}
		wp_enqueue_style( 'snscs' );
		$chip = function ( $term, $class = '' ) use ( $genre ) {
			return sprintf( '<li><a class="snscs-chip%s" href="%s">%s</a></li>', $class, esc_url( self::search_link( $term, $genre ) ), esc_html( $term ) );
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
		$more = self::page_url( $genre );
		if ( $more ) {
			$html .= '<p class="snscs-trends__more"><a href="' . esc_url( $more ) . '">トレンドをすべて見る</a></p>';
		}
		return $html . '</section>';
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'title' => '人気のキーワード', 'genre' => '' ), $atts, 'sns_trending' );
		return self::render( sanitize_text_field( $atts['title'] ), sanitize_title( $atts['genre'] ) );
	}

	/**
	 * 順位の一覧（1行 = 順位・ジャンル・キーワード・人数）。
	 */
	private static function render_rows( $rows, $genre, $label, $start = 1 ) {
		$html = '<ol class="snscs-trendlist" start="' . (int) $start . '">';
		$rank = $start;
		foreach ( $rows as $row ) {
			$meta  = $rank . ' · ' . $label . ( $row['hot'] ? ' · 急上昇' : '' );
			$html .= '<li class="snscs-trendlist__item"><a class="snscs-trendlist__link" href="' . esc_url( SNSCS_Trend_Pages::url( $row['term'], $genre ) ) . '">'
				. '<span class="snscs-trendlist__meta">' . esc_html( $meta ) . '</span>'
				. '<span class="snscs-trendlist__term' . ( $row['hot'] ? ' snscs-trendlist__term--hot' : '' ) . '">' . esc_html( $row['term'] ) . '</span>'
				. '<span class="snscs-trendlist__count">' . esc_html( sprintf( '%s人が検索（7日間）', number_format_i18n( $row['visitors'] ) ) ) . '</span>'
				. '</a></li>';
			++$rank;
		}
		return $html . '</ol>';
	}

	/**
	 * トレンドのページ（[sns_trends]）。上に「総合」と各ジャンルのタブ、下に順位。
	 */
	public static function page_shortcode() {
		SNSCS_Hub::remember_page( 'trends' );
		wp_enqueue_style( 'snscs' );
		if ( SNSCS_Trend_Pages::current() ) {
			return SNSCS_Trend_Pages::render( SNSCS_Trend_Pages::current() );
		}
		$genres  = self::genres_with_trends();
		$current = isset( $_GET[ self::TAB_PARAM ] ) ? sanitize_title( wp_unslash( $_GET[ self::TAB_PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示の切り替えだけ。
		$term    = '' === $current ? null : get_term_by( 'slug', $current, SNSCS_Post_Types::TAX_GENRE );
		if ( ! $term ) {
			$current = '';
		}
		$base = self::page_url();
		$base = $base ? $base : get_permalink();

		$html  = '<div class="snscs-trendpage">';
		$html .= '<nav class="snscs-trendtabs" aria-label="トレンドのジャンル"><ul>';
		$html .= '<li><a class="snscs-trendtabs__tab' . ( '' === $current ? ' is-current' : '' ) . '" href="' . esc_url( $base ) . '"' . ( '' === $current ? ' aria-current="page"' : '' ) . '>総合</a></li>';
		$tabs = $genres;
		if ( $term && ! in_array( $term->slug, wp_list_pluck( $genres, 'slug' ), true ) ) {
			$tabs[] = $term;
		}
		foreach ( $tabs as $tab ) {
			$on    = $tab->slug === $current;
			$html .= '<li><a class="snscs-trendtabs__tab' . ( $on ? ' is-current' : '' ) . '" href="' . esc_url( add_query_arg( self::TAB_PARAM, $tab->slug, $base ) ) . '"' . ( $on ? ' aria-current="page"' : '' ) . '>' . esc_html( $tab->name ) . '</a></li>';
		}
		$html .= '</ul></nav>';

		$label = $term ? $term->name . 'のトレンド' : '総合トレンド';
		$rows  = self::ranking( $current, 20 );
		$html .= '<h2 class="snscs-trendpage__title">' . esc_html( $label ) . '</h2>';
		if ( $rows ) {
			$html .= self::render_rows( $rows, $current, $term ? $term->name : 'トレンド' );
		} else {
			$html .= '<p class="snscs-trendpage__empty">' . esc_html( $term ? 'このジャンルのトレンドは、まだありません。検索が増えると表示されます。' : 'トレンドは、まだありません。検索が増えると表示されます。' ) . '</p>';
		}

		// 総合のタブでは、ジャンルごとの上位3つも並べる（X の「ジャンル別のトレンド」のように）。
		if ( ! $term && $genres ) {
			$html .= '<h2 class="snscs-trendpage__title">ジャンル別のトレンド</h2><div class="snscs-trendgrid">';
			foreach ( array_slice( $genres, 0, 12 ) as $genre ) {
				$top = self::ranking( $genre->slug, 3 );
				if ( ! $top ) {
					continue;
				}
				$html .= '<section class="snscs-trendcard"><h3 class="snscs-trendcard__title">' . esc_html( $genre->name ) . '</h3>'
					. self::render_rows( $top, $genre->slug, $genre->name )
					. '<a class="snscs-trendcard__more" href="' . esc_url( add_query_arg( self::TAB_PARAM, $genre->slug, $base ) ) . '">' . esc_html( $genre->name ) . 'のトレンドをもっと見る</a></section>';
			}
			$html .= '</div>';
		}
		$html .= '<p class="snscs-trendpage__note">直近の検索から、調べた人の数をもとに10分ごとに更新しています（同じ人の同じ日の検索は1回と数えます）。「急上昇」は、ここ2日で急に調べる人が増えた言葉です。</p>';
		return $html . '</div>';
	}
}
