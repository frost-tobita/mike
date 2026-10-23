<?php
/**
 * 発信者ポータル: 発信者本人が会員登録し、掲載内容を申請・変更するページ。
 *
 * 固定ページに [sns_creator_portal] と書くと、
 *   ログインしていない人 → 「ログイン」と「新規登録」
 *   発信者               → マイページ（掲載状況・審査状況・登録内容の編集）
 * が表示される。
 *
 * 発信者は WordPress の管理画面には入れない（このページだけで完結する）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Portal {

	const ROLE         = 'snscs_creator';
	const MIN_PASSWORD = 8;
	const MAX_PHOTO    = 2097152; // 2MB

	public static function init() {
		add_shortcode( 'sns_creator_portal', array( __CLASS__, 'shortcode' ) );

		add_action( 'admin_post_nopriv_snscs_register', array( __CLASS__, 'handle_register' ) );
		add_action( 'admin_post_snscs_register', array( __CLASS__, 'handle_register' ) );
		add_action( 'admin_post_snscs_submit', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_snscs_unpublish', array( __CLASS__, 'handle_unpublish' ) );
		add_action( 'admin_post_snscs_withdraw', array( __CLASS__, 'handle_withdraw' ) );
		add_action( 'admin_post_snscs_become_creator', array( __CLASS__, 'handle_become_creator' ) );

		add_action( 'admin_init', array( __CLASS__, 'keep_out_of_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
	}

	/**
	 * 「発信者」権限。サイトを見ることしかできず、管理画面の機能は使えない。
	 */
	public static function add_role() {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, '発信者', array( 'read' => true ) );
		}
	}

	public static function keep_out_of_admin() {
		global $pagenow;
		if ( snscs_is_creator_user() && ! wp_doing_ajax() && 'admin-post.php' !== $pagenow ) {
			wp_safe_redirect( snscs_portal_url() );
			exit;
		}
	}

	public static function hide_admin_bar( $show ) {
		return snscs_is_creator_user() ? false : $show;
	}

	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && snscs_is_creator_user( $user ) && ( '' === $requested || 0 === strpos( $requested, admin_url() ) ) ) {
			return snscs_portal_url();
		}
		return $redirect_to;
	}

	/* --------------------------------------------------------------------
	 * 画面
	 * ------------------------------------------------------------------ */

	public static function shortcode() {
		// このページを「発信者ポータル」として覚える（メールや転送先のURLに使う）。
		if ( is_singular() && (int) get_option( 'snscs_portal_page_id' ) !== get_the_ID() ) {
			update_option( 'snscs_portal_page_id', get_the_ID() );
		}
		wp_enqueue_style( 'snscs' );

		ob_start();
		echo '<div class="snscs-portal">';
		self::render_notice();

		if ( ! is_user_logged_in() ) {
			self::render_auth();
		} elseif ( SNSCS_Social::is_reader() ) {
			// 読者会員: 同じアカウントのまま、発信者としても登録できる。
			?>
			<section class="snscs-panel">
				<h2 class="snscs-panel__title">発信者としても掲載する</h2>
				<p>いまは読者会員としてログインしています。同じアカウントのまま発信者として登録し、掲載の申請や記事の執筆ができます（フォロー・ブックマークなどはそのまま使えます）。</p>
				<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
					<input type="hidden" name="action" value="snscs_become_creator">
					<?php wp_nonce_field( 'snscs_become_creator', 'snscs_nonce' ); ?>
					<label class="snscs-check"><input type="checkbox" name="agree" value="1" required> 掲載の条件（利用規約）に同意する</label>
					<p><button type="submit" class="snscs-button snscs-button--primary">発信者として登録する</button></p>
				</form>
			</section>
			<?php
		} elseif ( ! snscs_is_creator_user() ) {
			echo '<p class="snscs-notice">運営用のアカウントでログインしています。審査は管理画面の「発信者 → 審査」から行えます。</p>';
			echo '<p><a class="snscs-button" href="' . esc_url( admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . SNSCS_Review_Admin::PAGE ) ) . '">審査画面へ</a></p>';
		} elseif ( isset( $_GET['snscs_view'] ) && 'article' === $_GET['snscs_view'] && SNSCS_Submissions::creator_id_for_user( get_current_user_id() ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示の切り替えのみ。
			SNSCS_Articles::render_editor( get_current_user_id(), SNSCS_Submissions::creator_id_for_user( get_current_user_id() ) );
		} else {
			self::render_dashboard();
		}

		echo SNSCS_Legal::links_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo '</div>';
		return ob_get_clean();
	}

	private static function render_notice() {
		$messages = array(
			'registered'     => array( 'success', '登録が完了しました。続けて、掲載したい内容を入力して審査を申請してください。' ),
			'submitted'      => array( 'success', '申請を受け付けました。運営が審査し、結果をメールでお知らせします。' ),
			'unpublished'    => array( 'success', '掲載を停止しました。検索結果と発信者ページに表示されなくなりました。' ),
			'article_saved'     => array( 'success', '記事を下書きとして保存しました。' ),
			'article_submitted' => array( 'success', '記事の審査を申請しました。運営が確認し、結果をメールでお知らせします。' ),
			'article_published' => array( 'success', '記事を公開しました。フォロワーにお知らせが届きます。' ),
			'article_scheduled' => array( 'success', '記事の公開を予約しました。指定した日時に公開され、フォロワーにお知らせが届きます。' ),
			'article_updated'   => array( 'success', '記事を更新しました。' ),
			'article_deleted'   => array( 'success', '記事を削除しました。' ),
			'inquiry_saved'     => array( 'success', 'お仕事の相談の設定を保存しました。' ),
			'link_theme_saved'  => array( 'success', 'マイリンクのデザインと表示を保存しました。' ),
			'link_extra_saved'  => array( 'success', 'マイリンクのボタンと機能を保存しました。' ),
			'link_extra_partly' => array( 'warning', '保存しました。ただし、使えないURL（決まったサービス以外）や、終わりが始まりより前の期間は保存していません。' ),
			'sync_linked'    => array( 'success', '連携しました。フォロワー数は毎日自動で最新になり、発信者ページに「本人確認済み」の印が付きます。' ),
			'sync_unlinked'  => array( 'success', '連携を解除しました。' ),
			'sync_cancel'    => array( 'error', '連携は取り消されました。' ),
			'sync_expired'   => array( 'error', '連携の手続きの有効期限が切れました。もう一度「連携する」を押してください。' ),
			'sync_taken'     => array( 'error', 'このアカウントは、すでに別の発信者と連携されています。お心当たりがない場合は運営にお問い合わせください。' ),
			'sync_ig_type'   => array( 'error', 'Instagram のフォロワー数を取得できませんでした。Instagram のアプリで「プロアカウント（ビジネスまたはクリエイター）」に切り替えてから、もう一度連携してください（無料・すぐ切り替えられます）。' ),
			'sync_error'     => array( 'error', '連携できませんでした。時間をおいてもう一度お試しください。' ),
			'withdrawn'      => array( 'success', '退会しました。登録情報と掲載情報はすべて削除しました。ご利用ありがとうございました。' ),
			'withdraw_pass'  => array( 'error', 'パスワードが違うため、退会できませんでした。' ),
			'withdraw_check' => array( 'error', '退会するには、「削除されることを理解しました」にチェックを入れてください。' ),
			'error'          => array( 'error', '入力内容を確認してください。' ),
			'email_invalid'  => array( 'error', 'メールアドレスの形式が正しくありません。' ),
			'email_exists'   => array( 'error', 'このメールアドレスはすでに登録されています。ログインしてください。' ),
			'weak_password'  => array( 'error', sprintf( 'パスワードは%d文字以上にしてください。', self::MIN_PASSWORD ) ),
			'agree'          => array( 'error', '掲載の条件に同意してください。' ),
			'register_error' => array( 'error', '登録できませんでした。時間をおいてもう一度お試しください。' ),
			'form_retry'     => array( 'error', '確認のため、もう一度入力して送信してください（ページを開いてから時間がたっていました）。' ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = isset( $_GET['snscs_notice'] ) ? sanitize_key( $_GET['snscs_notice'] ) : '';
		if ( isset( $messages[ $key ] ) ) {
			printf( '<p class="snscs-notice snscs-notice--%s" role="status">%s</p>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
		}
	}

	private static function render_auth() {
		$invite_code = SNSCS_Invites::remembered_code();
		$inviter     = SNSCS_Invites::inviter_for_code( $invite_code );
		?>
		<?php if ( $inviter ) : ?>
			<p class="snscs-notice snscs-notice--invite">
				<strong><?php echo esc_html( get_the_title( $inviter ) ); ?></strong> さんからの招待です。下の「新規登録」から、無料で発信者として掲載を申し込めます。
			</p>
		<?php endif; ?>
		<div class="snscs-auth<?php echo $inviter ? ' snscs-auth--invited' : ''; ?>">
			<section class="snscs-panel">
				<h2 class="snscs-panel__title">ログイン</h2>
				<p>登録済みの発信者の方はこちら。</p>
				<?php
				wp_login_form(
					array(
						'redirect'       => snscs_portal_url(),
						'label_username' => 'メールアドレス',
						'label_password' => 'パスワード',
						'label_remember' => 'ログイン状態を保存する',
						'label_log_in'   => 'ログイン',
					)
				);
				?>
				<p><a href="<?php echo esc_url( wp_lostpassword_url( snscs_portal_url() ) ); ?>">パスワードを忘れた方</a></p>
			</section>

			<section class="snscs-panel">
				<h2 class="snscs-panel__title">発信者として新規登録（無料）</h2>
				<ol class="snscs-steps">
					<li>メールアドレスとパスワードで登録</li>
					<li>プロフィールとSNSアカウントを入力して申請</li>
					<li>運営の審査に通ると掲載されます</li>
				</ol>
				<form class="snscs-fields" method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
					<input type="hidden" name="action" value="snscs_register">
					<?php wp_nonce_field( 'snscs_register', 'snscs_nonce' ); ?>
					<label class="snscs-field">
						<span>メールアドレス</span>
						<input type="email" name="email" required autocomplete="email">
					</label>
					<label class="snscs-field">
						<span>パスワード（<?php echo (int) self::MIN_PASSWORD; ?>文字以上）</span>
						<input type="password" name="password" required minlength="<?php echo (int) self::MIN_PASSWORD; ?>" autocomplete="new-password">
					</label>
					<label class="snscs-field">
						<span>招待コード（お持ちの方のみ）</span>
						<input type="text" name="invite_code" value="<?php echo esc_attr( $invite_code ); ?>" maxlength="16" autocomplete="off" pattern="[A-Za-z0-9]*">
					</label>
					<?php // 機械による大量登録よけ。人には見えない欄。 ?>
					<label class="snscs-hp" aria-hidden="true">
						<span>Website</span>
						<input type="text" name="snscs_website" tabindex="-1" autocomplete="off">
					</label>
					<label class="snscs-check">
						<input type="checkbox" name="agree" value="1" required>
						<span>
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link_or_text() でエスケープ済み。
							echo SNSCS_Legal::link_or_text( 'terms' ) . 'と' . SNSCS_Legal::link_or_text( 'privacy' );
							?>
							に同意します。掲載内容は運営の審査後に公開され、内容を変更するたびに審査があります。
						</span>
					</label>
					<button type="submit" class="snscs-button snscs-button--primary">登録する</button>
				</form>
			</section>
		</div>
		<?php
	}

	private static function render_dashboard() {
		$user       = wp_get_current_user();
		$creator_id = SNSCS_Submissions::creator_id_for_user( $user->ID );
		$pending    = SNSCS_Submissions::pending_for_user( $user->ID );
		$latest     = SNSCS_Submissions::latest_for_user( $user->ID );
		$rejected   = ( ! $pending && $latest && SNSCS_Submissions::STATUS_REJECTED === $latest['status'] ) ? $latest : null;
		$published  = $creator_id && 'publish' === get_post_status( $creator_id );

		$form_state = get_transient( 'snscs_form_' . $user->ID );
		delete_transient( 'snscs_form_' . $user->ID );

		if ( $form_state ) {
			$data   = $form_state['data'];
			$errors = $form_state['errors'];
		} elseif ( $pending ) {
			$data   = $pending['data'];
			$errors = array();
		} elseif ( $rejected ) {
			$data   = $rejected['data'];
			$errors = array();
		} else {
			$data   = $creator_id ? SNSCS_Submissions::creator_data( $creator_id ) : SNSCS_Submissions::empty_data();
			$errors = array();
		}
		?>
		<div class="snscs-account-bar">
			<span><?php echo esc_html( $user->user_email ); ?> でログイン中</span>
			<?php if ( SNSCS_Hub::page_url( 'calendar' ) ) : ?>
				<a href="<?php echo esc_url( SNSCS_Hub::page_url( 'calendar' ) ); ?>">発信カレンダー</a>
			<?php endif; ?>
			<a href="<?php echo esc_url( wp_logout_url( snscs_portal_url() ) ); ?>">ログアウト</a>
		</div>

		<section class="snscs-panel snscs-status">
			<h2 class="snscs-panel__title">掲載状況</h2>
			<p>
				<?php if ( $published ) : ?>
					<span class="snscs-badge snscs-badge--live">掲載中</span>
					<a href="<?php echo esc_url( get_permalink( $creator_id ) ); ?>">掲載ページを見る</a>
				<?php elseif ( $creator_id ) : ?>
					<span class="snscs-badge snscs-badge--off">掲載停止中</span>
					もう一度掲載したいときは、下のフォームから申請してください。
				<?php else : ?>
					<span class="snscs-badge snscs-badge--off">未掲載</span>
					下のフォームから掲載を申請してください。
				<?php endif; ?>
			</p>

			<?php if ( $pending ) : ?>
				<div class="snscs-review snscs-review--pending">
					<p><span class="snscs-badge snscs-badge--pending">審査中</span>
						<?php echo esc_html( get_date_from_gmt( $pending['created_at'], 'Y年n月j日 H:i' ) ); ?> に申請しました。
					</p>
					<p>
						<?php if ( $published ) : ?>
							審査が終わるまで、掲載中の内容は変わりません。申請内容を直したいときは、下のフォームから送り直せます。
						<?php else : ?>
							運営が内容を確認しています。結果はメールでお知らせします。
						<?php endif; ?>
					</p>
				</div>
			<?php elseif ( $rejected ) : ?>
				<div class="snscs-review snscs-review--rejected">
					<p><span class="snscs-badge snscs-badge--rejected">差し戻し</span> 前回の申請はこのままでは掲載できませんでした。</p>
					<p class="snscs-review__note"><strong>運営からのメッセージ:</strong><br><?php echo nl2br( esc_html( $rejected['note'] ) ); ?></p>
					<p>内容を直して、もう一度申請してください。</p>
				</div>
			<?php endif; ?>
		</section>

		<?php if ( $published ) : ?>
			<?php SNSCS_Report::render_portal( $creator_id ); ?>
			<?php SNSCS_Trend_Alerts::render_portal( $creator_id ); ?>
			<?php SNSCS_Awards::render_portal( $creator_id ); ?>
			<?php SNSCS_Collab::render_portal( $creator_id ); ?>
			<?php SNSCS_QA::render_portal( $creator_id ); ?>
			<?php SNSCS_Line::render_settings( get_current_user_id() ); ?>
			<?php SNSCS_Sync::render_portal( $creator_id ); ?>
			<?php SNSCS_Biolink::render_portal( $creator_id ); ?>
			<?php self::render_referral( $creator_id ); ?>
			<?php SNSCS_Invites::render_portal( $creator_id, SNSCS_Scores::standing( $creator_id ) ); ?>
			<?php SNSCS_Share::render_portal( $creator_id ); ?>
			<?php SNSCS_Inquiries::render_portal( $creator_id ); ?>
		<?php endif; ?>

		<?php if ( $creator_id ) : ?>
			<?php SNSCS_Articles::render_portal_list( $creator_id ); ?>
		<?php endif; ?>

		<?php self::render_form( $data, $errors, $creator_id, (bool) $pending ); ?>

		<?php echo SNSCS_Columns::render( '発信力を上げるSNS運用コラム', array( 'count' => 3 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>

		<?php if ( $published ) : ?>
			<section class="snscs-panel snscs-danger">
				<h2 class="snscs-panel__title">掲載をやめる</h2>
				<p>すぐに検索結果と発信者ページから表示されなくなります（審査は不要です）。もう一度掲載するときは、改めて審査があります。</p>
				<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>" onsubmit="return confirm( '掲載を停止します。よろしいですか？' );">
					<input type="hidden" name="action" value="snscs_unpublish">
					<?php wp_nonce_field( 'snscs_unpublish', 'snscs_nonce' ); ?>
					<button type="submit" class="snscs-button snscs-button--danger">掲載を停止する</button>
				</form>
			</section>
		<?php endif; ?>

		<section class="snscs-panel snscs-danger" id="snscs-withdraw">
			<h2 class="snscs-panel__title">退会する</h2>
			<p>退会すると、次の情報がすべて削除され、<strong>元に戻すことはできません</strong>。もう一度掲載したいときは、新しく登録して審査を受けていただくことになります。</p>
			<ul class="snscs-withdraw__list">
				<li>アカウント（メールアドレス・パスワード）</li>
				<li>掲載ページ、SNSアカウント、プロフィール写真</li>
				<li>申請と審査の記録、書いた記事</li>
				<li>紹介リンクと、リンクから来た人の記録</li>
				<li>届いたお仕事の相談の記録</li>
			</ul>
			<p>一時的に表示を止めたいだけなら、退会ではなく「掲載をやめる」をお使いください。</p>
			<form class="snscs-fields" method="post" action="<?php echo esc_url( snscs_post_url() ); ?>" onsubmit="return confirm( '退会すると、登録情報と掲載情報がすべて削除され、元に戻せません。本当に退会しますか？' );">
				<input type="hidden" name="action" value="snscs_withdraw">
				<?php wp_nonce_field( 'snscs_withdraw', 'snscs_nonce' ); ?>
				<label class="snscs-field">
					<span>確認のため、パスワードを入力してください</span>
					<input type="password" name="password" required autocomplete="current-password">
				</label>
				<label class="snscs-check">
					<input type="checkbox" name="understand" value="1" required>
					<span>登録情報と掲載情報がすべて削除され、元に戻せないことを理解しました。</span>
				</label>
				<div>
					<button type="submit" class="snscs-button snscs-button--danger">退会する</button>
				</div>
			</form>
		</section>
		<?php
	}

	/**
	 * 秒数を「1分20秒」のように表す。
	 */
	private static function format_seconds( $seconds ) {
		$seconds = (int) round( $seconds );
		return $seconds >= 60 ? sprintf( '%d分%d秒', intdiv( $seconds, 60 ), $seconds % 60 ) : sprintf( '%d秒', $seconds );
	}

	/**
	 * 発信者専用の紹介リンクと、表示順位の状況・分析。
	 */
	private static function render_referral( $creator_id ) {
		$link     = SNSCS_Referrals::link_for( $creator_id );
		$days     = SNSCS_Scores::inflow_days();
		$inflow   = SNSCS_Referrals::count_for( $creator_id, $days );
		$standing = SNSCS_Scores::standing( $creator_id );
		?>
		<section class="snscs-panel snscs-referral">
			<h2 class="snscs-panel__title">あなた専用の紹介リンク</h2>
			<p>このリンクをSNSのプロフィール欄や投稿に貼ってください。リンクから来た人が多いほど、検索結果の「おすすめ順」で上に表示されやすくなります。</p>
			<div class="snscs-referral__link">
				<label for="snscs-ref-link" class="screen-reader-text">紹介リンク</label>
				<input id="snscs-ref-link" type="text" readonly value="<?php echo esc_url( $link ); ?>" onclick="this.select();">
				<button type="button" class="snscs-button snscs-button--primary" onclick="var i=document.getElementById('snscs-ref-link');i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent='コピーしました';">コピー</button>
			</div>

			<dl class="snscs-referral__stats">
				<div>
					<dt>紹介リンクから来た人（直近<?php echo (int) $days; ?>日）</dt>
					<dd><?php echo esc_html( number_format_i18n( $inflow ) ); ?>人</dd>
				</div>
				<?php if ( $standing ) : ?>
					<div>
						<dt>このサイトでのフォロワー</dt>
						<dd><?php echo esc_html( number_format_i18n( SNSCS_Social::follower_count( $creator_id ) ) ); ?>人</dd>
					</div>
					<div>
						<dt>あなたのリポストから読まれた数</dt>
						<dd><?php echo esc_html( number_format_i18n( (int) get_post_meta( $creator_id, '_snscs_spread_reads', true ) ) ); ?>人</dd>
					</div>
					<div>
						<dt>おすすめ順での順位</dt>
						<dd><?php echo esc_html( number_format_i18n( $standing['rank'] ) ); ?>位 <small>／ <?php echo esc_html( number_format_i18n( $standing['total'] ) ); ?>人中</small></dd>
					</div>
					<div>
						<dt>ページを見た人（直近<?php echo (int) $days; ?>日）</dt>
						<dd><?php echo esc_html( number_format_i18n( $standing['views'] ) ); ?>人</dd>
					</div>
					<div>
						<dt>平均滞在時間</dt>
						<dd><?php echo esc_html( self::format_seconds( $standing['dwell_avg'] ) ); ?></dd>
					</div>
					<div>
						<dt>あなたのサイト利用（直近<?php echo (int) $days; ?>日）</dt>
						<dd><?php echo esc_html( number_format_i18n( $standing['usage_days'] ) ); ?>日 <small>／ <?php echo esc_html( number_format_i18n( round( $standing['usage_time'] / 60 ) ) ); ?>分</small></dd>
					</div>
					<div>
						<dt>お気に入りに登録した人（直近<?php echo (int) $days; ?>日）</dt>
						<dd><?php echo esc_html( number_format_i18n( $standing['favs'] ) ); ?>人</dd>
					</div>
				<?php endif; ?>
			</dl>
			<p class="snscs-help">同じ人が同じ日に何度クリックしても1人として数え、ご自身のアクセスは数えません。</p>
		</section>

		<section class="snscs-panel snscs-analysis" id="snscs-analysis">
			<h2 class="snscs-panel__title">あなたの分析</h2>
			<?php if ( ! $standing ) : ?>
				<p>点数は掲載から1時間以内に表示されます。</p>
			<?php else : ?>
				<p>おすすめ順は、次の<?php echo count( SNSCS_Scores::signals() ); ?>つの点数（それぞれ0〜100点）を合わせて決まります。点数の低いものから伸ばすのが近道です。</p>
				<?php SNSCS_Integrity::render_notice( $creator_id ); ?>
				<p class="snscs-analysis__total">総合点 <strong><?php echo esc_html( number_format_i18n( $standing['score'], 1 ) ); ?></strong> 点</p>
				<ul class="snscs-analysis__list">
					<?php
					$signals = SNSCS_Scores::signals();
					$weakest = '';
					$lowest  = 101;
					foreach ( $signals as $key => $signal ) {
						$point = isset( $standing['points'][ $key ] ) ? (float) $standing['points'][ $key ] : 0;
						if ( $point < $lowest ) {
							$lowest  = $point;
							$weakest = $key;
						}
					}
					?>
					<?php foreach ( $signals as $key => $signal ) : ?>
						<?php $point = isset( $standing['points'][ $key ] ) ? (float) $standing['points'][ $key ] : 0; ?>
						<li class="<?php echo $key === $weakest ? 'is-weakest' : ''; ?>">
							<span class="snscs-analysis__label"><?php echo esc_html( $signal['label'] ); ?></span>
							<span class="snscs-analysis__bar" role="img" aria-label="<?php echo esc_attr( sprintf( '%s %d点', $signal['label'], round( $point ) ) ); ?>"><span style="width: <?php echo esc_attr( max( 2, round( $point ) ) ); ?>%"></span></span>
							<span class="snscs-analysis__point"><?php echo esc_html( round( $point ) ); ?>点</span>
							<?php if ( $key === $weakest ) : ?>
								<span class="snscs-analysis__tip">伸ばすヒント: <?php echo esc_html( $signal['tip'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="snscs-help">点数は全体の中での順位から計算し、1時間ごとに更新されます。</p>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_form( array $data, array $errors, $creator_id, $has_pending ) {
		// 公式APIで確認済みのフォロワー数（入力欄は読み取りだけにする）。
		$live_accounts = array();
		foreach ( $creator_id ? SNSCS_Accounts::get_for_creator( $creator_id ) : array() as $row ) {
			$live_accounts[ $row['platform'] ] = $row;
		}
		if ( $has_pending ) {
			$button = '申請内容を送り直す';
		} elseif ( $creator_id ) {
			$button = '変更を申請する';
		} else {
			$button = '掲載を申請する';
		}

		$genres  = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => false ) );
		$regions = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_REGION, 'hide_empty' => false, 'hierarchical' => true ) );
		$photo   = $data['thumbnail_id'] ? wp_get_attachment_image_url( $data['thumbnail_id'], 'thumbnail' ) : '';
		?>
		<section class="snscs-panel" id="snscs-form">
			<h2 class="snscs-panel__title"><?php echo $creator_id ? '登録内容の変更' : '掲載内容の入力'; ?></h2>

			<?php if ( $errors ) : ?>
				<div class="snscs-notice snscs-notice--error" role="alert">
					<ul>
						<?php foreach ( $errors as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<form class="snscs-fields" method="post" enctype="multipart/form-data" action="<?php echo esc_url( snscs_post_url() ); ?>">
				<input type="hidden" name="action" value="snscs_submit">
				<input type="hidden" name="snscs_thumbnail_id" value="<?php echo (int) $data['thumbnail_id']; ?>">
				<?php wp_nonce_field( 'snscs_submit', 'snscs_nonce' ); ?>

				<label class="snscs-field">
					<span>表示名 <em class="snscs-required">必須</em></span>
					<input type="text" name="snscs[name]" value="<?php echo esc_attr( $data['name'] ); ?>" maxlength="<?php echo (int) SNSCS_Submissions::MAX_NAME; ?>" required>
				</label>

				<div class="snscs-field">
					<span>プロフィール写真（JPEG・PNG・WebP、2MBまで）</span>
					<div class="snscs-photo">
						<?php if ( $photo ) : ?>
							<img src="<?php echo esc_url( $photo ); ?>" alt="現在の写真" width="64" height="64">
						<?php endif; ?>
						<input type="file" name="snscs_photo" accept="image/jpeg,image/png,image/webp">
					</div>
					<?php if ( $photo ) : ?>
						<label class="snscs-check"><input type="checkbox" name="snscs_remove_photo" value="1"> <span>写真を削除する</span></label>
					<?php endif; ?>
				</div>

				<label class="snscs-field">
					<span>紹介文（<?php echo (int) SNSCS_Submissions::MAX_BIO; ?>文字まで）</span>
					<textarea name="snscs[bio]" rows="5" maxlength="<?php echo (int) SNSCS_Submissions::MAX_BIO; ?>"><?php echo esc_textarea( $data['bio'] ); ?></textarea>
				</label>

				<fieldset class="snscs-field">
					<legend>ジャンル <em class="snscs-required">必須</em>（いくつでも）</legend>
					<div class="snscs-choices">
						<?php foreach ( $genres as $term ) : ?>
							<label class="snscs-chip">
								<input type="checkbox" name="snscs[genres][]" value="<?php echo (int) $term->term_id; ?>" <?php checked( in_array( (int) $term->term_id, array_map( 'intval', $data['genres'] ), true ) ); ?>>
								<span><?php echo esc_html( $term->name ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<label class="snscs-field">
					<span>活動地域</span>
					<select name="snscs[regions][]">
						<option value="">選択しない</option>
						<?php foreach ( $regions as $term ) : ?>
							<option value="<?php echo (int) $term->term_id; ?>" <?php selected( in_array( (int) $term->term_id, array_map( 'intval', $data['regions'] ), true ) ); ?>>
								<?php echo esc_html( ( $term->parent ? '　' : '' ) . $term->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<fieldset class="snscs-field">
					<legend>SNSアカウント <em class="snscs-required">1つ以上</em></legend>
					<p class="snscs-help">使っているSNSだけ入力してください。ハンドル名の代わりにプロフィールのURLを貼り付けても大丈夫です。フォロワー数は審査で確認します。</p>
					<div class="snscs-sns-rows">
						<?php foreach ( snscs_platforms() as $platform => $label ) : ?>
							<?php $account = isset( $data['accounts'][ $platform ] ) ? $data['accounts'][ $platform ] : array( 'handle' => '', 'followers' => '' ); ?>
							<?php $synced = isset( $live_accounts[ $platform ] ) && (int) $live_accounts[ $platform ]['verified'] > 0 && 0 === strcasecmp( (string) $live_accounts[ $platform ]['handle'], (string) $account['handle'] ) ? $live_accounts[ $platform ] : null; ?>
							<div class="snscs-sns-row snscs-sns-row--<?php echo esc_attr( $platform ); ?>">
								<span class="snscs-sns-row__label"><?php echo esc_html( $label ); ?></span>
								<label>
									<span class="screen-reader-text"><?php echo esc_html( $label ); ?> のハンドル名</span>
									<input type="text" name="snscs[accounts][<?php echo esc_attr( $platform ); ?>][handle]" value="<?php echo esc_attr( $account['handle'] ); ?>" placeholder="<?php echo 'substack' === $platform ? 'example（example.substack.com）' : '@ハンドル名'; ?>">
								</label>
								<label>
									<span class="screen-reader-text"><?php echo esc_html( $label ); ?> のフォロワー数</span>
									<input type="number" min="0" step="1" name="snscs[accounts][<?php echo esc_attr( $platform ); ?>][followers]" value="<?php echo esc_attr( $synced ? $synced['followers'] : $account['followers'] ); ?>" placeholder="<?php echo 'substack' === $platform ? '購読者数' : 'フォロワー数'; ?>" <?php echo $synced ? 'readonly' : ''; ?>>
								</label>
								<?php if ( $synced ) : ?>
									<span class="snscs-sns-row__auto">✓ 公式の数を自動で更新しています</span>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<fieldset class="snscs-field">
					<legend>コンテンツ販売・ブログ</legend>
					<p class="snscs-help">note や Brain で発信・販売している方は、名前かページのURLを入れてください。</p>
					<div class="snscs-sns-rows">
						<?php foreach ( snscs_profile_stores() as $key => $store ) : ?>
							<div class="snscs-store-row">
								<span class="snscs-sns-row__label"><?php echo esc_html( $store['label'] ); ?></span>
								<label>
									<span class="screen-reader-text"><?php echo esc_html( $store['label'] ); ?> の名前</span>
									<input type="text" name="snscs[stores][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( isset( $data['stores'][ $key ] ) ? $data['stores'][ $key ] : '' ); ?>" placeholder="<?php echo esc_attr( $store['placeholder'] ); ?>">
								</label>
							</div>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<fieldset class="snscs-field">
					<legend>その他のリンク（<?php echo (int) SNSCS_Submissions::MAX_LINKS; ?>つまで）</legend>
					<p class="snscs-help">ブログ、企業サイト、ネットショップ、上にないSNSなど、見てほしいページを載せられます。</p>
					<div class="snscs-link-rows">
						<?php for ( $i = 0; $i < SNSCS_Submissions::MAX_LINKS; $i++ ) : ?>
							<?php $link = isset( $data['links'][ $i ] ) ? $data['links'][ $i ] : array( 'label' => '', 'url' => '' ); ?>
							<div class="snscs-link-row">
								<label>
									<span class="screen-reader-text"><?php echo (int) $i + 1; ?>つ目のリンクの名前</span>
									<input type="text" name="snscs[links][<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $link['label'] ); ?>" maxlength="<?php echo (int) SNSCS_Submissions::MAX_LINK_LABEL; ?>" placeholder="名前（例: 公式ブログ）">
								</label>
								<label>
									<span class="screen-reader-text"><?php echo (int) $i + 1; ?>つ目のリンクのURL</span>
									<input type="url" name="snscs[links][<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( $link['url'] ); ?>" placeholder="https://">
								</label>
							</div>
						<?php endfor; ?>
					</div>
				</fieldset>

				<label class="snscs-field">
					<span>マイリンクのID（任意）</span>
					<span class="snscs-linkname"><span class="snscs-linkname__prefix"><?php echo esc_html( untrailingslashit( preg_replace( '#^https?://#', '', home_url( '/' ) ) ) ); ?>/@</span><input type="text" name="snscs[linkname]" value="<?php echo esc_attr( isset( $data['linkname'] ) ? $data['linkname'] : '' ); ?>" maxlength="<?php echo (int) SNSCS_Biolink::MAX_NAME; ?>" pattern="[A-Za-z0-9_\-]{3,30}" placeholder="hanako"></span>
					<span class="snscs-help">SNSのプロフィール欄に貼る「マイリンク」のURLになります。半角英数字・ハイフン・アンダーバーで3〜30文字。</span>
				</label>

				<p class="snscs-help">送信すると運営が審査します。<?php echo $creator_id ? '承認されるまで、掲載中の内容は変わりません。' : '承認されると検索結果に表示されます。'; ?></p>
				<button type="submit" class="snscs-button snscs-button--primary"><?php echo esc_html( $button ); ?></button>
			</form>
		</section>
		<?php
	}

	/* --------------------------------------------------------------------
	 * 送信の処理
	 * ------------------------------------------------------------------ */

	public static function handle_register() {
		if ( ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_register' ) ) {
			self::back( 'form_retry' );
		}
		if ( is_user_logged_in() ) {
			self::back();
		}
		// 人には見えない欄に入力がある = 機械による送信。
		if ( ! empty( $_POST['snscs_website'] ) ) {
			self::back();
		}

		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- パスワードは加工しない。

		if ( ! is_email( $email ) ) {
			self::back( 'email_invalid' );
		}
		if ( email_exists( $email ) ) {
			self::back( 'email_exists' );
		}
		if ( strlen( $password ) < self::MIN_PASSWORD ) {
			self::back( 'weak_password' );
		}
		if ( empty( $_POST['agree'] ) ) {
			self::back( 'agree' );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => self::unique_login(),
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => strstr( $email, '@', true ),
				'role'         => self::ROLE,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			self::back( 'register_error' );
		}

		$invite_code = isset( $_POST['invite_code'] ) && '' !== $_POST['invite_code'] ? sanitize_text_field( wp_unslash( $_POST['invite_code'] ) ) : SNSCS_Invites::remembered_code();
		SNSCS_Invites::record_signup( $user_id, $invite_code );

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );
		self::back( 'registered' );
	}

	/**
	 * 読者会員が、同じアカウントのまま発信者としても登録する。
	 */
	public static function handle_become_creator() {
		if ( ! SNSCS_Social::is_reader() || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_become_creator' ) ) {
			self::back();
		}
		if ( empty( $_POST['agree'] ) ) {
			self::back( 'agree' );
		}
		$user = wp_get_current_user();
		$user->add_role( self::ROLE );
		SNSCS_Invites::record_signup( $user->ID, SNSCS_Invites::remembered_code() );
		self::back( 'registered' );
	}

	public static function handle_submit() {
		if ( ! snscs_is_creator_user() || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_submit' ) ) {
			self::back();
		}

		$user_id = get_current_user_id();
		$errors  = array();

		// 各値は SNSCS_Submissions::sanitize() で整える。
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_POST['snscs'] ) && is_array( $_POST['snscs'] ) ? wp_unslash( $_POST['snscs'] ) : array();

		// 写真: 本人がアップロードしたもの、または掲載中の写真だけを受け付ける。
		$thumbnail_id = isset( $_POST['snscs_thumbnail_id'] ) ? absint( $_POST['snscs_thumbnail_id'] ) : 0;
		if ( ! self::can_use_photo( $thumbnail_id, $user_id ) ) {
			$thumbnail_id = 0;
		}
		if ( ! empty( $_POST['snscs_remove_photo'] ) ) {
			$thumbnail_id = 0;
		}
		if ( ! empty( $_FILES['snscs_photo']['name'] ) ) {
			$uploaded = self::upload_photo();
			if ( is_wp_error( $uploaded ) ) {
				$errors[] = $uploaded->get_error_message();
			} else {
				$thumbnail_id = $uploaded;
			}
		}
		$raw['thumbnail_id'] = $thumbnail_id;

		list( $data, $sanitize_errors ) = SNSCS_Submissions::sanitize( $raw, $user_id );
		$errors                         = array_merge( $errors, $sanitize_errors );

		if ( $errors ) {
			set_transient( 'snscs_form_' . $user_id, array( 'data' => $data, 'errors' => $errors ), 10 * MINUTE_IN_SECONDS );
			self::back( 'error', '#snscs-form' );
		}

		SNSCS_Submissions::submit( $user_id, $data );
		self::back( 'submitted' );
	}

	public static function handle_unpublish() {
		if ( ! snscs_is_creator_user() || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_unpublish' ) ) {
			self::back();
		}

		$creator_id = SNSCS_Submissions::creator_id_for_user( get_current_user_id() );
		if ( $creator_id ) {
			wp_update_post( array( 'ID' => $creator_id, 'post_status' => 'draft' ) );
		}
		self::back( 'unpublished' );
	}

	public static function handle_withdraw() {
		if ( ! snscs_is_creator_user() || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_withdraw' ) ) {
			self::back();
		}

		$user     = wp_get_current_user();
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- パスワードは加工しない。

		if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			self::back( 'withdraw_pass', '#snscs-withdraw' );
		}
		if ( empty( $_POST['understand'] ) ) {
			self::back( 'withdraw_check', '#snscs-withdraw' );
		}

		self::withdraw( $user->ID );

		wp_logout();
		self::back( 'withdrawn' );
	}

	/**
	 * 発信者を退会させ、本人に関わる情報をすべて削除する。
	 *
	 * @param int $user_id 発信者ユーザー。
	 */
	public static function withdraw( $user_id ) {
		global $wpdb;

		$user = get_userdata( $user_id );
		if ( ! $user || ! snscs_is_creator_user( $user ) ) {
			return;
		}

		$email      = $user->user_email;
		$creator_id = SNSCS_Submissions::creator_id_for_user( $user_id );
		$name       = $creator_id ? get_the_title( $creator_id ) : $user->display_name;

		if ( $creator_id ) {
			// SNSアカウントは before_delete_post（SNSCS_Admin::delete）で一緒に消える。
			wp_delete_post( $creator_id, true );
			$wpdb->delete( SNSCS_Scores::table(), array( 'creator_id' => $creator_id ), array( '%d' ) );
			$wpdb->delete( SNSCS_Referrals::table(), array( 'creator_id' => $creator_id ), array( '%d' ) );
		}

		// 本人がアップロードした写真（申請中・差し戻し中のものを含む）。
		$photos = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'author'      => $user_id,
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		foreach ( $photos as $photo_id ) {
			wp_delete_attachment( $photo_id, true );
		}

		SNSCS_Articles::delete_for_user( $user_id );
		if ( $creator_id ) {
			SNSCS_Tracking::delete_for_creator( $creator_id );
			SNSCS_Usage::delete_for_creator( $creator_id );
		}
		$wpdb->delete( SNSCS_Submissions::table(), array( 'user_id' => $user_id ), array( '%d' ) );
		delete_transient( 'snscs_form_' . $user_id );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );

		SNSCS_Scores::schedule_soon();
		do_action( 'snscs_creator_withdrawn', $email, $name );
	}

	/**
	 * @return int|WP_Error 写真の添付ファイルID。
	 */
	public static function upload_photo() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 呼び出し元で確認済み。
		$size = isset( $_FILES['snscs_photo']['size'] ) ? (int) $_FILES['snscs_photo']['size'] : 0;
		if ( $size > self::MAX_PHOTO ) {
			return new WP_Error( 'snscs_photo_size', '写真は2MB以下にしてください。' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_handle_upload(
			'snscs_photo',
			0,
			array(),
			array(
				'test_form' => false,
				'mimes'     => array(
					'jpg|jpeg|jpe' => 'image/jpeg',
					'png'          => 'image/png',
					'webp'         => 'image/webp',
				),
			)
		);
		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'snscs_photo', '写真をアップロードできませんでした（JPEG・PNG・WebPのみ使えます）。' );
		}

		return $id;
	}

	private static function can_use_photo( $attachment_id, $user_id ) {
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}
		$creator_id = SNSCS_Submissions::creator_id_for_user( $user_id );
		return (int) get_post_field( 'post_author', $attachment_id ) === (int) $user_id
			|| ( $creator_id && (int) get_post_thumbnail_id( $creator_id ) === (int) $attachment_id );
	}

	/**
	 * ログイン名は使わない（メールアドレスでログインする）ので、重ならない名前を自動で付ける。
	 */
	private static function unique_login() {
		do {
			$login = 'creator_' . strtolower( wp_generate_password( 10, false ) );
		} while ( username_exists( $login ) );
		return $login;
	}

	/**
	 * ポータルに戻る。
	 */
	private static function back( $notice = '', $anchor = '' ) {
		$url = snscs_portal_url();
		if ( $notice ) {
			$url = add_query_arg( 'snscs_notice', $notice, $url );
		}
		wp_safe_redirect( $url . $anchor );
		exit;
	}
}
