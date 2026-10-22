<?php
/**
 * 「おすすめ順」の並べ替え（2段階）。
 *
 * 1段目（候補集め）: キーワードがあれば索引から関連度つきで最大2000人、無ければ総合点の高い順に数百人。
 *                    絞り込み（SNS・ジャンル・地域・フォロワー数）はデータベースで行う。
 * 2段目（並べ替え）: 最終点 = 関連度の割合 × 関連度（0〜100）＋（1 − 割合）× 総合点（好みがあれば好みと合わせた点）
 *                    関連度の割合は、検索の意図で変える（人の名前 → 85%以上、ジャンル・地域の名前 → 低め）。
 * 1ページ目だけ:
 *   多様さ   : 同じSNS・同じジャンルの人が続かないよう、少しずつ点を引きながら選ぶ
 *   お試し表示: まだ表示の少ない人を、決まった枠に出す（トンプソン・サンプリング。
 *              押された割合の「ありえる範囲」からくじを引き、可能性のある人に機会を与える）
 * 表示した結果は、検索ごとのランダムな番号（qid）と一緒に記録し、学習に使う（SNSCS_Feedback）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Ranker {

	const BROWSE_POOL = 400;
	const EXPLORE_MAX = 30;

	/**
	 * @param array                $args    SNSCS_Search::normalize_args() に candidate_ids などを足したもの。
	 * @param SNSCS_MySQL_Backend  $backend 検索エンジン部分。
	 * @param array<int,float>     $rel     発信者ID => 関連度（キーワードがあるとき）。
	 * @param array                $context bucket、intent、log（表示を記録するか）、has_q。
	 * @return array{ids:int[],total:int,qid:string,explore:int[]}
	 */
	public static function rank( array $args, SNSCS_MySQL_Backend $backend, array $rel, array $context ) {
		$per_page = (int) $args['per_page'];
		$page     = (int) $args['page'];
		$offset   = ( $page - 1 ) * $per_page;
		$score    = 'b' === $context['bucket'] ? 'score_b' : 'score';

		$total = $backend->count( $args );
		if ( ! $total ) {
			return array( 'ids' => array(), 'total' => 0, 'qid' => '', 'explore' => array() );
		}
		$limit = $rel ? 0 : max( self::BROWSE_POOL, $offset + $per_page * 2 );
		$rows  = $backend->rows( $args, $limit, $score );

		// 2段目: 最終点。
		$final = array();
		if ( $rel ) {
			$share = SNSCS_Learner::rel_share( $context['bucket'] );
			if ( 'name' === $context['intent'] ) {
				$share = max( $share, 0.85 );
			} elseif ( in_array( $context['intent'], array( 'genre', 'region' ), true ) ) {
				$share *= 0.6;
			}
			$max = max( 0.000001, max( array_intersect_key( $rel, $rows ) ?: array( 1 ) ) ); // phpcs:ignore WordPress.PHP.DisallowShortTernary.Found
			foreach ( $rows as $id => $base ) {
				$final[ $id ] = $share * ( isset( $rel[ $id ] ) ? $rel[ $id ] : 0 ) / $max * 100 + ( 1 - $share ) * $base;
			}
		} else {
			$final = $rows;
		}
		arsort( $final );
		$order = array_keys( $final );

		// 1ページ目の多様さとお試し表示（2ページ目以降も同じ計算をして、1ページ目に出た人を除く）。
		$explore = array();
		if ( $per_page >= 6 ) {
			list( $first, $explore ) = self::first_page( $order, $final, $args, $per_page, $context );
			$order                   = array_merge( $first, array_values( array_diff( $order, $first ) ) );
		}

		if ( ! $rel && $offset + $per_page > count( $order ) && $offset + $per_page <= $total ) {
			// 候補の外まで進んだら、ふつうの順番で取る。
			$plain = $backend->search( array_merge( $args, array( 'sort' => 'recommended' ) ) );
			$ids   = $plain['ids'];
		} else {
			$ids = array_slice( $order, $offset, $per_page );
		}

		$qid = '';
		if ( ! empty( $context['log'] ) && $ids && $page <= 3 ) {
			$qid    = SNSCS_Feedback::new_qid();
			$events = array();
			foreach ( $ids as $i => $id ) {
				$events[] = array( $id, $offset + $i + 1, in_array( $id, $explore, true ), isset( $rel[ $id ] ) ? $rel[ $id ] : 0 );
			}
			if ( ! SNSCS_Feedback::log_impressions( $qid, $events, $context['bucket'], ! empty( $context['has_q'] ) ) ) {
				$qid = '';
			}
		}
		return array( 'ids' => $ids, 'total' => $total, 'qid' => $qid, 'explore' => $explore );
	}

	/**
	 * 1ページ目を作る。
	 *
	 * @return array{0:int[],1:int[]} [1ページ目の並び, お試し表示の人]
	 */
	private static function first_page( array $order, array $final, array $args, $per_page, array $context ) {
		$pool = array_slice( $order, 0, $per_page * 2 );

		// 多様さ: 同じSNS・同じジャンルが続くほど点を引いて、貪欲に選ぶ。
		$lambda = (int) SNSCS_Learner::setting( 'diversity' ) / 10;
		$first  = array();
		if ( $lambda > 0 && count( $pool ) > 1 ) {
			$features = self::features( $pool );
			$seen_p   = array();
			$seen_g   = array();
			$left     = $pool;
			while ( $left && count( $first ) < $per_page ) {
				$best = null;
				$top  = -INF;
				foreach ( $left as $id ) {
					$p   = $features[ $id ]['platform'];
					$g   = $features[ $id ]['genre'];
					$adj = $final[ $id ] - $lambda * ( ( $seen_p[ $p ] ?? 0 ) * 1.0 + ( $g ? ( $seen_g[ $g ] ?? 0 ) * 1.5 : 0 ) );
					if ( $adj > $top ) {
						$top  = $adj;
						$best = $id;
					}
				}
				$first[]                                       = $best;
				$seen_p[ $features[ $best ]['platform'] ]      = ( $seen_p[ $features[ $best ]['platform'] ] ?? 0 ) + 1;
				if ( $features[ $best ]['genre'] ) {
					$seen_g[ $features[ $best ]['genre'] ] = ( $seen_g[ $features[ $best ]['genre'] ] ?? 0 ) + 1;
				}
				$left = array_values( array_diff( $left, array( $best ) ) );
			}
		} else {
			$first = array_slice( $order, 0, $per_page );
		}

		// お試し表示: 1ページ目より下の上位200人のうち、表示の少ない人からくじで選ぶ。
		$slots   = min( (int) SNSCS_Learner::setting( 'explore_slots' ), (int) floor( $per_page / 4 ) );
		$explore = array();
		if ( $slots > 0 ) {
			$rest     = array_slice( array_values( array_diff( $order, $first ) ), 0, 200 );
			$feedback = SNSCS_Feedback::for_creators( $rest );
			mt_srand( crc32( wp_json_encode( array( $args['q'], $args['platform'], $args['genre'], $args['region'], $args['min_followers'], $args['max_followers'], current_time( 'Y-m-d' ), $context['bucket'] ) ) ) );
			$draws = array();
			foreach ( $rest as $id ) {
				$imp = isset( $feedback[ $id ] ) ? $feedback[ $id ]['impressions'] : 0;
				if ( $imp >= self::EXPLORE_MAX ) {
					continue;
				}
				$clk          = isset( $feedback[ $id ] ) ? min( $imp, $feedback[ $id ]['clicks'] ) : 0;
				$draws[ $id ] = self::beta( $clk + 1, $imp - $clk + 1 );
			}
			arsort( $draws );
			$explore = array_slice( array_keys( $draws ), 0, $slots );
			mt_srand();
			// 枠の位置: 4番目、9番目、14番目…（上位3人は動かさない）。
			foreach ( $explore as $i => $id ) {
				$position = min( count( $first ), 3 + $i * 5 );
				array_splice( $first, $position, 0, array( $id ) );
			}
			$first = array_slice( $first, 0, $per_page );
			$explore = array_values( array_intersect( $explore, $first ) );
		}
		return array( $first, $explore );
	}

	/**
	 * 発信者の主なSNSと、最初のジャンル。
	 */
	private static function features( array $ids ) {
		update_object_term_cache( $ids, SNSCS_Post_Types::POST_TYPE );
		$accounts = SNSCS_Accounts::get_for_creators( $ids );
		$out      = array();
		foreach ( $ids as $id ) {
			$main   = '';
			$top    = -1;
			foreach ( isset( $accounts[ $id ] ) ? $accounts[ $id ] : array() as $account ) {
				if ( (int) $account['followers'] > $top ) {
					$top  = (int) $account['followers'];
					$main = $account['platform'];
				}
			}
			$genres     = get_the_terms( $id, SNSCS_Post_Types::TAX_GENRE );
			$out[ $id ] = array(
				'platform' => $main,
				'genre'    => $genres && ! is_wp_error( $genres ) ? (int) $genres[0]->term_id : 0,
			);
		}
		return $out;
	}

	/**
	 * ベータ分布からのくじ（a, b ≥ 1）。ガンマ分布2つの比で作る。
	 */
	public static function beta( $a, $b ) {
		$x = self::gamma( $a );
		$y = self::gamma( $b );
		return $x / max( 1e-12, $x + $y );
	}

	private static function gamma( $k ) {
		// Marsaglia–Tsang の方法（k ≥ 1）。
		$d = $k - 1 / 3;
		$c = 1 / sqrt( 9 * $d );
		while ( true ) {
			do {
				$u1 = ( mt_rand() + 1 ) / ( mt_getrandmax() + 2 );
				$u2 = ( mt_rand() + 1 ) / ( mt_getrandmax() + 2 );
				$z  = sqrt( -2 * log( $u1 ) ) * cos( 2 * M_PI * $u2 );
				$v  = pow( 1 + $c * $z, 3 );
			} while ( $v <= 0 );
			$u = ( mt_rand() + 1 ) / ( mt_getrandmax() + 2 );
			if ( log( $u ) < 0.5 * $z * $z + $d - $d * $v + $d * log( $v ) ) {
				return $d * $v;
			}
		}
	}
}
