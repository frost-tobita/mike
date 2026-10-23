<?php
/**
 * お知らせ（会員への通知）。
 *
 * 届くもの:
 *   new_article      フォローしている発信者が記事を公開した（予約投稿の公開も含む）
 *   repost           フォローしている発信者がリポストした（サイト内だけ。メールでは送らない）
 *   bookmark_updated ブックマークした記事が更新された
 *   bookmark_unread  まだ読み終えていないブックマークがある（週1回）
 *   user_repost      フォローしている会員がリポストした（サイト内とスマホ通知。メールでは送らない）
 *   new_follower     （発信者へ）フォローされた。1日ごとにまとめる
 *   new_user_follower （公開プロフィールの会員へ）フォローされた
 *   reaction         （発信者へ）記事にいいね・リポストされた。記事ごと・1日ごとにまとめる
 *
 * 見る場所: 会員ページ「お知らせ」。サイト内の「会員ページ」へのリンクに未読の数が付く。
 * メール: 会員ごとに「すぐ」「1日1回まとめて（朝7時以降）」「送らない」を選べる（最初は「1日1回」）。
 *         メールの「配信停止」リンクは、ログインしなくても押せる。
 * 古いお知らせは90日で消す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Inbox {

	const CRON_HOOK   = 'snscs_inbox_cron';
	const META_MODE   = 'snscs_notify_email';
	const DIGEST_HOUR = 7;
	const KEEP_DAYS   = 90;

	/** メールで送る種類 */
	const EMAIL_TYPES = array( 'award', 'collab', 'collab_ok', 'qa_answer', 'qa_best', 'listed', 'monthly_report', 'new_article', 'bookmark_updated', 'bookmark_unread', 'new_follower', 'new_user_follower', 'reaction', 'biolink_week', 'biolink_nudge' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_publish' ), 10, 3 );
		add_action( 'snscs_article_updated', array( __CLASS__, 'on_article_updated' ) );
		add_action( 'snscs_social_changed', array( __CLASS__, 'on_social' ), 10, 4 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_snscs_inbox_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_nopriv_snscs_inbox_unsubscribe', array( __CLASS__, 'handle_unsubscribe' ) );
		add_action( 'admin_post_snscs_inbox_unsubscribe', array( __CLASS__, 'handle_unsubscribe' ) );
		add_action( 'deleted_user', array( __CLASS__, 'on_deleted_user' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_notifications';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  type varchar(20) NOT NULL,
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  count int(10) unsigned NOT NULL DEFAULT 1,
  day date NOT NULL,
  created_at datetime NOT NULL,
  read_at datetime DEFAULT NULL,
  emailed tinyint(1) unsigned NOT NULL DEFAULT 0,
  pushed tinyint(1) unsigned NOT NULL DEFAULT 0,
  lined tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY one_per_day (user_id,type,object_id,actor_id,day),
  KEY user_unread (user_id,read_at),
  KEY mail (emailed,created_at)
) {$charset};"
		);
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/* --------------------------------------------------------------------
	 * お知らせを作る
	 * ------------------------------------------------------------------ */

	/**
	 * 1件追加する（同じ日・同じ相手・同じ記事のものは回数を足してまとめる）。
	 */
	public static function add( $user_id, $type, $object_id = 0, $actor_id = 0 ) {
		global $wpdb;
		if ( ! $user_id ) {
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::table() . ' (user_id, type, object_id, actor_id, day, created_at) VALUES (%d, %s, %d, %d, %s, %s) ON DUPLICATE KEY UPDATE count = count + 1, created_at = VALUES(created_at), read_at = NULL, pushed = 0', $user_id, $type, $object_id, $actor_id, current_time( 'Y-m-d' ), current_time( 'mysql' ) ) );
		do_action( 'snscs_inbox_added' );
	}

	/**
	 * 発信者の記事が公開されたら、フォロワー全員にお知らせを作る（予約投稿が公開されたときも）。
	 */
	public static function on_publish( $new, $old, $post ) {
		if ( 'publish' !== $new || 'publish' === $old || SNSCS_Articles::POST_TYPE !== $post->post_type ) {
			return;
		}
		$creator = SNSCS_Articles::creator_of( $post->ID );
		if ( ! $creator || get_post_meta( $post->ID, '_snscs_notified', true ) ) {
			return;
		}
		update_post_meta( $post->ID, '_snscs_notified', 1 );
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (user_id, type, object_id, actor_id, day, created_at) SELECT user_id, %s, %d, %d, %s, %s FROM ' . SNSCS_Social::follows_table() . ' WHERE creator_id = %d', 'new_article', $post->ID, $creator, current_time( 'Y-m-d' ), current_time( 'mysql' ), $creator ) );
		do_action( 'snscs_inbox_added' );
	}

	/**
	 * 公開中の記事が更新されたら（変更の承認・すぐ反映）、ブックマークした人に知らせる。
	 */
	public static function on_article_updated( $article_id ) {
		global $wpdb;
		$creator = SNSCS_Articles::creator_of( $article_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (user_id, type, object_id, actor_id, day, created_at) SELECT user_id, %s, %d, %d, %s, %s FROM ' . SNSCS_Social::reactions_table() . ' WHERE article_id = %d AND type = %d', 'bookmark_updated', $article_id, $creator, current_time( 'Y-m-d' ), current_time( 'mysql' ), $article_id, SNSCS_Social::BOOKMARK ) );
		do_action( 'snscs_inbox_added' );
	}

	/**
	 * フォロー・いいね・リポストがあったとき（SNSCS_Social から）。
	 */
	public static function on_social( $user_id, $action, $id, $on ) {
		global $wpdb;
		if ( ! $on ) {
			return;
		}
		$owner_user = function ( $creator_id ) use ( $wpdb ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'snscs_creator_id' AND meta_value = %d LIMIT 1", $creator_id ) );
		};
		if ( 'follow' === $action ) {
			self::add( $owner_user( $id ), 'new_follower', $id );
			return;
		}
		if ( in_array( $action, array( 'like', 'repost' ), true ) && SNSCS_Articles::POST_TYPE === get_post_type( $id ) ) {
			self::add( $owner_user( SNSCS_Articles::creator_of( $id ) ), 'reaction', $id );
		}
		// 公開プロフィールの会員がリポストしたら、その人のフォロワーにも。
		if ( 'repost' === $action ) {
			SNSCS_People::on_repost( $user_id, $id );
		}
		// 発信者がリポストしたら、その発信者のフォロワーにも（サイト内だけ）。
		$creator = SNSCS_Social::own_creator( $user_id );
		if ( 'repost' === $action && $creator ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (user_id, type, object_id, actor_id, day, created_at, emailed) SELECT user_id, %s, %d, %d, %s, %s, 1 FROM ' . SNSCS_Social::follows_table() . ' WHERE creator_id = %d AND user_id <> %d', 'repost', $id, $creator, current_time( 'Y-m-d' ), current_time( 'mysql' ), $creator, $user_id ) );
			do_action( 'snscs_inbox_added' );
		}
	}

	public static function on_deleted_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/* --------------------------------------------------------------------
	 * 読む
	 * ------------------------------------------------------------------ */

	public static function unread_count( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE user_id = %d AND read_at IS NULL', $user_id ) );
	}

	public static function latest( $user_id, $limit = 50 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d', $user_id, $limit ), ARRAY_A );
	}

	public static function mark_read( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET read_at = %s WHERE user_id = %d AND read_at IS NULL', current_time( 'mysql' ), $user_id ) );
	}

	/**
	 * 1件を文章にする。
	 *
	 * @return array{text:string,url:string}|null
	 */
	public static function describe( array $row ) {
		$object = (int) $row['object_id'];
		$actor  = (int) $row['actor_id'];
		$count  = (int) $row['count'];
		$title  = $object ? html_entity_decode( get_the_title( $object ), ENT_QUOTES, 'UTF-8' ) : '';
		$name   = $actor ? html_entity_decode( get_the_title( $actor ), ENT_QUOTES, 'UTF-8' ) : '';
		switch ( $row['type'] ) {
			case 'new_article':
				return SNSCS_Social::is_article( $object ) ? array( 'text' => "{$name}さんが記事を公開しました:「{$title}」", 'url' => get_permalink( $object ) ) : null;
			case 'repost':
				return SNSCS_Social::is_article( $object ) ? array( 'text' => "{$name}さんがリポストしました:「{$title}」", 'url' => add_query_arg( 'rp', $actor, get_permalink( $object ) ) ) : null;
			case 'monthly_report':
				$month = SNSCS_Report::latest_month( $object );
				return '' !== $month ? array( 'text' => SNSCS_Report::month_label( $month ) . 'の成長レポートが届きました。画像をSNSでシェアしてみましょう。', 'url' => snscs_portal_url() . '#snscs-report' ) : null;
			case 'user_repost':
				return SNSCS_Social::is_article( $object ) && SNSCS_People::is_public( $actor ) ? array( 'text' => SNSCS_People::nickname( $actor ) . "さんがリポストしました:「{$title}」", 'url' => add_query_arg( 'ru', $actor, get_permalink( $object ) ) ) : null;
			case 'new_user_follower':
				return array( 'text' => $count > 1 ? "{$count}人に新しくフォローされました。" : '新しくフォローされました。', 'url' => SNSCS_People::profile_url( (int) $row['user_id'] ) );
			case 'bookmark_updated':
				return SNSCS_Social::is_article( $object ) ? array( 'text' => "ブックマークした記事が更新されました:「{$title}」", 'url' => get_permalink( $object ) ) : null;
			case 'bookmark_unread':
				return array( 'text' => "まだ読み終えていないブックマークが{$count}件あります。", 'url' => SNSCS_Social::member_url( array( 'tab' => 'bookmarks' ) ) );
			case 'new_follower':
				return array( 'text' => $count > 1 ? "{$count}人に新しくフォローされました。" : '新しくフォローされました。', 'url' => snscs_portal_url() );
			case 'trend':
				return SNSCS_Trend_Alerts::describe( $object );
			case 'award':
				$wins = SNSCS_Awards::wins( $object );
				return $wins ? array( 'text' => 'おめでとうございます！' . SNSCS_Awards::month_label( $wins[0]['month'] ) . 'の' . $wins[0]['genre']->name . 'アワードで' . $wins[0]['rank'] . '位になりました（' . $wins[0]['votes'] . '票）。受賞画像をSNSでシェアしましょう。', 'url' => snscs_portal_url() . '#snscs-award' ) : null;
			case 'collab':
				return SNSCS_Collab::describe( $object );
			case 'collab_ok':
				return SNSCS_Collab::describe( $object, true );
			case 'listed':
				return SNSCS_Lists::describe( $row );
			case 'qa_answer':
			case 'qa_best':
				return SNSCS_QA::describe( $row );
			case 'level_up':
			case 'badge':
			case 'streak':
			case 'invite_ok':
				return SNSCS_Gamify::describe( $row );
			case 'biolink_week':
			case 'biolink_nudge':
				return SNSCS_Biolink::describe( $row );
			case 'reaction':
				return SNSCS_Social::is_article( $object ) ? array( 'text' => "あなたの記事「{$title}」に、いいね・リポストが{$count}件ありました。", 'url' => get_permalink( $object ) ) : null;
		}
		return null;
	}

	public static function render( $user_id ) {
		$rows = self::latest( $user_id );
		self::mark_read( $user_id );
		if ( ! $rows ) {
			echo '<p class="snscs-help">お知らせはまだありません。発信者をフォローすると、新しい記事が公開されたときにここでお知らせします。</p>';
			return;
		}
		echo '<ul class="snscs-inbox">';
		foreach ( $rows as $row ) {
			$item = self::describe( $row );
			if ( ! $item ) {
				continue;
			}
			printf(
				'<li class="snscs-inbox__item%s"><a href="%s">%s</a><span class="snscs-inbox__when">%s前</span></li>',
				$row['read_at'] ? '' : ' is-unread',
				esc_url( $item['url'] ),
				esc_html( $item['text'] ),
				esc_html( human_time_diff( strtotime( $row['created_at'] ), strtotime( current_time( 'mysql' ) ) ) )
			);
		}
		echo '</ul>';
	}

	/* --------------------------------------------------------------------
	 * 未読の数（サイト内の「会員ページ」へのリンクに付ける）
	 * ------------------------------------------------------------------ */

	public static function register_routes() {
		register_rest_route(
			'snscs/v1',
			'/inbox/count',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					$response = rest_ensure_response( array( 'unread' => self::unread_count( get_current_user_id() ) ) );
					$response->header( 'Cache-Control', 'private, no-store' );
					return $response;
				},
				'permission_callback' => 'is_user_logged_in',
			)
		);
	}

	public static function enqueue() {
		if ( ! is_user_logged_in() || ! SNSCS_Social::member_url() ) {
			return;
		}
		wp_enqueue_style( 'snscs' );
		wp_enqueue_script( 'snscs-inbox', SNSCS_URL . 'assets/js/snscs-inbox.js', array(), SNSCS_VERSION, true );
		wp_add_inline_script(
			'snscs-inbox',
			'window.snscsInbox = ' . wp_json_encode(
				array(
					'count'  => esc_url_raw( rest_url( 'snscs/v1/inbox/count' ) ),
					'nonce'  => wp_create_nonce( 'wp_rest' ),
					'member' => SNSCS_Social::member_url(),
				)
			) . ';',
			'before'
		);
	}

	/* --------------------------------------------------------------------
	 * メール
	 * ------------------------------------------------------------------ */

	public static function mode( $user_id ) {
		$mode = (string) get_user_meta( $user_id, self::META_MODE, true );
		return in_array( $mode, array( 'instant', 'daily', 'off' ), true ) ? $mode : 'daily';
	}

	public static function unsubscribe_url( $user_id ) {
		return add_query_arg(
			array(
				'action' => 'snscs_inbox_unsubscribe',
				'u'      => $user_id,
				'k'      => substr( hash_hmac( 'sha256', 'inbox-unsub|' . $user_id, wp_salt( 'auth' ) ), 0, 24 ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	public static function handle_unsubscribe() {
		$user_id = isset( $_GET['u'] ) ? absint( $_GET['u'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key     = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ok      = $user_id && get_userdata( $user_id ) && hash_equals( substr( hash_hmac( 'sha256', 'inbox-unsub|' . $user_id, wp_salt( 'auth' ) ), 0, 24 ), $key );
		if ( $ok ) {
			update_user_meta( $user_id, self::META_MODE, 'off' );
		}
		wp_die( $ok ? 'お知らせメールの配信を停止しました。サイト内の「お知らせ」は引き続き見られます。会員ページの「アカウント」から、いつでも再開できます。' : 'このリンクは使えません。会員ページの「アカウント」から設定してください。', 'お知らせメール', array( 'response' => 200 ) );
	}

	public static function handle_settings() {
		if ( ! is_user_logged_in() || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_inbox_settings' ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'daily';
		update_user_meta( get_current_user_id(), self::META_MODE, in_array( $mode, array( 'instant', 'daily', 'off' ), true ) ? $mode : 'daily' );
		wp_safe_redirect( SNSCS_Social::member_url( array( 'tab' => 'account', 'snscs_notice' => 'inbox_saved' ) ) );
		exit;
	}

	public static function render_settings( $user_id ) {
		$mode    = self::mode( $user_id );
		$options = array(
			'instant' => 'すぐに送る（1時間ごとにまとめて）',
			'daily'   => '1日1回まとめて送る（朝）',
			'off'     => '送らない（サイト内のお知らせだけ）',
		);
		?>
		<section class="snscs-panel">
			<h2 class="snscs-panel__title">お知らせメール</h2>
			<p>フォローしている発信者の新しい記事や、ブックマークした記事の更新などを、メールでお知らせします。</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="snscs-form">
				<input type="hidden" name="action" value="snscs_inbox_settings">
				<?php wp_nonce_field( 'snscs_inbox_settings', 'snscs_nonce' ); ?>
				<?php foreach ( $options as $key => $label ) : ?>
					<p><label><input type="radio" name="mode" value="<?php echo esc_attr( $key ); ?>" <?php checked( $mode, $key ); ?>> <?php echo esc_html( $label ); ?></label></p>
				<?php endforeach; ?>
				<p><button type="submit" class="snscs-button">保存</button></p>
			</form>
		</section>
		<?php
	}

	/**
	 * 1時間ごと: 「すぐ」の人へ送る。朝7時以降の最初の1回: 「1日1回」の人へまとめて送る。
	 * 毎週月曜の朝: 読み終えていないブックマークのお知らせを作る。古いお知らせを消す。
	 *
	 * @return int 送った通数。
	 */
	public static function run( $force_digest = false ) {
		global $wpdb;
		$today  = current_time( 'Y-m-d' );
		$digest = $force_digest || ( (int) current_time( 'G' ) >= self::DIGEST_HOUR && get_option( 'snscs_inbox_digest' ) !== $today );
		if ( $digest ) {
			update_option( 'snscs_inbox_digest', $today, false );
			if ( '1' === current_time( 'N' ) || $force_digest ) {
				self::remind_bookmarks();
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - self::KEEP_DAYS * DAY_IN_SECONDS ) ) );
		}
		$types = "'" . implode( "','", self::EMAIL_TYPES ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . " WHERE emailed = 0 AND read_at IS NULL AND type IN ({$types}) ORDER BY user_id, created_at DESC LIMIT 5000", ARRAY_A );
		$by   = array();
		foreach ( $rows as $row ) {
			$by[ (int) $row['user_id'] ][] = $row;
		}
		$sent = 0;
		foreach ( $by as $user_id => $items ) {
			$mode = self::mode( $user_id );
			if ( 'daily' === $mode && ! $digest ) {
				continue;
			}
			$ids = array_map( 'intval', wp_list_pluck( $items, 'id' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'UPDATE ' . self::table() . ' SET emailed = 1 WHERE id IN (' . implode( ',', $ids ) . ')' );
			if ( 'off' !== $mode && self::send( $user_id, $items ) ) {
				$sent++;
			}
		}
		return $sent;
	}

	private static function send( $user_id, array $items ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		$lines = array();
		foreach ( array_slice( $items, 0, 20 ) as $row ) {
			$item = self::describe( $row );
			if ( $item ) {
				$lines[] = '・' . $item['text'] . "\n  " . $item['url'];
			}
		}
		if ( ! $lines ) {
			return false;
		}
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$first = self::describe( $items[0] );
		$title = count( $lines ) > 1 ? '新しいお知らせが' . count( $lines ) . '件あります' : ( $first ? mb_strimwidth( $first['text'], 0, 60, '…' ) : 'お知らせ' );
		$body  = implode(
			"\n",
			array_merge(
				array( '新しいお知らせです。', '' ),
				$lines,
				array(
					'',
					'お知らせの一覧: ' . SNSCS_Social::member_url( array( 'tab' => 'inbox' ) ),
					'',
					'――',
					'メールの頻度は、会員ページの「アカウント」で変えられます。',
					'配信を停止する: ' . self::unsubscribe_url( $user_id ),
				)
			)
		);
		return wp_mail( $user->user_email, '[' . $site . '] ' . $title, $body );
	}

	/**
	 * 2日以上前にブックマークして、まだ読み終えていない記事がある人に、週1回お知らせを作る。
	 */
	public static function remind_bookmarks() {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 2 * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT r.user_id, COUNT(*) AS n FROM ' . SNSCS_Social::reactions_table() . " r INNER JOIN {$wpdb->posts} p ON p.ID = r.article_id AND p.post_status = 'publish' WHERE r.type = %d AND r.read_at IS NULL AND r.created_at <= %s GROUP BY r.user_id", SNSCS_Social::BOOKMARK, $cutoff ), ARRAY_A );
		foreach ( $rows as $row ) {
			self::add( (int) $row['user_id'], 'bookmark_unread' );
			// 件数は「まとめた回数」ではなく、未読の数にする。
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET count = %d WHERE user_id = %d AND type = 'bookmark_unread' AND day = %s", (int) $row['n'], (int) $row['user_id'], current_time( 'Y-m-d' ) ) );
		}
		return count( $rows );
	}
}
