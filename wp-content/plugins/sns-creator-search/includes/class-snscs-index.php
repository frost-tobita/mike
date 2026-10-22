<?php
/**
 * 検索用の索引と、関連度の計算（BM25）。
 *
 * 発信者ごとに、項目（名前・ハンドル・ジャンルと地域・タグ・紹介文・記事の題名）ごとの単語と回数を記録する。
 * 検索では、言葉に合う発信者を索引から集め、次の式で「関連度」を出す（Google などが土台にしている計算）。
 *
 *   関連度 = Σ 項目の重み × 珍しさ(idf) × 回数の効き目
 *     珍しさ   : 多くの発信者に出てくる単語ほど小さい（「の」より「キャンプ」が効く）
 *     回数の効き目 : tf×(k1+1) ÷ (tf + k1×(1 − b + b×項目の長さ÷平均の長さ))
 *                   同じ単語を何度もくり返しても効き目は頭打ちになり、短い項目での一致ほど効く
 *
 * 項目の重み: 名前 3.0 / ハンドル 2.5 / ジャンル・地域 2.0 / タグ 1.6 / 紹介文 1.0 / 記事の題名 0.7
 * 言葉が複数あるとき（「料理 時短」）は、それぞれの言葉の単語が一定以上そろっている発信者だけを候補にする。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Index {

	const K1             = 1.2;
	const B              = 0.75;
	const MAX_CANDIDATES = 2000;
	const VERSION        = 1;

	/** 項目の番号 => [名前, 重み] */
	public static function fields() {
		return apply_filters(
			'snscs_index_fields',
			array(
				1 => array( 'name', 3.0 ),
				2 => array( 'handle', 2.5 ),
				3 => array( 'category', 2.0 ),
				4 => array( 'tags', 1.6 ),
				5 => array( 'bio', 1.0 ),
				6 => array( 'articles', 0.7 ),
			)
		);
	}

	public static function init() {
		add_action( 'snscs_submission_approved', array( __CLASS__, 'on_approved' ), 20 );
		add_action( 'save_post_' . SNSCS_Post_Types::POST_TYPE, array( __CLASS__, 'on_save' ), 20 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_article_status' ), 20, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'snscs_hourly_engine', array( __CLASS__, 'rebuild_step' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function terms_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_idx_terms';
	}

	public static function docs_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_idx_docs';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$terms   = self::terms_table();
		$docs    = self::docs_table();
		dbDelta(
			"CREATE TABLE {$terms} (
  term_hash int(10) unsigned NOT NULL,
  creator_id bigint(20) unsigned NOT NULL,
  field tinyint(3) unsigned NOT NULL,
  tf smallint(5) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY  (term_hash,creator_id,field),
  KEY creator_id (creator_id)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$docs} (
  creator_id bigint(20) unsigned NOT NULL,
  field tinyint(3) unsigned NOT NULL,
  len smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (creator_id,field)
) {$charset};"
		);
		// 索引の作り直しを始める（1時間ごとの処理で少しずつ進む）。
		if ( (int) get_option( 'snscs_index_version' ) !== self::VERSION ) {
			update_option( 'snscs_index_cursor', 0, false );
			update_option( 'snscs_index_ready', 0, false );
		}
	}

	/* --------------------------------------------------------------------
	 * 索引を作る
	 * ------------------------------------------------------------------ */

	/**
	 * 発信者1人の、項目ごとの文章。
	 *
	 * @return array<int,string>
	 */
	public static function document( $creator_id ) {
		$post = get_post( $creator_id );
		if ( ! $post ) {
			return array();
		}
		$handles = array();
		foreach ( SNSCS_Accounts::get_for_creator( $creator_id ) as $account ) {
			$handles[] = str_replace( array( '_', '.', '-' ), ' ', $account['handle'] ) . ' ' . $account['handle'] . ' ' . snscs_platform_label( $account['platform'] );
		}
		$names = function ( $taxonomy ) use ( $creator_id ) {
			$terms = wp_get_object_terms( $creator_id, $taxonomy, array( 'fields' => 'names' ) );
			return is_wp_error( $terms ) ? array() : $terms;
		};
		$tags = (array) get_post_meta( $creator_id, SNSCS_Tagger::META, true );
		foreach ( SNSCS_Submissions::stores_for( $creator_id ) as $key => $handle ) {
			$tags[] = $key . ' ' . $handle;
		}
		foreach ( SNSCS_Submissions::links_for( $creator_id ) as $link ) {
			$tags[] = $link['label'];
		}
		$articles = wp_list_pluck( SNSCS_Articles::for_creator( $creator_id, 'publish', 20 ), 'post_title' );
		return array(
			1 => $post->post_title,
			2 => implode( ' ', $handles ),
			3 => implode( ' ', array_merge( $names( SNSCS_Post_Types::TAX_GENRE ), $names( SNSCS_Post_Types::TAX_REGION ) ) ),
			4 => implode( ' ', array_filter( array_map( 'strval', $tags ) ) ),
			5 => mb_substr( wp_strip_all_tags( $post->post_content ), 0, 1500 ),
			6 => implode( ' ', $articles ),
		);
	}

	/**
	 * 発信者1人分を索引に入れ直す（掲載中でなければ消すだけ）。
	 */
	public static function index_creator( $creator_id ) {
		global $wpdb;
		$creator_id = (int) $creator_id;
		self::delete_creator( $creator_id );
		if ( 'publish' !== get_post_status( $creator_id ) || SNSCS_Post_Types::POST_TYPE !== get_post_type( $creator_id ) ) {
			return;
		}
		$terms = array();
		$docs  = array();
		foreach ( self::document( $creator_id ) as $field => $text ) {
			$tokens = SNSCS_Text::tokens( $text );
			if ( ! $tokens ) {
				continue;
			}
			$docs[] = $wpdb->prepare( '(%d, %d, %d)', $creator_id, $field, min( 65535, array_sum( $tokens ) ) );
			$seen   = array();
			foreach ( $tokens as $term => $tf ) {
				$hash = SNSCS_Text::hash( (string) $term );
				if ( isset( $seen[ $hash ] ) ) {
					continue;
				}
				$seen[ $hash ] = true;
				$terms[]       = $wpdb->prepare( '(%d, %d, %d, %d)', $hash, $creator_id, $field, min( 100, $tf ) );
			}
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		foreach ( array_chunk( $terms, 500 ) as $chunk ) {
			$wpdb->query( 'INSERT IGNORE INTO ' . self::terms_table() . ' (term_hash, creator_id, field, tf) VALUES ' . implode( ',', $chunk ) );
		}
		if ( $docs ) {
			$wpdb->query( 'INSERT IGNORE INTO ' . self::docs_table() . ' (creator_id, field, len) VALUES ' . implode( ',', $docs ) );
		}
		// phpcs:enable
		wp_cache_delete( 'snscs_index_stats', 'snscs' );
	}

	public static function delete_creator( $creator_id ) {
		global $wpdb;
		$wpdb->delete( self::terms_table(), array( 'creator_id' => (int) $creator_id ), array( '%d' ) );
		$wpdb->delete( self::docs_table(), array( 'creator_id' => (int) $creator_id ), array( '%d' ) );
	}

	public static function on_approved( $submission_id ) {
		$row = SNSCS_Submissions::get( $submission_id );
		if ( $row && $row['creator_id'] ) {
			self::index_creator( $row['creator_id'] );
		}
	}

	public static function on_save( $post_id ) {
		if ( ! wp_is_post_revision( $post_id ) ) {
			self::index_creator( $post_id );
		}
	}

	public static function on_article_status( $new, $old, $post ) {
		if ( SNSCS_Articles::POST_TYPE === $post->post_type && $new !== $old ) {
			$creator = SNSCS_Articles::creator_of( $post->ID );
			if ( $creator ) {
				self::index_creator( $creator );
			}
		}
	}

	public static function on_delete( $post_id ) {
		if ( SNSCS_Post_Types::POST_TYPE === get_post_type( $post_id ) ) {
			self::delete_creator( $post_id );
		}
	}

	/**
	 * 全員分の索引を少しずつ作る（1回に最大 $limit 人。1時間ごとの処理と、管理画面のボタンから呼ぶ）。
	 *
	 * @return array{done:int,total:int,ready:bool}
	 */
	public static function rebuild_step( $limit = 1000 ) {
		global $wpdb;
		$limit  = $limit ? (int) $limit : 1000;
		$cursor = (int) get_option( 'snscs_index_cursor', 0 );
		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", SNSCS_Post_Types::POST_TYPE ) );
		if ( get_option( 'snscs_index_ready' ) ) {
			return array( 'done' => $total, 'total' => $total, 'ready' => true );
		}
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND ID > %d ORDER BY ID LIMIT %d", SNSCS_Post_Types::POST_TYPE, $cursor, $limit ) );
		$start = microtime( true );
		foreach ( $ids as $id ) {
			self::index_creator( (int) $id );
			$cursor = (int) $id;
			if ( microtime( true ) - $start > 25 ) {
				break;
			}
		}
		update_option( 'snscs_index_cursor', $cursor, false );
		$done = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND ID <= %d", SNSCS_Post_Types::POST_TYPE, $cursor ) );
		if ( count( $ids ) < $limit && $done >= $total ) {
			update_option( 'snscs_index_ready', 1, false );
			update_option( 'snscs_index_version', self::VERSION, false );
		}
		return array( 'done' => $done, 'total' => $total, 'ready' => (bool) get_option( 'snscs_index_ready' ) );
	}

	public static function restart() {
		update_option( 'snscs_index_cursor', 0, false );
		update_option( 'snscs_index_ready', 0, false );
	}

	public static function ready() {
		return (bool) get_option( 'snscs_index_ready' );
	}

	/**
	 * 索引の全体の数字（発信者の数、項目ごとの平均の長さ）。
	 */
	public static function stats() {
		$stats = wp_cache_get( 'snscs_index_stats', 'snscs' );
		if ( is_array( $stats ) ) {
			return $stats;
		}
		global $wpdb;
		$docs  = self::docs_table();
		$rows  = $wpdb->get_results( "SELECT field, AVG(len) AS avg_len FROM {$docs} GROUP BY field", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stats = array(
			'n'   => max( 1, (int) $wpdb->get_var( "SELECT COUNT(DISTINCT creator_id) FROM {$docs}" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'avg' => array_map( 'floatval', wp_list_pluck( $rows, 'avg_len', 'field' ) ),
		);
		wp_cache_set( 'snscs_index_stats', $stats, 'snscs', 300 );
		return $stats;
	}

	/* --------------------------------------------------------------------
	 * 検索（候補集めと関連度）
	 * ------------------------------------------------------------------ */

	/**
	 * 言葉に合う発信者と関連度（高い順、最大2000人）。
	 *
	 * @return array<int,float> 発信者ID => 関連度。
	 */
	public static function candidates( $q ) {
		global $wpdb;
		$groups = SNSCS_Text::query_groups( $q );
		if ( ! $groups ) {
			return array();
		}
		$stats   = self::stats();
		$all     = array();
		$weight  = array();
		foreach ( $groups as $g => $group ) {
			foreach ( $group['terms'] as $hash => $w ) {
				$all[ $hash ]    = true;
				$weight[ $hash ] = max( isset( $weight[ $hash ] ) ? $weight[ $hash ] : 0, $w );
			}
		}
		$hashes = array_map( 'intval', array_keys( $all ) );
		$terms  = self::terms_table();
		$docs   = self::docs_table();
		$in     = implode( ',', $hashes );

		// 単語ごとの「出てくる発信者の数」から珍しさ（idf）を出す。
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$df  = wp_list_pluck( $wpdb->get_results( "SELECT term_hash, COUNT(DISTINCT creator_id) AS df FROM {$terms} WHERE term_hash IN ({$in}) GROUP BY term_hash", ARRAY_A ), 'df', 'term_hash' );
		$idf = array();
		foreach ( $hashes as $hash ) {
			$n            = isset( $df[ $hash ] ) ? (int) $df[ $hash ] : 0;
			$idf[ $hash ] = log( 1 + ( $stats['n'] - $n + 0.5 ) / ( $n + 0.5 ) ) * $weight[ $hash ];
		}


		$num  = function ( $v ) {
			return sprintf( '%.5F', (float) $v );
		};
		$idf_case = 'CASE t.term_hash';
		foreach ( $hashes as $hash ) {
			$idf_case .= ' WHEN ' . $hash . ' THEN ' . $num( $idf[ $hash ] );
		}
		$idf_case .= ' ELSE 0 END';
		$fw_case   = 'CASE t.field';
		$avg_case  = 'CASE t.field';
		foreach ( self::fields() as $id => $def ) {
			$fw_case  .= ' WHEN ' . (int) $id . ' THEN ' . $num( $def[1] );
			$avg_case .= ' WHEN ' . (int) $id . ' THEN ' . $num( max( 1, isset( $stats['avg'][ $id ] ) ? $stats['avg'][ $id ] : 1 ) );
		}
		$fw_case  .= ' ELSE 1 END';
		$avg_case .= ' ELSE 1 END';
		$k1        = $num( self::K1 );
		$b         = $num( self::B );

		$score = "SUM({$idf_case} * {$fw_case} * (t.tf * ({$k1} + 1)) / (t.tf + {$k1} * (1 - {$b} + {$b} * d.len / {$avg_case})))";
		// 単語の一致の数を、言葉ごとに数える（「料理 時短」なら、料理の単語と時短の単語の両方が必要）。
		// 言い換え（インスタ → instagram など）は、どれか1つの書き方の単語がそろえば一致とみなす。
		// 長い言葉は、単語の6割がそろえば一致（言葉が長いほど少しゆるく）。
		$having = array();
		foreach ( $groups as $group ) {
			$any = array();
			foreach ( $group['variants'] as $variant ) {
				$count = count( $variant );
				$need  = max( 1, (int) ceil( $count * ( $count > 3 ? 0.6 : 1.0 ) ) );
				$any[] = 'COUNT(DISTINCT CASE WHEN t.term_hash IN (' . implode( ',', array_map( 'intval', $variant ) ) . ') THEN t.term_hash END) >= ' . $need;
			}
			$having[] = '(' . implode( ' OR ', $any ) . ')';
		}
		$rows = $wpdb->get_results(
			"SELECT t.creator_id, {$score} AS score FROM {$terms} t INNER JOIN {$docs} d ON d.creator_id = t.creator_id AND d.field = t.field
			WHERE t.term_hash IN ({$in}) GROUP BY t.creator_id HAVING " . implode( ' AND ', $having ) . ' ORDER BY score DESC LIMIT ' . self::MAX_CANDIDATES,
			ARRAY_A
		);
		// phpcs:enable
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['creator_id'] ] = (float) $row['score'];
		}
		return $out;
	}

	/**
	 * 検索の意図: name（人の名前・ハンドル）/ genre / region / topic（話題）。
	 */
	public static function intent( $q ) {
		global $wpdb;
		$q = trim( (string) $q );
		if ( '' === $q ) {
			return '';
		}
		$plain = ltrim( $q, '@' );
		$hit   = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_title = %s LIMIT 1", SNSCS_Post_Types::POST_TYPE, $q ) );
		if ( ! $hit ) {
			$accounts = SNSCS_Accounts::table();
			$hit      = $wpdb->get_var( $wpdb->prepare( "SELECT creator_id FROM {$accounts} WHERE handle = %s LIMIT 1", $plain ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( $hit || 0 === strpos( $q, '@' ) ) {
			return 'name';
		}
		if ( get_term_by( 'name', $q, SNSCS_Post_Types::TAX_GENRE ) ) {
			return 'genre';
		}
		if ( get_term_by( 'name', $q, SNSCS_Post_Types::TAX_REGION ) ) {
			return 'region';
		}
		return 'topic';
	}

	/**
	 * 結果が0件のときの「もしかして」。ジャンル・地域・人気のキーワード・言い換え辞書・発信者名から、いちばん近い言葉。
	 */
	public static function did_you_mean( $q ) {
		$q = SNSCS_Text::normalize( $q );
		if ( mb_strlen( $q ) < 2 || mb_strlen( $q ) > 30 ) {
			return '';
		}
		$words = array();
		foreach ( array( SNSCS_Post_Types::TAX_GENRE, SNSCS_Post_Types::TAX_REGION ) as $taxonomy ) {
			$names = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true, 'fields' => 'names', 'number' => 300 ) );
			$words = array_merge( $words, is_wp_error( $names ) ? array() : $names );
		}
		$words = array_merge( $words, SNSCS_Trends::lists( 30 )['popular'] );
		foreach ( SNSCS_Text::synonyms() as $group ) {
			$words = array_merge( $words, $group );
		}
		$top   = SNSCS_Scores::top( 300 );
		$words = array_merge( $words, wp_list_pluck( $top, 'post_title' ) );

		$best  = '';
		$score = PHP_INT_MAX;
		$limit = max( 1, (int) floor( mb_strlen( $q ) / 3 ) );
		foreach ( array_unique( $words ) as $word ) {
			$norm = SNSCS_Text::normalize( $word );
			if ( $norm === $q || abs( mb_strlen( $norm ) - mb_strlen( $q ) ) > $limit ) {
				continue;
			}
			$d = SNSCS_Text::distance( $q, $norm );
			if ( $d <= $limit && $d < $score ) {
				$score = $d;
				$best  = $word;
			}
		}
		return $best;
	}

	/* --------------------------------------------------------------------
	 * 入力中の候補（サジェスト）
	 * ------------------------------------------------------------------ */

	public static function register_routes() {
		register_rest_route(
			'snscs/v1',
			'/suggest',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_suggest' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'q' => array( 'type' => 'string', 'required' => true ) ),
			)
		);
	}

	/**
	 * @return array<int,array{label:string,type:string}>
	 */
	public static function suggest( $q, $limit = 8 ) {
		global $wpdb;
		$q = trim( (string) $q );
		if ( mb_strlen( $q ) < 1 || mb_strlen( $q ) > 40 ) {
			return array();
		}
		$norm = SNSCS_Text::normalize( $q );
		$out  = array();
		$add  = function ( $label, $type ) use ( &$out ) {
			if ( ! isset( $out[ $label ] ) ) {
				$out[ $label ] = array( 'label' => $label, 'type' => $type );
			}
		};
		foreach ( array_merge( SNSCS_Trends::lists( 20 )['popular'], SNSCS_Trends::lists( 20 )['rising'] ) as $term ) {
			if ( 0 === mb_strpos( SNSCS_Text::normalize( $term ), $norm ) ) {
				$add( $term, 'keyword' );
			}
		}
		$genres = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => true, 'name__like' => $q, 'number' => 5 ) );
		foreach ( is_wp_error( $genres ) ? array() : $genres as $genre ) {
			$add( $genre->name, 'genre' );
		}
		$stats = SNSCS_Scores::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT p.post_title FROM {$wpdb->posts} p LEFT JOIN {$stats} st ON st.creator_id = p.ID WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_title LIKE %s ORDER BY COALESCE(st.score, 0) DESC LIMIT 6", SNSCS_Post_Types::POST_TYPE, $wpdb->esc_like( $q ) . '%' ) );
		foreach ( $names as $name ) {
			$add( $name, 'creator' );
		}
		return array_slice( array_values( $out ), 0, $limit );
	}

	public static function rest_suggest( WP_REST_Request $request ) {
		$q        = (string) $request->get_param( 'q' );
		$key      = 'snscs_sg_' . md5( SNSCS_Text::normalize( $q ) );
		$items    = get_transient( $key );
		if ( ! is_array( $items ) ) {
			$items = self::suggest( $q );
			set_transient( $key, $items, 10 * MINUTE_IN_SECONDS );
		}
		$response = rest_ensure_response( $items );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}
}
