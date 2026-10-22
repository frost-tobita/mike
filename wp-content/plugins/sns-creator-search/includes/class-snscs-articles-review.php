<?php
/**
 * 管理画面「発信者 → 記事の審査」: 発信者が書いた記事の新規公開・変更を承認または差し戻す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Articles_Review {

	const PAGE       = 'snscs-article-review';
	const CAPABILITY = 'edit_others_posts';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_snscs_article_review', array( __CLASS__, 'handle' ) );
	}

	public static function url( $article_id = 0 ) {
		$url = admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::PAGE );
		return $article_id ? add_query_arg( 'article', (int) $article_id, $url ) : $url;
	}

	/**
	 * 審査待ちの記事（新規＋公開中の記事の変更）を、申請の古い順に。
	 *
	 * @return array<int,array{post:WP_Post,type:string,at:int}>
	 */
	public static function queue() {
		$items = array();
		foreach ( get_posts( array( 'post_type' => SNSCS_Articles::POST_TYPE, 'post_status' => 'pending', 'numberposts' => 200 ) ) as $post ) {
			$items[] = array( 'post' => $post, 'type' => 'new', 'at' => strtotime( $post->post_modified_gmt . ' UTC' ) );
		}
		$edited = get_posts(
			array(
				'post_type'   => SNSCS_Articles::POST_TYPE,
				'post_status' => 'publish',
				'meta_key'    => '_snscs_pending', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'numberposts' => 200,
			)
		);
		foreach ( $edited as $post ) {
			$pending = get_post_meta( $post->ID, '_snscs_pending', true );
			$items[] = array( 'post' => $post, 'type' => 'update', 'at' => isset( $pending['at'] ) ? (int) $pending['at'] : 0 );
		}
		usort(
			$items,
			function ( $a, $b ) {
				return $a['at'] - $b['at'];
			}
		);
		return $items;
	}

	public static function add_menu() {
		$count  = count( self::queue() );
		$bubble = $count ? sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>', $count ) : '';
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, '記事の審査', '記事の審査' . $bubble, self::CAPABILITY, self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 表示の切り替えのみ。
		$article_id = isset( $_GET['article'] ) ? absint( $_GET['article'] ) : 0;
		$done       = isset( $_GET['snscs_done'] ) ? sanitize_key( $_GET['snscs_done'] ) : '';
		// phpcs:enable

		echo '<div class="wrap">';
		if ( 'approved' === $done ) {
			echo '<div class="notice notice-success is-dismissible"><p>承認して公開しました。発信者にメールでお知らせしました。</p></div>';
		} elseif ( 'rejected' === $done ) {
			echo '<div class="notice notice-success is-dismissible"><p>差し戻しました。理由を発信者にメールでお知らせしました。</p></div>';
		}
		$error = get_transient( 'snscs_article_review_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'snscs_article_review_error_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		$article = $article_id ? get_post( $article_id ) : null;
		if ( $article && SNSCS_Articles::POST_TYPE === $article->post_type ) {
			self::render_detail( $article );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_list() {
		$queue = self::queue();
		?>
		<h1>記事の審査</h1>
		<p>発信者が書いた記事の、新しい公開と変更の申請です。承認するとすぐに公開されます。</p>
		<table class="widefat striped" style="max-width: 1100px">
			<thead><tr><th>申請日時</th><th>タイトル</th><th>発信者</th><th>種類</th><th></th></tr></thead>
			<tbody>
				<?php if ( ! $queue ) : ?>
					<tr><td colspan="5">審査待ちの記事はありません。</td></tr>
				<?php endif; ?>
				<?php foreach ( $queue as $item ) : ?>
					<?php $creator_id = SNSCS_Articles::creator_of( $item['post']->ID ); ?>
					<tr>
						<td><?php echo esc_html( $item['at'] ? wp_date( 'Y/m/d H:i', $item['at'] ) : '' ); ?></td>
						<td><strong><a href="<?php echo esc_url( self::url( $item['post']->ID ) ); ?>"><?php echo esc_html( $item['post']->post_title ); ?></a></strong></td>
						<td><?php echo esc_html( $creator_id ? get_the_title( $creator_id ) : '' ); ?></td>
						<td><?php echo 'new' === $item['type'] ? '新しい記事' : '公開中の記事の変更'; ?></td>
						<td><a class="button button-primary" href="<?php echo esc_url( self::url( $item['post']->ID ) ); ?>">審査する</a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_detail( WP_Post $article ) {
		$pending    = get_post_meta( $article->ID, '_snscs_pending', true );
		$is_update  = 'publish' === $article->post_status && is_array( $pending );
		$is_new     = 'pending' === $article->post_status;
		$creator_id = SNSCS_Articles::creator_of( $article->ID );

		$current   = array( 'title' => $article->post_title, 'content' => $article->post_content, 'thumbnail_id' => (int) get_post_thumbnail_id( $article ) );
		$requested = $is_update ? $pending : $current;
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; 一覧に戻る</a></p>
		<h1><?php echo esc_html( $requested['title'] ); ?></h1>
		<p>
			発信者: <?php echo $creator_id ? '<a href="' . esc_url( get_permalink( $creator_id ) ) . '" target="_blank">' . esc_html( get_the_title( $creator_id ) ) . '</a>' : '—'; ?>
			／ 種類: <?php echo $is_update ? '公開中の記事の変更' : ( $is_new ? '新しい記事' : '審査待ちではありません' ); ?>
		</p>

		<div style="display: flex; flex-wrap: wrap; gap: 20px; max-width: 1200px">
			<?php if ( $is_update ) : ?>
				<?php self::render_version( '今公開している内容', $current ); ?>
			<?php endif; ?>
			<?php self::render_version( $is_update ? '申請された内容' : '申請内容', $requested ); ?>
		</div>

		<?php if ( $is_update || $is_new ) : ?>
			<div style="max-width: 1100px; margin-top: 24px; padding: 4px 20px 12px; border: 1px solid #c3c4c7; background: #fff">
				<h2>審査結果</h2>
				<p>宣伝が過剰でないか、他人の権利を侵害していないか、誤解を招く内容や法律に触れる内容がないかを確認してください。</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="snscs_article_review">
					<input type="hidden" name="article" value="<?php echo (int) $article->ID; ?>">
					<?php wp_nonce_field( 'snscs_article_review_' . $article->ID, 'snscs_nonce' ); ?>
					<p>
						<label for="snscs-article-note"><strong>差し戻す場合の理由</strong>（発信者にメールで届きます）</label><br>
						<textarea id="snscs-article-note" name="note" rows="4" class="large-text"></textarea>
					</p>
					<p>
						<button type="submit" name="decision" value="approve" class="button button-primary button-hero">承認して公開する</button>
						<button type="submit" name="decision" value="reject" class="button button-hero">差し戻す</button>
					</p>
				</form>
			</div>
		<?php endif; ?>
		<?php
	}

	private static function render_version( $heading, array $version ) {
		$image = $version['thumbnail_id'] ? wp_get_attachment_image_url( $version['thumbnail_id'], 'medium' ) : '';
		?>
		<div style="flex: 1 1 460px; min-width: 0; padding: 16px 20px; border: 1px solid #c3c4c7; background: #fff">
			<h2 style="margin-top: 0"><?php echo esc_html( $heading ); ?></h2>
			<p><strong><?php echo esc_html( $version['title'] ); ?></strong></p>
			<?php if ( $image ) : ?>
				<p><img src="<?php echo esc_url( $image ); ?>" alt="" style="max-width: 100%; height: auto"></p>
			<?php endif; ?>
			<div style="line-height: 1.8"><?php echo wp_kses_post( wpautop( $version['content'] ) ); ?></div>
		</div>
		<?php
	}

	public static function handle() {
		$article_id = isset( $_POST['article'] ) ? absint( $_POST['article'] ) : 0;
		if ( ! current_user_can( self::CAPABILITY ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_article_review_' . $article_id ) ) {
			wp_die( 'この操作は許可されていません。' );
		}

		$article  = get_post( $article_id );
		$pending  = get_post_meta( $article_id, '_snscs_pending', true );
		$decision = isset( $_POST['decision'] ) ? sanitize_key( $_POST['decision'] ) : '';
		$note     = isset( $_POST['note'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) ) : '';

		$is_update = $article && 'publish' === $article->post_status && is_array( $pending );
		$is_new    = $article && 'pending' === $article->post_status;
		if ( ! $article || SNSCS_Articles::POST_TYPE !== $article->post_type || ! ( $is_update || $is_new ) ) {
			self::fail( $article_id, 'この記事は審査待ちではありません。' );
		}

		if ( 'approve' === $decision ) {
			if ( $is_update ) {
				wp_update_post( wp_slash( array( 'ID' => $article_id, 'post_title' => $pending['title'], 'post_content' => $pending['content'] ) ) );
				if ( $pending['thumbnail_id'] ) {
					set_post_thumbnail( $article_id, $pending['thumbnail_id'] );
				}
				delete_post_meta( $article_id, '_snscs_pending' );
			} else {
				wp_update_post( array( 'ID' => $article_id, 'post_status' => 'publish' ) );
			}
			delete_post_meta( $article_id, '_snscs_reject_note' );
			do_action( 'snscs_article_approved', $article_id );
			SNSCS_Scores::schedule_soon();
			wp_safe_redirect( add_query_arg( 'snscs_done', 'approved', self::url() ) );
			exit;
		}

		if ( 'reject' === $decision ) {
			if ( '' === $note ) {
				self::fail( $article_id, '差し戻しの理由を入力してください。発信者に伝わります。' );
			}
			if ( $is_update ) {
				// 発信者が直して出し直せるよう、差し戻した変更内容は残しておく。
				update_post_meta( $article_id, '_snscs_rejected_draft', $pending );
				delete_post_meta( $article_id, '_snscs_pending' );
			} else {
				wp_update_post( array( 'ID' => $article_id, 'post_status' => 'draft' ) );
			}
			update_post_meta( $article_id, '_snscs_reject_note', $note );
			do_action( 'snscs_article_rejected', $article_id, $note );
			wp_safe_redirect( add_query_arg( 'snscs_done', 'rejected', self::url() ) );
			exit;
		}

		self::fail( $article_id, '承認か差し戻しを選んでください。' );
	}

	private static function fail( $article_id, $message ) {
		set_transient( 'snscs_article_review_error_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( $article_id ) );
		exit;
	}
}
