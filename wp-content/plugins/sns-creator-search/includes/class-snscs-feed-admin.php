<?php
/**
 * 記事のおすすめの学習と管理画面（管理画面「発信者 → 記事のおすすめ」）。
 *
 * ■ 学習（毎晩）
 *   おすすめに表示した記録（直近30日）を、「最後まで読んだ = 1 / それ以外 = 0」の例として、
 *   SNSCS_Feed の点数の式の重みを、確率的勾配降下法で直す（ロジスティック回帰。重みはマイナスにしない）。
 *   表示ごとの番号で8:2に分け、学習に使わなかった2割で「読まれる記事を上に出せているか」（AUC）を比べ、
 *   学習した重みが初期の重みより0.01以上良く、読了の例が30件以上あるときだけ使う。
 * ■ 管理画面
 *   直近30日の数字（表示・開いた割合・読了率）、重みの比較、使い方（自動 / 初期の重みだけ）、
 *   ログインしていない人へのおすすめの例（点数と理由つき）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Feed_Admin {

	const ADMIN_PAGE = 'snscs-feed';
	const MIN_POS    = 30;
	const MIN_GAIN   = 0.01;

	public static function init() {
		add_action( 'snscs_nightly_engine', array( __CLASS__, 'train' ), 30 );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
		add_action( 'admin_post_snscs_feed_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_snscs_feed_train', array( __CLASS__, 'handle_train' ) );
	}

	/* --------------------------------------------------------------------
	 * 学習
	 * ------------------------------------------------------------------ */

	/**
	 * 並べ方のよさ（AUC: 読了した記事が、読了しなかった記事より上の点になる確率）。
	 *
	 * @param float[] $scores
	 * @param int[]   $labels
	 */
	public static function auc( array $scores, array $labels ) {
		$pairs = array();
		foreach ( $scores as $i => $s ) {
			$pairs[] = array( $s, $labels[ $i ] );
		}
		usort(
			$pairs,
			function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);
		$pos  = 0;
		$neg  = 0;
		$sum  = 0.0;
		$rank = 1;
		$n    = count( $pairs );
		for ( $i = 0; $i < $n; ) {
			// 同じ点は順位を平均する。
			$j = $i;
			while ( $j < $n && $pairs[ $j ][0] === $pairs[ $i ][0] ) {
				++$j;
			}
			$avg = ( $rank + $rank + ( $j - $i ) - 1 ) / 2;
			for ( $k = $i; $k < $j; $k++ ) {
				if ( $pairs[ $k ][1] ) {
					$sum += $avg;
					++$pos;
				} else {
					++$neg;
				}
			}
			$rank += $j - $i;
			$i     = $j;
		}
		return ( $pos && $neg ) ? ( $sum - $pos * ( $pos + 1 ) / 2 ) / ( $pos * $neg ) : 0.5;
	}

	/**
	 * @return array 学習の結果（保存もする）。
	 */
	public static function train() {
		global $wpdb;
		$since = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . SNSCS_Feed::KEEP_LOG . ' days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT qid, x, readed FROM ' . SNSCS_Feed::log_table() . ' WHERE day >= %s ORDER BY id DESC LIMIT 60000', $since ), ARRAY_A );
		$keys  = array_keys( SNSCS_Feed::FEATURES );
		$train = array();
		$valid = array();
		foreach ( $rows as $row ) {
			$vals = array_map( 'floatval', explode( ',', $row['x'] ) );
			if ( count( $vals ) !== count( $keys ) ) {
				continue;
			}
			$example = array( array_combine( $keys, $vals ), (int) $row['readed'] );
			// 同じ表示（qid）の記事は同じ組に入れる。
			if ( hexdec( substr( $row['qid'], 0, 4 ) ) % 5 === 0 ) {
				$valid[] = $example;
			} else {
				$train[] = $example;
			}
		}
		$model = array_merge( array( 'mode' => 'auto' ), (array) get_option( SNSCS_Feed::MODEL, array() ) );
		$pos_v = count( array_filter( array_column( $valid, 1 ) ) );
		$pos_t = count( array_filter( array_column( $train, 1 ) ) );

		// 確率的勾配降下法（初期の重みから始め、8周。正則化は初期の重みに寄せる）。
		$w    = SNSCS_Feed::DEFAULT_WEIGHTS;
		$rate = 0.05;
		$l2   = 0.001;
		mt_srand( 42 );
		for ( $epoch = 0; $epoch < 8 && $pos_t; $epoch++ ) {
			shuffle( $train );
			foreach ( $train as $ex ) {
				$err      = $ex[1] - SNSCS_Feed::predict( $ex[0], $w );
				$w['bias'] += $rate * $err;
				foreach ( $keys as $k ) {
					$w[ $k ] += $rate * ( $err * $ex[0][ $k ] - $l2 * ( $w[ $k ] - SNSCS_Feed::DEFAULT_WEIGHTS[ $k ] ) );
					$w[ $k ]  = max( 0.0, $w[ $k ] );
				}
			}
			$rate *= 0.7;
		}
		mt_srand();

		$labels  = array_column( $valid, 1 );
		$default = array();
		$learned = array();
		foreach ( $valid as $ex ) {
			$default[] = SNSCS_Feed::predict( $ex[0], SNSCS_Feed::DEFAULT_WEIGHTS );
			$learned[] = SNSCS_Feed::predict( $ex[0], $w );
		}
		$auc_d = self::auc( $default, $labels );
		$auc_l = self::auc( $learned, $labels );
		$use   = $pos_v >= self::MIN_POS && $auc_l >= $auc_d + self::MIN_GAIN;

		$model = array_merge(
			$model,
			array(
				'weights'     => array_map(
					function ( $v ) {
						return round( $v, 4 );
					},
					$w
				),
				'auc_default' => round( $auc_d, 4 ),
				'auc_learned' => round( $auc_l, 4 ),
				'examples'    => count( $train ) + count( $valid ),
				'positives'   => $pos_t + $pos_v,
				'valid_pos'   => $pos_v,
				'use'         => $use,
				'trained_at'  => current_time( 'mysql' ),
			)
		);
		update_option( SNSCS_Feed::MODEL, $model, false );
		return $model;
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, '記事のおすすめ', '記事のおすすめ', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	private static function url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::ADMIN_PAGE ) );
	}

	private static function verify( $action ) {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['snscs_nonce'] ), $action ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
	}

	public static function handle_save() {
		self::verify( 'snscs_feed_save' );
		$model         = (array) get_option( SNSCS_Feed::MODEL, array() );
		$model['mode'] = isset( $_POST['mode'] ) && 'manual' === $_POST['mode'] ? 'manual' : 'auto'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify() で確認済み。
		update_option( SNSCS_Feed::MODEL, $model, false );
		wp_safe_redirect( self::url( array( 'snscs_msg' => 'saved' ) ) );
		exit;
	}

	public static function handle_train() {
		self::verify( 'snscs_feed_train' );
		self::train();
		SNSCS_Feed::clear_stats();
		wp_safe_redirect( self::url( array( 'snscs_msg' => 'trained' ) ) );
		exit;
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$model  = array_merge( array( 'mode' => 'auto' ), (array) get_option( SNSCS_Feed::MODEL, array() ) );
		$stats  = SNSCS_Feed::stats();
		$g      = $stats['global'];
		$weights = SNSCS_Feed::weights();
		$sample = SNSCS_Feed::items( array( 'user' => 0, 'limit' => 10, 'log' => false ) );
		$pct    = function ( $a, $b ) {
			return $b ? number_format_i18n( $a / $b * 100, 1 ) . '%' : '—';
		};
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['snscs_msg'] ) ? sanitize_key( $_GET['snscs_msg'] ) : '';
		?>
		<div class="wrap">
			<h1>記事のおすすめ</h1>
			<?php if ( 'saved' === $msg ) : ?>
				<div class="notice notice-success"><p>保存しました。</p></div>
			<?php elseif ( 'trained' === $msg ) : ?>
				<div class="notice notice-success"><p>学習しました。下の「学習の結果」を確認してください。</p></div>
			<?php endif; ?>
			<p>「みんなの記事」の「おすすめ」、メンバーページ、記事の下の「次に読むおすすめ」の並び順です。X のように「フォロー中」と「まだ知らない記事」を混ぜ、note のように「最後まで読まれる記事」を高く、Google のように「信頼できる書き手」と不正対策を効かせます。毎晩、実際に読まれたかどうかから重みを学習します。</p>

			<h2>直近60日の数字</h2>
			<table class="widefat striped" style="max-width: 760px">
				<tbody>
					<tr><th style="width: 260px">おすすめに表示</th><td><?php echo esc_html( number_format_i18n( $g['imps'] ) ); ?>回</td></tr>
					<tr><th>おすすめから開いた</th><td><?php echo esc_html( number_format_i18n( $g['opens'] ) . '回（' . $pct( $g['opens'], $g['imps'] ) . '）' ); ?></td></tr>
					<tr><th>記事を見た人</th><td><?php echo esc_html( number_format_i18n( $g['views'] ) ); ?>人</td></tr>
					<tr><th>最後まで読んだ人（読了率）</th><td><?php echo esc_html( number_format_i18n( $g['reads'] ) . '人（' . $pct( $g['reads'], $g['views'] ) . '）' ); ?></td></tr>
					<tr><th>対象の記事</th><td><?php echo esc_html( number_format_i18n( count( $stats['articles'] ) ) ); ?>本（直近120日に公開）</td></tr>
				</tbody>
			</table>

			<h2>重みと学習の結果</h2>
			<p>
				<?php if ( empty( $model['trained_at'] ) ) : ?>
					まだ学習していません（毎晩自動で学習します）。
				<?php else : ?>
					<?php echo esc_html( $model['trained_at'] ); ?> に学習。例 <?php echo esc_html( number_format_i18n( $model['examples'] ) ); ?>件（読了 <?php echo esc_html( number_format_i18n( $model['positives'] ) ); ?>件）。
					並べ方のよさ（AUC。0.5 = でたらめ、1 = 完ぺき）: 初期の重み <strong><?php echo esc_html( $model['auc_default'] ); ?></strong> → 学習した重み <strong><?php echo esc_html( $model['auc_learned'] ); ?></strong>。
					<?php echo $model['use'] ? '<strong>学習した重みを使っています。</strong>' : esc_html( '初期の重みを使っています（学習した重みが' . self::MIN_GAIN . '以上良く、確認用の読了が' . self::MIN_POS . '件以上あるときに切り替えます）。' ); ?>
				<?php endif; ?>
			</p>
			<table class="widefat striped" style="max-width: 760px">
				<thead><tr><th>特徴</th><th>初期の重み</th><th>学習した重み</th><th>いま使っている重み</th></tr></thead>
				<tbody>
					<?php foreach ( array_merge( array( 'bias' => '基準（切片）' ), SNSCS_Feed::FEATURES ) as $key => $label ) : ?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><?php echo esc_html( SNSCS_Feed::DEFAULT_WEIGHTS[ $key ] ); ?></td>
							<td><?php echo isset( $model['weights'][ $key ] ) ? esc_html( $model['weights'][ $key ] ) : '—'; ?></td>
							<td><strong><?php echo esc_html( round( $weights[ $key ], 4 ) ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>" style="margin-top: 16px">
				<input type="hidden" name="action" value="snscs_feed_save">
				<?php wp_nonce_field( 'snscs_feed_save', 'snscs_nonce' ); ?>
				<label><input type="radio" name="mode" value="auto" <?php checked( 'manual' !== $model['mode'] ); ?>> 自動（学習した重みが良ければ使う。おすすめ）</label><br>
				<label><input type="radio" name="mode" value="manual" <?php checked( 'manual', $model['mode'] ); ?>> 初期の重みだけを使う</label>
				<?php submit_button( '保存', 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>" style="margin-top: 8px">
				<input type="hidden" name="action" value="snscs_feed_train">
				<?php wp_nonce_field( 'snscs_feed_train', 'snscs_nonce' ); ?>
				<?php submit_button( 'いま学習する', 'secondary', 'submit', false ); ?>
			</form>

			<h2>おすすめの例（ログインしていない人）</h2>
			<table class="widefat striped" style="max-width: 1000px">
				<thead><tr><th>順</th><th>記事</th><th>読了の予測</th><th>理由</th><th>効いている特徴</th></tr></thead>
				<tbody>
					<?php if ( ! $sample ) : ?>
						<tr><td colspan="5">まだ記事がありません。</td></tr>
					<?php endif; ?>
					<?php foreach ( $sample as $i => $item ) : ?>
						<?php
						$top = array();
						foreach ( SNSCS_Feed::FEATURES as $key => $label ) {
							$top[ $label ] = $weights[ $key ] * $item['x'][ $key ];
						}
						arsort( $top );
						?>
						<tr>
							<td><?php echo (int) ( $i + 1 ); ?></td>
							<td><a href="<?php echo esc_url( get_permalink( $item['id'] ) ); ?>" target="_blank"><?php echo esc_html( get_the_title( $item['id'] ) ); ?></a></td>
							<td><?php echo esc_html( number_format_i18n( $item['score'] * 100, 1 ) . '%' ); ?></td>
							<td><?php echo esc_html( $item['reason'] ); ?></td>
							<td><?php echo esc_html( implode( '、', array_slice( array_keys( array_filter( $top ) ), 0, 3 ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
