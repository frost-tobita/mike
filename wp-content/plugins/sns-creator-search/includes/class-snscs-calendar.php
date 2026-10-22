<?php
/**
 * SNS発信カレンダー（スケジュール管理）。
 *
 *   [sns_calendar]      カレンダーのページ（月の表示・予定の追加／編集／削除・完了のチェック）
 *   [sns_calendar_link] トップページなどに置く「今日の日付と近い予定・カレンダーを開く」ボタン
 *
 * 予定は2種類を重ねて表示する。
 *   みんなの予定 : 運営が登録するイベント（管理画面「発信者 → カレンダーの予定」）と、
 *                  投稿のネタになる記念日（バレンタイン、猫の日など。毎年自動）
 *   自分の予定   : 投稿予定・撮影・打ち合わせ・締切など。
 *                  ログイン中の人はサーバーに保存（どの端末からも見られる。当日の朝にメールでお知らせも可）、
 *                  ログインしていない人はこのブラウザの中にだけ保存する。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Calendar {

	const EVENT_TYPE = 'snscs_event';
	const CRON_HOOK  = 'snscs_calendar_remind';
	const MAX_ITEMS  = 3000;
	const MAX_TITLE  = 80;
	const MAX_MEMO   = 1000;
	const REMIND_HOUR = 7;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_shortcode( 'sns_calendar', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'sns_calendar_link', array( __CLASS__, 'shortcode_link' ) );
		add_action( 'add_meta_boxes_' . self::EVENT_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . self::EVENT_TYPE, array( __CLASS__, 'save_meta' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'send_reminders' ) );
		add_action( 'delete_user', array( __CLASS__, 'delete_for_user' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_schedules';
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
  day date NOT NULL,
  time_at varchar(5) NOT NULL DEFAULT '',
  title varchar(200) NOT NULL,
  category varchar(20) NOT NULL DEFAULT 'post',
  sns varchar(20) NOT NULL DEFAULT '',
  memo text NOT NULL,
  done tinyint(1) unsigned NOT NULL DEFAULT 0,
  remind tinyint(1) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_day (user_id,day),
  KEY remind (remind,day)
) {$charset};"
		);
	}

	public static function categories() {
		return apply_filters(
			'snscs_calendar_categories',
			array(
				'post'    => array( 'label' => '投稿予定', 'color' => '#2563eb' ),
				'shoot'   => array( 'label' => '撮影・制作', 'color' => '#7c3aed' ),
				'meeting' => array( 'label' => '打ち合わせ', 'color' => '#059669' ),
				'live'    => array( 'label' => 'ライブ配信', 'color' => '#dc2626' ),
				'due'     => array( 'label' => '締切', 'color' => '#d97706' ),
				'other'   => array( 'label' => 'その他', 'color' => '#6b7280' ),
			)
		);
	}

	/* --------------------------------------------------------------------
	 * みんなの予定（運営のイベントと記念日）
	 * ------------------------------------------------------------------ */

	public static function register() {
		register_post_type(
			self::EVENT_TYPE,
			array(
				'labels'       => array(
					'name'          => 'カレンダーの予定',
					'singular_name' => 'カレンダーの予定',
					'add_new_item'  => '予定を追加',
					'edit_item'     => '予定を編集',
					'all_items'     => 'カレンダーの予定',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE,
				'supports'     => array( 'title', 'excerpt' ),
			)
		);
	}

	public static function add_meta_box() {
		add_meta_box( 'snscs-event', '日付とリンク', array( __CLASS__, 'render_meta_box' ), self::EVENT_TYPE, 'normal', 'high' );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'snscs_event_meta', 'snscs_event_nonce' );
		?>
		<p><label>開始日 <input type="date" name="snscs_event_start" value="<?php echo esc_attr( get_post_meta( $post->ID, '_snscs_event_start', true ) ); ?>" required></label>
		<label>終了日（1日だけなら空欄） <input type="date" name="snscs_event_end" value="<?php echo esc_attr( get_post_meta( $post->ID, '_snscs_event_end', true ) ); ?>"></label></p>
		<p><label>詳しいページのURL（任意） <input type="url" name="snscs_event_url" value="<?php echo esc_attr( get_post_meta( $post->ID, '_snscs_event_url', true ) ); ?>" class="large-text"></label></p>
		<p class="description">公開すると、カレンダーを見る全員に「みんなの予定」として表示されます。説明は「抜粋」に書いてください。</p>
		<?php
	}

	public static function save_meta( $post_id ) {
		if ( ! isset( $_POST['snscs_event_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_event_nonce'] ) ), 'snscs_event_meta' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( array( 'start', 'end' ) as $key ) {
			$value = isset( $_POST[ 'snscs_event_' . $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'snscs_event_' . $key ] ) ) : '';
			update_post_meta( $post_id, '_snscs_event_' . $key, preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '' );
		}
		update_post_meta( $post_id, '_snscs_event_url', isset( $_POST['snscs_event_url'] ) ? esc_url_raw( wp_unslash( $_POST['snscs_event_url'] ) ) : '' );
	}

	/**
	 * 投稿のネタになる記念日（毎年）。
	 *
	 * @return array<int,array{0:string,1:string}> ['月-日' または '月-第n-曜日', 名前]
	 */
	public static function anniversaries() {
		return apply_filters(
			'snscs_calendar_anniversaries',
			array(
				array( '01-01', '元日' ),
				array( '02-03', '節分' ),
				array( '02-14', 'バレンタインデー' ),
				array( '02-22', '猫の日' ),
				array( '03-03', 'ひな祭り' ),
				array( '03-14', 'ホワイトデー' ),
				array( '04-01', 'エイプリルフール' ),
				array( '05-05', 'こどもの日' ),
				array( '05-2-0', '母の日' ),
				array( '06-3-0', '父の日' ),
				array( '07-07', '七夕' ),
				array( '08-11', '山の日' ),
				array( '09-3-1', '敬老の日' ),
				array( '10-31', 'ハロウィン' ),
				array( '11-11', 'ポッキー＆プリッツの日' ),
				array( '11-22', 'いい夫婦の日' ),
				array( '12-24', 'クリスマスイブ' ),
				array( '12-25', 'クリスマス' ),
				array( '12-31', '大みそか' ),
			)
		);
	}

	/**
	 * 「5月の第2日曜日」などを日付にする。
	 */
	private static function nth_weekday( $year, $month, $nth, $weekday ) {
		$first  = (int) gmdate( 'w', gmmktime( 0, 0, 0, $month, 1, $year ) );
		$offset = ( $weekday - $first + 7 ) % 7;
		return sprintf( '%04d-%02d-%02d', $year, $month, 1 + $offset + 7 * ( $nth - 1 ) );
	}

	/**
	 * 期間内のみんなの予定。
	 *
	 * @return array<int,array{date:string,end:string,title:string,desc:string,url:string,kind:string}>
	 */
	public static function public_events( $from, $to ) {
		$events = array();
		$years  = range( (int) substr( $from, 0, 4 ), (int) substr( $to, 0, 4 ) );
		foreach ( $years as $year ) {
			foreach ( self::anniversaries() as $day ) {
				$parts = explode( '-', $day[0] );
				$date  = 3 === count( $parts ) ? self::nth_weekday( $year, (int) $parts[0], (int) $parts[1], (int) $parts[2] ) : sprintf( '%04d-%s', $year, $day[0] );
				if ( $date >= $from && $date <= $to ) {
					$events[] = array( 'date' => $date, 'end' => $date, 'title' => $day[1], 'desc' => '投稿のネタにしてみましょう', 'url' => '', 'kind' => 'day' );
				}
			}
		}
		$posts = get_posts(
			array(
				'post_type'      => self::EVENT_TYPE,
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'meta_query'     => array( array( 'key' => '_snscs_event_start', 'value' => $to, 'compare' => '<=', 'type' => 'DATE' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		foreach ( $posts as $post ) {
			$start = (string) get_post_meta( $post->ID, '_snscs_event_start', true );
			$end   = (string) get_post_meta( $post->ID, '_snscs_event_end', true );
			$end   = '' !== $end && $end >= $start ? $end : $start;
			if ( '' === $start || $end < $from ) {
				continue;
			}
			$events[] = array(
				'date'  => $start,
				'end'   => $end,
				'title' => get_the_title( $post ),
				'desc'  => $post->post_excerpt,
				'url'   => (string) get_post_meta( $post->ID, '_snscs_event_url', true ),
				'kind'  => 'event',
			);
		}
		usort(
			$events,
			function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);
		return $events;
	}

	/* --------------------------------------------------------------------
	 * 自分の予定（ログイン中の人）
	 * ------------------------------------------------------------------ */

	public static function register_routes() {
		register_rest_route(
			'snscs/v1',
			'/calendar',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_list' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'from' => array( 'type' => 'string', 'required' => true ),
						'to'   => array( 'type' => 'string', 'required' => true ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_save' ),
					'permission_callback' => 'is_user_logged_in',
				),
			)
		);
		register_rest_route(
			'snscs/v1',
			'/calendar/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'rest_delete' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
	}

	private static function valid_date( $date ) {
		return is_string( $date ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	private static function format_item( array $row ) {
		return array(
			'id'       => (int) $row['id'],
			'date'     => $row['day'],
			'time'     => $row['time_at'],
			'title'    => $row['title'],
			'category' => $row['category'],
			'sns'      => $row['sns'],
			'memo'     => $row['memo'],
			'done'     => (bool) $row['done'],
			'remind'   => (bool) $row['remind'],
		);
	}

	public static function rest_list( WP_REST_Request $request ) {
		global $wpdb;
		$from = (string) $request->get_param( 'from' );
		$to   = (string) $request->get_param( 'to' );
		if ( ! self::valid_date( $from ) || ! self::valid_date( $to ) || $to < $from || ( strtotime( $to ) - strtotime( $from ) ) > 100 * DAY_IN_SECONDS ) {
			return new WP_Error( 'snscs_range', '期間が正しくありません。', array( 'status' => 400 ) );
		}
		$mine = array();
		if ( is_user_logged_in() ) {
			$table = self::table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND day BETWEEN %s AND %s ORDER BY day, time_at = '', time_at, id", get_current_user_id(), $from, $to ), ARRAY_A );
			$mine = array_map( array( __CLASS__, 'format_item' ), $rows );
		}
		$response = rest_ensure_response(
			array(
				'public' => self::public_events( $from, $to ),
				'mine'   => $mine,
			)
		);
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * 予定の追加・変更。id があれば変更（自分の予定だけ）。
	 */
	public static function rest_save( WP_REST_Request $request ) {
		global $wpdb;
		$table   = self::table();
		$user_id = get_current_user_id();
		$id      = absint( $request->get_param( 'id' ) );
		$date    = (string) $request->get_param( 'date' );
		$time    = (string) $request->get_param( 'time' );
		$title   = mb_substr( trim( sanitize_text_field( (string) $request->get_param( 'title' ) ) ), 0, self::MAX_TITLE );
		$cat     = sanitize_key( (string) $request->get_param( 'category' ) );
		$sns     = sanitize_key( (string) $request->get_param( 'sns' ) );
		if ( ! self::valid_date( $date ) || '' === $title ) {
			return new WP_Error( 'snscs_invalid', '日付とタイトルを入れてください。', array( 'status' => 400 ) );
		}
		$data = array(
			'user_id'    => $user_id,
			'day'        => $date,
			'time_at'    => preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '',
			'title'      => $title,
			'category'   => array_key_exists( $cat, self::categories() ) ? $cat : 'other',
			'sns'        => array_key_exists( $sns, snscs_platforms() ) ? $sns : '',
			'memo'       => mb_substr( sanitize_textarea_field( (string) $request->get_param( 'memo' ) ), 0, self::MAX_MEMO ),
			'done'       => rest_sanitize_boolean( $request->get_param( 'done' ) ) ? 1 : 0,
			'remind'     => rest_sanitize_boolean( $request->get_param( 'remind' ) ) ? 1 : 0,
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( $id ) {
			$updated = $wpdb->update( $table, $data, array( 'id' => $id, 'user_id' => $user_id ) );
			if ( false === $updated || ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND user_id = %d", $id, $user_id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return new WP_Error( 'snscs_not_found', '予定が見つかりません。', array( 'status' => 404 ) );
			}
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) ) >= self::MAX_ITEMS ) {
				return new WP_Error( 'snscs_limit', '予定が多すぎます。古い予定を削除してください。', array( 'status' => 400 ) );
			}
			$wpdb->insert( $table, $data );
			$id = (int) $wpdb->insert_id;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return rest_ensure_response( self::format_item( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ) ) );
	}

	public static function rest_delete( WP_REST_Request $request ) {
		global $wpdb;
		$deleted = $wpdb->delete( self::table(), array( 'id' => absint( $request['id'] ), 'user_id' => get_current_user_id() ), array( '%d', '%d' ) );
		return rest_ensure_response( array( 'deleted' => (bool) $deleted ) );
	}

	public static function delete_for_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/* --------------------------------------------------------------------
	 * 当日の朝のお知らせ
	 * ------------------------------------------------------------------ */

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * 朝（7時以降の最初の1回）に、「お知らせ」をオンにした今日の予定を、人ごとに1通にまとめて送る。
	 *
	 * @return int 送った通数。
	 */
	public static function send_reminders( $force = false ) {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		if ( ! $force && ( (int) current_time( 'G' ) < self::REMIND_HOUR || get_option( 'snscs_calendar_reminded' ) === $today ) ) {
			return 0;
		}
		update_option( 'snscs_calendar_reminded', $today, false );
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE remind = 1 AND done = 0 AND day = %s ORDER BY user_id, time_at = '', time_at", $today ), ARRAY_A );
		$by_user = array();
		foreach ( $rows as $row ) {
			$by_user[ (int) $row['user_id'] ][] = $row;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$cats = self::categories();
		$sent = 0;
		foreach ( $by_user as $user_id => $items ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}
			$lines = array( '今日の予定です。', '' );
			foreach ( $items as $item ) {
				$lines[] = '・' . ( '' !== $item['time_at'] ? $item['time_at'] . ' ' : '' ) . $item['title'] . '（' . ( isset( $cats[ $item['category'] ] ) ? $cats[ $item['category'] ]['label'] : '' ) . ( '' !== $item['sns'] ? '・' . snscs_platform_label( $item['sns'] ) : '' ) . '）';
			}
			$lines[] = '';
			$lines[] = 'カレンダーを開く: ' . ( SNSCS_Hub::page_url( 'calendar' ) ? SNSCS_Hub::page_url( 'calendar' ) : home_url( '/' ) );
			$lines[] = '';
			$lines[] = '※ このお知らせは、予定ごとの「当日の朝にメールで知らせる」をオフにすると届かなくなります。';
			wp_mail( $user->user_email, '[' . $site . '] 今日の予定（' . count( $items ) . '件）', implode( "\n", $lines ) );
			$sent++;
		}
		return $sent;
	}

	/* --------------------------------------------------------------------
	 * 表示
	 * ------------------------------------------------------------------ */

	public static function shortcode() {
		SNSCS_Hub::remember_page( 'calendar' );
		wp_enqueue_style( 'snscs' );
		wp_enqueue_script( 'snscs-calendar', SNSCS_URL . 'assets/js/snscs-calendar.js', array(), SNSCS_VERSION, true );
		$logged_in = is_user_logged_in();
		wp_add_inline_script(
			'snscs-calendar',
			'window.snscsCalendar = ' . wp_json_encode(
				array(
					'rest'       => esc_url_raw( rest_url( 'snscs/v1/calendar' ) ),
					'nonce'      => $logged_in ? wp_create_nonce( 'wp_rest' ) : '',
					'loggedIn'   => $logged_in,
					'today'      => current_time( 'Y-m-d' ),
					'categories' => self::categories(),
					'sns'        => snscs_platforms(),
					'search'     => snscs_search_url(),
				)
			) . ';',
			'before'
		);
		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		?>
		<div class="snscs-calendar" data-snscs-calendar>
			<?php if ( ! $logged_in ) : ?>
				<p class="snscs-calendar__guest">予定はこのブラウザの中にだけ保存されます。<a href="<?php echo esc_url( snscs_portal_url() ); ?>">発信者としてログイン</a>すると、どの端末からも見られ、当日の朝にメールでお知らせも受け取れます。</p>
			<?php endif; ?>
			<noscript><p>カレンダーを使うには、JavaScript を有効にしてください。</p></noscript>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [sns_calendar_link]: 今日の日付・近いみんなの予定と「カレンダーを開く」ボタン。
	 */
	public static function shortcode_link( $atts ) {
		$atts = shortcode_atts( array( 'title' => 'SNS発信カレンダー', 'count' => 3 ), $atts, 'sns_calendar_link' );
		$url  = SNSCS_Hub::page_url( 'calendar' );
		if ( ! $url ) {
			return '';
		}
		wp_enqueue_style( 'snscs' );
		$today  = current_time( 'Y-m-d' );
		$events = array_slice( self::public_events( $today, gmdate( 'Y-m-d', strtotime( $today . ' +45 days' ) ) ), 0, min( 10, max( 0, (int) $atts['count'] ) ) );
		$week   = array( '日', '月', '火', '水', '木', '金', '土' );
		$ts     = strtotime( $today );
		ob_start();
		?>
		<aside class="snscs-callink">
			<a class="snscs-callink__date" href="<?php echo esc_url( $url ); ?>" aria-label="カレンダーを開く">
				<span class="snscs-callink__month"><?php echo esc_html( (int) gmdate( 'n', $ts ) . '月' ); ?></span>
				<span class="snscs-callink__day"><?php echo esc_html( (int) gmdate( 'j', $ts ) ); ?></span>
				<span class="snscs-callink__week"><?php echo esc_html( $week[ (int) gmdate( 'w', $ts ) ] . '曜日' ); ?></span>
			</a>
			<div class="snscs-callink__body">
				<p class="snscs-callink__title"><?php echo esc_html( sanitize_text_field( $atts['title'] ) ); ?></p>
				<?php if ( $events ) : ?>
					<ul class="snscs-callink__list">
						<?php foreach ( $events as $event ) : ?>
							<li><span><?php echo esc_html( (int) substr( $event['date'], 5, 2 ) . '/' . (int) substr( $event['date'], 8, 2 ) ); ?></span> <?php echo esc_html( $event['title'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<a class="snscs-button snscs-button--primary" href="<?php echo esc_url( $url ); ?>">カレンダーを開いて予定を管理</a>
			</div>
		</aside>
		<?php
		return ob_get_clean();
	}
}
