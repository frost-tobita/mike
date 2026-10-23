<?php
/**
 * 機能ページの下にできる個別のページ（例: /lists/12/、/qa/34/）の共通の仕組み。
 *
 *   URL      : 機能ページのURLの下に「/値/」（パーマリンクを使っていなければ ?クエリ変数=値）
 *   見出し・説明文・正規URL・noindex・構造化データを、WordPress本体と主要なSEOプラグインの両方に渡す。
 *   サイトマップ: 名前・URLの一覧・件数を渡すと、WordPress本体のサイトマップに載せる。
 * 推しリスト（SNSCS_Lists）・質問箱（SNSCS_QA）・プレス（SNSCS_Press）で使う。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Subpages {

	/** 機能ページのキー => クエリ変数 */
	private static $routes = array();

	/** サイトマップ: 名前 => [URLの一覧を返す関数(ページ番号, 件数), 件数を返す関数] */
	private static $sitemaps = array();

	/** いま表示している個別のページ。 */
	private static $current = null;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ), 12 );
		add_action( 'init', array( __CLASS__, 'register_sitemaps' ), 21 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'document_title_parts', array( __CLASS__, 'title_parts' ) );
		add_filter( 'the_title', array( __CLASS__, 'the_title' ), 10, 2 );
		add_filter( 'get_canonical_url', array( __CLASS__, 'canonical' ), 10, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 2 );
		foreach ( array( 'wpseo_title', 'rank_math/frontend/title', 'aioseo_title' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'seo_title' ) );
		}
		foreach ( array( 'wpseo_canonical', 'rank_math/frontend/canonical', 'aioseo_canonical_url' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'seo_canonical' ) );
		}
		foreach ( array( 'wpseo_metadesc', 'rank_math/frontend/description', 'aioseo_description' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'seo_description' ) );
		}
		add_filter( 'wpseo_robots', array( __CLASS__, 'seo_robots' ) );
	}

	public static function route( $page_key, $query_var ) {
		self::$routes[ $page_key ] = $query_var;
	}

	public static function sitemap( $name, callable $urls, callable $count ) {
		self::$sitemaps[ $name ] = array( $urls, $count );
	}

	public static function add_rewrite_rules() {
		$rules = get_option( 'rewrite_rules' );
		foreach ( self::$routes as $key => $var ) {
			$page_id = SNSCS_Hub::page_id( $key );
			$uri     = $page_id ? get_page_uri( $page_id ) : '';
			if ( ! $uri ) {
				continue;
			}
			$regex = '^' . preg_quote( $uri, '#' ) . '/([^/]+)/?$';
			add_rewrite_rule( $regex, 'index.php?page_id=' . $page_id . '&' . $var . '=$matches[1]', 'top' );
			if ( is_array( $rules ) && $rules && ! isset( $rules[ $regex ] ) ) {
				SNSCS_Features::request_flush();
			}
		}
	}

	public static function query_vars( $vars ) {
		return array_merge( $vars, array_values( self::$routes ) );
	}

	/**
	 * 個別のページのURL（機能ページが無ければ空文字）。
	 */
	public static function url( $page_key, $value ) {
		$base = SNSCS_Hub::page_url( $page_key );
		if ( ! $base || ! isset( self::$routes[ $page_key ] ) ) {
			return '';
		}
		if ( ! get_option( 'permalink_structure' ) ) {
			return add_query_arg( self::$routes[ $page_key ], rawurlencode( (string) $value ), $base );
		}
		return trailingslashit( $base ) . rawurlencode( (string) $value ) . '/';
	}

	/**
	 * 個別のページの値（その機能ページで、値が付いているときだけ）。
	 */
	public static function value( $page_key ) {
		$var = self::$routes[ $page_key ] ?? '';
		$v   = '' !== $var ? get_query_var( $var ) : '';
		return is_string( $v ) ? $v : '';
	}

	/**
	 * いま表示しているページを決める。
	 *
	 * @param array $page title（検索結果の題名）、heading（ページの見出し）、description、url、indexable、jsonld（@graph の配列）、page_key。
	 */
	public static function set_current( array $page ) {
		remove_action( 'template_redirect', 'redirect_canonical' );
		self::$current = wp_parse_args( $page, array( 'title' => '', 'heading' => '', 'description' => '', 'url' => '', 'indexable' => true, 'jsonld' => array(), 'page_key' => '' ) );
	}

	public static function current() {
		return self::$current;
	}

	public static function not_found() {
		// WordPress の「似たページへ転送する」を止め、だれにでも 404 を返す。
		remove_action( 'template_redirect', 'redirect_canonical' );
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
	}

	public static function title_parts( $parts ) {
		if ( self::$current ) {
			$parts['title'] = self::$current['title'];
		}
		return $parts;
	}

	public static function the_title( $title, $post_id = 0 ) {
		if ( self::$current && '' !== self::$current['heading'] && (int) $post_id === SNSCS_Hub::page_id( self::$current['page_key'] ) && ! is_admin() ) {
			return self::$current['heading'];
		}
		return $title;
	}

	public static function canonical( $url, $post = null ) {
		return self::$current ? self::$current['url'] : $url;
	}

	public static function robots( array $robots ) {
		if ( self::$current && ! self::$current['indexable'] ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	public static function seo_title( $title ) {
		return self::$current ? self::$current['title'] . ' | ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) : $title;
	}

	public static function seo_canonical( $url ) {
		return self::$current ? self::$current['url'] : $url;
	}

	public static function seo_description( $text ) {
		return self::$current ? self::$current['description'] : $text;
	}

	public static function seo_robots( $robots ) {
		return ( self::$current && ! self::$current['indexable'] ) ? 'noindex, follow' : $robots;
	}

	public static function head() {
		if ( ! self::$current ) {
			return;
		}
		if ( '' !== self::$current['description'] && ! defined( 'WPSEO_VERSION' ) && ! defined( 'RANK_MATH_VERSION' ) && ! defined( 'AIOSEO_VERSION' ) ) {
			echo '<meta name="description" content="' . esc_attr( self::$current['description'] ) . '">' . "\n";
		}
		if ( self::$current['jsonld'] ) {
			echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => self::$current['jsonld'] ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
		}
	}

	/**
	 * パンくずの構造化データ。
	 *
	 * @param array<int,array{0:string,1:string}> $crumbs [URL, 名前]
	 */
	public static function breadcrumb_ld( array $crumbs ) {
		$items = array();
		foreach ( $crumbs as $i => $c ) {
			$items[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[1], 'item' => $c[0] );
		}
		return array( '@type' => 'BreadcrumbList', 'itemListElement' => $items );
	}

	public static function breadcrumbs_html( array $crumbs ) {
		$html = '<nav class="snscs-breadcrumbs" aria-label="パンくずリスト"><ol>';
		foreach ( $crumbs as $c ) {
			$html .= '<li><a href="' . esc_url( $c[0] ) . '">' . esc_html( $c[1] ) . '</a></li>';
		}
		return $html . '</ol></nav>';
	}

	/* --------------------------------------------------------------------
	 * サイトマップ
	 * ------------------------------------------------------------------ */

	public static function register_sitemaps() {
		if ( ! self::$sitemaps || ! function_exists( 'wp_register_sitemap_provider' ) || ! class_exists( 'WP_Sitemaps_Provider' ) ) {
			return;
		}
		require_once SNSCS_DIR . 'includes/class-snscs-subpages-sitemap.php';
		foreach ( self::$sitemaps as $name => $cb ) {
			wp_register_sitemap_provider( $name, new SNSCS_Subpages_Sitemap( $name, $cb[0], $cb[1] ) );
		}
	}
}
