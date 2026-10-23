<?php
/**
 * 「似ている発信者」のページを WordPress 本体のサイトマップ（/wp-sitemap.xml）に載せる。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Similar_Sitemap extends WP_Sitemaps_Provider {

	const PER_PAGE = 2000;

	public function __construct() {
		$this->name        = 'snscssimilar';
		$this->object_type = 'snscssimilar';
	}

	public function get_url_list( $page_num, $object_subtype = '' ) {
		$out = array();
		foreach ( SNSCS_Similar::sitemap_ids( ( max( 1, (int) $page_num ) - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $id ) {
			$url = SNSCS_Similar::url( $id );
			if ( $url ) {
				$out[] = array( 'loc' => $url );
			}
		}
		return $out;
	}

	public function get_max_num_pages( $object_subtype = '' ) {
		return max( 1, (int) ceil( SNSCS_Similar::sitemap_count() / self::PER_PAGE ) );
	}
}
