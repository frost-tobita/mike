<?php
/**
 * 発信者どうしのつながりから出す「信頼度」（PageRank / TrustRank の考え方）。
 *
 * つながり（矢印）:
 *   招待     : 招待された人 → 招待した人（重み 1.0）、招待した人 → 招待された人（0.3）
 *   一緒に見られている : 同じ日に同じ人に見られた組（SNSCS_Personalize の記録。強さに比例、1人あたり上位30件）
 * 計算: 「信頼の量」を矢印に沿って流すことを30回くり返す。15%は毎回「起点」へ戻す（テレポート）。
 *   起点 = 運営が「信頼できる発信者」に選んだ人（いなければ全員に均等）。
 *   信頼できる人の近くにいる人ほど高く、孤立した集団（なれ合いの水増しなど）は低くなる。
 * 毎晩1回計算し、結果は「つながりの信頼度」の点数になる。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Graph {

	const META      = '_snscs_trusted';
	const DAMPING   = 0.85;
	const ITERATION = 30;

	public static function init() {
		add_action( 'snscs_nightly_engine', array( __CLASS__, 'compute' ), 20 );
		add_action( 'add_meta_boxes_' . SNSCS_Post_Types::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . SNSCS_Post_Types::POST_TYPE, array( __CLASS__, 'save_meta' ) );
	}

	public static function add_meta_box() {
		add_meta_box( 'snscs-trusted', '信頼できる発信者', array( __CLASS__, 'render_meta_box' ), SNSCS_Post_Types::POST_TYPE, 'side' );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'snscs_trusted', 'snscs_trusted_nonce' );
		?>
		<label><input type="checkbox" name="snscs_trusted" value="1" <?php checked( (bool) get_post_meta( $post->ID, self::META, true ) ); ?>> 信頼の起点にする</label>
		<p class="description">本人確認ができていて、内容も確かな発信者に付けてください。この人とつながりの近い発信者ほど「つながりの信頼度」が高くなります（毎晩計算）。</p>
		<?php
	}

	public static function save_meta( $post_id ) {
		if ( ! isset( $_POST['snscs_trusted_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_trusted_nonce'] ) ), 'snscs_trusted' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! empty( $_POST['snscs_trusted'] ) ) {
			update_post_meta( $post_id, self::META, 1 );
		} else {
			delete_post_meta( $post_id, self::META );
		}
	}

	/**
	 * 信頼度を計算して保存する。
	 *
	 * @return int 計算した人数。
	 */
	public static function compute() {
		global $wpdb;
		$nodes = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", SNSCS_Post_Types::POST_TYPE ) ) );
		$n     = count( $nodes );
		if ( ! $n ) {
			return 0;
		}
		$exists = array_flip( $nodes );
		$out    = array();
		$add    = function ( $from, $to, $w ) use ( &$out, $exists ) {
			if ( $from !== $to && isset( $exists[ $from ], $exists[ $to ] ) && $w > 0 ) {
				$out[ $from ][ $to ] = ( isset( $out[ $from ][ $to ] ) ? $out[ $from ][ $to ] : 0 ) + $w;
			}
		};
		foreach ( SNSCS_Invites::parents() as $child => $parent ) {
			$add( $child, $parent, 1.0 );
			$add( $parent, $child, 0.3 );
		}
		$coviews = SNSCS_Personalize::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT a, b, weight FROM {$coviews} WHERE weight >= 0.2 ORDER BY a, weight DESC", ARRAY_A );
		$per  = array();
		foreach ( $rows as $row ) {
			$a = (int) $row['a'];
			$per[ $a ] = isset( $per[ $a ] ) ? $per[ $a ] + 1 : 1;
			if ( $per[ $a ] <= 30 ) {
				$add( $a, (int) $row['b'], (float) $row['weight'] );
			}
		}

		// 起点（テレポート先）。
		$seeds = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish' WHERE pm.meta_key = %s AND pm.meta_value = '1'", self::META ) ) );
		$tele  = array();
		if ( $seeds ) {
			foreach ( $seeds as $id ) {
				$tele[ $id ] = 1 / count( $seeds );
			}
		}
		$rank = array_fill_keys( $nodes, 1 / $n );
		$sums = array();
		foreach ( $out as $from => $targets ) {
			$sums[ $from ] = array_sum( $targets );
		}
		for ( $i = 0; $i < self::ITERATION; $i++ ) {
			$next     = array_fill_keys( $nodes, 0.0 );
			$dangling = 0.0;
			foreach ( $rank as $id => $value ) {
				if ( empty( $out[ $id ] ) ) {
					$dangling += $value;
					continue;
				}
				foreach ( $out[ $id ] as $to => $w ) {
					$next[ $to ] += self::DAMPING * $value * $w / $sums[ $id ];
				}
			}
			// 行き先の無い人の分と、テレポートの分を起点（いなければ全員）に配る。
			$spread = ( 1 - self::DAMPING ) + self::DAMPING * $dangling;
			if ( $tele ) {
				foreach ( $tele as $id => $share ) {
					$next[ $id ] += $spread * $share;
				}
			} else {
				foreach ( $nodes as $id ) {
					$next[ $id ] += $spread / $n;
				}
			}
			$rank = $next;
		}

		// 全員の平均を1とした値で保存する。
		$table  = SNSCS_Feedback::table();
		$values = array();
		foreach ( $rank as $id => $value ) {
			$values[] = $wpdb->prepare( '(%d, %f)', $id, $value * $n );
		}
		foreach ( array_chunk( $values, 1000 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "INSERT INTO {$table} (creator_id, authority) VALUES " . implode( ',', $chunk ) . ' ON DUPLICATE KEY UPDATE authority = VALUES(authority)' );
		}
		update_option( 'snscs_graph_info', array( 'nodes' => $n, 'edges' => array_sum( array_map( 'count', $out ) ), 'seeds' => count( $seeds ), 'at' => current_time( 'mysql' ) ), false );
		return $n;
	}
}
