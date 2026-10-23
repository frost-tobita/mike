<?php
/**
 * 自動投稿の記事の種類（SNSCS_Autopost の拡張）。
 *
 *   column  SNS運用コラム（これまでどおり。テーマ一覧から）
 *   trend   トレンド記事: ジャンルで急上昇した言葉ごとに、その話題で発信するコツと人気の発信者
 *           （毎日10時以降。1日の本数は設定。仕上がったらすぐ公開）
 *   report  月刊「SNSトレンドレポート」: 前の月の検索・フォロワーの伸び・新しい発信者の数字から
 *           （毎月1日の8時以降。仕上がったらすぐ公開）
 *   guide   「ジャンル × SNS」の攻略ガイド: 発信者の多い組み合わせから順に、コラムと交互に書きためる
 *
 * 数字や発信者の名前は AI に作らせない。表・発信者の一覧はこのクラスが本物のデータで記事に差し込み
 * （[sns_column_block]。表示のたびに最新の発信者を出す。レポートの表は書いた月の数字のまま）、
 * AI には与えた数字だけを使って解説を書いてもらう。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Column_Kinds {

	const META_KIND   = '_snscs_column_kind';
	const META_TERM   = '_snscs_column_term';
	const META_GENRE  = '_snscs_column_genre';
	const META_SNS    = '_snscs_column_sns';
	const META_REPORT = '_snscs_column_report';
	const TREND_HOUR  = 10;
	const REPORT_HOUR = 8;
	const TREND_MIN   = 5;
	const GUIDE_MIN   = 10;
	const REPORT_MIN  = 30;

	public static function init() {
		add_shortcode( 'sns_column_block', array( __CLASS__, 'block_shortcode' ) );
	}

	public static function labels() {
		return array(
			'column' => 'SNS運用コラム',
			'trend'  => 'トレンド記事',
			'report' => 'SNSトレンドレポート',
			'guide'  => '攻略ガイド',
		);
	}

	public static function kind_of( $post_id ) {
		$kind = (string) get_post_meta( $post_id, self::META_KIND, true );
		return isset( self::labels()[ $kind ] ) ? $kind : 'column';
	}

	/* --------------------------------------------------------------------
	 * 1. トレンド記事
	 * ------------------------------------------------------------------ */

	/**
	 * 書く候補（急上昇・直近7日に5人以上・30日以内に同じ言葉で書いていない。人数の多い順）。
	 *
	 * @return array<int,array{term:string,genre:string,visitors:int}>
	 */
	public static function trend_candidates( array $done = array() ) {
		$found = array();
		foreach ( SNSCS_Trends::genres_with_trends() as $genre ) {
			$stats = SNSCS_Trends::stats( $genre->slug );
			foreach ( $stats['hot'] as $term ) {
				$visitors = (int) ( $stats['rising'][ $term ] ?? 0 );
				if ( $visitors < self::TREND_MIN || isset( $done[ $term ] ) || isset( $found[ $term ] ) || self::recent_post_for( $term ) ) {
					continue;
				}
				$found[ $term ] = array( 'term' => $term, 'genre' => $genre->slug, 'visitors' => $visitors );
				break; // 1ジャンル1語まで。
			}
		}
		usort(
			$found,
			function ( $a, $b ) {
				return $b['visitors'] - $a['visitors'];
			}
		);
		return array_values( $found );
	}

	/**
	 * その言葉のトレンド記事（30日以内）があるか。
	 */
	public static function recent_post_for( $term ) {
		return (bool) get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'publish', 'draft', 'future', 'pending' ),
				'meta_key'    => self::META_TERM, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $term, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'date_query'  => array( array( 'after' => '30 days ago' ) ),
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);
	}

	private static function trend_prompt( array $item ) {
		$genre   = get_term_by( 'slug', $item['genre'], SNSCS_Post_Types::TAX_GENRE );
		$name    = $genre ? $genre->name : '';
		$series  = SNSCS_Trend_Pages::series( $item['term'] );
		$others  = array_values( array_diff( wp_list_pluck( SNSCS_Trends::ranking( $item['genre'], 10 ), 'term' ), array( $item['term'] ) ) );
		$found   = SNSCS_Search::run( array( 'q' => $item['term'], 'per_page' => 1 ) );
		$lines   = array();
		$lines[] = '次の「トレンド記事」を書いてください。';
		$lines[] = 'テーマ: 「' . $item['term'] . '」（' . $name . 'ジャンルで、このサイトの検索が急に増えている言葉）';
		$lines[] = '';
		$lines[] = '記事の目的: この話題でSNS発信したい人に、すぐ使える企画・投稿の切り口・見せ方・注意点を伝え、読者にはこの話題の発信者を探して楽しむ方法を伝える。';
		$lines[] = '- タイトルは「' . $item['term'] . '」を前半に入れ、32字前後。例の型:「' . $item['term'] . 'のSNS発信アイデア○選｜' . $name . 'で伸びる投稿の作り方」';
		$lines[] = '- なぜこの言葉が話題になっているのか（ニュースや出来事）は、こちらでは分からない。理由を推測して事実のように書かない。「季節やイベントで関心が高まりやすい」のような一般的な言い方にとどめる。';
		$lines[] = '- 章は5〜6個。「この話題で発信する前に」「企画のアイデア（具体例を5つ以上）」「SNSごとの見せ方（各SNSの特徴に合わせて）」「投稿で気をつけること」「この話題の発信者の探し方」「まとめ」を含める。';
		$lines[] = '- 本文に発信者の名前は書かない（記事の中に、このサイトの本物のデータで「人気の発信者」の一覧を自動で差し込みます）。「下の一覧」「このサイトのトレンドのページ」と書いてよい。';
		$lines[] = '- 数字は、下の「このサイトのデータ」にあるものだけを使う。';
		$lines[] = '';
		$lines[] = 'このサイトのデータ:';
		$lines[] = '- ここ7日で「' . $item['term'] . '」を検索した人: ' . number_format_i18n( SNSCS_Trend_Pages::visitors( $item['term'], 7 ) ) . '人';
		$lines[] = '- 日ごとの検索した人（古い順・14日）: ' . implode( ', ', array_values( $series ) );
		if ( $found['total'] ) {
			$lines[] = '- この言葉で見つかる発信者: ' . number_format_i18n( $found['total'] ) . '人';
		}
		if ( $others ) {
			$lines[] = '- 同じジャンルで調べられているほかの言葉: ' . implode( '、', array_slice( $others, 0, 8 ) );
		}
		return $lines;
	}

	/* --------------------------------------------------------------------
	 * 2. 月刊SNSトレンドレポート
	 * ------------------------------------------------------------------ */

	/**
	 * 月の数字をまとめる（記事の表と、AIへの材料）。
	 *
	 * @param string $month "2026-10"
	 * @return array
	 */
	public static function report_data( $month ) {
		global $wpdb;
		$start      = $month . '-01';
		$end        = gmdate( 'Y-m-t', strtotime( $start ) );
		$prev_start = gmdate( 'Y-m-01', strtotime( $start . ' -1 month' ) );
		$prev_end   = gmdate( 'Y-m-t', strtotime( $prev_start ) );
		$terms      = SNSCS_Trends::terms_table();
		$gterms     = SNSCS_Trends::genre_table();
		$min        = max( 3, (int) SNSCS_Hub::setting( 'trend_min_visitors' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(visitors) FROM {$terms} WHERE day BETWEEN %s AND %s", $start, $end ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT term, SUM(visitors) AS n FROM {$terms} WHERE day BETWEEN %s AND %s GROUP BY term HAVING n >= %d ORDER BY n DESC, term ASC LIMIT 60", $start, $end, $min ), ARRAY_A );
		$prev  = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT term, SUM(visitors) AS n FROM {$terms} WHERE day BETWEEN %s AND %s GROUP BY term", $prev_start, $prev_end ), ARRAY_A ) as $row ) {
			$prev[ $row['term'] ] = (int) $row['n'];
		}
		// phpcs:enable
		$top    = array();
		$rising = array();
		foreach ( $rows as $row ) {
			if ( ! SNSCS_Trends::allowed( $row['term'] ) || false !== strpos( $row['term'], '/' ) ) {
				continue;
			}
			$n    = (int) $row['n'];
			$p    = $prev[ $row['term'] ] ?? 0;
			$item = array( 'term' => (string) $row['term'], 'visitors' => $n, 'previous' => $p );
			if ( count( $top ) < 10 ) {
				$top[] = $item;
			}
			if ( $n >= max( 5, $min ) && $n > $p * 1.5 ) {
				$rising[] = $item + array( 'ratio' => ( $n + 1 ) / ( $p + 1 ) );
			}
		}
		usort(
			$rising,
			function ( $a, $b ) {
				return $b['ratio'] <=> $a['ratio'];
			}
		);
		$rising = array_map(
			function ( $r ) {
				unset( $r['ratio'] );
				return $r;
			},
			array_slice( $rising, 0, 5 )
		);

		// ジャンルごと: 検索した人の合計と上位3語。
		$genres = array();
		$gterms_all = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => false ) );
		foreach ( is_wp_error( $gterms_all ) ? array() : $gterms_all as $genre ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sum = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(visitors) FROM {$gterms} WHERE genre = %s AND day BETWEEN %s AND %s", $genre->slug, $start, $end ) );
			if ( ! $sum ) {
				continue;
			}
			$words = $wpdb->get_results( $wpdb->prepare( "SELECT term, SUM(visitors) AS n FROM {$gterms} WHERE genre = %s AND day BETWEEN %s AND %s GROUP BY term HAVING n >= %d ORDER BY n DESC, term ASC LIMIT 10", $genre->slug, $start, $end, $min ), ARRAY_A );
			// phpcs:enable
			$list = array();
			foreach ( $words as $word ) {
				if ( SNSCS_Trends::allowed( $word['term'] ) && count( $list ) < 3 ) {
					$list[] = array( 'term' => (string) $word['term'], 'visitors' => (int) $word['n'] );
				}
			}
			$genres[] = array( 'slug' => $genre->slug, 'name' => $genre->name, 'visitors' => $sum, 'top' => $list );
		}
		usort(
			$genres,
			function ( $a, $b ) {
				return $b['visitors'] - $a['visitors'];
			}
		);

		// ジャンルごとのフォロワーの伸び（公式の数を記録している発信者だけ。月の最初と最後の記録の差）。
		$history = SNSCS_Sync::history_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hist = $wpdb->get_results( $wpdb->prepare( "SELECT creator_id, platform, day, followers FROM {$history} WHERE day BETWEEN %s AND %s ORDER BY day", $start, $end ), ARRAY_A );
		$first = array();
		$last  = array();
		foreach ( $hist as $row ) {
			$k = $row['creator_id'] . '|' . $row['platform'];
			if ( ! isset( $first[ $k ] ) ) {
				$first[ $k ] = $row;
			}
			$last[ $k ] = $row;
		}
		$by_creator = array();
		foreach ( $first as $k => $row ) {
			if ( $last[ $k ]['day'] === $row['day'] ) {
				continue;
			}
			$id                 = (int) $row['creator_id'];
			$by_creator[ $id ]  = $by_creator[ $id ] ?? array( 0, 0 );
			$by_creator[ $id ][0] += (int) $last[ $k ]['followers'] - (int) $row['followers'];
			$by_creator[ $id ][1] += (int) $row['followers'];
		}
		$growth = array();
		foreach ( $by_creator as $id => $g ) {
			$terms_of = get_the_terms( $id, SNSCS_Post_Types::TAX_GENRE );
			foreach ( ( $terms_of && ! is_wp_error( $terms_of ) ) ? $terms_of : array() as $t ) {
				$growth[ $t->term_id ] = $growth[ $t->term_id ] ?? array( 'name' => $t->name, 'gain' => 0, 'base' => 0, 'creators' => 0 );
				$growth[ $t->term_id ]['gain']     += $g[0];
				$growth[ $t->term_id ]['base']     += $g[1];
				$growth[ $t->term_id ]['creators'] += 1;
			}
		}
		$growth = array_values(
			array_filter(
				$growth,
				function ( $g ) {
					return $g['creators'] >= 3 && $g['base'] > 0;
				}
			)
		);
		foreach ( $growth as &$g ) {
			$g['rate'] = round( $g['gain'] / $g['base'] * 100, 1 );
		}
		unset( $g );
		usort(
			$growth,
			function ( $a, $b ) {
				return $b['rate'] <=> $a['rate'];
			}
		);

		// 新しく掲載された発信者。
		$new = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_date BETWEEN %s AND %s", SNSCS_Post_Types::POST_TYPE, $start . ' 00:00:00', $end . ' 23:59:59' ) );
		$all = (int) wp_count_posts( SNSCS_Post_Types::POST_TYPE )->publish;

		return array(
			'month'    => $month,
			'label'    => gmdate( 'Y年n月', strtotime( $start ) ),
			'searches' => $total,
			'top'      => $top,
			'rising'   => $rising,
			'genres'   => array_slice( $genres, 0, 12 ),
			'growth'   => array_slice( $growth, 0, 8 ),
			'new'      => $new,
			'creators' => $all,
		);
	}

	private static function report_prompt( array $item ) {
		$data    = $item['data'];
		$lines   = array();
		$lines[] = '次の「月刊SNSトレンドレポート」を書いてください。';
		$lines[] = 'テーマ: ' . $data['label'] . 'のSNSトレンドレポート';
		$lines[] = '';
		$lines[] = '- タイトルは「' . $data['label'] . 'のSNSトレンドレポート」で始め、いちばん目立つ動き（急上昇した言葉や、伸びたジャンル）を後半に入れる（40字以内）。';
		$lines[] = '- 記事の最初（リード文のあと）に、下のデータの表（検索の上位・急上昇・ジャンル別・フォロワーの伸び）を自動で差し込みます。本文で表を作り直さない。';
		$lines[] = '- 章は4〜6個。「今月の全体の動き」「急上昇した言葉から見えること」「ジャンル別の動き」「フォロワーが伸びたジャンル」（データがあるときだけ）「来月に向けて発信者ができること」「まとめ」。';
		$lines[] = '- 数字は下のJSONにあるものだけを使い、計算で出せる比率（前の月の何倍か、など）以外は作らない。データが少ない項目は「まだデータが少ない」と正直に書く。';
		$lines[] = '- 言葉が話題になった理由（ニュースや出来事）は分からないので推測で断定しない。季節の行事など、一般的に考えられる背景は「〜の時期でもあり」のように控えめに。';
		$lines[] = '- 発信者の個人名は書かない。本文は3000〜4500字。';
		$lines[] = '';
		$lines[] = 'このサイトのデータ（JSON。visitors は調べた人の数、previous は前の月、gain はフォロワーの増加数、rate は増加率%）:';
		$lines[] = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
		return $lines;
	}

	/* --------------------------------------------------------------------
	 * 3. 「ジャンル × SNS」の攻略ガイド
	 * ------------------------------------------------------------------ */

	/**
	 * まだ書いていない組み合わせ（発信者の多い順）。
	 *
	 * @param string[] $used "instagram|料理のスラッグ"
	 * @return array{sns:string,genre:WP_Term,count:int}|null
	 */
	public static function next_guide( array $used ) {
		$min = max( self::GUIDE_MIN, (int) SNSCS_Hub::setting( 'feature_min' ) );
		$all = SNSCS_Features::combos();
		arsort( $all );
		foreach ( $all as $key => $count ) {
			list( $sns, $genre_id, $region ) = array_pad( explode( '|', $key ), 3, '' );
			if ( '' === $sns || '' === $genre_id || '' !== $region || $count < $min ) {
				continue;
			}
			$genre = get_term( (int) $genre_id, SNSCS_Post_Types::TAX_GENRE );
			if ( ! $genre || is_wp_error( $genre ) || in_array( $sns . '|' . $genre->slug, $used, true ) ) {
				continue;
			}
			return array( 'sns' => $sns, 'genre' => $genre, 'count' => (int) $count );
		}
		return null;
	}

	public static function guide_topic( $sns, $genre_name ) {
		return $genre_name . 'ジャンルで' . snscs_platform_label( $sns ) . 'を伸ばす方法';
	}

	/**
	 * その組み合わせのフォロワー数の分布（中央値・上位10%）。
	 */
	private static function follower_stats( $sns, $genre_tt_id ) {
		global $wpdb;
		$accounts = SNSCS_Accounts::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT a.followers FROM {$accounts} a INNER JOIN {$wpdb->posts} p ON p.ID = a.creator_id AND p.post_status = 'publish' INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = a.creator_id AND tr.term_taxonomy_id = %d WHERE a.platform = %s ORDER BY a.followers", $genre_tt_id, $sns ) ) );
		if ( ! $counts ) {
			return array();
		}
		return array(
			'median' => $counts[ (int) floor( count( $counts ) / 2 ) ],
			'top10'  => $counts[ (int) floor( count( $counts ) * 0.9 ) ],
		);
	}

	private static function guide_prompt( array $item ) {
		$genre   = get_term_by( 'slug', $item['genre'], SNSCS_Post_Types::TAX_GENRE );
		$name    = $genre ? $genre->name : '';
		$label   = snscs_platform_label( $item['sns'] );
		$stats   = $genre ? self::follower_stats( $item['sns'], (int) $genre->term_taxonomy_id ) : array();
		$words   = SNSCS_Trends::lists( 10, $item['genre'] )['popular'];
		$lines   = array();
		$lines[] = '次の「攻略ガイド」を書いてください。';
		$lines[] = 'テーマ: ' . self::guide_topic( $item['sns'], $name ) . '（' . $name . 'ジャンルの発信者が、' . $label . 'でフォロワーと反応を伸ばすための実践ガイド）';
		$lines[] = '';
		$lines[] = '- タイトルは「' . $name . '」と「' . $label . '」を前半に入れ、32字前後。';
		$lines[] = '- 章は5〜7個。「' . $name . '×' . $label . 'で伸びるアカウントの共通点」「プロフィールの作り方」「投稿の企画（' . $name . 'ならではの例を5つ以上）」「' . $label . 'の機能の使い分け」「続けるための運用の型」「伸び悩んだときの見直し方」「まとめ」。';
		$lines[] = '- ' . $label . 'の仕様やアルゴリズムは、確かめられない形で断定しない。';
		$lines[] = '- 発信者の名前は書かない（記事の中に、このサイトの本物のデータで「人気の発信者」の一覧を自動で差し込みます）。数字は下のデータにあるものだけを使う。';
		$lines[] = '';
		$lines[] = 'このサイトのデータ:';
		$lines[] = '- このサイトに掲載中の「' . $name . '×' . $label . '」の発信者: ' . number_format_i18n( $item['count'] ) . '人';
		if ( $stats ) {
			$lines[] = '- その人たちの' . $label . 'のフォロワー数: 真ん中の人で' . number_format_i18n( $stats['median'] ) . '人、上位10%の人で' . number_format_i18n( $stats['top10'] ) . '人以上';
		}
		if ( $words ) {
			$lines[] = '- このジャンルでよく検索されている言葉（企画のヒント）: ' . implode( '、', $words );
		}
		return $lines;
	}

	/* --------------------------------------------------------------------
	 * 自動投稿とのつなぎ
	 * ------------------------------------------------------------------ */

	/**
	 * 種類ごとの依頼文（コラムなら null を返し、これまでの依頼文を使う）。
	 */
	public static function user_prompt( array $item ) {
		$kind = $item['kind'] ?? 'column';
		if ( 'trend' === $kind ) {
			$lines = self::trend_prompt( $item );
		} elseif ( 'report' === $kind ) {
			$lines = self::report_prompt( $item );
		} elseif ( 'guide' === $kind ) {
			$lines = self::guide_prompt( $item );
		} else {
			return null;
		}
		$genres  = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => false, 'fields' => 'names', 'number' => 100 ) );
		$lines[] = '';
		$lines[] = 'SNSのキー: ' . implode( ', ', array_keys( snscs_platforms() ) ) . '（' . implode( '、', snscs_platforms() ) . '）';
		if ( $genres && ! is_wp_error( $genres ) ) {
			$lines[] = 'ジャンル一覧: ' . implode( '、', $genres );
		}
		return implode( "\n", $lines );
	}

	/**
	 * 種類ごとの、記事として認める最低の字数。
	 */
	public static function min_chars( array $item ) {
		$kind = $item['kind'] ?? 'column';
		return array( 'trend' => 3000, 'report' => 2500, 'guide' => 3500 )[ $kind ] ?? SNSCS_Autopost::MIN_CHARS;
	}

	/**
	 * 本文に、本物のデータの一覧・表を差し込む。
	 *   trend・guide: 「まとめ」（最後の章）の前に、人気の発信者の一覧
	 *   report: リード文と目次のあと（最初の章の前）に、数字の表
	 */
	public static function decorate( $content, array $item ) {
		$kind = $item['kind'] ?? 'column';
		if ( 'trend' === $kind ) {
			$code = sprintf( '[sns_column_block type="trend" term="%s" genre="%s"]', esc_attr( $item['term'] ), esc_attr( $item['genre'] ) );
		} elseif ( 'guide' === $kind ) {
			$code = sprintf( '[sns_column_block type="guide" sns="%s" genre="%s"]', esc_attr( $item['sns'] ), esc_attr( $item['genre'] ) );
		} elseif ( 'report' === $kind ) {
			$code = '[sns_column_block type="report"]';
			$pos  = strpos( $content, '<h2 id="section-1"' );
			return false === $pos ? $code . $content : substr( $content, 0, $pos ) . $code . "\n" . substr( $content, $pos );
		} else {
			return $content;
		}
		$pos = strrpos( $content, '<h2' );
		return false === $pos ? $content . $code : substr( $content, 0, $pos ) . $code . "\n" . substr( $content, $pos );
	}

	/**
	 * 保存した記事に、種類と材料を記録する。
	 */
	public static function remember( $post_id, array $item ) {
		$kind = $item['kind'] ?? 'column';
		update_post_meta( $post_id, self::META_KIND, $kind );
		if ( 'trend' === $kind ) {
			update_post_meta( $post_id, self::META_TERM, $item['term'] );
			update_post_meta( $post_id, self::META_GENRE, $item['genre'] );
			$genre = get_term_by( 'slug', $item['genre'], SNSCS_Post_Types::TAX_GENRE );
			if ( $genre ) {
				wp_set_object_terms( $post_id, array( (int) $genre->term_id ), SNSCS_Post_Types::TAX_GENRE, true );
			}
		} elseif ( 'guide' === $kind ) {
			update_post_meta( $post_id, self::META_GENRE, $item['genre'] );
			update_post_meta( $post_id, self::META_SNS, $item['sns'] );
			$genre = get_term_by( 'slug', $item['genre'], SNSCS_Post_Types::TAX_GENRE );
			if ( $genre ) {
				wp_set_object_terms( $post_id, array( (int) $genre->term_id ), SNSCS_Post_Types::TAX_GENRE );
			}
			wp_set_object_terms( $post_id, array( $item['sns'] ), SNSCS_Columns::TAX_SNS );
		} elseif ( 'report' === $kind ) {
			update_post_meta( $post_id, self::META_REPORT, wp_slash( wp_json_encode( $item['data'], JSON_UNESCAPED_UNICODE ) ) );
		}
	}

	/**
	 * トレンド記事・レポートを頼む（1時間ごとの見回りから）。
	 *
	 * @param array $state SNSCS_Autopost の状態（trend_done・report_done を使う）。
	 * @return array{items:array<string,array>,report_month:string} 頼む分（custom_id => item）と、レポートを済ませた月。
	 */
	public static function due_items( array $state, $force = false ) {
		$s      = SNSCS_Autopost::settings();
		$today  = current_time( 'Y-m-d' );
		$items  = array();
		$closed = '';

		// 月刊レポート（前の月。検索の記録が少なすぎる月は書かない）。
		$month = gmdate( 'Y-m', strtotime( current_time( 'Y-m-01' ) . ' -1 month' ) );
		if ( $s['report'] && ( $force || ( (int) current_time( 'G' ) >= self::REPORT_HOUR && ( $state['report_done'] ?? '' ) !== $month ) ) ) {
			$data = self::report_data( $month );
			if ( $data['searches'] >= self::REPORT_MIN ) {
				$items[ 'rp-' . str_replace( '-', '', $month ) ] = array( 'topic' => $data['label'] . 'のSNSトレンドレポート', 'kind' => 'report', 'month' => $month, 'data' => $data );
			} else {
				SNSCS_Autopost::log( $data['label'] . 'のSNSトレンドレポートは、検索の記録が少ないため書きませんでした。' );
			}
			// 同じ月は1回だけ（記録が少なかった月も、もう一度は試さない）。
			$closed = $month;
		}

		// トレンド記事（1日の本数まで）。
		$per_day = (int) $s['trend_per_day'];
		$done    = (array) ( $state['trend_done'] ?? array() );
		$today_n = count( array_filter( $done, function ( $day ) use ( $today ) { return $day === $today; } ) );
		if ( $per_day > 0 && ( $force || (int) current_time( 'G' ) >= self::TREND_HOUR ) && $today_n < $per_day ) {
			foreach ( array_slice( self::trend_candidates( $done ), 0, $per_day - $today_n ) as $i => $c ) {
				$items[ 'tr-' . time() . '-' . $i ] = array( 'topic' => '「' . $c['term'] . '」のトレンド記事', 'kind' => 'trend', 'term' => $c['term'], 'genre' => $c['genre'] );
			}
		}
		return array( 'items' => $items, 'report_month' => $closed );
	}

	/* --------------------------------------------------------------------
	 * 記事に差し込む一覧・表（[sns_column_block]）
	 * ------------------------------------------------------------------ */

	public static function block_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'type' => '', 'term' => '', 'genre' => '', 'sns' => '' ), $atts, 'sns_column_block' );
		wp_enqueue_style( 'snscs' );
		if ( 'trend' === $atts['type'] ) {
			return self::trend_block( SNSCS_Trends::normalize( $atts['term'] ), sanitize_title( $atts['genre'] ) );
		}
		if ( 'guide' === $atts['type'] ) {
			return self::guide_block( sanitize_key( $atts['sns'] ), sanitize_title( $atts['genre'] ) );
		}
		if ( 'report' === $atts['type'] ) {
			$data = json_decode( (string) get_post_meta( get_the_ID(), self::META_REPORT, true ), true );
			return is_array( $data ) ? self::report_block( $data ) : '';
		}
		return '';
	}

	private static function cards( array $args, $heading ) {
		$result = SNSCS_Search::run( array_merge( array( 'sort' => 'recommended', 'per_page' => 6 ), $args ) );
		if ( ! $result['items'] ) {
			return '';
		}
		ob_start();
		echo '<h2 class="snscs-colblock__title">' . esc_html( $heading ) . '</h2>';
		SNSCS_Frontend::render_cards( $result['items'] );
		return ob_get_clean();
	}

	private static function trend_block( $term, $genre ) {
		if ( '' === $term ) {
			return '';
		}
		$week  = SNSCS_Trend_Pages::visitors( $term, 7 );
		$html  = '<aside class="snscs-colblock">';
		$html .= '<p class="snscs-colblock__lead">「' . esc_html( $term ) . '」は、ここ7日で' . esc_html( number_format_i18n( $week ) ) . '人が' . esc_html( get_bloginfo( 'name' ) ) . 'で検索しています。<a href="' . esc_url( SNSCS_Trend_Pages::url( $term ) ) . '">検索の推移と人気の発信者を見る</a></p>';
		$cards = self::cards( array( 'q' => $term ), '「' . $term . '」で人気の発信者' );
		// その言葉で見つかる人がいないときは、ジャンルの人気の発信者。
		$named = '' !== $genre ? get_term_by( 'slug', $genre, SNSCS_Post_Types::TAX_GENRE ) : null;
		if ( '' === $cards && $named ) {
			$cards = self::cards( array( 'genre' => $named->slug ), $named->name . 'ジャンルで人気の発信者' );
		}
		$html .= $cards;
		$more  = array_values( array_diff( wp_list_pluck( SNSCS_Trends::ranking( $genre, 9 ), 'term' ), array( $term ) ) );
		if ( $more ) {
			$html .= '<p class="snscs-colblock__sub">いま検索されているほかの言葉</p><ul class="snscs-chips">';
			foreach ( array_slice( $more, 0, 8 ) as $word ) {
				$html .= '<li><a class="snscs-chip" href="' . esc_url( SNSCS_Trend_Pages::url( $word ) ) . '">' . esc_html( $word ) . '</a></li>';
			}
			$html .= '</ul>';
		}
		return $html . '</aside>';
	}

	private static function guide_block( $sns, $genre_slug ) {
		$genre = get_term_by( 'slug', $genre_slug, SNSCS_Post_Types::TAX_GENRE );
		if ( ! $genre || ! array_key_exists( $sns, snscs_platforms() ) ) {
			return '';
		}
		$label = snscs_platform_label( $sns );
		$html  = '<aside class="snscs-colblock">';
		$html .= self::cards( array( 'platform' => $sns, 'genre' => $genre->slug ), $genre->name . '×' . $label . 'で人気の発信者' );
		$html .= '<p class="snscs-colblock__more"><a class="snscs-button snscs-button--primary" href="' . esc_url( SNSCS_Features::url( $sns, $genre->slug ) ) . '">' . esc_html( $genre->name . 'の' . $label . '発信者をもっと見る' ) . '</a></p>';
		return $html . '</aside>';
	}

	private static function report_block( array $d ) {
		$num  = function ( $n ) {
			return esc_html( number_format_i18n( (int) $n ) );
		};
		$link = function ( $term ) {
			return '<a href="' . esc_url( SNSCS_Trend_Pages::url( $term ) ) . '">' . esc_html( $term ) . '</a>';
		};
		$html  = '<aside class="snscs-colblock snscs-colblock--report">';
		$html .= '<dl class="snscs-trendterm__stats"><div><dt>検索した人（のべ）</dt><dd>' . $num( $d['searches'] ) . '人</dd></div><div><dt>新しく掲載された発信者</dt><dd>' . $num( $d['new'] ) . '人</dd></div><div><dt>掲載中の発信者</dt><dd>' . $num( $d['creators'] ) . '人</dd></div></dl>';
		if ( $d['top'] ) {
			$html .= '<h2 class="snscs-colblock__title">' . esc_html( $d['label'] ) . 'によく検索された言葉</h2><table><thead><tr><th>順位</th><th>言葉</th><th>検索した人</th><th>前の月</th></tr></thead><tbody>';
			foreach ( $d['top'] as $i => $r ) {
				$html .= '<tr><td>' . ( $i + 1 ) . '</td><td>' . $link( $r['term'] ) . '</td><td>' . $num( $r['visitors'] ) . '人</td><td>' . $num( $r['previous'] ) . '人</td></tr>';
			}
			$html .= '</tbody></table>';
		}
		if ( $d['rising'] ) {
			$html .= '<h3>急上昇した言葉</h3><table><thead><tr><th>言葉</th><th>検索した人</th><th>前の月から</th></tr></thead><tbody>';
			foreach ( $d['rising'] as $r ) {
				$html .= '<tr><td>' . $link( $r['term'] ) . '</td><td>' . $num( $r['visitors'] ) . '人</td><td>' . ( $r['previous'] ? esc_html( sprintf( '%.1f倍', $r['visitors'] / $r['previous'] ) ) : '新しく登場' ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
		}
		if ( $d['genres'] ) {
			$html .= '<h3>ジャンル別の検索</h3><table><thead><tr><th>ジャンル</th><th>検索した人</th><th>よく検索された言葉</th></tr></thead><tbody>';
			foreach ( $d['genres'] as $g ) {
				$words = array_map(
					function ( $w ) use ( $link ) {
						return $link( $w['term'] );
					},
					$g['top']
				);
				$html .= '<tr><td><a href="' . esc_url( SNSCS_Trends::page_url( $g['slug'] ) ) . '">' . esc_html( $g['name'] ) . '</a></td><td>' . $num( $g['visitors'] ) . '人</td><td>' . ( $words ? implode( '、', $words ) : '—' ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
		}
		if ( $d['growth'] ) {
			$html .= '<h3>フォロワーが伸びたジャンル</h3><table><thead><tr><th>ジャンル</th><th>増えたフォロワー</th><th>増加率</th><th>対象の発信者</th></tr></thead><tbody>';
			foreach ( $d['growth'] as $g ) {
				$html .= '<tr><td>' . esc_html( $g['name'] ) . '</td><td>' . esc_html( ( $g['gain'] >= 0 ? '+' : '' ) . number_format_i18n( $g['gain'] ) ) . '人</td><td>' . esc_html( ( $g['rate'] >= 0 ? '+' : '' ) . $g['rate'] ) . '%</td><td>' . $num( $g['creators'] ) . '人</td></tr>';
			}
			$html .= '</tbody></table><p class="snscs-help">フォロワー数は、各SNSの公式の数を記録している発信者の、月の最初と最後の記録の差です。</p>';
		}
		$html .= '<p class="snscs-help">' . esc_html( get_bloginfo( 'name' ) ) . 'での検索をもとにした数字です（同じ人の同じ日の検索は1回と数えています）。<a href="' . esc_url( SNSCS_Trends::page_url() ) . '">いまのトレンドを見る</a></p>';
		return $html . '</aside>';
	}
}
