<?php
/**
 * MySQL（WordPress 標準のデータベース）で検索する実装。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_MySQL_Backend implements SNSCS_Search_Backend {

	public function search( array $args ) {
		global $wpdb;

		$query = $this->build_from( $args );
		if ( null === $query ) {
			return array( 'ids' => array(), 'total' => 0 );
		}
		list( $from, $params ) = $query;

		switch ( $args['sort'] ) {
			case 'newest':
				$order = 'p.post_date DESC, p.ID DESC';
				break;
			case 'name':
				$order = 'p.post_title ASC, p.ID ASC';
				break;
			case 'followers':
				$order = 'top_followers DESC, p.ID DESC';
				break;
			default:
				// おすすめ順: 総合点（SNSCS_Scores）の高い順。
				// 一人ひとりの好みがあるときは、総合点と好みとの近さを合わせた点の高い順（SNSCS_Personalize）。
				$order = empty( $args['personal_order'] ) ? 'top_score DESC, total_followers DESC, p.ID DESC' : 'personal_rank DESC, top_score DESC, p.ID DESC';
		}
		$personal = empty( $args['personal_order'] ) ? '' : ', ' . $args['personal_order'] . ' AS personal_rank';

		$offset = ( $args['page'] - 1 ) * $args['per_page'];

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) {$from}", $params ) );

		$ids = array();
		if ( $total > $offset ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID, MAX(a.followers) AS top_followers, MAX(COALESCE(st.score, 0)) AS top_score, MAX(COALESCE(st.followers, 0)) AS total_followers{$personal} {$from} GROUP BY p.ID ORDER BY {$order} LIMIT %d, %d",
					array_merge( $params, array( $offset, $args['per_page'] ) )
				)
			);
		}
		// phpcs:enable

		return array(
			'ids'   => array_map( 'intval', $ids ),
			'total' => $total,
		);
	}

	/**
	 * 条件に合う人数。
	 */
	public function count( array $args ) {
		global $wpdb;
		$query = $this->build_from( $args );
		if ( null === $query ) {
			return 0;
		}
		list( $from, $params ) = $query;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) {$from}", $params ) );
	}

	/**
	 * 並べ替えの候補（SNSCS_Ranker 用）: 発信者ID => 総合点（好みがあれば好みと合わせた点）。
	 *
	 * @param array  $args  検索条件。
	 * @param int    $limit 最大人数（0 なら全員。キーワード検索の候補は最大2000人）。
	 * @param string $score 総合点の列（score、A/Bテストの B組は score_b）。
	 * @return array<int,float>
	 */
	public function rows( array $args, $limit, $score = 'score' ) {
		global $wpdb;
		$query = $this->build_from( $args );
		if ( null === $query ) {
			return array();
		}
		list( $from, $params ) = $query;
		$col   = 'score_b' === $score ? 'COALESCE(st.score_b, st.score, 0)' : 'COALESCE(st.score, 0)';
		$base  = empty( $args['personal_order'] ) ? "MAX({$col})" : $args['personal_order'];
		$sql   = "SELECT p.ID, {$base} AS base {$from} GROUP BY p.ID ORDER BY base DESC, p.ID DESC";
		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d';
			$params[] = (int) $limit;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['ID'] ] = (float) $row['base'];
		}
		return $out;
	}

	public function count_by_platform( array $args ) {
		global $wpdb;

		$counts = array_fill_keys( array_merge( array( '' ), array_keys( snscs_platforms() ) ), 0 );

		$query = $this->build_from( array_merge( $args, array( 'platform' => '' ) ) );
		if ( null === $query ) {
			return $counts;
		}
		list( $from, $params ) = $query;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$counts[''] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) {$from}", $params ) );
		$rows       = $wpdb->get_results( $wpdb->prepare( "SELECT a.platform, COUNT(DISTINCT p.ID) AS total {$from} GROUP BY a.platform", $params ), ARRAY_A );
		// phpcs:enable

		foreach ( $rows as $row ) {
			if ( isset( $counts[ $row['platform'] ] ) ) {
				$counts[ $row['platform'] ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * 絞り込みの件数（1.21）: ジャンル・地域ごとの人数（多い順）。その分類の絞り込みは外して数える。
	 *
	 * @return array<int,int> term_id => 人数
	 */
	public function count_by_term( array $args, $taxonomy, $limit = 12 ) {
		global $wpdb;
		$key   = SNSCS_Post_Types::TAX_GENRE === $taxonomy ? 'genre' : 'region';
		$query = $this->build_from( array_merge( $args, array( $key => '' ) ) );
		if ( null === $query ) {
			return array();
		}
		list( $from, $params ) = $query;
		// 最初の WHERE（いちばん外側）の前に、分類のつながりを足す。
		$from   = preg_replace( '/ WHERE /', " INNER JOIN {$wpdb->term_relationships} trf ON trf.object_id = p.ID INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = trf.term_taxonomy_id AND tt.taxonomy = %s WHERE ", $from, 1 );
		$params = array_merge( array( $taxonomy ), $params, array( (int) $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tt.term_id, COUNT(DISTINCT p.ID) AS total {$from} GROUP BY tt.term_id ORDER BY total DESC LIMIT %d", $params ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['term_id'] ] = (int) $row['total'];
		}
		return $out;
	}

	/**
	 * 検索条件から FROM 〜 WHERE 部分を組み立てる。
	 *
	 * @param array $args 検索条件。
	 * @return array{0:string,1:array}|null [SQL, 値]。該当なしが確定しているときは null。
	 */
	private function build_from( array $args ) {
		global $wpdb;

		$accounts = SNSCS_Accounts::table();
		$where    = array( 'p.post_type = %s', "p.post_status = 'publish'" );
		$params   = array( SNSCS_Post_Types::POST_TYPE );

		// 対応SNSから外したアカウントが残っていても、検索には出さない。
		$platforms = array_keys( snscs_platforms() );
		$where[]   = 'a.platform IN (' . implode( ',', array_fill( 0, count( $platforms ), '%s' ) ) . ')';
		$params    = array_merge( $params, $platforms );

		// SNSの種類とフォロワー数は「同じ1つのアカウント」が満たす条件として扱う。
		if ( '' !== $args['platform'] ) {
			$where[]  = 'a.platform = %s';
			$params[] = $args['platform'];
		}
		if ( $args['min_followers'] > 0 ) {
			$where[]  = 'a.followers >= %d';
			$params[] = $args['min_followers'];
		}
		if ( $args['max_followers'] > 0 ) {
			$where[]  = 'a.followers <= %d';
			$params[] = $args['max_followers'];
		}

		foreach ( array( 'genre' => SNSCS_Post_Types::TAX_GENRE, 'region' => SNSCS_Post_Types::TAX_REGION ) as $key => $taxonomy ) {
			if ( '' === $args[ $key ] ) {
				continue;
			}
			$tt_ids = $this->term_taxonomy_ids( $args[ $key ], $taxonomy );
			if ( empty( $tt_ids ) ) {
				return null;
			}
			$where[] = "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr WHERE tr.object_id = p.ID AND tr.term_taxonomy_id IN (" . implode( ',', $tt_ids ) . '))';
		}

		// 索引で集めた候補があれば、キーワードの条件はそれで置き換える（言い換え・表記ゆれ込み）。
		if ( isset( $args['candidate_ids'] ) ) {
			$ids = array_filter( array_map( 'intval', (array) $args['candidate_ids'] ) );
			if ( ! $ids ) {
				return null;
			}
			$where[] = 'p.ID IN (' . implode( ',', $ids ) . ')';
		}

		foreach ( isset( $args['candidate_ids'] ) ? array() : $this->keywords( $args['q'] ) as $word ) {
			$like     = '%' . $wpdb->esc_like( $word ) . '%';
			$where[]  = "(p.post_title LIKE %s OR p.post_excerpt LIKE %s OR p.post_content LIKE %s OR EXISTS (SELECT 1 FROM {$accounts} ah WHERE ah.creator_id = p.ID AND ah.handle LIKE %s))";
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $args['exclude'] ) ) {
			$where[] = 'p.ID NOT IN (' . implode( ',', array_map( 'intval', $args['exclude'] ) ) . ')';
		}

		$stats = SNSCS_Scores::table();
		$from  = "FROM {$wpdb->posts} p INNER JOIN {$accounts} a ON a.creator_id = p.ID LEFT JOIN {$stats} st ON st.creator_id = p.ID WHERE " . implode( ' AND ', $where );

		return array( $from, $params );
	}

	/**
	 * 分類のスラッグから、子の分類も含めた term_taxonomy_id を集める。
	 * 例: 「関東」を選ぶと「東京都」「神奈川県」に登録された発信者も対象になる。
	 *
	 * @param string $slug     分類のスラッグ。
	 * @param string $taxonomy 分類の種類。
	 * @return int[]
	 */
	private function term_taxonomy_ids( $slug, $taxonomy ) {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term ) {
			return array();
		}

		$ids = array( (int) $term->term_taxonomy_id );
		foreach ( (array) get_term_children( $term->term_id, $taxonomy ) as $child_id ) {
			$child = get_term( $child_id, $taxonomy );
			if ( $child && ! is_wp_error( $child ) ) {
				$ids[] = (int) $child->term_taxonomy_id;
			}
		}

		return $ids;
	}

	/**
	 * キーワードを空白（全角スペース含む）で区切る。最大5語。
	 *
	 * @param string $q 入力されたキーワード。
	 * @return string[]
	 */
	private function keywords( $q ) {
		$words = preg_split( '/[\s　]+/u', $q, -1, PREG_SPLIT_NO_EMPTY );
		return array_slice( (array) $words, 0, 5 );
	}
}
