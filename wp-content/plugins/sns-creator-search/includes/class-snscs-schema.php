<?php
/**
 * 検索エンジン向けの構造化データ（JSON-LD）。
 *
 *   発信者ページ: ProfilePage（主役は Person）
 *     name・説明・写真・URL・sameAs（各SNSと、その他のリンク）・knowsAbout（ジャンル）・homeLocation（地域）、
 *     このサイトでのフォロワー数（FollowAction）と記事の数（WriteAction）、作成日・更新日、パンくず
 *   発信者の記事・コラム: Article（見出し・説明・画像・公開日・更新日・書き手・発行者・いいねの数）
 *   トップページ: Organization（サイト名・ロゴ・公式SNS）
 * Yoast・Rank Math・AIOSEO があるときは、それらが出す Article と Organization は出さない（重複しないように）。
 * ProfilePage は SEO プラグインが出さないので、いつも出す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Schema {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output' ), 4 );
	}

	private static function seo_plugin() {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' );
	}

	private static function site_name() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	private static function logo() {
		$url = get_site_icon_url( 512 );
		return $url ? array( '@type' => 'ImageObject', 'url' => $url ) : null;
	}

	public static function output() {
		$data = null;
		if ( is_singular( SNSCS_Post_Types::POST_TYPE ) ) {
			$data = self::profile( get_queried_object_id() );
		} elseif ( is_singular( SNSCS_Articles::POST_TYPE ) || ( is_singular( 'post' ) && SNSCS_Columns::is_column( get_queried_object_id() ) ) ) {
			$data = self::seo_plugin() ? null : self::article( get_queried_object_id() );
		} elseif ( is_front_page() && ! self::seo_plugin() ) {
			$data = self::organization();
		}
		if ( $data ) {
			echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
		}
	}

	/**
	 * 発信者ページ（ProfilePage + Person + パンくず）。
	 */
	public static function profile( $creator_id ) {
		$post = get_post( $creator_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}
		$url      = get_permalink( $post );
		$accounts = SNSCS_Search::format_accounts( SNSCS_Accounts::get_for_creator( $creator_id ) );
		$same     = wp_list_pluck( $accounts, 'url' );
		foreach ( SNSCS_Submissions::links_for( $creator_id ) as $link ) {
			if ( ! empty( $link['url'] ) ) {
				$same[] = $link['url'];
			}
		}
		$genres  = get_the_terms( $creator_id, SNSCS_Post_Types::TAX_GENRE );
		$regions = get_the_terms( $creator_id, SNSCS_Post_Types::TAX_REGION );
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$followers = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SNSCS_Social::follows_table() . ' WHERE creator_id = %d', $creator_id ) );
		$articles  = count( SNSCS_Articles::for_creator( $creator_id, 'publish', 100 ) );

		$person = array_filter(
			array(
				'@type'                      => 'Person',
				'@id'                        => $url . '#person',
				'name'                       => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'alternateName'              => array_values( array_unique( array_map( function ( $a ) { return '@' . ltrim( $a['handle'], '@' ); }, $accounts ) ) ),
				'description'                => SNSCS_Search::excerpt( $post ),
				'image'                      => get_the_post_thumbnail_url( $post, 'large' ) ?: null, // phpcs:ignore WordPress.PHP.DisallowShortTernary.Found
				'url'                        => $url,
				'sameAs'                     => array_values( array_unique( array_filter( $same ) ) ),
				'knowsAbout'                 => ( $genres && ! is_wp_error( $genres ) ) ? wp_list_pluck( $genres, 'name' ) : null,
				'homeLocation'               => ( $regions && ! is_wp_error( $regions ) ) ? array( '@type' => 'Place', 'name' => $regions[0]->name ) : null,
				'interactionStatistic'       => array( '@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/FollowAction', 'userInteractionCount' => $followers ),
				'agentInteractionStatistic'  => $articles ? array( '@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/WriteAction', 'userInteractionCount' => $articles ) : null,
			),
			function ( $v ) {
				return null !== $v && array() !== $v && '' !== $v;
			}
		);

		$crumbs = array( array( home_url( '/' ), 'トップ' ) );
		if ( $genres && ! is_wp_error( $genres ) && SNSCS_Hub::page_id( 'features' ) ) {
			$crumbs[] = array( SNSCS_Features::url( '', $genres[0]->slug ), $genres[0]->name . 'の発信者' );
		}
		$crumbs[] = array( $url, $person['name'] );
		$list     = array();
		foreach ( $crumbs as $i => $c ) {
			$list[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[1], 'item' => $c[0] );
		}

		return array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				array(
					'@type'        => 'ProfilePage',
					'@id'          => $url,
					'url'          => $url,
					'name'         => $person['name'] . 'のプロフィール',
					'dateCreated'  => get_post_time( 'c', true, $post ),
					'dateModified' => get_post_modified_time( 'c', true, $post ),
					'mainEntity'   => array( '@id' => $person['@id'] ),
					'isPartOf'     => array( '@type' => 'WebSite', 'name' => self::site_name(), 'url' => home_url( '/' ) ),
				),
				$person,
				array( '@type' => 'BreadcrumbList', 'itemListElement' => $list ),
			),
		);
	}

	/**
	 * 発信者の記事・コラム（Article）。
	 */
	public static function article( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}
		$creator = SNSCS_Articles::POST_TYPE === $post->post_type ? SNSCS_Articles::creator_of( $post_id ) : 0;
		$author  = $creator
			? array( '@type' => 'Person', 'name' => html_entity_decode( get_the_title( $creator ), ENT_QUOTES, 'UTF-8' ), 'url' => get_permalink( $creator ) )
			: array( '@type' => 'Organization', 'name' => self::site_name(), 'url' => home_url( '/' ) );
		$data    = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'Article',
			'headline'         => mb_substr( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ), 0, 110 ),
			'description'      => wp_trim_words( wp_strip_all_tags( $post->post_excerpt ? $post->post_excerpt : $post->post_content ), 80, '…' ),
			'datePublished'    => get_post_time( 'c', true, $post ),
			'dateModified'     => get_post_modified_time( 'c', true, $post ),
			'mainEntityOfPage' => get_permalink( $post ),
			'author'           => $author,
			'publisher'        => array_filter( array( '@type' => 'Organization', 'name' => self::site_name(), 'logo' => self::logo() ) ),
		);
		$image = get_the_post_thumbnail_url( $post, 'large' );
		if ( $image ) {
			$data['image'] = array( $image );
		}
		if ( SNSCS_Articles::POST_TYPE === $post->post_type ) {
			$counts                       = SNSCS_Social::counts( $post_id );
			$data['interactionStatistic'] = array( '@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/LikeAction', 'userInteractionCount' => (int) $counts[ SNSCS_Social::LIKE ] );
		}
		return $data;
	}

	/**
	 * トップページ（Organization）。公式SNS（SNSCS_Official）があれば sameAs に。
	 */
	public static function organization() {
		$same = class_exists( 'SNSCS_Official' ) ? SNSCS_Official::profile_urls() : array();
		return array_filter(
			array(
				'@context' => 'https://schema.org',
				'@type'    => 'Organization',
				'name'     => self::site_name(),
				'url'      => home_url( '/' ),
				'logo'     => self::logo(),
				'sameAs'   => $same ? $same : null,
			)
		);
	}
}
