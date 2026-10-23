<?php
/**
 * フォロワー数の自動更新（各SNSの公式API）と、本人連携による本人確認。
 *
 * 2つのやり方:
 *   キーで取る（発信者は何もしなくてよい）
 *     YouTube : YouTube Data API（運営が Google の API キーを入れる。無料枠: 1日10,000ユニット）
 *               初回だけハンドル → チャンネルID を調べ（1件1ユニット。1日3,000件まで）、以後は50件ずつ1ユニットでまとめて取る
 *     X       : X API（運営が Bearer トークンを入れる。従量課金のため最初はオフ。間隔と1日の上限を選べる）
 *   本人が連携する（発信者がマイページで「連携する」を押す。そのアカウントの持ち主であることの確認にもなる）
 *     Instagram : Instagram API（Instagram ログイン。ビジネス・クリエイターアカウントのみ）
 *     Threads   : Threads API（フォロワー数は threads_manage_insights の権限が必要）
 *     TikTok    : Login Kit（user.info.stats の権限が必要）
 *   Substack は公式APIが無いため、本人の申告のまま。
 *
 * 確認のレベル（アカウントの verified 列）: 0 = 本人の申告 / 1 = 公式APIで数を確認 / 2 = 本人が連携（持ち主であることも確認）。
 * 確認済みの数は、発信者が内容を更新して承認されても、申告の数で上書きしない（SNSCS_Accounts::replace_for_creator）。
 * 本人が連携したアカウントを、別の発信者が登録していた場合は、そちらから外す（なりすまし対策）。
 * フォロワー数の推移を日ごとに残し、発信者ページに「30日で +○人」と出す。
 * トークンは暗号化して保存する（AES-256-GCM。鍵は WordPress の秘密の値から作る）。連携を解除すると消す。
 *
 * 各SNSの接続先のURLは endpoints() にまとめ、フィルター snscs_sync_endpoints で変えられる（仕様の変更に備えて）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Sync {

	const OPTION    = 'snscs_sync';
	const CRON_HOOK = 'snscs_sync_tick';
	const LOG       = 'snscs_sync_log';

	const LEVEL_API   = 1;
	const LEVEL_OWNER = 2;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_post_snscs_sync_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_snscs_sync_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_snscs_sync_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_snscs_sync_run', array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'snscs_accounts_replaced', array( __CLASS__, 'reapply_links' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
	}

	/* --------------------------------------------------------------------
	 * テーブル・設定
	 * ------------------------------------------------------------------ */

	public static function links_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_sns_links';
	}

	public static function history_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_follower_history';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$links   = self::links_table();
		$history = self::history_table();
		dbDelta(
			"CREATE TABLE {$links} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  creator_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  platform varchar(32) NOT NULL,
  external_id varchar(100) NOT NULL,
  username varchar(191) NOT NULL DEFAULT '',
  followers bigint(20) unsigned NOT NULL DEFAULT 0,
  token text NOT NULL,
  refresh_token text NOT NULL,
  expires_at datetime DEFAULT NULL,
  linked_at datetime NOT NULL,
  synced_at datetime DEFAULT NULL,
  status varchar(10) NOT NULL DEFAULT 'ok',
  error varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY creator_platform (creator_id,platform),
  UNIQUE KEY platform_external (platform,external_id)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$history} (
  creator_id bigint(20) unsigned NOT NULL,
  platform varchar(32) NOT NULL,
  day date NOT NULL,
  followers bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (creator_id,platform,day),
  KEY day (day)
) {$charset};"
		);
	}

	public static function defaults() {
		return array(
			'youtube_key'      => '',
			'x_bearer'         => '',
			'x_enabled'        => 0,
			'x_interval'       => 7,
			'x_max_per_day'    => 300,
			'instagram_id'     => '',
			'instagram_secret' => '',
			'threads_id'       => '',
			'threads_secret'   => '',
			'tiktok_key'       => '',
			'tiktok_secret'    => '',
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function setting( $key ) {
		$s = self::settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : '';
	}

	/**
	 * 本人連携で使うSNSと、運営の設定が済んでいるか。
	 *
	 * @return array<string,bool>
	 */
	public static function oauth_platforms() {
		return array(
			'instagram' => '' !== self::setting( 'instagram_id' ) && '' !== self::setting( 'instagram_secret' ),
			'threads'   => '' !== self::setting( 'threads_id' ) && '' !== self::setting( 'threads_secret' ),
			'tiktok'    => '' !== self::setting( 'tiktok_key' ) && '' !== self::setting( 'tiktok_secret' ),
		);
	}

	/**
	 * 各SNSの接続先。
	 */
	public static function endpoints() {
		$e = array(
			'youtube_channels'    => 'https://www.googleapis.com/youtube/v3/channels',
			'x_users'             => 'https://api.x.com/2/users/by',
			'instagram_authorize' => 'https://www.instagram.com/oauth/authorize',
			'instagram_token'     => 'https://api.instagram.com/oauth/access_token',
			'instagram_graph'     => 'https://graph.instagram.com',
			'threads_authorize'   => 'https://threads.net/oauth/authorize',
			'threads_graph'       => 'https://graph.threads.net',
			'tiktok_authorize'    => 'https://www.tiktok.com/v2/auth/authorize/',
			'tiktok_token'        => 'https://open.tiktokapis.com/v2/oauth/token/',
			'tiktok_userinfo'     => 'https://open.tiktokapis.com/v2/user/info/',
		);
		// テスト用: 接続先をすべて手元のテストサーバーに向ける。
		if ( defined( 'SNSCS_SYNC_MOCK_BASE' ) ) {
			foreach ( $e as $k => $url ) {
				$e[ $k ] = rtrim( SNSCS_SYNC_MOCK_BASE, '/' ) . '/' . substr( $url, strlen( 'https://' ) );
			}
		}
		return apply_filters( 'snscs_sync_endpoints', $e );
	}

	public static function redirect_uri() {
		return rest_url( 'snscs/v1/oauth/callback' );
	}

	/* --------------------------------------------------------------------
	 * トークンの暗号化
	 * ------------------------------------------------------------------ */

	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|snscs-sync', true );
	}

	public static function encrypt( $plain ) {
		if ( '' === (string) $plain ) {
			return '';
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( (string) $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return base64_encode( $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function decrypt( $stored ) {
		$raw = base64_decode( (string) $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? '' : $plain;
	}

	/* --------------------------------------------------------------------
	 * 通信
	 * ------------------------------------------------------------------ */

	/**
	 * @return array|WP_Error 受け取った JSON。
	 */
	private static function http( $method, $url, array $args = array() ) {
		$args     = array_merge( array( 'method' => $method, 'timeout' => 20 ), $args );
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$message = is_array( $data ) ? ( $data['error']['message'] ?? ( $data['error_description'] ?? ( $data['detail'] ?? ( $data['title'] ?? '' ) ) ) ) : '';
			return new WP_Error( 'snscs_sync_http', trim( 'HTTP ' . $code . ' ' . ( is_string( $message ) ? $message : wp_json_encode( $message ) ) ) );
		}
		return $data;
	}

	public static function log( $message, $level = 'info' ) {
		$log   = (array) get_option( self::LOG, array() );
		$log[] = array( 'at' => current_time( 'mysql' ), 'level' => $level, 'message' => mb_substr( (string) $message, 0, 300 ) );
		update_option( self::LOG, array_slice( $log, -50 ), false );
	}

	/* --------------------------------------------------------------------
	 * フォロワー数を反映する
	 * ------------------------------------------------------------------ */

	/**
	 * 公式の数を発信者のアカウントに反映し、推移を記録する。
	 *
	 * @param int    $creator_id 発信者。
	 * @param string $platform   SNS。
	 * @param string $handle     ハンドル名（本人連携のときは、連携したアカウントのもの）。
	 * @param int    $followers  フォロワー数。
	 * @param int    $level      LEVEL_API / LEVEL_OWNER。
	 * @param string $external   各SNSでのID。
	 */
	public static function apply( $creator_id, $platform, $handle, $followers, $level, $external = '' ) {
		global $wpdb;
		$table  = SNSCS_Accounts::table();
		$now    = current_time( 'mysql', true );
		$handle = SNSCS_Accounts::normalize_handle( $platform, (string) $handle );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE creator_id = %d AND platform = %s", $creator_id, $platform ), ARRAY_A );
		if ( self::LEVEL_OWNER === $level && '' !== $handle ) {
			// 本人が連携したアカウントを、別の発信者が登録していたら外す（なりすまし対策）。
			$other = $wpdb->get_row( $wpdb->prepare( "SELECT id, creator_id FROM {$table} WHERE platform = %s AND handle = %s AND creator_id <> %d", $platform, $handle, $creator_id ), ARRAY_A );
			if ( $other ) {
				$wpdb->delete( $table, array( 'id' => $other['id'] ) );
				self::log( sprintf( '本人連携により、発信者「%s」が登録していた %s のアカウント「%s」を外しました（なりすましの可能性）。', get_the_title( (int) $other['creator_id'] ), snscs_platform_label( $platform ), $handle ), 'warn' );
			}
		}
		if ( $row && 0 === strcasecmp( $row['handle'], $handle ) && ! self::plausible( (int) $row['followers'], (int) $followers ) ) {
			self::log( sprintf( '%s「%s」のフォロワー数が急に0になったため、反映しませんでした。', snscs_platform_label( $platform ), $handle ), 'warn' );
			return false;
		}
		$data = array(
			'followers'   => (int) $followers,
			'verified'    => $row && 0 === strcasecmp( $row['handle'], $handle ) ? max( (int) $row['verified'], $level ) : $level,
			'synced_at'   => $now,
			'external_id' => (string) $external,
			'updated_at'  => $now,
		);
		if ( $row ) {
			if ( self::LEVEL_OWNER === $level && '' !== $handle && 0 !== strcasecmp( $row['handle'], $handle ) ) {
				$data['handle'] = $handle;
				$data['url']    = '';
			} elseif ( '' !== $handle && 0 !== strcasecmp( $row['handle'], $handle ) ) {
				// 公式APIで調べたものと違うアカウント（ハンドルが変わった）なら反映しない。
				return false;
			}
			$wpdb->update( $table, $data, array( 'id' => $row['id'] ) );
		} elseif ( self::LEVEL_OWNER === $level && '' !== $handle ) {
			$wpdb->insert( $table, array_merge( $data, array( 'creator_id' => $creator_id, 'platform' => $platform, 'handle' => $handle, 'url' => '' ) ) );
		} else {
			return false;
		}
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::history_table() . ' (creator_id, platform, day, followers) VALUES (%d, %s, %s, %d) ON DUPLICATE KEY UPDATE followers = VALUES(followers)', $creator_id, $platform, current_time( 'Y-m-d' ), (int) $followers ) );
		// phpcs:enable
		return true;
	}

	/**
	 * 発信者が内容を更新して承認されたあと（アカウントが入れ替わったあと）、本人連携の内容をもう一度反映する。
	 */
	public static function reapply_links( $creator_id ) {
		foreach ( self::links_for( $creator_id ) as $link ) {
			self::apply( $creator_id, $link['platform'], $link['username'], (int) $link['followers'], self::LEVEL_OWNER, $link['external_id'] );
		}
	}

	public static function links_for( $creator_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::links_table() . ' WHERE creator_id = %d', $creator_id ), ARRAY_A );
	}

	public static function on_delete_post( $post_id ) {
		global $wpdb;
		if ( SNSCS_Post_Types::POST_TYPE === get_post_type( $post_id ) ) {
			$wpdb->delete( self::links_table(), array( 'creator_id' => (int) $post_id ), array( '%d' ) );
			$wpdb->delete( self::history_table(), array( 'creator_id' => (int) $post_id ), array( '%d' ) );
		}
	}

	/**
	 * 取得した数が、取得の不具合ではなさそうか（100人以上いたのに急に0になったら不具合とみなす）。
	 */
	public static function plausible( $before, $after ) {
		return ! ( $before >= 100 && 0 === (int) $after );
	}

	/**
	 * 直近 $days 日のフォロワー数の増減（記録が無ければ null）。
	 */
	public static function growth( $creator_id, $platform, $days = 30 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . (int) $days . ' days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT day, followers FROM ' . self::history_table() . ' WHERE creator_id = %d AND platform = %s AND day >= %s ORDER BY day', $creator_id, $platform, $since ), ARRAY_A );
		if ( count( $rows ) < 2 ) {
			return null;
		}
		return (int) end( $rows )['followers'] - (int) $rows[0]['followers'];
	}

	/* --------------------------------------------------------------------
	 * キーで取る: YouTube・X
	 * ------------------------------------------------------------------ */

	/**
	 * YouTube のチャンネルID（ハンドルから調べる。UC で始まるIDならそのまま）。
	 *
	 * @return string|WP_Error|null 見つからなければ null。
	 */
	public static function youtube_channel_id( $handle ) {
		$handle = ltrim( (string) $handle, '@' );
		if ( preg_match( '/^UC[A-Za-z0-9_-]{22}$/', $handle ) ) {
			return $handle;
		}
		$data = self::http( 'GET', add_query_arg( array( 'part' => 'id', 'forHandle' => '@' . $handle, 'key' => self::setting( 'youtube_key' ) ), self::endpoints()['youtube_channels'] ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return empty( $data['items'][0]['id'] ) ? null : (string) $data['items'][0]['id'];
	}

	/**
	 * YouTube の登録者数を、IDごとにまとめて（50件ずつ）。
	 *
	 * @param string[] $ids チャンネルID。
	 * @return array<string,int>|WP_Error 登録者数を非公開にしているチャンネルは含めない。
	 */
	public static function youtube_counts( array $ids ) {
		$out = array();
		foreach ( array_chunk( array_values( array_unique( $ids ) ), 50 ) as $chunk ) {
			$data = self::http( 'GET', add_query_arg( array( 'part' => 'statistics', 'id' => implode( ',', $chunk ), 'key' => self::setting( 'youtube_key' ), 'maxResults' => 50 ), self::endpoints()['youtube_channels'] ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
				if ( empty( $item['statistics']['hiddenSubscriberCount'] ) && isset( $item['statistics']['subscriberCount'] ) ) {
					$out[ (string) $item['id'] ] = (int) $item['statistics']['subscriberCount'];
				}
			}
		}
		return $out;
	}

	/**
	 * YouTube: 今日まだ更新していないアカウントを更新する。
	 *
	 * @return int 更新した数。
	 */
	public static function sync_youtube( $limit = 5000 ) {
		global $wpdb;
		if ( '' === self::setting( 'youtube_key' ) ) {
			return 0;
		}
		$table = SNSCS_Accounts::table();
		$today = gmdate( 'Y-m-d 00:00:00', strtotime( current_time( 'mysql', true ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT creator_id, handle, external_id FROM {$table} WHERE platform = 'youtube' AND verified < %d AND (synced_at IS NULL OR synced_at < %s) ORDER BY synced_at IS NOT NULL, synced_at LIMIT %d", self::LEVEL_OWNER, $today, $limit ), ARRAY_A );
		// 初回はハンドル → ID を1件ずつ調べる（1日3,000件まで。無料枠を使い切らないため）。
		$resolved = (int) get_transient( 'snscs_sync_yt_resolved' );
		$ids      = array();
		foreach ( $rows as $i => $row ) {
			if ( '' === $row['external_id'] ) {
				if ( $resolved >= 3000 ) {
					unset( $rows[ $i ] );
					continue;
				}
				$id = self::youtube_channel_id( $row['handle'] );
				++$resolved;
				if ( is_wp_error( $id ) ) {
					self::log( 'YouTube: ' . $id->get_error_message(), 'error' );
					set_transient( 'snscs_sync_yt_resolved', $resolved, DAY_IN_SECONDS );
					return 0;
				}
				if ( null === $id ) {
					// 見つからないハンドルは、今日はもう調べない。
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET synced_at = %s WHERE creator_id = %d AND platform = 'youtube'", current_time( 'mysql', true ), $row['creator_id'] ) );
					unset( $rows[ $i ] );
					continue;
				}
				$rows[ $i ]['external_id'] = $id;
			}
			$ids[] = $rows[ $i ]['external_id'];
		}
		set_transient( 'snscs_sync_yt_resolved', $resolved, DAY_IN_SECONDS );
		if ( ! $ids ) {
			return 0;
		}
		$counts = self::youtube_counts( $ids );
		if ( is_wp_error( $counts ) ) {
			self::log( 'YouTube: ' . $counts->get_error_message(), 'error' );
			return 0;
		}
		$n = 0;
		foreach ( $rows as $row ) {
			if ( isset( $counts[ $row['external_id'] ] ) && self::apply( (int) $row['creator_id'], 'youtube', $row['handle'], $counts[ $row['external_id'] ], self::LEVEL_API, $row['external_id'] ) ) {
				++$n;
			} else {
				// 非公開などで取れなかったものも、今日はもう調べない。
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET synced_at = %s, external_id = %s WHERE creator_id = %d AND platform = 'youtube'", current_time( 'mysql', true ), $row['external_id'], $row['creator_id'] ) );
			}
		}
		return $n;
	}

	/**
	 * X: 間隔（日数）を過ぎたアカウントを、1日の上限まで更新する（従量課金のため）。
	 */
	public static function sync_x() {
		global $wpdb;
		if ( ! self::setting( 'x_enabled' ) || '' === self::setting( 'x_bearer' ) ) {
			return 0;
		}
		$used  = (int) get_transient( 'snscs_sync_x_used' );
		$limit = max( 0, (int) self::setting( 'x_max_per_day' ) - $used );
		if ( ! $limit ) {
			return 0;
		}
		$table = SNSCS_Accounts::table();
		$due   = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql', true ) ) - max( 1, (int) self::setting( 'x_interval' ) ) * DAY_IN_SECONDS + HOUR_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT creator_id, handle FROM {$table} WHERE platform = 'x' AND (synced_at IS NULL OR synced_at < %s) ORDER BY synced_at IS NOT NULL, synced_at LIMIT %d", $due, $limit ), ARRAY_A );
		$n    = 0;
		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$names = array_map(
				function ( $r ) {
					return ltrim( $r['handle'], '@' );
				},
				$chunk
			);
			$data = self::http(
				'GET',
				add_query_arg( array( 'usernames' => implode( ',', $names ), 'user.fields' => 'public_metrics' ), self::endpoints()['x_users'] ),
				array( 'headers' => array( 'Authorization' => 'Bearer ' . self::setting( 'x_bearer' ) ) )
			);
			$used += count( $chunk );
			if ( is_wp_error( $data ) ) {
				self::log( 'X: ' . $data->get_error_message(), 'error' );
				break;
			}
			$found = array();
			foreach ( (array) ( $data['data'] ?? array() ) as $user ) {
				$found[ strtolower( $user['username'] ) ] = $user;
			}
			foreach ( $chunk as $row ) {
				$user = $found[ strtolower( ltrim( $row['handle'], '@' ) ) ] ?? null;
				if ( $user && isset( $user['public_metrics']['followers_count'] ) && self::apply( (int) $row['creator_id'], 'x', $row['handle'], (int) $user['public_metrics']['followers_count'], self::LEVEL_API, (string) $user['id'] ) ) {
					++$n;
				} else {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET synced_at = %s WHERE creator_id = %d AND platform = 'x'", current_time( 'mysql', true ), $row['creator_id'] ) );
				}
			}
		}
		set_transient( 'snscs_sync_x_used', $used, DAY_IN_SECONDS );
		return $n;
	}

	/* --------------------------------------------------------------------
	 * 本人が連携する: Instagram・Threads・TikTok
	 * ------------------------------------------------------------------ */

	public static function authorize_url( $platform, $state ) {
		$e = self::endpoints();
		switch ( $platform ) {
			case 'instagram':
				return add_query_arg( array( 'client_id' => self::setting( 'instagram_id' ), 'redirect_uri' => rawurlencode( self::redirect_uri() ), 'response_type' => 'code', 'scope' => 'instagram_business_basic', 'state' => $state ), $e['instagram_authorize'] );
			case 'threads':
				return add_query_arg( array( 'client_id' => self::setting( 'threads_id' ), 'redirect_uri' => rawurlencode( self::redirect_uri() ), 'response_type' => 'code', 'scope' => 'threads_basic,threads_manage_insights', 'state' => $state ), $e['threads_authorize'] );
			case 'tiktok':
				return add_query_arg( array( 'client_key' => self::setting( 'tiktok_key' ), 'redirect_uri' => rawurlencode( self::redirect_uri() ), 'response_type' => 'code', 'scope' => 'user.info.basic,user.info.profile,user.info.stats', 'state' => $state ), $e['tiktok_authorize'] );
		}
		return '';
	}

	/**
	 * 認可コードをトークンに替える。
	 *
	 * @return array{token:string,refresh:string,expires:int,id:string}|WP_Error
	 */
	public static function exchange( $platform, $code ) {
		$e = self::endpoints();
		if ( 'instagram' === $platform || 'threads' === $platform ) {
			$p     = 'instagram' === $platform ? 'instagram' : 'threads';
			$short = self::http(
				'POST',
				'instagram' === $platform ? $e['instagram_token'] : $e['threads_graph'] . '/oauth/access_token',
				array( 'body' => array( 'client_id' => self::setting( $p . '_id' ), 'client_secret' => self::setting( $p . '_secret' ), 'grant_type' => 'authorization_code', 'redirect_uri' => self::redirect_uri(), 'code' => $code ) )
			);
			if ( is_wp_error( $short ) ) {
				return $short;
			}
			// 新しい形では data[0] に入ることがある。
			$short = isset( $short['data'][0] ) ? $short['data'][0] : $short;
			$long  = self::http(
				'GET',
				add_query_arg(
					array(
						'grant_type'    => 'instagram' === $platform ? 'ig_exchange_token' : 'th_exchange_token',
						'client_secret' => self::setting( $p . '_secret' ),
						'access_token'  => $short['access_token'] ?? '',
					),
					( 'instagram' === $platform ? $e['instagram_graph'] : $e['threads_graph'] ) . '/access_token'
				)
			);
			if ( is_wp_error( $long ) ) {
				return $long;
			}
			return array( 'token' => (string) $long['access_token'], 'refresh' => '', 'expires' => time() + (int) ( $long['expires_in'] ?? 5184000 ), 'id' => (string) ( $short['user_id'] ?? '' ) );
		}
		if ( 'tiktok' === $platform ) {
			$data = self::http(
				'POST',
				$e['tiktok_token'],
				array(
					'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
					'body'    => array( 'client_key' => self::setting( 'tiktok_key' ), 'client_secret' => self::setting( 'tiktok_secret' ), 'code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => self::redirect_uri() ),
				)
			);
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			if ( empty( $data['access_token'] ) ) {
				return new WP_Error( 'snscs_sync', 'TikTok: ' . ( $data['error_description'] ?? '連携できませんでした' ) );
			}
			return array( 'token' => (string) $data['access_token'], 'refresh' => (string) ( $data['refresh_token'] ?? '' ), 'expires' => time() + (int) ( $data['expires_in'] ?? 86400 ), 'id' => (string) ( $data['open_id'] ?? '' ) );
		}
		return new WP_Error( 'snscs_sync', '対応していないSNSです。' );
	}

	/**
	 * 期限が近いトークンを延長する。
	 *
	 * @return array|WP_Error|null 変わらなければ null。
	 */
	public static function refresh( array $link ) {
		$e       = self::endpoints();
		$expires = $link['expires_at'] ? strtotime( $link['expires_at'] ) : 0;
		if ( 'tiktok' === $link['platform'] ) {
			if ( $expires > time() + 10 * MINUTE_IN_SECONDS ) {
				return null;
			}
			$data = self::http(
				'POST',
				$e['tiktok_token'],
				array(
					'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
					'body'    => array( 'client_key' => self::setting( 'tiktok_key' ), 'client_secret' => self::setting( 'tiktok_secret' ), 'grant_type' => 'refresh_token', 'refresh_token' => self::decrypt( $link['refresh_token'] ) ),
				)
			);
			if ( is_wp_error( $data ) || empty( $data['access_token'] ) ) {
				return is_wp_error( $data ) ? $data : new WP_Error( 'snscs_sync', 'TikTok: 延長できませんでした' );
			}
			return array( 'token' => (string) $data['access_token'], 'refresh' => (string) ( $data['refresh_token'] ?? self::decrypt( $link['refresh_token'] ) ), 'expires' => time() + (int) ( $data['expires_in'] ?? 86400 ) );
		}
		// Instagram・Threads: 60日のトークン。残り15日を切ったら延長する（発行から24時間たったものだけ延長できる）。
		if ( $expires > time() + 15 * DAY_IN_SECONDS || strtotime( $link['linked_at'] ) > strtotime( current_time( 'mysql' ) ) - DAY_IN_SECONDS ) {
			return null;
		}
		$ig   = 'instagram' === $link['platform'];
		$data = self::http(
			'GET',
			add_query_arg(
				array( 'grant_type' => $ig ? 'ig_refresh_token' : 'th_refresh_token', 'access_token' => self::decrypt( $link['token'] ) ),
				( $ig ? $e['instagram_graph'] : $e['threads_graph'] ) . '/refresh_access_token'
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return array( 'token' => (string) $data['access_token'], 'refresh' => '', 'expires' => time() + (int) ( $data['expires_in'] ?? 5184000 ) );
	}

	/**
	 * 連携したアカウントのユーザー名とフォロワー数。
	 *
	 * @return array{username:string,followers:int,id:string}|WP_Error
	 */
	public static function profile( $platform, $token, $known_id = '' ) {
		$e = self::endpoints();
		if ( 'instagram' === $platform ) {
			$data = self::http( 'GET', add_query_arg( array( 'fields' => 'user_id,username,followers_count', 'access_token' => $token ), $e['instagram_graph'] . '/me' ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			if ( ! isset( $data['followers_count'] ) ) {
				return new WP_Error( 'snscs_sync', 'Instagram: フォロワー数を取得できませんでした（ビジネスまたはクリエイターアカウントで連携してください）。' );
			}
			return array( 'username' => (string) $data['username'], 'followers' => (int) $data['followers_count'], 'id' => (string) ( $data['user_id'] ?? ( $data['id'] ?? $known_id ) ) );
		}
		if ( 'threads' === $platform ) {
			$me = self::http( 'GET', add_query_arg( array( 'fields' => 'id,username', 'access_token' => $token ), $e['threads_graph'] . '/v1.0/me' ) );
			if ( is_wp_error( $me ) ) {
				return $me;
			}
			$ins = self::http( 'GET', add_query_arg( array( 'metric' => 'followers_count', 'access_token' => $token ), $e['threads_graph'] . '/v1.0/' . rawurlencode( (string) $me['id'] ) . '/threads_insights' ) );
			if ( is_wp_error( $ins ) ) {
				return $ins;
			}
			$value = $ins['data'][0]['total_value']['value'] ?? null;
			if ( null === $value ) {
				return new WP_Error( 'snscs_sync', 'Threads: フォロワー数を取得できませんでした。' );
			}
			return array( 'username' => (string) $me['username'], 'followers' => (int) $value, 'id' => (string) $me['id'] );
		}
		if ( 'tiktok' === $platform ) {
			$data = self::http( 'GET', add_query_arg( 'fields', 'open_id,username,follower_count', $e['tiktok_userinfo'] ), array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			if ( ( $data['error']['code'] ?? 'ok' ) !== 'ok' || ! isset( $data['data']['user']['follower_count'] ) ) {
				return new WP_Error( 'snscs_sync', 'TikTok: ' . ( $data['error']['message'] ?? 'フォロワー数を取得できませんでした' ) );
			}
			$user = $data['data']['user'];
			return array( 'username' => (string) ( $user['username'] ?? '' ), 'followers' => (int) $user['follower_count'], 'id' => (string) ( $user['open_id'] ?? $known_id ) );
		}
		return new WP_Error( 'snscs_sync', '対応していないSNSです。' );
	}

	/**
	 * 連携したアカウントを1件更新する（トークンの延長も）。
	 */
	public static function sync_link( array $link ) {
		global $wpdb;
		$table = self::links_table();
		$new   = self::refresh( $link );
		if ( is_wp_error( $new ) ) {
			$wpdb->update( $table, array( 'status' => 'error', 'error' => mb_substr( $new->get_error_message(), 0, 250 ), 'synced_at' => current_time( 'mysql' ) ), array( 'id' => $link['id'] ) );
			return false;
		}
		if ( $new ) {
			$link['token'] = self::encrypt( $new['token'] );
			$update        = array( 'token' => $link['token'], 'expires_at' => gmdate( 'Y-m-d H:i:s', $new['expires'] ) );
			if ( '' !== $new['refresh'] ) {
				$update['refresh_token'] = self::encrypt( $new['refresh'] );
			}
			$wpdb->update( $table, $update, array( 'id' => $link['id'] ) );
		}
		$profile = self::profile( $link['platform'], self::decrypt( $link['token'] ), $link['external_id'] );
		if ( is_wp_error( $profile ) ) {
			$wpdb->update( $table, array( 'status' => 'error', 'error' => mb_substr( $profile->get_error_message(), 0, 250 ), 'synced_at' => current_time( 'mysql' ) ), array( 'id' => $link['id'] ) );
			return false;
		}
		if ( ! self::plausible( (int) $link['followers'], $profile['followers'] ) ) {
			$wpdb->update( $table, array( 'status' => 'error', 'error' => 'フォロワー数が急に0になったため、取得の不具合とみなして反映しませんでした。', 'synced_at' => current_time( 'mysql' ) ), array( 'id' => $link['id'] ) );
			return false;
		}
		$wpdb->update( $table, array( 'username' => $profile['username'], 'followers' => $profile['followers'], 'status' => 'ok', 'error' => '', 'synced_at' => current_time( 'mysql' ) ), array( 'id' => $link['id'] ) );
		return self::apply( (int) $link['creator_id'], $link['platform'], $profile['username'], $profile['followers'], self::LEVEL_OWNER, $profile['id'] );
	}

	public static function register_routes() {
		register_rest_route(
			'snscs/v1',
			'/oauth/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_callback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * マイページの「連携する」→ 各SNSの許可の画面へ。
	 */
	public static function handle_connect() {
		$platform = isset( $_GET['platform'] ) ? sanitize_key( $_GET['platform'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id  = get_current_user_id();
		$creator  = $user_id ? SNSCS_Submissions::creator_id_for_user( $user_id ) : 0;
		if ( ! $creator || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'snscs_sync_connect' ) || empty( self::oauth_platforms()[ $platform ] ) ) {
			wp_safe_redirect( add_query_arg( 'snscs_notice', 'sync_error', snscs_portal_url() ) );
			exit;
		}
		$state = wp_generate_password( 32, false );
		set_transient( 'snscs_oauth_' . $state, array( 'user' => $user_id, 'creator' => $creator, 'platform' => $platform ), 15 * MINUTE_IN_SECONDS );
		wp_redirect( self::authorize_url( $platform, $state ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- 各SNSの許可の画面（外部）へ。
		exit;
	}

	/**
	 * 各SNSの許可の画面から戻ってきたところ。state で本人を確かめる（1回だけ使える）。
	 */
	public static function rest_callback( WP_REST_Request $request ) {
		global $wpdb;
		$state = preg_replace( '/[^A-Za-z0-9]/', '', (string) $request->get_param( 'state' ) );
		$info  = $state ? get_transient( 'snscs_oauth_' . $state ) : false;
		$back  = function ( $notice ) {
			wp_safe_redirect( add_query_arg( 'snscs_notice', $notice, snscs_portal_url() ) . '#snscs-sync' );
			exit;
		};
		if ( ! is_array( $info ) ) {
			$back( 'sync_expired' );
		}
		delete_transient( 'snscs_oauth_' . $state );
		$code = (string) $request->get_param( 'code' );
		if ( '' === $code ) {
			$back( 'sync_cancel' );
		}
		$token = self::exchange( $info['platform'], $code );
		if ( is_wp_error( $token ) ) {
			self::log( snscs_platform_label( $info['platform'] ) . ' の連携に失敗: ' . $token->get_error_message(), 'error' );
			$back( 'sync_error' );
		}
		$profile = self::profile( $info['platform'], $token['token'], $token['id'] );
		if ( is_wp_error( $profile ) ) {
			self::log( snscs_platform_label( $info['platform'] ) . ' の連携に失敗: ' . $profile->get_error_message(), 'error' );
			$back( 'instagram' === $info['platform'] ? 'sync_ig_type' : 'sync_error' );
		}
		$table = self::links_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$taken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT creator_id FROM {$table} WHERE platform = %s AND external_id = %s AND creator_id <> %d", $info['platform'], $profile['id'], $info['creator'] ) );
		if ( $taken ) {
			$back( 'sync_taken' );
		}
		$wpdb->delete( $table, array( 'creator_id' => $info['creator'], 'platform' => $info['platform'] ) );
		$wpdb->insert(
			$table,
			array(
				'creator_id'    => $info['creator'],
				'user_id'       => $info['user'],
				'platform'      => $info['platform'],
				'external_id'   => $profile['id'],
				'username'      => $profile['username'],
				'followers'     => $profile['followers'],
				'token'         => self::encrypt( $token['token'] ),
				'refresh_token' => self::encrypt( $token['refresh'] ),
				'expires_at'    => gmdate( 'Y-m-d H:i:s', $token['expires'] ),
				'linked_at'     => current_time( 'mysql' ),
				'synced_at'     => current_time( 'mysql' ),
			)
		);
		self::apply( (int) $info['creator'], $info['platform'], $profile['username'], $profile['followers'], self::LEVEL_OWNER, $profile['id'] );
		SNSCS_Index::index_creator( (int) $info['creator'] );
		SNSCS_Scores::schedule_soon();
		$back( 'sync_linked' );
	}

	public static function handle_disconnect() {
		global $wpdb;
		$platform = isset( $_POST['platform'] ) ? sanitize_key( $_POST['platform'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$creator  = SNSCS_Submissions::creator_id_for_user( get_current_user_id() );
		if ( $creator && isset( $_POST['snscs_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_sync_disconnect' ) ) {
			$wpdb->delete( self::links_table(), array( 'creator_id' => $creator, 'platform' => $platform ) );
			// 本人連携の印を外す（数は、次に申請するまでそのまま）。
			$wpdb->update( SNSCS_Accounts::table(), array( 'verified' => 0 ), array( 'creator_id' => $creator, 'platform' => $platform ) );
		}
		wp_safe_redirect( add_query_arg( 'snscs_notice', 'sync_unlinked', snscs_portal_url() ) . '#snscs-sync' );
		exit;
	}

	/* --------------------------------------------------------------------
	 * 定期の更新
	 * ------------------------------------------------------------------ */

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * 1時間ごと: 連携したアカウント（20時間以上たったもの）と、YouTube・X を少しずつ更新する。
	 *
	 * @return array{links:int,youtube:int,x:int}
	 */
	public static function tick() {
		global $wpdb;
		$start = microtime( true );
		$links = 0;
		$due   = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 20 * HOUR_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::links_table() . ' WHERE synced_at IS NULL OR synced_at < %s ORDER BY synced_at LIMIT 200', $due ), ARRAY_A );
		foreach ( $rows as $link ) {
			if ( ! empty( self::oauth_platforms()[ $link['platform'] ] ) && self::sync_link( $link ) ) {
				++$links;
			}
			if ( microtime( true ) - $start > 20 ) {
				break;
			}
		}
		$youtube = self::sync_youtube( 2000 );
		$x       = self::sync_x();
		if ( $links + $youtube + $x ) {
			update_option( 'snscs_sync_last', array( 'at' => current_time( 'mysql' ), 'links' => $links, 'youtube' => $youtube, 'x' => $x ), false );
		}
		return array( 'links' => $links, 'youtube' => $youtube, 'x' => $x );
	}

	/* --------------------------------------------------------------------
	 * 発信者のマイページ
	 * ------------------------------------------------------------------ */

	public static function render_portal( $creator_id ) {
		$enabled = array_filter( self::oauth_platforms() );
		$auto    = array();
		if ( '' !== self::setting( 'youtube_key' ) ) {
			$auto[] = 'YouTube';
		}
		if ( self::setting( 'x_enabled' ) && '' !== self::setting( 'x_bearer' ) ) {
			$auto[] = 'X';
		}
		if ( ! $enabled && ! $auto ) {
			return;
		}
		$links = array();
		foreach ( self::links_for( $creator_id ) as $link ) {
			$links[ $link['platform'] ] = $link;
		}
		?>
		<section class="snscs-panel" id="snscs-sync">
			<h2 class="snscs-panel__title">SNSと連携する（フォロワー数の自動更新・本人確認）</h2>
			<p>連携すると、フォロワー数が毎日自動で最新になり、発信者ページに「本人確認済み」の印が付きます。パスワードがこのサイトに渡ることはなく、いつでも解除できます。</p>
			<?php if ( $auto ) : ?>
				<p class="snscs-help"><?php echo esc_html( implode( '・', $auto ) ); ?>は、連携しなくても公式の数を自動で取り込みます。</p>
			<?php endif; ?>
			<ul class="snscs-sync-list">
				<?php foreach ( array_keys( $enabled ) as $platform ) : ?>
					<?php $link = $links[ $platform ] ?? null; ?>
					<li class="snscs-sync-list__item">
						<strong><?php echo esc_html( snscs_platform_label( $platform ) ); ?></strong>
						<?php if ( $link ) : ?>
							<span>連携中: <?php echo esc_html( snscs_display_handle( $platform, $link['username'] ) ); ?>（<?php echo esc_html( number_format_i18n( $link['followers'] ) ); ?>人）</span>
							<?php if ( 'error' === $link['status'] ) : ?>
								<span class="snscs-sync-list__error">更新できませんでした。もう一度連携してください。</span>
								<a class="snscs-button" href="<?php echo esc_url( self::connect_url( $platform ) ); ?>">連携し直す</a>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>" style="display:inline">
								<input type="hidden" name="action" value="snscs_sync_disconnect">
								<input type="hidden" name="platform" value="<?php echo esc_attr( $platform ); ?>">
								<?php wp_nonce_field( 'snscs_sync_disconnect', 'snscs_nonce' ); ?>
								<button type="submit" class="snscs-linklike">解除</button>
							</form>
						<?php else : ?>
							<a class="snscs-button snscs-button--primary" href="<?php echo esc_url( self::connect_url( $platform ) ); ?>">連携する</a>
							<?php if ( 'instagram' === $platform ) : ?>
								<span class="snscs-help">（ビジネスまたはクリエイターアカウントのみ）</span>
							<?php endif; ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	public static function connect_url( $platform ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'snscs_sync_connect', 'platform' => $platform ), snscs_post_url() ), 'snscs_sync_connect' );
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, 'フォロワー数の自動更新', 'フォロワー数の自動更新', 'manage_options', 'snscs-sync', array( __CLASS__, 'render_admin' ) );
	}

	private static function admin_url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=snscs-sync' ) );
	}

	public static function handle_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_sync_settings' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$old = self::settings();
		$new = array();
		foreach ( self::defaults() as $key => $default ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 上で確認済み。
			$value = isset( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
			if ( is_int( $default ) ) {
				$new[ $key ] = 'x_enabled' === $key ? ( '' !== $value ? 1 : 0 ) : max( 'x_interval' === $key ? 1 : 0, (int) $value );
			} else {
				// 秘密の値（キー・シークレット・トークン）は画面に出さないため、空のまま保存したら今の値を残す。
				$is_secret   = false !== strpos( $key, 'secret' ) || in_array( $key, array( 'x_bearer', 'youtube_key' ), true );
				$new[ $key ] = $is_secret && '' === $value ? $old[ $key ] : $value;
			}
		}
		update_option( self::OPTION, $new, false );
		wp_safe_redirect( self::admin_url( array( 'snscs_msg' => 'saved' ) ) );
		exit;
	}

	public static function handle_run() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_sync_run' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$r = self::tick();
		wp_safe_redirect( self::admin_url( array( 'snscs_msg' => 'ran', 'l' => $r['links'], 'y' => $r['youtube'], 'x' => $r['x'] ) ) );
		exit;
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$s      = self::settings();
		$table  = SNSCS_Accounts::table();
		$counts = $wpdb->get_results( "SELECT platform, COUNT(*) AS n, SUM(verified = 1) AS api, SUM(verified = 2) AS owner FROM {$table} GROUP BY platform", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$log    = array_reverse( (array) get_option( self::LOG, array() ) );
		$msg    = isset( $_GET['snscs_msg'] ) ? sanitize_key( $_GET['snscs_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$secret = function ( $key, $label, $help = '' ) use ( $s ) {
			echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="password" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="" class="regular-text" autocomplete="off" placeholder="' . esc_attr( '' !== $s[ $key ] ? '設定済み（変えるときだけ入力）' : '' ) . '">' . ( $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>';
		};
		$plain  = function ( $key, $label, $help = '' ) use ( $s ) {
			echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $s[ $key ] ) . '" class="regular-text" autocomplete="off">' . ( $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>';
		};
		?>
		<div class="wrap">
			<h1>フォロワー数の自動更新</h1>
			<?php if ( 'saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
			<?php elseif ( 'ran' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( '更新しました（本人連携 %d件・YouTube %d件・X %d件）。', isset( $_GET['l'] ) ? absint( $_GET['l'] ) : 0, isset( $_GET['y'] ) ? absint( $_GET['y'] ) : 0, isset( $_GET['x'] ) ? absint( $_GET['x'] ) : 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>
			<p>各SNSの公式の仕組み（API）で、フォロワー数を毎日自動で最新にします。公式に確認できた数には印が付き、発信者が申告した数で上書きされなくなります。設定していないSNSは、今までどおり本人の申告と審査で運用します。</p>

			<h2>いまの状態</h2>
			<table class="widefat striped" style="max-width: 700px">
				<thead><tr><th>SNS</th><th style="text-align:right">登録アカウント</th><th style="text-align:right">公式APIで確認</th><th style="text-align:right">本人連携</th></tr></thead>
				<tbody>
					<?php foreach ( $counts as $row ) : ?>
						<?php
						if ( ! array_key_exists( $row['platform'], snscs_platforms() ) ) {
							continue;
						}
						?>
						<tr><td><?php echo esc_html( snscs_platform_label( $row['platform'] ) ); ?></td><td style="text-align:right"><?php echo esc_html( number_format_i18n( $row['n'] ) ); ?></td><td style="text-align:right"><?php echo esc_html( number_format_i18n( $row['api'] ) ); ?></td><td style="text-align:right"><?php echo esc_html( number_format_i18n( $row['owner'] ) ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>" style="margin: 12px 0">
				<input type="hidden" name="action" value="snscs_sync_run">
				<?php wp_nonce_field( 'snscs_sync_run', 'snscs_nonce' ); ?>
				<button type="submit" class="button">今すぐ更新する</button>
				<span class="description">ふだんは1時間ごとに少しずつ、自動で更新します。</span>
			</form>

			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
				<input type="hidden" name="action" value="snscs_sync_settings">
				<?php wp_nonce_field( 'snscs_sync_settings', 'snscs_nonce' ); ?>

				<h2>YouTube（発信者は何もしなくてよい・無料）</h2>
				<table class="form-table" role="presentation">
					<?php $secret( 'youtube_key', 'API キー', 'Google Cloud でプロジェクトを作り、「YouTube Data API v3」を有効にして API キーを作ります。無料枠（1日10,000ユニット）で、登録者数は数万チャンネルまで毎日更新できます。' ); ?>
				</table>

				<h2>Instagram・Threads・TikTok（発信者が「連携する」を押す・無料）</h2>
				<p>各SNSの開発者向けサイトでアプリを作り、次の「戻り先のURL」を登録してから、アプリのIDと秘密の値を入れてください。</p>
				<p><code><?php echo esc_html( self::redirect_uri() ); ?></code></p>
				<table class="form-table" role="presentation">
					<?php $plain( 'instagram_id', 'Instagram のアプリID', 'Meta for Developers で「Instagram API（Instagram ログイン）」を使うアプリを作ります。権限 instagram_business_basic。ビジネス・クリエイターアカウントのみ連携できます。' ); ?>
					<?php $secret( 'instagram_secret', 'Instagram のアプリシークレット' ); ?>
					<?php $plain( 'threads_id', 'Threads のアプリID', 'Meta for Developers で「Threads API」を使うアプリを作ります。権限 threads_basic と threads_manage_insights（フォロワー数に必要）。' ); ?>
					<?php $secret( 'threads_secret', 'Threads のアプリシークレット' ); ?>
					<?php $plain( 'tiktok_key', 'TikTok のクライアントキー', 'TikTok for Developers で Login Kit を使うアプリを作ります。権限 user.info.basic・user.info.profile・user.info.stats（審査あり）。' ); ?>
					<?php $secret( 'tiktok_secret', 'TikTok のクライアントシークレット' ); ?>
				</table>

				<h2>X（有料・最初はオフ）</h2>
				<table class="form-table" role="presentation">
					<tr><th>使う</th><td><label><input type="checkbox" name="x_enabled" value="1" <?php checked( (int) $s['x_enabled'] ); ?>> X のフォロワー数を自動で取り込む</label>
						<p class="description">X API は従量課金です（目安: アカウント1件の取得ごとに約1.5円。最新の料金は X の開発者向けサイトで確認してください）。</p></td></tr>
					<?php $secret( 'x_bearer', 'Bearer トークン' ); ?>
					<tr><th><label for="x_interval">更新の間隔</label></th><td><input type="number" id="x_interval" name="x_interval" min="1" max="90" value="<?php echo (int) $s['x_interval']; ?>" class="small-text"> 日ごと</td></tr>
					<tr><th><label for="x_max_per_day">1日の上限</label></th><td><input type="number" id="x_max_per_day" name="x_max_per_day" min="0" value="<?php echo (int) $s['x_max_per_day']; ?>" class="small-text"> 件まで
						<p class="description"><?php echo esc_html( sprintf( 'いまの設定だと、多くても1日 約%s円・1か月 約%s円です。', number_format_i18n( (int) $s['x_max_per_day'] * 1.5 ), number_format_i18n( (int) $s['x_max_per_day'] * 1.5 * 30 ) ) ); ?></p></td></tr>
				</table>
				<?php submit_button( '設定を保存' ); ?>
			</form>

			<h2>記録（新しい順）</h2>
			<?php if ( ! $log ) : ?>
				<p>まだありません。</p>
			<?php else : ?>
				<ul>
					<?php foreach ( array_slice( $log, 0, 20 ) as $item ) : ?>
						<li style="<?php echo 'info' === $item['level'] ? '' : 'color:#b32d2e'; ?>"><?php echo esc_html( $item['at'] . ' ' . $item['message'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}
