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
 *       中央: 看板・創設メンバー募集・SNSから探す・注目の発信者・あなたへのおすすめ・急上昇・特集・新着・
 *       読みもの（コラム・今日の用語・質問箱・急上昇の言葉）・はじめての方へ・マイリンク / 右: ログイン・発信者の方へ・カレンダー・
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
		add_action( 'transition_post_status', array( __CLASS__, 'on_status' ), 10, 3 );
	}

	/* --------------------------------------------------------------------
	 * テンプレート
	 * ------------------------------------------------------------------ */

	public static function add_template( $templates ) {
		$templates[ self::TEMPLATE ] = 'SNS検索ポータル（全幅）';
		return $templates;
	}

	private static $active = null;

	/**
	 * このページをポータルの見た目（このプラグインのヘッダー・フッター。テーマのCSSは外す）で出すか。
	 *
	 * 「集客・回遊」の「ポータルの見た目にするページ」で決める:
	 *   top    … テンプレート「SNS検索ポータル（全幅）」を選んだページだけ
	 *   plugin … それに加えて、このプラグインのページ（機能ページ・検索・マイページ・規約・発信者・記事・コラム）（初期値）
	 *   site   … サイトのすべての固定ページ・投稿
	 */
	public static function is_active() {
		if ( null !== self::$active && did_action( 'wp' ) ) {
			return self::$active;
		}
		$active = false;
		if ( is_singular( 'page' ) && self::TEMPLATE === get_page_template_slug( get_queried_object_id() ) ) {
			$active = true;
		} elseif ( ! is_admin() && ! is_feed() && ! is_embed() && is_singular() ) {
			$scope = (string) SNSCS_Hub::setting( 'portal_scope' );
			if ( 'site' === $scope ) {
				$active = true;
			} elseif ( 'plugin' === $scope ) {
				$active = self::is_plugin_page( get_queried_object_id() );
			}
		}
		if ( did_action( 'wp' ) ) {
			self::$active = $active;
		}
		return $active;
	}

	/**
	 * このプラグインが作った・使うページか。
	 */
	public static function is_plugin_page( $id ) {
		$type = get_post_type( $id );
		if ( in_array( $type, array( SNSCS_Post_Types::POST_TYPE, SNSCS_Articles::POST_TYPE ), true ) ) {
			return true;
		}
		if ( 'post' === $type ) {
			return SNSCS_Columns::is_column( $id );
		}
		if ( 'page' !== $type ) {
			return false;
		}
		$ids = array_map( 'intval', array_values( (array) get_option( SNSCS_Hub::PAGES, array() ) ) );
		foreach ( array( 'snscs_search_page_id', 'snscs_portal_page_id', 'snscs_portal_top_id' ) as $option ) {
			$ids[] = (int) get_option( $option );
		}
		foreach ( array_keys( SNSCS_Legal::pages() ) as $key ) {
			$ids[] = (int) SNSCS_Legal::page_id( $key );
		}
		return in_array( (int) $id, array_filter( $ids ), true );
	}

	public static function use_template( $template ) {
		return self::is_active() ? SNSCS_DIR . 'templates/portal-full.php' : $template;
	}

	/**
	 * テーマのCSSとスクリプトを外す（ヘッダーなどはこのプラグインが出すため。テーマとの見た目の衝突やエラーを防ぐ）。
	 */
	public static function drop_theme_styles() {
		if ( ! self::is_active() ) {
			return;
		}
		wp_enqueue_style( 'snscs' );
		// 左右の列がスクロールについてくる動き（トップのタイムラインの読み込みも同じファイル）。
		wp_enqueue_script( 'snscs-timeline', SNSCS_URL . 'assets/js/snscs-timeline.js', array(), SNSCS_VERSION, true );
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
		// 1.26.3: テーマのスクリプトも外す（テーマのヘッダーやメニューが無いページで動いて、エラーになるため。AFFINGER の base.js など）。
		foreach ( wp_scripts()->queue as $handle ) {
			$src = (string) ( wp_scripts()->registered[ $handle ]->src ?? '' );
			foreach ( $theme_urls as $url ) {
				if ( '' !== $src && 0 === strpos( $src, $url ) ) {
					wp_dequeue_script( $handle );
				}
			}
		}
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
		// 登録済みの発信者には、掲載の案内は出さない。
		if ( ! ( is_user_logged_in() && snscs_is_creator_user() ) ) {
			$links[] = array( snscs_portal_url(), '発信者として掲載（無料）' );
		}
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
			'threads'   => '<path fill="currentColor" d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.845 1.205 8.6.024 12.18 0h.014c2.746.02 5.043.725 6.826 2.098 1.677 1.29 2.858 3.13 3.509 5.467l-2.04.569c-1.104-3.96-3.898-5.984-8.304-6.015-2.91.022-5.11.936-6.54 2.717C4.307 6.504 3.616 8.914 3.589 12c.027 3.086.718 5.496 2.057 7.164 1.43 1.783 3.631 2.698 6.54 2.717 2.623-.02 4.358-.631 5.8-2.045 1.647-1.613 1.618-3.593 1.09-4.798-.31-.71-.873-1.3-1.634-1.75-.192 1.352-.622 2.446-1.284 3.272-.886 1.102-2.14 1.704-3.73 1.79-1.202.065-2.361-.218-3.259-.801-1.063-.689-1.685-1.74-1.752-2.964-.065-1.19.408-2.285 1.33-3.082.88-.76 2.119-1.207 3.583-1.291a13.853 13.853 0 0 1 3.02.142c-.126-.742-.375-1.332-.75-1.757-.513-.586-1.308-.883-2.359-.89h-.029c-.844 0-1.992.232-2.721 1.32L7.734 7.847c.98-1.454 2.568-2.256 4.478-2.256h.044c3.194.02 5.097 1.975 5.287 5.388.108.046.216.094.321.142 1.49.7 2.58 1.761 3.154 3.07.797 1.82.871 4.79-1.548 7.158-1.85 1.81-4.094 2.628-7.277 2.65zm1.003-11.69c-.242 0-.487.007-.739.021-1.836.103-2.98.946-2.916 2.143.067 1.256 1.452 1.839 2.784 1.767 1.224-.065 2.818-.543 3.086-3.71a10.5 10.5 0 0 0-2.215-.221z"/>',
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

	/* --------------------------------------------------------------------
	 * 1.17.2: 発信者が少ないうちも空白にならない中央の列
	 * ------------------------------------------------------------------ */

	/**
	 * 掲載中の発信者の数（10分ごとに数え直す）。
	 */
	public static function creator_total() {
		$n = get_transient( 'snscs_pt_total' );
		if ( false === $n ) {
			$n = (int) wp_count_posts( SNSCS_Post_Types::POST_TYPE )->publish;
			set_transient( 'snscs_pt_total', $n, 10 * MINUTE_IN_SECONDS );
		}
		return (int) $n;
	}

	/**
	 * 創設メンバー（最初に掲載された人から、決めた人数まで）。
	 * 1.26.3: 本人のアカウントで登録した発信者だけ（運営者が管理画面から足した発信者ページは数えない。
	 * 数えると、あとから登録した本人の枠が埋まってしまう）。
	 *
	 * @return array<int,true> 発信者ID
	 */
	public static function founders() {
		$limit = (int) SNSCS_Hub::setting( 'founders_limit' );
		if ( $limit <= 0 ) {
			return array();
		}
		$cached = get_transient( 'snscs_pt_founders' );
		if ( ! is_array( $cached ) ) {
			global $wpdb;
			$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status = 'publish' AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} m WHERE m.meta_key = 'snscs_creator_id' AND m.meta_value = p.ID) ORDER BY p.post_date ASC, p.ID ASC LIMIT %d", SNSCS_Post_Types::POST_TYPE, $limit ) );
			$cached = array_fill_keys( array_map( 'intval', $ids ), true );
			set_transient( 'snscs_pt_founders', $cached, HOUR_IN_SECONDS );
		}
		return $cached;
	}

	public static function is_founder( $creator_id ) {
		// 通し番号は一度付いたら変わらない（SNSCS_Founder）。
		return SNSCS_Founder::number( $creator_id ) > 0;
	}

	public static function founder_badge( $creator_id ) {
		return SNSCS_Founder::card_html( $creator_id );
	}

	public static function on_status( $new, $old, $post ) {
		if ( SNSCS_Post_Types::POST_TYPE === $post->post_type && ( 'publish' === $new || 'publish' === $old ) ) {
			self::forget_counts();
		}
	}

	public static function forget_counts() {
		delete_transient( 'snscs_pt_total' );
		delete_transient( 'snscs_pt_founders' );
		delete_transient( 'snscs_pt_sns_counts' );
	}

	/** 1. 看板 */
	private static function hero() {
		$total  = self::creator_total();
		$genres = (int) wp_count_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => true ) );
		$title  = (string) SNSCS_Hub::setting( 'hero_title' );
		$text   = (string) SNSCS_Hub::setting( 'hero_text' );
		$stats  = '';
		if ( $total >= 10 ) {
			$stats = '<ul class="snscs-pt-hero__stats"><li><strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>人の発信者</li><li><strong>' . esc_html( number_format_i18n( max( 1, $genres ) ) ) . '</strong>ジャンル</li><li><strong>' . count( snscs_platforms() ) . '</strong>つのSNS</li></ul>';
		}
		$brand = SNSCS_Brand::on() && '' !== SNSCS_Brand::tagline() ? '<p class="snscs-pt-hero__brand"><img src="' . esc_url( SNSCS_Brand::url( 'follofind-icon.svg' ) ) . '" alt="" width="22" height="22">' . esc_html( SNSCS_Brand::NAME . '｜' . SNSCS_Brand::tagline() ) . '</p>' : '';
		return '<section class="snscs-pt-hero' . ( $total >= 300 ? ' snscs-pt-hero--compact' : '' ) . '">' . $brand . '<p class="snscs-pt-hero__title">' . esc_html( $title ) . '</p>'
			. ( '' !== $text ? '<p class="snscs-pt-hero__text">' . esc_html( $text ) . '</p>' : '' )
			. '<p class="snscs-pt-hero__btns"><a class="snscs-pt-hero__btn" href="' . esc_url( snscs_search_url() ) . '">発信者を探す</a><a class="snscs-pt-hero__btn snscs-pt-hero__btn--sub" href="' . esc_url( snscs_portal_url() ) . '">無料で掲載する</a></p>'
			. $stats . '</section>';
	}

	/**
	 * 2. 創設メンバー募集（掲載が決めた人数に届いたら消える）。
	 * 1.24.2: いま受付中のランク（ノワール→ミッドナイト→エメラルド→プラチナ）と残りの枠、4つのランクの一覧、FIRST 10。
	 */
	private static function founders_banner() {
		$limit = (int) SNSCS_Hub::setting( 'founders_limit' );
		$total = min( $limit, count( self::founders() ) );
		if ( $limit <= 0 || $total >= $limit ) {
			return '';
		}
		$next  = $total + 1;
		$now   = SNSCS_Founder::design( $next );
		$tiers = SNSCS_Founder::tiers();
		$tiers['platinum'][1] = $limit;
		$range = $tiers[ $now ];
		$left  = $range[1] - $total;
		$top   = 'noir' === $now ? '最上位' : '';

		$rows = '';
		foreach ( $tiers as $design => $r ) {
			if ( $r[0] > $limit ) {
				continue;
			}
			$size  = $r[1] - $r[0] + 1;
			$state = $total >= $r[1] ? 'done' : ( $design === $now ? 'now' : 'next' );
			if ( 'done' === $state ) {
				$status = '受付終了';
			} elseif ( 'now' === $state ) {
				$status = sprintf( '受付中 残り%s枠', number_format_i18n( $left ) );
			} else {
				$status = sprintf( '残り%s枠', number_format_i18n( $size ) );
			}
			$rows .= '<div class="snscs-pt-founders__tier is-' . $state . '"><img src="' . esc_url( SNSCS_Founder::medal_url( $design ) ) . '" alt="" width="46" height="46">'
				. '<div class="snscs-pt-founders__tierbody"><p class="snscs-pt-founders__tiername">' . esc_html( SNSCS_Founder::name( $design ) ) . '</p>'
				. '<p class="snscs-pt-founders__range">Nº ' . esc_html( SNSCS_Founder::serial( $r[0] ) . '〜' . SNSCS_Founder::serial( $r[1] ) ) . '</p>'
				. '<p class="snscs-pt-founders__status">' . esc_html( $status ) . '</p>'
				. ( 'now' === $state ? '<div class="snscs-pt-founders__bar"><span style="width:' . max( 3, (int) round( ( $total - $r[0] + 1 ) / $size * 100 ) ) . '%"></span></div>' : '' )
				. '</div></div>';
		}

		$first = $total < 10
			? sprintf( 'Nº 001〜010 は残り%d枠。メダルとカードに「FIRST 10」の特別な刻印が入ります', 10 - $total )
			: 'Nº 001〜010 は受付終了しました（メダルとカードに「FIRST 10」の刻印）';

		return '<section class="snscs-pt-founders snscs-pt-founders--' . esc_attr( $now ) . '">'
			. '<div class="snscs-pt-founders__head"><img class="snscs-pt-founders__medal" src="' . esc_url( SNSCS_Founder::medal_url( $now ) ) . '" alt="" width="150" height="150">'
			. '<div class="snscs-pt-founders__body"><p class="snscs-pt-founders__tag">' . esc_html( sprintf( '創設メンバー募集中 ・ 先着%s人', number_format_i18n( $limit ) ) ) . '</p>'
			. '<p class="snscs-pt-founders__title">いま登録して掲載されると、<span>' . esc_html( $top ) . '<b>「' . esc_html( SNSCS_Founder::name( $now ) ) . '」</b>メダル</span></p>'
			. '<p class="snscs-pt-founders__left">' . esc_html( SNSCS_Founder::name( $now ) ) . ' 残り<strong>' . esc_html( number_format_i18n( $left ) ) . '</strong>枠 <span>（Nº ' . esc_html( SNSCS_Founder::serial( $next ) . '〜' . SNSCS_Founder::serial( $range[1] ) ) . '）</span></p></div>'
			. '<a class="snscs-pt-founders__btn" href="' . esc_url( snscs_portal_url() ) . '">無料で掲載して受け取る</a></div>'
			. '<div class="snscs-pt-founders__tiers">' . $rows . '</div>'
			. '<p class="snscs-pt-founders__first"><em>FIRST 10 ★</em> ' . esc_html( $first ) . '</p></section>';
	}

	/**
	 * 2の2. セイントの冒険（1.26）: ボーナスイベント・ボス戦・ランクのお知らせが出ているときだけ、細い帯で知らせる。
	 */
	private static function game_banner() {
		$events = SNSCS_Game::events_html( true );
		if ( '' === $events ) {
			return '';
		}
		$more = SNSCS_Social::member_url( array( 'tab' => 'level' ) );
		return '<section class="snscs-pt-game" aria-label="セイントの冒険"><p class="snscs-pt-game__tag">SAINT QUEST</p>' . $events
			. ( $more ? '<a class="snscs-pt-game__more" href="' . esc_url( is_user_logged_in() ? $more : snscs_portal_url() ) . '">' . ( is_user_logged_in() ? '冒険の記録を見る' : 'セイントになって参加する（無料）' ) . '</a>' : '' ) . '</section>';
	}

	/** 3. SNSから探す */
	private static function sns_cards() {
		$counts = get_transient( 'snscs_pt_sns_counts' );
		if ( ! is_array( $counts ) ) {
			$counts = SNSCS_Search::backend()->count_by_platform( SNSCS_Search::normalize_args( array() ) );
			set_transient( 'snscs_pt_sns_counts', $counts, HOUR_IN_SECONDS );
		}
		$notes = array(
			'x'         => 'いまの話題・速報',
			'youtube'   => '動画・解説・Vlog',
			'instagram' => '写真・リール',
			'threads'   => 'ゆるい交流・日常',
			'tiktok'    => 'ショート動画',
			'substack'  => 'ニュースレター',
		);
		$cards = '';
		foreach ( snscs_platforms() as $key => $label ) {
			$n      = (int) ( $counts[ $key ] ?? 0 );
			$cards .= '<a class="snscs-pt-snscard snscs-pt-tab--' . esc_attr( $key ) . '" href="' . esc_url( add_query_arg( 'snscs_platform', $key, snscs_search_url() ) ) . '">'
				. '<span class="snscs-pt-snscard__ico">' . self::sns_icon( $key ) . '</span><span class="snscs-pt-snscard__name">' . esc_html( $label ) . '</span>'
				. '<span class="snscs-pt-snscard__note">' . esc_html( $n >= 5 ? number_format_i18n( $n ) . '人' : ( $notes[ $key ] ?? '' ) ) . '</span></a>';
		}
		return self::box( 'SNSから探す', '<div class="snscs-pt-snscards">' . $cards . '</div>' );
	}

	/** 5. 読みもの（今日の用語・質問箱・急上昇の言葉） */
	private static function reading_picks() {
		$cards = '';
		$terms = SNSCS_Hub::page_id( 'glossary' ) ? SNSCS_Glossary::all() : array();
		if ( $terms ) {
			$t      = $terms[ (int) floor( current_time( 'timestamp' ) / DAY_IN_SECONDS ) % count( $terms ) ];
			$cards .= '<a class="snscs-pt-read snscs-pt-read--term" href="' . esc_url( SNSCS_Glossary::url( $t['slug'] ) ) . '"><span class="snscs-pt-read__tag">今日の用語</span><strong>' . esc_html( $t['name'] ) . 'とは？</strong><span>' . esc_html( $t['short'] ) . '</span></a>';
		}
		if ( SNSCS_Hub::page_id( 'qa' ) ) {
			$qs = SNSCS_QA::list_questions( 'new', '', 0, 1 );
			if ( $qs ) {
				$cards .= '<a class="snscs-pt-read snscs-pt-read--qa" href="' . esc_url( SNSCS_QA::url( (int) $qs[0]['id'] ) ) . '"><span class="snscs-pt-read__tag">質問箱の新着</span><strong>' . esc_html( $qs[0]['title'] ) . '</strong><span>' . esc_html( (int) $qs[0]['answers'] ? '回答' . (int) $qs[0]['answers'] . '件' : 'まだ回答がありません。答えてみませんか？' ) . '</span></a>';
			} else {
				$cards .= '<a class="snscs-pt-read snscs-pt-read--qa" href="' . esc_url( SNSCS_Hub::page_url( 'qa' ) ) . '"><span class="snscs-pt-read__tag">質問箱</span><strong>SNS運用の悩みを、発信者に聞いてみよう</strong><span>だれでも無料で質問できます</span></a>';
			}
		}
		$hot = array_values(
			array_filter(
				SNSCS_Trends::ranking( '', 10 ),
				function ( $row ) {
					return $row['hot'];
				}
			)
		);
		if ( $hot ) {
			$words = '';
			foreach ( array_slice( $hot, 0, 4 ) as $row ) {
				$words .= '<span>' . esc_html( $row['term'] ) . '</span>';
			}
			$cards .= '<a class="snscs-pt-read snscs-pt-read--trend" href="' . esc_url( SNSCS_Trends::page_url() ) . '"><span class="snscs-pt-read__tag">急上昇の言葉</span><strong>いま検索が増えている言葉</strong><span class="snscs-pt-read__words">' . $words . '</span></a>';
		} elseif ( SNSCS_Hub::page_id( 'profile-check' ) ) {
			$cards .= '<a class="snscs-pt-read snscs-pt-read--tool" href="' . esc_url( SNSCS_Hub::page_url( 'profile-check' ) ) . '"><span class="snscs-pt-read__tag">無料ツール</span><strong>SNSプロフィールAI診断</strong><span>あなたのプロフィールをAIが点数と書き直し案で診断します</span></a>';
		}
		return self::box( '読みもの', $cards ? '<div class="snscs-pt-reads">' . $cards . '</div>' : '' );
	}

	/** 4. はじめての方へ（読者向け・発信者向けを切り替え） */
	private static function guide() {
		$steps = array(
			'reader'  => array( '読者の方', array( array( '探す', 'ジャンルやSNSから、好きな発信者を探します。' ), array( 'フォロー', '無料の会員登録で、気になる発信者をフォロー。' ), array( '新着が届く', '新しい記事や話題を、お知らせで受け取れます。' ) ), SNSCS_Hub::page_url( 'member' ) ? SNSCS_Hub::page_url( 'member' ) : snscs_search_url(), '無料で会員登録' ),
			'creator' => array( '発信者の方', array( array( '登録', 'SNSアカウントを入れて、無料で掲載を申し込みます。' ), array( 'マイリンク', 'SNSのプロフィールに貼るリンクまとめを作ります。' ), array( '相談が届く', '企業からのお仕事の相談や、新しいファンが増えます。' ) ), snscs_portal_url(), '無料で掲載する' ),
		);
		$html = '<div class="snscs-pt-guide">';
		$i    = 0;
		foreach ( $steps as $key => $g ) {
			$html .= '<input type="radio" name="snscs_pt_guide" id="snscs-pt-guide-' . $key . '" class="snscs-pt-guide__radio"' . ( 0 === $i ? ' checked' : '' ) . '><label for="snscs-pt-guide-' . $key . '" class="snscs-pt-guide__tab">' . esc_html( $g[0] ) . '</label>';
			$i++;
		}
		foreach ( $steps as $key => $g ) {
			$html .= '<div class="snscs-pt-guide__panel snscs-pt-guide__panel--' . $key . '"><ol class="snscs-pt-guide__steps">';
			foreach ( $g[1] as $n => $st ) {
				$html .= '<li><span class="snscs-pt-guide__no">' . ( $n + 1 ) . '</span><strong>' . esc_html( $st[0] ) . '</strong><span>' . esc_html( $st[1] ) . '</span></li>';
			}
			$html .= '</ol><p class="snscs-pt-guide__cta"><a href="' . esc_url( $g[2] ) . '">' . esc_html( $g[3] ) . '</a></p></div>';
		}
		return self::box( 'はじめての方へ', $html . '</div>' );
	}

	private static function center_column() {
		$out = self::hero() . self::founders_banner() . self::game_banner() . self::sns_cards();

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

		$out .= self::reading_picks();
		$out .= SNSCS_Columns::render( 'SNS運用コラム', array( 'count' => 3 ) );
		$out .= self::guide();
		// ここから下は、記事・発信者・マイリンク・広告が延々と続くタイムライン。
		$out .= SNSCS_Timeline::render();
		return $out;
	}

	/**
	 * ログイン中の「マイページ」の枠: 会員ランクのカード（創設メンバーはランクの色とメダル）、レベル、マイページへのリンク。
	 */
	private static function mypage_box( array $who, $creator ) {
		$user_id = get_current_user_id();
		$design  = $who['design'];
		$rank    = SNSCS_Game::rank_of( $user_id );
		$since   = mysql2date( 'Y.m', get_userdata( $user_id )->user_registered );
		if ( $design ) {
			$first = SNSCS_Founder::is_first( $who['no'] );
			$card  = '<div class="snscs-mycard snscs-mycard--' . esc_attr( $design ) . '" style="background-image:url(' . esc_url( SNSCS_URL . 'assets/badges/founder-' . $design . '-card-bg.webp?ver=' . SNSCS_VERSION ) . ')">'
				. '<div class="snscs-mycard__text"><p class="snscs-mycard__eyebrow">FOUNDING MEMBER</p>'
				. '<p class="snscs-mycard__rank">' . esc_html( $who['role'] ) . '</p>'
				. '<p class="snscs-mycard__no">Nº ' . esc_html( SNSCS_Founder::serial( $who['no'] ) ) . ( $first ? ' <span>FIRST 10 ★</span>' : '' ) . '</p>'
				. '<p class="snscs-mycard__since">SINCE ' . esc_html( $since ) . '</p></div>'
				. '<span class="snscs-mycard__medal">' . SNSCS_Founder::medal_html( $who['no'], $design, '' ) . '</span></div>';
		} else {
			// 1.26: 創設メンバーでない人は、カードランクの色のカード（セイント）。
			$card = '<div class="snscs-mycard snscs-mycard--member snscs-mycard--' . esc_attr( SNSCS_Game::rank_keys()[ $rank ] ) . '">'
				. '<div class="snscs-mycard__text"><p class="snscs-mycard__eyebrow">FOLLOFIND SAINT</p>'
				. '<p class="snscs-mycard__rank">' . esc_html( SNSCS_Game::rank_name( $rank ) ) . '</p>'
				. '<p class="snscs-mycard__no">SAINT</p>'
				. '<p class="snscs-mycard__since">SINCE ' . esc_html( $since ) . '</p></div>'
				. '<span class="snscs-mycard__logo" aria-hidden="true"><img src="' . esc_url( SNSCS_Brand::url( 'follofind-icon.svg' ) ) . '" alt="" width="56" height="56"></span></div>';
		}

		// レベル（上限なし）・カードランク・貢献度ゲージ・イベント
		$info   = SNSCS_Game::level_info( $user_id );
		$level  = '<div class="snscs-mylevel"><p><strong>Lv.' . (int) $info['level'] . '</strong> ' . esc_html( $info['title'] )
			. '<span class="snscs-mylevel__next">' . esc_html( 'あと' . number_format_i18n( $info['next'] - $info['xp'] ) ) . '</span></p>'
			. '<div class="snscs-mylevel__bar"><span style="width:' . max( 2, $info['pct'] ) . '%"></span></div>'
			. '<p class="snscs-mylevel__meta">' . ( $design ? '<span>カードランク <strong>' . esc_html( SNSCS_Game::rank_name( $rank ) ) . '</strong></span>' : '' ) . '<span>貢献度 ' . SNSCS_Game::stars_html( $user_id ) . '</span></p></div>';
		$events = SNSCS_Game::events_html( true );

		$is_creator = snscs_is_creator_user();
		$home       = $is_creator ? snscs_portal_url() : ( SNSCS_Hub::page_url( 'member' ) ? SNSCS_Hub::page_url( 'member' ) : snscs_portal_url() );
		$body       = '<p class="snscs-pt-login__who"><span class="snscs-pt-login__dot" aria-hidden="true"></span><strong>' . esc_html( $who['name'] ) . '</strong>さん<span class="snscs-pt-login__state">ログイン中</span></p>'
			. $card . $level . $events
			. '<a class="snscs-pt-btn" href="' . esc_url( $home ) . '">マイページを開く</a>'
			. ( SNSCS_Social::member_url() ? '<a class="snscs-pt-sublink" href="' . esc_url( SNSCS_Social::member_url( array( 'tab' => 'level' ) ) ) . '">冒険の記録を見る</a>' : '' );
		if ( $creator && 'publish' === get_post_status( $creator ) ) {
			$body .= '<a class="snscs-pt-sublink" href="' . esc_url( SNSCS_Biolink::url( $creator ) ) . '">マイリンクを見る</a>';
		}
		// 読者には、創設メンバーの募集中だけ、発信者になる案内をひとこと。
		$limit = (int) SNSCS_Hub::setting( 'founders_limit' );
		$taken = count( self::founders() );
		if ( ! $is_creator && $limit > $taken ) {
			$body .= '<a class="snscs-pt-sublink snscs-mypage__up" href="' . esc_url( snscs_portal_url() ) . '">発信者として掲載すると「創設メンバー」のメダル（残り' . esc_html( number_format_i18n( $limit - $taken ) ) . '枠）</a>';
		} elseif ( $is_creator && ! ( $creator && 'publish' === get_post_status( $creator ) ) && $limit > $taken ) {
			// 審査待ち・申請前の発信者: 承認されるとメダルが付くことを伝える。
			$pending = SNSCS_Submissions::pending_for_user( $user_id );
			$body   .= '<p class="snscs-mypage__note">' . esc_html( ( $pending ? '審査中です。' : '掲載の申請をしましょう。' ) . '審査に通って掲載されると、創設メンバーのメダル（いまなら「' . SNSCS_Founder::name( SNSCS_Founder::design( $taken + 1 ) ) . '」）が付きます。' ) . '</p>';
		}
		$body .= '<a class="snscs-pt-sublink snscs-pt-login__out" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">ログアウト</a>';
		return self::box( 'マイページ', $body, '', 'snscs-pt-box--login snscs-pt-box--mypage is-in' );
	}

	/**
	 * ログイン中の発信者向けのおすすめ（「発信者の方へ」の場所に広告が入っていないとき）。
	 */
	private static function creator_picks() {
		$items = array(
			array( snscs_portal_url() . '#snscs-promo', '検索のいちばん上に出す「PR枠」', 'ジャンルとキーワードを選んで、見つけてもらう機会を増やす' ),
		);
		foreach ( array( 'collab' => array( 'コラボ相手を探す', '同じジャンルの発信者と、いっしょに企画' ), 'tools' => array( 'SNS運用の無料ツール', '投稿の時間・ハッシュタグなど' ), 'qa' => array( '質問箱に答える', '答えると、プロフィールに実績が残ります' ) ) as $key => $item ) {
			if ( SNSCS_Hub::page_url( $key ) ) {
				$items[] = array( SNSCS_Hub::page_url( $key ), $item[0], $item[1] );
			}
		}
		$rows = '';
		foreach ( $items as $item ) {
			$rows .= '<li><a href="' . esc_url( $item[0] ) . '"><strong>' . esc_html( $item[1] ) . '</strong><span>' . esc_html( $item[2] ) . '</span></a></li>';
		}
		return self::box( '発信者のおすすめ', '<ul class="snscs-pt-picks">' . $rows . '</ul>', '', 'snscs-pt-box--picks' );
	}

	private static function rank_row( $rank, array $item, $note ) {
		$main = $item['accounts'] ? $item['accounts'][0]['platform_label'] : '';
		return '<li><span class="snscs-pt-rank">' . (int) $rank . '</span><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a><span class="snscs-pt-meta">' . esc_html( $main ) . '</span>' . ( '' !== $note ? '<span class="snscs-pt-growth">' . esc_html( $note ) . '</span>' : '' ) . '</li>';
	}

	/**
	 * トップ以外のページの右の列（トップの右の列と同じ中身）。
	 */
	public static function sidebar() {
		return self::right_column();
	}

	private static function right_column() {
		$out = SNSCS_Ads::render( 'top_side' );

		// ログイン（1.25: ログイン中は「○○さん ログイン中」。保持を選んだ人はログアウトするまでこのまま）
		$who     = SNSCS_Session::who();
		$creator = $who && snscs_is_creator_user() ? SNSCS_Submissions::creator_id_for_user( get_current_user_id() ) : 0;
		if ( $who ) {
			// 1.25.2: ログイン中は「マイページ」の枠（会員カード）。
			$out .= self::mypage_box( $who, $creator );
		} else {
			$body = '<p>ログインすると、掲載内容の編集や、カレンダーの予定をどの端末からも使えます。</p><a class="snscs-pt-btn" href="' . esc_url( snscs_portal_url() ) . '">ログイン</a><a class="snscs-pt-sublink" href="' . esc_url( snscs_portal_url() ) . '">新規登録（無料）</a>';
			$out .= self::box( '', $body, '', 'snscs-pt-box--login' );
		}

		if ( $who && snscs_is_creator_user() ) {
			// 登録済みの発信者には「発信者の方へ」（登録の案内）は出さず、広告枠にする。広告が無いときは発信者向けのおすすめ。
			$ad   = SNSCS_Ads::render( 'side_member' );
			$out .= '' !== $ad ? $ad : self::creator_picks();
		} else {
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
		}

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
		<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
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
