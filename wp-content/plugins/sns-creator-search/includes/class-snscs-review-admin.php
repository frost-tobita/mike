<?php
/**
 * 管理画面「発信者 → 審査」: 発信者からの申請を確認し、承認または差し戻す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Review_Admin {

	const PAGE       = 'snscs-review';
	const CAPABILITY = 'edit_others_posts';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_snscs_review', array( __CLASS__, 'handle' ) );
	}

	public static function list_url( $status = SNSCS_Submissions::STATUS_PENDING ) {
		return admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::PAGE . '&status=' . $status );
	}

	public static function detail_url( $id ) {
		return admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::PAGE . '&submission=' . (int) $id );
	}

	public static function add_menu() {
		$pending = SNSCS_Submissions::count_by_status( SNSCS_Submissions::STATUS_PENDING );
		$bubble  = $pending ? sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>', $pending ) : '';

		add_submenu_page(
			'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE,
			'審査',
			'審査' . $bubble,
			self::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 表示の切り替えのみ。
		$id     = isset( $_GET['submission'] ) ? absint( $_GET['submission'] ) : 0;
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : SNSCS_Submissions::STATUS_PENDING;
		$done   = isset( $_GET['snscs_done'] ) ? sanitize_key( $_GET['snscs_done'] ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		echo '<div class="wrap snscs-review-admin">';
		self::styles();

		if ( 'approved' === $done ) {
			echo '<div class="notice notice-success is-dismissible"><p>承認して掲載しました。発信者にメールでお知らせしました。</p></div>';
		} elseif ( 'rejected' === $done ) {
			echo '<div class="notice notice-success is-dismissible"><p>差し戻しました。理由を発信者にメールでお知らせしました。</p></div>';
		}

		$error = get_transient( 'snscs_review_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'snscs_review_error_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		$row = $id ? SNSCS_Submissions::get( $id ) : null;
		if ( $row ) {
			self::render_detail( $row );
		} else {
			self::render_list( array_key_exists( $status, SNSCS_Submissions::status_labels() ) ? $status : SNSCS_Submissions::STATUS_PENDING, $paged );
		}

		echo '</div>';
	}

	private static function render_list( $status, $paged ) {
		$per_page = 20;
		$result   = SNSCS_Submissions::query( $status, $paged, $per_page );
		?>
		<h1>審査</h1>
		<p>発信者からの新規登録・変更の申請です。承認すると内容がすぐ公開されます。</p>

		<ul class="subsubsub">
			<?php
			$links = array();
			foreach ( SNSCS_Submissions::status_labels() as $key => $label ) {
				$links[] = sprintf(
					'<li><a href="%s"%s>%s <span class="count">(%s)</span></a></li>',
					esc_url( self::list_url( $key ) ),
					$key === $status ? ' class="current" aria-current="page"' : '',
					esc_html( $label ),
					esc_html( number_format_i18n( SNSCS_Submissions::count_by_status( $key ) ) )
				);
			}
			echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</ul>

		<table class="widefat striped snscs-review-table">
			<thead>
				<tr>
					<th>申請日時</th>
					<th>表示名</th>
					<th>種類</th>
					<th>申請者</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $result['items'] ) ) : ?>
					<tr><td colspan="5">
						<?php echo SNSCS_Submissions::STATUS_PENDING === $status ? '審査待ちの申請はありません。' : '該当する申請はありません。'; ?>
					</td></tr>
				<?php endif; ?>
				<?php foreach ( $result['items'] as $row ) : ?>
					<?php $user = get_userdata( $row['user_id'] ); ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $row['created_at'], 'Y/m/d H:i' ) ); ?></td>
						<td><strong><a href="<?php echo esc_url( self::detail_url( $row['id'] ) ); ?>"><?php echo esc_html( $row['data']['name'] ); ?></a></strong></td>
						<td><?php self::type_badge( $row['type'] ); ?></td>
						<td><?php echo $user ? esc_html( $user->user_email ) : '（削除されたユーザー）'; ?></td>
						<td><a class="button<?php echo SNSCS_Submissions::STATUS_PENDING === $status ? ' button-primary' : ''; ?>" href="<?php echo esc_url( self::detail_url( $row['id'] ) ); ?>"><?php echo SNSCS_Submissions::STATUS_PENDING === $status ? '審査する' : '内容を見る'; ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $result['total'] / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div></div>';
		}
	}

	private static function render_detail( array $row ) {
		$user      = get_userdata( $row['user_id'] );
		$is_update = SNSCS_Submissions::TYPE_UPDATE === $row['type'];
		$current   = $is_update && $row['creator_id'] ? SNSCS_Submissions::creator_data( $row['creator_id'] ) : null;
		$requested = $row['data'];
		$labels    = SNSCS_Submissions::status_labels();
		$before    = $current ? SNSCS_Submissions::describe( $current ) : array();
		$after     = SNSCS_Submissions::describe( $requested );
		$changed   = 0;
		?>
		<p><a href="<?php echo esc_url( self::list_url( $row['status'] ) ); ?>">&larr; 一覧に戻る</a></p>
		<h1>
			<?php echo esc_html( $requested['name'] ); ?>
			<?php self::type_badge( $row['type'] ); ?>
		</h1>
		<p class="snscs-review-meta">
			状態: <strong><?php echo esc_html( $labels[ $row['status'] ] ); ?></strong>
			／ 申請日時: <?php echo esc_html( get_date_from_gmt( $row['created_at'], 'Y/m/d H:i' ) ); ?>
			／ 申請者: <?php echo $user ? esc_html( $user->user_email ) : '（削除されたユーザー）'; ?>
			<?php if ( $row['creator_id'] && 'publish' === get_post_status( $row['creator_id'] ) ) : ?>
				／ <a href="<?php echo esc_url( get_permalink( $row['creator_id'] ) ); ?>" target="_blank">掲載中のページ</a>
			<?php endif; ?>
		</p>

		<table class="widefat snscs-diff">
			<thead>
				<tr>
					<th class="snscs-diff__item">項目</th>
					<?php if ( $current ) : ?>
						<th>現在の掲載内容</th>
					<?php endif; ?>
					<th><?php echo $current ? '申請された内容' : '申請内容'; ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $after as $label => $value ) : ?>
					<?php
					$is_changed = $current && $before[ $label ] !== $value;
					$changed   += $is_changed ? 1 : 0;
					?>
					<tr class="<?php echo $is_changed ? 'snscs-diff--changed' : ''; ?>">
						<th scope="row">
							<?php echo esc_html( $label ); ?>
							<?php if ( $is_changed ) : ?>
								<span class="snscs-diff__tag">変更</span>
							<?php endif; ?>
						</th>
						<?php if ( $current ) : ?>
							<td><?php self::render_cell( $label, $current ); ?></td>
						<?php endif; ?>
						<td><?php self::render_cell( $label, $requested ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $current ) : ?>
			<p><?php echo esc_html( sprintf( '変更された項目: %d件（黄色の行）', $changed ) ); ?></p>
		<?php endif; ?>

		<?php if ( SNSCS_Submissions::STATUS_PENDING === $row['status'] ) : ?>
			<div class="snscs-decision">
				<h2>審査結果</h2>
				<p>SNSのリンクを開いて、本人のアカウントであること・フォロワー数が大きく違わないことを確認してください。</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="snscs_review">
					<input type="hidden" name="submission" value="<?php echo (int) $row['id']; ?>">
					<?php wp_nonce_field( 'snscs_review_' . $row['id'], 'snscs_nonce' ); ?>
					<p>
						<label for="snscs-note"><strong>差し戻す場合の理由</strong>（発信者にメールで届きます）</label><br>
						<textarea id="snscs-note" name="note" rows="4" class="large-text" placeholder="例: Instagramのアカウントが見つかりませんでした。ハンドル名をご確認ください。"></textarea>
					</p>
					<p>
						<button type="submit" name="decision" value="approve" class="button button-primary button-hero">承認して掲載する</button>
						<button type="submit" name="decision" value="reject" class="button button-hero">差し戻す</button>
					</p>
				</form>
			</div>
		<?php else : ?>
			<?php $reviewer = get_userdata( $row['reviewer_id'] ); ?>
			<div class="snscs-decision">
				<h2>審査結果: <?php echo esc_html( $labels[ $row['status'] ] ); ?></h2>
				<p>
					<?php echo esc_html( $row['reviewed_at'] ? get_date_from_gmt( $row['reviewed_at'], 'Y/m/d H:i' ) : '' ); ?>
					<?php echo $reviewer ? esc_html( '（担当: ' . $reviewer->display_name . '）' ) : ''; ?>
				</p>
				<?php if ( '' !== $row['note'] ) : ?>
					<p><strong>差し戻しの理由:</strong><br><?php echo nl2br( esc_html( $row['note'] ) ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * 表の1マス。SNSは確認しやすいようリンクにし、写真は画像で見せる。
	 */
	private static function render_cell( $label, array $data ) {
		if ( '写真' === $label ) {
			$url = $data['thumbnail_id'] ? wp_get_attachment_image_url( $data['thumbnail_id'], 'thumbnail' ) : '';
			echo $url ? '<img src="' . esc_url( $url ) . '" alt="" width="64" height="64" class="snscs-diff__photo">' : '（なし）';
			return;
		}

		$platform = array_search( $label, snscs_platforms(), true );
		if ( false !== $platform ) {
			if ( ! isset( $data['accounts'][ $platform ] ) ) {
				echo '（なし）';
				return;
			}
			$account = $data['accounts'][ $platform ];
			printf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a> <span class="snscs-diff__followers">%s人</span>',
				esc_url( snscs_profile_url( $platform, $account['handle'] ) ),
				esc_html( snscs_display_handle( $platform, $account['handle'] ) ),
				esc_html( number_format_i18n( $account['followers'] ) )
			);
			return;
		}

		$described = SNSCS_Submissions::describe( $data );
		echo nl2br( esc_html( $described[ $label ] ) );
	}

	private static function type_badge( $type ) {
		$is_new = SNSCS_Submissions::TYPE_NEW === $type;
		printf( '<span class="snscs-type snscs-type--%s">%s</span>', esc_attr( $type ), $is_new ? '新規登録' : '変更' );
	}

	public static function handle() {
		$id = isset( $_POST['submission'] ) ? absint( $_POST['submission'] ) : 0;

		if ( ! current_user_can( self::CAPABILITY ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_review_' . $id ) ) {
			wp_die( 'この操作は許可されていません。' );
		}

		$decision = isset( $_POST['decision'] ) ? sanitize_key( $_POST['decision'] ) : '';
		$note     = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		if ( 'approve' === $decision ) {
			$result = SNSCS_Submissions::approve( $id, get_current_user_id() );
			$done   = 'approved';
		} elseif ( 'reject' === $decision ) {
			$result = SNSCS_Submissions::reject( $id, get_current_user_id(), $note );
			$done   = 'rejected';
		} else {
			$result = new WP_Error( 'snscs_decision', '承認か差し戻しを選んでください。' );
		}

		if ( is_wp_error( $result ) ) {
			set_transient( 'snscs_review_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS );
			wp_safe_redirect( self::detail_url( $id ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'snscs_done', $done, self::list_url() ) );
		exit;
	}

	private static function styles() {
		?>
		<style>
			.snscs-type { display: inline-block; margin-left: 8px; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: normal; vertical-align: middle; }
			.snscs-type--new { background: #d7f0dd; color: #14532d; }
			.snscs-type--update { background: #dbe8fb; color: #1e3a8a; }
			.snscs-review-meta { color: #50575e; }
			.snscs-diff { max-width: 1100px; margin-top: 16px; }
			.snscs-diff th, .snscs-diff td { padding: 10px 12px; vertical-align: top; }
			.snscs-diff__item { width: 140px; }
			.snscs-diff tbody th { font-weight: 600; }
			.snscs-diff--changed th, .snscs-diff--changed td { background: #fff8d6; }
			.snscs-diff__tag { display: inline-block; margin-left: 6px; padding: 0 6px; border-radius: 3px; background: #dba617; color: #fff; font-size: 11px; font-weight: normal; }
			.snscs-diff__photo { border-radius: 50%; object-fit: cover; }
			.snscs-diff__followers { color: #50575e; }
			.snscs-decision { max-width: 1100px; margin-top: 24px; padding: 4px 20px 12px; border: 1px solid #c3c4c7; background: #fff; }
		</style>
		<?php
	}
}
