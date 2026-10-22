<?php
/**
 * 管理画面「発信者 → 表示順の設定」: おすすめ順の重み付け、上位と「要確認」の発信者の確認。
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

		$settings = SNSCS_Scores::settings();
		$signals  = SNSCS_Scores::signals();
		$updated  = (int) get_option( 'snscs_scores_updated' );
		$top      = SNSCS_Scores::top( 20 );
		$flagged  = SNSCS_Scores::top( 50, true );
		$labels   = SNSCS_Scores::flag_labels();
		$total_w  = max( 1, array_sum( array_map( 'intval', $settings['weights'] ) ) );
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

			<div style="max-width: 1000px; padding: 4px 20px 12px; margin-top: 16px; border: 1px solid #c3c4c7; background: #fff">
				<h2>おすすめ順の決め方</h2>
				<p><?php echo count( $signals ); ?>つの点数を、それぞれ<strong>全体の中での順位</strong>から0〜100点（1位が100点、最下位が0点）にし、下の重みで合わせた<strong>総合点</strong>の高い順に並べます。検索結果の「おすすめ順」、おすすめの発信者、似ている発信者に使われます。</p>
				<ul style="list-style: disc; padding-left: 1.4em">
					<li>紹介リンクとページ閲覧は、<strong>最近の動きほど重く</strong>数えます（<?php echo (int) $settings['half_life']; ?>日前の動きは半分）。</li>
					<li>滞在時間とクリック率は、見た人が少ないと偶然に左右されるため、全体の平均に寄せて計算します。</li>
					<li>ロボット、発信者本人、運営のアクセスは数えません。同じ人の同じ日のアクセスは1回だけ数えます。</li>
					<li>機械的な水増しの疑いがある場合は点数を半分にし、下の「要確認」に表示します。</li>
				</ul>
				<p>点数は1時間ごとに自動で計算し直します。</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="snscs_ranking_save">
				<?php wp_nonce_field( 'snscs_ranking_save', 'snscs_nonce' ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( $signals as $key => $signal ) : ?>
						<tr>
							<th scope="row"><label for="snscs-w-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $signal['label'] ); ?>の重み</label></th>
							<td>
								<input id="snscs-w-<?php echo esc_attr( $key ); ?>" name="weights[<?php echo esc_attr( $key ); ?>]" type="number" min="0" max="100" step="1" value="<?php echo (int) $settings['weights'][ $key ]; ?>" class="small-text">
								<span class="description"><?php echo esc_html( $signal['desc'] ); ?>（いまの割合: <?php echo esc_html( number_format_i18n( (int) $settings['weights'][ $key ] / $total_w * 100 ) ); ?>%）</span>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="snscs-days">数える期間</label></th>
						<td><input id="snscs-days" name="days" type="number" min="1" max="90" step="1" value="<?php echo (int) $settings['days']; ?>" class="small-text"> 日間 <span class="description">（紹介リンク・閲覧・滞在・クリック）</span></td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-tiers">紹介ネットワークの段数</label></th>
						<td>
							<select id="snscs-tiers" name="tiers">
								<?php for ( $t = 1; $t <= SNSCS_Invites::MAX_TIERS; $t++ ) : ?>
									<option value="<?php echo (int) $t; ?>" <?php selected( (int) $settings['tiers'], $t ); ?>><?php echo (int) $t; ?>段目まで</option>
								<?php endfor; ?>
							</select>
							<span class="description">招待した人（1段目）、その人が招待した人（2段目）…を何段目まで数えるか</span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-decay">1段深くなるごとの割合</label></th>
						<td><input id="snscs-decay" name="tier_decay" type="number" min="0" max="100" step="5" value="<?php echo (int) $settings['tier_decay']; ?>" class="small-text"> % <span class="description">（50%なら 1段目100%、2段目50%、3段目25%…）</span></td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-bonus">招待1人あたりのボーナス</label></th>
						<td><input id="snscs-bonus" name="join_bonus" type="number" min="0" max="1000" step="1" value="<?php echo (int) $settings['join_bonus']; ?>" class="small-text"> 人分 <span class="description">（招待した人が掲載されたとき、紹介リンクから何人来たのと同じに数えるか）</span></td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-half">半分に数えるまでの日数</label></th>
						<td><input id="snscs-half" name="half_life" type="number" min="1" max="90" step="1" value="<?php echo (int) $settings['half_life']; ?>" class="small-text"> 日 <span class="description">（小さいほど、最近の動きを重視します）</span></td>
					</tr>
				</table>
				<p class="description">重みは割合で使われます（例: すべて同じ数にすると均等）。0にするとその点数を使いません。紹介リンクとフォロワーは最初 1:1（25:25）です。</p>
				<p class="description">「サイトの利用日数・利用時間」は、発信者がログインしてこのサイトを使った状況です。水増しを防ぐため、利用時間は画面を見て操作している時間だけを1日30分まで、利用日数は1日1回まで数えます。</p>
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
			<?php self::render_table( $top, $settings['days'] ); ?>

			<h2 style="margin-top: 32px">要確認の発信者</h2>
			<p>機械的な水増しの疑いがあり、点数を下げている発信者です。発信者ページや紹介リンクの使われ方を確認し、必要なら掲載停止などを検討してください。</p>
			<?php if ( ! $flagged ) : ?>
				<p>いまはありません。</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width: 1100px">
					<thead><tr><th>発信者</th><th>理由</th><th style="text-align: right">紹介リンク</th><th style="text-align: right">閲覧</th><th style="text-align: right">平均滞在</th></tr></thead>
					<tbody>
						<?php foreach ( $flagged as $row ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_post_link( $row['creator_id'] ) ); ?>"><?php echo esc_html( $row['post_title'] ); ?></a></td>
								<td>
									<?php foreach ( array_filter( explode( ',', $row['flags'] ) ) as $flag ) : ?>
										<div><?php echo esc_html( isset( $labels[ $flag ] ) ? $labels[ $flag ] : $flag ); ?></div>
									<?php endforeach; ?>
								</td>
								<td style="text-align: right"><?php echo esc_html( number_format_i18n( $row['inflow'] ) ); ?>人</td>
								<td style="text-align: right"><?php echo esc_html( number_format_i18n( $row['views'] ) ); ?>人</td>
								<td style="text-align: right"><?php echo esc_html( number_format_i18n( $row['dwell_avg'], 1 ) ); ?>秒</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_table( array $rows, $days ) {
		$signals = SNSCS_Scores::signals();
		?>
		<div style="overflow-x: auto; max-width: 1300px">
			<table class="widefat striped">
				<thead>
					<tr>
						<th>順位</th>
						<th>発信者</th>
						<th style="text-align: right">紹介（<?php echo (int) $days; ?>日）</th>
						<th style="text-align: right">合計フォロワー</th>
						<th style="text-align: right">閲覧</th>
						<th style="text-align: right">平均滞在</th>
						<th style="text-align: right">クリック率</th>
						<th style="text-align: right">記事</th>
						<th style="text-align: right">利用日数</th>
						<th style="text-align: right">利用時間</th>
						<th style="text-align: right">招待</th>
						<th style="text-align: right">総合点</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="12">まだ点数がありません。「今すぐ計算し直す」を押してください。</td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $i => $row ) : ?>
						<?php $p = json_decode( (string) $row['points'], true ); ?>
						<tr>
							<td><?php echo (int) $i + 1; ?></td>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $row['creator_id'] ) ); ?>"><?php echo esc_html( $row['post_title'] ); ?></a>
								<?php if ( '' !== $row['flags'] ) : ?>
									<span style="color: #b32d2e">要確認</span>
								<?php endif; ?>
							</td>
							<?php
							$cells = array(
								'referral'  => number_format_i18n( $row['inflow'] ) . '人',
								'followers' => number_format_i18n( $row['followers'] ),
								'views'     => number_format_i18n( $row['views'] ) . '人',
								'dwell'     => number_format_i18n( $row['dwell_avg'], 1 ) . '秒',
								'clicks'    => number_format_i18n( $row['click_rate'] * 100, 1 ) . '%',
								'articles'  => number_format_i18n( $row['articles'], 1 ),
								'site_days' => number_format_i18n( $row['usage_days'] ) . '日',
								'site_time' => number_format_i18n( round( $row['usage_time'] / 60 ) ) . '分',
								'network'   => number_format_i18n( $row['invites'] ) . '人',
								'favorites' => number_format_i18n( $row['favs'] ) . '人',
							);
							?>
							<?php foreach ( $cells as $signal => $value ) : ?>
								<td style="text-align: right">
									<?php echo esc_html( $value ); ?>
									<br><span class="description"><?php echo esc_html( number_format_i18n( isset( $p[ $signal ] ) ? $p[ $signal ] : 0, 1 ) ); ?>点</span>
								</td>
							<?php endforeach; ?>
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

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check() で確認済み。
		$weights = array();
		foreach ( array_keys( SNSCS_Scores::signals() ) as $key ) {
			$weights[ $key ] = isset( $_POST['weights'][ $key ] ) ? min( 100, max( 0, (int) $_POST['weights'][ $key ] ) ) : 0;
		}
		update_option(
			'snscs_score_settings',
			array(
				'weights'   => $weights,
				'days'      => isset( $_POST['days'] ) ? min( 90, max( 1, (int) $_POST['days'] ) ) : 30,
				'half_life'  => isset( $_POST['half_life'] ) ? min( 90, max( 1, (int) $_POST['half_life'] ) ) : 14,
				'tiers'      => isset( $_POST['tiers'] ) ? min( SNSCS_Invites::MAX_TIERS, max( 1, (int) $_POST['tiers'] ) ) : 3,
				'tier_decay' => isset( $_POST['tier_decay'] ) ? min( 100, max( 0, (int) $_POST['tier_decay'] ) ) : 50,
				'join_bonus' => isset( $_POST['join_bonus'] ) ? min( 1000, max( 0, (int) $_POST['join_bonus'] ) ) : 10,
			)
		);
		// phpcs:enable

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
