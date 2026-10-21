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
		} elseif ( ! snscs_is_creator_user() ) {
			echo '<p class="snscs-notice">運営用のアカウントでログインしています。審査は管理画面の「発信者 → 審査」から行えます。</p>';
			echo '<p><a class="snscs-button" href="' . esc_url( admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . SNSCS_Review_Admin::PAGE ) ) . '">審査画面へ</a></p>';
		} else {
			self::render_dashboard();
		}

		echo '</div>';
		return ob_get_clean();
	}

	private static function render_notice() {
		$messages = array(
			'registered'     => array( 'success', '登録が完了しました。続けて、掲載したい内容を入力して審査を申請してください。' ),
			'submitted'      => array( 'success', '申請を受け付けました。運営が審査し、結果をメールでお知らせします。' ),
			'unpublished'    => array( 'success', '掲載を停止しました。検索結果と発信者ページに表示されなくなりました。' ),
			'error'          => array( 'error', '入力内容を確認してください。' ),
			'email_invalid'  => array( 'error', 'メールアドレスの形式が正しくありません。' ),
			'email_exists'   => array( 'error', 'このメールアドレスはすでに登録されています。ログインしてください。' ),
			'weak_password'  => array( 'error', sprintf( 'パスワードは%d文字以上にしてください。', self::MIN_PASSWORD ) ),
			'agree'          => array( 'error', '掲載の条件に同意してください。' ),
			'register_error' => array( 'error', '登録できませんでした。時間をおいてもう一度お試しください。' ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = isset( $_GET['snscs_notice'] ) ? sanitize_key( $_GET['snscs_notice'] ) : '';
		if ( isset( $messages[ $key ] ) ) {
			printf( '<p class="snscs-notice snscs-notice--%s" role="status">%s</p>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
		}
	}

	private static function render_auth() {
		?>
		<div class="snscs-auth">
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
				<form class="snscs-fields" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
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
					<?php // 機械による大量登録よけ。人には見えない欄。 ?>
					<label class="snscs-hp" aria-hidden="true">
						<span>Website</span>
						<input type="text" name="snscs_website" tabindex="-1" autocomplete="off">
					</label>
					<label class="snscs-check">
						<input type="checkbox" name="agree" value="1" required>
						<span>掲載内容は運営の審査後に公開されること、内容を変更するたびに審査があることに同意します。</span>
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

		<?php self::render_form( $data, $errors, $creator_id, (bool) $pending ); ?>

		<?php if ( $published ) : ?>
			<section class="snscs-panel snscs-danger">
				<h2 class="snscs-panel__title">掲載をやめる</h2>
				<p>すぐに検索結果と発信者ページから表示されなくなります（審査は不要です）。もう一度掲載するときは、改めて審査があります。</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm( '掲載を停止します。よろしいですか？' );">
					<input type="hidden" name="action" value="snscs_unpublish">
					<?php wp_nonce_field( 'snscs_unpublish', 'snscs_nonce' ); ?>
					<button type="submit" class="snscs-button snscs-button--danger">掲載を停止する</button>
				</form>
			</section>
		<?php endif; ?>
		<?php
	}

	private static function render_form( array $data, array $errors, $creator_id, $has_pending ) {
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

			<form class="snscs-fields" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
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
							<div class="snscs-sns-row snscs-sns-row--<?php echo esc_attr( $platform ); ?>">
								<span class="snscs-sns-row__label"><?php echo esc_html( $label ); ?></span>
								<label>
									<span class="screen-reader-text"><?php echo esc_html( $label ); ?> のハンドル名</span>
									<input type="text" name="snscs[accounts][<?php echo esc_attr( $platform ); ?>][handle]" value="<?php echo esc_attr( $account['handle'] ); ?>" placeholder="<?php echo 'substack' === $platform ? 'example（example.substack.com）' : '@ハンドル名'; ?>">
								</label>
								<label>
									<span class="screen-reader-text"><?php echo esc_html( $label ); ?> のフォロワー数</span>
									<input type="number" min="0" step="1" name="snscs[accounts][<?php echo esc_attr( $platform ); ?>][followers]" value="<?php echo esc_attr( $account['followers'] ); ?>" placeholder="<?php echo 'substack' === $platform ? '購読者数' : 'フォロワー数'; ?>">
								</label>
							</div>
						<?php endforeach; ?>
					</div>
				</fieldset>

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
			self::back( 'register_error' );
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

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );
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

	/**
	 * @return int|WP_Error 写真の添付ファイルID。
	 */
	private static function upload_photo() {
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
