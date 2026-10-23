<?php
/**
 * サイト公式のSNSアカウント（Threads・X）への自動投稿（管理画面「発信者 → 公式SNSへの自動投稿」）。
 *
 * 投稿する内容（それぞれオン・オフできる）:
 *   今日の急上昇ワード（毎日、決めた時刻）     … 上位3語とジャンル、トレンドのページ
 *   今週フォロワーが伸びた発信者（毎週月曜）   … 公式の数を記録している発信者の7日の増加の上位3人。
 *                                               その発信者の同じSNSのアカウントを @ で紹介（本人に届き、リポストしてもらいやすい）
 *   月間アワードの結果（締めたとき）
 *   トレンド記事・月刊レポート（公開したとき）
 * 文字数は、X は「全角1文字 = 2、URL = 23」で280まで、Threads は500字まで。入りきらない行は後ろから省く。
 *
 * 公開のしかた: 「確認してから投稿」（初期。候補を作るだけで、管理画面で1件ずつ投稿）/「自動で投稿」。
 * 1回の見回り（1時間ごと）で投稿するのは1件まで（短い間に続けて投稿しない）。
 * Threads のトークンは60日で切れるので、残り10日を切ったら自動で延長する。
 * X は OAuth 1.0a（APIキー・APIシークレット・アクセストークン・アクセストークンシークレット）で投稿する（X の API は有料）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Official {

	const OPTION     = 'snscs_official';
	const QUEUE      = 'snscs_official_queue';
	const LOG        = 'snscs_official_log';
	const ADMIN_PAGE = 'snscs-official';

	public static function init() {
		add_action( SNSCS_Inbox::CRON_HOOK, array( __CLASS__, 'tick' ), 20 );
		add_action( 'snscs_awards_closed', array( __CLASS__, 'on_awards' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_publish' ), 20, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 22 );
		add_action( 'admin_post_snscs_official_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_snscs_official_run', array( __CLASS__, 'handle_run' ) );
	}

	public static function defaults() {
		return array(
			'mode'            => 'draft',
			'hour'            => 12,
			'kinds'           => array( 'trends', 'growth', 'awards', 'articles' ),
			'mention'         => 1,
			'threads_on'      => 0,
			'threads_user'    => '',
			'threads_token'   => '',
			'threads_expires' => 0,
			'threads_name'    => '',
			'x_on'            => 0,
			'x_key'           => '',
			'x_secret'        => '',
			'x_token'         => '',
			'x_token_secret'  => '',
			'x_name'          => '',
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	/**
	 * 公式アカウントのURL（構造化データの sameAs 用）。
	 */
	public static function profile_urls() {
		$s    = self::settings();
		$urls = array();
		if ( '' !== $s['threads_name'] ) {
			$urls[] = 'https://www.threads.net/@' . rawurlencode( ltrim( $s['threads_name'], '@' ) );
		}
		if ( '' !== $s['x_name'] ) {
			$urls[] = 'https://x.com/' . rawurlencode( ltrim( $s['x_name'], '@' ) );
		}
		return $urls;
	}

	public static function endpoints() {
		$base = defined( 'SNSCS_OFFICIAL_BASE' ) ? untrailingslashit( SNSCS_OFFICIAL_BASE ) : '';
		return apply_filters(
			'snscs_official_endpoints',
			array(
				'threads' => '' !== $base ? $base . '/threads' : 'https://graph.threads.net',
				'x_tweet' => '' !== $base ? $base . '/x/2/tweets' : 'https://api.x.com/2/tweets',
			)
		);
	}

	public static function log( $message, $level = 'info' ) {
		$log = (array) get_option( self::LOG, array() );
		array_unshift( $log, array( 'at' => current_time( 'mysql' ), 'level' => $level, 'message' => mb_substr( (string) $message, 0, 300 ) ) );
		update_option( self::LOG, array_slice( $log, 0, 50 ), false );
	}

	/* --------------------------------------------------------------------
	 * 投稿の候補
	 * ------------------------------------------------------------------ */

	private static function queue() {
		return wp_parse_args( (array) get_option( self::QUEUE, array() ), array( 'items' => array(), 'done' => array() ) );
	}

	/**
	 * 候補を足す（同じ key は二度足さない）。
	 *
	 * @param array $item key、title（1行目）、lines（行。@紹介は {x:handle} {threads:handle} の形）、url。
	 */
	public static function add( array $item ) {
		$q = self::queue();
		if ( in_array( $item['key'], $q['done'], true ) ) {
			return false;
		}
		$q['done'][]  = $item['key'];
		$q['done']    = array_slice( $q['done'], -300 );
		$item['at']   = current_time( 'mysql' );
		$item['sent'] = array();
		$q['items'][] = $item;
		$q['items']   = array_slice( $q['items'], -50 );
		update_option( self::QUEUE, $q, false );
		return true;
	}

	private static function enabled( $kind ) {
		return in_array( $kind, (array) self::settings()['kinds'], true );
	}

	/**
	 * その時刻になった分の候補を作る。
	 */
	public static function collect( $force = false ) {
		$s     = self::settings();
		$today = current_time( 'Y-m-d' );
		$late  = $force || (int) current_time( 'G' ) >= (int) $s['hour'];
		$made  = 0;

		// 今日の急上昇ワード。
		if ( self::enabled( 'trends' ) && $late ) {
			$lines = array();
			foreach ( array_slice( SNSCS_Trends::ranking( '', 5 ), 0, 3 ) as $i => $row ) {
				$genres  = SNSCS_Trend_Pages::genres( $row['term'] );
				$lines[] = ( $i + 1 ) . '. ' . $row['term'] . ( $genres ? '（' . $genres[0]['term']->name . '）' : '' );
			}
			if ( $lines && self::add( array( 'key' => 'trends-' . $today, 'title' => '今日の急上昇ワード🔥', 'lines' => $lines, 'url' => SNSCS_Trends::page_url() ? SNSCS_Trends::page_url() : home_url( '/' ) ) ) ) {
				++$made;
			}
		}

		// 今週フォロワーが伸びた発信者（月曜）。
		if ( self::enabled( 'growth' ) && $late && ( $force || '1' === current_time( 'N' ) ) ) {
			$growth = self::weekly_growth( 3 );
			$lines  = array();
			foreach ( $growth as $i => $g ) {
				$mention = '';
				if ( $s['mention'] ) {
					foreach ( array( 'x', 'threads' ) as $p ) {
						if ( ! empty( $g['handles'][ $p ] ) ) {
							$mention .= '{' . $p . ':' . $g['handles'][ $p ] . '}';
						}
					}
				}
				$lines[] = ( $i + 1 ) . '. ' . $g['name'] . 'さん' . $mention . '（+' . number_format_i18n( $g['gain'] ) . '人）';
			}
			if ( $lines && self::add( array( 'key' => 'growth-' . current_time( 'o-\WW' ), 'title' => '今週フォロワーが伸びた発信者📈', 'lines' => $lines, 'url' => SNSCS_Hub::page_url( 'ranking' ) ? SNSCS_Hub::page_url( 'ranking' ) : home_url( '/' ) ) ) ) {
				++$made;
			}
		}
		return $made;
	}

	/**
	 * 7日間のフォロワーの増加（公式の数を記録している発信者。多い順）。
	 *
	 * @return array<int,array{id:int,name:string,gain:int,handles:array}>
	 */
	public static function weekly_growth( $limit = 3 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -7 days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT creator_id, platform, day, followers FROM ' . SNSCS_Sync::history_table() . ' WHERE day >= %s ORDER BY day', $since ), ARRAY_A );
		$first = array();
		$last  = array();
		foreach ( $rows as $row ) {
			$k = $row['creator_id'] . '|' . $row['platform'];
			if ( ! isset( $first[ $k ] ) ) {
				$first[ $k ] = (int) $row['followers'];
			}
			$last[ $k ] = (int) $row['followers'];
		}
		$gain = array();
		foreach ( $first as $k => $f ) {
			$id          = (int) strtok( $k, '|' );
			$gain[ $id ] = ( $gain[ $id ] ?? 0 ) + $last[ $k ] - $f;
		}
		arsort( $gain );
		$penalties = SNSCS_Integrity::penalties();
		$out       = array();
		foreach ( $gain as $id => $g ) {
			if ( $g <= 0 || count( $out ) >= $limit ) {
				break;
			}
			if ( 'publish' !== get_post_status( $id ) || ( $penalties[ $id ] ?? 1 ) < 1 ) {
				continue;
			}
			$handles = array();
			foreach ( SNSCS_Accounts::get_for_creator( $id ) as $a ) {
				if ( in_array( $a['platform'], array( 'x', 'threads' ), true ) ) {
					$handles[ $a['platform'] ] = ltrim( $a['handle'], '@' );
				}
			}
			$out[] = array( 'id' => $id, 'name' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ), 'gain' => $g, 'handles' => $handles );
		}
		return $out;
	}

	public static function on_awards( $month, array $results ) {
		if ( ! self::enabled( 'awards' ) ) {
			return;
		}
		$lines = array();
		foreach ( $results as $genre_id => $rows ) {
			$g = get_term( (int) $genre_id, SNSCS_Post_Types::TAX_GENRE );
			if ( $g && ! is_wp_error( $g ) && $rows ) {
				$lines[] = $g->name . '：' . html_entity_decode( get_the_title( $rows[0][0] ), ENT_QUOTES, 'UTF-8' ) . 'さん';
			}
		}
		if ( $lines ) {
			self::add( array( 'key' => 'awards-' . $month, 'title' => '🏆' . SNSCS_Awards::month_label( $month ) . 'の発信者アワード 1位', 'lines' => $lines, 'url' => SNSCS_Awards::page_url() ? SNSCS_Awards::page_url() : home_url( '/' ) ) );
		}
	}

	public static function on_publish( $new, $old, $post ) {
		if ( 'publish' !== $new || 'publish' === $old || 'post' !== $post->post_type || ! self::enabled( 'articles' ) ) {
			return;
		}
		$kind = SNSCS_Column_Kinds::kind_of( $post->ID );
		if ( ! in_array( $kind, array( 'trend', 'report' ), true ) ) {
			return;
		}
		self::add( array( 'key' => 'post-' . $post->ID, 'title' => '【' . SNSCS_Column_Kinds::labels()[ $kind ] . '】', 'lines' => array( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) ), 'url' => get_permalink( $post ) ) );
	}

	/* --------------------------------------------------------------------
	 * 文
	 * ------------------------------------------------------------------ */

	/**
	 * X の文字数（全角などは2、URLは23）。
	 */
	public static function x_length( $text ) {
		$len  = 0;
		$text = preg_replace_callback(
			'#https?://\S+#',
			function () use ( &$len ) {
				$len += 23;
				return '';
			},
			$text
		);
		foreach ( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			$cp   = mb_ord( $ch, 'UTF-8' );
			$len += ( $cp <= 0x10FF || ( $cp >= 0x2000 && $cp <= 0x200D ) || ( $cp >= 0x2010 && $cp <= 0x201F ) || ( $cp >= 0x2032 && $cp <= 0x2037 ) ) ? 1 : 2;
		}
		return $len;
	}

	/**
	 * SNSごとの投稿文（入りきらない行は後ろから省く）。
	 */
	public static function compose( array $item, $platform ) {
		$lines = array_map(
			function ( $line ) use ( $platform ) {
				return preg_replace_callback(
					'/\{(x|threads):([A-Za-z0-9_.]+)\}/',
					function ( $m ) use ( $platform ) {
						return $m[1] === $platform ? ' @' . $m[2] : '';
					},
					$line
				);
			},
			$item['lines']
		);
		$tags = '#' . preg_replace( '/[\s　]+/u', '', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		while ( true ) {
			$text = trim( $item['title'] . "\n" . implode( "\n", $lines ) . "\n" . $item['url'] . "\n" . $tags );
			$ok   = 'x' === $platform ? self::x_length( $text ) <= 280 : mb_strlen( $text ) <= 500;
			if ( $ok || count( $lines ) <= 1 ) {
				break;
			}
			array_pop( $lines );
		}
		if ( 'x' === $platform && self::x_length( $text ) > 280 ) {
			$text = trim( $item['title'] . "\n" . $item['url'] );
		}
		return $text;
	}

	/* --------------------------------------------------------------------
	 * 投稿
	 * ------------------------------------------------------------------ */

	private static function http( $method, $url, array $args = array() ) {
		$response = wp_remote_request( $url, array_merge( array( 'method' => $method, 'timeout' => 20 ), $args ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$msg = is_array( $data ) ? ( $data['error']['message'] ?? ( $data['detail'] ?? ( $data['title'] ?? '' ) ) ) : '';
			return new WP_Error( 'snscs_official', trim( 'HTTP ' . $code . ' ' . ( is_string( $msg ) ? $msg : '' ) ) );
		}
		return $data;
	}

	/**
	 * Threads に投稿する（入れ物を作って、公開）。
	 *
	 * @return string|WP_Error 投稿のID。
	 */
	public static function post_threads( $text ) {
		$s    = self::settings();
		$base = self::endpoints()['threads'] . '/v1.0/' . rawurlencode( $s['threads_user'] );
		$made = self::http( 'POST', add_query_arg( array( 'media_type' => 'TEXT', 'text' => rawurlencode( $text ), 'access_token' => rawurlencode( $s['threads_token'] ) ), $base . '/threads' ) );
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		$done = self::http( 'POST', add_query_arg( array( 'creation_id' => rawurlencode( (string) $made['id'] ), 'access_token' => rawurlencode( $s['threads_token'] ) ), $base . '/threads_publish' ) );
		return is_wp_error( $done ) ? $done : (string) $done['id'];
	}

	/**
	 * Threads のトークンを延長する（残り10日を切ったら）。
	 */
	public static function refresh_threads( $force = false ) {
		$s = self::settings();
		if ( ! $s['threads_on'] || '' === $s['threads_token'] || ( ! $force && (int) $s['threads_expires'] > time() + 10 * DAY_IN_SECONDS ) ) {
			return false;
		}
		$data = self::http( 'GET', add_query_arg( array( 'grant_type' => 'th_refresh_token', 'access_token' => rawurlencode( $s['threads_token'] ) ), self::endpoints()['threads'] . '/refresh_access_token' ) );
		if ( is_wp_error( $data ) || empty( $data['access_token'] ) ) {
			self::log( 'Threads のトークンを延長できませんでした: ' . ( is_wp_error( $data ) ? $data->get_error_message() : '' ), 'error' );
			return false;
		}
		$s['threads_token']   = (string) $data['access_token'];
		$s['threads_expires'] = time() + (int) ( $data['expires_in'] ?? 60 * DAY_IN_SECONDS );
		update_option( self::OPTION, $s, false );
		self::log( 'Threads のトークンを延長しました。' );
		return true;
	}

	/**
	 * OAuth 1.0a の署名つきヘッダー（X）。
	 */
	public static function oauth_header( $method, $url, array $s, $nonce = '', $timestamp = 0 ) {
		$oauth = array(
			'oauth_consumer_key'     => $s['x_key'],
			'oauth_nonce'            => '' !== $nonce ? $nonce : wp_generate_password( 32, false ),
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp'        => (string) ( $timestamp ? $timestamp : time() ),
			'oauth_token'            => $s['x_token'],
			'oauth_version'          => '1.0',
		);
		$params = $oauth;
		$query  = (string) wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $q );
		$params = array_merge( $params, $q );
		ksort( $params );
		$pairs = array();
		foreach ( $params as $k => $v ) {
			$pairs[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
		}
		$base_url                 = strtok( $url, '?' );
		$base                     = strtoupper( $method ) . '&' . rawurlencode( $base_url ) . '&' . rawurlencode( implode( '&', $pairs ) );
		$key                      = rawurlencode( $s['x_secret'] ) . '&' . rawurlencode( $s['x_token_secret'] );
		$oauth['oauth_signature'] = base64_encode( hash_hmac( 'sha1', $base, $key, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$parts                    = array();
		foreach ( $oauth as $k => $v ) {
			$parts[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
		}
		return 'OAuth ' . implode( ', ', $parts );
	}

	/**
	 * @return string|WP_Error 投稿のID。
	 */
	public static function post_x( $text ) {
		$s    = self::settings();
		$url  = self::endpoints()['x_tweet'];
		$data = self::http(
			'POST',
			$url,
			array(
				'headers' => array( 'Authorization' => self::oauth_header( 'POST', $url, $s ), 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'text' => $text ), JSON_UNESCAPED_UNICODE ),
			)
		);
		return is_wp_error( $data ) ? $data : (string) ( $data['data']['id'] ?? '' );
	}

	/**
	 * 候補を1件投稿する（オンになっているSNSすべてに）。
	 */
	public static function send( $index ) {
		$q = self::queue();
		if ( ! isset( $q['items'][ $index ] ) ) {
			return false;
		}
		$s    = self::settings();
		$item = &$q['items'][ $index ];
		$ok   = false;
		foreach ( array( 'threads' => $s['threads_on'] && '' !== $s['threads_token'], 'x' => $s['x_on'] && '' !== $s['x_token'] ) as $platform => $on ) {
			if ( ! $on || ! empty( $item['sent'][ $platform ] ) ) {
				continue;
			}
			$text   = self::compose( $item, $platform );
			$result = 'x' === $platform ? self::post_x( $text ) : self::post_threads( $text );
			if ( is_wp_error( $result ) ) {
				self::log( ( 'x' === $platform ? 'X' : 'Threads' ) . 'に投稿できませんでした（' . $item['title'] . '）: ' . $result->get_error_message(), 'error' );
				$item['sent'][ $platform ] = 'error';
			} else {
				self::log( ( 'x' === $platform ? 'X' : 'Threads' ) . 'に投稿しました: ' . $item['title'] );
				$item['sent'][ $platform ] = 'ok';
				$ok                        = true;
			}
		}
		$item['posted'] = current_time( 'mysql' );
		unset( $item );
		update_option( self::QUEUE, $q, false );
		return $ok;
	}

	/**
	 * 1時間ごと: トークンの延長、候補作り、（自動なら）まだの候補を1件投稿。
	 */
	public static function tick() {
		$s = self::settings();
		if ( ! $s['threads_on'] && ! $s['x_on'] ) {
			return;
		}
		self::refresh_threads();
		self::collect();
		if ( 'live' !== $s['mode'] ) {
			return;
		}
		foreach ( self::queue()['items'] as $i => $item ) {
			if ( empty( $item['posted'] ) ) {
				self::send( $i );
				break;
			}
		}
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, '公式SNSへの自動投稿', '公式SNSへの自動投稿', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	private static function url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::ADMIN_PAGE ) );
	}

	private static function verify( $action ) {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['snscs_nonce'] ), $action ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
	}

	public static function handle_save() {
		self::verify( 'snscs_official_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verify() で確認済み。
		$in = wp_unslash( $_POST );
		$s  = self::settings();
		$s['mode']       = 'live' === ( $in['mode'] ?? '' ) ? 'live' : 'draft';
		$s['hour']       = min( 23, max( 0, (int) ( $in['hour'] ?? 12 ) ) );
		$s['kinds']      = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $in['kinds'] ?? array() ) ), array( 'trends', 'growth', 'awards', 'articles' ) ) );
		$s['mention']    = empty( $in['mention'] ) ? 0 : 1;
		$s['threads_on'] = empty( $in['threads_on'] ) ? 0 : 1;
		$s['x_on']       = empty( $in['x_on'] ) ? 0 : 1;
		foreach ( array( 'threads_user', 'threads_name', 'x_name' ) as $k ) {
			$s[ $k ] = sanitize_text_field( (string) ( $in[ $k ] ?? '' ) );
		}
		// 秘密の値は、入力があったときだけ変える。
		foreach ( array( 'threads_token', 'x_key', 'x_secret', 'x_token', 'x_token_secret' ) as $k ) {
			$v = trim( sanitize_text_field( (string) ( $in[ $k ] ?? '' ) ) );
			if ( '' !== $v ) {
				$s[ $k ] = $v;
				if ( 'threads_token' === $k ) {
					$s['threads_expires'] = time() + 60 * DAY_IN_SECONDS;
				}
			}
		}
		// phpcs:enable
		update_option( self::OPTION, $s, false );
		wp_safe_redirect( self::url( array( 'snscs_msg' => 'saved' ) ) );
		exit;
	}

	public static function handle_run() {
		self::verify( 'snscs_official_run' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verify() で確認済み。
		$do  = sanitize_key( $_POST['do'] ?? '' );
		$idx = (int) ( $_POST['index'] ?? -1 );
		// phpcs:enable
		$msg = 'done';
		if ( 'collect' === $do ) {
			$msg = self::collect( true ) ? 'collected' : 'nothing';
		} elseif ( 'send' === $do ) {
			$msg = self::send( $idx ) ? 'sent' : 'failed';
		} elseif ( 'delete' === $do ) {
			$q = self::queue();
			unset( $q['items'][ $idx ] );
			$q['items'] = array_values( $q['items'] );
			update_option( self::QUEUE, $q, false );
		}
		wp_safe_redirect( self::url( array( 'snscs_msg' => $msg ) ) );
		exit;
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = self::settings();
		$q     = self::queue();
		$log   = (array) get_option( self::LOG, array() );
		$kinds = array( 'trends' => '今日の急上昇ワード（毎日）', 'growth' => '今週フォロワーが伸びた発信者（毎週月曜）', 'awards' => '月間アワードの結果', 'articles' => 'トレンド記事・月刊レポートの公開' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg   = isset( $_GET['snscs_msg'] ) ? sanitize_key( $_GET['snscs_msg'] ) : '';
		$notes = array( 'saved' => '保存しました。', 'collected' => '投稿の候補を作りました。', 'nothing' => 'いま作れる候補はありませんでした（急上昇の言葉やフォロワーの記録がまだ少ない、または今日の分は作成済み）。', 'sent' => '投稿しました。', 'failed' => '投稿できませんでした。下の記録を確認してください。', 'done' => '' );
		?>
		<div class="wrap">
			<h1>公式SNSへの自動投稿</h1>
			<?php if ( ! empty( $notes[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo 'failed' === $msg ? 'error' : 'success'; ?>"><p><?php echo esc_html( $notes[ $msg ] ); ?></p></div>
			<?php endif; ?>
			<p>サイト公式の Threads・X に、急上昇ワード・伸びた発信者・アワードの結果・トレンド記事を投稿します。発信者を @ で紹介すると、本人がリポストしてくれやすくなります。</p>

			<h2>投稿の候補</h2>
			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
				<input type="hidden" name="action" value="snscs_official_run">
				<?php wp_nonce_field( 'snscs_official_run', 'snscs_nonce' ); ?>
				<button class="button" name="do" value="collect">いま候補を作る</button>
			</form>
			<table class="widefat striped" style="max-width: 1100px; margin-top: 8px">
				<thead><tr><th style="width: 140px">作成</th><th>Threads の文</th><th>X の文（<?php echo esc_html( '最大280' ); ?>）</th><th style="width: 170px">操作</th></tr></thead>
				<tbody>
					<?php if ( ! $q['items'] ) : ?>
						<tr><td colspan="4">まだありません。</td></tr>
					<?php endif; ?>
					<?php foreach ( array_reverse( $q['items'], true ) as $i => $item ) : ?>
						<?php $xt = self::compose( $item, 'x' ); ?>
						<tr>
							<td><?php echo esc_html( $item['at'] ); ?><?php echo ! empty( $item['posted'] ) ? '<br><strong>投稿済み</strong> ' . esc_html( implode( ' ', array_map( function ( $k, $v ) { return $k . ':' . $v; }, array_keys( $item['sent'] ), $item['sent'] ) ) ) : ''; ?></td>
							<td><pre style="white-space: pre-wrap; margin: 0"><?php echo esc_html( self::compose( $item, 'threads' ) ); ?></pre></td>
							<td><pre style="white-space: pre-wrap; margin: 0"><?php echo esc_html( $xt ); ?></pre><small><?php echo (int) self::x_length( $xt ); ?>/280</small></td>
							<td>
								<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
									<input type="hidden" name="action" value="snscs_official_run">
									<input type="hidden" name="index" value="<?php echo (int) $i; ?>">
									<?php wp_nonce_field( 'snscs_official_run', 'snscs_nonce' ); ?>
									<button class="button button-primary" name="do" value="send" <?php disabled( ! $s['threads_on'] && ! $s['x_on'] ); ?>>投稿する</button>
									<button class="button" name="do" value="delete">削除</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
				<input type="hidden" name="action" value="snscs_official_save">
				<?php wp_nonce_field( 'snscs_official_save', 'snscs_nonce' ); ?>
				<h2>設定</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">公開のしかた</th><td>
						<label><input type="radio" name="mode" value="draft" <?php checked( 'live' !== $s['mode'] ); ?>> 確認してから投稿する（候補を作るだけ。上の「投稿する」で1件ずつ）</label><br>
						<label><input type="radio" name="mode" value="live" <?php checked( 'live', $s['mode'] ); ?>> 自動で投稿する（1時間に1件まで）</label>
					</td></tr>
					<tr><th scope="row">投稿する内容</th><td>
						<?php foreach ( $kinds as $key => $label ) : ?>
							<label><input type="checkbox" name="kinds[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $s['kinds'], true ) ); ?>> <?php echo esc_html( $label ); ?></label><br>
						<?php endforeach; ?>
						<label><input type="checkbox" name="mention" value="1" <?php checked( $s['mention'] ); ?>> 発信者の同じSNSのアカウントを @ で紹介する</label>
					</td></tr>
					<tr><th scope="row"><label for="snscs-off-hour">毎日の投稿の時刻</label></th><td><input id="snscs-off-hour" type="number" name="hour" min="0" max="23" value="<?php echo (int) $s['hour']; ?>" class="small-text"> 時以降</td></tr>
					<tr><th scope="row">Threads</th><td>
						<label><input type="checkbox" name="threads_on" value="1" <?php checked( $s['threads_on'] ); ?>> 投稿する</label><br>
						<input type="text" name="threads_user" value="<?php echo esc_attr( $s['threads_user'] ); ?>" placeholder="ユーザーID（数字）" class="regular-text"><br>
						<input type="password" name="threads_token" value="" placeholder="<?php echo '' !== $s['threads_token'] ? '長期アクセストークン（設定済み。変えるときだけ）' : '長期アクセストークン'; ?>" class="regular-text" autocomplete="off"><br>
						<input type="text" name="threads_name" value="<?php echo esc_attr( $s['threads_name'] ); ?>" placeholder="ユーザー名（@なし。プロフィールのURL用）" class="regular-text">
						<p class="description">Meta の開発者サイトで Threads のアプリを作り、threads_basic と threads_content_publish の権限で長期アクセストークン（60日）を発行して貼ってください。切れる前に自動で延長します。<?php echo $s['threads_expires'] ? esc_html( '（期限: ' . wp_date( 'Y-m-d', (int) $s['threads_expires'] ) . '）' ) : ''; ?></p>
					</td></tr>
					<tr><th scope="row">X</th><td>
						<label><input type="checkbox" name="x_on" value="1" <?php checked( $s['x_on'] ); ?>> 投稿する</label><br>
						<?php foreach ( array( 'x_key' => 'APIキー', 'x_secret' => 'APIキーシークレット', 'x_token' => 'アクセストークン', 'x_token_secret' => 'アクセストークンシークレット' ) as $k => $label ) : ?>
							<input type="password" name="<?php echo esc_attr( $k ); ?>" value="" placeholder="<?php echo esc_attr( $label . ( '' !== $s[ $k ] ? '（設定済み。変えるときだけ）' : '' ) ); ?>" class="regular-text" autocomplete="off"><br>
						<?php endforeach; ?>
						<input type="text" name="x_name" value="<?php echo esc_attr( $s['x_name'] ); ?>" placeholder="ユーザー名（@なし）" class="regular-text">
						<p class="description">X の開発者ポータルでアプリを作り、「読み取りと書き込み」の権限で発行した4つの値を貼ってください（X の API の投稿は有料プランが必要です）。</p>
					</td></tr>
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
