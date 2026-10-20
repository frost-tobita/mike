<?php
/**
 * 検索API: /wp-json/snscs/v1/creators
 *
 * 将来スマホアプリや別の画面から検索するときに使う。
 * 例: /wp-json/snscs/v1/creators?q=料理&platform=instagram&min_followers=10000
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Rest {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'snscs/v1',
			'/creators',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'search' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'             => array( 'type' => 'string' ),
					'platform'      => array( 'type' => 'string', 'enum' => array_merge( array( '' ), array_keys( snscs_platforms() ) ) ),
					'genre'         => array( 'type' => 'string' ),
					'region'        => array( 'type' => 'string' ),
					'min_followers' => array( 'type' => 'integer', 'minimum' => 0 ),
					'max_followers' => array( 'type' => 'integer', 'minimum' => 0 ),
					'sort'          => array( 'type' => 'string', 'enum' => array_keys( SNSCS_Search::sort_options() ) ),
					'page'          => array( 'type' => 'integer', 'minimum' => 1 ),
					'per_page'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => SNSCS_Search::MAX_PER_PAGE ),
				),
			)
		);
	}

	public static function search( WP_REST_Request $request ) {
		$result = SNSCS_Search::run( $request->get_params() );

		$response = rest_ensure_response( $result['items'] );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $result['total_pages'] );

		return $response;
	}
}
