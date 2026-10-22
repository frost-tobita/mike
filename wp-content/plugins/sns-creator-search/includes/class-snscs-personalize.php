<?php
/**
 * 一人ひとりに合わせたおすすめ（パーソナライズ）と、「一緒に見られている発信者」の予測。
 *
 * ■ 好みの記録（訪問者のブラウザの中だけ。サーバーには個人ごとの記録を残さない）
 *   assets/js/snscs-personal.js が、次の行動から「好みの重み」を育てる。
 *     - 検索: 選んだジャンル・SNS・地域、キーワードに含まれるジャンル名
 *     - 発信者ページ: その人のジャンル・SNS・地域。じっくり見たほど（滞在時間）、リンクを押したほど重く
 *     - コラム・発信者の記事: 記事のジャンル・SNS。読んだ時間が長いほど重く
 *     - お気に入り登録: そのジャンルを大きく
 *   重みは時間とともに薄れる（14日で半分）。好みはクッキー snscs_pf に要約し、サーバーはそれを読むだけ。
 *   訪問者はいつでもオフ・リセットできる。ブラウザの「追跡しない（GPC）」設定があれば最初からオフ。
 *
 * ■ 予測1: 好みとの近さ
 *   好み（ジャンル・SNS・地域）と発信者の近さを 0〜1 にし、「一緒に見られている」近さと合わせて
 *   personal = 0.45×ジャンル + 0.2×SNS + 0.1×地域 + 0.25×一緒に見られている
 *   おすすめ順の並び = (1 − 強さ) × 総合点 + 強さ × 100 × personal   （強さの初期値 30%）
 *
 * ■ 予測2: 一緒に見られている発信者（協調フィルタリング）
 *   「同じ日に同じ人が見た発信者の組」を毎日数え（1日に多く見る人の1件は軽く数える）、古い組ほど薄める。
 *   最近見た・お気に入りの発信者を起点に、組の強さ ÷ √人気 で「この人も好きそう」を予測する。
 *   訪問者は日付入りのハッシュで区別しているため、別の日の行動どうしは結びつかない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Personalize {

	const COOKIE     = 'snscs_pf';
	const OFF_COOKIE = 'snscs_pf_off';
	const MIN_WEIGHT = 1.5;
	const DECAY      = 0.95;
	const MIN_PAIR   = 0.02;
	const MAX_PER_VISITOR = 40;

	/** personal の内訳の重み。 */
	const MIX = array(
		'genre'    => 0.45,
		'platform' => 0.2,
		'region'   => 0.1,
		'cf'       => 0.25,
	);

	private static $profile = null;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_shortcode( 'sns_for_you', array( __CLASS__, 'shortcode_for_you' ) );
		add_shortcode( 'sns_favorites', array( __CLASS__, 'shortcode_favorites' ) );
		add_action( SNSCS_Scores::CRON_HOOK, array( __CLASS__, 'build_coviews' ), 5 );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_creator' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_coviews';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  a bigint(20) unsigned NOT NULL,
  b bigint(20) unsigned NOT NULL,
  weight double NOT NULL DEFAULT 0,
  PRIMARY KEY  (a,b),
  KEY b (b)
) {$charset};"
		);
	}

	public static function enabled() {
		return (bool) SNSCS_Hub::setting( 'personalize' );
	}

	/* --------------------------------------------------------------------
	 * ページ側
	 * ------------------------------------------------------------------ */

	/**
	 * 今のページの「中身」（ジャンル・SNS・地域）。好みの記録に使う。
	 *
	 * @return array|null
	 */
	public static function page_context() {
		$terms = function ( $post_id, $taxonomy ) {
			$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			return is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
		};

		if ( is_singular( SNSCS_Post_Types::POST_TYPE ) ) {
			$id = (int) get_queried_object_id();
			return array(
				'type'    => 'creator',
				'id'      => $id,
				'genres'  => $terms( $id, SNSCS_Post_Types::TAX_GENRE ),
				'regions' => $terms( $id, SNSCS_Post_Types::TAX_REGION ),
				'sns'     => array_values( array_unique( wp_list_pluck( SNSCS_Accounts::get_for_creator( $id ), 'platform' ) ) ),
			);
		}
		if ( is_singular( SNSCS_Articles::POST_TYPE ) ) {
			$creator = SNSCS_Articles::creator_of( get_queried_object_id() );
			return array(
				'type'    => 'article',
				'id'      => (int) get_queried_object_id(),
				'creator' => $creator,
				'genres'  => $creator ? $terms( $creator, SNSCS_Post_Types::TAX_GENRE ) : array(),
				'regions' => array(),
				'sns'     => $creator ? array_values( array_unique( wp_list_pluck( SNSCS_Accounts::get_for_creator( $creator ), 'platform' ) ) ) : array(),
			);
		}
		if ( is_singular( 'post' ) && SNSCS_Columns::is_column( get_queried_object_id() ) ) {
			$id  = (int) get_queried_object_id();
			$sns = get_the_terms( $id, SNSCS_Columns::TAX_SNS );
			return array(
				'type'    => 'column',
				'id'      => $id,
				'genres'  => $terms( $id, SNSCS_Post_Types::TAX_GENRE ),
				'regions' => array(),
				'sns'     => $sns && ! is_wp_error( $sns ) ? array_values( array_intersect( wp_list_pluck( $sns, 'slug' ), array_keys( snscs_platforms() ) ) ) : array(),
			);
		}
		return null;
	}

	/**
	 * 検索ページ・特集ページで、検索の条件を好みの記録に渡す。
	 */
	public static function search_context( array $args ) {
		$ids = function ( $slug, $taxonomy ) {
			$term = '' !== $slug ? get_term_by( 'slug', $slug, $taxonomy ) : null;
			return $term ? array( (int) $term->term_id ) : array();
		};
		return array(
			'type'      => 'search',
			'genres'    => $ids( $args['genre'], SNSCS_Post_Types::TAX_GENRE ),
			'regions'   => $ids( $args['region'], SNSCS_Post_Types::TAX_REGION ),
			'sns'       => '' !== $args['platform'] ? array( $args['platform'] ) : array(),
			'kwGenres'  => self::genres_in_text( $args['q'] ),
		);
	}

	/**
	 * キーワードに名前が含まれるジャンル（例: 「料理 時短」→ 料理）。
	 *
	 * @return int[]
	 */
	public static function genres_in_text( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array();
		}
		$found = array();
		$terms = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => false, 'number' => 500 ) );
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			if ( mb_strlen( $term->name ) >= 2 && false !== mb_stripos( $text, $term->name ) ) {
				$found[] = (int) $term->term_id;
			}
		}
		return array_slice( $found, 0, 3 );
	}

	private static $search_ctx = null;

	/**
	 * 検索ページから呼ぶ: このページの検索条件を好みの記録に使う。
	 */
	public static function set_search_context( array $args ) {
		self::$search_ctx = self::search_context( $args );
		self::enqueue_script();
	}

	public static function enqueue() {
		if ( is_admin() ) {
			return;
		}
		$ctx = self::page_context();
		if ( $ctx ) {
			self::enqueue_script( $ctx );
		}
	}

	/**
	 * 好みの記録・お気に入り・「最近見た」のスクリプト。ページの中身は window.snscsCtx で渡す。
	 */
	public static function enqueue_script( $ctx = null ) {
		static $done = false;
		if ( ! wp_script_is( 'snscs-personal', 'registered' ) ) {
			wp_register_script( 'snscs-personal', SNSCS_URL . 'assets/js/snscs-personal.js', array(), SNSCS_VERSION, true );
		}
		if ( ! $done ) {
			$done = true;
			wp_enqueue_script( 'snscs-personal' );
			wp_enqueue_style( 'snscs' );
			wp_add_inline_script(
				'snscs-personal',
				'window.snscsPersonal = ' . wp_json_encode(
					array(
						'rest'      => esc_url_raw( rest_url( 'snscs/v1/personal' ) ),
						'learn'     => self::enabled(),
						'cookie'    => self::COOKIE,
						'off'       => self::OFF_COOKIE,
						'path'      => COOKIEPATH ? COOKIEPATH : '/',
						'favorites' => SNSCS_Hub::page_url( 'favorites' ),
					)
				) . ';',
				'before'
			);
		}
		if ( $ctx ) {
			wp_add_inline_script( 'snscs-personal', 'window.snscsCtx = ' . wp_json_encode( $ctx ) . ';', 'before' );
		}
	}

	/**
	 * 検索ページの文脈を出力する（ショートコードの中から呼ぶため、フッターのスクリプトの前に足す）。
	 */
	public static function print_search_context() {
		if ( self::$search_ctx ) {
			wp_add_inline_script( 'snscs-personal', 'window.snscsCtx = ' . wp_json_encode( self::$search_ctx ) . ';', 'before' );
			self::$search_ctx = null;
		}
	}

	/* --------------------------------------------------------------------
	 * 好みの読み取り
	 * ------------------------------------------------------------------ */

	/**
	 * クッキーに要約された好み。使えないときは null。
	 * 形式: g:12=3.1,5=1.2|p:instagram=2|r:7=0.5|c:12,45|f:9,3
	 *
	 * @return array{genres:array<int,float>,platforms:array<string,float>,regions:array<int,float>,recent:int[],favs:int[]}|null
	 */
	public static function profile() {
		if ( null !== self::$profile ) {
			return self::$profile ? self::$profile : null;
		}
		self::$profile = false;
		if ( ! self::enabled() || ! empty( $_COOKIE[ self::OFF_COOKIE ] ) || empty( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}
		$profile = self::parse( (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parse() で数値とSNSのキーだけを取り出す。
		if ( $profile ) {
			self::$profile = $profile;
		}
		return $profile;
	}

	/**
	 * @param string $raw クッキーの値。
	 * @return array|null
	 */
	public static function parse( $raw ) {
		$profile = array( 'genres' => array(), 'platforms' => array(), 'regions' => array(), 'recent' => array(), 'favs' => array() );
		$map     = array( 'g' => 'genres', 'r' => 'regions', 'p' => 'platforms' );
		foreach ( explode( '|', substr( $raw, 0, 2000 ) ) as $part ) {
			if ( ! preg_match( '/^([a-z]):(.*)$/', $part, $m ) ) {
				continue;
			}
			if ( isset( $map[ $m[1] ] ) ) {
				foreach ( explode( ',', $m[2] ) as $pair ) {
					if ( ! preg_match( '/^([a-z0-9_]{1,20})=(\d{1,4}(?:\.\d{1,3})?)$/', $pair, $kv ) ) {
						continue;
					}
					$key = 'p' === $m[1] ? $kv[1] : (int) $kv[1];
					if ( 'p' === $m[1] ? ! array_key_exists( $key, snscs_platforms() ) : $key <= 0 ) {
						continue;
					}
					$profile[ $map[ $m[1] ] ][ $key ] = min( 1000.0, (float) $kv[2] );
				}
			} elseif ( 'c' === $m[1] || 'f' === $m[1] ) {
				$ids = array_filter( array_map( 'absint', explode( ',', $m[2] ) ) );
				$profile[ 'c' === $m[1] ? 'recent' : 'favs' ] = array_slice( array_values( array_unique( $ids ) ), 0, 20 );
			}
		}
		foreach ( array( 'genres', 'platforms', 'regions' ) as $key ) {
			arsort( $profile[ $key ] );
			$profile[ $key ] = array_slice( $profile[ $key ], 0, 'genres' === $key ? 12 : 6, true );
		}
		$total = array_sum( $profile['genres'] ) + array_sum( $profile['platforms'] ) + count( $profile['recent'] ) + count( $profile['favs'] );
		return $total >= self::MIN_WEIGHT ? $profile : null;
	}

	/* --------------------------------------------------------------------
	 * 並び替えの式
	 * ------------------------------------------------------------------ */

	/**
	 * 検索（SNSCS_MySQL_Backend）の並びに使う「好みとの近さ」の式。
	 * 数値と整数IDだけを埋め込むため、SQLに直接書いても安全。
	 *
	 * @param array $profile  profile() の戻り値。
	 * @param float $strength 0〜1。
	 * @return string|null 例: "(0.7 * MAX(COALESCE(st.score,0)) + 30 * (...))"
	 */
	public static function order_expression( array $profile, $strength, $score_column = 'st.score' ) {
		global $wpdb;
		$strength = min( 0.9, max( 0.0, (float) $strength ) );
		if ( $strength <= 0 ) {
			return null;
		}
		$num   = function ( $value ) {
			return sprintf( '%.4F', (float) $value );
		};
		$parts = array();

		// ジャンル・地域: 一番好きなものを 1 として、当てはまる分を足す（最大 1）。
		foreach ( array( 'genre' => array( 'genres', SNSCS_Post_Types::TAX_GENRE ), 'region' => array( 'regions', SNSCS_Post_Types::TAX_REGION ) ) as $mix => $def ) {
			$weights = $profile[ $def[0] ];
			if ( ! $weights ) {
				continue;
			}
			$max   = max( $weights );
			$cases = array();
			foreach ( $weights as $term_id => $w ) {
				$term = get_term( (int) $term_id, $def[1] );
				if ( $term && ! is_wp_error( $term ) ) {
					$cases[ (int) $term->term_taxonomy_id ] = $w / $max;
				}
			}
			if ( ! $cases ) {
				continue;
			}
			$sql = 'CASE trp.term_taxonomy_id';
			foreach ( $cases as $tt => $w ) {
				$sql .= ' WHEN ' . (int) $tt . ' THEN ' . $num( $w );
			}
			$sql    .= ' ELSE 0 END';
			$parts[] = $num( self::MIX[ $mix ] ) . " * LEAST(1, COALESCE((SELECT SUM({$sql}) FROM {$wpdb->term_relationships} trp WHERE trp.object_id = p.ID AND trp.term_taxonomy_id IN (" . implode( ',', array_keys( $cases ) ) . ')), 0))';
		}

		// SNS: 一番よく見るSNSを 1 として、その人のアカウントのうち一番近いもの。
		if ( $profile['platforms'] ) {
			$max = max( $profile['platforms'] );
			$sql = 'CASE a.platform';
			foreach ( $profile['platforms'] as $key => $w ) {
				$sql .= " WHEN '" . esc_sql( $key ) . "' THEN " . $num( $w / $max );
			}
			$parts[] = $num( self::MIX['platform'] ) . " * MAX({$sql} ELSE 0 END)";
		}

		// 一緒に見られている発信者（予測）。
		$predicted = self::predict( array_merge( $profile['favs'], $profile['recent'] ), 40 );
		if ( $predicted ) {
			$max = max( $predicted );
			$sql = 'CASE p.ID';
			foreach ( $predicted as $id => $w ) {
				$sql .= ' WHEN ' . (int) $id . ' THEN ' . $num( $w / $max );
			}
			$parts[] = $num( self::MIX['cf'] ) . " * ({$sql} ELSE 0 END)";
		}

		if ( ! $parts ) {
			return null;
		}
		$score_column = 'st.score_b' === $score_column ? 'COALESCE(st.score_b, st.score, 0)' : 'COALESCE(st.score, 0)';
		return '(' . $num( 1 - $strength ) . ' * MAX(' . $score_column . ') + ' . $num( 100 * $strength ) . ' * (' . implode( ' + ', $parts ) . '))';
	}

	/* --------------------------------------------------------------------
	 * 一緒に見られている発信者
	 * ------------------------------------------------------------------ */

	/**
	 * 起点の発信者たちと一緒に見られている発信者を予測する（起点そのものは除く）。
	 *
	 * @param int[] $seeds 起点（最近見た・お気に入り）。前にあるほど重く数える。
	 * @param int   $limit 件数。
	 * @return array<int,float> 発信者ID => 予測の強さ（大きい順）。
	 */
	public static function predict( array $seeds, $limit = 20 ) {
		global $wpdb;
		$seeds = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $seeds ) ) ) ), 0, 20 );
		if ( ! $seeds ) {
			return array();
		}
		$table = self::table();
		$stats = SNSCS_Scores::table();
		// 起点ごとの重み（前にあるものほど重い）。
		$seed_weight = array();
		foreach ( $seeds as $i => $id ) {
			$seed_weight[ $id ] = 1 / ( 1 + 0.15 * $i );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT cv.a, cv.b, cv.weight, COALESCE(st.views, 0) AS views FROM {$table} cv INNER JOIN {$wpdb->posts} p ON p.ID = cv.b AND p.post_status = 'publish' LEFT JOIN {$stats} st ON st.creator_id = cv.b WHERE cv.a IN (" . implode( ',', $seeds ) . ') ORDER BY cv.weight DESC LIMIT 2000', ARRAY_A );

		$scores = array();
		foreach ( $rows as $row ) {
			$b = (int) $row['b'];
			if ( isset( $seed_weight[ $b ] ) ) {
				continue;
			}
			// 人気の人ばかりにならないよう、人気（閲覧数）の平方根で割る。
			$scores[ $b ] = ( isset( $scores[ $b ] ) ? $scores[ $b ] : 0 ) + $seed_weight[ (int) $row['a'] ] * (float) $row['weight'] / sqrt( max( 5, (int) $row['views'] ) );
		}
		arsort( $scores );
		return array_slice( $scores, 0, $limit, true );
	}

	/**
	 * 前日の「同じ人が同じ日に見た発信者の組」を数えて足す（1日1回。点数計算の定期処理から呼ばれる）。
	 * 重複防止用の記録（SNSCS_Tracking の snscs_visits）は2日残るので、前日分はまだ読める。
	 *
	 * @param string $day 数える日（テスト用）。省略時は前日。
	 * @return int 数えた組の数。
	 */
	public static function build_coviews( $day = null ) {
		global $wpdb;

		// 定期処理からは空の引数で呼ばれる。そのときは前日分を1日1回だけ数える。
		if ( empty( $day ) ) {
			$day = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 day' ) );
			if ( get_option( 'snscs_coviews_day' ) === $day ) {
				return 0;
			}
			update_option( 'snscs_coviews_day', $day, false );
		}

		$table  = self::table();
		$visits = SNSCS_Tracking::visits_table();

		// 古い組を少しずつ薄め、ほとんど0になったものは消す。
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET weight = weight * %f", self::DECAY ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE weight < %f", self::MIN_PAIR ) );

		$pairs  = array();
		$offset = 0;
		$batch  = 5000;
		do {
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT GROUP_CONCAT(creator_id ORDER BY creator_id) FROM {$visits} WHERE day = %s GROUP BY visitor_hash HAVING COUNT(*) BETWEEN 2 AND %d ORDER BY visitor_hash LIMIT %d, %d", $day, self::MAX_PER_VISITOR, $offset, $batch ) );
			foreach ( $rows as $list ) {
				$ids = array_map( 'intval', explode( ',', $list ) );
				$n   = count( $ids );
				// たくさん見る人の1件は軽く数える（ロボットのような巡回の影響を小さくする）。
				$w = 1 / log( 1 + $n, 2 );
				for ( $i = 0; $i < $n; $i++ ) {
					for ( $j = $i + 1; $j < $n; $j++ ) {
						$key           = $ids[ $i ] . ':' . $ids[ $j ];
						$pairs[ $key ] = ( isset( $pairs[ $key ] ) ? $pairs[ $key ] : 0 ) + $w;
					}
				}
			}
			$offset += $batch;
		} while ( count( $rows ) === $batch );

		foreach ( array_chunk( $pairs, 400, true ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $key => $w ) {
				list( $a, $b ) = array_map( 'intval', explode( ':', $key ) );
				$values[]      = $wpdb->prepare( '(%d, %d, %f), (%d, %d, %f)', $a, $b, $w, $b, $a, $w );
			}
			$wpdb->query( "INSERT INTO {$table} (a, b, weight) VALUES " . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE weight = weight + VALUES(weight)' );
		}
		// phpcs:enable

		return count( $pairs );
	}

	public static function delete_for_creator( $post_id ) {
		global $wpdb;
		if ( SNSCS_Post_Types::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE a = %d OR b = %d", $post_id, $post_id ) );
	}

	/**
	 * 発信者ページの「この発信者を見た人は、こんな発信者も見ています」（全員共通の予測）。
	 */
	public static function also_viewed_html( $creator_id ) {
		$predicted = self::predict( array( $creator_id ), 4 );
		if ( count( $predicted ) < 2 ) {
			return '';
		}
		return SNSCS_Recommend::render( 'この発信者を見た人は、こんな発信者も見ています', self::items( array_keys( $predicted ) ) );
	}

	/* --------------------------------------------------------------------
	 * あなたへのおすすめ
	 * ------------------------------------------------------------------ */

	/**
	 * 発信者IDから表示用データ（掲載中の人だけ、順番はそのまま）。
	 */
	public static function items( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		_prime_post_caches( $ids );
		update_object_term_cache( $ids, SNSCS_Post_Types::POST_TYPE );
		$accounts = SNSCS_Accounts::get_for_creators( $ids );
		$items    = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post && SNSCS_Post_Types::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) {
				$items[] = SNSCS_Search::format_creator( $post, isset( $accounts[ $id ] ) ? $accounts[ $id ] : array() );
			}
		}
		return $items;
	}

	/**
	 * 好みに合わせたおすすめの発信者（見たことのある人・お気に入りは除く）。理由の一言つき。
	 *
	 * @param array|null $profile 好み（無ければ「いま人気」）。
	 * @param int[]      $exclude 除く発信者。
	 * @param int        $limit   人数。
	 * @return array{items:array,personal:bool}
	 */
	public static function recommend( $profile, array $exclude, $limit = 8 ) {
		if ( ! $profile ) {
			$result = SNSCS_Search::run( array( 'sort' => 'recommended', 'per_page' => $limit, 'exclude' => $exclude ) );
			foreach ( $result['items'] as &$item ) {
				$item['reason'] = 'いま人気';
			}
			unset( $item );
			return array( 'items' => $result['items'], 'personal' => false );
		}

		$result    = SNSCS_Search::run(
			array(
				'sort'     => 'recommended',
				'per_page' => $limit,
				'exclude'  => $exclude,
			),
			false,
			array( 'profile' => $profile, 'strength' => 0.6 )
		);
		$predicted = self::predict( array_merge( $profile['favs'], $profile['recent'] ), 40 );
		$seed      = $profile['recent'] ? $profile['recent'][0] : ( $profile['favs'] ? $profile['favs'][0] : 0 );
		$top_genre = $profile['genres'] ? get_term( (int) key( $profile['genres'] ), SNSCS_Post_Types::TAX_GENRE ) : null;
		$genre_ids = array_map( 'intval', array_keys( $profile['genres'] ) );
		foreach ( $result['items'] as &$item ) {
			$item_genres = wp_get_object_terms( $item['id'], SNSCS_Post_Types::TAX_GENRE, array( 'fields' => 'ids' ) );
			$common      = is_wp_error( $item_genres ) ? array() : array_values( array_intersect( $genre_ids, array_map( 'intval', $item_genres ) ) );
			if ( isset( $predicted[ $item['id'] ] ) && $seed ) {
				$item['reason'] = get_the_title( $seed ) . 'を見た人に人気';
			} elseif ( $common ) {
				$term           = get_term( $common[0], SNSCS_Post_Types::TAX_GENRE );
				$item['reason'] = ( $term && ! is_wp_error( $term ) ? $term->name : 'このジャンル' ) . 'をよく見ているあなたに';
			} else {
				$item['reason'] = $top_genre && ! is_wp_error( $top_genre ) ? 'よく見るSNSで人気' : 'いま人気';
			}
		}
		unset( $item );
		return array( 'items' => $result['items'], 'personal' => true );
	}

	/**
	 * 好みに合うコラムと発信者の記事。
	 *
	 * @param array|null $profile 好み。
	 * @param int[]      $seen    読んだ記事（除く）。
	 * @return WP_Post[]
	 */
	public static function recommend_posts( $profile, array $seen, $limit = 4 ) {
		$genres = $profile ? array_slice( array_map( 'intval', array_keys( $profile['genres'] ) ), 0, 3 ) : array();
		$sns    = $profile ? array_slice( array_keys( $profile['platforms'] ), 0, 2 ) : array();
		$posts  = SNSCS_Columns::find( array( 'sns' => $sns, 'genres' => $genres, 'count' => $limit, 'exclude' => $seen ) );

		// 好きなジャンルの発信者が書いた記事も混ぜる。
		if ( $genres ) {
			$creators = get_posts(
				array(
					'post_type'      => SNSCS_Post_Types::POST_TYPE,
					'posts_per_page' => 30,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'tax_query'      => array( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'field' => 'term_id', 'terms' => $genres ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				)
			);
			if ( $creators ) {
				$articles = get_posts(
					array(
						'post_type'      => SNSCS_Articles::POST_TYPE,
						'posts_per_page' => 2,
						'post__not_in'   => $seen,
						'no_found_rows'  => true,
						'meta_query'     => array( array( 'key' => '_snscs_creator_id', 'value' => $creators, 'compare' => 'IN' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					)
				);
				$posts = array_slice( array_merge( $articles, $posts ), 0, $limit );
			}
		}
		return $posts;
	}

	/* --------------------------------------------------------------------
	 * REST: 一人ひとりに合わせた欄（ページはキャッシュできるよう、あとから読み込む）
	 * ------------------------------------------------------------------ */

	public static function register_routes() {
		register_rest_route(
			'snscs/v1',
			'/personal',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_personal' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'blocks' => array( 'type' => 'string' ),
					'recent' => array( 'type' => 'string' ),
					'favs'   => array( 'type' => 'string' ),
					'seen'   => array( 'type' => 'string' ),
					'here'   => array( 'type' => 'integer' ),
				),
			)
		);
	}

	private static function id_list( $value, $max = 30 ) {
		return array_slice( array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $value ) ) ) ) ), 0, $max );
	}

	public static function rest_personal( WP_REST_Request $request ) {
		$blocks  = array_intersect( explode( ',', (string) $request->get_param( 'blocks' ) ), array( 'foryou', 'personal', 'recent', 'favs', 'posts' ) );
		$recent  = self::id_list( $request->get_param( 'recent' ) );
		$favs    = self::id_list( $request->get_param( 'favs' ), 100 );
		$seen    = self::id_list( $request->get_param( 'seen' ) );
		$here    = absint( $request->get_param( 'here' ) );
		$profile = self::profile();
		$out     = array();

		if ( in_array( 'foryou', $blocks, true ) ) {
			$reco          = self::recommend( $profile, array_merge( $recent, $favs, array( $here ) ), 8 );
			$out['foryou'] = SNSCS_Recommend::render( $reco['personal'] ? 'あなたへのおすすめ' : 'いま人気の発信者', $reco['items'], array( 'reasons' => true, 'note' => $reco['personal'] ? 'personal' : 'popular' ) );
		}
		// personal: 好みが分かっている人にだけ出す「あなたへのおすすめ」（トップページ用。人気の一覧は別の欄にあるため）。
		if ( in_array( 'personal', $blocks, true ) && $profile ) {
			$reco            = self::recommend( $profile, array_merge( $recent, $favs, array( $here ) ), 6 );
			$out['personal'] = SNSCS_Recommend::render( 'あなたへのおすすめ', $reco['items'], array( 'reasons' => true, 'note' => 'personal' ) );
		}
		if ( in_array( 'recent', $blocks, true ) ) {
			$out['recent'] = SNSCS_Recommend::render( '最近見た発信者', self::items( array_slice( array_diff( $recent, array( $here ) ), 0, 8 ) ), array( 'compact' => true ) );
		}
		if ( in_array( 'favs', $blocks, true ) ) {
			$out['favs'] = SNSCS_Recommend::render( 'お気に入り', self::items( $favs ), array( 'compact' => true ) );
		}
		if ( in_array( 'posts', $blocks, true ) ) {
			$out['posts'] = SNSCS_Columns::render_posts( $profile ? 'あなたにおすすめの記事' : '人気のSNS運用コラム', self::recommend_posts( $profile, $seen ) );
		}

		$response = rest_ensure_response( $out );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * あとから中身を読み込む欄。JavaScript が無いときは何も出さない。
	 *
	 * @param string $blocks foryou,recent,favs,posts の組み合わせ。
	 */
	public static function placeholder( $blocks ) {
		self::enqueue_script();
		return '<div class="snscs-personal" data-snscs-blocks="' . esc_attr( $blocks ) . '" aria-live="polite"></div>';
	}

	/**
	 * [sns_for_you blocks="foryou,recent,posts"]
	 */
	public static function shortcode_for_you( $atts ) {
		$atts = shortcode_atts( array( 'blocks' => 'foryou,recent,posts' ), $atts, 'sns_for_you' );
		return self::placeholder( preg_replace( '/[^a-z,]/', '', $atts['blocks'] ) );
	}

	/**
	 * [sns_favorites]: お気に入り・最近見た発信者・あなたへのおすすめと、おすすめの設定。
	 */
	public static function shortcode_favorites() {
		SNSCS_Hub::remember_page( 'favorites' );
		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		?>
		<p class="snscs-feature__lead">発信者ページの「☆ お気に入り」を押すと、ここに集まります。お気に入りと閲覧の記録は、お使いのブラウザの中にだけ保存されます（別の端末やブラウザとは共有されません）。</p>
		<?php echo self::placeholder( 'favs,recent,foryou' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>
		<noscript><p>お気に入りを使うには、JavaScript を有効にしてください。</p></noscript>
		<section class="snscs-panel snscs-personal-settings">
			<h2 class="snscs-panel__title">おすすめの設定</h2>
			<?php if ( self::enabled() ) : ?>
				<p>見た発信者や記事、検索の傾向から「あなたへのおすすめ」と「おすすめ順」を一人ひとりに合わせています。傾向はこのブラウザの中にだけ保存され、14日ほどで薄れていきます。</p>
				<p>現在: <strong data-snscs-personal-state>—</strong></p>
				<p>
					<button type="button" class="snscs-button" data-snscs-personal-reset>これまでの傾向を消す</button>
					<button type="button" class="snscs-button" data-snscs-personal-off>オフにする</button>
					<button type="button" class="snscs-button" data-snscs-personal-on>オンにする</button>
				</p>
			<?php else : ?>
				<p>このサイトでは、閲覧の傾向を使ったおすすめは行っていません。</p>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}
}
