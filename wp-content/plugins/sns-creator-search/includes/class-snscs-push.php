<?php
/**
 * スマホ・パソコンへのプッシュ通知（Web Push）と、ホーム画面に追加できるようにする設定（PWA）。
 *
 * しくみ:
 *   1. 会員が「この端末で通知を受け取る」を押すと、ブラウザが通知の許可を求め、宛先（購読情報）をサーバーに送る
 *   2. お知らせ（SNSCS_Inbox）ができたら、数十秒後にまとめて、その会員の端末へ送る
 *   3. 端末では、サイトを閉じていても通知が出る。押すとその記事が開く
 *
 * 規格: RFC 8291（中身の暗号化。aes128gcm）と RFC 8292（VAPID。送り主の証明）。外部のライブラリは使わず、
 *       PHP の openssl（楕円曲線 P-256、AES-128-GCM）と hash_hkdf で作る。鍵はサイトごとに1回だけ作って保存する。
 * iPhone（iOS 16.4以降）は、Safari で「ホーム画面に追加」したアプリからだけ通知を受け取れる（そのためのマニフェストも出す）。
 *
 * 送る種類: フォローした発信者・ユーザーの新しい記事とリポスト、ブックマークした記事の更新、新しいフォロワー。
 * 宛先が無効（404・410）になった端末は消す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Push {

	const OPTION    = 'snscs_vapid';
	const SEND_HOOK = 'snscs_push_send';
	const TYPES     = array( 'new_article', 'repost', 'user_repost', 'bookmark_updated', 'new_follower', 'new_user_follower' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'serve' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'head' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( self::SEND_HOOK, array( __CLASS__, 'send_pending' ) );
		add_action( 'snscs_inbox_added', array( __CLASS__, 'schedule_send' ) );
		add_action( 'deleted_user', array( __CLASS__, 'on_deleted_user' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_push_subs';
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
  endpoint text NOT NULL,
  endpoint_hash char(40) NOT NULL,
  p256dh varchar(120) NOT NULL,
  auth varchar(40) NOT NULL,
  created_at datetime NOT NULL,
  last_ok datetime DEFAULT NULL,
  failures smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY endpoint_hash (endpoint_hash),
  KEY user_id (user_id)
) {$charset};"
		);
		self::keys();
	}

	/* --------------------------------------------------------------------
	 * 鍵（VAPID）
	 * ------------------------------------------------------------------ */

	public static function b64u( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function b64u_decode( $data ) {
		$data = strtr( (string) $data, '-_', '+/' );
		return (string) base64_decode( $data . str_repeat( '=', ( 4 - strlen( $data ) % 4 ) % 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * 楕円曲線の鍵の公開部分（65バイト。0x04 + X + Y）。
	 */
	public static function raw_public( $key ) {
		$details = openssl_pkey_get_details( $key );
		return "\x04" . str_pad( $details['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $details['ec']['y'], 32, "\0", STR_PAD_LEFT );
	}

	/**
	 * 65バイトの公開鍵を、openssl で使える形（PEM）にする。
	 */
	public static function public_pem( $raw ) {
		$der = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . $raw;
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * サイトの鍵（無ければ作る）。
	 *
	 * @return array{private:string,public:string}|null private は PEM、public は base64url。
	 */
	public static function keys() {
		$keys = get_option( self::OPTION );
		if ( is_array( $keys ) && ! empty( $keys['private'] ) ) {
			return $keys;
		}
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return null;
		}
		$key = openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
		if ( ! $key || ! openssl_pkey_export( $key, $pem ) ) {
			return null;
		}
		$keys = array( 'private' => $pem, 'public' => self::b64u( self::raw_public( $key ) ) );
		update_option( self::OPTION, $keys, false );
		return $keys;
	}

	public static function available() {
		return function_exists( 'openssl_pkey_derive' ) && function_exists( 'hash_hkdf' ) && null !== self::keys();
	}

	/* --------------------------------------------------------------------
	 * 暗号化と送信
	 * ------------------------------------------------------------------ */

	/**
	 * RFC 8291 の形に暗号化する。
	 *
	 * @return string|null 送る本文。
	 */
	public static function encrypt( $payload, $p256dh, $auth ) {
		$ua_public = self::b64u_decode( $p256dh );
		$secret    = self::b64u_decode( $auth );
		if ( 65 !== strlen( $ua_public ) || 16 !== strlen( $secret ) ) {
			return null;
		}
		$ephemeral = openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
		$as_public = self::raw_public( $ephemeral );
		$shared    = openssl_pkey_derive( openssl_pkey_get_public( self::public_pem( $ua_public ) ), $ephemeral, 32 );
		if ( false === $shared ) {
			return null;
		}
		$ikm   = hash_hkdf( 'sha256', $shared, 32, "WebPush: info\0" . $ua_public . $as_public, $secret );
		$salt  = random_bytes( 16 );
		$cek   = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt );
		$nonce = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt );
		$tag   = '';
		$body  = openssl_encrypt( $payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );
		if ( false === $body ) {
			return null;
		}
		return $salt . pack( 'N', 4096 ) . chr( 65 ) . $as_public . $body . $tag;
	}

	/**
	 * DER 形式の署名を、JWT（ES256）用の64バイト（R + S）にする。
	 */
	private static function der_to_raw( $der ) {
		$offset = 3;
		$r_len  = ord( $der[ $offset ] );
		$r      = substr( $der, $offset + 1, $r_len );
		$offset = $offset + 1 + $r_len + 1;
		$s_len  = ord( $der[ $offset ] );
		$s      = substr( $der, $offset + 1, $s_len );
		$fix    = function ( $v ) {
			return str_pad( ltrim( $v, "\0" ), 32, "\0", STR_PAD_LEFT );
		};
		return $fix( $r ) . $fix( $s );
	}

	/**
	 * VAPID の証明（Authorization ヘッダー）。
	 */
	public static function vapid_header( $endpoint ) {
		$keys  = self::keys();
		$parts = wp_parse_url( $endpoint );
		$aud   = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$info  = snscs_operator_info();
		$head  = self::b64u( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
		$claim = self::b64u( wp_json_encode( array( 'aud' => $aud, 'exp' => time() + 12 * HOUR_IN_SECONDS, 'sub' => 'mailto:' . $info['email'] ) ) );
		$der   = '';
		openssl_sign( $head . '.' . $claim, $der, openssl_pkey_get_private( $keys['private'] ), OPENSSL_ALGO_SHA256 );
		return 'vapid t=' . $head . '.' . $claim . '.' . self::b64u( self::der_to_raw( $der ) ) . ', k=' . $keys['public'];
	}

	/**
	 * 1台の端末へ送る。
	 *
	 * @return int HTTP の結果（0 は送れなかった）。
	 */
	public static function send_one( array $sub, array $message ) {
		// 送れる大きさには上限（約4KB）があるので、日本語はそのまま（\u の形にしない）で、本文も短くする。
		$message['body'] = mb_strimwidth( (string) $message['body'], 0, 240, '…' );
		$body            = self::encrypt( wp_json_encode( $message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), $sub['p256dh'], $sub['auth'] );
		if ( null === $body ) {
			return 0;
		}
		$response = wp_remote_post(
			$sub['endpoint'],
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization'    => self::vapid_header( $sub['endpoint'] ),
					'Content-Encoding' => 'aes128gcm',
					'Content-Type'     => 'application/octet-stream',
					'TTL'              => (string) DAY_IN_SECONDS,
					'Urgency'          => 'normal',
				),
				'body'    => $body,
			)
		);
		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * お知らせができたら、少しあとにまとめて送る（記事の公開の処理を遅くしないため）。
	 */
	public static function schedule_send() {
		if ( ! wp_next_scheduled( self::SEND_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::SEND_HOOK );
		}
	}

	/**
	 * まだ送っていないお知らせを、端末を登録している会員へ送る（1回に最大500人）。
	 *
	 * @return int 送れた通知の数。
	 */
	public static function send_pending() {
		global $wpdb;
		$subs  = self::table();
		$inbox = SNSCS_Inbox::table();
		$types = "'" . implode( "','", self::TYPES ) . "'";
		$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT n.* FROM {$inbox} n WHERE n.pushed = 0 AND n.read_at IS NULL AND n.created_at >= %s AND n.type IN ({$types}) AND n.user_id IN (SELECT user_id FROM {$subs}) ORDER BY n.user_id, n.created_at DESC LIMIT 2000", $since ), ARRAY_A );
		$wpdb->query( $wpdb->prepare( "UPDATE {$inbox} SET pushed = 1 WHERE pushed = 0 AND created_at < %s", $since ) );
		// phpcs:enable
		$by = array();
		foreach ( $rows as $row ) {
			$by[ (int) $row['user_id'] ][] = $row;
		}
		$sent = 0;
		foreach ( array_slice( $by, 0, 500, true ) as $user_id => $items ) {
			$ids = implode( ',', array_map( 'intval', wp_list_pluck( $items, 'id' ) ) );
			$wpdb->query( "UPDATE {$inbox} SET pushed = 1 WHERE id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$first = SNSCS_Inbox::describe( $items[0] );
			if ( ! $first ) {
				continue;
			}
			$message = array(
				'title' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'body'  => count( $items ) > 1 ? $first['text'] . '（ほか' . ( count( $items ) - 1 ) . '件）' : $first['text'],
				'url'   => count( $items ) > 1 ? SNSCS_Social::member_url( array( 'tab' => 'inbox' ) ) : $first['url'],
				'tag'   => 'snscs-' . $user_id,
				'icon'  => self::icon_url(),
			);
			foreach ( self::subscriptions( $user_id ) as $sub ) {
				$code = self::send_one( $sub, $message );
				if ( $code >= 200 && $code < 300 ) {
					$sent++;
					$wpdb->update( $subs, array( 'last_ok' => current_time( 'mysql' ), 'failures' => 0 ), array( 'id' => $sub['id'] ) );
				} elseif ( 404 === $code || 410 === $code || (int) $sub['failures'] >= 20 ) {
					$wpdb->delete( $subs, array( 'id' => $sub['id'] ) );
				} else {
					$wpdb->update( $subs, array( 'failures' => (int) $sub['failures'] + 1 ), array( 'id' => $sub['id'] ) );
				}
			}
		}
		return $sent;
	}

	public static function subscriptions( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY id DESC LIMIT 10', $user_id ), ARRAY_A );
	}

	public static function on_deleted_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/* --------------------------------------------------------------------
	 * 端末の登録（REST）
	 * ------------------------------------------------------------------ */

	public static function register_routes() {
		register_rest_route(
			'snscs/v1',
			'/push',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_subscribe' ),
					'permission_callback' => 'is_user_logged_in',
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'rest_unsubscribe' ),
					'permission_callback' => 'is_user_logged_in',
				),
			)
		);
	}

	private static function valid_endpoint( $endpoint ) {
		$parts = wp_parse_url( (string) $endpoint );
		return $parts && 'https' === ( $parts['scheme'] ?? '' ) && ! empty( $parts['host'] ) && strlen( $endpoint ) <= 1000
			&& ( apply_filters( 'snscs_push_allow_endpoint', false, $endpoint ) || ! preg_match( '/^(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $parts['host'] ) );
	}

	public static function rest_subscribe( WP_REST_Request $request ) {
		global $wpdb;
		$endpoint = (string) $request->get_param( 'endpoint' );
		$keys     = (array) $request->get_param( 'keys' );
		$p256dh   = isset( $keys['p256dh'] ) ? (string) $keys['p256dh'] : '';
		$auth     = isset( $keys['auth'] ) ? (string) $keys['auth'] : '';
		if ( ! self::valid_endpoint( $endpoint ) || 65 !== strlen( self::b64u_decode( $p256dh ) ) || 16 !== strlen( self::b64u_decode( $auth ) ) ) {
			return new WP_Error( 'bad_subscription', '通知の登録に失敗しました。', array( 'status' => 400 ) );
		}
		$user_id = get_current_user_id();
		if ( count( self::subscriptions( $user_id ) ) >= 10 ) {
			// 古い端末から入れ替える。
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE user_id = %d ORDER BY id ASC LIMIT 1', $user_id ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::table() . ' (user_id, endpoint, endpoint_hash, p256dh, auth, created_at) VALUES (%d, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), failures = 0', $user_id, $endpoint, sha1( $endpoint ), $p256dh, $auth, current_time( 'mysql' ) ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function rest_unsubscribe( WP_REST_Request $request ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => get_current_user_id(), 'endpoint_hash' => sha1( (string) $request->get_param( 'endpoint' ) ) ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* --------------------------------------------------------------------
	 * サービスワーカー・マニフェスト・画面
	 * ------------------------------------------------------------------ */

	public static function icon_url() {
		$icon = get_site_icon_url( 192 );
		return $icon ? $icon : SNSCS_URL . 'assets/img/app-icon.png';
	}

	/**
	 * /?snscs_sw=1（サービスワーカー。サイト全体を対象にするため、サイトの直下のURLで出す）と /?snscs_manifest=1。
	 */
	public static function serve() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['snscs_sw'] ) ) {
			header( 'Content-Type: application/javascript; charset=utf-8' );
			header( 'Cache-Control: no-cache' );
			header( 'Service-Worker-Allowed: /' );
			readfile( SNSCS_DIR . 'assets/js/snscs-sw.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			exit;
		}
		if ( isset( $_GET['snscs_manifest'] ) ) {
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			echo wp_json_encode(
				array(
					'name'             => $name,
					'short_name'       => mb_substr( $name, 0, 12 ),
					'start_url'        => SNSCS_Social::member_url() ? SNSCS_Social::member_url() : home_url( '/' ),
					'scope'            => home_url( '/' ),
					'display'          => 'standalone',
					'background_color' => '#ffffff',
					'theme_color'      => '#1e3a8a',
					'lang'             => 'ja',
					'icons'            => array(
						array( 'src' => self::icon_url(), 'sizes' => '192x192', 'type' => 'image/png' ),
						array( 'src' => get_site_icon_url( 512 ) ? get_site_icon_url( 512 ) : self::icon_url(), 'sizes' => '512x512', 'type' => 'image/png' ),
					),
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			exit;
		}
		// phpcs:enable
	}

	public static function head() {
		echo '<link rel="manifest" href="' . esc_url( home_url( '/?snscs_manifest=1' ) ) . '">' . "\n";
		echo '<meta name="theme-color" content="#1e3a8a">' . "\n";
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
		echo '<link rel="apple-touch-icon" href="' . esc_url( self::icon_url() ) . '">' . "\n";
	}

	public static function enqueue() {
		if ( ! is_user_logged_in() || ! self::available() ) {
			return;
		}
		wp_enqueue_script( 'snscs-push', SNSCS_URL . 'assets/js/snscs-push.js', array(), SNSCS_VERSION, true );
		wp_add_inline_script(
			'snscs-push',
			'window.snscsPush = ' . wp_json_encode(
				array(
					'sw'    => home_url( '/?snscs_sw=1' ),
					'scope' => wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ? wp_parse_url( home_url( '/' ), PHP_URL_PATH ) : '/',
					'key'   => self::keys()['public'],
					'rest'  => esc_url_raw( rest_url( 'snscs/v1/push' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * 会員ページの「アカウント」に出す設定。
	 */
	public static function render_settings() {
		if ( ! self::available() ) {
			return;
		}
		?>
		<section class="snscs-panel" data-snscs-push-panel>
			<h2 class="snscs-panel__title">スマホ・パソコンへの通知</h2>
			<p>フォローしている人の新しい記事などを、サイトを閉じていても、この端末に通知します（端末ごとに設定します）。</p>
			<p data-snscs-push-status class="snscs-help">確認しています…</p>
			<p>
				<button type="button" class="snscs-button snscs-button--primary" data-snscs-push="on" hidden>この端末で通知を受け取る</button>
				<button type="button" class="snscs-button" data-snscs-push="off" hidden>この端末の通知を止める</button>
			</p>
			<p class="snscs-help">iPhone・iPad の場合: Safari の共有ボタンから「ホーム画面に追加」し、ホーム画面のアイコンから開いて、このボタンを押してください（iOS 16.4 以降）。</p>
		</section>
		<?php
	}
}
