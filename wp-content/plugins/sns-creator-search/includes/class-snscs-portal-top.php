<?php
/**
 * ヤフー風のポータル表示。
 *
 *   ページテンプレート「SNS検索ポータル（全幅）」
 *       テーマのヘッダー・サイドバー・フッターを使わず、このプラグインのヘッダー（サイト名・メニュー・ログイン）と
 *       フッター（規約などのリンク）で表示する。トップページのほか、検索・ランキングなどのページにも選べる。
 *       テーマの見た目（CSS）はこのテンプレートのページでは読み込まない（広告・アクセス解析などのコードは残る）。
 *       AFFINGER のように、CSSを登録せずに wp_head で直接書き出すテーマもあるので、テーマのファイルにある
 *       wp_head・wp_footer の処理の出力から <style> と CSSの <link> だけを取り除く（<script> などはそのまま）。
 *   [sns_portal_top]
 *       トップページの中身: 大きな検索窓（SNSのタブつき）・注目のキーワード / 左: カテゴリ・地域 /
 *       中央: 注目の発信者・あなたへのおすすめ・急上昇・特集・新着・コラム / 右: ログイン・発信者の方へ・カレンダー・
 *       トレンドワード・お知らせ・広告
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Portal_Top {

	const TEMPLATE = 'snscs-portal-full.php';
	const CHEVRON  = '<svg class="snscs-pt-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';
	const CHECK    = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg>';

	public static function init() {
		add_filter( 'theme_page_templates', array( __CLASS__, 'add_template' ) );
		add_filter( 'template_include', array( __CLASS__, 'use_template' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'drop_theme_styles' ), 1000 );
		add_action( 'template_redirect', array( __CLASS__, 'tame_theme_output' ), 999 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_shortcode( 'sns_portal_top', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_snscs_portal_top', array( __CLASS__, 'handle_create' ) );
		add_action( 'after_setup_theme', array( __CLASS__, 'register_menus' ), 20 );
	}

	/* --------------------------------------------------------------------
	 * テンプレート
	 * ------------------------------------------------------------------ */

	public static function add_template( $templates ) {
		$templates[ self::TEMPLATE ] = 'SNS検索ポータル（全幅）';
		return $templates;
	}

	public static function is_active() {
		return is_singular( 'page' ) && self::TEMPLATE === get_page_template_slug( get_queried_object_id() );
	}

	public static function use_template( $template ) {
		return self::is_active() ? SNSCS_DIR . 'templates/portal-full.php' : $template;
	}

	/**
	 * テーマのCSSを外す（ヘッダーなどはこのプラグインが出すため。テーマとの見た目の衝突を防ぐ）。
	 */
	public static function drop_theme_styles() {
		if ( ! self::is_active() ) {
			return;
		}
		wp_enqueue_style( 'snscs' );
		$theme_urls = array_unique( array( get_template_directory_uri(), get_stylesheet_directory_uri() ) );
		foreach ( wp_styles()->queue as $handle ) {
			$src = (string) ( wp_styles()->registered[ $handle ]->src ?? '' );
			foreach ( $theme_urls as $url ) {
				if ( '' !== $src && 0 === strpos( $src, $url ) ) {
					wp_dequeue_style( $handle );
				}
			}
		}
		// ブロックテーマの全体の文字サイズや色の設定も外す。
		wp_dequeue_style( 'global-styles' );
	}

	/**
	 * テーマが wp_head・wp_body_open・wp_footer に付けた処理を、CSSを取り除く形に包み直す。
	 */
	public static function tame_theme_output() {
		if ( ! self::is_active() ) {
			return;
		}
		global $wp_filter;
		foreach ( array( 'wp_head', 'wp_body_open', 'wp_footer' ) as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) ) {
				continue;
			}
			$found = array();
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					if ( self::from_theme( $cb['function'] ) ) {
						$found[] = array( $priority, $cb );
					}
				}
			}
			foreach ( $found as $item ) {
				list( $priority, $cb ) = $item;
				remove_action( $hook, $cb['function'], $priority );
				add_action(
					$hook,
					function () use ( $cb ) {
						ob_start();
						call_user_func_array( $cb['function'], array_slice( func_get_args(), 0, (int) $cb['accepted_args'] ) );
						echo self::strip_styles( (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- テーマが出したHTMLから、CSSだけを取り除いたもの。
					},
					$priority,
					$cb['accepted_args']
				);
			}
		}
	}

	/**
	 * その処理がテーマ（親・子）のファイルに書かれているか。
	 */
	public static function from_theme( $callback ) {
		try {
			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$callback = explode( '::', $callback, 2 );
			}
			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$ref = new ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( $callback instanceof Closure || ( is_string( $callback ) && function_exists( $callback ) ) ) {
				$ref = new ReflectionFunction( $callback );
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$ref = new ReflectionMethod( $callback, '__invoke' );
			} else {
				return false;
			}
		} catch ( ReflectionException $e ) {
			return false;
		}
		$file = wp_normalize_path( (string) $ref->getFileName() );
		if ( '' === $file ) {
			return false;
		}
		foreach ( array_unique( array( get_template_directory(), get_stylesheet_directory() ) ) as $dir ) {
			$dir = trailingslashit( wp_normalize_path( $dir ) );
			if ( 0 === strpos( $file, $dir ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * HTMLから <style>…</style> と CSSの <link> を取り除く。
	 */
	public static function strip_styles( $html ) {
		$html = preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $html );
		return (string) preg_replace( '#<link\b(?=[^>]*\brel\s*=\s*["\']?stylesheet)[^>]*>\s*#i', '', (string) $html );
	}

	public static function body_class( $classes ) {
		if ( self::is_active() ) {
			$classes[] = 'snscs-portal-body';
		}
		return $classes;
	}

	public static function register_menus() {
		register_nav_menus(
			array(
				'snscs_portal_header' => 'SNS検索ポータル：ヘッダー',
				'snscs_portal_footer' => 'SNS検索ポータル：フッター',
			)
		);
	}

	/**
	 * ヘッダーのリンク（「SNS検索ポータル：ヘッダー」のメニューがあればそれ、無ければ機能ページから自動で）。
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function header_links() {
		$links = array();
		foreach ( array( 'stories' => 'みんなの記事', 'ranking' => 'ランキング', 'features' => '特集', 'member' => is_user_logged_in() ? 'フォロー中' : 'ログイン', 'business' => '企業の方へ' ) as $key => $label ) {
			if ( SNSCS_Hub::page_url( $key ) ) {
				$links[] = array( SNSCS_Hub::page_url( $key ), $label );
			}
		}
		$links[] = array( snscs_portal_url(), '発信者として掲載（無料）' );
		return $links;
	}

	public static function footer_links() {
		$links = array();
		foreach ( array( 'terms', 'privacy', 'notes' ) as $key ) {
			$id = SNSCS_Legal::page_id( $key );
			if ( $id && 'publish' === get_post_status( $id ) ) {
				$links[] = array( get_permalink( $id ), get_the_title( $id ) );
			}
		}
		foreach ( array( 'business' => '企業の方へ', 'newsletter' => 'メールマガジン', 'tools' => '無料ツール' ) as $key => $label ) {
			if ( SNSCS_Hub::page_url( $key ) ) {
				$links[] = array( SNSCS_Hub::page_url( $key ), $label );
			}
		}
		return $links;
	}

	/* --------------------------------------------------------------------
	 * トップページの中身
	 * ------------------------------------------------------------------ */

	private static function box( $title, $body, $more = '', $class = '' ) {
		if ( '' === trim( $body ) ) {
			return '';
		}
		$head = '' !== $title ? '<div class="snscs-pt-box__head"><h2 class="snscs-pt-box__title">' . esc_html( $title ) . '</h2>' . ( $more ? '<a class="snscs-pt-box__more" href="' . esc_url( $more ) . '">もっと見る</a>' : '' ) . '</div>' : '';
		return '<section class="snscs-pt-box' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '">' . $head . $body . '</section>';
	}

	/**
	 * SNSを思い浮かべやすい小さなアイコン（SVG。色は CSS の currentColor）。
	 */
	public static function sns_icon( $key ) {
		$paths = array(
			'all'       => '<path fill="currentColor" d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/>',
			'x'         => '<path fill="currentColor" d="M17.8 3h3.1l-6.8 7.8L22 21h-6.2l-4.9-6.4L5.3 21H2.2l7.3-8.3L2 3h6.4l4.4 5.8L17.8 3zm-1.1 16.2h1.7L7.4 4.7H5.5l11.2 14.5z"/>',
			'youtube'   => '<path fill="currentColor" fill-rule="evenodd" d="M6.5 4.5h11A4.5 4.5 0 0 1 22 9v6a4.5 4.5 0 0 1-4.5 4.5h-11A4.5 4.5 0 0 1 2 15V9a4.5 4.5 0 0 1 4.5-4.5zM10 8.6v6.8l5.8-3.4L10 8.6z"/>',
			'instagram' => '<g fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4.2"/></g><circle fill="currentColor" cx="17.4" cy="6.6" r="1.3"/>',
			'threads'   => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M16.2 9.6C15.5 7.6 14 6.6 12 6.6c-3 0-4.6 2.3-4.6 5.4s1.6 5.4 4.6 5.4c2.4 0 4-1.3 4-3.2 0-2.2-2.3-3-4.6-2.8-1.6.1-2.6 1-2.5 2.1.1 1.2 1.3 1.8 2.6 1.7 2.3-.2 3.3-2.3 3-5.4M12 21.5c-5.5 0-9-3.7-9-9.5S6.5 2.5 12 2.5c4.4 0 7.4 2.3 8.5 6"/>',
			'tiktok'    => '<path fill="currentColor" d="M13.5 2.5h3.2a4.8 4.8 0 0 0 4.3 4.3v3.3a8 8 0 0 1-4.3-1.3v6.4a6.3 6.3 0 1 1-6.3-6.3h.6v3.3H10a3 3 0 1 0 3.5 3V2.5z"/>',
			'substack'  => '<path fill="currentColor" d="M4 3.5h16v2.6H4zM4 8.3h16v2.6H4zM4 13.1h16v7.9l-8-4.5-8 4.5z"/>',
		);
		$path = $paths[ $key ] ?? '<circle fill="currentColor" cx="12" cy="12" r="8"/>';
		return '<svg class="snscs-pt-tab__ico" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}

	/**
	 * 検索窓の下に出すトレンドワード（急上昇 → 人気 → 発信者の多いジャンルの順に、決めた数まで）。
	 *
	 * @return array<int,array{term:string,hot:bool}>
	 */
	public static function trend_words( $limit ) {
		if ( $limit <= 0 ) {
			return array();
		}
		$out = array();
		foreach ( SNSCS_Trends::ranking( '', $limit ) as $row ) {
			$out[ mb_strtolower( $row['term'] ) ] = array( 'term' => $row['term'], 'hot' => (bool) $row['hot'] );
		}
		if ( count( $out ) < $limit ) {
			$genres = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'orderby' => 'count', 'order' => 'DESC', 'number' => $limit, 'hide_empty' => false ) );
			foreach ( is_wp_error( $genres ) ? array() : $genres as $genre ) {
				if ( count( $out ) >= $limit ) {
					break;
				}
				$out += array( mb_strtolower( $genre->name ) => array( 'term' => $genre->name, 'hot' => false ) );
			}
		}
		return array_values( array_slice( $out, 0, $limit ) );
	}

	private static function search_band() {
		$words = self::trend_words( (int) SNSCS_Hub::setting( 'search_trend_count' ) );
		$tabs = array_merge( array( '' => 'すべて' ), snscs_platforms() );
		ob_start();
		?>
		<section class="snscs-pt-search" aria-label="検索">
			<form class="snscs-pt-search__inner" role="search" method="get" action="<?php echo esc_url( snscs_search_url() ); ?>">
				<fieldset class="snscs-pt-tabs">
					<legend class="screen-reader-text">検索するSNS</legend>
					<?php foreach ( $tabs as $key => $label ) : ?>
						<label class="snscs-pt-tab snscs-pt-tab--<?php echo esc_attr( '' === $key ? 'all' : $key ); ?>"><input type="radio" name="snscs_platform" value="<?php echo esc_attr( $key ); ?>" <?php checked( '', $key ); ?>><span><?php echo self::sns_icon( '' === $key ? 'all' : $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 決まったSVG。 ?><?php echo esc_html( $label ); ?></span></label>
					<?php endforeach; ?>
				</fieldset>
				<div class="snscs-pt-search__row">
					<label for="snscs-pt-q" class="screen-reader-text">発信者を検索</label>
					<input id="snscs-pt-q" type="search" name="snscs_q" placeholder="発信者名・ジャンル・キーワードで探す">
					<button type="submit"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-4-4"></path></svg><span>検索</span></button>
				</div>
				<?php if ( $words ) : ?>
					<div class="snscs-pt-trendwords" aria-label="トレンドワード">
						<span class="snscs-pt-trendwords__label"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/></svg>トレンド</span>
						<ul class="snscs-pt-trendwords__list">
							<?php foreach ( $words as $word ) : ?>
								<li><a class="snscs-pt-trendword<?php echo $word['hot'] ? ' snscs-pt-trendword--hot' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'snscs_q', rawurlencode( $word['term'] ), snscs_search_url() ) ); ?>" data-q="<?php echo esc_attr( $word['term'] ); ?>"><?php if ( $word['hot'] ) : ?><span class="snscs-pt-trendword__hot">急上昇</span><?php endif; ?><?php echo esc_html( $word['term'] ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					</div>
					<script>
					( function () {
						// 選んでいるSNSも一緒に渡す。
						document.querySelectorAll( '.snscs-pt-trendword' ).forEach( function ( a ) {
							a.addEventListener( 'click', function () {
								var sns = document.querySelector( '.snscs-pt-tabs input:checked' );
								if ( sns && sns.value ) {
									var u = new URL( a.href );
									u.searchParams.set( 'snscs_platform', sns.value );
									a.href = u.toString();
								}
							} );
						} );
					} )();
					</script>
				<?php endif; ?>
			</form>
		</section>
		<?php
		return ob_get_clean();
	}

	private static function left_column() {
		$genres = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'parent' => 0, 'hide_empty' => false, 'number' => 30, 'orderby' => 'count', 'order' => 'DESC' ) );
		$html   = '';
		foreach ( is_wp_error( $genres ) ? array() : $genres as $genre ) {
			$subs  = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'parent' => $genre->term_id, 'hide_empty' => false, 'number' => 4 ) );
			$html .= '<li><a class="snscs-pt-cat" href="' . esc_url( SNSCS_Features::url( '', $genre->slug ) ) . '"><span class="snscs-pt-cat__name">' . esc_html( $genre->name ) . '</span>' . ( $genre->count ? '<span class="snscs-pt-cat__count">' . esc_html( number_format_i18n( $genre->count ) ) . '</span>' : '' ) . self::CHEVRON . '</a>';
			if ( $subs && ! is_wp_error( $subs ) ) {
				$sub_links = array();
				foreach ( $subs as $sub ) {
					$sub_links[] = '<a href="' . esc_url( SNSCS_Features::url( '', $sub->slug ) ) . '">' . esc_html( $sub->name ) . '</a>';
				}
				$html .= '<span class="snscs-pt-cat__subs">' . implode( ' / ', $sub_links ) . '</span>';
			}
			$html .= '</li>';
		}
		$out = self::box( 'カテゴリから探す', $html ? '<ul class="snscs-pt-cats">' . $html . '</ul>' : '', '', 'snscs-pt-box--cats' );

		$regions = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_REGION, 'parent' => 0, 'hide_empty' => false, 'number' => 20 ) );
		$links   = '';
		foreach ( is_wp_error( $regions ) ? array() : $regions as $region ) {
			$links .= '<a href="' . esc_url( SNSCS_Features::url( '', '', $region->slug ) ) . '">' . esc_html( $region->name ) . '</a>';
		}
		$out .= self::box( '地域から探す', $links ? '<div class="snscs-pt-regions">' . $links . '</div>' : '', '', 'snscs-pt-box--regions' );
		return $out;
	}

	private static function center_column() {
		$out = '';

		// 注目の発信者（総合点の上位）。
		$top = SNSCS_Search::run( array( 'sort' => 'recommended', 'per_page' => 6 ) );
		if ( $top['items'] ) {
			$cards = '';
			foreach ( $top['items'] as $item ) {
				$main   = $item['accounts'] ? $item['accounts'][0] : null;
				$photo  = $item['thumbnail'] ? '<img src="' . esc_url( $item['thumbnail'] ) . '" alt="" width="56" height="56" loading="lazy">' : '<span class="snscs-pt-pick__initial" aria-hidden="true">' . esc_html( mb_substr( $item['name'], 0, 1 ) ) . '</span>';
				$cards .= '<a class="snscs-pt-pick" href="' . esc_url( $item['url'] ) . '">' . $photo . '<strong>' . esc_html( $item['name'] ) . '</strong><span class="snscs-pt-pick__genre">' . esc_html( implode( '・', $item['genres'] ) ) . '</span>' . ( $main ? '<span>' . esc_html( $main['platform_label'] . ' ' . $main['followers_label'] ) . '</span>' : '' ) . '</a>';
			}
			$out .= self::box( '注目の発信者', '<div class="snscs-pt-picks">' . $cards . '</div>', SNSCS_Hub::page_url( 'ranking' ) );
		}

		// あなたへのおすすめ（閲覧の傾向から。あとから読み込む）。
		$out .= '<div class="snscs-pt-personal">' . SNSCS_Personalize::placeholder( 'personal,recent' ) . '</div>';
		$out .= SNSCS_Ads::render( 'top_infeed' );

		// 急上昇（データが無いうちは総合の上位）。
		$rising = SNSCS_Rankings::rising( 5 );
		$rows   = '';
		$rank   = 0;
		if ( $rising ) {
			foreach ( SNSCS_Personalize::items( array_keys( $rising ) ) as $item ) {
				$r     = $rising[ $item['id'] ];
				$rate  = $r['prev'] ? '+' . round( ( $r['cur'] - $r['prev'] ) / $r['prev'] * 100 ) . '%' : '急上昇';
				$rows .= self::rank_row( ++$rank, $item, $rate );
			}
			$title = '急上昇ランキング';
		} else {
			foreach ( array_slice( $top['items'], 0, 5 ) as $item ) {
				$rows .= self::rank_row( ++$rank, $item, '' );
			}
			$title = '総合ランキング';
		}
		$ranking = SNSCS_Hub::page_url( 'ranking' );
		$out    .= self::box( $title, $rows ? '<ol class="snscs-pt-ranks">' . $rows . '</ol>' : '', $ranking ? add_query_arg( 'snscs_rk', $rising ? 'rising' : 'overall', $ranking ) : '' );

		// 特集（発信者の多い組み合わせ）。
		$combos = SNSCS_Features::find_combos(
			function ( $s, $g, $r ) {
				return '' !== $s && '' !== $g && '' === $r;
			},
			4,
			(int) SNSCS_Hub::setting( 'feature_min' )
		);
		$cards = '';
		foreach ( $combos as $key => $count ) {
			$info = SNSCS_Features::combo_info( $key );
			if ( $info ) {
				$cards .= '<a class="snscs-pt-feature" href="' . esc_url( $info['url'] ) . '"><span class="snscs-pt-feature__tag">特集</span><strong>' . esc_html( $info['genre']->name . 'の' . snscs_platform_label( $info['platform'] ) . '発信者' ) . '</strong><span>' . esc_html( number_format_i18n( $count ) ) . '人を紹介しています</span></a>';
			}
		}
		$out .= self::box( '特集', $cards ? '<div class="snscs-pt-features">' . $cards . '</div>' : '', SNSCS_Hub::page_url( 'features' ) );

		// 新着の発信者。
		$new  = SNSCS_Search::run( array( 'sort' => 'newest', 'per_page' => 5 ) );
		$rows = '';
		foreach ( $new['items'] as $item ) {
			$rows .= '<li><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a><span class="snscs-pt-meta">' . esc_html( implode( '・', $item['genres'] ) ) . '</span><span class="snscs-pt-when">' . esc_html( human_time_diff( get_post_time( 'U', true, $item['id'] ) ) . '前' ) . '</span></li>';
		}
		$ranking_new = $ranking ? add_query_arg( 'snscs_rk', 'new', $ranking ) : '';
		$out        .= self::box( '新着の発信者', $rows ? '<ul class="snscs-pt-list">' . $rows . '</ul>' : '', $ranking_new );

		// 話題の記事（いいね・リポスト・ブックマークの多い記事）。
		$hot = array_keys( SNSCS_Stories::trending( 5 ) );
		if ( ! $hot ) {
			$hot = SNSCS_Stories::newest( 5 );
		}
		if ( $hot ) {
			$items = array();
			foreach ( $hot as $id ) {
				$items[] = array( 'id' => $id, 'note' => '' );
			}
			$out .= self::box( '話題の記事', SNSCS_Social::render_list( $items ), SNSCS_Stories::stories_url() );
		}

		$out .= SNSCS_Columns::render( 'SNS運用コラム', array( 'count' => 3 ) );
		return $out;
	}

	private static function rank_row( $rank, array $item, $note ) {
		$main = $item['accounts'] ? $item['accounts'][0]['platform_label'] : '';
		return '<li><span class="snscs-pt-rank">' . (int) $rank . '</span><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a><span class="snscs-pt-meta">' . esc_html( $main ) . '</span>' . ( '' !== $note ? '<span class="snscs-pt-growth">' . esc_html( $note ) . '</span>' : '' ) . '</li>';
	}

	private static function right_column() {
		$out = SNSCS_Ads::render( 'top_side' );

		// ログイン
		if ( is_user_logged_in() && snscs_is_creator_user() ) {
			$creator = SNSCS_Submissions::creator_id_for_user( get_current_user_id() );
			$body    = '<p>ログイン中です。</p><a class="snscs-pt-btn" href="' . esc_url( snscs_portal_url() ) . '">マイページ</a>';
			if ( $creator && 'publish' === get_post_status( $creator ) ) {
				$body .= '<a class="snscs-pt-sublink" href="' . esc_url( SNSCS_Biolink::url( $creator ) ) . '">マイリンクを見る</a>';
			}
		} else {
			$body = '<p>ログインすると、掲載内容の編集や、カレンダーの予定をどの端末からも使えます。</p><a class="snscs-pt-btn" href="' . esc_url( snscs_portal_url() ) . '">ログイン</a><a class="snscs-pt-sublink" href="' . esc_url( snscs_portal_url() ) . '">新規登録（無料）</a>';
		}
		$out .= self::box( '', $body, '', 'snscs-pt-box--login' );

		$points = array( 'SNSアカウントを無料で掲載', 'SNSに貼れる「マイリンク」', '企業からお仕事の相談が届く' );
		$body   = '<p class="snscs-pt-cta__lead">見つけてもらえる場所を、無料で</p><ul class="snscs-pt-cta__points">';
		foreach ( $points as $point ) {
			$body .= '<li>' . self::CHECK . '<span>' . esc_html( $point ) . '</span></li>';
		}
		$body .= '</ul><a class="snscs-pt-cta__btn" href="' . esc_url( snscs_portal_url() ) . '">無料で掲載する</a>';
		if ( SNSCS_Hub::page_url( 'mylink' ) ) {
			$body .= '<a class="snscs-pt-sublink" href="' . esc_url( SNSCS_Hub::page_url( 'mylink' ) ) . '">マイリンクについて詳しく</a>';
		}
		$out .= self::box( '発信者の方へ', $body, '', 'snscs-pt-box--cta' );

		$out .= SNSCS_Calendar::shortcode_link( array( 'title' => 'SNS発信カレンダー', 'count' => 3 ) );

		// トレンドワード（急上昇と人気をまとめて順位をつける）。ジャンル別はトレンドのページで。
		$rows = '';
		$rank = 0;
		foreach ( SNSCS_Trends::ranking( '', 8 ) as $row ) {
			$rows .= '<li><span class="snscs-pt-rank">' . ( ++$rank ) . '</span><a href="' . esc_url( SNSCS_Trend_Pages::url( $row['term'] ) ) . '">' . esc_html( $row['term'] ) . '</a><span class="snscs-pt-meta">' . esc_html( $row['hot'] ? '急上昇' : '人気' ) . '</span></li>';
		}
		$out .= self::box( 'トレンドワード', $rows ? '<ol class="snscs-pt-ranks snscs-pt-ranks--small">' . $rows . '</ol>' : '', SNSCS_Trends::page_url() );

		// お知らせ（カテゴリ「お知らせ」の投稿）。
		$news = get_term_by( 'slug', 'news', 'category' );
		if ( $news ) {
			$rows = '';
			foreach ( get_posts( array( 'category' => $news->term_id, 'posts_per_page' => 4, 'no_found_rows' => true ) ) as $post ) {
				$rows .= '<li><span class="snscs-pt-when">' . esc_html( get_the_date( 'Y/m/d', $post ) ) . '</span><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></li>';
			}
			$out .= self::box( 'お知らせ', $rows ? '<ul class="snscs-pt-list snscs-pt-list--news">' . $rows . '</ul>' : '', get_category_link( $news->term_id ) );
		}
		return $out;
	}

	public static function shortcode() {
		wp_enqueue_style( 'snscs' );
		return '<div class="snscs-pt">' . self::search_band()
			. '<div class="snscs-pt-columns">'
			. '<div class="snscs-pt-left" role="complementary" aria-label="カテゴリと地域">' . self::left_column() . '</div>'
			. '<div class="snscs-pt-center">' . self::center_column() . '</div>'
			. '<div class="snscs-pt-right" role="complementary" aria-label="ログインとトレンド">' . self::right_column() . '</div>'
			. '</div></div>';
	}

	/* --------------------------------------------------------------------
	 * 作成（管理画面「集客・回遊」のボタン）
	 * ------------------------------------------------------------------ */

	public static function handle_create() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_portal_top' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$id = (int) get_option( 'snscs_portal_top_id' );
		if ( ! $id || 'page' !== get_post_type( $id ) || 'trash' === get_post_status( $id ) ) {
			$id = (int) wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_title'   => 'トップ',
					'post_name'    => 'top',
					'post_status'  => 'publish',
					'post_content' => '<!-- wp:shortcode -->[sns_portal_top]<!-- /wp:shortcode -->',
				)
			);
			update_option( 'snscs_portal_top_id', $id );
		}
		update_post_meta( $id, '_wp_page_template', self::TEMPLATE );
		if ( ! empty( $_POST['front'] ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $id );
		}
		if ( ! empty( $_POST['all_pages'] ) ) {
			// 検索・マイページ・機能ページも同じ見た目にそろえる。
			$ids = array( (int) get_option( 'snscs_search_page_id' ), (int) get_option( 'snscs_portal_page_id' ) );
			foreach ( array_keys( SNSCS_Hub::pages() ) as $key ) {
				$ids[] = SNSCS_Hub::page_id( $key );
			}
			foreach ( array_filter( $ids ) as $page_id ) {
				update_post_meta( $page_id, '_wp_page_template', self::TEMPLATE );
			}
		}
		wp_safe_redirect( SNSCS_Hub::admin_url( array( 'snscs_portal_top' => 1 ) ) );
		exit;
	}

	public static function render_admin_box() {
		$id = (int) get_option( 'snscs_portal_top_id' );
		?>
		<h2>ヤフー風のトップページ</h2>
		<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<?php if ( isset( $_GET['snscs_portal_top'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>トップページを用意しました。<a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank">サイトを見る</a></p></div>
		<?php endif; ?>
		<p>大きな検索窓とSNSのタブ、左にカテゴリ、中央に注目の発信者やランキング、右にログインやトレンドワードを並べた、ポータルサイトの形のトップページを作ります。テーマのヘッダー・フッターの代わりに、このプラグインのヘッダー・フッターで表示します。</p>
		<?php if ( $id && 'publish' === get_post_status( $id ) ) : ?>
			<p>作成済み: <a href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank"><?php echo esc_html( get_the_title( $id ) ); ?></a></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="snscs_portal_top">
			<?php wp_nonce_field( 'snscs_portal_top', 'snscs_nonce' ); ?>
			<p><label><input type="checkbox" name="front" value="1" checked> サイトのトップページ（ホームページ）に設定する</label></p>
			<p><label><input type="checkbox" name="all_pages" value="1" checked> 検索・マイページ・ランキングなどのページも、同じヘッダー・フッターの見た目にそろえる</label></p>
			<?php submit_button( 'ヤフー風のトップページを作成・設定', 'primary' ); ?>
		</form>
		<p class="description">元に戻すときは「設定 → 表示設定」でホームページを選び直し、各ページの編集画面の「テンプレート」を元に戻してください。ヘッダーのメニューは自動で作られます（「外観 → メニュー」の位置「SNS検索ポータル：ヘッダー」に自分で作ったメニューを入れると、そちらが使われます）。お知らせ欄は、カテゴリ「お知らせ」（スラッグ news）の投稿があると表示されます。</p>
		<?php
	}
}
