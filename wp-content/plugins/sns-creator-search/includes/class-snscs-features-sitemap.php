<?php
/**
 * 特集ページを WordPress 本体のサイトマップ（/wp-sitemap.xml）に載せる。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Features_Sitemap extends WP_Sitemaps_Provider {

	const PER_PAGE = 2000;

	public function __construct() {
		$this->name        = 'snscsfeatures';
		$this->object_type = 'snscsfeatures';
	}

	public function get_url_list( $page_num, $object_subtype = '' ) {
		$urls = array_slice( SNSCS_Features::sitemap_urls(), ( max( 1, (int) $page_num ) - 1 ) * self::PER_PAGE, self::PER_PAGE );
		return array_map(
			function ( $url ) {
				return array( 'loc' => $url );
			},
			$urls
		);
	}

	public function get_max_num_pages( $object_subtype = '' ) {
		return max( 1, (int) ceil( count( SNSCS_Features::sitemap_urls() ) / self::PER_PAGE ) );
	}
}
