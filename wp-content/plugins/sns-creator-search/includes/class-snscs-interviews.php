<?php
/**
 * 発信者インタビュー記事。
 *
 *   - 管理画面の発信者一覧の「インタビューの下書きを作る」から、質問のひな形入りの下書きを作れる
 *     （カテゴリ「SNS運用コラム」「発信者インタビュー」、ジャンル・対象SNSは発信者から引き継ぐ）
 *   - 記事の最初と最後に、インタビューした発信者のカード（写真・SNS・ページへのリンク・お気に入り）を出す
 *   - 発信者ページに「インタビュー記事」を出す
 *   - 公開したら発信者にメールで知らせ、シェアをお願いする（載った本人のシェアは確実な集客になる）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Interviews {

	const META          = '_snscs_interview_creator';
	const CATEGORY_SLUG = 'creator-interview';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_setup' ) );
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_post', array( __CLASS__, 'save_meta' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_action( 'admin_post_snscs_interview_draft', array( __CLASS__, 'handle_draft' ) );
		add_filter( 'the_content', array( __CLASS__, 'wrap_content' ), 15 );
		add_action( 'transition_post_status', array( __CLASS__, 'notify_on_publish' ), 10, 3 );
	}

	public static function maybe_setup() {
		if ( ! get_term_by( 'slug', self::CATEGORY_SLUG, 'category' ) ) {
			wp_insert_term( '発信者インタビュー', 'category', array( 'slug' => self::CATEGORY_SLUG, 'description' => '掲載中の発信者へのインタビュー' ) );
		}
	}

	public static function creator_of( $post_id ) {
		$creator = (int) get_post_meta( $post_id, self::META, true );
		return ( $creator && 'publish' === get_post_status( $creator ) ) ? $creator : 0;
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function add_meta_box() {
		add_meta_box( 'snscs-interview', '発信者インタビュー', array( __CLASS__, 'render_meta_box' ), 'post', 'side' );
	}

	public static function render_meta_box( $post ) {
		$current  = (int) get_post_meta( $post->ID, self::META, true );
		$creators = get_posts( array( 'post_type' => SNSCS_Post_Types::POST_TYPE, 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids', 'no_found_rows' => true ) );
		wp_nonce_field( 'snscs_interview_meta', 'snscs_interview_nonce' );
		?>
		<p><label for="snscs-interview-creator">インタビューした発信者</label></p>
		<select id="snscs-interview-creator" name="snscs_interview_creator" style="width:100%">
			<option value="0">（インタビュー記事ではない）</option>
			<?php foreach ( $creators as $id ) : ?>
				<option value="<?php echo (int) $id; ?>" <?php selected( $current, $id ); ?>><?php echo esc_html( get_the_title( $id ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description">選ぶと、記事の最初と最後にその発信者の紹介が付き、発信者ページにもこの記事へのリンクが出ます。公開時に本人へメールでお知らせします。</p>
		<?php
	}

	public static function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['snscs_interview_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_interview_nonce'] ) ), 'snscs_interview_meta' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$creator = isset( $_POST['snscs_interview_creator'] ) ? absint( $_POST['snscs_interview_creator'] ) : 0;
		if ( $creator && SNSCS_Post_Types::POST_TYPE === get_post_type( $creator ) ) {
			update_post_meta( $post_id, self::META, $creator );
		} else {
			delete_post_meta( $post_id, self::META );
		}
	}

	public static function row_action( $actions, $post ) {
		if ( SNSCS_Post_Types::POST_TYPE === $post->post_type && 'publish' === $post->post_status && current_user_can( 'edit_posts' ) ) {
			$actions['snscs_interview'] = sprintf( '<a href="%s">インタビューの下書きを作る</a>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=snscs_interview_draft&creator=' . $post->ID ), 'snscs_interview_draft_' . $post->ID ) ) );
		}
		return $actions;
	}

	/**
	 * 質問のひな形入りの下書きを作る。
	 *
	 * @return int 下書きの投稿ID（失敗したら0）。
	 */
	public static function create_draft( $creator_id ) {
		$name      = get_the_title( $creator_id );
		$platforms = array_values( array_unique( wp_list_pluck( SNSCS_Accounts::get_for_creator( $creator_id ), 'platform' ) ) );
		$sns       = implode( '・', array_map( 'snscs_platform_label', $platforms ) );
		$questions = array(
			'発信を始めたきっかけを教えてください。',
			$sns ? sprintf( '%sを選んだ理由は？', $sns ) : '今のSNSを選んだ理由は？',
			'ふだんの投稿で大切にしていることは？',
			'反応が大きかった投稿と、その理由を教えてください。',
			'発信を続けるうえで大変だったことと、乗り越え方は？',
			'これから発信を始める人へのアドバイスをお願いします。',
			'今後挑戦したいことは？',
		);
		$blocks = array( '<!-- wp:paragraph --><p>（リード文: ' . esc_html( $name ) . 'さんの紹介を2〜3行で）</p><!-- /wp:paragraph -->' );
		foreach ( $questions as $question ) {
			$blocks[] = '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html( $question ) . '</h2><!-- /wp:heading -->';
			$blocks[] = '<!-- wp:paragraph --><p>（回答）</p><!-- /wp:paragraph -->';
		}
		$cats = array_filter( array( SNSCS_Columns::category_id(), (int) ( get_term_by( 'slug', self::CATEGORY_SLUG, 'category' )->term_id ?? 0 ) ) );
		$id   = wp_insert_post(
			wp_slash(
				array(
					'post_type'     => 'post',
					'post_status'   => 'draft',
					'post_title'    => $name . 'さんインタビュー：発信を続ける理由',
					'post_content'  => implode( "\n\n", $blocks ),
					'post_category' => $cats,
				)
			)
		);
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		update_post_meta( $id, self::META, $creator_id );
		wp_set_object_terms( $id, wp_get_object_terms( $creator_id, SNSCS_Post_Types::TAX_GENRE, array( 'fields' => 'ids' ) ), SNSCS_Post_Types::TAX_GENRE );
		wp_set_object_terms( $id, $platforms, SNSCS_Columns::TAX_SNS );
		return (int) $id;
	}

	public static function handle_draft() {
		$creator = isset( $_GET['creator'] ) ? absint( $_GET['creator'] ) : 0;
		check_admin_referer( 'snscs_interview_draft_' . $creator );
		if ( ! current_user_can( 'edit_posts' ) || SNSCS_Post_Types::POST_TYPE !== get_post_type( $creator ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$id = self::create_draft( $creator );
		wp_safe_redirect( $id ? get_edit_post_link( $id, 'raw' ) : admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE ) );
		exit;
	}

	/* --------------------------------------------------------------------
	 * 表示
	 * ------------------------------------------------------------------ */

	private static function card( $creator_id, $heading ) {
		$items = SNSCS_Personalize::items( array( $creator_id ) );
		if ( ! $items ) {
			return '';
		}
		$item = $items[0];
		ob_start();
		?>
		<aside class="snscs-interview-card">
			<p class="snscs-interview-card__label"><?php echo esc_html( $heading ); ?></p>
			<div class="snscs-interview-card__body">
				<?php if ( $item['thumbnail'] ) : ?>
					<img src="<?php echo esc_url( $item['thumbnail'] ); ?>" alt="" width="64" height="64" loading="lazy">
				<?php endif; ?>
				<div>
					<p class="snscs-interview-card__name"><a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['name'] ); ?></a> <?php echo SNSCS_Recommend::fav_button( $creator_id, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済み。 ?></p>
					<?php SNSCS_Frontend::render_accounts( $item['accounts'] ); ?>
				</div>
			</div>
			<p><a class="snscs-button snscs-button--primary" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['name'] ); ?>さんのページを見る</a></p>
		</aside>
		<?php
		return ob_get_clean();
	}

	public static function wrap_content( $content ) {
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		$creator = self::creator_of( get_the_ID() );
		if ( ! $creator ) {
			return $content;
		}
		wp_enqueue_style( 'snscs' );
		return self::card( $creator, '今回お話を聞いた発信者' ) . $content . self::card( $creator, 'この記事の発信者' );
	}

	/**
	 * 発信者ページの「インタビュー記事」。
	 */
	public static function for_creator_html( $creator_id ) {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'posts_per_page' => 3,
				'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (int) $creator_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
			)
		);
		return SNSCS_Columns::render_posts( 'インタビュー記事', $posts );
	}

	/**
	 * インタビュー記事を公開したら、本人に知らせてシェアをお願いする（1回だけ）。
	 */
	public static function notify_on_publish( $new, $old, $post ) {
		if ( 'publish' !== $new || 'publish' === $old || 'post' !== $post->post_type || get_post_meta( $post->ID, '_snscs_interview_notified', true ) ) {
			return;
		}
		$creator = (int) get_post_meta( $post->ID, self::META, true );
		$user    = $creator ? get_userdata( (int) get_post_field( 'post_author', $creator ) ) : null;
		if ( ! $user || ! snscs_is_creator_user( $user ) ) {
			return;
		}
		update_post_meta( $post->ID, '_snscs_interview_notified', 1 );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$url  = get_permalink( $post );
		$text = sprintf( '%sにインタビューしていただきました！', $site );
		$body = implode(
			"\n",
			array(
				get_the_title( $creator ) . ' さん',
				'',
				'インタビュー記事を公開しました。ご協力ありがとうございました。',
				get_the_title( $post ),
				$url,
				'',
				'よろしければ、SNSでシェアしていただけるとうれしいです:',
				'https://x.com/intent/tweet?' . http_build_query( array( 'text' => $text, 'url' => $url ) ),
			)
		);
		wp_mail( $user->user_email, '[' . $site . '] インタビュー記事を公開しました', $body );
	}
}
