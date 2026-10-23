<?php
/**
 * トレンドのキーワードごとのページ（検索エンジンからの入口）。
 *
 *   /trends/            トレンドのページ（[sns_trends] を置いた固定ページ）
 *   /trends/おせち/     「おせち」のページ
 *
 * 中身: 調べた人数とここ14日の推移、急上昇しているジャンル、そのキーワードで見つかる発信者、
 * 関連する記事、同じジャンルのほかのトレンド。構造化データ（パンくず・一覧）つき。
 *
 * 検索エンジンに載せるのは、直近30日に設定した人数（トレンドの表示と同じ。最低3人）以上が
 * 調べた言葉だけ。それ未満は noindex にし、サイトマップにも入れない。
 * 記録の無い言葉・表示しない言葉は 404。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Trend_Pages {

	const QUERY_VAR   = 'snscs_trend_term';
	const PER_PAGE    = 12;
	const SITEMAP_MAX = 500;

	/** いま表示しているキーワードのページ（解析済み）。 */
	private static $current = null;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_action( 'init', array( __CLASS__, 'register_sitemap' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'resolve' ), 5 );

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
		$page_id = SNSCS_Hub::page_id( 'trends' );
		if ( ! $page_id ) {
			return;
		}
		$uri = get_page_uri( $page_id );
		if ( $uri ) {
			$regex = '^' . preg_quote( $uri, '#' ) . '/([^/]+)/?$';
			add_rewrite_rule( $regex, 'index.php?page_id=' . $page_id . '&' . self::QUERY_VAR . '=$matches[1]', 'top' );
			// ページを作った直後など、保存済みの規則にまだ無ければ作り直してもらう（SNSCS_Features::maybe_flush）。
			$rules = get_option( 'rewrite_rules' );
			if ( is_array( $rules ) && $rules && ! isset( $rules[ $regex ] ) ) {
				SNSCS_Features::request_flush();
			}
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * キーワードのページのURL。ページを作れない言葉（トレンドのページが無い・「/」を含む）は検索結果へ。
	 */
	public static function url( $term, $genre = '' ) {
		$base = SNSCS_Hub::page_url( 'trends' );
		if ( ! $base || false !== strpos( $term, '/' ) ) {
			return SNSCS_Trends::search_link( $term, $genre );
		}
		if ( ! get_option( 'permalink_structure' ) ) {
			return add_query_arg( self::QUERY_VAR, rawurlencode( $term ), $base );
		}
		return trailingslashit( $base ) . rawurlencode( $term ) . '/';
	}

	/* --------------------------------------------------------------------
	 * 数字
	 * ------------------------------------------------------------------ */

	private static function since( $days ) {
		return gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . ( $days - 1 ) . ' days' ) );
	}

	/**
	 * 直近 $days 日に調べた人数。
	 */
	public static function visitors( $term, $days ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(visitors) FROM ' . SNSCS_Trends::terms_table() . ' WHERE term = %s AND day >= %s', $term, self::since( $days ) ) );
	}

	/**
	 * ここ $days 日の、日ごとの人数（古い順。記録の無い日は0）。
	 *
	 * @return array<string,int> 日付 => 人数
	 */
	public static function series( $term, $days = 14 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT day, visitors FROM ' . SNSCS_Trends::terms_table() . ' WHERE term = %s AND day >= %s', $term, self::since( $days ) ), ARRAY_A );
		$out  = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$out[ gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . $i . ' days' ) ) ] = 0;
		}
		foreach ( $rows as $row ) {
			if ( isset( $out[ $row['day'] ] ) ) {
				$out[ $row['day'] ] = (int) $row['visitors'];
			}
		}
		return $out;
	}

	/**
	 * この言葉が調べられているジャンル（直近7日の人数の多い順）。
	 *
	 * @return array<int,array{term:WP_Term,visitors:int,hot:bool}>
	 */
	public static function genres( $term ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT genre, SUM(visitors) AS n FROM ' . SNSCS_Trends::genre_table() . ' WHERE term = %s AND day >= %s GROUP BY genre ORDER BY n DESC LIMIT 5', $term, self::since( 7 ) ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$genre = get_term_by( 'slug', $row['genre'], SNSCS_Post_Types::TAX_GENRE );
			if ( $genre ) {
				$out[] = array( 'term' => $genre, 'visitors' => (int) $row['n'], 'hot' => in_array( $term, SNSCS_Trends::stats( $genre->slug )['hot'], true ) );
			}
		}
		return $out;
	}

	/**
	 * 検索エンジンに載せてよい人数の下限。
	 */
	private static function index_min() {
		return max( 3, (int) SNSCS_Hub::setting( 'trend_min_visitors' ) );
	}

	/* --------------------------------------------------------------------
	 * 表示の準備
	 * ------------------------------------------------------------------ */

	public static function resolve() {
		$raw = get_query_var( self::QUERY_VAR );
		if ( '' === $raw || ! is_string( $raw ) ) {
			return;
		}
		remove_action( 'template_redirect', 'redirect_canonical' );
		$term = SNSCS_Trends::normalize( urldecode( $raw ) );
		$days = '' === $term ? 0 : self::visitors( $term, SNSCS_Trends::KEEP_DAYS );
		if ( '' === $term || ! $days || ! SNSCS_Trends::allowed( $term ) || false !== strpos( $term, '/' ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		$week    = self::visitors( $term, 7 );
		$month   = self::visitors( $term, 30 );
		$genres  = self::genres( $term );
		$main    = $genres ? $genres[0]['term'] : null;
		$current = array(
			'term'      => $term,
			'url'       => self::url( $term ),
			'week'      => $week,
			'month'     => $month,
			'series'    => self::series( $term ),
			'genres'    => $genres,
			'hot'       => in_array( $term, SNSCS_Trends::stats()['hot'], true ),
			'indexable' => $month >= self::index_min(),
			'result'    => SNSCS_Search::run( array( 'q' => $term, 'sort' => 'recommended', 'per_page' => self::PER_PAGE ) ),
			'articles'  => self::articles( $term ),
			'columns'   => self::columns( $term ),
			'related'   => $main ? SNSCS_Trends::ranking( $main->slug, 13 ) : SNSCS_Trends::ranking( '', 13 ),
			'main'      => $main,
		);
		$current['title'] = self::title( $current );
		self::$current    = $current;

		// 大文字・全角などの書き方の違いは、そろえた形のURLへ。
		$requested = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$expected  = (string) wp_parse_url( $current['url'], PHP_URL_PATH );
		if ( get_option( 'permalink_structure' ) && '' !== $requested && untrailingslashit( rawurldecode( $requested ) ) !== untrailingslashit( rawurldecode( $expected ) ) ) {
			wp_safe_redirect( $current['url'], 301 );
			exit;
		}
	}

	public static function current() {
		return self::$current;
	}

	/**
	 * 本文やタイトル・ハッシュタグにこの言葉を含む、公開中の記事。
	 *
	 * @return int[]
	 */
	private static function articles( $term ) {
		$query = new WP_Query(
			array(
				'post_type'           => SNSCS_Articles::POST_TYPE,
				'post_status'         => 'publish',
				's'                   => $term,
				'posts_per_page'      => 6,
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);
		return array_map( 'intval', $query->posts );
	}

	public static function title( array $page ) {
		return sprintf( '「%s」のトレンド｜人気のSNS発信者と話題の記事', $page['term'] );
	}

	public static function description( array $page ) {
		$text = sprintf( '「%s」は、ここ7日で%s人が検索したキーワードです。', $page['term'], number_format_i18n( $page['week'] ) );
		if ( $page['main'] ) {
			$text .= sprintf( '%sジャンルで%s。', $page['main']->name, $page['genres'][0]['hot'] ? '急上昇しています' : 'よく調べられています' );
		}
		$names = wp_list_pluck( array_slice( $page['result']['items'], 0, 3 ), 'name' );
		if ( $names ) {
			$text .= sprintf( '「%s」で見つかる発信者は%s人。', $page['term'], number_format_i18n( $page['result']['total'] ) ) . implode( '、', $names ) . 'など。';
		}
		return $text . '関連する記事や、同じジャンルのトレンドも紹介します。';
	}

	/* --------------------------------------------------------------------
	 * 見出し・SEO（特集ページと同じ形）
	 * ------------------------------------------------------------------ */

	public static function title_parts( $parts ) {
		if ( self::$current ) {
			$parts['title'] = self::$current['title'];
		}
		return $parts;
	}

	public static function the_title( $title, $post_id = 0 ) {
		if ( self::$current && (int) $post_id === SNSCS_Hub::page_id( 'trends' ) && ! is_admin() ) {
			return '「' . self::$current['term'] . '」のトレンド';
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
		return self::$current ? self::description( self::$current ) : $text;
	}

	public static function seo_robots( $robots ) {
		return ( self::$current && ! self::$current['indexable'] ) ? 'noindex, follow' : $robots;
	}

	/**
	 * パンくず: トップ > トレンド > (ジャンル) > キーワード
	 *
	 * @return array<int,array{0:string,1:string}> [URL, 名前]
	 */
	public static function breadcrumbs( array $page ) {
		$crumbs = array( array( home_url( '/' ), 'トップ' ), array( SNSCS_Trends::page_url(), 'トレンド' ) );
		if ( $page['main'] ) {
			$crumbs[] = array( SNSCS_Trends::page_url( $page['main']->slug ), $page['main']->name . 'のトレンド' );
		}
		$crumbs[] = array( $page['url'], $page['term'] );
		return $crumbs;
	}

	public static function head() {
		if ( ! self::$current ) {
			return;
		}
		$page = self::$current;
		if ( ! defined( 'WPSEO_VERSION' ) && ! defined( 'RANK_MATH_VERSION' ) && ! defined( 'AIOSEO_VERSION' ) ) {
			echo '<meta name="description" content="' . esc_attr( self::description( $page ) ) . '">' . "\n";
		}
		$list = array();
		foreach ( $page['result']['items'] as $i => $item ) {
			$list[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'url' => $item['url'], 'name' => $item['name'] );
		}
		$crumbs = array();
		foreach ( self::breadcrumbs( $page ) as $i => $crumb ) {
			$crumbs[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb[1], 'item' => $crumb[0] );
		}
		$graph = array( array( '@type' => 'BreadcrumbList', 'itemListElement' => $crumbs ) );
		if ( $list ) {
			$graph[] = array( '@type' => 'ItemList', 'name' => '「' . $page['term'] . '」で人気の発信者', 'itemListElement' => $list );
		}
		echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}

	/* --------------------------------------------------------------------
	 * 表示（[sns_trends] がキーワードのURLで表示されたとき）
	 * ------------------------------------------------------------------ */

	public static function render( array $page ) {
		$term   = $page['term'];
		$genre  = $page['main'] ? $page['main']->slug : '';
		$series = $page['series'];
		$peak   = max( 1, max( $series ) );
		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		?>
		<nav class="snscs-breadcrumbs" aria-label="パンくずリスト">
			<ol>
				<?php foreach ( self::breadcrumbs( $page ) as $crumb ) : ?>
					<li><a href="<?php echo esc_url( $crumb[0] ); ?>"><?php echo esc_html( $crumb[1] ); ?></a></li>
				<?php endforeach; ?>
			</ol>
		</nav>
		<div class="snscs-trendterm">
			<p class="snscs-trendterm__lead"><?php echo esc_html( self::description( $page ) ); ?></p>
			<dl class="snscs-trendterm__stats">
				<div><dt>ここ7日で検索した人</dt><dd><?php echo esc_html( number_format_i18n( $page['week'] ) ); ?>人<?php echo $page['hot'] ? ' <span class="snscs-trendterm__hot">急上昇</span>' : ''; ?></dd></div>
				<div><dt>ここ30日</dt><dd><?php echo esc_html( number_format_i18n( $page['month'] ) ); ?>人</dd></div>
				<div><dt>見つかる発信者</dt><dd><?php echo esc_html( number_format_i18n( $page['result']['total'] ) ); ?>人</dd></div>
			</dl>

			<h2 class="snscs-trendpage__title">検索した人の推移（14日間）</h2>
			<ol class="snscs-trendchart" aria-label="<?php echo esc_attr( '日ごとの検索した人の数' ); ?>">
				<?php foreach ( $series as $day => $count ) : ?>
					<li class="snscs-trendchart__bar" style="--h:<?php echo esc_attr( (string) round( $count / $peak * 100 ) ); ?>%">
						<span class="snscs-trendchart__value"><?php echo esc_html( (string) $count ); ?></span>
						<span class="snscs-trendchart__day"><?php echo esc_html( wp_date( 'n/j', strtotime( $day . ' 12:00:00' ) ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>

			<?php if ( $page['genres'] ) : ?>
				<h2 class="snscs-trendpage__title">調べられているジャンル</h2>
				<ul class="snscs-chips">
					<?php foreach ( $page['genres'] as $row ) : ?>
						<li><a class="snscs-chip<?php echo $row['hot'] ? ' snscs-chip--hot' : ''; ?>" href="<?php echo esc_url( SNSCS_Trends::page_url( $row['term']->slug ) ); ?>"><?php echo esc_html( $row['term']->name ); ?> <span class="snscs-chip__count"><?php echo esc_html( number_format_i18n( $row['visitors'] ) ); ?>人</span></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2 class="snscs-trendpage__title">「<?php echo esc_html( $term ); ?>」で人気の発信者</h2>
			<?php echo SNSCS_Ads::render( 'search_top' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 広告枠のHTML。 ?>
			<?php if ( $page['result']['items'] ) : ?>
				<?php SNSCS_Frontend::render_cards( $page['result']['items'], (int) SNSCS_Ads::settings()['infeed_after'] ); ?>
			<?php else : ?>
				<p class="snscs-results__empty">いまは、この言葉で見つかる発信者がいません。</p>
			<?php endif; ?>
			<p class="snscs-feature__more">
				<a class="snscs-button snscs-button--primary" href="<?php echo esc_url( SNSCS_Trends::search_link( $term ) ); ?>">
					<?php echo esc_html( $page['result']['total'] > count( $page['result']['items'] ) ? sprintf( '「%s」の発信者を全%s人から探す', $term, number_format_i18n( $page['result']['total'] ) ) : sprintf( '「%s」で条件を変えて探す', $term ) ); ?>
				</a>
			</p>

			<?php echo SNSCS_Columns::render_posts( '「' . $term . '」のコラム・トレンド記事', $page['columns'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>

			<?php if ( $page['articles'] ) : ?>
				<h2 class="snscs-trendpage__title">「<?php echo esc_html( $term ); ?>」の記事</h2>
				<?php
				$items = array();
				foreach ( $page['articles'] as $id ) {
					$items[] = array( 'id' => $id, 'note' => '' );
				}
				echo SNSCS_Social::render_list( $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
				?>
			<?php endif; ?>

			<?php
			$related = array_values(
				array_filter(
					$page['related'],
					function ( $row ) use ( $term ) {
						return $row['term'] !== $term;
					}
				)
			);
			?>
			<?php if ( $related ) : ?>
				<h2 class="snscs-trendpage__title"><?php echo esc_html( $page['main'] ? $page['main']->name . 'のほかのトレンド' : 'ほかのトレンド' ); ?></h2>
				<ul class="snscs-chips">
					<?php foreach ( array_slice( $related, 0, 12 ) as $row ) : ?>
						<li><a class="snscs-chip<?php echo $row['hot'] ? ' snscs-chip--hot' : ''; ?>" href="<?php echo esc_url( self::url( $row['term'] ) ); ?>"><?php echo esc_html( $row['term'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="snscs-trends__more"><a href="<?php echo esc_url( SNSCS_Trends::page_url( $genre ) ); ?>">トレンドの一覧へ</a></p>
		</div>
		<?php
		echo SNSCS_Ads::render( 'search_bottom' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 広告枠のHTML。
		echo SNSCS_Newsletter::mini_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		return ob_get_clean();
	}

	/* --------------------------------------------------------------------
	 * サイトマップ
	 * ------------------------------------------------------------------ */

	public static function register_sitemap() {
		if ( ! function_exists( 'wp_register_sitemap_provider' ) || ! class_exists( 'WP_Sitemaps_Provider' ) || ! SNSCS_Hub::page_id( 'trends' ) ) {
			return;
		}
		require_once SNSCS_DIR . 'includes/class-snscs-trend-sitemap.php';
		wp_register_sitemap_provider( 'snscstrends', new SNSCS_Trend_Sitemap() );
	}

	/**
	 * サイトマップに載せるキーワードのページ（直近30日に下限の人数以上が調べた言葉。多い順）。
	 *
	 * @return string[]
	 */
	public static function sitemap_urls() {
		return array_merge( array( SNSCS_Trends::page_url() ), array_map( array( __CLASS__, 'url' ), self::indexable_terms( self::SITEMAP_MAX ) ) );
	}

	/**
	 * 検索エンジンに載せているキーワード（直近30日に下限の人数以上が調べた言葉。多い順。10分ごとに数え直す）。
	 *
	 * @return string[]
	 */
	public static function indexable_terms( $limit = self::SITEMAP_MAX ) {
		$cached = get_transient( 'snscs_trend_indexable' );
		if ( ! is_array( $cached ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$terms  = $wpdb->get_col( $wpdb->prepare( 'SELECT term FROM ' . SNSCS_Trends::terms_table() . ' WHERE day >= %s GROUP BY term HAVING SUM(visitors) >= %d ORDER BY SUM(visitors) DESC LIMIT %d', self::since( 30 ), self::index_min(), self::SITEMAP_MAX ), 0 );
			$cached = array();
			foreach ( $terms as $term ) {
				if ( SNSCS_Trends::allowed( $term ) && false === strpos( $term, '/' ) ) {
					$cached[] = (string) $term;
				}
			}
			set_transient( 'snscs_trend_indexable', $cached, 10 * MINUTE_IN_SECONDS );
		}
		return array_slice( $cached, 0, $limit );
	}

	/**
	 * この言葉のコラム（その言葉のトレンド記事を先に、ほかは本文にその言葉を含む新しいもの）。
	 *
	 * @return WP_Post[]
	 */
	public static function columns( $term, $limit = 4 ) {
		$cat = SNSCS_Columns::category_id();
		if ( ! $cat ) {
			return array();
		}
		$own   = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'meta_key' => SNSCS_Column_Kinds::META_TERM, 'meta_value' => $term, 'numberposts' => 2 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$other = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'cat' => $cat, 's' => $term, 'numberposts' => $limit, 'post__not_in' => wp_list_pluck( $own, 'ID' ) ) );
		return array_slice( array_merge( $own, $other ), 0, $limit );
	}
}
