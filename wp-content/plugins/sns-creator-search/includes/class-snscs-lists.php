<?php
/**
 * 会員が作る「推しリスト」（例: 私の推し料理インスタグラマー5選）。
 *
 *   作る   : 発信者ページの「推しリストに追加」から（新しいリストもその場で作れる）。会員ページ「推しリスト」で
 *            題名・説明・ひとこと・並び順を直し、外し、消せる。1人20リスト・1リスト30人まで。
 *   見る   : [sns_lists]（機能ページ「みんなの推しリスト」）。人気・新着、ジャンルで絞り込み。
 *            /lists/{番号}/ がリストのページ（ひとこと付きのカード・いいね・シェア・似ている発信者・ほかのリスト）。
 *            シェアするとカードに画像（1200×630。題名と上位3人）が出る。
 *   広がる : リストに入った発信者にお知らせ（1リスト1回）。発信者がシェアしてくれる。
 *   検索   : 3人以上・説明が20字以上・作った人が登録3日以上・非表示でないリストだけ検索エンジンに載せ、サイトマップに入れる。
 *   レベル : 作る・追加するとポイント（SNSCS_Gamify。snscs_points の動き）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Lists {

	const QUERY_VAR  = 'snscs_list';
	const IMAGE_VAR  = 'snscs_list_img';
	const MAX_LISTS  = 20;
	const MAX_ITEMS  = 30;
	const SEEN_KIND  = 9;

	public static function init() {
		SNSCS_Subpages::route( 'lists', self::QUERY_VAR );
		SNSCS_Subpages::sitemap( 'snscslists', array( __CLASS__, 'sitemap_urls' ), array( __CLASS__, 'sitemap_count' ) );
		add_action( 'template_redirect', array( __CLASS__, 'resolve' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'serve_image' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'ogp' ), 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_script' ) );
		add_action( 'admin_post_snscs_list_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_snscs_list_delete', array( __CLASS__, 'handle_delete' ) );
		add_shortcode( 'sns_lists', array( __CLASS__, 'shortcode' ) );
		foreach ( array( 'wpseo_opengraph_image', 'wpseo_twitter_image', 'rank_math/opengraph/facebook/image', 'rank_math/opengraph/twitter/image' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'seo_image' ) );
		}
	}

	/* --------------------------------------------------------------------
	 * テーブル
	 * ------------------------------------------------------------------ */

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_lists';
	}

	public static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_list_items';
	}

	public static function likes_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_list_likes';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$lists   = self::table();
		$items   = self::items_table();
		$likes   = self::likes_table();
		dbDelta(
			"CREATE TABLE {$lists} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  title varchar(80) NOT NULL,
  description varchar(300) NOT NULL DEFAULT '',
  status tinyint(1) unsigned NOT NULL DEFAULT 1,
  items smallint(5) unsigned NOT NULL DEFAULT 0,
  likes int(10) unsigned NOT NULL DEFAULT 0,
  views int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY status_likes (status,likes)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$items} (
  list_id bigint(20) unsigned NOT NULL,
  creator_id bigint(20) unsigned NOT NULL,
  comment varchar(140) NOT NULL DEFAULT '',
  position smallint(5) unsigned NOT NULL DEFAULT 0,
  added_at datetime NOT NULL,
  PRIMARY KEY  (list_id,creator_id),
  KEY creator_id (creator_id)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$likes} (
  list_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (list_id,user_id)
) {$charset};"
		);
	}

	/* --------------------------------------------------------------------
	 * 読み書き
	 * ------------------------------------------------------------------ */

	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	/**
	 * リストの発信者（並び順。掲載中の人だけ）。
	 *
	 * @return array<int,array{creator_id:int,comment:string,position:int}>
	 */
	public static function items( $list_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT i.creator_id, i.comment, i.position FROM ' . self::items_table() . " i INNER JOIN {$wpdb->posts} p ON p.ID = i.creator_id AND p.post_status = 'publish' WHERE i.list_id = %d ORDER BY i.position ASC, i.added_at ASC", $list_id ), ARRAY_A );
		return array_map(
			function ( $r ) {
				return array( 'creator_id' => (int) $r['creator_id'], 'comment' => (string) $r['comment'], 'position' => (int) $r['position'] );
			},
			$rows
		);
	}

	public static function of_user( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY updated_at DESC', $user_id ), ARRAY_A );
	}

	private static function recount( $list_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::items_table() . ' WHERE list_id = %d', $list_id ) );
		$likes = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::likes_table() . ' WHERE list_id = %d', $list_id ) );
		// phpcs:enable
		$wpdb->update( self::table(), array( 'items' => $items, 'likes' => $likes, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $list_id ) );
	}

	/**
	 * 新しいリストを作る。
	 *
	 * @return int|WP_Error
	 */
	public static function create( $user_id, $title, $description = '' ) {
		global $wpdb;
		$title       = SNSCS_UGC::clean( $title, 60 );
		$description = SNSCS_UGC::clean( $description, 300, true );
		if ( mb_strlen( $title ) < 2 ) {
			return new WP_Error( 'snscs_list', 'リストの題名を2文字以上で入れてください。' );
		}
		if ( SNSCS_UGC::blocked( $title . ' ' . $description ) ) {
			return new WP_Error( 'snscs_list', '使えない言葉が含まれています。' );
		}
		if ( count( self::of_user( $user_id ) ) >= self::MAX_LISTS ) {
			return new WP_Error( 'snscs_list', 'リストは' . self::MAX_LISTS . '個までです。' );
		}
		$now = current_time( 'mysql' );
		$wpdb->insert( self::table(), array( 'user_id' => $user_id, 'title' => $title, 'description' => $description, 'created_at' => $now, 'updated_at' => $now ) );
		// ポイントの記録で insert_id が変わるので、先に取っておく。
		$id = (int) $wpdb->insert_id;
		do_action( 'snscs_points', $user_id, 'list' );
		return $id;
	}

	/**
	 * 発信者をリストに足す（入ったことを発信者に知らせる）。
	 *
	 * @return true|WP_Error
	 */
	public static function add( $user_id, $list_id, $creator_id ) {
		global $wpdb;
		$list = self::get( $list_id );
		if ( ! $list || (int) $list['user_id'] !== (int) $user_id ) {
			return new WP_Error( 'snscs_list', 'このリストには追加できません。' );
		}
		if ( SNSCS_Post_Types::POST_TYPE !== get_post_type( $creator_id ) || 'publish' !== get_post_status( $creator_id ) ) {
			return new WP_Error( 'snscs_list', 'この発信者は追加できません。' );
		}
		if ( (int) $list['items'] >= self::MAX_ITEMS ) {
			return new WP_Error( 'snscs_list', '1つのリストに入れられるのは' . self::MAX_ITEMS . '人までです。' );
		}
		$new = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::items_table() . ' (list_id, creator_id, comment, position, added_at) VALUES (%d, %d, %s, %d, %s)', $list_id, $creator_id, '', (int) $list['items'] + 1, current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::recount( $list_id );
		if ( $new ) {
			do_action( 'snscs_points', $user_id, 'list_item' );
			$owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'snscs_creator_id' AND meta_value = %d LIMIT 1", $creator_id ) );
			if ( $owner && $owner !== (int) $user_id ) {
				SNSCS_Inbox::add( $owner, 'listed', $list_id, $creator_id );
			}
		}
		return true;
	}

	/**
	 * いいね（つける・外す）。
	 *
	 * @return array{on:bool,likes:int}|WP_Error
	 */
	public static function toggle_like( $user_id, $list_id ) {
		global $wpdb;
		$list = self::get( $list_id );
		if ( ! $list || ! $list['status'] ) {
			return new WP_Error( 'snscs_list', 'このリストにはいいねできません。' );
		}
		$table = self::likes_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$on = ! $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE list_id = %d AND user_id = %d", $list_id, $user_id ) );
		if ( $on ) {
			$wpdb->insert( $table, array( 'list_id' => $list_id, 'user_id' => $user_id, 'created_at' => current_time( 'mysql' ) ) );
		} else {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE list_id = %d AND user_id = %d", $list_id, $user_id ) );
		}
		// phpcs:enable
		self::recount( $list_id );
		if ( $on ) {
			do_action( 'snscs_points', $user_id, 'react' );
		}
		return array( 'on' => $on, 'likes' => (int) self::get( $list_id )['likes'] );
	}

	/**
	 * 発信者のお知らせの文（listed: object = リスト）。
	 */
	public static function describe( array $row ) {
		$list = self::get( (int) $row['object_id'] );
		if ( ! $list || ! $list['status'] ) {
			return null;
		}
		return array( 'text' => SNSCS_UGC::author_name( (int) $list['user_id'] ) . 'さんの推しリスト「' . $list['title'] . '」に入りました。シェアしてお礼を伝えてみましょう。', 'url' => self::url( (int) $list['id'] ) );
	}

	/* --------------------------------------------------------------------
	 * REST（発信者ページの「推しリストに追加」・いいね）
	 * ------------------------------------------------------------------ */

	public static function register_routes() {
		$base = array( 'permission_callback' => 'is_user_logged_in' );
		register_rest_route( 'snscs/v1', '/lists/mine', $base + array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_mine' ), 'args' => array( 'creator' => array( 'type' => 'integer', 'default' => 0 ) ) ) );
		register_rest_route( 'snscs/v1', '/lists/add', $base + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_add' ) ) );
		register_rest_route( 'snscs/v1', '/lists/like', $base + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_like' ) ) );
	}

	public static function rest_mine( WP_REST_Request $request ) {
		global $wpdb;
		$creator = (int) $request['creator'];
		$out     = array();
		foreach ( self::of_user( get_current_user_id() ) as $list ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$has   = $creator ? (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::items_table() . ' WHERE list_id = %d AND creator_id = %d', $list['id'], $creator ) ) : false;
			$out[] = array( 'id' => (int) $list['id'], 'title' => $list['title'], 'items' => (int) $list['items'], 'has' => $has );
		}
		return new WP_REST_Response( $out, 200 );
	}

	public static function rest_add( WP_REST_Request $request ) {
		$user    = get_current_user_id();
		$list_id = (int) $request['list'];
		if ( ! $list_id ) {
			$list_id = self::create( $user, (string) $request['title'] );
			if ( is_wp_error( $list_id ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'message' => $list_id->get_error_message() ), 400 );
			}
		}
		$result = self::add( $user, $list_id, (int) $request['creator'] );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => $result->get_error_message() ), 400 );
		}
		$list = self::get( $list_id );
		return new WP_REST_Response( array( 'ok' => true, 'message' => '「' . $list['title'] . '」に追加しました。', 'url' => self::url( $list_id ), 'edit' => SNSCS_Social::member_url( array( 'tab' => 'lists' ) ) ), 200 );
	}

	public static function rest_like( WP_REST_Request $request ) {
		$result = self::toggle_like( get_current_user_id(), (int) $request['list'] );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => $result->get_error_message() ), 400 );
		}
		return new WP_REST_Response( array( 'ok' => true ) + $result, 200 );
	}

	public static function register_script() {
		wp_register_script( 'snscs-lists', SNSCS_URL . 'assets/js/snscs-lists.js', array(), SNSCS_VERSION, true );
		wp_localize_script(
			'snscs-lists',
			'snscsLists',
			array(
				'rest'  => esc_url_raw( rest_url( 'snscs/v1/lists' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * 発信者ページの「推しリストに追加」。
	 */
	public static function add_button( $creator_id ) {
		if ( ! SNSCS_Hub::page_id( 'lists' ) ) {
			return '';
		}
		wp_enqueue_script( 'snscs-lists' );
		if ( ! is_user_logged_in() ) {
			$url = SNSCS_Social::member_url( array( 'redirect_to' => get_permalink( $creator_id ) ) );
			return $url ? '<a class="snscs-button snscs-list-add" href="' . esc_url( $url ) . '">＋推しリストに追加</a>' : '';
		}
		return '<div class="snscs-list-add-wrap"><button type="button" class="snscs-button snscs-list-add" data-snscs-list-add="' . (int) $creator_id . '" aria-expanded="false">＋推しリストに追加</button><div class="snscs-list-add__panel" hidden></div></div>';
	}

	/* --------------------------------------------------------------------
	 * 会員ページ「推しリスト」
	 * ------------------------------------------------------------------ */

	private static function back( $notice ) {
		wp_safe_redirect( add_query_arg( array( 'tab' => 'lists', 'snscs_list_msg' => $notice ), SNSCS_Social::member_url() ) );
		exit;
	}

	public static function handle_save() {
		check_admin_referer( 'snscs_list_save', 'snscs_nonce' );
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() で確認済み。
		$user = get_current_user_id();
		$id   = absint( $_POST['list'] ?? 0 );
		if ( ! $user ) {
			self::back( 'error' );
		}
		if ( ! $id ) {
			$id = self::create( $user, (string) wp_unslash( $_POST['title'] ?? '' ), (string) wp_unslash( $_POST['description'] ?? '' ) );
			self::back( is_wp_error( $id ) ? 'error' : 'created' );
		}
		$list = self::get( $id );
		if ( ! $list || (int) $list['user_id'] !== $user ) {
			self::back( 'error' );
		}
		$title = SNSCS_UGC::clean( wp_unslash( $_POST['title'] ?? '' ), 60 );
		$desc  = SNSCS_UGC::clean( wp_unslash( $_POST['description'] ?? '' ), 300, true );
		if ( mb_strlen( $title ) < 2 || SNSCS_UGC::blocked( $title . ' ' . $desc ) ) {
			self::back( 'error' );
		}
		$wpdb->update( self::table(), array( 'title' => $title, 'description' => $desc ), array( 'id' => $id ) );
		$remove   = array_map( 'absint', (array) ( $_POST['remove'] ?? array() ) );
		$comments = (array) wp_unslash( $_POST['comment'] ?? array() );
		$order    = (array) ( $_POST['order'] ?? array() );
		foreach ( self::items( $id ) as $item ) {
			$c = $item['creator_id'];
			if ( in_array( $c, $remove, true ) ) {
				$wpdb->delete( self::items_table(), array( 'list_id' => $id, 'creator_id' => $c ) );
				continue;
			}
			$comment = SNSCS_UGC::clean( $comments[ $c ] ?? '', 140 );
			$wpdb->update( self::items_table(), array( 'comment' => SNSCS_UGC::blocked( $comment ) ? '' : $comment, 'position' => max( 0, min( 999, (int) ( $order[ $c ] ?? $item['position'] ) ) ) ), array( 'list_id' => $id, 'creator_id' => $c ) );
		}
		// phpcs:enable
		self::recount( $id );
		self::back( 'saved' );
	}

	public static function handle_delete() {
		check_admin_referer( 'snscs_list_delete', 'snscs_nonce' );
		global $wpdb;
		$id   = absint( $_POST['list'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$list = self::get( $id );
		if ( $list && (int) $list['user_id'] === get_current_user_id() ) {
			$wpdb->delete( self::table(), array( 'id' => $id ) );
			$wpdb->delete( self::items_table(), array( 'list_id' => $id ) );
			$wpdb->delete( self::likes_table(), array( 'list_id' => $id ) );
		}
		self::back( 'deleted' );
	}

	public static function render_member( $user_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg   = isset( $_GET['snscs_list_msg'] ) ? sanitize_key( $_GET['snscs_list_msg'] ) : '';
		$notes = array( 'created' => 'リストを作りました。発信者ページの「推しリストに追加」から発信者を入れましょう。', 'saved' => '保存しました。', 'deleted' => 'リストを消しました。', 'error' => '保存できませんでした（題名は2文字以上、使えない言葉は入れられません）。' );
		if ( isset( $notes[ $msg ] ) ) {
			echo '<p class="snscs-notice' . ( 'error' === $msg ? ' snscs-notice--error' : '' ) . '" role="status">' . esc_html( $notes[ $msg ] ) . '</p>';
		}
		$lists = self::of_user( $user_id );
		?>
		<p>好きな発信者をまとめて、紹介しましょう。リストに入った発信者にはお知らせが届きます。3人以上入れて説明を書くと、検索からも見つけてもらえます。</p>
		<?php foreach ( $lists as $list ) : ?>
			<?php $items = self::items( (int) $list['id'] ); ?>
			<details class="snscs-list-edit">
				<summary><strong><?php echo esc_html( $list['title'] ); ?></strong>（<?php echo (int) $list['items']; ?>人・いいね<?php echo (int) $list['likes']; ?>）<?php echo $list['status'] ? '' : ' <span class="snscs-badge">非表示</span>'; ?> — <a href="<?php echo esc_url( self::url( (int) $list['id'] ) ); ?>">ページを見る</a></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="snscs_list_save">
					<input type="hidden" name="list" value="<?php echo (int) $list['id']; ?>">
					<?php wp_nonce_field( 'snscs_list_save', 'snscs_nonce' ); ?>
					<p><label>題名<input class="snscs-input" type="text" name="title" maxlength="60" required value="<?php echo esc_attr( $list['title'] ); ?>"></label></p>
					<p><label>説明（このリストのおすすめポイント。300字まで）<textarea class="snscs-input" name="description" rows="3" maxlength="300"><?php echo esc_textarea( $list['description'] ); ?></textarea></label></p>
					<?php if ( $items ) : ?>
						<ol class="snscs-list-edit__items">
							<?php foreach ( $items as $item ) : ?>
								<?php $c = $item['creator_id']; ?>
								<li>
									<strong><?php echo esc_html( get_the_title( $c ) ); ?></strong>
									<label>順番 <input type="number" name="order[<?php echo (int) $c; ?>]" value="<?php echo (int) $item['position']; ?>" min="0" max="999" class="small-text"></label>
									<label><input type="checkbox" name="remove[]" value="<?php echo (int) $c; ?>"> 外す</label>
									<input class="snscs-input" type="text" name="comment[<?php echo (int) $c; ?>]" maxlength="140" value="<?php echo esc_attr( $item['comment'] ); ?>" placeholder="ひとこと（例: 毎週の作り置きが参考になります！）">
								</li>
							<?php endforeach; ?>
						</ol>
					<?php else : ?>
						<p class="snscs-help">まだ発信者が入っていません。発信者ページの「推しリストに追加」から入れましょう。</p>
					<?php endif; ?>
					<button type="submit" class="snscs-button snscs-button--primary">保存</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('このリストを消しますか？');">
					<input type="hidden" name="action" value="snscs_list_delete">
					<input type="hidden" name="list" value="<?php echo (int) $list['id']; ?>">
					<?php wp_nonce_field( 'snscs_list_delete', 'snscs_nonce' ); ?>
					<button type="submit" class="snscs-button">リストを消す</button>
				</form>
			</details>
		<?php endforeach; ?>
		<?php if ( count( $lists ) < self::MAX_LISTS ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="snscs-list-new">
				<input type="hidden" name="action" value="snscs_list_save">
				<?php wp_nonce_field( 'snscs_list_save', 'snscs_nonce' ); ?>
				<h3>新しいリストを作る</h3>
				<p><label>題名<input class="snscs-input" type="text" name="title" maxlength="60" required placeholder="例: 私の推し料理インスタグラマー5選"></label></p>
				<p><label>説明（任意）<textarea class="snscs-input" name="description" rows="2" maxlength="300"></textarea></label></p>
				<button type="submit" class="snscs-button snscs-button--primary">作る</button>
			</form>
		<?php endif; ?>
		<?php
	}

	/* --------------------------------------------------------------------
	 * 公開のページ
	 * ------------------------------------------------------------------ */

	public static function url( $list_id ) {
		return SNSCS_Subpages::url( 'lists', (int) $list_id );
	}

	private static function indexable( array $list ) {
		return $list['status'] && (int) $list['items'] >= 3 && mb_strlen( $list['description'] ) >= 20 && SNSCS_UGC::seasoned( (int) $list['user_id'] );
	}

	private static $current = null;

	public static function resolve() {
		$value = SNSCS_Subpages::value( 'lists' );
		if ( '' === $value ) {
			return;
		}
		$list = ctype_digit( $value ) ? self::get( (int) $value ) : null;
		if ( ! $list || ! $list['status'] ) {
			SNSCS_Subpages::not_found();
			return;
		}
		$items  = self::items( (int) $list['id'] );
		$author = SNSCS_UGC::author_name( (int) $list['user_id'] );
		$people = SNSCS_Personalize::items( wp_list_pluck( $items, 'creator_id' ) );
		$url    = self::url( (int) $list['id'] );
		$title  = $list['title'] . '｜' . $author . 'さんの推しリスト';
		$desc   = ( '' !== $list['description'] ? $list['description'] . ' ' : '' ) . implode( '、', array_slice( wp_list_pluck( $people, 'name' ), 0, 5 ) ) . 'など' . count( $people ) . '人の発信者を紹介する推しリストです。';
		$crumbs = array( array( home_url( '/' ), 'トップ' ), array( SNSCS_Hub::page_url( 'lists' ), 'みんなの推しリスト' ), array( $url, $list['title'] ) );
		$ld     = array();
		foreach ( $people as $i => $p ) {
			$ld[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'url' => $p['url'], 'name' => $p['name'] );
		}
		self::$current = array( 'list' => $list, 'items' => $items, 'people' => $people, 'author' => $author, 'crumbs' => $crumbs, 'url' => $url );
		SNSCS_Subpages::set_current(
			array(
				'page_key'    => 'lists',
				'title'       => $title,
				'heading'     => $list['title'],
				'description' => mb_substr( $desc, 0, 160 ),
				'url'         => $url,
				'indexable'   => self::indexable( $list ),
				'jsonld'      => array( SNSCS_Subpages::breadcrumb_ld( $crumbs ), array( '@type' => 'ItemList', 'name' => $list['title'], 'itemListElement' => $ld ) ),
			)
		);
		// 見られた数（同じ人・同じ日は1回）。
		if ( ! SNSCS_Referrals::is_bot_request() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . SNSCS_Feed::seen_table() . ' (day, visitor, article_id, kind) VALUES (%s, %s, %d, %d)', current_time( 'Y-m-d' ), substr( SNSCS_Referrals::visitor_hash(), 0, 16 ), $list['id'], self::SEEN_KIND ) ) ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET views = views + 1 WHERE id = %d', $list['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}

	public static function image_url( $list_id ) {
		return add_query_arg( self::IMAGE_VAR, (int) $list_id, home_url( '/' ) );
	}

	public static function seo_image( $image ) {
		return self::$current ? self::image_url( (int) self::$current['list']['id'] ) : $image;
	}

	public static function ogp() {
		if ( ! self::$current || defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
			return;
		}
		$c    = self::$current;
		$tags = array(
			'og:type'         => 'article',
			'og:title'        => $c['list']['title'] . '｜' . $c['author'] . 'さんの推しリスト',
			'og:description'  => SNSCS_Subpages::current()['description'],
			'og:url'          => $c['url'],
			'og:image'        => self::image_url( (int) $c['list']['id'] ),
			'og:image:width'  => '1200',
			'og:image:height' => '630',
			'twitter:card'    => 'summary_large_image',
		);
		foreach ( $tags as $property => $content ) {
			printf( '<meta %s="%s" content="%s">' . "\n", 0 === strpos( $property, 'twitter:' ) ? 'name' : 'property', esc_attr( $property ), esc_attr( $content ) );
		}
	}

	public static function serve_image() {
		if ( empty( $_GET[ self::IMAGE_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$list = self::get( absint( $_GET[ self::IMAGE_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$font = SNSCS_Share::font();
		if ( ! $list || ! $list['status'] || ! $font || ! function_exists( 'imagecreatetruecolor' ) ) {
			status_header( 404 );
			exit;
		}
		$w     = 1200;
		$h     = 630;
		$image = imagecreatetruecolor( $w, $h );
		imageantialias( $image, true );
		for ( $y = 0; $y < $h; $y++ ) {
			$t = $y / $h;
			imageline( $image, 0, $y, $w, $y, imagecolorallocate( $image, (int) ( 236 - 20 * $t ), (int) ( 72 - 10 * $t ), (int) ( 153 - 30 * $t ) ) );
		}
		$white = imagecolorallocate( $image, 255, 255, 255 );
		$soft  = imagecolorallocate( $image, 252, 231, 243 );
		$ink   = imagecolorallocate( $image, 17, 24, 39 );
		$pink  = imagecolorallocate( $image, 219, 39, 119 );
		$text  = function ( $str, $size, $x, $y, $color, $max ) use ( $image, $font ) {
			list( $str, $size ) = SNSCS_Share::fit( $str, $font, $size, $max );
			imagettftext( $image, $size, 0, $x, $y, $color, $font, $str );
		};
		$text( SNSCS_UGC::author_name( (int) $list['user_id'] ) . 'さんの推しリスト', 26, 70, 90, $soft, 1060 );
		$text( $list['title'], 50, 70, 170, $white, 1060 );
		SNSCS_Share::rounded_rect( $image, 60, 220, $w - 60, $h - 80, 24, $white );
		$y = 290;
		foreach ( array_slice( self::items( (int) $list['id'] ), 0, 3 ) as $i => $item ) {
			imagefilledellipse( $image, 118, $y - 14, 46, 46, $pink );
			$text( (string) ( $i + 1 ), 22, 110, $y - 3, $white, 40 );
			$text( html_entity_decode( get_the_title( $item['creator_id'] ), ENT_QUOTES, 'UTF-8' ), 32, 160, $y, $ink, 940 );
			$y += 80;
		}
		if ( (int) $list['items'] > 3 ) {
			$text( 'ほか' . ( (int) $list['items'] - 3 ) . '人', 22, 160, $y - 20, $pink, 400 );
		}
		$text( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '　' . wp_parse_url( home_url(), PHP_URL_HOST ), 22, 70, $h - 30, $soft, 1060 );
		header( 'Content-Type: image/png' );
		header( 'Cache-Control: public, max-age=3600' );
		imagepng( $image );
		imagedestroy( $image );
		exit;
	}

	/**
	 * 公開中のリスト（人気順か新着順。ジャンルで絞り込み）。
	 *
	 * @return array[]
	 */
	public static function public_lists( $order = 'popular', $genre_id = 0, $limit = 20, $offset = 0, $creator = 0 ) {
		global $wpdb;
		$join  = '';
		$where = 'l.status = 1 AND l.items > 0';
		if ( $genre_id ) {
			$join .= $wpdb->prepare( ' INNER JOIN ' . self::items_table() . " gi ON gi.list_id = l.id INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = gi.creator_id INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.term_id = %d", $genre_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( $creator ) {
			$join .= $wpdb->prepare( ' INNER JOIN ' . self::items_table() . ' ci ON ci.list_id = l.id AND ci.creator_id = %d', $creator ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$sort = 'new' === $order ? 'l.created_at DESC' : '(l.likes * 3 + l.views / 20 + l.items) DESC, l.id DESC';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT l.* FROM ' . self::table() . " l {$join} WHERE {$where} ORDER BY {$sort} LIMIT %d, %d", $offset, $limit ), ARRAY_A );
	}

	private static function list_cards( array $lists ) {
		if ( ! $lists ) {
			return '<p class="snscs-results__empty">まだリストがありません。</p>';
		}
		$html = '<ul class="snscs-lists">';
		foreach ( $lists as $list ) {
			$names = array_map(
				function ( $i ) {
					return html_entity_decode( get_the_title( $i['creator_id'] ), ENT_QUOTES, 'UTF-8' );
				},
				array_slice( self::items( (int) $list['id'] ), 0, 3 )
			);
			$html .= '<li class="snscs-lists__item"><a class="snscs-lists__link" href="' . esc_url( self::url( (int) $list['id'] ) ) . '"><span class="snscs-lists__title">' . esc_html( $list['title'] ) . '</span>'
				. '<span class="snscs-lists__meta">' . esc_html( SNSCS_UGC::author_name( (int) $list['user_id'] ) . 'さん・' . (int) $list['items'] . '人・♥ ' . (int) $list['likes'] ) . '</span>'
				. '<span class="snscs-lists__names">' . esc_html( implode( '、', $names ) . ( (int) $list['items'] > 3 ? ' ほか' : '' ) ) . '</span></a></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * 発信者ページ: この発信者が入っている推しリスト。
	 */
	public static function for_creator_html( $creator_id ) {
		$lists = self::public_lists( 'popular', 0, 5, 0, $creator_id );
		if ( ! $lists ) {
			return '';
		}
		return '<section class="snscs-lists-box"><h2 class="snscs-single__heading">この発信者が入っている推しリスト</h2>' . self::list_cards( $lists ) . '</section>';
	}

	public static function shortcode() {
		SNSCS_Hub::remember_page( 'lists' );
		wp_enqueue_style( 'snscs' );
		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		if ( self::$current ) {
			self::render_single( self::$current );
			return ob_get_clean();
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$order = isset( $_GET['lo'] ) && 'new' === $_GET['lo'] ? 'new' : 'popular';
		$genre = isset( $_GET['lg'] ) ? get_term_by( 'slug', sanitize_title( wp_unslash( $_GET['lg'] ) ), SNSCS_Post_Types::TAX_GENRE ) : null;
		// phpcs:enable
		$base   = SNSCS_Hub::page_url( 'lists' );
		$genres = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => true ) );
		?>
		<p class="snscs-feature__lead">会員のみなさんが作った、好きな発信者のまとめです。あなたも「推しリスト」を作って、好きな発信者を紹介しましょう（発信者ページの「推しリストに追加」から作れます）。</p>
		<nav class="snscs-member__tabs" aria-label="並べ方">
			<a class="snscs-chip" href="<?php echo esc_url( remove_query_arg( 'lo', add_query_arg( array() ) ) ); ?>" <?php echo 'popular' === $order ? 'aria-current="page"' : ''; ?>>人気</a>
			<a class="snscs-chip" href="<?php echo esc_url( add_query_arg( 'lo', 'new' ) ); ?>" <?php echo 'new' === $order ? 'aria-current="page"' : ''; ?>>新着</a>
		</nav>
		<ul class="snscs-chips">
			<li><a class="snscs-chip<?php echo $genre ? '' : ' is-current'; ?>" href="<?php echo esc_url( add_query_arg( 'lo', $order, $base ) ); ?>">すべて</a></li>
			<?php foreach ( is_wp_error( $genres ) ? array() : $genres as $g ) : ?>
				<li><a class="snscs-chip<?php echo $genre && $genre->term_id === $g->term_id ? ' is-current' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'lo' => $order, 'lg' => urldecode( $g->slug ) ), $base ) ); ?>"><?php echo esc_html( $g->name ); ?></a></li>
			<?php endforeach; ?>
		</ul>
		<?php
		echo self::list_cards( self::public_lists( $order, $genre ? (int) $genre->term_id : 0, 30 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		if ( is_user_logged_in() ) {
			echo '<p><a class="snscs-button snscs-button--primary" href="' . esc_url( SNSCS_Social::member_url( array( 'tab' => 'lists' ) ) ) . '">自分の推しリストを作る・直す</a></p>';
		} elseif ( SNSCS_Social::member_url() ) {
			echo '<p><a class="snscs-button snscs-button--primary" href="' . esc_url( SNSCS_Social::member_url( array( 'redirect_to' => $base ) ) ) . '">無料の会員登録をして推しリストを作る</a></p>';
		}
		return ob_get_clean();
	}

	private static function render_single( array $c ) {
		global $wpdb;
		$list  = $c['list'];
		$notes = array();
		foreach ( $c['items'] as $item ) {
			if ( '' !== $item['comment'] ) {
				$notes[ $item['creator_id'] ] = '「' . $item['comment'] . '」';
			}
		}
		$liked = is_user_logged_in() && $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::likes_table() . ' WHERE list_id = %d AND user_id = %d', $list['id'], get_current_user_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$text  = '「' . $list['title'] . '」' . $c['author'] . 'さんの推しリスト';
		$aurl  = SNSCS_UGC::author_url( (int) $list['user_id'] );
		wp_enqueue_script( 'snscs-lists' );
		echo SNSCS_Subpages::breadcrumbs_html( $c['crumbs'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		?>
		<div class="snscs-list-page">
			<p class="snscs-list-page__by">作った人: <?php echo $aurl ? '<a href="' . esc_url( $aurl ) . '">' . esc_html( $c['author'] ) . '</a>' : esc_html( $c['author'] ); ?>さん・<?php echo esc_html( mysql2date( 'Y年n月j日', $list['updated_at'] ) ); ?>更新</p>
			<?php if ( '' !== $list['description'] ) : ?>
				<p class="snscs-list-page__desc"><?php echo nl2br( esc_html( $list['description'] ) ); ?></p>
			<?php endif; ?>
			<div class="snscs-share">
				<?php if ( is_user_logged_in() ) : ?>
					<button type="button" class="snscs-button snscs-list-like<?php echo $liked ? ' is-on' : ''; ?>" data-snscs-list-like="<?php echo (int) $list['id']; ?>" aria-pressed="<?php echo $liked ? 'true' : 'false'; ?>">♥ <span><?php echo (int) $list['likes']; ?></span></button>
				<?php else : ?>
					<span class="snscs-button">♥ <?php echo (int) $list['likes']; ?></span>
				<?php endif; ?>
				<a class="snscs-share__button snscs-share__button--x" href="<?php echo esc_url( 'https://x.com/intent/tweet?' . http_build_query( array( 'text' => $text, 'url' => $c['url'] ) ) ); ?>" target="_blank" rel="noopener">Xでシェア</a>
				<a class="snscs-share__button snscs-share__button--threads" href="<?php echo esc_url( 'https://www.threads.net/intent/post?' . http_build_query( array( 'text' => $text . ' ' . $c['url'] ) ) ); ?>" target="_blank" rel="noopener">Threadsでシェア</a>
				<a class="snscs-share__button snscs-share__button--line" href="<?php echo esc_url( 'https://social-plugins.line.me/lineit/share?' . http_build_query( array( 'url' => $c['url'] ) ) ); ?>" target="_blank" rel="noopener">LINEで送る</a>
			</div>
			<?php if ( $c['people'] ) : ?>
				<?php
				$badges = array();
				foreach ( $c['people'] as $i => $p ) {
					$badges[ $p['id'] ] = ( $i + 1 ) . '';
				}
				SNSCS_Frontend::render_cards( $c['people'], 0, $badges, $notes );
				?>
			<?php else : ?>
				<p class="snscs-results__empty">まだ発信者が入っていません。</p>
			<?php endif; ?>
			<?php
			if ( $c['people'] ) {
				$similar = SNSCS_Similar::find( $c['people'][0]['id'], 12 );
				$ids     = array_slice( array_values( array_diff( array_keys( $similar ), wp_list_pluck( $c['people'], 'id' ) ) ), 0, 4 );
				if ( $ids ) {
					echo SNSCS_Recommend::render( 'このリストが好きな人におすすめの発信者', SNSCS_Personalize::items( $ids ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
				}
			}
			$others = array_filter(
				self::of_user( (int) $list['user_id'] ),
				function ( $l ) use ( $list ) {
					return (int) $l['id'] !== (int) $list['id'] && $l['status'] && $l['items'] > 0;
				}
			);
			if ( $others ) {
				echo '<h2 class="snscs-single__heading">' . esc_html( $c['author'] . 'さんのほかのリスト' ) . '</h2>' . self::list_cards( array_slice( $others, 0, 4 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
			}
			echo '<p><a href="' . esc_url( SNSCS_Hub::page_url( 'lists' ) ) . '">みんなの推しリストをもっと見る</a></p>';
			?>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------
	 * サイトマップ
	 * ------------------------------------------------------------------ */

	private static function sitemap_where() {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - SNSCS_Social::MIN_AGE_DAYS * DAY_IN_SECONDS );
		global $wpdb;
		return $wpdb->prepare( "l.status = 1 AND l.items >= 3 AND CHAR_LENGTH(l.description) >= 20 AND u.user_registered <= %s", $cutoff );
	}

	public static function sitemap_urls( $offset, $limit ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT l.id FROM ' . self::table() . " l INNER JOIN {$wpdb->users} u ON u.ID = l.user_id WHERE " . self::sitemap_where() . ' ORDER BY l.likes DESC, l.id DESC LIMIT %d, %d', $offset, $limit ) );
		return array_map( array( __CLASS__, 'url' ), array_map( 'intval', $ids ) );
	}

	public static function sitemap_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " l INNER JOIN {$wpdb->users} u ON u.ID = l.user_id WHERE " . self::sitemap_where() );
	}
}
