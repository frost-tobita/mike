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
			'recommended' => 'おすすめ順',
			'followers'   => 'フォロワーが多い順',
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
			'sort'          => array_key_exists( $sort, self::sort_options() ) ? $sort : 'recommended',
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
	 * @param array      $raw         入力値（未整形でよい）。exclude（除く発信者IDの配列）も渡せる。
	 * @param bool       $with_counts SNSごとの人数（インデックス用）も数えるか。
	 * @param array|null $personal    一人ひとりに合わせるとき: profile（SNSCS_Personalize::profile()）、strength（0〜1、省略時は設定値）。
	 *                                「おすすめ順」のときだけ使う。
	 * @return array{items:array,total:int,page:int,per_page:int,total_pages:int,args:array,counts:array,personalized:bool}
	 */
	public static function run( array $raw, $with_counts = false, $personal = null ) {
		$args            = self::normalize_args( $raw );
		$args['exclude'] = isset( $raw['exclude'] ) ? array_slice( array_values( array_filter( array_map( 'absint', (array) $raw['exclude'] ) ) ), 0, 200 ) : array();
		$args['personal_order'] = null;
		if ( $personal && ! empty( $personal['profile'] ) && 'recommended' === $args['sort'] ) {
			$strength               = isset( $personal['strength'] ) ? $personal['strength'] : SNSCS_Hub::setting( 'personal_strength' ) / 100;
			$args['personal_order'] = SNSCS_Personalize::order_expression( $personal['profile'], $strength );
		}
		$backend = self::backend();
		$result  = $backend->search( $args );
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
			'counts'      => $with_counts ? $backend->count_by_platform( $args ) : array(),
			'personalized' => ! empty( $args['personal_order'] ),
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

		return array(
			'id'        => (int) $post->ID,
			'name'      => get_the_title( $post ),
			'url'       => get_permalink( $post ),
			'excerpt'   => self::excerpt( $post ),
			'thumbnail' => get_the_post_thumbnail_url( $post, 'thumbnail' ) ? get_the_post_thumbnail_url( $post, 'thumbnail' ) : '',
			'genres'    => $term_names( SNSCS_Post_Types::TAX_GENRE ),
			'regions'   => $term_names( SNSCS_Post_Types::TAX_REGION ),
			'accounts'  => self::format_accounts( $accounts ),
		);
	}

	/**
	 * 紹介文の抜粋。get_the_excerpt() は本文のフィルター（the_content）を通すため、
	 * 発信者ページの中で他の発信者のカードを作ると処理が呼び合って終わらなくなる。そのため本文から直接作る。
	 */
	public static function excerpt( WP_Post $post ) {
		$text = '' !== $post->post_excerpt ? $post->post_excerpt : $post->post_content;
		return wp_trim_words( wp_strip_all_tags( strip_shortcodes( $text ) ), 120, '…' );
	}

	/**
	 * アカウントの表示用データを作る（対応していないSNSは除く）。
	 *
	 * @param array $accounts SNSCS_Accounts の行。
	 * @return array
	 */
	public static function format_accounts( array $accounts ) {
		$formatted = array();
		foreach ( $accounts as $account ) {
			if ( ! array_key_exists( $account['platform'], snscs_platforms() ) ) {
				continue;
			}
			$formatted[] = array(
				'platform'        => $account['platform'],
				'platform_label'  => snscs_platform_label( $account['platform'] ),
				'handle'          => $account['handle'],
				'url'             => $account['url'] ? $account['url'] : snscs_profile_url( $account['platform'], $account['handle'] ),
				'followers'       => (int) $account['followers'],
				'followers_label' => snscs_format_count( $account['followers'] ),
			);
		}

		return $formatted;
	}
}
