<?php
/**
 * Anthropic API（Claude）の呼び出し。記事の自動投稿（SNSCS_Autopost）と自動タグ付け（SNSCS_Tagger）で使う。
 *
 * WordPress を置くサーバーの多くでは Anthropic の PHP SDK（PHP 8.1 以上・大きなライブラリ）が使えないため、
 * WordPress 標準の通信機能で API を直接呼ぶ。APIキーは記事の自動投稿の設定（または wp-config.php）のものを使う。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Claude {

	const API_VERSION = '2026-10-21';

	public static function base_url() {
		return untrailingslashit( defined( 'SNSCS_ANTHROPIC_BASE_URL' ) ? SNSCS_ANTHROPIC_BASE_URL : 'https://api.anthropic.com' );
	}

	public static function has_key() {
		return '' !== SNSCS_Autopost::api_key();
	}

	/**
	 * @return array|WP_Error JSON（JSON Lines の結果は 'raw' に文字列のまま）。
	 */
	public static function request( $method, $path, $body = null, array $extra_headers = array(), $timeout = 60 ) {
		$key = SNSCS_Autopost::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'snscs_no_key', 'APIキーが設定されていません。' );
		}
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array_merge(
				array(
					'x-api-key'         => $key,
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				),
				$extra_headers
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( self::base_url() . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			$data = json_decode( $raw, true );
			$msg  = isset( $data['error']['message'] ) ? $data['error']['message'] : substr( $raw, 0, 200 );
			return new WP_Error( 'snscs_api', sprintf( 'APIエラー（%d）: %s', $code, $msg ) );
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array( 'raw' => $raw );
	}

	/**
	 * まとめての依頼の結果（JSON Lines）を1件ずつ読む。日本語を壊さないよう、改行の文字だけで区切る。
	 *
	 * @return array<int,array> 結果の行。
	 */
	public static function batch_results( $batch_id ) {
		$results = self::request( 'GET', '/v1/messages/batches/' . rawurlencode( $batch_id ) . '/results', null, array(), 120 );
		if ( is_wp_error( $results ) ) {
			return $results;
		}
		$raw  = isset( $results['raw'] ) ? $results['raw'] : wp_json_encode( $results );
		$rows = array();
		foreach ( explode( "\n", trim( $raw ) ) as $line ) {
			$row = json_decode( trim( $line ), true );
			if ( is_array( $row ) && ! empty( $row['custom_id'] ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * 返事の本文（text のブロックをつなげたもの）。
	 */
	public static function text_of( array $message ) {
		$text = '';
		foreach ( (array) ( $message['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= $block['text'];
			}
		}
		return $text;
	}
}
