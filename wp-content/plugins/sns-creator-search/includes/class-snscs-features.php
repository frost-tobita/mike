<?php
/**
 * 特集ページ: 「ジャンル × SNS × 地域」の組み合わせごとに自動でできるページ（検索エンジンからの入口）。
 *
 *   /find/                       特集の一覧（[sns_features] を置いた固定ページ）
 *   /find/instagram/             Instagramの発信者
 *   /find/instagram/料理/        料理 × Instagram
 *   /find/instagram/料理/東京都/ 料理 × Instagram × 東京都
 *   /find/all/料理/              料理（SNSは問わない）
 *   ※ 料理・東京都の部分は、ジャンル・地域の「スラッグ」。
 *
 * 各ページには、組み合わせに合わせた見出し・説明文・おすすめ順の一覧・関連する特集へのリンク・
 * 構造化データ（一覧とパンくず）を自動で出す。発信者が少ない組み合わせ（設定した人数未満）は
 * 検索エンジンに載せず（noindex）、サイトマップにも入れない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Features {

	const QUERY_VAR = 'snscs_feature';
	const CACHE     = 'snscs_feature_combos';
	const PER_PAGE  = 30;

	/** いま表示している特集（解析済み）。 */
	private static $current = null;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_action( 'init', array( __CLASS__, 'register_sitemap' ), 20 );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_flush' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'snscs_hub_page_changed', array( __CLASS__, 'request_flush' ) );
		add_action( 'template_redirect', array( __CLASS__, 'resolve' ), 5 );
		add_shortcode( 'sns_features', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'sns_feature_links', array( __CLASS__, 'shortcode_links' ) );
		add_action( 'update_option_snscs_scores_updated', array( __CLASS__, 'clear_cache' ) );

		// 見出し・説明文・正規URL・noindex（WordPress本体と主要なSEOプラグインの両方に対応）。
		add_filter( 'document_title_parts', array( __CLASS__, 'title_parts' ) );
		add_filter( 'the_title', array( __CLASS__, 'the_title' ), 10, 2 );
		add_filter( 'get_canonical_url', array( __CLASS__, 'canonical' ), 10, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 2 );
		add_filter( 'wpseo_title', array( __CLASS__, 'seo_title' ) );
		add_filter( 'wpseo_canonical', array( __CLASS__, 'seo_canonical' ) );
		add_filter( 'wpseo_metadesc', array( __CLASS__, 'seo_description' ) );
		add_filter( 'wpseo_robots', array( __CLASS__, 'seo_robots' ) );
		add_filter( 'rank_math/frontend/title', array( __CLASS__, 'seo_title' ) );
		add_filter( 'rank_math/frontend/canonical', array( __CLASS__, 'seo_canonical' ) );
		add_filter( 'rank_math/frontend/description', array( __CLASS__, 'seo_description' ) );
		add_filter( 'aioseo_title', array( __CLASS__, 'seo_title' ) );
		add_filter( 'aioseo_canonical_url', array( __CLASS__, 'seo_canonical' ) );
		add_filter( 'aioseo_description', array( __CLASS__, 'seo_description' ) );
	}

	/* --------------------------------------------------------------------
	 * URL
	 * ------------------------------------------------------------------ */

	public static function add_rewrite_rule() {
		$page_id = SNSCS_Hub::page_id( 'features' );
		if ( ! $page_id ) {
			return;
		}
		$uri = get_page_uri( $page_id );
		if ( $uri ) {
			add_rewrite_rule( '^' . preg_quote( $uri, '#' ) . '/(.+?)/?$', 'index.php?page_id=' . $page_id . '&' . self::QUERY_VAR . '=$matches[1]', 'top' );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function request_flush() {
		update_option( 'snscs_flush_rewrite', 1 );
	}

	public static function maybe_flush() {
		if ( get_option( 'snscs_flush_rewrite' ) ) {
			delete_option( 'snscs_flush_rewrite' );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * 特集ページのURL。指定しない部分は空文字。
	 */
	public static function url( $platform = '', $genre_slug = '', $region_slug = '' ) {
		$base = SNSCS_Hub::page_url( 'features' );
		if ( ! $base ) {
			// 特集ページが無いときは、同じ条件の検索結果へ。
			return add_query_arg( array_filter( array( 'snscs_platform' => $platform, 'snscs_genre' => $genre_slug, 'snscs_region' => $region_slug ) ), snscs_search_url() );
		}
		$parts = array( '' !== $platform ? $platform : 'all', '' !== $genre_slug ? $genre_slug : 'all', $region_slug );
		while ( $parts && ( '' === end( $parts ) || 'all' === end( $parts ) ) ) {
			array_pop( $parts );
		}
		if ( ! $parts ) {
			return $base;
		}
		if ( ! get_option( 'permalink_structure' ) ) {
			return add_query_arg( self::QUERY_VAR, implode( '/', $parts ), $base );
		}
		return trailingslashit( $base ) . implode( '/', array_map( 'rawurlencode', array_map( 'urldecode', $parts ) ) ) . '/';
	}

	/**
	 * URLの「instagram/料理/東京都」を解析する。使えない形なら null。
	 *
	 * @return array{platform:string,genre:WP_Term|null,region:WP_Term|null}|null
	 */
	public static function parse( $path ) {
		$parts = array_values( array_filter( explode( '/', (string) $path ), 'strlen' ) );
		if ( ! $parts || count( $parts ) > 3 ) {
			return null;
		}
		$platform = 'all' === $parts[0] ? '' : sanitize_key( $parts[0] );
		if ( '' !== $platform && ! array_key_exists( $platform, snscs_platforms() ) ) {
			return null;
		}
		$term = function ( $index, $taxonomy ) use ( $parts ) {
			if ( ! isset( $parts[ $index ] ) || 'all' === $parts[ $index ] ) {
				return null;
			}
			$found = get_term_by( 'slug', sanitize_title( urldecode( $parts[ $index ] ) ), $taxonomy );
			return $found ? $found : false;
		};
		$genre  = $term( 1, SNSCS_Post_Types::TAX_GENRE );
		$region = $term( 2, SNSCS_Post_Types::TAX_REGION );
		if ( false === $genre || false === $region || ( '' === $platform && ! $genre && ! $region ) ) {
			return null;
		}
		return array( 'platform' => $platform, 'genre' => $genre, 'region' => $region );
	}

	/**
	 * 特集のURLで来たら、内容を解析して覚える（無い組み合わせは404）。
	 */
	public static function resolve() {
		$path = get_query_var( self::QUERY_VAR );
		if ( '' === $path || ! is_string( $path ) ) {
			return;
		}
		// 特集のURLは自分で正しい形にそろえる（WordPress本体の転送だと、特集の一覧へ飛ばされてしまう）。
		remove_action( 'template_redirect', 'redirect_canonical' );
		$feature = self::parse( $path );
		if ( ! $feature ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		$feature['args']  = array(
			'platform' => $feature['platform'],
			'genre'    => $feature['genre'] ? $feature['genre']->slug : '',
			'region'   => $feature['region'] ? $feature['region']->slug : '',
		);
		$feature['url']   = self::url( $feature['args']['platform'], $feature['args']['genre'], $feature['args']['region'] );
		$feature['title'] = self::title( $feature );
		$result           = SNSCS_Search::run( array_merge( $feature['args'], array( 'sort' => 'recommended', 'per_page' => self::PER_PAGE ) ) );
		$feature['result'] = $result;
		self::$current     = $feature;

		// 正しい形のURLにそろえる（all の付け方の違いなど）。
		$requested = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$expected  = (string) wp_parse_url( $feature['url'], PHP_URL_PATH );
		if ( get_option( 'permalink_structure' ) && '' !== $requested && untrailingslashit( rawurldecode( $requested ) ) !== untrailingslashit( rawurldecode( $expected ) ) ) {
			wp_safe_redirect( $feature['url'], 301 );
			exit;
		}
	}

	public static function current() {
		return self::$current;
	}

	/**
	 * 見出し。例: 「料理のInstagram発信者（東京都）」
	 */
	public static function title( array $feature ) {
		$genre  = $feature['genre'] ? $feature['genre']->name : '';
		$sns    = '' !== $feature['platform'] ? snscs_platform_label( $feature['platform'] ) : 'SNS';
		$region = $feature['region'] ? $feature['region']->name : '';
		$title  = ( '' !== $genre ? $genre . 'の' : '' ) . $sns . '発信者';
		return ( '' !== $region ? $region . 'の' : '' ) . $title . 'おすすめ一覧';
	}

	/**
	 * 説明文（ページの最初と、検索エンジン向けの説明に使う）。
	 */
	public static function description( array $feature ) {
		$total = (int) $feature['result']['total'];
		$names = wp_list_pluck( array_slice( $feature['result']['items'], 0, 3 ), 'name' );
		$text  = sprintf(
			'%s%s%sで発信している%s人を、おすすめ順に紹介します。',
			$feature['region'] ? $feature['region']->name . 'の' : '',
			$feature['genre'] ? $feature['genre']->name . 'ジャンルを' : '',
			'' !== $feature['platform'] ? snscs_platform_label( $feature['platform'] ) : 'SNS',
			number_format_i18n( $total )
		);
		if ( $names ) {
			$text .= '注目の発信者は' . implode( '、', $names ) . 'など。';
		}
		return $text . 'フォロワー数やジャンルで絞り込んで、気になる発信者を探せます。';
	}

	private static function indexable() {
		return self::$current && (int) self::$current['result']['total'] >= (int) SNSCS_Hub::setting( 'feature_min' );
	}

	/* --------------------------------------------------------------------
	 * 見出し・SEO
	 * ------------------------------------------------------------------ */

	public static function title_parts( $parts ) {
		if ( self::$current ) {
			$parts['title'] = self::$current['title'];
		}
		return $parts;
	}

	public static function the_title( $title, $post_id = 0 ) {
		if ( self::$current && (int) $post_id === SNSCS_Hub::page_id( 'features' ) && ! is_admin() ) {
			return self::$current['title'];
		}
		return $title;
	}

	public static function canonical( $url, $post = null ) {
		return self::$current ? self::$current['url'] : $url;
	}

	public static function robots( array $robots ) {
		if ( self::$current && ! self::indexable() ) {
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
		return self::$current ? self::description( self::$current ) : $text;
	}

	public static function seo_robots( $robots ) {
		return ( self::$current && ! self::indexable() ) ? 'noindex, follow' : $robots;
	}

	/**
	 * 説明文（SEOプラグインが無いとき）と構造化データ。
	 */
	public static function head() {
		if ( ! self::$current ) {
			return;
		}
		$feature = self::$current;
		if ( ! defined( 'WPSEO_VERSION' ) && ! defined( 'RANK_MATH_VERSION' ) && ! defined( 'AIOSEO_VERSION' ) ) {
			echo '<meta name="description" content="' . esc_attr( self::description( $feature ) ) . '">' . "\n";
		}
		$list = array();
		foreach ( $feature['result']['items'] as $i => $item ) {
			$list[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'url' => $item['url'], 'name' => $item['name'] );
		}
		$crumbs = array();
		foreach ( self::breadcrumbs( $feature ) as $i => $crumb ) {
			$crumbs[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb[1], 'item' => $crumb[0] );
		}
		$data = array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				array( '@type' => 'ItemList', 'name' => $feature['title'], 'itemListElement' => $list ),
				array( '@type' => 'BreadcrumbList', 'itemListElement' => $crumbs ),
			),
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}

	/**
	 * パンくず: トップ > 特集 > SNS > ジャンル > 地域
	 *
	 * @return array<int,array{0:string,1:string}> [URL, 名前]
	 */
	public static function breadcrumbs( array $feature ) {
		$crumbs = array( array( home_url( '/' ), 'トップ' ) );
		if ( SNSCS_Hub::page_url( 'features' ) ) {
			$crumbs[] = array( SNSCS_Hub::page_url( 'features' ), '特集' );
		}
		if ( '' !== $feature['platform'] ) {
			$crumbs[] = array( self::url( $feature['platform'] ), snscs_platform_label( $feature['platform'] ) . 'の発信者' );
		}
		if ( $feature['genre'] ) {
			$crumbs[] = array( self::url( $feature['platform'], $feature['genre']->slug ), $feature['genre']->name . ( '' !== $feature['platform'] ? '×' . snscs_platform_label( $feature['platform'] ) : '' ) );
		}
		if ( $feature['region'] ) {
			$crumbs[] = array( $feature['url'], $feature['region']->name );
		}
		return $crumbs;
	}

	/* --------------------------------------------------------------------
	 * 組み合わせの人数（一覧・関連リンク・サイトマップ用。1時間ごとに数え直す）
	 * ------------------------------------------------------------------ */

	public static function clear_cache() {
		delete_transient( self::CACHE );
	}

	/**
	 * @return array<string,int> "platform|genre_id|region_id" => 人数（指定しない部分は空）。
	 */
	public static function combos() {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$accounts     = SNSCS_Accounts::table();
		$platforms    = array_keys( snscs_platforms() );
		$placeholders = implode( ',', array_fill( 0, count( $platforms ), '%s' ) );
		$base         = "FROM {$wpdb->posts} p INNER JOIN {$accounts} a ON a.creator_id = p.ID WHERE p.post_type = %s AND p.post_status = 'publish' AND a.platform IN ({$placeholders})";
		$params       = array_merge( array( SNSCS_Post_Types::POST_TYPE ), $platforms );
		$tax_join     = function ( $alias, $taxonomy ) use ( $wpdb ) {
			return "INNER JOIN {$wpdb->term_relationships} tr{$alias} ON tr{$alias}.object_id = p.ID INNER JOIN {$wpdb->term_taxonomy} tt{$alias} ON tt{$alias}.term_taxonomy_id = tr{$alias}.term_taxonomy_id AND tt{$alias}.taxonomy = '" . esc_sql( $taxonomy ) . "'";
		};
		$from_g  = str_replace( 'WHERE', $tax_join( 'g', SNSCS_Post_Types::TAX_GENRE ) . ' WHERE', $base );
		$from_r  = str_replace( 'WHERE', $tax_join( 'r', SNSCS_Post_Types::TAX_REGION ) . ' WHERE', $base );
		$from_gr = str_replace( 'WHERE', $tax_join( 'g', SNSCS_Post_Types::TAX_GENRE ) . ' ' . $tax_join( 'r', SNSCS_Post_Types::TAX_REGION ) . ' WHERE', $base );

		$queries = array(
			"SELECT a.platform AS s, 0 AS g, 0 AS r, COUNT(DISTINCT p.ID) AS n {$base} GROUP BY a.platform",
			"SELECT '' AS s, ttg.term_id AS g, 0 AS r, COUNT(DISTINCT p.ID) AS n {$from_g} GROUP BY ttg.term_id",
			"SELECT a.platform AS s, ttg.term_id AS g, 0 AS r, COUNT(DISTINCT p.ID) AS n {$from_g} GROUP BY a.platform, ttg.term_id",
			"SELECT '' AS s, 0 AS g, ttr.term_id AS r, COUNT(DISTINCT p.ID) AS n {$from_r} GROUP BY ttr.term_id",
			"SELECT a.platform AS s, 0 AS g, ttr.term_id AS r, COUNT(DISTINCT p.ID) AS n {$from_r} GROUP BY a.platform, ttr.term_id",
			"SELECT '' AS s, ttg.term_id AS g, ttr.term_id AS r, COUNT(DISTINCT p.ID) AS n {$from_gr} GROUP BY ttg.term_id, ttr.term_id",
			"SELECT a.platform AS s, ttg.term_id AS g, ttr.term_id AS r, COUNT(DISTINCT p.ID) AS n {$from_gr} GROUP BY a.platform, ttg.term_id, ttr.term_id",
		);
		$combos = array();
		foreach ( $queries as $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) as $row ) {
				$key            = $row['s'] . '|' . ( (int) $row['g'] ? (int) $row['g'] : '' ) . '|' . ( (int) $row['r'] ? (int) $row['r'] : '' );
				$combos[ $key ] = (int) $row['n'];
			}
		}
		arsort( $combos );
		set_transient( self::CACHE, $combos, HOUR_IN_SECONDS );
		return $combos;
	}

	/**
	 * 組み合わせのキーから、URLと名前。
	 *
	 * @return array{url:string,label:string,platform:string,genre:WP_Term|null,region:WP_Term|null}|null
	 */
	public static function combo_info( $key ) {
		list( $platform, $genre_id, $region_id ) = array_pad( explode( '|', $key ), 3, '' );
		$genre  = '' !== $genre_id ? get_term( (int) $genre_id, SNSCS_Post_Types::TAX_GENRE ) : null;
		$region = '' !== $region_id ? get_term( (int) $region_id, SNSCS_Post_Types::TAX_REGION ) : null;
		if ( is_wp_error( $genre ) || is_wp_error( $region ) || ( '' !== $genre_id && ! $genre ) || ( '' !== $region_id && ! $region ) ) {
			return null;
		}
		$label = implode( '×', array_filter( array( $genre ? $genre->name : '', '' !== $platform ? snscs_platform_label( $platform ) : '', $region ? $region->name : '' ) ) );
		return array(
			'url'      => self::url( $platform, $genre ? $genre->slug : '', $region ? $region->slug : '' ),
			'label'    => $label,
			'platform' => $platform,
			'genre'    => $genre,
			'region'   => $region,
		);
	}

	/**
	 * 条件に合う組み合わせ（人数の多い順）。
	 *
	 * @param callable $filter キー（platform, genre_id, region_id）を受け取り、使うなら true。
	 * @return array<string,int>
	 */
	public static function find_combos( callable $filter, $limit = 12, $min = 1 ) {
		$found = array();
		foreach ( self::combos() as $key => $count ) {
			if ( $count < $min ) {
				continue;
			}
			list( $s, $g, $r ) = array_pad( explode( '|', $key ), 3, '' );
			if ( $filter( $s, $g, $r ) ) {
				$found[ $key ] = $count;
				if ( count( $found ) >= $limit ) {
					break;
				}
			}
		}
		return $found;
	}

	/* --------------------------------------------------------------------
	 * 表示
	 * ------------------------------------------------------------------ */

	/**
	 * 組み合わせへのリンク（チップ）。
	 */
	public static function chips_html( array $combos, $heading = '' ) {
		$chips = '';
		foreach ( $combos as $key => $count ) {
			$info = self::combo_info( $key );
			if ( $info ) {
				$chips .= sprintf( '<li><a class="snscs-chip" href="%s">%s<span class="snscs-chip__count">%s</span></a></li>', esc_url( $info['url'] ), esc_html( $info['label'] ), esc_html( number_format_i18n( $count ) ) );
			}
		}
		if ( '' === $chips ) {
			return '';
		}
		wp_enqueue_style( 'snscs' );
		return '<section class="snscs-feature-links">' . ( '' !== $heading ? '<h2 class="snscs-feature-links__title">' . esc_html( $heading ) . '</h2>' : '' ) . '<ul class="snscs-chips">' . $chips . '</ul></section>';
	}

	/**
	 * [sns_features]: 特集のURLなら特集ページ、そうでなければ特集の一覧。
	 */
	public static function shortcode() {
		SNSCS_Hub::remember_page( 'features' );
		wp_enqueue_style( 'snscs' );
		return self::$current ? self::render_feature( self::$current ) : self::render_index();
	}

	private static function render_feature( array $feature ) {
		SNSCS_Personalize::set_search_context( array_merge( $feature['result']['args'], array( 'q' => '' ) ) );
		SNSCS_Personalize::print_search_context();
		$result = $feature['result'];
		$search = add_query_arg( array_filter( array( 'snscs_platform' => $feature['args']['platform'], 'snscs_genre' => $feature['args']['genre'], 'snscs_region' => $feature['args']['region'] ) ), snscs_search_url() );
		$genre  = $feature['genre'] ? (int) $feature['genre']->term_id : 0;
		$region = $feature['region'] ? (int) $feature['region']->term_id : 0;
		$sns    = $feature['platform'];

		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		?>
		<nav class="snscs-breadcrumbs" aria-label="パンくずリスト">
			<ol>
				<?php foreach ( self::breadcrumbs( $feature ) as $crumb ) : ?>
					<li><a href="<?php echo esc_url( $crumb[0] ); ?>"><?php echo esc_html( $crumb[1] ); ?></a></li>
				<?php endforeach; ?>
			</ol>
		</nav>
		<p class="snscs-feature__lead"><?php echo esc_html( self::description( $feature ) ); ?></p>
		<?php echo SNSCS_Ads::render( 'search_top' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 広告枠のHTML。 ?>
		<?php if ( $result['items'] ) : ?>
			<?php SNSCS_Frontend::render_cards( $result['items'], (int) SNSCS_Ads::settings()['infeed_after'] ); ?>
		<?php else : ?>
			<p class="snscs-results__empty">この組み合わせの発信者は、まだ掲載されていません。</p>
		<?php endif; ?>
		<p class="snscs-feature__more">
			<a class="snscs-button snscs-button--primary" href="<?php echo esc_url( $search ); ?>">
				<?php echo esc_html( $result['total'] > count( $result['items'] ) ? sprintf( '全%s人を、条件を変えて探す', number_format_i18n( $result['total'] ) ) : 'フォロワー数などの条件で絞り込む' ); ?>
			</a>
		</p>
		<?php
		// 関連する特集: 同じジャンルのほかのSNS、同じSNSのほかのジャンル、この組み合わせの地域。
		if ( $genre ) {
			echo self::chips_html( self::find_combos( function ( $s, $g, $r ) use ( $genre, $sns, $region ) { return (int) $g === $genre && '' === $r && '' !== $s && $s !== $sns; }, 6 ), $feature['genre']->name . 'の発信者をほかのSNSで' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		}
		echo self::chips_html( self::find_combos( function ( $s, $g, $r ) use ( $genre, $sns ) { return $s === $sns && '' !== $g && (int) $g !== $genre && '' === $r; }, 12 ), ( '' !== $sns ? snscs_platform_label( $sns ) : 'SNS' ) . 'のほかのジャンル' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		if ( ! $region ) {
			echo self::chips_html( self::find_combos( function ( $s, $g, $r ) use ( $genre, $sns ) { return $s === $sns && (string) $g === (string) ( $genre ? $genre : '' ) && '' !== $r; }, 10 ), '地域で絞り込む' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		}
		echo SNSCS_Columns::render( '関連するSNS運用コラム', array( 'sns' => array_filter( array( $sns ) ), 'genres' => array_filter( array( $genre ) ), 'count' => 3 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo SNSCS_Ads::render( 'search_bottom' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 広告枠のHTML。
		echo SNSCS_Newsletter::mini_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		return ob_get_clean();
	}

	private static function render_index() {
		$min = (int) SNSCS_Hub::setting( 'feature_min' );
		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		?>
		<p class="snscs-feature__lead">SNSとジャンル・地域の組み合わせから、発信者を探せます。</p>
		<?php
		echo SNSCS_Search_UI::popular_conditions_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		foreach ( snscs_platforms() as $key => $label ) {
			$total = isset( self::combos()[ $key . '||' ] ) ? self::combos()[ $key . '||' ] : 0;
			if ( ! $total ) {
				continue;
			}
			echo '<section class="snscs-feature-index">';
			echo '<h2 class="snscs-feature-index__title"><a href="' . esc_url( self::url( $key ) ) . '">' . esc_html( $label ) . 'の発信者</a> <span class="snscs-chip__count">' . esc_html( number_format_i18n( $total ) ) . '人</span></h2>';
			echo self::chips_html( self::find_combos( function ( $s, $g, $r ) use ( $key ) { return $s === $key && '' !== $g && '' === $r; }, 24, $min ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
			echo '</section>';
		}
		echo self::chips_html( self::find_combos( function ( $s, $g, $r ) { return '' === $s && '' !== $g && '' === $r; }, 40, $min ), 'ジャンルから探す（すべてのSNS）' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo self::chips_html( self::find_combos( function ( $s, $g, $r ) { return '' === $s && '' === $g && '' !== $r; }, 47, $min ), '地域から探す' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		return ob_get_clean();
	}

	/**
	 * [sns_feature_links count="12" title="人気の特集"]
	 */
	public static function shortcode_links( $atts ) {
		$atts = shortcode_atts( array( 'count' => 12, 'title' => '人気の特集' ), $atts, 'sns_feature_links' );
		return self::chips_html( self::find_combos( function ( $s, $g, $r ) { return '' !== $s && '' !== $g && '' === $r; }, min( 40, max( 1, (int) $atts['count'] ) ), (int) SNSCS_Hub::setting( 'feature_min' ) ), sanitize_text_field( $atts['title'] ) );
	}

	/**
	 * 検索結果の下: 今の条件に近い特集へのリンク。
	 */
	public static function related_html( array $args ) {
		$genre = '' !== $args['genre'] ? get_term_by( 'slug', $args['genre'], SNSCS_Post_Types::TAX_GENRE ) : null;
		$sns   = $args['platform'];
		$gid   = $genre ? (string) $genre->term_id : '';
		$found = self::find_combos(
			function ( $s, $g, $r ) use ( $sns, $gid ) {
				if ( '' !== $r ) {
					return false;
				}
				if ( '' !== $gid ) {
					return $g === $gid && '' !== $s;
				}
				return ( '' === $sns || $s === $sns ) && '' !== $g && '' !== $s;
			},
			10
		);
		return self::chips_html( $found, '特集から探す' );
	}

	/**
	 * 発信者ページのパンくずと、その人が載っている特集へのリンク。
	 */
	public static function creator_links_html( $creator_id ) {
		$genres    = wp_get_object_terms( $creator_id, SNSCS_Post_Types::TAX_GENRE );
		$platforms = array_values( array_unique( wp_list_pluck( SNSCS_Accounts::get_for_creator( $creator_id ), 'platform' ) ) );
		$genres    = is_wp_error( $genres ) ? array() : $genres;
		$combos    = self::combos();
		$links     = array();
		foreach ( $platforms as $platform ) {
			foreach ( $genres as $genre ) {
				$key = $platform . '|' . $genre->term_id . '|';
				if ( isset( $combos[ $key ] ) ) {
					$links[ $key ] = $combos[ $key ];
				}
			}
		}
		arsort( $links );
		$main = $platforms ? $platforms[0] : '';
		$html = '';
		if ( SNSCS_Hub::page_url( 'features' ) && ( $main || $genres ) ) {
			$crumbs = array( array( home_url( '/' ), 'トップ' ) );
			if ( $main ) {
				$crumbs[] = array( self::url( $main ), snscs_platform_label( $main ) . 'の発信者' );
			}
			if ( $genres ) {
				$crumbs[] = array( self::url( $main, $genres[0]->slug ), $genres[0]->name . ( $main ? '×' . snscs_platform_label( $main ) : '' ) );
			}
			$html .= '<nav class="snscs-breadcrumbs" aria-label="パンくずリスト"><ol>';
			foreach ( $crumbs as $crumb ) {
				$html .= '<li><a href="' . esc_url( $crumb[0] ) . '">' . esc_html( $crumb[1] ) . '</a></li>';
			}
			$html .= '<li aria-current="page">' . esc_html( get_the_title( $creator_id ) ) . '</li></ol></nav>';
		}
		return $html . self::chips_html( array_slice( $links, 0, 6, true ), 'この発信者が載っている特集' );
	}

	/* --------------------------------------------------------------------
	 * サイトマップ（WordPress本体の /wp-sitemap.xml に追加）
	 * ------------------------------------------------------------------ */

	public static function register_sitemap() {
		if ( ! function_exists( 'wp_register_sitemap_provider' ) || ! class_exists( 'WP_Sitemaps_Provider' ) || ! SNSCS_Hub::page_id( 'features' ) ) {
			return;
		}
		require_once SNSCS_DIR . 'includes/class-snscs-features-sitemap.php';
		wp_register_sitemap_provider( 'snscsfeatures', new SNSCS_Features_Sitemap() );
	}

	/**
	 * サイトマップに載せる特集のURL（設定した人数以上の組み合わせだけ）。
	 *
	 * @return string[]
	 */
	public static function sitemap_urls() {
		$min  = (int) SNSCS_Hub::setting( 'feature_min' );
		$urls = array( SNSCS_Hub::page_url( 'features' ) );
		foreach ( self::combos() as $key => $count ) {
			if ( $count < $min ) {
				continue;
			}
			$info = self::combo_info( $key );
			if ( $info ) {
				$urls[] = $info['url'];
			}
		}
		return array_values( array_unique( array_filter( $urls ) ) );
	}
}
