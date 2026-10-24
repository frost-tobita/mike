<?php
/**
 * 読者の公開プロフィールと、会員どうしのフォロー（X のように、読者のリポストもフォロワーへ広がる）。
 *
 * 公開プロフィール（会員が自分で「公開する」を選んだときだけ。最初は非公開）:
 *   ニックネーム・ひとこと紹介・フォロー／フォロワーの数・リポストした記事（ひとこと付き）を、
 *   会員ページの ?user=ID で誰でも見られる。いいね・ブックマークは公開しない。
 * 会員どうしのフォロー: 公開プロフィールの会員だけフォローできる（自分自身は不可）。
 *   フォローした人のリポストが「フォロー中の新着」とお知らせ（スマホ通知も）に流れる。
 *   フォローされた人には「新しいフォロワー」のお知らせ。
 * 公開プロフィールの会員のリポストは、記事の下の「この記事を広めた人」にも出る。
 * 運営は、ユーザーの編集画面で公開プロフィールを止められる（ひとことが不適切な場合など）。
 * 発信者は、発信者ページが公開プロフィールの代わり（発信者をフォローするのは SNSCS_Social）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_People {

	const META_PUBLIC   = 'snscs_public';
	const META_NICKNAME = 'snscs_nickname';
	const META_BIO      = 'snscs_bio';
	const META_BANNED   = 'snscs_profile_banned';

	public static function init() {
		add_action( 'admin_post_snscs_profile_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'admin_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'admin_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'admin_save' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'admin_save' ) );
		add_action( 'deleted_user', array( __CLASS__, 'on_deleted_user' ) );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_user_follows';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  follower_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (follower_id,user_id),
  KEY user_id (user_id)
) {$charset};"
		);
	}

	/* --------------------------------------------------------------------
	 * プロフィール
	 * ------------------------------------------------------------------ */

	/**
	 * 公開プロフィールを持つ読者か（発信者は発信者ページがあるため対象外）。
	 */
	public static function is_public( $user_id ) {
		return $user_id && get_userdata( $user_id ) && get_user_meta( $user_id, self::META_PUBLIC, true ) && ! get_user_meta( $user_id, self::META_BANNED, true ) && ! SNSCS_Social::own_creator( $user_id );
	}

	public static function nickname( $user_id ) {
		$name = (string) get_user_meta( $user_id, self::META_NICKNAME, true );
		return '' !== $name ? $name : '読者' . $user_id;
	}

	public static function profile_url( $user_id ) {
		return SNSCS_Social::member_url( array( 'user' => (int) $user_id ) );
	}

	public static function follower_count( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE user_id = %d', $user_id ) );
	}

	public static function following_count( $user_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE follower_id = %d', $user_id ) )
			+ (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SNSCS_Social::follows_table() . ' WHERE user_id = %d', $user_id ) );
		// phpcs:enable
	}

	/**
	 * フォローしている会員（公開プロフィールの人だけ）。
	 *
	 * @return int[]
	 */
	public static function following_ids( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . self::table() . ' WHERE follower_id = %d ORDER BY created_at DESC LIMIT 2000', $user_id ) ) );
		return array_values( array_filter( $ids, array( __CLASS__, 'is_public' ) ) );
	}

	public static function is_following( $follower_id, $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::table() . ' WHERE follower_id = %d AND user_id = %d', $follower_id, $user_id ) );
	}

	/**
	 * 会員をフォローする・やめる（SNSCS_Social::toggle から）。
	 *
	 * @return array|WP_Error
	 */
	public static function toggle( $follower_id, $user_id, $on ) {
		global $wpdb;
		if ( $follower_id === $user_id ) {
			return new WP_Error( 'self', '自分自身はフォローできません。', array( 'status' => 400 ) );
		}
		if ( $on && ! self::is_public( $user_id ) ) {
			return new WP_Error( 'not_found', 'このユーザーはフォローできません。', array( 'status' => 404 ) );
		}
		if ( $on ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$new = (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (follower_id, user_id, created_at) VALUES (%d, %d, %s)', $follower_id, $user_id, current_time( 'mysql' ) ) );
			if ( $new ) {
				SNSCS_Inbox::add( $user_id, 'new_user_follower' );
			}
		} else {
			$wpdb->delete( self::table(), array( 'follower_id' => $follower_id, 'user_id' => $user_id ), array( '%d', '%d' ) );
		}
		return array( 'on' => (bool) $on, 'count' => self::follower_count( $user_id ) );
	}

	/**
	 * 公開プロフィールの会員がリポストしたら、その人のフォロワーへ（SNSCS_Inbox のお知らせ）。
	 */
	public static function on_repost( $user_id, $article_id ) {
		global $wpdb;
		if ( ! self::is_public( $user_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . SNSCS_Inbox::table() . ' (user_id, type, object_id, actor_id, day, created_at, emailed) SELECT follower_id, %s, %d, %d, %s, %s, 1 FROM ' . self::table() . ' WHERE user_id = %d', 'user_repost', $article_id, $user_id, current_time( 'Y-m-d' ), current_time( 'mysql' ), $user_id ) );
		do_action( 'snscs_inbox_added' );
	}

	public static function on_deleted_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'follower_id' => $user_id ), array( '%d' ) );
		$wpdb->delete( self::table(), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/* --------------------------------------------------------------------
	 * 画面
	 * ------------------------------------------------------------------ */

	public static function follow_button( $user_id ) {
		$user_id = (int) $user_id;
		$me      = get_current_user_id();
		if ( $me === $user_id ) {
			return '';
		}
		$on = $me && self::is_following( $me, $user_id );
		return sprintf(
			'<div class="snscs-follow"><button type="button" class="snscs-follow__button%s" data-snscs-social="follow_user" data-id="%d" aria-pressed="%s"><span data-label-on="フォロー中" data-label-off="フォローする">%s</span></button> <span class="snscs-follow__count"><span data-snscs-count>%s</span>人がフォロー</span></div>',
			$on ? ' is-on' : '',
			$user_id,
			$on ? 'true' : 'false',
			$on ? 'フォロー中' : 'フォローする',
			esc_html( number_format_i18n( self::follower_count( $user_id ) ) )
		);
	}

	/**
	 * プロフィールのページ（会員ページの ?user=ID）。
	 */
	public static function render_profile( $user_id ) {
		if ( ! self::is_public( $user_id ) ) {
			$creator = SNSCS_Social::own_creator( $user_id );
			if ( $creator && 'publish' === get_post_status( $creator ) ) {
				echo '<p><a href="' . esc_url( get_permalink( $creator ) ) . '">この人の発信者ページを見る</a></p>';
				return;
			}
			echo '<p class="snscs-notice">このユーザーのプロフィールは公開されていません。</p>';
			return;
		}
		$name = self::nickname( $user_id );
		$bio  = (string) get_user_meta( $user_id, self::META_BIO, true );
		$days = max( 1, (int) floor( ( time() - strtotime( get_userdata( $user_id )->user_registered ) ) / DAY_IN_SECONDS ) );
		?>
		<section class="snscs-profile">
			<div class="snscs-profile__head">
				<span class="snscs-profile__avatar" aria-hidden="true"><?php echo esc_html( mb_substr( $name, 0, 1 ) ); ?></span>
				<div>
					<h2 class="snscs-profile__name"><?php echo esc_html( $name ); ?></h2>
					<p class="snscs-profile__stats">フォロー <?php echo esc_html( number_format_i18n( self::following_count( $user_id ) ) ); ?>　フォロワー <?php echo esc_html( number_format_i18n( self::follower_count( $user_id ) ) ); ?>　登録から<?php echo esc_html( number_format_i18n( $days ) ); ?>日</p>
				</div>
			</div>
			<?php if ( '' !== $bio ) : ?>
				<p class="snscs-profile__bio"><?php echo esc_html( $bio ); ?></p>
			<?php endif; ?>
			<?php echo self::follow_button( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>
			<?php echo SNSCS_Gamify::summary_html( $user_id, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>
		</section>
		<?php
		$lists = array_filter(
			SNSCS_Lists::of_user( $user_id ),
			function ( $l ) {
				return $l['status'] && $l['items'] > 0;
			}
		);
		if ( $lists ) {
			echo '<h2 class="snscs-single__heading">推しリスト</h2><ul class="snscs-lists">';
			foreach ( $lists as $l ) {
				echo '<li class="snscs-lists__item"><a class="snscs-lists__link" href="' . esc_url( SNSCS_Lists::url( (int) $l['id'] ) ) . '"><span class="snscs-lists__title">' . esc_html( $l['title'] ) . '</span><span class="snscs-lists__meta">' . esc_html( (int) $l['items'] . '人・♥ ' . (int) $l['likes'] ) . '</span></a></li>';
			}
			echo '</ul>';
		}
		?>
		<h2 class="snscs-single__heading">リポストした記事</h2>
		<?php
		$items = SNSCS_Social::reacted( $user_id, SNSCS_Social::REPOST );
		foreach ( $items as $i => $item ) {
			$items[ $i ]['ru'] = $user_id;
		}
		echo SNSCS_Social::render_list( array_slice( $items, 0, SNSCS_Social::PER_PAGE ), 'まだリポストした記事はありません。' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
	}

	/**
	 * 会員ページ「アカウント」のプロフィール設定（読者だけ）。
	 */
	/**
	 * ハンドルネーム（表示名）をそろえる: 前後の空白・改行・タグを除き、30文字まで。
	 */
	public static function clean_nickname( $raw ) {
		return mb_substr( trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $raw ) ) ), 0, 30 );
	}

	public static function render_settings( $user_id ) {
		if ( SNSCS_Social::own_creator( $user_id ) ) {
			return;
		}
		$public = (bool) get_user_meta( $user_id, self::META_PUBLIC, true );
		$banned = (bool) get_user_meta( $user_id, self::META_BANNED, true );
		?>
		<section class="snscs-panel">
			<h2 class="snscs-panel__title">公開プロフィール</h2>
			<p>公開すると、ほかの会員があなたをフォローでき、あなたのリポスト（ひとこと付き）がフォロワーに届きます。いいね・ブックマーク・メールアドレスは公開されません。</p>
			<?php if ( $banned ) : ?>
				<p class="snscs-notice snscs-notice--error">運営の判断で、プロフィールの公開を止めています。</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>" class="snscs-form">
				<input type="hidden" name="action" value="snscs_profile_save">
				<?php wp_nonce_field( 'snscs_profile_save', 'snscs_nonce' ); ?>
				<p><label><input type="checkbox" name="public" value="1" <?php checked( $public ); ?>> プロフィールを公開する</label></p>
				<p><label>ハンドルネーム（表示名・30文字まで）<br><input type="text" name="nickname" maxlength="30" value="<?php echo esc_attr( (string) get_user_meta( $user_id, self::META_NICKNAME, true ) ); ?>"></label></p>
				<p><label>ひとこと紹介（160文字まで）<br><textarea name="bio" maxlength="160" rows="3"><?php echo esc_textarea( (string) get_user_meta( $user_id, self::META_BIO, true ) ); ?></textarea></label></p>
				<p><button type="submit" class="snscs-button">保存</button>
					<?php if ( $public && ! $banned ) : ?>
						<a href="<?php echo esc_url( self::profile_url( $user_id ) ); ?>">公開中のプロフィールを見る</a>
					<?php endif; ?>
				</p>
			</form>
		</section>
		<?php
	}

	public static function handle_save() {
		if ( ! is_user_logged_in() || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_profile_save' ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$user_id  = get_current_user_id();
		$nickname = isset( $_POST['nickname'] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( $_POST['nickname'] ) ) ), 0, 30 ) : '';
		$bio      = isset( $_POST['bio'] ) ? mb_substr( trim( sanitize_textarea_field( wp_unslash( $_POST['bio'] ) ) ), 0, 160 ) : '';
		$public   = ! empty( $_POST['public'] );
		if ( $public && '' === $nickname ) {
			wp_safe_redirect( SNSCS_Social::member_url( array( 'tab' => 'account', 'snscs_notice' => 'profile_name' ) ) );
			exit;
		}
		update_user_meta( $user_id, self::META_NICKNAME, $nickname );
		if ( '' !== $nickname ) {
			wp_update_user( array( 'ID' => $user_id, 'display_name' => $nickname ) );
		}
		update_user_meta( $user_id, self::META_BIO, $bio );
		update_user_meta( $user_id, self::META_PUBLIC, $public ? 1 : 0 );
		wp_safe_redirect( SNSCS_Social::member_url( array( 'tab' => 'account', 'snscs_notice' => 'profile_saved' ) ) );
		exit;
	}

	public static function admin_field( $user ) {
		if ( ! current_user_can( 'edit_users' ) || ! get_user_meta( $user->ID, self::META_PUBLIC, true ) ) {
			return;
		}
		?>
		<h2>公開プロフィール（SNS Creator Search）</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th>ニックネーム・紹介</th>
				<td><?php echo esc_html( self::nickname( $user->ID ) ); ?><br><span class="description"><?php echo esc_html( (string) get_user_meta( $user->ID, self::META_BIO, true ) ); ?></span></td>
			</tr>
			<tr>
				<th>公開の停止</th>
				<td><label><input type="checkbox" name="snscs_profile_banned" value="1" <?php checked( (bool) get_user_meta( $user->ID, self::META_BANNED, true ) ); ?>> 公開プロフィールを止める（リポストのひとことも表示されなくなります）</label></td>
			</tr>
		</table>
		<?php
	}

	public static function admin_save( $user_id ) {
		if ( ! current_user_can( 'edit_users' ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-user_' . $user_id ) ) {
			return;
		}
		if ( ! get_user_meta( $user_id, self::META_PUBLIC, true ) ) {
			return;
		}
		update_user_meta( $user_id, self::META_BANNED, empty( $_POST['snscs_profile_banned'] ) ? 0 : 1 );
	}

	/**
	 * プロフィールのページのタイトル。
	 */
	public static function document_title( $title ) {
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $user_id && SNSCS_Hub::page_id( 'member' ) && is_page( SNSCS_Hub::page_id( 'member' ) ) && self::is_public( $user_id ) ) {
			return self::nickname( $user_id ) . 'さんのプロフィール | ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}
		return $title;
	}
}
