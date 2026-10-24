<?php
/**
 * LINE公式アカウントでのお知らせ（LINE Messaging API。管理画面「発信者 → LINE連携」）。
 *
 * つなぎ方: メンバーページ・マイページの「LINEで受け取る」で、友だち追加のボタンと6けたのコード（30分有効）を出す。
 *           メンバーがLINE公式アカウントにコードを送ると、Webhook でその人のLINEとメンバーを結びつける。
 *           ブロック（友だち解除）されたら、結びつきを外す。Webhook は署名（X-Line-Signature）を確かめる。
 * 送るもの: スマホ通知と同じお知らせ（フォロー中の新着記事・リポスト・アワード・コラボなど）を、
 *           LINEとつながっているメンバーに、まとめて1通で送る（プッシュメッセージ）。
 *           1か月に送る数の上限を決められる（無料プランの上限に合わせる。初期200通）。
 *           任意で、毎週月曜に「今週のおすすめ」を友だち全員に送る（一斉送信。上限の数に含む）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Line {

	const OPTION     = 'snscs_line';
	const META_USER  = 'snscs_line_user';
	const META_CODE  = 'snscs_line_code';
	const SEND_HOOK  = 'snscs_line_send';
	const ADMIN_PAGE = 'snscs-line';
	const LOG        = 'snscs_line_log';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'snscs_inbox_added', array( __CLASS__, 'schedule_send' ) );
		add_action( self::SEND_HOOK, array( __CLASS__, 'send_pending' ) );
		add_action( SNSCS_Inbox::CRON_HOOK, array( __CLASS__, 'tick' ), 30 );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 23 );
		add_action( 'admin_post_snscs_line_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_snscs_line_unlink', array( __CLASS__, 'handle_unlink' ) );
	}

	public static function defaults() {
		return array(
			'enabled'  => 0,
			'secret'   => '',
			'token'    => '',
			'basic_id' => '',
			'limit'    => 200,
			'weekly'   => 0,
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function active() {
		$s = self::settings();
		return $s['enabled'] && '' !== $s['secret'] && '' !== $s['token'];
	}

	private static function base() {
		return defined( 'SNSCS_LINE_BASE' ) ? untrailingslashit( SNSCS_LINE_BASE ) : 'https://api.line.me';
	}

	public static function log( $message, $level = 'info' ) {
		$log = (array) get_option( self::LOG, array() );
		array_unshift( $log, array( 'at' => current_time( 'mysql' ), 'level' => $level, 'message' => mb_substr( (string) $message, 0, 300 ) ) );
		update_option( self::LOG, array_slice( $log, 0, 50 ), false );
	}

	/* --------------------------------------------------------------------
	 * 送る
	 * ------------------------------------------------------------------ */

	private static function month_key() {
		return 'snscs_line_sent_' . current_time( 'Ym' );
	}

	public static function sent_this_month() {
		return (int) get_option( self::month_key(), 0 );
	}

	private static function count_sent( $n ) {
		update_option( self::month_key(), self::sent_this_month() + $n, false );
	}

	/**
	 * @param string $path    /v2/bot/message/push など。
	 * @return true|WP_Error
	 */
	private static function api( $path, array $body ) {
		$response = wp_remote_post(
			self::base() . $path,
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . self::settings()['token'], 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			return new WP_Error( 'snscs_line', 'HTTP ' . $code . ' ' . ( is_array( $data ) ? (string) ( $data['message'] ?? '' ) : '' ) );
		}
		return true;
	}

	public static function push( $line_user, $text ) {
		return self::api( '/v2/bot/message/push', array( 'to' => $line_user, 'messages' => array( array( 'type' => 'text', 'text' => mb_substr( $text, 0, 4900 ) ) ) ) );
	}

	private static function reply( $token, $text ) {
		return self::api( '/v2/bot/message/reply', array( 'replyToken' => $token, 'messages' => array( array( 'type' => 'text', 'text' => $text ) ) ) );
	}

	public static function schedule_send() {
		if ( self::active() && ! wp_next_scheduled( self::SEND_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::SEND_HOOK );
		}
	}

	/**
	 * まだLINEで送っていないお知らせを、つながっているメンバーにまとめて送る。
	 *
	 * @return int 送った通数。
	 */
	public static function send_pending() {
		if ( ! self::active() ) {
			return 0;
		}
		global $wpdb;
		$inbox = SNSCS_Inbox::table();
		$types = "'" . implode( "','", SNSCS_Push::TYPES ) . "'";
		$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT n.* FROM {$inbox} n INNER JOIN {$wpdb->usermeta} um ON um.user_id = n.user_id AND um.meta_key = %s WHERE n.lined = 0 AND n.read_at IS NULL AND n.created_at >= %s AND n.type IN ({$types}) ORDER BY n.user_id, n.created_at DESC LIMIT 2000", self::META_USER, $since ), ARRAY_A );
		$wpdb->query( $wpdb->prepare( "UPDATE {$inbox} SET lined = 1 WHERE lined = 0 AND created_at < %s", $since ) );
		// phpcs:enable
		$by = array();
		foreach ( $rows as $row ) {
			$by[ (int) $row['user_id'] ][] = $row;
		}
		$limit = (int) self::settings()['limit'];
		$sent  = 0;
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		foreach ( $by as $user_id => $items ) {
			if ( $limit > 0 && self::sent_this_month() >= $limit ) {
				self::log( '今月の上限（' . $limit . '通）に達したため、送るのを止めました。', 'error' );
				break;
			}
			$ids = implode( ',', array_map( 'intval', wp_list_pluck( $items, 'id' ) ) );
			$wpdb->query( "UPDATE {$inbox} SET lined = 1 WHERE id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$lines = array();
			foreach ( array_slice( $items, 0, 5 ) as $item ) {
				$d = SNSCS_Inbox::describe( $item );
				if ( $d ) {
					$lines[] = '・' . $d['text'] . "\n" . $d['url'];
				}
			}
			if ( ! $lines ) {
				continue;
			}
			$text = '【' . $site . '】' . "\n" . implode( "\n\n", $lines ) . ( count( $items ) > 5 ? "\n\nほか" . ( count( $items ) - 5 ) . '件: ' . SNSCS_Social::member_url( array( 'tab' => 'inbox' ) ) : '' );
			$res  = self::push( (string) get_user_meta( $user_id, self::META_USER, true ), $text );
			if ( is_wp_error( $res ) ) {
				self::log( 'LINEで送れませんでした: ' . $res->get_error_message(), 'error' );
				continue;
			}
			self::count_sent( 1 );
			++$sent;
		}
		return $sent;
	}

	/**
	 * 1時間ごと: 送り残しと、毎週の「今週のおすすめ」。
	 */
	public static function tick() {
		if ( ! self::active() ) {
			return;
		}
		self::send_pending();
		$s    = self::settings();
		$week = current_time( 'o-W' );
		if ( $s['weekly'] && '1' === current_time( 'N' ) && (int) current_time( 'G' ) >= 18 && get_option( 'snscs_line_weekly' ) !== $week ) {
			update_option( 'snscs_line_weekly', $week, false );
			self::broadcast_weekly();
		}
	}

	/**
	 * 今週のおすすめ（話題の記事3本と急上昇ワード）を友だち全員に。
	 */
	public static function broadcast_weekly() {
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$lines = array( '【' . $site . '】今週のおすすめ' );
		foreach ( array_slice( array_keys( SNSCS_Stories::trending( 3 ) ), 0, 3 ) as $id ) {
			$lines[] = '📖 ' . html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) . "\n" . get_permalink( $id );
		}
		$words = wp_list_pluck( SNSCS_Trends::ranking( '', 3 ), 'term' );
		if ( $words ) {
			$lines[] = '🔥 急上昇ワード: ' . implode( '、', $words ) . "\n" . SNSCS_Trends::page_url();
		}
		if ( count( $lines ) < 2 ) {
			return false;
		}
		$res = self::api( '/v2/bot/message/broadcast', array( 'messages' => array( array( 'type' => 'text', 'text' => implode( "\n\n", $lines ) ) ) ) );
		if ( is_wp_error( $res ) ) {
			self::log( '今週のおすすめを送れませんでした: ' . $res->get_error_message(), 'error' );
			return false;
		}
		self::log( '今週のおすすめを友だち全員に送りました。' );
		return true;
	}

	/* --------------------------------------------------------------------
	 * Webhook（LINE から届くできごと）
	 * ------------------------------------------------------------------ */

	public static function register_routes() {
		register_rest_route(
			'snscs/v1',
			'/line/webhook',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'webhook' ),
			)
		);
	}

	public static function webhook_url() {
		return rest_url( 'snscs/v1/line/webhook' );
	}

	public static function verify_signature( $body, $signature ) {
		$expected = base64_encode( hash_hmac( 'sha256', $body, self::settings()['secret'], true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return '' !== self::settings()['secret'] && hash_equals( $expected, (string) $signature );
	}

	public static function webhook( WP_REST_Request $request ) {
		$body = (string) $request->get_body();
		if ( ! self::verify_signature( $body, $request->get_header( 'x_line_signature' ) ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 401 );
		}
		$data = json_decode( $body, true );
		foreach ( (array) ( $data['events'] ?? array() ) as $event ) {
			$line_user = (string) ( $event['source']['userId'] ?? '' );
			$token     = (string) ( $event['replyToken'] ?? '' );
			if ( '' === $line_user ) {
				continue;
			}
			$type = $event['type'] ?? '';
			if ( 'follow' === $type && '' !== $token ) {
				self::reply( $token, '友だち追加ありがとうございます！' . "\n" . 'お知らせを受け取るには、' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . 'のメンバーページ（アカウント）に出ている6けたのコードを、このトークに送ってください。' );
			} elseif ( 'unfollow' === $type ) {
				foreach ( get_users( array( 'meta_key' => self::META_USER, 'meta_value' => $line_user, 'fields' => 'ID' ) ) as $user_id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
					delete_user_meta( (int) $user_id, self::META_USER );
				}
			} elseif ( 'message' === $type && 'text' === ( $event['message']['type'] ?? '' ) ) {
				$code = preg_replace( '/\D/', '', mb_convert_kana( (string) $event['message']['text'], 'n', 'UTF-8' ) );
				$text = self::link_by_code( $code, $line_user );
				if ( '' !== $token && null !== $text ) {
					self::reply( $token, $text );
				}
			}
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * コードでメンバーと結びつける。
	 *
	 * @return string|null 返事（コードでなければ null）。
	 */
	public static function link_by_code( $code, $line_user ) {
		if ( 6 !== strlen( $code ) ) {
			return null;
		}
		$users = get_users( array( 'meta_key' => self::META_CODE, 'meta_value' => $code, 'fields' => 'ID', 'number' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( ! $users ) {
			return 'コードが見つかりませんでした。メンバーページで新しいコードを出して、もう一度送ってください。';
		}
		$user_id = (int) $users[0];
		$expires = (int) get_user_meta( $user_id, self::META_CODE . '_expires', true );
		delete_user_meta( $user_id, self::META_CODE );
		if ( $expires < time() ) {
			return 'コードの有効期限（30分）が切れています。メンバーページで新しいコードを出してください。';
		}
		// 同じLINEがほかのメンバーとつながっていたら外す。
		foreach ( get_users( array( 'meta_key' => self::META_USER, 'meta_value' => $line_user, 'fields' => 'ID' ) ) as $other ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			delete_user_meta( (int) $other, self::META_USER );
		}
		update_user_meta( $user_id, self::META_USER, $line_user );
		return '連携しました！これから、フォロー中の新着記事などのお知らせをLINEでお届けします。止めるときは、メンバーページの「LINEで受け取る」から解除できます。';
	}

	/* --------------------------------------------------------------------
	 * メンバーページ・マイページ
	 * ------------------------------------------------------------------ */

	public static function handle_unlink() {
		check_admin_referer( 'snscs_line_unlink', 'snscs_nonce' );
		if ( is_user_logged_in() ) {
			delete_user_meta( get_current_user_id(), self::META_USER );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
		exit;
	}

	/**
	 * 「LINEで受け取る」の欄。
	 */
	public static function render_settings( $user_id ) {
		if ( ! self::active() ) {
			return;
		}
		$s      = self::settings();
		$linked = '' !== (string) get_user_meta( $user_id, self::META_USER, true );
		if ( ! $linked ) {
			$code = (string) get_user_meta( $user_id, self::META_CODE, true );
			if ( '' === $code || (int) get_user_meta( $user_id, self::META_CODE . '_expires', true ) < time() + 5 * MINUTE_IN_SECONDS ) {
				$code = (string) wp_rand( 100000, 999999 );
				update_user_meta( $user_id, self::META_CODE, $code );
				update_user_meta( $user_id, self::META_CODE . '_expires', time() + 30 * MINUTE_IN_SECONDS );
			}
		}
		$add = 'https://line.me/R/ti/p/' . rawurlencode( '@' . ltrim( $s['basic_id'], '@' ) );
		?>
		<section class="snscs-panel snscs-line" id="snscs-line">
			<h2 class="snscs-panel__title">LINEで受け取る</h2>
			<?php if ( $linked ) : ?>
				<p>LINEと連携しています。フォロー中の新着記事などのお知らせを、LINEでお届けします。</p>
				<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
					<input type="hidden" name="action" value="snscs_line_unlink">
					<?php wp_nonce_field( 'snscs_line_unlink', 'snscs_nonce' ); ?>
					<button type="submit" class="snscs-button">連携を解除する</button>
				</form>
			<?php else : ?>
				<ol>
					<li>下のボタンから、LINE公式アカウントを友だちに追加します。<br><a class="snscs-button snscs-line__add" href="<?php echo esc_url( $add ); ?>" target="_blank" rel="noopener">LINEで友だち追加</a></li>
					<li>トークに、このコードを送ってください（30分有効）: <strong class="snscs-line__code"><?php echo esc_html( $code ); ?></strong></li>
				</ol>
				<p class="snscs-help">連携すると、お知らせがLINEに届きます。いつでも解除できます。</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, 'LINE連携', 'LINE連携', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		check_admin_referer( 'snscs_line_save', 'snscs_nonce' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() で確認済み。
		$in = wp_unslash( $_POST );
		$s  = self::settings();
		$s['enabled']  = empty( $in['enabled'] ) ? 0 : 1;
		$s['weekly']   = empty( $in['weekly'] ) ? 0 : 1;
		$s['limit']    = max( 0, (int) ( $in['limit'] ?? 200 ) );
		$s['basic_id'] = ltrim( sanitize_text_field( (string) ( $in['basic_id'] ?? '' ) ), '@' );
		foreach ( array( 'secret', 'token' ) as $k ) {
			$v = trim( sanitize_text_field( (string) ( $in[ $k ] ?? '' ) ) );
			if ( '' !== $v ) {
				$s[ $k ] = $v;
			}
		}
		// phpcs:enable
		update_option( self::OPTION, $s, false );
		wp_safe_redirect( add_query_arg( 'snscs_msg', 'saved', admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::ADMIN_PAGE ) ) );
		exit;
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = self::settings();
		$linked = count( get_users( array( 'meta_key' => self::META_USER, 'fields' => 'ID' ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$log    = (array) get_option( self::LOG, array() );
		?>
		<div class="wrap">
			<h1>LINE連携</h1>
			<?php if ( isset( $_GET['snscs_msg'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p>保存しました。</p></div>
			<?php endif; ?>
			<p>LINE公式アカウントから、メンバーにお知らせ（フォロー中の新着記事・リポスト・アワード・コラボなど）を届けます。日本ではブラウザの通知より見てもらいやすい方法です。</p>
			<table class="widefat striped" style="max-width: 760px">
				<tbody>
					<tr><th style="width: 240px">状態</th><td><?php echo self::active() ? '<strong>オン</strong>' : 'オフ（チャネルシークレットとアクセストークンを入れ、オンにしてください）'; ?></td></tr>
					<tr><th>LINEとつながっているメンバー</th><td><?php echo (int) $linked; ?>人</td></tr>
					<tr><th>今月送った数</th><td><?php echo esc_html( self::sent_this_month() . '通' . ( $s['limit'] ? ' / 上限 ' . $s['limit'] . '通' : '' ) ); ?></td></tr>
					<tr><th>Webhook URL</th><td><code><?php echo esc_html( self::webhook_url() ); ?></code></td></tr>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
				<input type="hidden" name="action" value="snscs_line_save">
				<?php wp_nonce_field( 'snscs_line_save', 'snscs_nonce' ); ?>
				<h2>設定</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">LINEで送る</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> オンにする</label></td></tr>
					<tr><th scope="row"><label for="snscs-line-basic">ベーシックID</label></th><td><input id="snscs-line-basic" type="text" name="basic_id" value="<?php echo esc_attr( $s['basic_id'] ); ?>" class="regular-text" placeholder="@123abcde"><p class="description">友だち追加のボタンに使います（LINE Official Account Manager に出ている @ から始まるID）。</p></td></tr>
					<tr><th scope="row">チャネルシークレット</th><td><input type="password" name="secret" value="" class="regular-text" autocomplete="off" placeholder="<?php echo '' !== $s['secret'] ? '設定済み（変えるときだけ）' : ''; ?>"></td></tr>
					<tr><th scope="row">チャネルアクセストークン（長期）</th><td><input type="password" name="token" value="" class="regular-text" autocomplete="off" placeholder="<?php echo '' !== $s['token'] ? '設定済み（変えるときだけ）' : ''; ?>">
						<p class="description">LINE Developers で Messaging API のチャネルを作り、「チャネル基本設定」のシークレットと「Messaging API設定」の長期トークンを貼ってください。Webhook URL に上の URL を入れ、「Webhookの利用」をオン、「応答メッセージ」をオフにします。</p></td></tr>
					<tr><th scope="row"><label for="snscs-line-limit">1か月に送る上限</label></th><td><input id="snscs-line-limit" type="number" name="limit" min="0" value="<?php echo (int) $s['limit']; ?>" class="small-text"> 通（0で上限なし。無料プランの上限に合わせてください）</td></tr>
					<tr><th scope="row">今週のおすすめ</th><td><label><input type="checkbox" name="weekly" value="1" <?php checked( $s['weekly'] ); ?>> 毎週月曜の夕方に、話題の記事と急上昇ワードを友だち全員に送る（友だちの人数分が上限の数に含まれます）</label></td></tr>
				</table>
				<?php submit_button( '設定を保存' ); ?>
			</form>
			<h2>記録</h2>
			<table class="widefat striped" style="max-width: 900px">
				<tbody>
					<?php if ( ! $log ) : ?>
						<tr><td>まだありません。</td></tr>
					<?php endif; ?>
					<?php foreach ( $log as $row ) : ?>
						<tr><td style="width: 160px"><?php echo esc_html( $row['at'] ); ?></td><td<?php echo 'error' === $row['level'] ? ' style="color: #b32d2e"' : ''; ?>><?php echo esc_html( $row['message'] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
