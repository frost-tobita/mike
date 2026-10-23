<?php
/**
 * コラム（SNS運用コラム・トレンド記事・レポート・攻略ガイド）の中のサイト内リンク。
 *
 * 表示のときに付けるので、これまでの記事にも、トレンドが変わったあとにも効く（本文は書き換えない）。
 *   1. 本文の言葉にリンク: 検索エンジンに載せているトレンドの言葉 → キーワードのページ、
 *      ジャンル名・SNS名 → 特集ページ。言葉ごとに最初の1回だけ、1記事に最大8か所。
 *      見出し・目次・表の見出し・すでにあるリンク・差し込みの一覧（[sns_column_block]）の中には付けない。
 *   2. 記事の最後に「この記事に関連するページ」: この記事のジャンル・SNSの特集ページ、
 *      ジャンルのトレンド、発信者診断への案内。
 * 逆向きのリンク（キーワードのページ → その言葉のコラム）は SNSCS_Trend_Pages が出す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Autolink {

	const MAX_LINKS = 8;
	const CACHE     = 'snscs_autolink_targets';
	const SKIP_TAGS = array( 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'nav', 'th', 'script', 'style', 'code', 'pre', 'button', 'aside' );

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'link_words' ), 8 );
		add_filter( 'the_content', array( __CLASS__, 'append_related' ), 19 );
	}

	private static function applies() {
		return SNSCS_Autopost::settings()['autolink'] && is_singular( 'post' ) && in_the_loop() && is_main_query() && get_the_ID() === get_queried_object_id() && SNSCS_Columns::is_column();
	}

	/**
	 * リンクにする言葉 => URL（長い言葉から。1時間ごとに作り直す）。
	 *
	 * @return array<string,string>
	 */
	public static function targets() {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$targets = array();
		// トレンドの言葉（キーワードのページを検索エンジンに載せているものだけ。2文字の英数字は誤爆しやすいので除く）。
		if ( SNSCS_Hub::page_id( 'trends' ) ) {
			foreach ( SNSCS_Trend_Pages::indexable_terms( 300 ) as $term ) {
				if ( ! preg_match( '/^[a-z0-9 ]{1,2}$/', $term ) ) {
					$targets[ $term ] = SNSCS_Trend_Pages::url( $term );
				}
			}
		}
		// ジャンル名・SNS名 → 特集ページ（特集ページがあり、発信者が足りている組み合わせだけ）。
		if ( SNSCS_Hub::page_id( 'features' ) ) {
			$combos = SNSCS_Features::combos();
			$min    = (int) SNSCS_Hub::setting( 'feature_min' );
			$genres = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => true ) );
			foreach ( is_wp_error( $genres ) ? array() : $genres as $genre ) {
				if ( ( $combos[ '|' . $genre->term_id . '|' ] ?? 0 ) >= $min && mb_strlen( $genre->name ) >= 2 ) {
					$targets[ mb_strtolower( $genre->name ) ] = SNSCS_Features::url( '', $genre->slug );
				}
			}
			foreach ( snscs_platforms() as $key => $label ) {
				if ( mb_strlen( $label ) >= 3 && ( $combos[ $key . '||' ] ?? 0 ) >= $min ) {
					$targets[ mb_strtolower( $label ) ] = SNSCS_Features::url( $key );
				}
			}
		}
		// SNS用語集の言葉 → 用語のページ（トレンド・特集と同じ言葉なら、そちらを優先）。
		$targets += SNSCS_Glossary::autolink_targets();
		uksort(
			$targets,
			function ( $a, $b ) {
				return mb_strlen( $b ) - mb_strlen( $a );
			}
		);
		set_transient( self::CACHE, $targets, HOUR_IN_SECONDS );
		return $targets;
	}

	/**
	 * 本文の言葉にリンクを付ける（ショートコードと、リンクを付けないタグの中は触らない）。
	 */
	public static function link_words( $content ) {
		if ( ! self::applies() ) {
			return $content;
		}
		return self::apply( $content, self::targets() );
	}

	/**
	 * @param array<string,string> $targets 言葉（小文字）=> URL
	 */
	public static function apply( $content, array $targets ) {
		if ( ! $targets ) {
			return $content;
		}
		$own   = untrailingslashit( (string) get_permalink() );
		$used  = array();
		$count = 0;
		$skip  = 0;
		$parts = preg_split( '/(\[[^\]]*\]|<[^>]+>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( '[' === $part[0] ) {
				// 差し込みの一覧（[sns_column_block ...]）などのショートコードはそのまま。
				$out .= $part;
				continue;
			}
			if ( '<' === $part[0] ) {
				if ( preg_match( '#^<(/?)([a-z0-9]+)#i', $part, $m ) && in_array( strtolower( $m[2] ), self::SKIP_TAGS, true ) && '/>' !== substr( $part, -2 ) ) {
					$skip = max( 0, $skip + ( '/' === $m[1] ? -1 : 1 ) );
				}
				$out .= $part;
				continue;
			}
			if ( $skip || $count >= self::MAX_LINKS ) {
				$out .= $part;
				continue;
			}
			$out .= self::link_text( $part, $targets, $used, $count, $own );
		}
		return $out;
	}

	/**
	 * 1つの文字の並びの中で、まだ使っていない言葉の最初の1回にリンクを付ける。
	 */
	private static function link_text( $text, array $targets, array &$used, &$count, $own ) {
		$lower = mb_strtolower( $text );
		$found = array();
		foreach ( $targets as $word => $url ) {
			if ( isset( $used[ $word ] ) || isset( $used[ $url ] ) || untrailingslashit( $url ) === $own ) {
				continue;
			}
			$pos = mb_strpos( $lower, $word );
			if ( false === $pos ) {
				continue;
			}
			// すでに選んだ（長い）言葉と重なるところは使わない。
			$end = $pos + mb_strlen( $word );
			foreach ( $found as $f ) {
				if ( $pos < $f[1] && $end > $f[0] ) {
					continue 2;
				}
			}
			$found[]       = array( $pos, $end, $url );
			$used[ $word ] = true;
			$used[ $url ]  = true;
			if ( ++$count >= self::MAX_LINKS ) {
				break;
			}
		}
		if ( ! $found ) {
			return $text;
		}
		usort(
			$found,
			function ( $a, $b ) {
				return $b[0] - $a[0];
			}
		);
		foreach ( $found as $f ) {
			$word = mb_substr( $text, $f[0], $f[1] - $f[0] );
			$text = mb_substr( $text, 0, $f[0] ) . '<a class="snscs-autolink" href="' . esc_url( $f[2] ) . '">' . $word . '</a>' . mb_substr( $text, $f[1] );
		}
		return $text;
	}

	/**
	 * 記事の最後に「この記事に関連するページ」。
	 */
	public static function append_related( $content ) {
		if ( ! self::applies() ) {
			return $content;
		}
		$post_id = get_the_ID();
		$genres  = get_the_terms( $post_id, SNSCS_Post_Types::TAX_GENRE );
		$genres  = ( $genres && ! is_wp_error( $genres ) ) ? $genres : array();
		// トレンド記事・攻略ガイドは、その記事のジャンルを先に。
		$main = (string) get_post_meta( $post_id, SNSCS_Column_Kinds::META_GENRE, true );
		usort(
			$genres,
			function ( $a, $b ) use ( $main ) {
				return (int) ( $b->slug === $main ) - (int) ( $a->slug === $main );
			}
		);
		$genres = array_slice( $genres, 0, 2 );
		$sns     = get_the_terms( $post_id, SNSCS_Columns::TAX_SNS );
		$sns     = ( $sns && ! is_wp_error( $sns ) ) ? array_values( array_intersect( wp_list_pluck( $sns, 'slug' ), array_keys( snscs_platforms() ) ) ) : array();
		$term    = (string) get_post_meta( $post_id, SNSCS_Column_Kinds::META_TERM, true );
		$links   = array();

		if ( '' !== $term ) {
			$links[] = array( SNSCS_Trend_Pages::url( $term ), '「' . $term . '」のトレンド（検索の推移と人気の発信者）' );
		}
		if ( SNSCS_Hub::page_id( 'features' ) ) {
			foreach ( $genres as $genre ) {
				foreach ( array_slice( $sns, 0, 2 ) as $key ) {
					$links[] = array( SNSCS_Features::url( $key, $genre->slug ), $genre->name . '×' . snscs_platform_label( $key ) . 'の発信者' );
				}
				$links[] = array( SNSCS_Features::url( '', $genre->slug ), $genre->name . 'の発信者一覧' );
			}
			if ( ! $genres ) {
				foreach ( array_slice( $sns, 0, 2 ) as $key ) {
					$links[] = array( SNSCS_Features::url( $key ), snscs_platform_label( $key ) . 'の発信者一覧' );
				}
			}
		}
		foreach ( $genres as $genre ) {
			if ( SNSCS_Trends::ranking( $genre->slug, 1 ) && SNSCS_Trends::page_url( $genre->slug ) ) {
				$links[] = array( SNSCS_Trends::page_url( $genre->slug ), $genre->name . 'でいま検索されている言葉' );
			}
		}
		$words = array();
		foreach ( $genres as $genre ) {
			foreach ( SNSCS_Trends::ranking( $genre->slug, 6 ) as $row ) {
				if ( $row['term'] !== $term ) {
					$words[ $row['term'] ] = $row['hot'];
				}
			}
		}
		$quiz = SNSCS_Hub::page_url( 'shindan' );
		if ( ! $links && ! $words && ! $quiz ) {
			return $content;
		}

		wp_enqueue_style( 'snscs' );
		$html = '<aside class="snscs-related" aria-label="この記事に関連するページ"><h2 class="snscs-related__title">この記事に関連するページ</h2>';
		if ( $links ) {
			$html .= '<ul class="snscs-related__list">';
			foreach ( array_slice( $links, 0, 6 ) as $link ) {
				$html .= '<li><a href="' . esc_url( $link[0] ) . '">' . esc_html( $link[1] ) . '</a></li>';
			}
			$html .= '</ul>';
		}
		if ( $words ) {
			$html .= '<p class="snscs-related__sub">いま検索されている言葉</p><ul class="snscs-chips">';
			foreach ( array_slice( $words, 0, 8, true ) as $word => $hot ) {
				$html .= '<li><a class="snscs-chip' . ( $hot ? ' snscs-chip--hot' : '' ) . '" href="' . esc_url( SNSCS_Trend_Pages::url( (string) $word ) ) . '">' . esc_html( (string) $word ) . '</a></li>';
			}
			$html .= '</ul>';
		}
		if ( $quiz ) {
			$html .= '<a class="snscs-related__quiz" href="' . esc_url( $quiz ) . '"><strong>あなたに合う発信者診断</strong><span>5つの質問で、好みにぴったりの発信者3人がわかります（約30秒）</span></a>';
		}
		return $content . $html . '</aside>';
	}
}
