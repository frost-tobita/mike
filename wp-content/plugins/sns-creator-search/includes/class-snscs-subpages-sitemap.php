<?php
/**
 * 個別のページ（SNSCS_Subpages）を WordPress 本体のサイトマップに載せる。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Subpages_Sitemap extends WP_Sitemaps_Provider {

	const PER_PAGE = 2000;

	/** @var callable (offset, limit) => string[] */
	private $urls;

	/** @var callable () => int */
	private $count;

	public function __construct( $name, callable $urls, callable $count ) {
		$this->name        = $name;
		$this->object_type = $name;
		$this->urls        = $urls;
		$this->count       = $count;
	}

	public function get_url_list( $page_num, $object_subtype = '' ) {
		$urls = call_user_func( $this->urls, ( max( 1, (int) $page_num ) - 1 ) * self::PER_PAGE, self::PER_PAGE );
		return array_map(
			function ( $url ) {
				return array( 'loc' => $url );
			},
			array_filter( (array) $urls )
		);
	}

	public function get_max_num_pages( $object_subtype = '' ) {
		return max( 1, (int) ceil( call_user_func( $this->count ) / self::PER_PAGE ) );
	}
}
