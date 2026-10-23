<?php
/**
 * 「○○さんに似ている発信者」のページ（発信者の名前で検索する人の入口）。
 *
 *   /similar/              似ている発信者のページ（[sns_similar] を置いた固定ページ。一覧は出さず、案内だけ）
 *   /similar/{発信者のスラッグ}/  その人に似ている発信者10人
 *
 * 似ている度合い（0〜1）:
 *   0.45 × 一緒に見られている強さ（SNSCS_Personalize::predict。いちばん強い人を1）
 *   ＋ 0.25 × ジャンルの重なり（共通のジャンル ÷ 合わせたジャンル）
 *   ＋ 0.15 × SNSの重なり
 *   ＋ 0.15 × フォロワーの規模の近さ（桁の差が3桁で0）
 *   を、総合点で少し（0.7〜1倍）、不正対策の倍率で掛けて並べる。12時間ごとに作り直す。
 * 似ている人が5人未満のとき・その発信者が不正対策で下げられているときは、検索エンジンに載せない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Similar {

	const QUERY_VAR = 'snscs_similar';
	const SIZE      = 10;
	const MIN       = 5;

	private static $current = null;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_action( 'init', array( __CLASS__, 'register_sitemap' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'resolve' ), 5 );
		add_shortcode( 'sns_similar', array( __CLASS__, 'shortcode' ) );
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
		add_action( 'save_post_' . SNSCS_Post_Types::POST_TYPE, array( __CLASS__, 'forget' ) );
	}

	/* --------------------------------------------------------------------
	 * URL
	 * ------------------------------------------------------------------ */

	public static function add_rewrite_rule() {
		$page_id = SNSCS_Hub::page_id( 'similar' );
		$uri     = $page_id ? get_page_uri( $page_id ) : '';
		if ( ! $uri ) {
			return;
		}
		$regex = '^' . preg_quote( $uri, '#' ) . '/([^/]+)/?$';
		add_rewrite_rule( $regex, 'index.php?page_id=' . $page_id . '&' . self::QUERY_VAR . '=$matches[1]', 'top' );
		$rules = get_option( 'rewrite_rules' );
		if ( is_array( $rules ) && $rules && ! isset( $rules[ $regex ] ) ) {
			SNSCS_Features::request_flush();
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * その発信者の「似ている発信者」のページのURL（ページが無ければ空文字）。
	 */
	public static function url( $creator_id ) {
		$base = SNSCS_Hub::page_url( 'similar' );
		$slug = get_post_field( 'post_name', $creator_id );
		if ( ! $base || '' === $slug ) {
			return '';
		}
		if ( ! get_option( 'permalink_structure' ) ) {
			return add_query_arg( self::QUERY_VAR, $slug, $base );
		}
		return trailingslashit( $base ) . $slug . '/';
	}

	/* --------------------------------------------------------------------
	 * 似ている発信者
	 * ------------------------------------------------------------------ */

	public static function forget( $creator_id ) {
		delete_transient( 'snscs_similar_' . (int) $creator_id );
	}

	/**
	 * @return array<int,array{score:float,reasons:string[]}> 発信者ID => 点と理由（高い順）
	 */
	public static function find( $creator_id, $limit = self::SIZE ) {
		$key    = 'snscs_similar_' . (int) $creator_id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return array_slice( $cached, 0, $limit, true );
		}
		$coview = SNSCS_Personalize::predict( array( $creator_id ), 40 );
		$cv_max = $coview ? max( $coview ) : 0;
		$cands  = array_unique( array_merge( array_keys( $coview ), SNSCS_Recommend::similar_ids( $creator_id, 60 ) ) );
		$cands  = array_values( array_diff( array_map( 'intval', $cands ), array( (int) $creator_id ) ) );
		if ( ! $cands ) {
			set_transient( $key, array(), 12 * HOUR_IN_SECONDS );
			return array();
		}
		update_object_term_cache( $cands, SNSCS_Post_Types::POST_TYPE );
		$genres_of = function ( $id ) {
			$t = get_the_terms( $id, SNSCS_Post_Types::TAX_GENRE );
			return ( $t && ! is_wp_error( $t ) ) ? wp_list_pluck( $t, 'name', 'term_id' ) : array();
		};
		$accounts  = SNSCS_Accounts::get_for_creators( array_merge( $cands, array( (int) $creator_id ) ) );
		$sns_of    = function ( $id ) use ( $accounts ) {
			return array_values( array_unique( wp_list_pluck( $accounts[ $id ] ?? array(), 'platform' ) ) );
		};
		$size_of   = function ( $id ) use ( $accounts ) {
			return array_sum( array_map( 'intval', wp_list_pluck( $accounts[ $id ] ?? array(), 'followers' ) ) );
		};
		$my_genres = $genres_of( $creator_id );
		$my_sns    = $sns_of( $creator_id );
		$my_size   = log10( $size_of( $creator_id ) + 1 );
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$scores    = wp_list_pluck( $wpdb->get_results( 'SELECT creator_id, score FROM ' . SNSCS_Scores::table() . ' WHERE creator_id IN (' . implode( ',', $cands ) . ')', ARRAY_A ), 'score', 'creator_id' );
		$penalties = SNSCS_Integrity::penalties();

		$out = array();
		foreach ( $cands as $id ) {
			if ( 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			$g       = $genres_of( $id );
			$common  = array_intersect_key( $g, $my_genres );
			$union   = count( $g + $my_genres );
			$s       = $sns_of( $id );
			$both    = array_intersect( $s, $my_sns );
			$size    = log10( $size_of( $id ) + 1 );
			$scale   = max( 0, 1 - abs( $size - $my_size ) / 3 );
			$cv      = $cv_max > 0 && isset( $coview[ $id ] ) ? $coview[ $id ] / $cv_max : 0;
			$value   = 0.45 * $cv + 0.25 * ( $union ? count( $common ) / $union : 0 ) + 0.15 * ( $my_sns ? count( $both ) / count( array_unique( array_merge( $s, $my_sns ) ) ) : 0 ) + 0.15 * $scale;
			$value  *= ( 0.7 + 0.3 * min( 1, (float) ( $scores[ $id ] ?? 0 ) / 100 ) ) * ( $penalties[ $id ] ?? 1 );
			$reasons = array();
			if ( $cv >= 0.2 ) {
				$reasons[] = '一緒に見られています';
			}
			if ( $common ) {
				$reasons[] = '同じ' . implode( '・', array_slice( $common, 0, 2 ) ) . 'ジャンル';
			}
			if ( $both ) {
				$reasons[] = '同じ' . implode( '・', array_map( 'snscs_platform_label', array_slice( array_values( $both ), 0, 2 ) ) );
			}
			if ( $scale >= 0.8 && $size > 0 ) {
				$reasons[] = 'フォロワーの規模が近い';
			}
			$out[ $id ] = array( 'score' => $value, 'reasons' => $reasons );
		}
		uasort(
			$out,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		$out = array_slice( $out, 0, 20, true );
		set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
		return array_slice( $out, 0, $limit, true );
	}

	/* --------------------------------------------------------------------
	 * ページ
	 * ------------------------------------------------------------------ */

	public static function resolve() {
		$slug = get_query_var( self::QUERY_VAR );
		if ( '' === $slug || ! is_string( $slug ) ) {
			return;
		}
		remove_action( 'template_redirect', 'redirect_canonical' );
		$found   = get_posts( array( 'post_type' => SNSCS_Post_Types::POST_TYPE, 'name' => sanitize_title( urldecode( $slug ) ), 'post_status' => 'publish', 'numberposts' => 1 ) );
		$creator = $found ? $found[0] : null;
		if ( ! $creator ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		$similar   = self::find( $creator->ID );
		$items     = SNSCS_Personalize::items( array_keys( $similar ) );
		$penalties = SNSCS_Integrity::penalties();
		$genres    = get_the_terms( $creator->ID, SNSCS_Post_Types::TAX_GENRE );
		self::$current = array(
			'creator'   => $creator,
			'name'      => html_entity_decode( get_the_title( $creator ), ENT_QUOTES, 'UTF-8' ),
			'url'       => self::url( $creator->ID ),
			'similar'   => $similar,
			'items'     => $items,
			'genre'     => ( $genres && ! is_wp_error( $genres ) ) ? $genres[0] : null,
			'indexable' => count( $items ) >= self::MIN && ( $penalties[ $creator->ID ] ?? 1 ) >= 1,
		);
	}

	public static function current() {
		return self::$current;
	}

	public static function title( array $page ) {
		return sprintf( '%sさんに似ているSNS発信者%d選｜好きな人におすすめ', $page['name'], count( $page['items'] ) );
	}

	public static function description( array $page ) {
		$names = wp_list_pluck( array_slice( $page['items'], 0, 3 ), 'name' );
		return sprintf(
			'%sさん%sが好きな人におすすめの発信者を、一緒に見られているデータとジャンル・SNS・フォロワーの規模から選びました。%s',
			$page['name'],
			$page['genre'] ? '（' . $page['genre']->name . '）' : '',
			$names ? implode( '、', $names ) . 'など' . count( $page['items'] ) . '人を紹介します。' : ''
		);
	}

	public static function title_parts( $parts ) {
		if ( self::$current ) {
			$parts['title'] = self::title( self::$current );
		}
		return $parts;
	}

	public static function the_title( $title, $post_id = 0 ) {
		if ( self::$current && (int) $post_id === SNSCS_Hub::page_id( 'similar' ) && ! is_admin() ) {
			return self::$current['name'] . 'さんに似ている発信者';
		}
		return $title;
	}

	public static function canonical( $url, $post = null ) {
		return self::$current ? self::$current['url'] : $url;
	}

	public static function robots( array $robots ) {
		if ( ( self::$current && ! self::$current['indexable'] ) || ( ! self::$current && SNSCS_Hub::page_id( 'similar' ) && is_page( SNSCS_Hub::page_id( 'similar' ) ) ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	public static function seo_title( $title ) {
		return self::$current ? self::title( self::$current ) . ' | ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) : $title;
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

	private static function breadcrumbs( array $page ) {
		$crumbs = array( array( home_url( '/' ), 'トップ' ) );
		if ( $page['genre'] && SNSCS_Hub::page_id( 'features' ) ) {
			$crumbs[] = array( SNSCS_Features::url( '', $page['genre']->slug ), $page['genre']->name . 'の発信者' );
		}
		$crumbs[] = array( get_permalink( $page['creator'] ), $page['name'] );
		$crumbs[] = array( $page['url'], '似ている発信者' );
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
		foreach ( $page['items'] as $i => $item ) {
			$list[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'url' => $item['url'], 'name' => $item['name'] );
		}
		$crumbs = array();
		foreach ( self::breadcrumbs( $page ) as $i => $c ) {
			$crumbs[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[1], 'item' => $c[0] );
		}
		$graph = array( array( '@type' => 'BreadcrumbList', 'itemListElement' => $crumbs ) );
		if ( $list ) {
			$graph[] = array( '@type' => 'ItemList', 'name' => self::title( $page ), 'itemListElement' => $list );
		}
		echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}

	public static function shortcode() {
		SNSCS_Hub::remember_page( 'similar' );
		wp_enqueue_style( 'snscs' );
		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		if ( ! self::$current ) {
			echo '<p>発信者のページの「似ている発信者をもっと見る」から、その人に似ている発信者を探せます。</p><p><a class="snscs-button snscs-button--primary" href="' . esc_url( snscs_search_url() ) . '">発信者を探す</a></p>';
			return ob_get_clean();
		}
		$page    = self::$current;
		$creator = $page['creator'];
		$notes   = array();
		foreach ( $page['similar'] as $id => $s ) {
			if ( $s['reasons'] ) {
				$notes[ $id ] = '似ている点: ' . implode( '・', $s['reasons'] );
			}
		}
		?>
		<nav class="snscs-breadcrumbs" aria-label="パンくずリスト">
			<ol>
				<?php foreach ( self::breadcrumbs( $page ) as $crumb ) : ?>
					<li><a href="<?php echo esc_url( $crumb[0] ); ?>"><?php echo esc_html( $crumb[1] ); ?></a></li>
				<?php endforeach; ?>
			</ol>
		</nav>
		<p class="snscs-feature__lead"><?php echo esc_html( self::description( $page ) ); ?></p>
		<p class="snscs-similar__base"><a class="snscs-button" href="<?php echo esc_url( get_permalink( $creator ) ); ?>"><?php echo esc_html( $page['name'] . 'さんのページへ' ); ?></a></p>
		<h2 class="snscs-trendpage__title"><?php echo esc_html( $page['name'] . 'さんに似ている発信者' ); ?></h2>
		<?php if ( $page['items'] ) : ?>
			<?php SNSCS_Frontend::render_cards( $page['items'], 0, array(), $notes ); ?>
		<?php else : ?>
			<p class="snscs-results__empty">まだ似ている発信者が見つかっていません。</p>
		<?php endif; ?>
		<?php
		if ( $page['genre'] ) {
			echo '<p class="snscs-feature__more"><a class="snscs-button snscs-button--primary" href="' . esc_url( SNSCS_Features::url( '', $page['genre']->slug ) ) . '">' . esc_html( $page['genre']->name . 'の発信者をもっと見る' ) . '</a></p>';
		}
		// 似ている人の「似ている発信者」へも回れるように。
		$more = array();
		foreach ( array_slice( $page['items'], 0, 6 ) as $item ) {
			$u = self::url( $item['id'] );
			if ( $u ) {
				$more[] = '<li><a class="snscs-chip" href="' . esc_url( $u ) . '">' . esc_html( $item['name'] . 'さんに似ている人' ) . '</a></li>';
			}
		}
		if ( $more ) {
			echo '<h2 class="snscs-trendpage__title">ほかの発信者に似ている人</h2><ul class="snscs-chips">' . implode( '', $more ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		}
		echo SNSCS_Ads::render( 'search_bottom' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 広告枠のHTML。
		return ob_get_clean();
	}

	/**
	 * 発信者ページの「似ている発信者」の下に出すリンク。
	 */
	public static function link_html( $creator_id ) {
		$url = self::url( $creator_id );
		if ( ! $url ) {
			return '';
		}
		return '<p class="snscs-similar__more"><a href="' . esc_url( $url ) . '">' . esc_html( html_entity_decode( get_the_title( $creator_id ), ENT_QUOTES, 'UTF-8' ) . 'さんに似ている発信者をもっと見る →' ) . '</a></p>';
	}

	/* --------------------------------------------------------------------
	 * サイトマップ（ジャンルのある掲載中の発信者。ジャンルの人数が足りない人は除く）
	 * ------------------------------------------------------------------ */

	public static function register_sitemap() {
		if ( ! function_exists( 'wp_register_sitemap_provider' ) || ! class_exists( 'WP_Sitemaps_Provider' ) || ! SNSCS_Hub::page_id( 'similar' ) ) {
			return;
		}
		require_once SNSCS_DIR . 'includes/class-snscs-similar-sitemap.php';
		wp_register_sitemap_provider( 'snscssimilar', new SNSCS_Similar_Sitemap() );
	}

	public static function sitemap_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s AND tt.count > %d WHERE p.post_type = %s AND p.post_status = 'publish'", SNSCS_Post_Types::TAX_GENRE, self::MIN, SNSCS_Post_Types::POST_TYPE ) );
	}

	/**
	 * @return int[] 載せる発信者ID（総合点の高い順）
	 */
	public static function sitemap_ids( $offset, $limit ) {
		global $wpdb;
		$penalized = array_keys(
			array_filter(
				SNSCS_Integrity::penalties(),
				function ( $p ) {
					return $p < 1;
				}
			)
		);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
					INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s AND tt.count > %d
					LEFT JOIN " . SNSCS_Scores::table() . " st ON st.creator_id = p.ID
					WHERE p.post_type = %s AND p.post_status = 'publish'" . ( $penalized ? ' AND p.ID NOT IN (' . implode( ',', array_map( 'intval', $penalized ) ) . ')' : '' ) . '
					GROUP BY p.ID ORDER BY MAX(COALESCE(st.score, 0)) DESC, p.ID ASC LIMIT %d, %d',
					SNSCS_Post_Types::TAX_GENRE,
					self::MIN,
					SNSCS_Post_Types::POST_TYPE,
					$offset,
					$limit
				)
			)
		);
		// phpcs:enable
	}
}
