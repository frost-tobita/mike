<?php
/**
 * WP-CLI コマンド（サーバー上で一括処理するための命令）。
 *
 *   wp snscs import creators.csv     CSVから発信者を一括登録
 *   wp snscs seed --count=10000      動作・速度確認用のダミー発信者を作成
 *   wp snscs delete-dummy            ダミー発信者を削除
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_CLI {

	const DUMMY_META = '_snscs_dummy';

	/**
	 * CSVから発信者を一括登録する。
	 *
	 * 1行 = 1アカウント。同じ name の行は同じ発信者のアカウントとしてまとめる。
	 * 列: name,description,genres,regions,platform,handle,url,followers
	 * genres / regions は「|」区切りで複数指定でき、無い分類は自動で作る。
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : CSVファイルのパス（UTF-8、1行目は見出し）。
	 *
	 * ## EXAMPLES
	 *
	 *     wp snscs import creators.csv
	 *
	 * @param array $args 位置引数。
	 */
	public function import( $args ) {
		$file = $args[0];
		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "ファイルを読めません: {$file}" );
		}

		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$header = fgetcsv( $handle );
		if ( ! $header ) {
			WP_CLI::error( 'CSVが空です。' );
		}
		$header = array_map(
			function ( $col ) {
				return strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', $col ) ) );
			},
			$header
		);

		$creators = array();
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( count( $row ) !== count( $header ) ) {
				continue;
			}
			$row  = array_combine( $header, $row );
			$name = trim( $row['name'] );
			if ( '' === $name ) {
				continue;
			}
			if ( ! isset( $creators[ $name ] ) ) {
				$creators[ $name ] = array(
					'description' => isset( $row['description'] ) ? $row['description'] : '',
					'genres'      => isset( $row['genres'] ) ? $row['genres'] : '',
					'regions'     => isset( $row['regions'] ) ? $row['regions'] : '',
					'accounts'    => array(),
				);
			}
			$creators[ $name ]['accounts'][] = $row;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$created = 0;
		foreach ( $creators as $name => $data ) {
			$existing = get_posts(
				array(
					'post_type'   => SNSCS_Post_Types::POST_TYPE,
					'title'       => $name,
					'post_status' => 'any',
					'numberposts' => 1,
					'fields'      => 'ids',
				)
			);

			$post_id = $existing ? $existing[0] : wp_insert_post(
				array(
					'post_type'    => SNSCS_Post_Types::POST_TYPE,
					'post_title'   => $name,
					'post_content' => $data['description'],
					'post_status'  => 'publish',
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				WP_CLI::warning( "{$name}: " . $post_id->get_error_message() );
				continue;
			}

			$this->assign_terms( $post_id, $data['genres'], SNSCS_Post_Types::TAX_GENRE );
			$this->assign_terms( $post_id, $data['regions'], SNSCS_Post_Types::TAX_REGION );

			foreach ( SNSCS_Accounts::replace_for_creator( $post_id, $data['accounts'] ) as $label ) {
				WP_CLI::warning( "{$name}: 別の発信者に登録済みのためスキップ ({$label})" );
			}

			if ( ! $existing ) {
				$created++;
			}
		}

		WP_CLI::success( sprintf( '%d人を処理しました（新規 %d人）。', count( $creators ), $created ) );
	}

	/**
	 * 動作・速度確認用のダミー発信者を作る。
	 *
	 * ## OPTIONS
	 *
	 * [--count=<count>]
	 * : 作る人数。
	 * ---
	 * default: 100
	 * ---
	 *
	 * @param array $args       位置引数。
	 * @param array $assoc_args オプション。
	 */
	public function seed( $args, $assoc_args ) {
		$count     = max( 1, (int) $assoc_args['count'] );
		$genres    = array( '料理', '旅行', 'ファッション', '美容', 'ゲーム', 'テクノロジー', 'スポーツ', '音楽', 'ビジネス', '子育て' );
		$regions   = array( '北海道', '東北', '関東', '中部', '近畿', '中国', '四国', '九州・沖縄' );
		$platforms = array_keys( snscs_platforms() );

		wp_defer_term_counting( true );
		$progress = WP_CLI\Utils\make_progress_bar( 'ダミー発信者を作成中', $count );

		for ( $i = 1; $i <= $count; $i++ ) {
			$genre   = $genres[ array_rand( $genres ) ];
			$post_id = wp_insert_post(
				array(
					'post_type'    => SNSCS_Post_Types::POST_TYPE,
					'post_title'   => "ダミー発信者 {$i}",
					'post_content' => "{$genre}について発信しています。（テスト用データ）",
					'post_status'  => 'publish',
					'meta_input'   => array( self::DUMMY_META => 1 ),
				)
			);

			wp_set_object_terms( $post_id, $genre, SNSCS_Post_Types::TAX_GENRE );
			wp_set_object_terms( $post_id, $regions[ array_rand( $regions ) ], SNSCS_Post_Types::TAX_REGION );

			$accounts = array();
			foreach ( (array) array_rand( array_flip( $platforms ), wp_rand( 1, 3 ) ) as $platform ) {
				$accounts[] = array(
					'platform'  => $platform,
					'handle'    => "dummy_{$post_id}_{$platform}",
					// 少数の大物と多数の小規模発信者という、実際に近い偏りを作る。
					'followers' => (int) ( 10 ** ( wp_rand( 200, 700 ) / 100 ) ),
				);
			}
			SNSCS_Accounts::replace_for_creator( $post_id, $accounts );

			$progress->tick();
		}

		$progress->finish();
		wp_defer_term_counting( false );
		WP_CLI::success( "{$count}人のダミー発信者を作成しました。" );
	}

	/**
	 * seed で作ったダミー発信者をすべて削除する。
	 *
	 * @subcommand delete-dummy
	 */
	public function delete_dummy() {
		$deleted = 0;
		do {
			$ids = get_posts(
				array(
					'post_type'   => SNSCS_Post_Types::POST_TYPE,
					'post_status' => 'any',
					'meta_key'    => self::DUMMY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'numberposts' => 500,
					'fields'      => 'ids',
				)
			);
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
				$deleted++;
			}
		} while ( $ids );

		WP_CLI::success( "{$deleted}人のダミー発信者を削除しました。" );
	}

	/**
	 * 「|」区切りの分類名を、無ければ作って割り当てる。
	 */
	private function assign_terms( $post_id, $names, $taxonomy ) {
		$names = array_filter( array_map( 'trim', explode( '|', (string) $names ) ) );
		if ( $names ) {
			wp_set_object_terms( $post_id, array_values( $names ), $taxonomy );
		}
	}
}
