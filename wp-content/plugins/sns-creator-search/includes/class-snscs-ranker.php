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
 * 1.21:
 *   言葉ごとの選ばれやすさ: その言葉で探した人によく選ばれている人の点を上げる（SNSCS_Feedback::query_boosts。0.7〜1.6倍）
 *   さっき見た人に近い人  : このタブで押した発信者と同じジャンル・一緒に見られている人を、上の方へ寄せる
 *   並べ方を混ぜて比べる  : 「インターリーブ」モードでは、1ページ目をいまの並び（A）と学習した並び（B）から交互に選び、
 *                           どちらの並びから来た人が押されたかで、少ない検索数でも良し悪しを比べる
 *   結果を覚える          : 同じ条件の検索は、並べた結果を5分覚えて使い回す（SNSCS_Search_Cache）
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
	 * @param array                $context bucket、intent、log（表示を記録するか）、has_q、qhash（言葉の番号）、session（このタブで押した発信者）。
	 * @return array{ids:int[],total:int,qid:string,explore:int[],boosts:array,session:int[]}
	 */
	public static function rank( array $args, SNSCS_MySQL_Backend $backend, array $rel, array $context ) {
		$per_page = (int) $args['per_page'];
		$page     = (int) $args['page'];
		$offset   = ( $page - 1 ) * $per_page;
		$bucket   = $context['bucket'];
		$qhash    = (int) ( $context['qhash'] ?? 0 );
		$session  = array_slice( array_map( 'intval', (array) ( $context['session'] ?? array() ) ), 0, 5 );
		// 並べ方を混ぜて比べる（インターリーブ）: 1ページ目で、一人ひとりに合わせていない検索だけ。
		$interleave = 'interleave' === SNSCS_Learner::setting( 'mode' ) && 1 === $page && empty( $args['personal_order'] ) && ! $session && $per_page >= 6;
		$cacheable  = empty( $args['personal_order'] ) && ! $session;
		$cache_key  = array( array_diff_key( $args, array_flip( array( 'page' ) ) ), $bucket, $qhash );

		$cached = $cacheable ? SNSCS_Search_Cache::get( 'rank', $cache_key ) : null;
		$boosts = $qhash ? SNSCS_Feedback::query_boosts( $qhash ) : array();
		$boosted_session = array();
		if ( is_array( $cached ) ) {
			list( $total, $order, $explore, $final_top ) = $cached;
		} else {
			$total = $backend->count( $args );
			if ( ! $total ) {
				return array( 'ids' => array(), 'total' => 0, 'qid' => '', 'explore' => array(), 'boosts' => array(), 'session' => array() );
			}
			$limit = $rel ? 0 : max( self::BROWSE_POOL, $offset + $per_page * 2 );
			$final = self::final_scores( $args, $backend, $rel, $limit, $bucket, $boosts, $context );

			// このタブで押した発信者に近い人を、上の方へ寄せる（さっき見た人と同じジャンル・一緒に見られている人）。
			if ( $session ) {
				$boosted_session = self::session_boost( $final, $session );
			}
			arsort( $final );
			$order = array_keys( $final );

			// 1ページ目の多様さとお試し表示（2ページ目以降も同じ計算をして、1ページ目に出た人を除く）。
			$explore = array();
			if ( $per_page >= 6 ) {
				list( $first, $explore ) = self::first_page( $order, $final, $args, $per_page, $context );
				$order                   = array_merge( $first, array_values( array_diff( $order, $first ) ) );
			}
			$final_top = array_slice( $final, 0, 200, true );
			if ( $cacheable ) {
				SNSCS_Search_Cache::set( 'rank', $cache_key, array( $total, array_slice( $order, 0, 2000 ), $explore, $final_top ), max( $total, count( $rel ) ) );
			}
		}

		$teams = array();
		if ( $interleave ) {
			// B の並び（学習した重み）を作り、A（いまの並び）と交互に取る（チーム・ドラフト方式）。
			$final_b = self::final_scores( $args, $backend, $rel, $rel ? 0 : max( self::BROWSE_POOL, $per_page * 2 ), 'b', $boosts, $context );
			arsort( $final_b );
			list( $first, $teams ) = self::team_draft( array_slice( $order, 0, $per_page * 2 ), array_slice( array_keys( $final_b ), 0, $per_page * 2 ), $per_page );
			$order                 = array_merge( $first, array_values( array_diff( $order, $first ) ) );
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
				$row = array( $id, $offset + $i + 1, in_array( $id, $explore, true ), isset( $rel[ $id ] ) ? $rel[ $id ] : 0 );
				if ( isset( $teams[ $id ] ) ) {
					$row[] = $teams[ $id ];
				}
				$events[] = $row;
			}
			if ( ! SNSCS_Feedback::log_impressions( $qid, $events, $bucket, ! empty( $context['has_q'] ), $qhash ) ) {
				$qid = '';
			}
		}
		return array(
			'ids'     => $ids,
			'total'   => $total,
			'qid'     => $qid,
			'explore' => $explore,
			'boosts'  => array_intersect_key( $boosts, array_flip( $ids ) ),
			'session' => array_values( array_intersect( $boosted_session, $ids ) ),
		);
	}

	/**
	 * 2段目の最終点（並べる前）。
	 *
	 * @return array<int,float>
	 */
	private static function final_scores( array $args, SNSCS_MySQL_Backend $backend, array $rel, $limit, $bucket, array $boosts, array $context ) {
		$rows  = $backend->rows( $args, $limit, 'b' === $bucket ? 'score_b' : 'score' );
		$final = array();
		if ( $rel ) {
			$share = SNSCS_Learner::rel_share( $bucket );
			if ( 'name' === $context['intent'] ) {
				$share = max( $share, 0.85 );
			} elseif ( in_array( $context['intent'], array( 'genre', 'region' ), true ) ) {
				$share *= 0.6;
			}
			$max = max( 0.000001, max( array_intersect_key( $rel, $rows ) ?: array( 1 ) ) ); // phpcs:ignore WordPress.PHP.DisallowShortTernary.Found
			// 不正対策で下げている人は、言葉との一致の点にも同じ倍率を掛ける（総合点には計算のときに掛け済み）。
			$penalties = SNSCS_Integrity::penalties();
			foreach ( $rows as $id => $base ) {
				$factor       = isset( $penalties[ $id ] ) ? $penalties[ $id ] : 1.0;
				$final[ $id ] = $share * ( isset( $rel[ $id ] ) ? $rel[ $id ] : 0 ) / $max * 100 * $factor + ( 1 - $share ) * $base;
			}
		} else {
			$final = $rows;
		}
		// その言葉で探した人によく選ばれている人を上げ、表示されても選ばれない人を少し下げる。
		foreach ( $boosts as $id => $boost ) {
			if ( isset( $final[ $id ] ) ) {
				$final[ $id ] *= $boost;
			}
		}
		return $final;
	}

	/**
	 * このタブで押した発信者に近い人の点を上げる。
	 *
	 * @param array<int,float> $final 最終点（書き換える）。
	 * @param int[]            $seeds 押した発信者（新しい順）。
	 * @return int[] 上げた人。
	 */
	private static function session_boost( array &$final, array $seeds ) {
		arsort( $final );
		$pool   = array_slice( array_keys( $final ), 0, 80 );
		$genres = array();
		foreach ( $seeds as $id ) {
			$terms = get_the_terms( $id, SNSCS_Post_Types::TAX_GENRE );
			foreach ( $terms && ! is_wp_error( $terms ) ? $terms : array() as $term ) {
				$genres[ (int) $term->term_id ] = true;
			}
		}
		$co      = SNSCS_Personalize::predict( $seeds, 60 );
		$co_max  = $co ? max( $co ) : 1;
		$top     = $final ? max( $final ) : 100;
		$boosted = array();
		update_object_term_cache( $pool, SNSCS_Post_Types::POST_TYPE );
		foreach ( $pool as $id ) {
			if ( in_array( $id, $seeds, true ) ) {
				continue;
			}
			$bonus = 0.0;
			$terms = get_the_terms( $id, SNSCS_Post_Types::TAX_GENRE );
			foreach ( $terms && ! is_wp_error( $terms ) ? $terms : array() as $term ) {
				if ( isset( $genres[ (int) $term->term_id ] ) ) {
					$bonus += 0.06;
					break;
				}
			}
			if ( isset( $co[ $id ] ) ) {
				$bonus += 0.08 * $co[ $id ] / max( 1e-9, $co_max );
			}
			if ( $bonus > 0 ) {
				$final[ $id ] += $bonus * $top;
				$boosted[]     = $id;
			}
		}
		return $boosted;
	}

	/**
	 * チーム・ドラフト方式で2つの並びを混ぜる（どちらの並びから選んだかを覚える）。
	 *
	 * @return array{0:int[],1:array<int,string>} [混ぜた並び, 発信者ID => 'a' | 'b']
	 */
	public static function team_draft( array $a, array $b, $limit ) {
		$out   = array();
		$teams = array();
		$count = array( 'a' => 0, 'b' => 0 );
		$lists = array( 'a' => $a, 'b' => $b );
		while ( count( $out ) < $limit && ( $lists['a'] || $lists['b'] ) ) {
			// 選んだ数が少ない方から。同じならくじで決める。
			if ( $count['a'] === $count['b'] ) {
				$team = wp_rand( 0, 1 ) ? 'a' : 'b';
			} else {
				$team = $count['a'] < $count['b'] ? 'a' : 'b';
			}
			if ( ! $lists[ $team ] ) {
				$team = 'a' === $team ? 'b' : 'a';
			}
			while ( $lists[ $team ] && isset( $teams[ $lists[ $team ][0] ] ) ) {
				array_shift( $lists[ $team ] );
			}
			if ( ! $lists[ $team ] ) {
				$count[ $team ] = PHP_INT_MAX;
				if ( PHP_INT_MAX === $count['a'] && PHP_INT_MAX === $count['b'] ) {
					break;
				}
				continue;
			}
			$id            = array_shift( $lists[ $team ] );
			$out[]         = $id;
			$teams[ $id ]  = $team;
			$count[ $team ]++;
		}
		return array( $out, $teams );
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
