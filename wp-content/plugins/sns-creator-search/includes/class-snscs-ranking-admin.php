<?php
/**
 * 管理画面「発信者 → 表示順の設定」: おすすめ順の重み付けと、現在の上位の確認。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Ranking_Admin {

	const PAGE       = 'snscs-ranking';
	const CAPABILITY = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_snscs_ranking_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_snscs_ranking_recalc', array( __CLASS__, 'recalc' ) );
	}

	public static function url() {
		return admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::PAGE );
	}

	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE,
			'表示順の設定',
			'表示順の設定',
			self::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$wi      = SNSCS_Scores::weight_inflow();
		$wf      = SNSCS_Scores::weight_followers();
		$days    = SNSCS_Scores::inflow_days();
		$updated = (int) get_option( 'snscs_scores_updated' );
		$top     = SNSCS_Scores::top( 20 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$done = isset( $_GET['snscs_done'] ) ? sanitize_key( $_GET['snscs_done'] ) : '';
		?>
		<div class="wrap">
			<h1>表示順の設定</h1>

			<?php if ( 'saved' === $done ) : ?>
				<div class="notice notice-success is-dismissible"><p>設定を保存し、全員の点数を計算し直しました。</p></div>
			<?php elseif ( 'recalculated' === $done ) : ?>
				<div class="notice notice-success is-dismissible"><p>全員の点数を計算し直しました。</p></div>
			<?php endif; ?>

			<div style="max-width: 900px; padding: 4px 20px 12px; margin-top: 16px; border: 1px solid #c3c4c7; background: #fff">
				<h2>おすすめ順の決め方</h2>
				<ol>
					<li><strong>① 紹介流入点</strong>: 発信者ごとの紹介リンクから、直近<?php echo (int) $days; ?>日間に来た人数の順位を0〜100点にしたもの</li>
					<li><strong>② フォロワー点</strong>: 全SNSの合計フォロワー数の順位を0〜100点にしたもの</li>
				</ol>
				<p>1位が100点、最下位が0点、同じ数の人は同じ点です。<br>
				<strong>総合点 ＝（① × ①の重み ＋ ② × ②の重み）÷（重みの合計）</strong>。検索結果の「おすすめ順」はこの総合点の高い順です。</p>
				<p>紹介リンクは、同じ人の同じ日のアクセスは1回、ロボット・先読み・本人のアクセスは数えません。点数は1時間ごとに自動で計算し直します。</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="snscs_ranking_save">
				<?php wp_nonce_field( 'snscs_ranking_save', 'snscs_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="snscs-wi">① 紹介流入の重み</label></th>
						<td><input id="snscs-wi" name="weight_inflow" type="number" min="0" max="10" step="1" value="<?php echo (int) $wi; ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-wf">② フォロワー数の重み</label></th>
						<td>
							<input id="snscs-wf" name="weight_followers" type="number" min="0" max="10" step="1" value="<?php echo (int) $wf; ?>" class="small-text">
							<p class="description">例: ①1・②1 で半々。①2・②1 にすると紹介流入を2倍重視します。0にするとその項目を使いません。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-days">紹介流入を数える期間</label></th>
						<td><input id="snscs-days" name="inflow_days" type="number" min="1" max="90" step="1" value="<?php echo (int) $days; ?>" class="small-text"> 日間</td>
					</tr>
				</table>
				<?php submit_button( '保存して計算し直す' ); ?>
			</form>

			<h2>現在の上位20人</h2>
			<p>
				最終計算: <?php echo $updated ? esc_html( wp_date( 'Y/m/d H:i', $updated ) ) : '（まだ計算していません）'; ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 12px">
				<input type="hidden" name="action" value="snscs_ranking_recalc">
				<?php wp_nonce_field( 'snscs_ranking_recalc', 'snscs_nonce' ); ?>
				<button type="submit" class="button">今すぐ計算し直す</button>
			</form>
			<table class="widefat striped" style="max-width: 1100px">
				<thead>
					<tr>
						<th>順位</th>
						<th>発信者</th>
						<th style="text-align: right">紹介流入（<?php echo (int) $days; ?>日）</th>
						<th style="text-align: right">① 点</th>
						<th style="text-align: right">合計フォロワー</th>
						<th style="text-align: right">② 点</th>
						<th style="text-align: right">総合点</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $top ) ) : ?>
						<tr><td colspan="7">まだ点数がありません。「今すぐ計算し直す」を押してください。</td></tr>
					<?php endif; ?>
					<?php foreach ( $top as $i => $row ) : ?>
						<tr>
							<td><?php echo (int) $i + 1; ?></td>
							<td><a href="<?php echo esc_url( get_edit_post_link( $row['creator_id'] ) ); ?>"><?php echo esc_html( $row['post_title'] ); ?></a></td>
							<td style="text-align: right"><?php echo esc_html( number_format_i18n( $row['inflow'] ) ); ?>人</td>
							<td style="text-align: right"><?php echo esc_html( number_format_i18n( $row['inflow_points'], 1 ) ); ?></td>
							<td style="text-align: right"><?php echo esc_html( number_format_i18n( $row['followers'] ) ); ?></td>
							<td style="text-align: right"><?php echo esc_html( number_format_i18n( $row['follower_points'], 1 ) ); ?></td>
							<td style="text-align: right"><strong><?php echo esc_html( number_format_i18n( $row['score'], 1 ) ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function save() {
		self::check( 'snscs_ranking_save' );

		$clamp = function ( $key, $min, $max, $default ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check() で確認済み。
			$value = isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : $default;
			return min( $max, max( $min, $value ) );
		};

		update_option( 'snscs_weight_inflow', $clamp( 'weight_inflow', 0, 10, SNSCS_Scores::DEFAULT_WEIGHT_INFLOW ) );
		update_option( 'snscs_weight_followers', $clamp( 'weight_followers', 0, 10, SNSCS_Scores::DEFAULT_WEIGHT_FOLLOWERS ) );
		update_option( 'snscs_inflow_days', $clamp( 'inflow_days', 1, 90, SNSCS_Scores::DEFAULT_INFLOW_DAYS ) );

		SNSCS_Scores::recalculate();
		wp_safe_redirect( add_query_arg( 'snscs_done', 'saved', self::url() ) );
		exit;
	}

	public static function recalc() {
		self::check( 'snscs_ranking_recalc' );
		SNSCS_Scores::recalculate();
		wp_safe_redirect( add_query_arg( 'snscs_done', 'recalculated', self::url() ) );
		exit;
	}

	private static function check( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), $action ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
	}
}
