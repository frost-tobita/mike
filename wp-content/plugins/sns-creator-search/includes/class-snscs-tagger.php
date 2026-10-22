<?php
/**
 * AI による自動タグ付け。
 *
 * 発信者の名前・ジャンル・紹介文・記事の題名を Claude に読ませ、「時短レシピ」「キャンプ飯」のような
 * 細かいタグを付ける。タグは検索の索引（項目「タグ」）に入り、言葉で探したときに見つかりやすくなる。
 * まとめて依頼する方式（料金半額）で、1日に決めた人数まで。紹介文が変わった人は付け直す。
 * 設定は「検索アルゴリズム」の画面。APIキーは記事の自動投稿と同じもの。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Tagger {

	const META      = '_snscs_ai_tags';
	const META_HASH = '_snscs_ai_tags_hash';
	const STATE     = 'snscs_tagger_state';

	public static function init() {
		add_action( 'snscs_hourly_engine', array( __CLASS__, 'collect' ) );
		add_action( 'snscs_nightly_engine', array( __CLASS__, 'order' ), 30 );
	}

	/**
	 * タグ付けに使う文章（これが変わったら付け直す）。
	 */
	public static function source( $creator_id ) {
		$genres = wp_get_object_terms( $creator_id, SNSCS_Post_Types::TAX_GENRE, array( 'fields' => 'names' ) );
		return implode(
			"\n",
			array(
				'名前: ' . get_the_title( $creator_id ),
				'ジャンル: ' . ( is_wp_error( $genres ) ? '' : implode( '、', $genres ) ),
				'SNS: ' . implode( '、', array_unique( array_map( 'snscs_platform_label', wp_list_pluck( SNSCS_Accounts::get_for_creator( $creator_id ), 'platform' ) ) ) ),
				'紹介文: ' . mb_substr( wp_strip_all_tags( get_post_field( 'post_content', $creator_id ) ), 0, 1200 ),
				'記事: ' . implode( ' / ', wp_list_pluck( SNSCS_Articles::for_creator( $creator_id, 'publish', 10 ), 'post_title' ) ),
			)
		);
	}

	public static function params( $creator_id ) {
		return array(
			'model'         => 'claude-opus-5-5',
			'max_tokens'    => 2000,
			'system'        => 'あなたはSNS発信者の検索サイトの編集者です。発信者の情報を読み、検索で使われそうな日本語のタグを5〜10個付けてください。タグは2〜12文字の名詞句で、具体的な発信テーマ（例: 時短レシピ、キャンプ飯、韓国コスメ、中学数学、保護猫）を中心にし、情報に書かれていないことは推測で足さないでください。人名・SNS名・「おすすめ」のような評価の言葉は入れないでください。',
			'output_config' => array(
				'effort' => 'low',
				'format' => array(
					'type'   => 'json_schema',
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'tags' ),
						'properties'           => array( 'tags' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ),
					),
				),
			),
			'messages'      => array( array( 'role' => 'user', 'content' => self::source( $creator_id ) ) ),
		);
	}

	/**
	 * まだタグが無い（または紹介文が変わった）発信者を、1日の上限まで依頼する（毎晩の処理から呼ぶ）。
	 *
	 * @return int 依頼した人数。
	 */
	public static function order() {
		if ( ! SNSCS_Learner::setting( 'ai_tags' ) || ! SNSCS_Claude::has_key() ) {
			return 0;
		}
		$state = (array) get_option( self::STATE, array() );
		if ( ! empty( $state['batch'] ) ) {
			return 0;
		}
		$limit = max( 1, (int) SNSCS_Learner::setting( 'ai_tags_per_day' ) );
		// まだタグの無い人を先に、次に最近更新された人（紹介文が変わっていれば付け直す）。
		$untagged = get_posts( array( 'post_type' => SNSCS_Post_Types::POST_TYPE, 'posts_per_page' => $limit, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => array( array( 'key' => self::META_HASH, 'compare' => 'NOT EXISTS' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$recent   = get_posts( array( 'post_type' => SNSCS_Post_Types::POST_TYPE, 'posts_per_page' => $limit, 'orderby' => 'modified', 'order' => 'DESC', 'fields' => 'ids', 'no_found_rows' => true ) );
		$ids      = array_unique( array_merge( $untagged, $recent ) );
		$items = array();
		foreach ( $ids as $id ) {
			$hash = md5( self::source( $id ) );
			if ( get_post_meta( $id, self::META_HASH, true ) !== $hash ) {
				$items[ 'tag-' . $id ] = $hash;
			}
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		if ( ! $items ) {
			return 0;
		}
		$requests = array();
		foreach ( array_keys( $items ) as $cid ) {
			$requests[] = array( 'custom_id' => $cid, 'params' => self::params( (int) substr( $cid, 4 ) ) );
		}
		$batch = SNSCS_Claude::request( 'POST', '/v1/messages/batches', array( 'requests' => $requests ) );
		if ( is_wp_error( $batch ) || empty( $batch['id'] ) ) {
			update_option( self::STATE, array( 'error' => is_wp_error( $batch ) ? $batch->get_error_message() : '不明な応答', 'at' => current_time( 'mysql' ) ), false );
			return 0;
		}
		update_option( self::STATE, array( 'batch' => $batch['id'], 'items' => $items, 'created' => time(), 'at' => current_time( 'mysql' ) ), false );
		return count( $items );
	}

	/**
	 * 仕上がったタグを受け取り、索引に入れ直す（1時間ごと）。
	 *
	 * @return int タグを付けた人数。
	 */
	public static function collect() {
		$state = (array) get_option( self::STATE, array() );
		if ( empty( $state['batch'] ) ) {
			return 0;
		}
		$info = SNSCS_Claude::request( 'GET', '/v1/messages/batches/' . rawurlencode( $state['batch'] ) );
		if ( is_wp_error( $info ) || 'ended' !== ( $info['processing_status'] ?? '' ) ) {
			if ( time() - (int) $state['created'] > 25 * HOUR_IN_SECONDS ) {
				delete_option( self::STATE );
			}
			return 0;
		}
		$rows = SNSCS_Claude::batch_results( $state['batch'] );
		if ( is_wp_error( $rows ) ) {
			return 0;
		}
		$done = 0;
		foreach ( $rows as $row ) {
			if ( ! isset( $state['items'][ $row['custom_id'] ] ) || 'succeeded' !== ( $row['result']['type'] ?? '' ) ) {
				continue;
			}
			$data = json_decode( SNSCS_Claude::text_of( $row['result']['message'] ), true );
			if ( ! is_array( $data ) || empty( $data['tags'] ) ) {
				continue;
			}
			$tags = array();
			foreach ( (array) $data['tags'] as $tag ) {
				$tag = mb_substr( trim( sanitize_text_field( $tag ) ), 0, 20 );
				if ( mb_strlen( $tag ) >= 2 ) {
					$tags[] = $tag;
				}
			}
			$creator_id = (int) substr( $row['custom_id'], 4 );
			update_post_meta( $creator_id, self::META, array_slice( array_values( array_unique( $tags ) ), 0, 10 ) );
			update_post_meta( $creator_id, self::META_HASH, $state['items'][ $row['custom_id'] ] );
			SNSCS_Index::index_creator( $creator_id );
			$done++;
		}
		update_option( self::STATE, array( 'done' => $done, 'at' => current_time( 'mysql' ) ), false );
		return $done;
	}

	public static function tags_for( $creator_id ) {
		return array_filter( (array) get_post_meta( $creator_id, self::META, true ) );
	}
}
