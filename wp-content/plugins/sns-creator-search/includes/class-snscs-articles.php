<?php
/**
 * 発信者の記事: 発信者がマイページから書き、運営の審査を通ると公開される。
 *
 * 状態:
 *   下書き   (draft)   本人だけが見られる
 *   審査待ち (pending) 新しい記事を申請中
 *   公開中   (publish) 公開中の記事を直したときは、変更内容をメタ情報に置いておき、
 *                      承認されたら入れ替える（それまでは前の内容が表示され続ける）
 * 差し戻しの理由はメタ情報 _snscs_reject_note に置き、マイページに表示する。
 *
 * 記事の下には、書いた発信者の紹介・SNS・リンクと、同じ発信者の他の記事を表示する。
 * 発信者ページを拠点（ハブ）にして、記事から発信者のSNSへ人を送るため。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Articles {

	const POST_TYPE   = 'snscs_article';
	const MAX_TITLE   = 80;
	const MAX_CONTENT = 20000;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'template_redirect', array( __CLASS__, 'hide_if_creator_unpublished' ) );
		add_filter( 'the_content', array( __CLASS__, 'append_author_box' ), 20 );
		add_action( 'admin_post_snscs_article_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_snscs_article_delete', array( __CLASS__, 'handle_delete' ) );
	}

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => '発信者の記事',
					'singular_name' => '発信者の記事',
					'all_items'     => '発信者の記事',
					'edit_item'     => '発信者の記事を編集',
					'not_found'     => '記事はまだありません',
				),
				'public'          => true,
				'has_archive'     => true,
				'rewrite'         => array( 'slug' => 'articles' ),
				'show_in_menu'    => 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE,
				'supports'        => array( 'title', 'editor', 'thumbnail', 'author', 'excerpt' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	public static function creator_of( $article_id ) {
		return (int) get_post_meta( $article_id, '_snscs_creator_id', true );
	}

	/**
	 * 発信者の記事（新しい順）。
	 *
	 * @param int    $creator_id 発信者ページのID。
	 * @param string $status     'publish' または 'any'。
	 * @param int    $limit      件数（-1 で全部）。
	 * @param int[]  $exclude    除く記事。
	 * @return WP_Post[]
	 */
	public static function for_creator( $creator_id, $status = 'publish', $limit = 5, array $exclude = array() ) {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any' === $status ? array( 'publish', 'pending', 'draft' ) : $status,
				'posts_per_page' => $limit,
				'post__not_in'   => $exclude,
				'meta_key'       => '_snscs_creator_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (int) $creator_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * 発信者ページが公開されていないとき（掲載停止中など）は、記事も見せない。
	 */
	public static function hide_if_creator_unpublished() {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return;
		}
		$creator_id = self::creator_of( get_queried_object_id() );
		if ( ! $creator_id || 'publish' !== get_post_status( $creator_id ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
		}
	}

	/* --------------------------------------------------------------------
	 * 記事ページ・発信者ページでの表示
	 * ------------------------------------------------------------------ */

	public static function append_author_box( $content ) {
		if ( ! is_singular( self::POST_TYPE ) || ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}

		$article_id = get_the_ID();
		$creator_id = self::creator_of( $article_id );
		if ( ! $creator_id ) {
			return $content;
		}
		wp_enqueue_style( 'snscs' );

		$accounts = SNSCS_Search::format_accounts( SNSCS_Accounts::get_for_creator( $creator_id ) );
		$photo    = get_the_post_thumbnail_url( $creator_id, 'thumbnail' );

		ob_start();
		?>
		<aside class="snscs-author" aria-label="この記事を書いた人">
			<p class="snscs-author__label">この記事を書いた人</p>
			<div class="snscs-author__head">
				<?php if ( $photo ) : ?>
					<img class="snscs-author__photo" src="<?php echo esc_url( $photo ); ?>" alt="" width="64" height="64">
				<?php endif; ?>
				<div>
					<a class="snscs-author__name" href="<?php echo esc_url( get_permalink( $creator_id ) ); ?>"><?php echo esc_html( get_the_title( $creator_id ) ); ?></a>
					<p class="snscs-author__bio"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $creator_id ) ), 50, '…' ) ); ?></p>
				</div>
			</div>
			<?php SNSCS_Frontend::render_accounts( $accounts ); ?>
			<?php SNSCS_Frontend::render_links( SNSCS_Submissions::links_for( $creator_id ), SNSCS_Submissions::stores_for( $creator_id ) ); ?>
			<p class="snscs-author__more"><a href="<?php echo esc_url( get_permalink( $creator_id ) ); ?>">プロフィールを見る</a></p>
		</aside>
		<?php
		echo self::render_list( 'この発信者の他の記事', self::for_creator( $creator_id, 'publish', 4, array( $article_id ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済み。
		echo SNSCS_Ads::render( 'article_bottom' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 広告枠のHTML。
		return $content . ob_get_clean();
	}

	/**
	 * 記事の一覧（見出し付き）。記事が無ければ空文字。
	 *
	 * @param string    $heading 見出し。
	 * @param WP_Post[] $posts   記事。
	 * @return string
	 */
	public static function render_list( $heading, array $posts ) {
		if ( ! $posts ) {
			return '';
		}
		ob_start();
		?>
		<section class="snscs-articles">
			<h2 class="snscs-articles__title"><?php echo esc_html( $heading ); ?></h2>
			<ul class="snscs-articles__list">
				<?php foreach ( $posts as $post ) : ?>
					<li>
						<a href="<?php echo esc_url( get_permalink( $post ) ); ?>">
							<?php if ( has_post_thumbnail( $post ) ) : ?>
								<?php echo get_the_post_thumbnail( $post, 'thumbnail', array( 'class' => 'snscs-articles__image', 'loading' => 'lazy', 'alt' => '' ) ); ?>
							<?php endif; ?>
							<span>
								<strong><?php echo esc_html( get_the_title( $post ) ); ?></strong>
								<span class="snscs-articles__date"><?php echo esc_html( get_the_date( 'Y年n月j日', $post ) ); ?></span>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
		return ob_get_clean();
	}

	/* --------------------------------------------------------------------
	 * マイページ: 記事の一覧と編集画面
	 * ------------------------------------------------------------------ */

	public static function editor_url( $article_id = 0 ) {
		$args = array( 'snscs_view' => 'article' );
		if ( $article_id ) {
			$args['article'] = (int) $article_id;
		}
		return add_query_arg( $args, snscs_portal_url() );
	}

	/**
	 * マイページの「あなたの記事」欄。
	 */
	public static function render_portal_list( $creator_id ) {
		$articles = self::for_creator( $creator_id, 'any', -1 );
		?>
		<section class="snscs-panel snscs-my-articles" id="snscs-articles">
			<div class="snscs-my-articles__head">
				<h2 class="snscs-panel__title">あなたの記事</h2>
				<a class="snscs-button snscs-button--primary" href="<?php echo esc_url( self::editor_url() ); ?>">新しい記事を書く</a>
			</div>
			<p class="snscs-help">記事はあなたの発信者ページに並び、記事の下にはあなたのSNSとリンクが表示されます。新しい記事も変更も、運営の審査を通ると公開されます。</p>
			<?php if ( ! $articles ) : ?>
				<p>まだ記事はありません。得意なテーマや、SNSでは書ききれない話を書いてみましょう。</p>
			<?php else : ?>
				<ul class="snscs-my-articles__list">
					<?php foreach ( $articles as $article ) : ?>
						<?php list( $badge, $label ) = self::status_badge( $article ); ?>
						<li>
							<span class="snscs-badge snscs-badge--<?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $label ); ?></span>
							<span class="snscs-my-articles__name"><?php echo esc_html( get_the_title( $article ) ? get_the_title( $article ) : '（タイトルなし）' ); ?></span>
							<span class="snscs-my-articles__actions">
								<a href="<?php echo esc_url( self::editor_url( $article->ID ) ); ?>">編集</a>
								<?php if ( 'publish' === $article->post_status ) : ?>
									<a href="<?php echo esc_url( get_permalink( $article ) ); ?>" target="_blank">見る</a>
								<?php endif; ?>
							</span>
							<?php $note = get_post_meta( $article->ID, '_snscs_reject_note', true ); ?>
							<?php if ( $note ) : ?>
								<span class="snscs-my-articles__note">運営からのメッセージ: <?php echo esc_html( $note ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @return array{0:string,1:string} [バッジの種類, 表示名]
	 */
	private static function status_badge( WP_Post $article ) {
		if ( 'publish' === $article->post_status ) {
			if ( get_post_meta( $article->ID, '_snscs_pending', true ) ) {
				return array( 'pending', '公開中・変更を審査中' );
			}
			return get_post_meta( $article->ID, '_snscs_reject_note', true ) ? array( 'rejected', '公開中・変更は差し戻し' ) : array( 'live', '公開中' );
		}
		if ( 'pending' === $article->post_status ) {
			return array( 'pending', '審査中' );
		}
		return get_post_meta( $article->ID, '_snscs_reject_note', true ) ? array( 'rejected', '差し戻し' ) : array( 'off', '下書き' );
	}

	/**
	 * 記事の編集画面。
	 */
	public static function render_editor( $user_id, $creator_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$article_id = isset( $_GET['article'] ) ? absint( $_GET['article'] ) : 0;
		$article    = $article_id ? get_post( $article_id ) : null;
		if ( $article && ( self::POST_TYPE !== $article->post_type || (int) $article->post_author !== (int) $user_id ) ) {
			echo '<p class="snscs-notice snscs-notice--error">この記事は編集できません。</p>';
			return;
		}

		$state   = get_transient( 'snscs_article_form_' . $user_id );
		$errors  = array();
		$title   = $article ? $article->post_title : '';
		$content = $article ? $article->post_content : '';
		$photo   = $article ? (int) get_post_thumbnail_id( $article ) : 0;
		$pending = $article ? get_post_meta( $article->ID, '_snscs_pending', true ) : null;
		if ( ! is_array( $pending ) && $article ) {
			// 差し戻された変更があれば、それを直せるように表示する。
			$pending = get_post_meta( $article->ID, '_snscs_rejected_draft', true );
		}
		if ( is_array( $pending ) ) {
			// 変更を審査中なら、申請中の内容を編集する。
			$title   = $pending['title'];
			$content = $pending['content'];
			$photo   = (int) $pending['thumbnail_id'];
		}
		if ( $state && (int) $state['article_id'] === $article_id ) {
			delete_transient( 'snscs_article_form_' . $user_id );
			$errors  = $state['errors'];
			$title   = $state['title'];
			$content = $state['content'];
		}
		$published = $article && 'publish' === $article->post_status;
		$photo_url = $photo ? wp_get_attachment_image_url( $photo, 'medium' ) : '';
		?>
		<div class="snscs-account-bar">
			<a href="<?php echo esc_url( snscs_portal_url() . '#snscs-articles' ); ?>">&larr; マイページに戻る</a>
		</div>
		<section class="snscs-panel">
			<h2 class="snscs-panel__title"><?php echo $article ? '記事を編集' : '新しい記事を書く'; ?></h2>

			<?php if ( $errors ) : ?>
				<div class="snscs-notice snscs-notice--error" role="alert">
					<ul>
						<?php foreach ( $errors as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			<?php if ( $published ) : ?>
				<p class="snscs-notice">この記事は公開中です。変更は審査を通ると反映され、それまでは今の内容が表示されます。</p>
			<?php endif; ?>

			<form class="snscs-fields" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="snscs_article_save">
				<input type="hidden" name="article" value="<?php echo (int) $article_id; ?>">
				<input type="hidden" name="thumbnail_id" value="<?php echo (int) $photo; ?>">
				<?php wp_nonce_field( 'snscs_article_save', 'snscs_nonce' ); ?>

				<label class="snscs-field">
					<span>タイトル <em class="snscs-required">必須</em></span>
					<input type="text" name="title" value="<?php echo esc_attr( $title ); ?>" maxlength="<?php echo (int) self::MAX_TITLE; ?>" required>
				</label>

				<div class="snscs-field">
					<span>アイキャッチ画像（JPEG・PNG・WebP、2MBまで）</span>
					<div class="snscs-photo">
						<?php if ( $photo_url ) : ?>
							<img src="<?php echo esc_url( $photo_url ); ?>" alt="現在の画像" width="160" style="border-radius: 6px; height: auto">
						<?php endif; ?>
						<input type="file" name="snscs_photo" accept="image/jpeg,image/png,image/webp">
					</div>
				</div>

				<div class="snscs-field">
					<span>本文 <em class="snscs-required">必須</em></span>
					<?php
					wp_editor(
						$content,
						'snscs_article_content',
						array(
							'textarea_name' => 'content',
							'media_buttons' => false,
							'textarea_rows' => 16,
							'teeny'         => false,
							'quicktags'     => true,
							'tinymce'       => array(
								'toolbar1'      => 'formatselect,bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo',
								'toolbar2'      => '',
								'block_formats' => '段落=p;見出し=h2;小見出し=h3',
							),
						)
					);
					?>
				</div>

				<div class="snscs-article-buttons">
					<button type="submit" name="intent" value="submit" class="snscs-button snscs-button--primary"><?php echo $published ? '変更を申請する' : '審査を申請する'; ?></button>
					<?php if ( ! $published ) : ?>
						<button type="submit" name="intent" value="draft" class="snscs-button">下書き保存</button>
					<?php endif; ?>
				</div>
			</form>

			<?php if ( $article ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm( 'この記事を削除します。元に戻せません。よろしいですか？' );" class="snscs-article-delete">
					<input type="hidden" name="action" value="snscs_article_delete">
					<input type="hidden" name="article" value="<?php echo (int) $article_id; ?>">
					<?php wp_nonce_field( 'snscs_article_delete', 'snscs_nonce' ); ?>
					<button type="submit" class="snscs-button snscs-button--danger">この記事を削除する</button>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	/* --------------------------------------------------------------------
	 * 保存・削除
	 * ------------------------------------------------------------------ */

	public static function handle_save() {
		if ( ! snscs_is_creator_user() || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_article_save' ) ) {
			wp_safe_redirect( snscs_portal_url() );
			exit;
		}

		$user_id    = get_current_user_id();
		$creator_id = SNSCS_Submissions::creator_id_for_user( $user_id );
		$article_id = isset( $_POST['article'] ) ? absint( $_POST['article'] ) : 0;
		$article    = $article_id ? get_post( $article_id ) : null;
		$intent     = isset( $_POST['intent'] ) && 'draft' === $_POST['intent'] ? 'draft' : 'submit';

		if ( ! $creator_id || ( $article && ( self::POST_TYPE !== $article->post_type || (int) $article->post_author !== $user_id ) ) ) {
			wp_safe_redirect( snscs_portal_url() );
			exit;
		}

		$title   = isset( $_POST['title'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['title'] ) ) ) : '';
		$content = isset( $_POST['content'] ) ? trim( wp_kses_post( wp_unslash( $_POST['content'] ) ) ) : '';
		$errors  = array();
		if ( '' === $title ) {
			$errors[] = 'タイトルを入力してください。';
		} elseif ( mb_strlen( $title ) > self::MAX_TITLE ) {
			$errors[] = sprintf( 'タイトルは%d文字以内にしてください。', self::MAX_TITLE );
		}
		if ( 'submit' === $intent && '' === trim( wp_strip_all_tags( $content ) ) ) {
			$errors[] = '本文を入力してください。';
		}
		if ( mb_strlen( $content ) > self::MAX_CONTENT ) {
			$errors[] = '本文が長すぎます。2つの記事に分けてください。';
		}

		$thumbnail_id = isset( $_POST['thumbnail_id'] ) ? absint( $_POST['thumbnail_id'] ) : 0;
		if ( $thumbnail_id && (int) get_post_field( 'post_author', $thumbnail_id ) !== $user_id ) {
			$thumbnail_id = 0;
		}
		if ( ! empty( $_FILES['snscs_photo']['name'] ) ) {
			$uploaded = SNSCS_Portal::upload_photo();
			if ( is_wp_error( $uploaded ) ) {
				$errors[] = $uploaded->get_error_message();
			} else {
				$thumbnail_id = $uploaded;
			}
		}

		if ( $errors ) {
			set_transient(
				'snscs_article_form_' . $user_id,
				array( 'article_id' => $article_id, 'errors' => $errors, 'title' => $title, 'content' => $content ),
				10 * MINUTE_IN_SECONDS
			);
			wp_safe_redirect( self::editor_url( $article_id ) );
			exit;
		}

		if ( $article && 'publish' === $article->post_status ) {
			// 公開中の記事: 変更は承認まで別に置いておく。
			update_post_meta( $article_id, '_snscs_pending', array( 'title' => $title, 'content' => $content, 'thumbnail_id' => $thumbnail_id, 'at' => time() ) );
			delete_post_meta( $article_id, '_snscs_reject_note' );
			delete_post_meta( $article_id, '_snscs_rejected_draft' );
			do_action( 'snscs_article_submitted', $article_id );
			self::back( 'article_submitted' );
		}

		$status  = 'submit' === $intent ? 'pending' : 'draft';
		$postarr = array(
			'post_type'    => self::POST_TYPE,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_author'  => $user_id,
		);
		if ( $article ) {
			$postarr['ID'] = $article_id;
			wp_update_post( wp_slash( $postarr ) );
		} else {
			$article_id = (int) wp_insert_post( wp_slash( $postarr ) );
			update_post_meta( $article_id, '_snscs_creator_id', $creator_id );
		}
		if ( $thumbnail_id ) {
			set_post_thumbnail( $article_id, $thumbnail_id );
		}

		if ( 'pending' === $status ) {
			delete_post_meta( $article_id, '_snscs_reject_note' );
			do_action( 'snscs_article_submitted', $article_id );
			self::back( 'article_submitted' );
		}
		self::back( 'article_saved' );
	}

	public static function handle_delete() {
		$article_id = isset( $_POST['article'] ) ? absint( $_POST['article'] ) : 0;
		if ( snscs_is_creator_user() && isset( $_POST['snscs_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_article_delete' )
			&& self::POST_TYPE === get_post_type( $article_id ) && (int) get_post_field( 'post_author', $article_id ) === get_current_user_id() ) {
			wp_delete_post( $article_id, true );
			self::back( 'article_deleted' );
		}
		self::back();
	}

	/**
	 * 発信者のすべての記事を消す（退会のとき）。
	 */
	public static function delete_for_user( $user_id ) {
		$ids = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'any', 'author' => $user_id, 'numberposts' => -1, 'fields' => 'ids' ) );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * 発信者ごとの「最近公開した記事」の数（新しいほど重く数える）。
	 *
	 * @param int   $days      直近何日分か。
	 * @param float $half_life 何日前の記事を半分に数えるか。
	 * @return array<int,float> 発信者ID => 重み付きの本数。
	 */
	public static function activity( $days, $half_life ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS creator_id, SUM(POW(0.5, TIMESTAMPDIFF(HOUR, p.post_date_gmt, UTC_TIMESTAMP()) / 24 / %f)) AS score
				FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_snscs_creator_id'
				WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_date_gmt >= %s
				GROUP BY pm.meta_value",
				max( 1, (float) $half_life ),
				self::POST_TYPE,
				$since
			),
			ARRAY_A
		);
		$activity = array();
		foreach ( $rows as $row ) {
			$activity[ (int) $row['creator_id'] ] = (float) $row['score'];
		}
		return $activity;
	}

	private static function back( $notice = '' ) {
		$url = snscs_portal_url();
		if ( $notice ) {
			$url = add_query_arg( 'snscs_notice', $notice, $url );
		}
		wp_safe_redirect( $url . '#snscs-articles' );
		exit;
	}
}
