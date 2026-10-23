<?php
/**
 * 会員が作る内容（推しリスト・質問箱）の共通の決まりと、運営の確認画面（管理画面「発信者 → 投稿の確認」）。
 *
 *   書いた人の名前: 発信者なら発信者の名前、読者ならニックネーム（メールアドレスは出さない）。
 *   文の掃除   : URL（http・www）は消す（宣伝の書き込みを防ぐ）。管理画面の「使えない言葉」を含む文は受け付けない。
 *   確認画面   : 新しい推しリスト・質問・回答を一覧にし、運営が「非表示」にできる（非表示にしたものは、
 *               ページ・一覧・サイトマップから消え、検索エンジンにも出ない）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_UGC {

	const OPTION     = 'snscs_ugc_block';
	const ADMIN_PAGE = 'snscs-ugc';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 24 );
		add_action( 'admin_post_snscs_ugc_toggle', array( __CLASS__, 'handle_toggle' ) );
		add_action( 'admin_post_snscs_ugc_words', array( __CLASS__, 'handle_words' ) );
	}

	public static function author_name( $user_id ) {
		$creator = SNSCS_Social::own_creator( $user_id );
		if ( $creator && 'publish' === get_post_status( $creator ) ) {
			return html_entity_decode( get_the_title( $creator ), ENT_QUOTES, 'UTF-8' );
		}
		return SNSCS_People::nickname( $user_id );
	}

	public static function author_url( $user_id ) {
		$creator = SNSCS_Social::own_creator( $user_id );
		if ( $creator && 'publish' === get_post_status( $creator ) ) {
			return get_permalink( $creator );
		}
		return SNSCS_People::is_public( $user_id ) ? SNSCS_People::profile_url( $user_id ) : '';
	}

	/**
	 * 文を整える（URLを消し、長さをそろえる）。
	 */
	public static function clean( $text, $max, $multiline = false ) {
		$text = $multiline ? sanitize_textarea_field( (string) $text ) : sanitize_text_field( (string) $text );
		$text = preg_replace( '#(https?://|www\.)\S+#iu', '', $text );
		$text = trim( preg_replace( $multiline ? '/[ \t]+/u' : '/\s+/u', ' ', $text ) );
		return mb_substr( $text, 0, $max );
	}

	/**
	 * 使えない言葉を含むか。
	 */
	public static function blocked( $text ) {
		foreach ( preg_split( '/\R/u', (string) get_option( self::OPTION, '' ) ) as $word ) {
			$word = trim( $word );
			if ( '' !== $word && false !== mb_stripos( (string) $text, $word ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 登録から3日以上たった会員か（検索エンジンに載せるかの判断などに使う）。
	 */
	public static function seasoned( $user_id ) {
		$user = get_userdata( $user_id );
		return $user && strtotime( $user->user_registered ) <= time() - SNSCS_Social::MIN_AGE_DAYS * DAY_IN_SECONDS;
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, '投稿の確認（推しリスト・質問箱）', '投稿の確認', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	private static function url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::ADMIN_PAGE ) );
	}

	/**
	 * 種類 => [テーブル, 状態の列]
	 */
	private static function kinds() {
		return array(
			'list'     => array( SNSCS_Lists::table(), 'status' ),
			'question' => array( SNSCS_QA::questions_table(), 'status' ),
			'answer'   => array( SNSCS_QA::answers_table(), 'status' ),
		);
	}

	public static function handle_toggle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		check_admin_referer( 'snscs_ugc_toggle', 'snscs_nonce' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() で確認済み。
		$kind = sanitize_key( $_POST['kind'] ?? '' );
		$id   = absint( $_POST['id'] ?? 0 );
		$show = ! empty( $_POST['show'] );
		// phpcs:enable
		$kinds = self::kinds();
		if ( isset( $kinds[ $kind ] ) && $id ) {
			global $wpdb;
			$wpdb->update( $kinds[ $kind ][0], array( $kinds[ $kind ][1] => $show ? 1 : 0 ), array( 'id' => $id ) );
			if ( 'answer' === $kind ) {
				SNSCS_QA::recount( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT question_id FROM ' . SNSCS_QA::answers_table() . ' WHERE id = %d', $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		wp_safe_redirect( self::url( array( 'snscs_msg' => 'ok' ) ) );
		exit;
	}

	public static function handle_words() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		check_admin_referer( 'snscs_ugc_words', 'snscs_nonce' );
		update_option( self::OPTION, sanitize_textarea_field( wp_unslash( $_POST['words'] ?? '' ) ), false ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_safe_redirect( self::url( array( 'snscs_msg' => 'ok' ) ) );
		exit;
	}

	private static function toggle_button( $kind, $id, $visible ) {
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline">
			<input type="hidden" name="action" value="snscs_ugc_toggle">
			<input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
			<input type="hidden" name="show" value="<?php echo $visible ? '' : '1'; ?>">
			<?php wp_nonce_field( 'snscs_ugc_toggle', 'snscs_nonce' ); ?>
			<button class="button<?php echo $visible ? '' : ' button-primary'; ?>"><?php echo $visible ? '非表示にする' : '表示に戻す'; ?></button>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$lists     = $wpdb->get_results( 'SELECT * FROM ' . SNSCS_Lists::table() . ' ORDER BY id DESC LIMIT 30', ARRAY_A );
		$questions = $wpdb->get_results( 'SELECT * FROM ' . SNSCS_QA::questions_table() . ' ORDER BY id DESC LIMIT 30', ARRAY_A );
		$answers   = $wpdb->get_results( 'SELECT * FROM ' . SNSCS_QA::answers_table() . ' ORDER BY id DESC LIMIT 30', ARRAY_A );
		// phpcs:enable
		$who = function ( $user_id ) {
			$u = get_userdata( $user_id );
			return self::author_name( $user_id ) . ( $u ? '（' . $u->user_email . '）' : '' );
		};
		?>
		<div class="wrap">
			<h1>投稿の確認（推しリスト・質問箱）</h1>
			<?php if ( isset( $_GET['snscs_msg'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p>変更しました。</p></div>
			<?php endif; ?>
			<p>会員が作った推しリスト・質問・回答の新しい順です。ふさわしくないものは「非表示にする」を押してください（ページ・一覧・サイトマップから消えます）。</p>

			<h2>推しリスト</h2>
			<table class="widefat striped">
				<thead><tr><th>題名</th><th>作った人</th><th>発信者</th><th>いいね</th><th>作成</th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $lists as $row ) : ?>
						<tr<?php echo $row['status'] ? '' : ' style="opacity: .6"'; ?>>
							<td><a href="<?php echo esc_url( SNSCS_Lists::url( (int) $row['id'] ) ); ?>" target="_blank"><?php echo esc_html( $row['title'] ); ?></a><br><small><?php echo esc_html( $row['description'] ); ?></small></td>
							<td><?php echo esc_html( $who( (int) $row['user_id'] ) ); ?></td>
							<td><?php echo (int) $row['items']; ?>人</td>
							<td><?php echo (int) $row['likes']; ?></td>
							<td><?php echo esc_html( $row['created_at'] ); ?></td>
							<td><?php echo self::toggle_button( 'list', (int) $row['id'], (bool) $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2>質問</h2>
			<table class="widefat striped">
				<thead><tr><th>質問</th><th>した人</th><th>回答</th><th>作成</th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $questions as $row ) : ?>
						<tr<?php echo $row['status'] ? '' : ' style="opacity: .6"'; ?>>
							<td><a href="<?php echo esc_url( SNSCS_QA::url( (int) $row['id'] ) ); ?>" target="_blank"><?php echo esc_html( $row['title'] ); ?></a><br><small><?php echo esc_html( mb_substr( $row['body'], 0, 120 ) ); ?></small></td>
							<td><?php echo esc_html( $who( (int) $row['user_id'] ) ); ?></td>
							<td><?php echo (int) $row['answers']; ?></td>
							<td><?php echo esc_html( $row['created_at'] ); ?></td>
							<td><?php echo self::toggle_button( 'question', (int) $row['id'], (bool) $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2>回答</h2>
			<table class="widefat striped">
				<thead><tr><th>回答</th><th>した発信者</th><th>参考になった</th><th>作成</th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $answers as $row ) : ?>
						<tr<?php echo $row['status'] ? '' : ' style="opacity: .6"'; ?>>
							<td><a href="<?php echo esc_url( SNSCS_QA::url( (int) $row['question_id'] ) . '#answer-' . (int) $row['id'] ); ?>" target="_blank"><?php echo esc_html( mb_substr( $row['body'], 0, 120 ) ); ?></a></td>
							<td><?php echo esc_html( get_the_title( (int) $row['creator_id'] ) ); ?></td>
							<td><?php echo (int) $row['helpful']; ?></td>
							<td><?php echo esc_html( $row['created_at'] ); ?></td>
							<td><?php echo self::toggle_button( 'answer', (int) $row['id'], (bool) $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2>使えない言葉</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="snscs_ugc_words">
				<?php wp_nonce_field( 'snscs_ugc_words', 'snscs_nonce' ); ?>
				<textarea name="words" rows="5" class="large-text"><?php echo esc_textarea( (string) get_option( self::OPTION, '' ) ); ?></textarea>
				<p class="description">1行に1つ。この言葉を含む推しリスト・質問・回答は受け付けません。</p>
				<?php submit_button( '保存' ); ?>
			</form>
		</div>
		<?php
	}
}
