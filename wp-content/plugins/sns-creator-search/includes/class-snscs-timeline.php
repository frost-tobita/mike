<?php
/**
 * トップページの「あなたへのおすすめ」（X・Yahoo!・note のように、下へ延々と続くタイムライン）。
 *
 * 1回分（1ページ）の並び: 記事2 → おすすめの発信者 → 記事2 → 広告 → 記事2 → FolloLink（または用語・質問）→ 記事2
 * - 記事は記事のおすすめ（SNSCS_Feed）の順。表示は記録して、学習に使う
 * - 最初の1回分はページに書き出し（JavaScript が無くても読める）、2回目からは下に近づいたら読み込む
 * - 出した記事・発信者は次から出さない（ブラウザから送ってもらう）。広告はランダム（広告枠「トップページ：コンテンツの間」）
 * - 記事が無くなっても、発信者・FolloLink・用語が続くかぎり続ける。最大30回分
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Timeline {

	const MAX_PAGES = 30;
	const ARTICLES  = 8;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'snscs/v1',
			'/timeline',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'page' => array( 'sanitize_callback' => 'absint' ),
					'xa'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'xc'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
				'callback'            => function ( WP_REST_Request $request ) {
					$ids = function ( $raw ) {
						return array_slice( array_values( array_filter( array_map( 'absint', explode( ',', (string) $raw ) ) ) ), 0, 400 );
					};
					$out      = self::build( max( 2, (int) $request->get_param( 'page' ) ), $ids( $request->get_param( 'xa' ) ), $ids( $request->get_param( 'xc' ) ) );
					$response = new WP_REST_Response( $out );
					$response->header( 'Cache-Control', 'no-store, max-age=0' );
					return $response;
				},
			)
		);
	}

	/**
	 * 1回分を作る。
	 *
	 * @param int   $page             何回目か（1から）。
	 * @param int[] $exclude_articles もう出した記事。
	 * @param int[] $exclude_creators もう出した発信者。
	 * @return array{html:string,articles:int[],creators:int[],more:bool}
	 */
	public static function build( $page, array $exclude_articles = array(), array $exclude_creators = array() ) {
		$page = max( 1, (int) $page );
		if ( $page > self::MAX_PAGES ) {
			return array( 'html' => '', 'articles' => array(), 'creators' => array(), 'more' => false );
		}

		// 記事（おすすめの順。もう出したものは除く）。
		$skip     = array_flip( $exclude_articles );
		$articles = array();
		foreach ( SNSCS_Feed::items( array( 'limit' => 80, 'log' => false ) ) as $item ) {
			if ( ! isset( $skip[ $item['id'] ] ) ) {
				$articles[] = $item;
			}
			if ( count( $articles ) >= self::ARTICLES ) {
				break;
			}
		}
		$chunks = array_chunk( $articles, 2 );

		// おすすめの発信者（3人）。
		$found    = SNSCS_Search::run( array( 'sort' => 'recommended', 'per_page' => 3, 'exclude' => $exclude_creators ) );
		$creators = $found['items'];
		if ( ! $creators ) {
			// 立ち上げたばかりで点数がまだ無いサイトでは、新しい順。
			$creators = SNSCS_Search::run( array( 'sort' => 'newest', 'per_page' => 3, 'exclude' => $exclude_creators ) )['items'];
		}

		$blocks = array();
		$take   = function () use ( &$chunks ) {
			$chunk = array_shift( $chunks );
			return $chunk ? self::block( 'articles', SNSCS_Feed::render( $chunk ) ) : '';
		};
		$blocks[] = $take();
		$blocks[] = $creators ? self::creators_block( $creators ) : '';
		$blocks[] = $take();
		$blocks[] = self::ad_block();
		$blocks[] = $take();
		$blocks[] = 1 === $page % 2 ? self::mylinks_block( $page ) : self::read_block( $page );
		$blocks[] = $take();
		if ( ! $articles ) {
			// 記事がまだ無いサイトでも、読みものを足して続ける。
			$blocks[] = 1 === $page % 2 ? self::read_block( $page ) : self::mylinks_block( $page );
		}
		$html = implode( '', array_filter( $blocks ) );

		return array(
			'html'     => $html,
			'articles' => wp_list_pluck( $articles, 'id' ),
			'creators' => wp_list_pluck( $creators, 'id' ),
			// 記事か発信者がまだ残っているあいだは続ける。
			'more'     => '' !== $html && ( $articles || $creators ) && $page < self::MAX_PAGES,
		);
	}

	private static function block( $kind, $inner ) {
		return '' === trim( $inner ) ? '' : '<div class="snscs-tl__block snscs-tl__block--' . esc_attr( $kind ) . '">' . $inner . '</div>';
	}

	private static function creators_block( array $creators ) {
		$cards = '';
		foreach ( $creators as $item ) {
			$main   = $item['accounts'] ? $item['accounts'][0] : null;
			$photo  = $item['thumbnail'] ? '<img src="' . esc_url( $item['thumbnail'] ) . '" alt="" width="56" height="56" loading="lazy">' : '<span class="snscs-pt-pick__initial" aria-hidden="true">' . esc_html( mb_substr( $item['name'], 0, 1 ) ) . '</span>';
			$cards .= '<a class="snscs-pt-pick" href="' . esc_url( $item['url'] ) . '">' . $photo . '<strong>' . esc_html( $item['name'] ) . '</strong><span class="snscs-pt-pick__genre">' . esc_html( implode( '・', $item['genres'] ) ) . '</span>' . ( $main ? '<span>' . esc_html( $main['platform_label'] . ' ' . $main['followers_label'] ) . '</span>' : '' ) . '</a>';
		}
		return self::block( 'creators', '<p class="snscs-tl__label">おすすめの発信者</p><div class="snscs-tl__picks">' . $cards . '</div>' );
	}

	private static function ad_block() {
		$ad = SNSCS_Ads::render( 'top_infeed', 'snscs-tl__ad' );
		return '' !== $ad ? '<div class="snscs-tl__block snscs-tl__block--ad">' . $ad . '</div>' : '';
	}

	private static function mylinks_block( $page ) {
		$ids = get_posts( array( 'post_type' => SNSCS_Post_Types::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 4, 'offset' => ( (int) ceil( $page / 2 ) - 1 ) * 4, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC' ) );
		if ( ! $ids ) {
			return '';
		}
		$items = '';
		foreach ( $ids as $id ) {
			$photo  = get_the_post_thumbnail_url( $id, 'thumbnail' );
			$name   = get_the_title( $id );
			$items .= '<a class="snscs-pt-mylink" href="' . esc_url( SNSCS_Biolink::url( $id ) ) . '">' . ( $photo ? '<img src="' . esc_url( $photo ) . '" alt="" width="52" height="52" loading="lazy">' : '<span class="snscs-pt-mylink__initial" aria-hidden="true">' . esc_html( mb_substr( $name, 0, 1 ) ) . '</span>' ) . '<span class="snscs-pt-mylink__name">' . esc_html( $name ) . '</span><span class="snscs-pt-mylink__id">@' . esc_html( SNSCS_Biolink::name_for( $id ) ) . '</span></a>';
		}
		$more = SNSCS_Hub::page_url( 'mylink' );
		return self::block( 'mylinks', '<p class="snscs-tl__label">新着のFolloLink</p><div class="snscs-pt-mylinks">' . $items . '</div>' . ( $more ? '<p class="snscs-pt-mylinks__cta"><a href="' . esc_url( $more ) . '">あなたもFolloLinkを作る（無料）</a></p>' : '' ) );
	}

	/**
	 * 読みもの（用語・質問を順番に）。
	 */
	private static function read_block( $page ) {
		$cards = '';
		$terms = SNSCS_Hub::page_id( 'glossary' ) ? SNSCS_Glossary::all() : array();
		if ( $terms ) {
			$t      = $terms[ ( $page * 7 + (int) floor( current_time( 'timestamp' ) / DAY_IN_SECONDS ) ) % count( $terms ) ];
			$cards .= '<a class="snscs-pt-read snscs-pt-read--term" href="' . esc_url( SNSCS_Glossary::url( $t['slug'] ) ) . '"><span class="snscs-pt-read__tag">SNS用語</span><strong>' . esc_html( $t['name'] ) . 'とは？</strong><span>' . esc_html( $t['short'] ) . '</span></a>';
		}
		if ( SNSCS_Hub::page_id( 'qa' ) ) {
			$qs = SNSCS_QA::list_questions( 'popular', '', 0, self::MAX_PAGES );
			$q  = $qs[ $page - 1 ] ?? null;
			if ( $q ) {
				$cards .= '<a class="snscs-pt-read snscs-pt-read--qa" href="' . esc_url( SNSCS_QA::url( (int) $q['id'] ) ) . '"><span class="snscs-pt-read__tag">質問箱</span><strong>' . esc_html( $q['title'] ) . '</strong><span>' . esc_html( (int) $q['answers'] ? '回答' . (int) $q['answers'] . '件' : 'まだ回答がありません' ) . '</span></a>';
			}
		}
		return '' !== $cards ? self::block( 'reads', '<div class="snscs-pt-reads">' . $cards . '</div>' ) : '';
	}

	/**
	 * トップページに置く部分（最初の1回分と、続きを読み込む仕組み）。
	 */
	public static function render() {
		$first = self::build( 1 );
		if ( '' === $first['html'] ) {
			return '';
		}
		wp_enqueue_script( 'snscs-timeline', SNSCS_URL . 'assets/js/snscs-timeline.js', array(), SNSCS_VERSION, true );
		wp_localize_script(
			'snscs-timeline',
			'snscsTimeline',
			array(
				'rest'  => esc_url_raw( rest_url( 'snscs/v1/timeline' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
		return '<section class="snscs-tl" aria-label="あなたへのおすすめ" data-articles="' . esc_attr( implode( ',', $first['articles'] ) ) . '" data-creators="' . esc_attr( implode( ',', $first['creators'] ) ) . '" data-more="' . ( $first['more'] ? '1' : '0' ) . '">'
			. '<h2 class="snscs-tl__title">あなたへのおすすめ</h2>'
			. '<div class="snscs-tl__list">' . $first['html'] . '</div>'
			. '<div class="snscs-tl__status" aria-live="polite"><span class="snscs-tl__spinner" hidden></span><button type="button" class="snscs-tl__more"' . ( $first['more'] ? '' : ' hidden' ) . '>もっと見る</button><p class="snscs-tl__end"' . ( $first['more'] ? ' hidden' : '' ) . '>ここまで読んでくれてありがとうございます。<a href="' . esc_url( snscs_search_url() ) . '">発信者を探す</a></p></div>'
			. '</section>';
	}
}
