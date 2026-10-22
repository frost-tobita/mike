<?php
/**
 * 検索アルゴリズムの学習と設定（管理画面「発信者 → 検索アルゴリズム」）。
 *
 * ■ ランキング学習（Learning to Rank）
 *   検索結果で「押されて満足された発信者」は、その上に表示されたのに押されなかった発信者や、
 *   すぐ下で押されなかった発信者より「良い」と考え、その組（ペア）を毎晩集める。
 *   点数の式 s = w_関連度 × 関連度 + Σ w_k × 信号kの点 の重み w を、ペアの順番を当てられるように
 *   少しずつ直す（ペアワイズ・ロジスティック回帰、確率的勾配降下法）。重みはマイナスにしない。
 *   学習に使わなかった2割のペアで、手で決めた重みと学習した重みの「当たり率」を比べ、
 *   学習した方が1ポイント以上良いときだけ使う（悪くなる変更はしない）。
 *
 * ■ 使い方（モード）
 *   manual : 手で決めた重みだけ（初期）
 *   auto   : 学習した重みが勝っていれば、手の重みと「反映度」の割合で混ぜて使う
 *   ab     : A/Bテスト。訪問者を半分に分け、B組だけ学習した重みで並べ、満足の割合を比べる
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Learner {

	const OPTION     = 'snscs_rank_engine';
	const LEARNED    = 'snscs_learned';
	const ADMIN_PAGE = 'snscs-engine';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'snscs_nightly_engine', array( __CLASS__, 'train' ), 25 );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_snscs_engine_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_snscs_engine_run', array( __CLASS__, 'handle_run' ) );
	}

	public static function defaults() {
		return array(
			'mode'            => 'manual',
			'blend'           => 50,
			'rel_share'       => 60,
			'explore_slots'   => 2,
			'diversity'       => 30,
			'log_rate'        => 100,
			'min_pairs'       => 300,
			'synonyms'        => SNSCS_Text::default_synonyms(),
			'ai_tags'         => 0,
			'ai_tags_per_day' => 200,
			'spike'           => 1,
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function setting( $key ) {
		$s = self::settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	/**
	 * 毎晩（3時ごろ）と1時間ごとの処理を登録する。
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'snscs_nightly_engine' ) ) {
			$tomorrow3 = strtotime( current_time( 'Y-m-d' ) . ' 03:10:00' ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
			if ( $tomorrow3 < time() ) {
				$tomorrow3 += DAY_IN_SECONDS;
			}
			wp_schedule_event( $tomorrow3, 'daily', 'snscs_nightly_engine' );
		}
		if ( ! wp_next_scheduled( 'snscs_hourly_engine' ) ) {
			wp_schedule_event( time() + 3 * MINUTE_IN_SECONDS, 'hourly', 'snscs_hourly_engine' );
		}
	}

	/* --------------------------------------------------------------------
	 * 使う重み
	 * ------------------------------------------------------------------ */

	public static function learned() {
		$learned = get_option( self::LEARNED );
		return is_array( $learned ) ? $learned : array();
	}

	/**
	 * 合計100にそろえる。
	 */
	private static function normalize( array $weights ) {
		$sum = array_sum( array_map( 'floatval', $weights ) );
		if ( $sum <= 0 ) {
			return $weights;
		}
		return array_map(
			function ( $w ) use ( $sum ) {
				return round( (float) $w / $sum * 100, 3 );
			},
			$weights
		);
	}

	/**
	 * 手の重みと学習した重みを、反映度の割合で混ぜる。
	 */
	private static function blended( array $manual ) {
		$learned = self::learned();
		if ( empty( $learned['weights'] ) ) {
			return array( 'weights' => $manual, 'rel_share' => (float) self::setting( 'rel_share' ) / 100 );
		}
		$a      = min( 100, max( 0, (int) self::setting( 'blend' ) ) ) / 100;
		$m      = self::normalize( $manual );
		$l      = self::normalize( array_intersect_key( $learned['weights'], $manual ) + array_fill_keys( array_keys( $manual ), 0 ) );
		$mixed  = array();
		foreach ( $m as $key => $w ) {
			$mixed[ $key ] = ( 1 - $a ) * $w + $a * ( isset( $l[ $key ] ) ? $l[ $key ] : 0 );
		}
		$rel = ( 1 - $a ) * (float) self::setting( 'rel_share' ) / 100 + $a * (float) $learned['rel_share'];
		return array( 'weights' => $mixed, 'rel_share' => $rel );
	}

	/**
	 * 総合点（score の列）に使う重み。
	 */
	public static function active_weights( array $manual ) {
		$learned = self::learned();
		if ( 'auto' === self::setting( 'mode' ) && ! empty( $learned['adopt'] ) ) {
			return self::blended( $manual )['weights'];
		}
		return $manual;
	}

	/**
	 * A/Bテストの B組に使う重み（ab のときだけ）。
	 */
	public static function candidate_weights( array $manual ) {
		return 'ab' === self::setting( 'mode' ) ? self::blended( $manual )['weights'] : null;
	}

	/**
	 * 関連度と総合点の割合（0〜1）。
	 */
	public static function rel_share( $bucket ) {
		$mode    = self::setting( 'mode' );
		$learned = self::learned();
		if ( ( 'auto' === $mode && ! empty( $learned['adopt'] ) ) || ( 'ab' === $mode && 'b' === $bucket ) ) {
			// 学習の結果がどうであれ、言葉との一致は3割より軽くしない（検索した言葉と関係ない人が並ばないように）。
			return max( 0.3, self::blended( SNSCS_Scores::settings()['weights'] )['rel_share'] );
		}
		return (float) self::setting( 'rel_share' ) / 100;
	}

	/**
	 * A/Bテストの組（訪問者ごと・日ごと）。
	 */
	public static function bucket() {
		if ( 'ab' !== self::setting( 'mode' ) ) {
			return 'a';
		}
		return hexdec( substr( SNSCS_Referrals::visitor_hash(), 0, 2 ) ) % 2 ? 'b' : 'a';
	}

	/* --------------------------------------------------------------------
	 * 学習
	 * ------------------------------------------------------------------ */

	/**
	 * 記録から学習用のペアを作る。
	 *
	 * @return array{0:array,1:array} [学習用, 確認用] 各要素は [良い方の特徴, 悪い方の特徴]。
	 */
	public static function pairs() {
		global $wpdb;
		$events = SNSCS_Feedback::events_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$qids = $wpdb->get_col( "SELECT DISTINCT qid FROM {$events} WHERE clicked = 1 AND explore = 0 LIMIT 20000" );
		if ( ! $qids ) {
			return array( array(), array() );
		}
		$signals = array_keys( SNSCS_Scores::signals() );
		$train   = array();
		$test    = array();
		$points  = array();
		foreach ( array_chunk( $qids, 500 ) as $chunk ) {
			$in   = "'" . implode( "','", array_map( 'esc_sql', $chunk ) ) . "'";
			$rows = $wpdb->get_results( "SELECT qid, creator_id, pos, rel, clicked, good, bounce FROM {$events} WHERE qid IN ({$in}) ORDER BY qid, pos", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$need = array_diff( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'creator_id' ) ) ), array_keys( $points ) );
			if ( $need ) {
				$stats = SNSCS_Scores::table();
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				foreach ( $wpdb->get_results( "SELECT creator_id, points FROM {$stats} WHERE creator_id IN (" . implode( ',', $need ) . ')', ARRAY_A ) as $p ) {
					$decoded                              = json_decode( (string) $p['points'], true );
					$points[ (int) $p['creator_id'] ] = is_array( $decoded ) ? $decoded : array();
				}
			}
			$by_q = array();
			foreach ( $rows as $row ) {
				$by_q[ $row['qid'] ][] = $row;
			}
			foreach ( $by_q as $qid => $list ) {
				$max_rel = max( 0.000001, max( array_map( 'floatval', wp_list_pluck( $list, 'rel' ) ) ) );
				$feat    = function ( $row ) use ( $points, $signals, $max_rel ) {
					$x = array( 'rel' => (float) $row['rel'] / $max_rel );
					$p = isset( $points[ (int) $row['creator_id'] ] ) ? $points[ (int) $row['creator_id'] ] : array();
					foreach ( $signals as $key ) {
						$x[ $key ] = isset( $p[ $key ] ) ? (float) $p[ $key ] / 100 : 0.0;
					}
					return $x;
				};
				$grade = function ( $row ) {
					if ( ! $row['clicked'] ) {
						return 0;
					}
					return $row['good'] ? 2 : ( $row['bounce'] ? 0 : 1 );
				};
				$target = 0 === crc32( $qid ) % 5 ? 'test' : 'train';
				foreach ( $list as $j => $good ) {
					$g = $grade( $good );
					if ( $g <= 0 ) {
						continue;
					}
					foreach ( $list as $i => $other ) {
						// 上に表示されたのに押されなかった人と、すぐ下で押されなかった人。
						$skip_above = (int) $other['pos'] < (int) $good['pos'] && ! $other['clicked'];
						$skip_next  = (int) $other['pos'] === (int) $good['pos'] + 1 && ! $other['clicked'];
						$worse      = $other['clicked'] && $grade( $other ) < $g;
						if ( $skip_above || $skip_next || $worse ) {
							${$target}[] = array( $feat( $good ), $feat( $other ) );
						}
					}
				}
			}
		}
		return array( $train, $test );
	}

	private static function score( array $w, array $x ) {
		$s = 0.0;
		foreach ( $w as $key => $value ) {
			$s += $value * ( isset( $x[ $key ] ) ? $x[ $key ] : 0 );
		}
		return $s;
	}

	/**
	 * ペアの順番を当てられた割合。
	 */
	public static function accuracy( array $w, array $pairs ) {
		if ( ! $pairs ) {
			return 0.0;
		}
		$ok = 0;
		foreach ( $pairs as $pair ) {
			$ok += self::score( $w, $pair[0] ) > self::score( $w, $pair[1] ) ? 1 : 0;
		}
		return $ok / count( $pairs );
	}

	/**
	 * 手で決めた重みを、学習と同じ形（関連度＋各信号）にする。
	 */
	public static function manual_vector() {
		$manual = SNSCS_Scores::settings()['weights'];
		$sum    = max( 1, array_sum( $manual ) );
		$share  = (float) self::setting( 'rel_share' ) / 100;
		$w      = array( 'rel' => $share );
		foreach ( $manual as $key => $value ) {
			$w[ $key ] = ( 1 - $share ) * $value / $sum;
		}
		return $w;
	}

	/**
	 * 毎晩の学習。
	 *
	 * @return array 学習の結果。
	 */
	public static function train() {
		list( $train, $test ) = self::pairs();
		$manual               = self::manual_vector();
		$result               = array(
			'pairs'      => count( $train ) + count( $test ),
			'trained_at' => current_time( 'mysql' ),
			'adopt'      => false,
		);
		if ( count( $train ) + count( $test ) < (int) self::setting( 'min_pairs' ) ) {
			$result['note'] = 'データがまだ少ないため、手で決めた重みを使っています。';
			$previous       = self::learned();
			update_option( self::LEARNED, array_merge( $previous, $result, array( 'adopt' => ! empty( $previous['adopt'] ) ) ), false );
			return $result;
		}

		// 手の重みから始めて、ペアの順番を当てられるように少しずつ直す。
		$w      = $manual;
		$rate   = 0.5;
		$lambda = 0.001;
		mt_srand( 42 );
		for ( $epoch = 0; $epoch < 25; $epoch++ ) {
			shuffle( $train );
			foreach ( $train as $pair ) {
				$diff = self::score( $w, $pair[0] ) - self::score( $w, $pair[1] );
				$g    = 1 / ( 1 + exp( min( 50, $diff * 10 ) ) );
				foreach ( $w as $key => $value ) {
					$d          = ( isset( $pair[0][ $key ] ) ? $pair[0][ $key ] : 0 ) - ( isset( $pair[1][ $key ] ) ? $pair[1][ $key ] : 0 );
					$w[ $key ] = max( 0.0, $value + $rate * ( $g * $d - $lambda * $value ) );
				}
			}
			$rate *= 0.85;
		}
		$sum = max( 0.000001, array_sum( $w ) );
		foreach ( $w as $key => $value ) {
			$w[ $key ] = $value / $sum;
		}

		$acc_manual  = self::accuracy( $manual, $test ? $test : $train );
		$acc_learned = self::accuracy( $w, $test ? $test : $train );
		$weights     = $w;
		unset( $weights['rel'] );
		$result = array_merge(
			$result,
			array(
				'weights'     => array_map(
					function ( $v ) {
						return round( $v * 100, 3 );
					},
					$weights
				),
				'rel_share'   => round( $w['rel'], 4 ),
				'acc_manual'  => round( $acc_manual, 4 ),
				'acc_learned' => round( $acc_learned, 4 ),
				'adopt'       => $acc_learned >= $acc_manual + 0.01,
				'note'        => $acc_learned >= $acc_manual + 0.01 ? '学習した重みの方が、利用者の満足をよく当てられました。' : '学習した重みは、手で決めた重みより良くならなかったため使いません。',
			)
		);
		update_option( self::LEARNED, $result, false );
		SNSCS_Scores::schedule_soon();
		return $result;
	}

	/**
	 * A/Bテストの結果（直近14日、1ページ目の上位10件）。
	 *
	 * @return array<string,array{searches:int,clicked:int,good:int,rate:float}>|array
	 */
	public static function ab_results() {
		global $wpdb;
		$events = SNSCS_Feedback::events_table();
		$since  = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -13 days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT bucket, COUNT(*) AS searches, SUM(any_click) AS clicked, SUM(any_good) AS good FROM (SELECT qid, MAX(bucket) AS bucket, MAX(clicked) AS any_click, MAX(good) AS any_good FROM {$events} WHERE day >= %s AND pos <= 10 GROUP BY qid) t GROUP BY bucket", $since ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$n                     = max( 1, (int) $row['searches'] );
			$out[ $row['bucket'] ] = array( 'searches' => (int) $row['searches'], 'clicked' => (int) $row['clicked'], 'good' => (int) $row['good'], 'rate' => (int) $row['good'] / $n );
		}
		if ( isset( $out['a'], $out['b'] ) ) {
			// 2つの割合の差の検定（z）。|z| が 1.96 を超えれば、偶然ではない差（95%）。
			$p1 = $out['a']['rate'];
			$p2 = $out['b']['rate'];
			$n1 = max( 1, $out['a']['searches'] );
			$n2 = max( 1, $out['b']['searches'] );
			$p  = ( $out['a']['good'] + $out['b']['good'] ) / ( $n1 + $n2 );
			$se = sqrt( max( 1e-9, $p * ( 1 - $p ) * ( 1 / $n1 + 1 / $n2 ) ) );
			$out['z'] = ( $p2 - $p1 ) / $se;
		}
		return $out;
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, '検索アルゴリズム', '検索アルゴリズム', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	public static function admin_url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::ADMIN_PAGE ) );
	}

	private static function verify( $action ) {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), $action ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
	}

	public static function handle_save() {
		self::verify( 'snscs_engine_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verify() で確認済み。
		$in = wp_unslash( $_POST );
		$s  = self::settings();
		$s['mode']            = in_array( $in['mode'] ?? '', array( 'manual', 'auto', 'ab' ), true ) ? $in['mode'] : 'manual';
		$s['blend']           = min( 100, max( 0, (int) ( $in['blend'] ?? 50 ) ) );
		$s['rel_share']       = min( 95, max( 5, (int) ( $in['rel_share'] ?? 60 ) ) );
		$s['explore_slots']   = min( 5, max( 0, (int) ( $in['explore_slots'] ?? 2 ) ) );
		$s['diversity']       = min( 100, max( 0, (int) ( $in['diversity'] ?? 30 ) ) );
		$s['log_rate']        = min( 100, max( 1, (int) ( $in['log_rate'] ?? 100 ) ) );
		$s['min_pairs']       = min( 100000, max( 50, (int) ( $in['min_pairs'] ?? 300 ) ) );
		// ブラウザから届く改行（\r\n）を \n にそろえる（変わったかどうかを正しく比べるため）。
		$s['synonyms']        = str_replace( array( "\r\n", "\r" ), "\n", sanitize_textarea_field( (string) ( $in['synonyms'] ?? '' ) ) );
		$s['ai_tags']         = empty( $in['ai_tags'] ) ? 0 : 1;
		$s['ai_tags_per_day'] = min( 2000, max( 10, (int) ( $in['ai_tags_per_day'] ?? 200 ) ) );
		$s['spike']           = empty( $in['spike'] ) ? 0 : 1;
		// phpcs:enable
		$synonyms_changed = trim( $s['synonyms'] ) !== trim( str_replace( array( "\r\n", "\r" ), "\n", (string) self::setting( 'synonyms' ) ) );
		update_option( self::OPTION, $s, false );
		SNSCS_Scores::schedule_soon();
		wp_safe_redirect( self::admin_url( array( 'snscs_msg' => $synonyms_changed ? 'saved_syn' : 'saved' ) ) );
		exit;
	}

	public static function handle_run() {
		self::verify( 'snscs_engine_run' );
		$do  = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$msg = '';
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		if ( 'index' === $do ) {
			SNSCS_Index::restart();
			$state = SNSCS_Index::rebuild_step( 5000 );
			$msg   = $state['ready'] ? 'indexed' : 'indexing';
		} elseif ( 'index_more' === $do ) {
			$state = SNSCS_Index::rebuild_step( 5000 );
			$msg   = $state['ready'] ? 'indexed' : 'indexing';
		} elseif ( 'learn' === $do ) {
			SNSCS_Feedback::aggregate();
			SNSCS_Graph::compute();
			self::train();
			SNSCS_Scores::recalculate();
			$msg = 'learned';
		} elseif ( 'tags' === $do ) {
			$msg = SNSCS_Tagger::order() ? 'tagging' : 'tags_none';
		} elseif ( 'adopt_b' === $do ) {
			$s         = self::settings();
			$s['mode'] = 'auto';
			update_option( self::OPTION, $s, false );
			$learned          = self::learned();
			$learned['adopt'] = true;
			update_option( self::LEARNED, $learned, false );
			SNSCS_Scores::schedule_soon();
			$msg = 'adopted';
		}
		wp_safe_redirect( self::admin_url( array( 'snscs_msg' => $msg ) ) );
		exit;
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$s       = self::settings();
		$learned = self::learned();
		$manual  = SNSCS_Scores::settings()['weights'];
		$signals = SNSCS_Scores::signals();
		$summary = SNSCS_Feedback::summary();
		$ab      = 'ab' === $s['mode'] ? self::ab_results() : array();
		$curve   = SNSCS_Feedback::position_ctr();
		$graph   = (array) get_option( 'snscs_graph_info', array() );
		$tagger  = (array) get_option( SNSCS_Tagger::STATE, array() );
		$total   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", SNSCS_Post_Types::POST_TYPE ) );
		$indexed = (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT creator_id) FROM ' . SNSCS_Index::docs_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$tagged  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", SNSCS_Tagger::META ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg      = isset( $_GET['snscs_msg'] ) ? sanitize_key( $_GET['snscs_msg'] ) : '';
		$messages = array(
			'saved'     => '設定を保存しました。',
			'saved_syn' => '設定を保存しました。言い換え辞書を変えたので、新しい言葉は次の検索からすぐに使われます。',
			'indexed'   => '検索の索引ができました。',
			'indexing'  => '索引を作っています。人数が多いため、1時間ごとの処理で続きを作ります（「続きを作る」を押すと今すぐ進みます）。',
			'learned'   => '集計・信頼度の計算・学習・点数の計算を行いました。',
			'tagging'   => 'AIにタグ付けを依頼しました。多くは1時間以内に仕上がります。',
			'tags_none' => 'タグ付けする発信者がいないか、設定（オン・APIキー）が足りません。',
			'adopted'   => '学習した重み（B）を採用しました。',
		);
		$num = function ( $v, $d = 1 ) {
			return number_format_i18n( (float) $v, $d );
		};
		?>
		<div class="wrap">
			<h1>検索アルゴリズム</h1>
			<?php if ( isset( $messages[ $msg ] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $messages[ $msg ] ); ?></p></div>
			<?php endif; ?>

			<p>キーワード検索の「関連度」、利用者の行動から学ぶ並べ替え、新人のお試し表示、並びの多様化、つながりの信頼度、急増の検知、AIの自動タグ付けを設定します。</p>

			<h2>いまの状態</h2>
			<table class="widefat striped" style="max-width: 980px">
				<tbody>
					<tr><th style="width: 260px">検索の索引</th><td><?php echo esc_html( sprintf( '%s / %s 人', number_format_i18n( $indexed ), number_format_i18n( $total ) ) ); ?> <?php echo SNSCS_Index::ready() ? '（完成）' : '<strong>（作成中。完成までは、これまでの検索方法を使います）</strong>'; ?></td></tr>
					<tr><th>直近7日の検索</th><td><?php echo esc_html( sprintf( '%s回 ・ 表示 %s ・ クリック %s ・ 満足 %s ・ すぐ戻る %s', number_format_i18n( (int) $summary['searches'] ), number_format_i18n( (int) $summary['impressions'] ), number_format_i18n( (int) $summary['clicks'] ), number_format_i18n( (int) $summary['good'] ), number_format_i18n( (int) $summary['bounce'] ) ) ); ?></td></tr>
					<tr><th>位置ごとのクリック率</th><td><?php echo $curve ? esc_html( implode( ' / ', array_map( function ( $p, $c ) { return $p . '位 ' . number_format( $c * 100, 1 ) . '%'; }, array_slice( array_keys( $curve ), 0, 10 ), array_slice( $curve, 0, 10 ) ) ) ) : 'まだありません（毎晩集計）'; ?></td></tr>
					<tr><th>学習</th><td>
						<?php if ( $learned ) : ?>
							<?php echo esc_html( sprintf( '%s（ペア %s 組）', $learned['trained_at'] ?? '', number_format_i18n( (int) ( $learned['pairs'] ?? 0 ) ) ) ); ?><br>
							<?php if ( isset( $learned['acc_manual'] ) ) : ?>
								<?php echo esc_html( sprintf( '当たり率: 手の重み %s%% → 学習した重み %s%%', $num( $learned['acc_manual'] * 100 ), $num( $learned['acc_learned'] * 100 ) ) ); ?><br>
							<?php endif; ?>
							<?php echo esc_html( $learned['note'] ?? '' ); ?>
						<?php else : ?>
							まだ学習していません（毎晩3時ごろ）。
						<?php endif; ?>
					</td></tr>
					<tr><th>つながりの信頼度</th><td><?php echo $graph ? esc_html( sprintf( '%s（%s人・つながり %s・起点 %s人）', $graph['at'], number_format_i18n( $graph['nodes'] ), number_format_i18n( $graph['edges'] ), number_format_i18n( $graph['seeds'] ) ) ) : 'まだ計算していません（毎晩）'; ?>
						<br><span class="description">起点は、発信者の編集画面の「信頼できる発信者」で選べます。</span></td></tr>
					<tr><th>AIの自動タグ</th><td><?php echo esc_html( sprintf( '%s人にタグあり', number_format_i18n( $tagged ) ) ); ?><?php echo ! empty( $tagger['batch'] ) ? '（依頼中）' : ''; ?><?php echo ! empty( $tagger['error'] ) ? ' <span style="color:#b32d2e">' . esc_html( $tagger['error'] ) . '</span>' : ''; ?></td></tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px">
				<input type="hidden" name="action" value="snscs_engine_run">
				<?php wp_nonce_field( 'snscs_engine_run', 'snscs_nonce' ); ?>
				<button class="button" name="do" value="index" onclick="return confirm( '検索の索引を最初から作り直します。人数が多い場合は数回に分かれます。よろしいですか？' );">索引を作り直す</button>
				<?php if ( ! SNSCS_Index::ready() ) : ?>
					<button class="button button-primary" name="do" value="index_more">索引の続きを作る</button>
				<?php endif; ?>
				<button class="button" name="do" value="learn">今すぐ集計・学習する</button>
				<button class="button" name="do" value="tags">今すぐAIタグ付けを依頼</button>
			</form>

			<?php if ( $learned && ! empty( $learned['weights'] ) ) : ?>
				<h2>重みの比較</h2>
				<table class="widefat striped" style="max-width: 640px">
					<thead><tr><th>点数</th><th style="text-align: right">手で決めた重み</th><th style="text-align: right">学習した重み</th></tr></thead>
					<tbody>
						<?php $mn = array_sum( $manual ); ?>
						<?php foreach ( $signals as $key => $signal ) : ?>
							<tr><td><?php echo esc_html( $signal['label'] ); ?></td><td style="text-align: right"><?php echo esc_html( $num( ( $manual[ $key ] ?? 0 ) / max( 1, $mn ) * 100 ) ); ?>%</td><td style="text-align: right"><?php echo esc_html( $num( ( $learned['weights'][ $key ] ?? 0 ) / max( 0.001, array_sum( $learned['weights'] ) ) * 100 ) ); ?>%</td></tr>
						<?php endforeach; ?>
						<tr><td><strong>キーワード検索での関連度の割合</strong></td><td style="text-align: right"><?php echo (int) $s['rel_share']; ?>%</td><td style="text-align: right"><?php echo esc_html( $num( $learned['rel_share'] * 100 ) ); ?>%<?php echo $learned['rel_share'] < 0.3 ? '<br><span class="description">（使うときは下限の30%）</span>' : ''; ?></td></tr>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( 'ab' === $s['mode'] ) : ?>
				<h2>A/Bテストの結果（直近14日）</h2>
				<?php if ( isset( $ab['a'], $ab['b'] ) ) : ?>
					<table class="widefat striped" style="max-width: 640px">
						<thead><tr><th>組</th><th style="text-align: right">検索</th><th style="text-align: right">満足したクリックがあった割合</th></tr></thead>
						<tbody>
							<tr><td>A（手で決めた重み）</td><td style="text-align: right"><?php echo esc_html( number_format_i18n( $ab['a']['searches'] ) ); ?></td><td style="text-align: right"><?php echo esc_html( $num( $ab['a']['rate'] * 100 ) ); ?>%</td></tr>
							<tr><td>B（学習した重み）</td><td style="text-align: right"><?php echo esc_html( number_format_i18n( $ab['b']['searches'] ) ); ?></td><td style="text-align: right"><?php echo esc_html( $num( $ab['b']['rate'] * 100 ) ); ?>%</td></tr>
						</tbody>
					</table>
					<p>
						<?php if ( $ab['z'] >= 1.96 ) : ?>
							<strong>B の方が良い（偶然ではない差: 95%）。</strong>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline">
								<input type="hidden" name="action" value="snscs_engine_run"><?php wp_nonce_field( 'snscs_engine_run', 'snscs_nonce' ); ?>
								<button class="button button-primary" name="do" value="adopt_b">B を採用する</button>
							</form>
						<?php elseif ( $ab['z'] <= -1.96 ) : ?>
							A の方が良い結果です。学習した重みは使わない方がよさそうです。
						<?php else : ?>
							まだはっきりした差はありません（z = <?php echo esc_html( $num( $ab['z'], 2 ) ); ?>）。データが増えるのを待ちましょう。
						<?php endif; ?>
					</p>
				<?php else : ?>
					<p>まだデータがありません。</p>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="snscs_engine_save">
				<?php wp_nonce_field( 'snscs_engine_save', 'snscs_nonce' ); ?>
				<h2>並べ方の設定</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">学習の使い方</th>
						<td>
							<label><input type="radio" name="mode" value="manual" <?php checked( $s['mode'], 'manual' ); ?>> 手で決めた重みだけを使う</label><br>
							<label><input type="radio" name="mode" value="auto" <?php checked( $s['mode'], 'auto' ); ?>> 学習した重みが良ければ自動で使う（おすすめ。データが少ないうちは手の重みのまま）</label><br>
							<label><input type="radio" name="mode" value="ab" <?php checked( $s['mode'], 'ab' ); ?>> A/Bテストをする（訪問者の半分だけ学習した重みで並べ、結果を比べる）</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="blend">学習の反映度</label></th>
						<td><input id="blend" type="number" name="blend" min="0" max="100" value="<?php echo (int) $s['blend']; ?>" class="small-text"> %
						<p class="description">学習した重みを使うとき、手の重みとどの割合で混ぜるか。100% なら学習した重みだけ。</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="rel_share">関連度の割合</label></th>
						<td><input id="rel_share" type="number" name="rel_share" min="5" max="95" value="<?php echo (int) $s['rel_share']; ?>" class="small-text"> %
						<p class="description">キーワード検索の「おすすめ順」で、言葉との合い方（関連度）を何%、総合点を何%にするか。人の名前で探したときは自動で関連度を85%以上に、ジャンル・地域の名前で探したときは低めにします。</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="explore_slots">新人のお試し表示</label></th>
						<td>1ページ目のうち <input id="explore_slots" type="number" name="explore_slots" min="0" max="5" value="<?php echo (int) $s['explore_slots']; ?>" class="small-text"> 枠
						<p class="description">まだ表示の少ない発信者を、反応を見るために上のほうへ出します（反応が良ければ自然に上がり、悪ければ戻ります）。</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="diversity">並びの多様さ</label></th>
						<td><input id="diversity" type="number" name="diversity" min="0" max="100" value="<?php echo (int) $s['diversity']; ?>" class="small-text">
						<p class="description">1ページ目で、同じSNS・同じジャンルの人が続きすぎないようにする強さ（0で無効）。</p></td>
					</tr>
					<tr>
						<th scope="row">急増の検知</th>
						<td><label><input type="checkbox" name="spike" value="1" <?php checked( $s['spike'] ); ?>> 紹介リンクや閲覧がふだんより極端に急に増えた発信者を「要確認」にし、その点数を半分にする</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="synonyms">言い換え辞書</label></th>
						<td><textarea id="synonyms" name="synonyms" rows="10" class="large-text code"><?php echo esc_textarea( $s['synonyms'] ); ?></textarea>
						<p class="description">1行に1グループ、カンマ区切り。どれかの言葉で探すと、同じ行のほかの言葉でも見つかります（ひらがな・カタカナ、全角・半角の違いは辞書が無くても自動でそろえます）。</p></td>
					</tr>
				</table>

				<h2>学習とAIの設定</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="min_pairs">学習を始める量</label></th>
						<td><input id="min_pairs" type="number" name="min_pairs" min="50" max="100000" value="<?php echo (int) $s['min_pairs']; ?>" class="small-text"> 組
						<p class="description">「押されて満足された人」と「押されなかった人」の組がこの数に届くまでは学習した重みを使いません。</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="log_rate">記録する検索の割合</label></th>
						<td><input id="log_rate" type="number" name="log_rate" min="1" max="100" value="<?php echo (int) $s['log_rate']; ?>" class="small-text"> %
						<p class="description">検索がとても多くなったら下げると、記録の量（データベースの大きさ）を抑えられます。</p></td>
					</tr>
					<tr>
						<th scope="row">AIの自動タグ</th>
						<td>
							<label><input type="checkbox" name="ai_tags" value="1" <?php checked( $s['ai_tags'] ); ?>> 発信者の紹介文や記事から、AIが細かいタグ（例: 時短レシピ、キャンプ飯）を付けて、検索で見つかりやすくする</label><br>
							1日に <input type="number" name="ai_tags_per_day" min="10" max="2000" value="<?php echo (int) $s['ai_tags_per_day']; ?>" class="small-text"> 人まで
							<p class="description">APIキーは「記事の自動投稿」と同じものを使います。料金の目安は1人あたり1円前後（まとめて依頼する方式）。紹介文が変わった人は付け直します。</p>
						</td>
					</tr>
				</table>
				<?php submit_button( '設定を保存' ); ?>
			</form>
		</div>
		<?php
	}
}
