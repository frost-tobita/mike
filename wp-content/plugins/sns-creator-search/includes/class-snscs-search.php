<?php
/**
 * 検索の窓口。入力を整え、検索エンジン部分に問い合わせ、表示用データを組み立てる。
 * 画面（ショートコード）と API の両方がここを使う。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Search {

	const MAX_PER_PAGE = 100;

	/**
	 * @return string[] 並び順の選択肢（キー => 表示名）。
	 */
	public static function sort_options() {
		return array(
			'followers' => 'フォロワーが多い順',
			'newest'    => '新着順',
			'name'      => '名前順',
		);
	}

	/**
	 * 検索条件を安全な形に整える。
	 *
	 * @param array $raw 入力値。
	 * @return array
	 */
	public static function normalize_args( array $raw ) {
		$get = function ( $key ) use ( $raw ) {
			return isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? (string) $raw[ $key ] : '';
		};

		$platform = sanitize_key( $get( 'platform' ) );
		$sort     = sanitize_key( $get( 'sort' ) );
		$per_page = absint( $get( 'per_page' ) );

		// 選択肢の「すべて」は "0" で送られてくるので、空（指定なし）として扱う。
		$term_slug = function ( $key ) use ( $get ) {
			$slug = sanitize_title( $get( $key ) );
			return '0' === $slug ? '' : $slug;
		};

		return array(
			'q'             => mb_substr( trim( sanitize_text_field( $get( 'q' ) ) ), 0, 100 ),
			'platform'      => array_key_exists( $platform, snscs_platforms() ) ? $platform : '',
			'genre'         => $term_slug( 'genre' ),
			'region'        => $term_slug( 'region' ),
			'min_followers' => absint( $get( 'min_followers' ) ),
			'max_followers' => absint( $get( 'max_followers' ) ),
			'sort'          => array_key_exists( $sort, self::sort_options() ) ? $sort : 'followers',
			'page'          => max( 1, absint( $get( 'page' ) ) ),
			'per_page'      => $per_page > 0 ? min( $per_page, self::MAX_PER_PAGE ) : 20,
		);
	}

	/**
	 * @return SNSCS_Search_Backend
	 */
	public static function backend() {
		$backend = apply_filters( 'snscs_search_backend', null );
		return $backend instanceof SNSCS_Search_Backend ? $backend : new SNSCS_MySQL_Backend();
	}

	/**
	 * 検索を実行する。
	 *
	 * @param array $raw 入力値（未整形でよい）。
	 * @return array{items:array,total:int,page:int,per_page:int,total_pages:int,args:array}
	 */
	public static function run( array $raw ) {
		$args   = self::normalize_args( $raw );
		$result = self::backend()->search( $args );
		$ids    = $result['ids'];

		_prime_post_caches( $ids );
		update_object_term_cache( $ids, SNSCS_Post_Types::POST_TYPE );
		$accounts = SNSCS_Accounts::get_for_creators( $ids );

		$items = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post ) {
				$items[] = self::format_creator( $post, isset( $accounts[ $id ] ) ? $accounts[ $id ] : array() );
			}
		}

		return array(
			'items'       => $items,
			'total'       => $result['total'],
			'page'        => $args['page'],
			'per_page'    => $args['per_page'],
			'total_pages' => (int) ceil( $result['total'] / $args['per_page'] ),
			'args'        => $args,
		);
	}

	/**
	 * 発信者1人分の表示用データを作る。
	 *
	 * @param WP_Post $post     発信者の投稿。
	 * @param array   $accounts その発信者のアカウント。
	 * @return array
	 */
	public static function format_creator( $post, array $accounts ) {
		$term_names = function ( $taxonomy ) use ( $post ) {
			$terms = get_the_terms( $post, $taxonomy );
			return ( $terms && ! is_wp_error( $terms ) ) ? wp_list_pluck( $terms, 'name' ) : array();
		};

		$formatted = array();
		foreach ( $accounts as $account ) {
			$formatted[] = array(
				'platform'        => $account['platform'],
				'platform_label'  => snscs_platform_label( $account['platform'] ),
				'handle'          => $account['handle'],
				'url'             => $account['url'],
				'followers'       => (int) $account['followers'],
				'followers_label' => snscs_format_count( $account['followers'] ),
			);
		}

		return array(
			'id'        => (int) $post->ID,
			'name'      => get_the_title( $post ),
			'url'       => get_permalink( $post ),
			'excerpt'   => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'thumbnail' => get_the_post_thumbnail_url( $post, 'thumbnail' ) ? get_the_post_thumbnail_url( $post, 'thumbnail' ) : '',
			'genres'    => $term_names( SNSCS_Post_Types::TAX_GENRE ),
			'regions'   => $term_names( SNSCS_Post_Types::TAX_REGION ),
			'accounts'  => $formatted,
		);
	}
}
