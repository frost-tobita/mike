<?php
/**
 * 「発信者」の投稿タイプと、ジャンル・地域の分類を登録する。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Post_Types {

	const POST_TYPE = 'snscs_creator';
	const TAX_GENRE = 'snscs_genre';
	const TAX_REGION = 'snscs_region';

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => '発信者',
					'singular_name' => '発信者',
					'add_new'       => '新規追加',
					'add_new_item'  => '発信者を追加',
					'edit_item'     => '発信者を編集',
					'search_items'  => '発信者を検索',
					'not_found'     => '発信者が見つかりません',
					'all_items'     => '発信者一覧',
				),
				'public'       => true,
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => 'creators' ),
				'menu_icon'    => 'dashicons-groups',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'show_in_rest' => true,
			)
		);

		register_taxonomy(
			self::TAX_GENRE,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => 'ジャンル',
					'singular_name' => 'ジャンル',
					'add_new_item'  => 'ジャンルを追加',
				),
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'creator-genre' ),
			)
		);

		register_taxonomy(
			self::TAX_REGION,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => '地域',
					'singular_name' => '地域',
					'add_new_item'  => '地域を追加',
				),
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'creator-region' ),
			)
		);
	}

	/**
	 * 新しいサイトで、ジャンルと地域がまだ1つも無ければ、よく使うものを入れる（あとから管理画面で自由に変えられる）。
	 * ジャンルが無いと発信者が登録の申請をできないため。
	 */
	public static function install_default_terms() {
		$defaults = array(
			self::TAX_GENRE  => array( '料理', '美容', 'ファッション', '旅行', '子育て', 'ゲーム', '音楽', 'スポーツ', 'ビジネス', 'テクノロジー' ),
			self::TAX_REGION => array( '北海道', '東北', '関東', '中部', '近畿', '中国', '四国', '九州・沖縄' ),
		);
		foreach ( $defaults as $taxonomy => $names ) {
			if ( ! taxonomy_exists( $taxonomy ) || wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ) ) {
				continue;
			}
			foreach ( $names as $name ) {
				wp_insert_term( $name, $taxonomy );
			}
		}
	}
}
