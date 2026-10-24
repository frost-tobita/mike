<?php
/**
 * 検索結果の画面の改良（1.21）。
 *
 * - 件数つきの絞り込み: ジャンル・地域・フォロワー数の選択肢に「料理（35）」のように人数を出す（いまの条件のまま数える）
 * - なぜこの人？: カードに理由（「キャンプ」がタグに一致・この1か月でフォロワー+12%・この言葉でよく選ばれています など）を出し、
 *   紹介文の、言葉に合ったところを色付けして、その前後を見せる
 * - 比べる: カードの「比べる」で2〜4人（企業向けプランは10人）を選び、フォロワー数・伸び率・記事の数・相談の受付などを横に並べる
 * - 検索を保存してお知らせ: メンバーは条件を保存でき、その条件に新しい発信者が加わると、お知らせ（とメール・プッシュ）で届ける
 *   （1人5件まで。企業向けプランは30件。1日1回調べる）
 * - 読み解いた条件のお知らせ（「大阪府・Instagram・フォロワー1万人以上」で絞り込みました）と、入力したとおりに探すリンク
 * - この条件のまとめページ（特集ページ）へのリンク、AIの言い換えの言葉
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Search_UI {

	const MAX_SAVED       = 5;
	const COMPARE_MAX     = 4;
	const COMPARE_PARAM   = 'snscs_compare';
	const FOLLOWER_BANDS  = array( 1000 => '1,000人以上', 10000 => '1万人以上', 100000 => '10万人以上' );

	public static function init() {
		add_action( 'admin_post_snscs_save_search', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_snscs_delete_search', array( __CLASS__, 'handle_delete' ) );
		add_action( 'snscs_inbox_cron', array( __CLASS__, 'check_saved' ), 30 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'delete_user', array( __CLASS__, 'delete_for_user' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_saved_searches';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  args text NOT NULL,
  label varchar(200) NOT NULL DEFAULT '',
  last_check datetime NOT NULL,
  last_new int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY last_check (last_check)
) {$charset};"
		);
	}

	/* --------------------------------------------------------------------
	 * 共通
	 * ------------------------------------------------------------------ */

	/**
	 * 検索条件 => 検索ページのURL。
	 */
	public static function search_url( array $args ) {
		$params = array();
		foreach ( SNSCS_Frontend::PARAMS as $param => $key ) {
			if ( in_array( $key, array( 'page' ), true ) || empty( $args[ $key ] ) || ( 'sort' === $key && 'recommended' === $args[ $key ] ) ) {
				continue;
			}
			$params[ $param ] = rawurlencode( (string) $args[ $key ] );
		}
		return add_query_arg( $params, snscs_search_url() );
	}

	/**
	 * 条件を人が読める言葉に（「キャンプ」・Instagram・東京都・フォロワー1万人以上）。
	 */
	public static function describe_args( array $args ) {
		$parts = array();
		if ( ! empty( $args['q'] ) ) {
			$parts[] = '「' . $args['q'] . '」';
		}
		if ( ! empty( $args['platform'] ) ) {
			$parts[] = snscs_platform_label( $args['platform'] );
		}
		foreach ( array( 'genre' => SNSCS_Post_Types::TAX_GENRE, 'region' => SNSCS_Post_Types::TAX_REGION ) as $key => $tax ) {
			if ( ! empty( $args[ $key ] ) ) {
				$term    = get_term_by( 'slug', $args[ $key ], $tax );
				$parts[] = $term ? $term->name : $args[ $key ];
			}
		}
		if ( ! empty( $args['min_followers'] ) ) {
			$parts[] = 'フォロワー' . snscs_format_count( $args['min_followers'] ) . '人以上';
		}
		if ( ! empty( $args['max_followers'] ) ) {
			$parts[] = 'フォロワー' . snscs_format_count( $args['max_followers'] ) . '人以下';
		}
		return $parts ? implode( '・', $parts ) : 'すべての発信者';
	}

	private static function facet_args( array $args ) {
		return array_diff_key( $args, array_flip( array( 'page', 'per_page', 'sort', 'personal_order', 'exclude' ) ) );
	}

	/* --------------------------------------------------------------------
	 * 読み解いた条件・まとめページ・言い換え
	 * ------------------------------------------------------------------ */

	public static function understood_html( array $result ) {
		if ( empty( $result['understood'] ) ) {
			return '';
		}
		$q     = $result['args']['q'];
		$exact = add_query_arg( 'snscs_exact', 1, remove_query_arg( array( 'snscs_page', 'snscs_genre', 'snscs_region', 'snscs_platform', 'snscs_min', 'snscs_max' ) ) );
		return '<p class="snscs-understood" role="status"><span class="snscs-understood__ico" aria-hidden="true">✨</span>「' . esc_html( $result['original_q'] ) . '」を、条件<strong>「' . esc_html( implode( '・', $result['understood'] ) ) . '」</strong>' . ( '' !== $q ? 'と言葉「' . esc_html( $q ) . '」' : '' ) . 'で探しました。<a href="' . esc_url( $exact ) . '">入力したとおりに探す</a></p>';
	}

	/**
	 * 今の条件がまとめページ（特集）になっていれば、そのリンク。
	 */
	public static function feature_link_html( array $args ) {
		if ( '' !== $args['q'] || $args['min_followers'] || $args['max_followers'] || ( '' === $args['platform'] && '' === $args['genre'] && '' === $args['region'] ) ) {
			return '';
		}
		$genre  = '' !== $args['genre'] ? get_term_by( 'slug', $args['genre'], SNSCS_Post_Types::TAX_GENRE ) : null;
		$region = '' !== $args['region'] ? get_term_by( 'slug', $args['region'], SNSCS_Post_Types::TAX_REGION ) : null;
		$key    = $args['platform'] . '|' . ( $genre ? $genre->term_id : '' ) . '|' . ( $region ? $region->term_id : '' );
		$combos = SNSCS_Features::combos();
		if ( ! SNSCS_Hub::page_url( 'features' ) || ( $combos[ $key ] ?? 0 ) < max( 3, (int) SNSCS_Hub::setting( 'feature_min' ) ) ) {
			return '';
		}
		$label = implode( ' × ', array_filter( array( $genre ? $genre->name : '', '' !== $args['platform'] ? snscs_platform_label( $args['platform'] ) : '', $region ? $region->name : '' ) ) );
		return '<p class="snscs-featurelink"><a href="' . esc_url( SNSCS_Features::url( $args['platform'], $genre ? $genre->slug : '', $region ? $region->slug : '' ) ) . '">📚 ' . esc_html( $label ) . 'の発信者まとめ（' . esc_html( number_format_i18n( $combos[ $key ] ) ) . '人）を見る</a></p>';
	}

	/**
	 * AIが作った、意味の近い言葉（押すとその言葉で探す）。
	 */
	public static function related_words_html( array $args ) {
		if ( '' === $args['q'] ) {
			return '';
		}
		$words = array();
		foreach ( array_slice( preg_split( '/\s+/u', $args['q'], -1, PREG_SPLIT_NO_EMPTY ), 0, 2 ) as $word ) {
			$words = array_merge( $words, SNSCS_Query::expansions( $word ) );
		}
		$words = array_slice( array_values( array_unique( $words ) ), 0, 8 );
		if ( ! $words ) {
			return '';
		}
		$html = '<div class="snscs-related"><span class="snscs-related__label">意味の近い言葉</span><ul class="snscs-chips">';
		foreach ( $words as $word ) {
			$html .= '<li><a class="snscs-chip" href="' . esc_url( add_query_arg( 'snscs_q', rawurlencode( $word ), remove_query_arg( array( 'snscs_page', 'snscs_q' ) ) ) ) . '">' . esc_html( $word ) . '</a></li>';
		}
		return $html . '</ul></div>';
	}

	/**
	 * よく探されている条件（人気のキーワードを条件に読み解けたもの）→ まとめページへのリンク。
	 * 検索エンジンから来る人の入口を、実際に探されている組み合わせから増やす。
	 */
	public static function popular_conditions_html( $limit = 12 ) {
		$cached = get_transient( 'snscs_popular_conditions' );
		if ( ! is_array( $cached ) ) {
			$cached = array();
			$combos = SNSCS_Features::combos();
			$min    = max( 3, (int) SNSCS_Hub::setting( 'feature_min' ) );
			foreach ( SNSCS_Trends::lists( 60 )['popular'] as $term ) {
				$parsed = SNSCS_Query::parse( $term );
				if ( ! $parsed || '' !== $parsed['q'] || isset( $parsed['set']['min_followers'] ) || isset( $parsed['set']['max_followers'] ) ) {
					continue;
				}
				$set    = $parsed['set'] + array( 'platform' => '', 'genre' => '', 'region' => '' );
				$genre  = '' !== $set['genre'] ? get_term_by( 'slug', $set['genre'], SNSCS_Post_Types::TAX_GENRE ) : null;
				$region = '' !== $set['region'] ? get_term_by( 'slug', $set['region'], SNSCS_Post_Types::TAX_REGION ) : null;
				$key    = $set['platform'] . '|' . ( $genre ? $genre->term_id : '' ) . '|' . ( $region ? $region->term_id : '' );
				if ( isset( $cached[ $key ] ) || ( $combos[ $key ] ?? 0 ) < $min ) {
					continue;
				}
				$cached[ $key ] = array( 'url' => SNSCS_Features::url( $set['platform'], $genre ? $genre->slug : '', $region ? $region->slug : '' ), 'label' => implode( ' × ', $parsed['labels'] ), 'count' => (int) $combos[ $key ] );
			}
			set_transient( 'snscs_popular_conditions', $cached, HOUR_IN_SECONDS );
		}
		$cached = array_slice( $cached, 0, $limit );
		if ( ! $cached ) {
			return '';
		}
		$html = '<section class="snscs-feature-links"><h2 class="snscs-feature-links__title">よく探されている条件</h2><ul class="snscs-chips">';
		foreach ( $cached as $c ) {
			$html .= '<li><a class="snscs-chip" href="' . esc_url( $c['url'] ) . '">' . esc_html( $c['label'] ) . ' <span class="snscs-chip__count">' . esc_html( number_format_i18n( $c['count'] ) ) . '人</span></a></li>';
		}
		return $html . '</ul></section>';
	}

	/* --------------------------------------------------------------------
	 * 件数つきの絞り込み
	 * ------------------------------------------------------------------ */

	/**
	 * @return array{genre:array<int,int>,region:array<int,int>,followers:array<int,int>}
	 */
	public static function facets( array $args ) {
		$backend = SNSCS_Search::backend();
		if ( ! method_exists( $backend, 'count_by_term' ) ) {
			return array( 'genre' => array(), 'region' => array(), 'followers' => array() );
		}
		$key    = self::facet_args( $args );
		$cached = SNSCS_Search_Cache::get( 'facet', $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$base      = array_merge( $args, array( 'personal_order' => null, 'exclude' => array() ) );
		$followers = array();
		foreach ( array_keys( self::FOLLOWER_BANDS ) as $min ) {
			if ( $args['max_followers'] && $min >= $args['max_followers'] ) {
				continue;
			}
			$followers[ $min ] = $backend->count( array_merge( $base, array( 'min_followers' => $min ) ) );
		}
		$out = array(
			'genre'     => $backend->count_by_term( $base, SNSCS_Post_Types::TAX_GENRE, 10 ),
			'region'    => $backend->count_by_term( $base, SNSCS_Post_Types::TAX_REGION, 10 ),
			'followers' => $followers,
		);
		SNSCS_Search_Cache::set( 'facet', $key, $out, 1000 );
		return $out;
	}

	public static function facets_html( array $result ) {
		$args = $result['args'];
		if ( ! $result['total'] && '' === $args['genre'] && '' === $args['region'] && ! $args['min_followers'] ) {
			return '';
		}
		$facets = self::facets( $args );
		$rows   = array();
		foreach ( array( 'genre' => array( SNSCS_Post_Types::TAX_GENRE, 'snscs_genre', 'ジャンル' ), 'region' => array( SNSCS_Post_Types::TAX_REGION, 'snscs_region', '地域' ) ) as $key => $def ) {
			$chips = '';
			if ( '' !== $args[ $key ] ) {
				$term   = get_term_by( 'slug', $args[ $key ], $def[0] );
				$chips .= '<li><a class="snscs-chip is-current" href="' . esc_url( remove_query_arg( array( $def[1], 'snscs_page' ) ) ) . '" aria-label="' . esc_attr( ( $term ? $term->name : $args[ $key ] ) . 'の指定を外す' ) . '">' . esc_html( $term ? $term->name : $args[ $key ] ) . ' ×</a></li>';
			} else {
				foreach ( $facets[ $key ] as $term_id => $count ) {
					$term = get_term( $term_id, $def[0] );
					if ( ! $term || is_wp_error( $term ) ) {
						continue;
					}
					$chips .= '<li><a class="snscs-chip" href="' . esc_url( add_query_arg( $def[1], $term->slug, remove_query_arg( 'snscs_page' ) ) ) . '">' . esc_html( $term->name ) . ' <span class="snscs-chip__count">' . esc_html( number_format_i18n( $count ) ) . '</span></a></li>';
				}
			}
			if ( '' !== $chips ) {
				$rows[] = array( $def[2], $chips );
			}
		}
		$chips = '';
		foreach ( self::FOLLOWER_BANDS as $min => $label ) {
			if ( (int) $args['min_followers'] === $min ) {
				$chips .= '<li><a class="snscs-chip is-current" href="' . esc_url( remove_query_arg( array( 'snscs_min', 'snscs_page' ) ) ) . '">' . esc_html( $label ) . ' ×</a></li>';
			} elseif ( ! empty( $facets['followers'][ $min ] ) && ( $facets['followers'][ $min ] < $result['total'] || $args['min_followers'] ) ) {
				$chips .= '<li><a class="snscs-chip" href="' . esc_url( add_query_arg( 'snscs_min', $min, remove_query_arg( 'snscs_page' ) ) ) . '">' . esc_html( $label ) . ' <span class="snscs-chip__count">' . esc_html( number_format_i18n( $facets['followers'][ $min ] ) ) . '</span></a></li>';
			}
		}
		if ( '' !== $chips ) {
			$rows[] = array( 'フォロワー', $chips );
		}
		if ( ! $rows ) {
			return '';
		}
		$html = '<div class="snscs-facets" aria-label="絞り込み（人数つき）">';
		foreach ( $rows as $row ) {
			$html .= '<div class="snscs-facets__row"><span class="snscs-facets__label">' . esc_html( $row[0] ) . '</span><ul class="snscs-chips">' . $row[1] . '</ul></div>';
		}
		return $html . '</div>';
	}

	/* --------------------------------------------------------------------
	 * なぜこの人？・言葉の色付け
	 * ------------------------------------------------------------------ */

	/**
	 * 言葉（色付けと理由に使う）。
	 *
	 * @return string[] そろえた言葉
	 */
	public static function terms( array $args ) {
		if ( '' === $args['q'] ) {
			return array();
		}
		$terms = array();
		foreach ( array_slice( preg_split( '/\s+/u', SNSCS_Text::normalize( $args['q'] ), -1, PREG_SPLIT_NO_EMPTY ), 0, 5 ) as $word ) {
			if ( mb_strlen( $word ) >= 2 || preg_match( '/^[\p{Han}]$/u', $word ) ) {
				$terms[] = $word;
			}
		}
		return $terms;
	}

	/**
	 * カードごとの理由（最大2つ）。
	 *
	 * @return array<int,string[]>
	 */
	public static function reasons( array $result ) {
		global $wpdb;
		$items = $result['items'];
		if ( ! $items ) {
			return array();
		}
		$ids    = array_map( 'intval', wp_list_pluck( $items, 'id' ) );
		// 理由には、入力したままの言葉を出す（そろえた形は「作リ置キ」のようにカタカナになるため）。
		$terms = array();
		foreach ( array_slice( preg_split( '/\s+/u', (string) $result['args']['q'], -1, PREG_SPLIT_NO_EMPTY ), 0, 5 ) as $word ) {
			$terms[ SNSCS_Text::normalize( $word ) ] = $word;
		}
		$stats  = SNSCS_Scores::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$growth = wp_list_pluck( $wpdb->get_results( "SELECT creator_id, growth FROM {$stats} WHERE creator_id IN (" . implode( ',', $ids ) . ')', ARRAY_A ), 'growth', 'creator_id' );
		$norm   = function ( $text ) {
			return SNSCS_Text::normalize( $text );
		};
		$out = array();
		foreach ( $items as $item ) {
			$id = (int) $item['id'];
			$r  = array();
			foreach ( $terms as $word => $label ) {
				if ( '' === $word ) {
					continue;
				}
				if ( false !== mb_strpos( $norm( $item['name'] ), $word ) ) {
					$r[] = '名前が一致';
				} elseif ( false !== mb_strpos( $norm( implode( ' ', array_merge( $item['genres'], $item['regions'], SNSCS_Tagger::tags_for( $id ) ) ) ), $word ) ) {
					$r[] = '「' . $label . '」のジャンル・タグ';
				} elseif ( false !== mb_strpos( $norm( implode( ' ', wp_list_pluck( $item['accounts'], 'handle' ) ) ), $word ) ) {
					$r[] = 'アカウント名が一致';
				}
				if ( $r ) {
					break;
				}
			}
			if ( ( $result['boosts'][ $id ] ?? 1 ) >= 1.15 ) {
				$r[] = 'この言葉で探した人によく選ばれています';
			}
			if ( in_array( $id, (array) ( $result['session'] ?? array() ), true ) ) {
				$r[] = 'さっき見た人に近い';
			}
			if ( isset( $growth[ $id ] ) && (float) $growth[ $id ] >= 0.05 ) {
				$r[] = sprintf( 'この1か月でフォロワー+%d%%', min( 500, (int) round( (float) $growth[ $id ] * 100 ) ) );
			}
			if ( in_array( $id, (array) ( $result['explore'] ?? array() ), true ) ) {
				$r[] = '注目の新人';
			} elseif ( strtotime( get_post_field( 'post_date_gmt', $id ) . ' UTC' ) > time() - 14 * DAY_IN_SECONDS ) {
				$r[] = '新しく掲載';
			}
			if ( $r ) {
				$out[ $id ] = array_slice( array_values( array_unique( $r ) ), 0, 2 );
			}
		}
		return $out;
	}

	/**
	 * 紹介文の抜粋: 言葉に合ったところの前後を見せ、そこを <mark> で色付けする（エスケープ済みのHTML）。
	 */
	public static function snippet_html( $text, array $terms, $length = 110 ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( ! $terms ) {
			return esc_html( mb_strimwidth( $text, 0, $length * 2, '…', 'UTF-8' ) );
		}
		// ひらがな→カタカナ・大文字→小文字は1文字ずつ対応するので、同じ位置で見つけられる。
		$search = mb_strtolower( mb_convert_kana( $text, 'C', 'UTF-8' ), 'UTF-8' );
		$first  = null;
		foreach ( $terms as $term ) {
			$pos = mb_strpos( $search, $term );
			if ( false !== $pos && ( null === $first || $pos < $first ) ) {
				$first = $pos;
			}
		}
		$start = null !== $first && $first > 30 ? $first - 25 : 0;
		$part  = mb_substr( $text, $start, $length );
		$spart = mb_substr( $search, $start, $length );
		$html  = $start > 0 ? '…' : '';
		$i     = 0;
		$len   = mb_strlen( $part );
		while ( $i < $len ) {
			$hit = 0;
			foreach ( $terms as $term ) {
				$tl = mb_strlen( $term );
				if ( $tl > $hit && mb_substr( $spart, $i, $tl ) === $term ) {
					$hit = $tl;
				}
			}
			if ( $hit ) {
				$html .= '<mark>' . esc_html( mb_substr( $part, $i, $hit ) ) . '</mark>';
				$i    += $hit;
				continue;
			}
			// 次に色付けするところまで、まとめてエスケープ。
			$next = $len;
			foreach ( $terms as $term ) {
				$p = mb_strpos( $spart, $term, $i + 1 );
				if ( false !== $p && $p < $next ) {
					$next = $p;
				}
			}
			$html .= esc_html( mb_substr( $part, $i, $next - $i ) );
			$i     = $next;
		}
		return $html . ( $start + $length < mb_strlen( $text ) ? '…' : '' );
	}

	/* --------------------------------------------------------------------
	 * 比べる
	 * ------------------------------------------------------------------ */

	public static function compare_max() {
		return SNSCS_Business::is_biz() ? SNSCS_Business::COMPARE_MAX : self::COMPARE_MAX;
	}

	public static function enqueue_compare() {
		wp_enqueue_script( 'snscs-compare', SNSCS_URL . 'assets/js/snscs-compare.js', array(), SNSCS_VERSION, true );
		wp_localize_script( 'snscs-compare', 'snscsCompare', array( 'url' => snscs_search_url(), 'max' => self::compare_max() ) );
	}

	public static function compare_ids() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = isset( $_GET[ self::COMPARE_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::COMPARE_PARAM ] ) ) : '';
		$ids = array();
		foreach ( array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) as $id ) {
			if ( 'publish' === get_post_status( $id ) && SNSCS_Post_Types::POST_TYPE === get_post_type( $id ) ) {
				$ids[] = $id;
			}
		}
		return array_slice( $ids, 0, self::compare_max() );
	}

	public static function robots( array $robots ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::COMPARE_PARAM ] ) ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	public static function render_compare( array $ids ) {
		global $wpdb;
		wp_enqueue_style( 'snscs' );
		$back = remove_query_arg( self::COMPARE_PARAM );
		if ( count( $ids ) < 2 ) {
			return '<p class="snscs-results__empty">比べる発信者を2人以上選んでください。</p><p><a class="snscs-button" href="' . esc_url( $back ) . '">検索に戻る</a></p>';
		}
		$accounts = SNSCS_Accounts::get_for_creators( $ids );
		$stats    = SNSCS_Scores::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows      = $wpdb->get_results( "SELECT creator_id, followers, growth FROM {$stats} WHERE creator_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')', ARRAY_A );
		$st        = array();
		foreach ( $rows as $row ) {
			$st[ (int) $row['creator_id'] ] = $row;
		}
		$platforms = array();
		foreach ( $accounts as $list ) {
			foreach ( $list as $a ) {
				if ( array_key_exists( $a['platform'], snscs_platforms() ) ) {
					$platforms[ $a['platform'] ] = true;
				}
			}
		}
		$platforms = array_intersect_key( snscs_platforms(), $platforms );
		$people    = array();
		foreach ( $ids as $id ) {
			$item   = SNSCS_Search::format_creator( get_post( $id ), $accounts[ $id ] ?? array() );
			$recent = 0;
			foreach ( SNSCS_Articles::for_creator( $id, 'publish', 50 ) as $article ) {
				if ( strtotime( $article->post_date_gmt . ' UTC' ) > time() - 30 * DAY_IN_SECONDS ) {
					++$recent;
				}
			}
			$by = array();
			foreach ( $item['accounts'] as $a ) {
				$by[ $a['platform'] ] = $a;
			}
			$people[] = array(
				'item'     => $item,
				'by'       => $by,
				'total'    => isset( $st[ $id ] ) ? (int) $st[ $id ]['followers'] : array_sum( wp_list_pluck( $item['accounts'], 'followers' ) ),
				'growth'   => isset( $st[ $id ] ) ? (float) $st[ $id ]['growth'] : null,
				'articles' => $recent,
				'inquiry'  => SNSCS_Inquiries::settings_for( $id )['on'],
				'verified' => (bool) array_filter( wp_list_pluck( $item['accounts'], 'verified' ) ),
			);
		}
		$best = function ( $values ) {
			$values = array_filter( $values, 'is_numeric' );
			return $values ? max( $values ) : null;
		};
		$top_total  = $best( wp_list_pluck( $people, 'total' ) );
		$top_growth = $best( wp_list_pluck( $people, 'growth' ) );
		ob_start();
		?>
		<div class="snscs-compare">
			<h2 class="snscs-compare__title">発信者を比べる（<?php echo (int) count( $people ); ?>人）</h2>
			<p><a href="<?php echo esc_url( $back ); ?>">← 検索に戻る</a></p>
			<div class="snscs-compare__scroll">
				<table class="snscs-compare__table">
					<thead>
						<tr>
							<th scope="col"><span class="screen-reader-text">項目</span></th>
							<?php foreach ( $people as $p ) : ?>
								<th scope="col">
									<a href="<?php echo esc_url( $p['item']['url'] ); ?>">
										<?php if ( $p['item']['thumbnail'] ) : ?>
											<img src="<?php echo esc_url( $p['item']['thumbnail'] ); ?>" alt="" width="56" height="56" loading="lazy">
										<?php else : ?>
											<span class="snscs-compare__initial" aria-hidden="true"><?php echo esc_html( mb_substr( $p['item']['name'], 0, 1 ) ); ?></span>
										<?php endif; ?>
										<span><?php echo esc_html( $p['item']['name'] ); ?></span>
									</a>
								</th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<tr><th scope="row">ジャンル</th><?php foreach ( $people as $p ) : ?><td><?php echo esc_html( implode( '・', $p['item']['genres'] ) ?: '—' ); ?></td><?php endforeach; ?></tr>
						<tr><th scope="row">地域</th><?php foreach ( $people as $p ) : ?><td><?php echo esc_html( implode( '・', $p['item']['regions'] ) ?: '—' ); ?></td><?php endforeach; ?></tr>
						<?php foreach ( $platforms as $key => $label ) : ?>
							<?php $top = $best( array_map( function ( $p ) use ( $key ) { return isset( $p['by'][ $key ] ) ? $p['by'][ $key ]['followers'] : null; }, $people ) ); ?>
							<tr><th scope="row"><?php echo esc_html( $label ); ?></th>
								<?php foreach ( $people as $p ) : ?>
									<td<?php echo isset( $p['by'][ $key ] ) && $top && $p['by'][ $key ]['followers'] === $top ? ' class="is-best"' : ''; ?>><?php if ( isset( $p['by'][ $key ] ) ) : ?><a href="<?php echo esc_url( $p['by'][ $key ]['url'] ); ?>" target="_blank" rel="noopener nofollow ugc"><?php echo esc_html( $p['by'][ $key ]['followers_label'] ); ?></a><?php echo $p['by'][ $key ]['verified'] ? ' <span class="snscs-compare__ok" title="本人確認済み">✓</span>' : ''; ?><?php else : ?>—<?php endif; ?></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
						<tr><th scope="row">合計フォロワー</th><?php foreach ( $people as $p ) : ?><td<?php echo $top_total && $p['total'] === $top_total ? ' class="is-best"' : ''; ?>><strong><?php echo esc_html( snscs_format_count( $p['total'] ) ); ?></strong></td><?php endforeach; ?></tr>
						<tr><th scope="row">この1か月の伸び</th><?php foreach ( $people as $p ) : ?><td<?php echo null !== $p['growth'] && null !== $top_growth && $top_growth > 0 && $p['growth'] === $top_growth ? ' class="is-best"' : ''; ?>><?php echo esc_html( null === $p['growth'] || 0.0 === $p['growth'] ? '—' : sprintf( '%+.1f%%', $p['growth'] * 100 ) ); ?></td><?php endforeach; ?></tr>
						<tr><th scope="row">記事（30日）</th><?php foreach ( $people as $p ) : ?><td><?php echo esc_html( $p['articles'] . '本' ); ?></td><?php endforeach; ?></tr>
						<tr><th scope="row">お仕事の相談</th><?php foreach ( $people as $p ) : ?><td><?php echo $p['inquiry'] ? '<a href="' . esc_url( $p['item']['url'] . '#snscs-inquiry' ) . '">受付中</a>' : '—'; ?></td><?php endforeach; ?></tr>
						<tr><th scope="row">フォロワー数の確認</th><?php foreach ( $people as $p ) : ?><td><?php echo $p['verified'] ? '公式に確認済み' : '自己申告'; ?></td><?php endforeach; ?></tr>
						<tr><th scope="row">FolloLink</th><?php foreach ( $people as $p ) : ?><td><a href="<?php echo esc_url( SNSCS_Biolink::url( $p['item']['id'] ) ); ?>" target="_blank" rel="noopener">開く</a></td><?php endforeach; ?></tr>
					</tbody>
				</table>
			</div>
			<p class="snscs-help">緑色は、その項目でいちばん大きい人です。フォロワー数は、各SNSの数を発信者が登録したもの（「公式に確認済み」はSNSと連携して自動で更新しているもの）です。</p>
			<?php echo SNSCS_Business::bulk_form( $ids ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* --------------------------------------------------------------------
	 * 検索を保存してお知らせ
	 * ------------------------------------------------------------------ */

	public static function max_saved( $user_id = 0 ) {
		return SNSCS_Business::is_biz( $user_id ) ? SNSCS_Business::SAVED_MAX : self::MAX_SAVED;
	}

	public static function saved_for( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY id DESC', $user_id ), ARRAY_A );
	}

	/**
	 * 検索結果の上の「この条件を保存」。
	 */
	public static function save_button_html( array $args ) {
		if ( '' === $args['q'] && '' === $args['genre'] && '' === $args['region'] && '' === $args['platform'] && ! $args['min_followers'] && ! $args['max_followers'] ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saved = ! empty( $_GET['snscs_saved'] );
		if ( ! is_user_logged_in() ) {
			$login = SNSCS_Social::member_url( array( 'redirect_to' => rawurlencode( self::search_url( $args ) ) ) );
			return $login ? '<p class="snscs-savesearch"><a class="snscs-savesearch__btn" href="' . esc_url( $login ) . '" rel="nofollow">🔔 この条件を保存して、新しい発信者をお知らせで受け取る</a> <span class="snscs-help">（無料のメンバー登録・ログイン）</span></p>' : '';
		}
		if ( $saved ) {
			return '<p class="snscs-savesearch" role="status">🔔 この条件を保存しました。新しい発信者が加わると、お知らせで届きます。<a href="' . esc_url( SNSCS_Social::member_url( array( 'tab' => 'saved' ) ) ) . '">保存した検索</a></p>';
		}
		$html = '<form class="snscs-savesearch" method="post" action="' . esc_url( snscs_post_url() ) . '"><input type="hidden" name="action" value="snscs_save_search">' . wp_nonce_field( 'snscs_save_search', 'snscs_nonce', true, false );
		foreach ( array( 'q', 'platform', 'genre', 'region', 'min_followers', 'max_followers' ) as $key ) {
			$html .= '<input type="hidden" name="s_' . esc_attr( $key ) . '" value="' . esc_attr( (string) $args[ $key ] ) . '">';
		}
		return $html . '<button type="submit" class="snscs-savesearch__btn">🔔 この条件を保存して、新しい発信者をお知らせで受け取る</button></form>';
	}

	public static function handle_save() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 下で確かめる。
		if ( ! is_user_logged_in() || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_save_search' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$raw = array();
		foreach ( array( 'q', 'platform', 'genre', 'region', 'min_followers', 'max_followers' ) as $key ) {
			$raw[ $key ] = isset( $_POST[ 's_' . $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ 's_' . $key ] ) ) : '';
		}
		// phpcs:enable
		$args  = array_intersect_key( SNSCS_Search::normalize_args( $raw ), array_flip( array( 'q', 'platform', 'genre', 'region', 'min_followers', 'max_followers' ) ) );
		$user  = get_current_user_id();
		$saved = self::saved_for( $user );
		$json  = wp_json_encode( $args );
		foreach ( $saved as $row ) {
			if ( $row['args'] === $json ) {
				wp_safe_redirect( add_query_arg( 'snscs_saved', 1, self::search_url( $args ) ) );
				exit;
			}
		}
		if ( count( $saved ) >= self::max_saved( $user ) ) {
			wp_safe_redirect( SNSCS_Social::member_url( array( 'tab' => 'saved', 'full' => 1 ) ) );
			exit;
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert( self::table(), array( 'user_id' => $user, 'args' => $json, 'label' => mb_substr( self::describe_args( $args ), 0, 200 ), 'last_check' => $now, 'created_at' => $now ) );
		wp_safe_redirect( add_query_arg( 'snscs_saved', 1, self::search_url( $args ) ) );
		exit;
	}

	public static function handle_delete() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 下で確かめる。
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! is_user_logged_in() || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_delete_search_' . $id ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		// phpcs:enable
		$wpdb->delete( self::table(), array( 'id' => $id, 'user_id' => get_current_user_id() ) );
		wp_safe_redirect( SNSCS_Social::member_url( array( 'tab' => 'saved' ) ) );
		exit;
	}

	/**
	 * メンバーページ「保存した検索」。
	 */
	public static function render_saved( $user_id ) {
		$rows = self::saved_for( $user_id );
		$max  = self::max_saved( $user_id );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['full'] ) ) {
			echo '<p class="snscs-notice snscs-notice--error">' . esc_html( sprintf( '保存できる検索は%d件までです。使っていないものを消してから保存してください。', $max ) ) . '</p>';
		}
		echo '<p class="snscs-help">' . esc_html( sprintf( '検索結果の「この条件を保存」で、%d件まで保存できます。保存した条件に新しい発信者が加わると、1日1回お知らせで届きます。', $max ) ) . '</p>';
		if ( ! $rows ) {
			echo '<p><a class="snscs-button snscs-button--primary" href="' . esc_url( snscs_search_url() ) . '">発信者を探す</a></p>';
			return;
		}
		echo '<ul class="snscs-saved">';
		foreach ( $rows as $row ) {
			$args = (array) json_decode( $row['args'], true );
			echo '<li class="snscs-saved__item"><a class="snscs-saved__label" href="' . esc_url( self::search_url( $args ) ) . '">' . esc_html( $row['label'] ) . '</a>';
			echo '<span class="snscs-help">' . esc_html( '保存した日: ' . get_date_from_gmt( $row['created_at'], 'Y年n月j日' ) ) . '</span>';
			echo '<form method="post" action="' . esc_url( snscs_post_url() ) . '"><input type="hidden" name="action" value="snscs_delete_search"><input type="hidden" name="id" value="' . (int) $row['id'] . '">' . wp_nonce_field( 'snscs_delete_search_' . (int) $row['id'], 'snscs_nonce', true, false ) . '<button type="submit" class="snscs-linklike">消す</button></form></li>';
		}
		echo '</ul>';
	}

	/**
	 * 保存した検索に、新しい発信者が加わったかを調べる（1日1回、朝9時以降。1回に500件まで）。
	 *
	 * @return int お知らせした数。
	 */
	public static function check_saved() {
		global $wpdb;
		if ( (int) current_time( 'G' ) < 9 ) {
			return 0;
		}
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - 20 * HOUR_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE last_check < %s ORDER BY last_check ASC LIMIT 500", $since ), ARRAY_A );
		$sent = 0;
		foreach ( $rows as $row ) {
			$args   = (array) json_decode( $row['args'], true );
			$result = SNSCS_Search::run( array_merge( $args, array( 'sort' => 'newest', 'per_page' => 30 ) ) );
			$new    = 0;
			foreach ( $result['items'] as $item ) {
				if ( strtotime( get_post_field( 'post_date_gmt', $item['id'] ) . ' UTC' ) > strtotime( $row['last_check'] . ' UTC' ) ) {
					++$new;
				}
			}
			$wpdb->update( $table, array( 'last_check' => current_time( 'mysql', true ), 'last_new' => $new ), array( 'id' => (int) $row['id'] ) );
			if ( $new ) {
				SNSCS_Inbox::add( (int) $row['user_id'], 'saved_search', (int) $row['id'] );
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * お知らせの文（SNSCS_Inbox）。
	 */
	public static function describe( array $row ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$saved = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $row['object_id'] ), ARRAY_A );
		if ( ! $saved ) {
			return null;
		}
		$args = (array) json_decode( $saved['args'], true );
		return array(
			'text' => sprintf( '保存した検索（%s）に、新しい発信者が%d人加わりました。', $saved['label'], max( 1, (int) $saved['last_new'] ) ),
			'url'  => self::search_url( array_merge( $args, array( 'sort' => 'newest' ) ) ),
		);
	}

	public static function delete_for_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => (int) $user_id ) );
	}
}
