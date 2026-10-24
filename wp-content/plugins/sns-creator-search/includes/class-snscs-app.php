<?php
/**
 * スマホアプリとして動かす（PWA。1.22）。
 *
 * - アプリの設定（マニフェスト /?snscs_manifest=1）: 名前・アイコン（Android の丸や角丸に切り抜かれても欠けないアイコンも）・
 *   ホーム画面のアイコンを長押ししたときのショートカット（探す・ランキング・お知らせ・マイページ）・
 *   ほかのアプリからの「共有」の受け取り（SNSのプロフィールのURLを共有すると、その人のページを開く。文なら検索する）
 * - サービスワーカー（/?snscs_sw=1）: 見たページと画像・CSSを端末に覚え、電波が無くても前に見たページを開ける。
 *   開けないページは「オフラインです」のページを出す。プッシュ通知（SNSCS_Push）もここで受け取る。
 *   ログイン中のページ（個人の情報を含む）は覚えない。管理画面・ログイン・送信・データのやりとりは常にサーバーへ
 * - インストールの案内: 2回目に来た人に「アプリとして追加」を出す（Android・パソコンはボタン1つ、iPhone は共有メニューからの手順）。
 *   閉じたら30日は出さない
 * - アプリとして開いたとき: 画面の下にタブ（ホーム・探す・記事・お知らせ・マイページ）と、左上に戻るボタン。
 *   お知らせの未読の数は、タブとホーム画面のアイコン（対応する端末）に出す
 * - アプリストアに出す準備: Google Play（Android）用に /.well-known/assetlinks.json を出す（パッケージ名と署名の指紋を設定）。
 *   iPhone の App Store 用は PWABuilder などでアプリの形にする手順を、管理画面「スマホアプリ」に書いてある
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_App {

	const OPTION     = 'snscs_app_settings';
	const ADMIN_PAGE = 'snscs-app';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'serve' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_share' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 2 );
		add_action( 'send_headers', array( __CLASS__, 'private_header' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_snscs_app_save', array( __CLASS__, 'handle_save' ) );
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'on'             => 1,
				'name'           => '',
				'short_name'     => '',
				'install_prompt' => 1,
				'tabbar'         => 1,
				'offline'        => 1,
				'android_pkg'    => '',
				'android_sha256' => '',
			)
		);
	}

	public static function on() {
		return (bool) self::settings()['on'];
	}

	public static function name() {
		$s = self::settings();
		if ( '' !== $s['name'] ) {
			return $s['name'];
		}
		return SNSCS_Brand::on() ? SNSCS_Brand::NAME : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	public static function short_name() {
		$s = self::settings();
		return '' !== $s['short_name'] ? $s['short_name'] : mb_substr( self::name(), 0, 12 );
	}

	public static function start_url() {
		return add_query_arg( 'snscs_app', 1, home_url( '/' ) );
	}

	public static function offline_url() {
		return add_query_arg( 'snscs_offline', 1, home_url( '/' ) );
	}

	/* --------------------------------------------------------------------
	 * マニフェスト・サービスワーカー・オフラインのページ・assetlinks.json
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<int,array{src:string,sizes:string,type:string,purpose?:string}>
	 */
	public static function icons() {
		$icons = array(
			array( 'src' => SNSCS_Push::icon_url(), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
			array( 'src' => SNSCS_Push::icon_url( 512 ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
		);
		if ( SNSCS_Brand::on() && ! has_site_icon() ) {
			$icons[] = array( 'src' => SNSCS_Brand::url( 'follofind-maskable-192.png' ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable' );
			$icons[] = array( 'src' => SNSCS_Brand::url( 'follofind-maskable-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' );
		}
		return $icons;
	}

	public static function manifest() {
		$icon      = array( array( 'src' => SNSCS_Push::icon_url(), 'sizes' => '192x192', 'type' => 'image/png' ) );
		$shortcuts = array( array( 'name' => '発信者を探す', 'short_name' => '探す', 'url' => add_query_arg( 'snscs_app', 1, snscs_search_url() ), 'icons' => $icon ) );
		if ( SNSCS_Hub::page_url( 'ranking' ) ) {
			$shortcuts[] = array( 'name' => 'ランキング', 'url' => add_query_arg( 'snscs_app', 1, SNSCS_Hub::page_url( 'ranking' ) ), 'icons' => $icon );
		}
		if ( SNSCS_Social::member_url() ) {
			$shortcuts[] = array( 'name' => 'お知らせ', 'url' => add_query_arg( array( 'tab' => 'inbox', 'snscs_app' => 1 ), SNSCS_Social::member_url() ), 'icons' => $icon );
		}
		$shortcuts[] = array( 'name' => 'マイページ（発信者）', 'short_name' => 'マイページ', 'url' => add_query_arg( 'snscs_app', 1, snscs_portal_url() ), 'icons' => $icon );
		$s        = self::settings();
		$manifest = array(
			'id'               => wp_make_link_relative( self::start_url() ),
			'name'             => self::name(),
			'short_name'       => self::short_name(),
			'description'      => SNSCS_Brand::on() ? SNSCS_Brand::tagline() . '。SNSで発信している人を、ジャンル・SNS・地域から探せます。' : wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
			'lang'             => 'ja',
			'dir'              => 'ltr',
			'start_url'        => self::start_url(),
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			'display_override' => array( 'standalone', 'minimal-ui' ),
			'background_color' => '#ffffff',
			'theme_color'      => SNSCS_Brand::on() ? SNSCS_Brand::BLUE : '#1e3a8a',
			'categories'       => array( 'social', 'lifestyle', 'entertainment' ),
			'icons'            => self::icons(),
			'shortcuts'        => array_slice( $shortcuts, 0, 4 ),
			'share_target'     => array(
				'action' => home_url( '/' ),
				'method' => 'GET',
				'params' => array( 'title' => 'snscs_share_title', 'text' => 'snscs_share_text', 'url' => 'snscs_share_url' ),
			),
			'launch_handler'   => array( 'client_mode' => array( 'navigate-existing', 'auto' ) ),
		);
		if ( '' !== $s['android_pkg'] ) {
			$manifest['related_applications']        = array( array( 'platform' => 'play', 'id' => $s['android_pkg'] ) );
			$manifest['prefer_related_applications'] = false;
		}
		return $manifest;
	}

	public static function serve() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['snscs_manifest'] ) ) {
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			header( 'Cache-Control: public, max-age=3600' );
			echo wp_json_encode( self::manifest(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			exit;
		}
		if ( isset( $_GET['snscs_sw'] ) ) {
			header( 'Content-Type: application/javascript; charset=utf-8' );
			header( 'Cache-Control: no-cache' );
			header( 'Service-Worker-Allowed: /' );
			$config = array(
				'version' => SNSCS_VERSION . '-' . substr( md5( (string) filemtime( SNSCS_DIR . 'assets/js/snscs-sw.js' ) ), 0, 6 ),
				'offline' => self::on() && self::settings()['offline'] ? self::offline_url() : '',
				'precache' => array_values(
					array_filter(
						array(
							self::on() && self::settings()['offline'] ? self::offline_url() : '',
							SNSCS_URL . 'assets/css/snscs.css?ver=' . SNSCS_VERSION,
							SNSCS_Push::icon_url(),
						)
					)
				),
				'home'    => wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ? wp_parse_url( home_url( '/' ), PHP_URL_PATH ) : '/',
			);
			echo 'var SNSCS_SW = ' . wp_json_encode( $config, JSON_UNESCAPED_SLASHES ) . ";\n";
			readfile( SNSCS_DIR . 'assets/js/snscs-sw.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			exit;
		}
		if ( isset( $_GET['snscs_offline'] ) ) {
			self::render_offline();
			exit;
		}
		// phpcs:enable
		// Google Play（Trusted Web Activity）用: /.well-known/assetlinks.json
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
		$home = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		if ( $home . '/.well-known/assetlinks.json' === $path ) {
			$json = self::assetlinks_json();
			if ( '' === $json ) {
				return;
			}
			header( 'Content-Type: application/json' );
			header( 'Access-Control-Allow-Origin: *' );
			echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON。
			exit;
		}
	}

	/**
	 * assetlinks.json の中身（パッケージ名と指紋が無ければ空）。
	 */
	public static function assetlinks_json() {
		$s = self::settings();
		if ( '' === $s['android_pkg'] || '' === $s['android_sha256'] ) {
			return '';
		}
		return (string) wp_json_encode(
			array(
				array(
					'relation' => array( 'delegate_permission/common.handle_all_urls' ),
					'target'   => array(
						'namespace'                => 'android_app',
						'package_name'             => $s['android_pkg'],
						'sha256_cert_fingerprints' => array_values( array_filter( array_map( 'trim', explode( ',', $s['android_sha256'] ) ) ) ),
					),
				),
			),
			JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * サイトの直下に .well-known/assetlinks.json のファイルを書き出す。
	 * パーマリンクが「基本」のときや、サーバーが .well-known をプラグインまで通さないときでも、ファイルなら必ず開ける。
	 *
	 * @return string 'ok' | 'fail' | 'removed' | ''
	 */
	public static function write_assetlinks() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$dir  = trailingslashit( get_home_path() ) . '.well-known';
		$file = $dir . '/assetlinks.json';
		$json = self::assetlinks_json();
		if ( '' === $json ) {
			// 自分で書いたファイルだけ消す。
			if ( get_option( 'snscs_assetlinks_written' ) && is_file( $file ) ) {
				wp_delete_file( $file );
				delete_option( 'snscs_assetlinks_written' );
				return 'removed';
			}
			return '';
		}
		if ( ! wp_mkdir_p( $dir ) || false === file_put_contents( $file, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return 'fail';
		}
		update_option( 'snscs_assetlinks_written', 1, false );
		return 'ok';
	}

	/**
	 * ログイン中のページには印を付け、サービスワーカーが端末に覚えないようにする（ほかの人に見えないように）。
	 */
	public static function private_header() {
		if ( is_user_logged_in() ) {
			header( 'X-SNSCS-Private: 1' );
		}
	}

	public static function head() {
		if ( ! self::on() ) {
			return;
		}
		echo '<link rel="manifest" href="' . esc_url( home_url( '/?snscs_manifest=1' ) ) . '">' . "\n";
		echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
		echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( self::short_name() ) . '">' . "\n";
		// ブランドを使うときは、色とホーム画面のアイコンを SNSCS_Brand が出す。
		if ( ! SNSCS_Brand::on() ) {
			echo '<meta name="theme-color" content="#1e3a8a">' . "\n";
			echo '<link rel="apple-touch-icon" href="' . esc_url( SNSCS_Push::icon_url() ) . '">' . "\n";
		}
	}

	public static function render_offline() {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		$name = self::name();
		?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?php echo esc_html( 'オフライン | ' . $name ); ?></title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f5f7fb;color:#111827;font-family:"Hiragino Sans","Noto Sans JP",system-ui,sans-serif;text-align:center}
.o{max-width:420px;padding:32px 20px}
.o img{width:72px;height:72px;border-radius:18px}
.o h1{margin:16px 0 8px;font-size:20px}
.o p{margin:0 0 16px;color:#4b5563;line-height:1.7}
.o button{min-height:44px;padding:0 24px;border:0;border-radius:999px;background:#1B27B0;color:#fff;font:inherit;font-weight:700}
.o ul{margin:20px 0 0;padding:0;list-style:none;text-align:left}
.o li a{display:block;padding:10px 14px;margin-bottom:8px;border-radius:12px;background:#fff;color:#1B27B0;text-decoration:none;border:1px solid #e5e7eb}
.o h2{margin:24px 0 8px;font-size:14px;color:#6b7280}
</style>
</head>
<body>
<main class="o">
	<img src="<?php echo esc_url( SNSCS_Push::icon_url() ); ?>" alt="">
	<h1>インターネットにつながっていません</h1>
	<p>電波の良いところで、もう一度お試しください。前に見たページは、つながっていなくても開けます。</p>
	<button type="button" onclick="location.reload()">もう一度読み込む</button>
	<h2 id="o-h" hidden>前に見たページ</h2>
	<ul id="o-list"></ul>
</main>
<script>
( function () {
	if ( ! window.caches ) { return; }
	caches.keys().then( function ( keys ) {
		var name = keys.filter( function ( k ) { return 0 === k.indexOf( 'snscs-pages-' ); } )[0];
		if ( ! name ) { return; }
		return caches.open( name ).then( function ( cache ) {
			return cache.keys().then( function ( reqs ) {
				return Promise.all( reqs.slice( -20 ).reverse().map( function ( req ) {
					return cache.match( req ).then( function ( res ) {
						return res.text().then( function ( html ) {
							var m = html.match( /<title[^>]*>([^<]*)<\/title>/i );
							var t = document.createElement( 'textarea' );
							t.innerHTML = m ? m[1] : req.url;
							return { url: req.url, title: t.value };
						} );
					} );
				} ) );
			} );
		} );
	} ).then( function ( items ) {
		if ( ! items || ! items.length ) { return; }
		var list = document.getElementById( 'o-list' );
		document.getElementById( 'o-h' ).hidden = false;
		items.forEach( function ( item ) {
			var li = document.createElement( 'li' );
			var a = document.createElement( 'a' );
			a.href = item.url;
			a.textContent = item.title;
			li.appendChild( a );
			list.appendChild( li );
		} );
	} ).catch( function () {} );
} )();
</script>
</body>
</html>
		<?php
	}

	/* --------------------------------------------------------------------
	 * ほかのアプリからの「共有」
	 * ------------------------------------------------------------------ */

	/**
	 * SNSのプロフィールのURL → 掲載している発信者。
	 */
	public static function creator_from_url( $url ) {
		global $wpdb;
		$parts = wp_parse_url( (string) $url );
		if ( empty( $parts['host'] ) ) {
			return 0;
		}
		$host = preg_replace( '/^(www|m|mobile)\./', '', strtolower( $parts['host'] ) );
		$map  = array(
			'instagram.com' => 'instagram',
			'x.com'         => 'x',
			'twitter.com'   => 'x',
			'tiktok.com'    => 'tiktok',
			'youtube.com'   => 'youtube',
			'threads.net'   => 'threads',
			'threads.com'   => 'threads',
		);
		if ( ! isset( $map[ $host ] ) ) {
			return 0;
		}
		$segments = array_values( array_filter( explode( '/', (string) ( $parts['path'] ?? '' ) ) ) );
		if ( ! $segments ) {
			return 0;
		}
		$handle = ltrim( rawurldecode( $segments[0] ), '@' );
		if ( 'youtube' === $map[ $host ] && in_array( $segments[0], array( 'c', 'user', 'channel' ), true ) && isset( $segments[1] ) ) {
			$handle = $segments[1];
		}
		if ( ! preg_match( '/^[A-Za-z0-9_.\-]{1,100}$/', $handle ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT creator_id FROM ' . SNSCS_Accounts::table() . ' WHERE platform = %s AND (handle = %s OR external_id = %s) LIMIT 1', $map[ $host ], $handle, $handle ) );
	}

	public static function handle_share() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['snscs_share_text'] ) && ! isset( $_GET['snscs_share_url'] ) && ! isset( $_GET['snscs_share_title'] ) ) {
			return;
		}
		$text = implode( ' ', array_filter( array_map( function ( $k ) {
			return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
		}, array( 'snscs_share_url', 'snscs_share_text', 'snscs_share_title' ) ) ) );
		// phpcs:enable
		preg_match_all( '#https?://[^\s]+#u', $text, $m );
		foreach ( $m[0] as $url ) {
			$creator = self::creator_from_url( $url );
			if ( $creator && 'publish' === get_post_status( $creator ) ) {
				wp_safe_redirect( get_permalink( $creator ) );
				exit;
			}
		}
		// 掲載していない人・ただの文は、検索する（URLは外す）。
		$q = mb_substr( trim( preg_replace( '#https?://[^\s]+#u', ' ', $text ) ), 0, 60 );
		wp_safe_redirect( '' !== $q ? add_query_arg( 'snscs_q', rawurlencode( $q ), snscs_search_url() ) : snscs_search_url() );
		exit;
	}

	/* --------------------------------------------------------------------
	 * 画面: サービスワーカーの登録・インストールの案内・アプリのタブ
	 * ------------------------------------------------------------------ */

	/**
	 * 下のタブ（アプリとして開いたときだけ JavaScript が出す）。
	 */
	public static function tabs() {
		$member = SNSCS_Social::member_url();
		$mine   = is_user_logged_in() && snscs_is_creator_user() ? snscs_portal_url() : ( $member ? $member : snscs_portal_url() );
		$tabs   = array(
			array( 'key' => 'home', 'label' => 'ホーム', 'url' => home_url( '/' ), 'icon' => 'home' ),
			array( 'key' => 'search', 'label' => '探す', 'url' => snscs_search_url(), 'icon' => 'search' ),
		);
		if ( SNSCS_Hub::page_url( 'stories' ) ) {
			$tabs[] = array( 'key' => 'stories', 'label' => '記事', 'url' => SNSCS_Hub::page_url( 'stories' ), 'icon' => 'stories' );
		} elseif ( SNSCS_Hub::page_url( 'ranking' ) ) {
			$tabs[] = array( 'key' => 'ranking', 'label' => 'ランキング', 'url' => SNSCS_Hub::page_url( 'ranking' ), 'icon' => 'ranking' );
		}
		if ( $member ) {
			$tabs[] = array( 'key' => 'inbox', 'label' => 'お知らせ', 'url' => add_query_arg( 'tab', 'inbox', $member ), 'icon' => 'bell' );
		}
		$tabs[] = array( 'key' => 'me', 'label' => is_user_logged_in() ? 'マイページ' : 'ログイン', 'url' => $mine, 'icon' => 'me' );
		return $tabs;
	}

	public static function enqueue() {
		if ( ! self::on() || is_admin() ) {
			return;
		}
		$s = self::settings();
		wp_enqueue_style( 'snscs' );
		wp_enqueue_script( 'snscs-app', SNSCS_URL . 'assets/js/snscs-app.js', array(), SNSCS_VERSION, true );
		wp_add_inline_script(
			'snscs-app',
			'window.snscsApp = ' . wp_json_encode(
				array(
					'sw'      => home_url( '/?snscs_sw=1' ),
					'scope'   => wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ? wp_parse_url( home_url( '/' ), PHP_URL_PATH ) : '/',
					'name'    => self::short_name(),
					'icon'    => SNSCS_Push::icon_url(),
					'prompt'  => (bool) $s['install_prompt'],
					'tabbar'  => (bool) $s['tabbar'],
					'tabs'    => self::tabs(),
					'home'    => home_url( '/' ),
					'user'    => is_user_logged_in() ? 1 : 0,
					'unread'  => is_user_logged_in() && SNSCS_Social::member_url() ? esc_url_raw( rest_url( 'snscs/v1/inbox/count' ) ) : '',
					'nonce'   => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			) . ';',
			'before'
		);
	}

	/**
	 * 会員ページ・マイページに出す「アプリとして使う」。
	 */
	public static function install_box() {
		if ( ! self::on() ) {
			return '';
		}
		return '<section class="snscs-panel snscs-appbox" data-snscs-appbox><h2 class="snscs-panel__title">📱 アプリとして使う</h2>'
			. '<p>ホーム画面に追加すると、アプリのように全画面で開けます。電波が弱いところでも、前に見たページを開けます。通知も受け取れます（iPhone は追加したアプリからだけ）。</p>'
			. '<p class="snscs-appbox__done" hidden>✓ アプリとして使っています。</p>'
			. '<p><button type="button" class="snscs-button snscs-button--primary" data-snscs-install>アプリとして追加する</button></p>'
			. '<p class="snscs-help" data-snscs-install-ios hidden>iPhone・iPad: 画面の下（または上）の共有ボタン <span aria-hidden="true">⬆️</span> を押し、「ホーム画面に追加」を選んでください。</p></section>';
	}

	/* --------------------------------------------------------------------
	 * 管理画面「スマホアプリ」
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, 'スマホアプリ', 'スマホアプリ', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_app_save' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$in  = wp_unslash( $_POST );
		$pkg = isset( $in['android_pkg'] ) ? trim( sanitize_text_field( $in['android_pkg'] ) ) : '';
		$sha = array();
		foreach ( explode( ',', isset( $in['android_sha256'] ) ? strtoupper( sanitize_text_field( $in['android_sha256'] ) ) : '' ) as $fp ) {
			$fp = trim( $fp );
			if ( preg_match( '/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $fp ) ) {
				$sha[] = $fp;
			}
		}
		update_option(
			self::OPTION,
			array(
				'on'             => empty( $in['on'] ) ? 0 : 1,
				'name'           => mb_substr( sanitize_text_field( $in['name'] ?? '' ), 0, 45 ),
				'short_name'     => mb_substr( sanitize_text_field( $in['short_name'] ?? '' ), 0, 12 ),
				'install_prompt' => empty( $in['install_prompt'] ) ? 0 : 1,
				'tabbar'         => empty( $in['tabbar'] ) ? 0 : 1,
				'offline'        => empty( $in['offline'] ) ? 0 : 1,
				'android_pkg'    => preg_match( '/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/', $pkg ) ? $pkg : '',
				'android_sha256' => implode( ',', $sha ),
			),
			false
		);
		$file = self::write_assetlinks();
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::ADMIN_PAGE . '&saved=1' . ( $file ? '&file=' . $file : '' ) ) );
		exit;
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = self::settings();
		$https = 0 === strpos( home_url( '/' ), 'https://' );
		$links = home_url( '/.well-known/assetlinks.json' );
		?>
		<div class="wrap">
			<h1>スマホアプリ</h1>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( ! empty( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>保存しました。</p></div>
			<?php endif; ?>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['file'] ) && 'ok' === $_GET['file'] ) : ?>
				<div class="notice notice-success is-dismissible"><p>サイトの直下に <code>.well-known/assetlinks.json</code> のファイルを作りました。下の「確かめる」のリンクを開いて、文字が表示されればOKです。</p></div>
			<?php elseif ( isset( $_GET['file'] ) && 'fail' === $_GET['file'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-warning"><p><code>.well-known/assetlinks.json</code> のファイルを作れませんでした（サーバーの書き込みの許可がありません）。下の「確かめる」のリンクが開ければそのままでOKです。開けない場合は、サーバーのファイルマネージャーで、サイトの直下に <code>.well-known</code> というフォルダを作り、その中に <code>assetlinks.json</code> というファイルを置いて、下の中身を貼り付けてください。</p><textarea readonly rows="4" style="width:100%;font-family:monospace"><?php echo esc_textarea( self::assetlinks_json() ); ?></textarea></div>
			<?php endif; ?>
			<p>このサイトは、スマホのホーム画面に追加して<strong>アプリとして使えます</strong>（PWA）。アプリストアを通さずに、サイトから直接インストールできます。</p>
			<?php if ( ! $https ) : ?>
				<div class="notice notice-error inline"><p>アプリとして使うには、サイトが <strong>https://</strong> で始まる必要があります（SSL）。サーバーの管理画面で「無料SSL」を有効にしてください。</p></div>
			<?php endif; ?>
			<div style="display: flex; gap: 24px; flex-wrap: wrap; align-items: flex-start">
				<div style="flex: 1 1 520px">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="snscs_app_save">
						<?php wp_nonce_field( 'snscs_app_save', 'snscs_nonce' ); ?>
						<table class="form-table" role="presentation">
							<tr><th scope="row">アプリとして使う</th><td><label><input type="checkbox" name="on" value="1" <?php checked( $s['on'] ); ?>> ホーム画面に追加できるようにする</label></td></tr>
							<tr><th scope="row"><label for="app-name">アプリの名前</label></th><td><input id="app-name" type="text" name="name" maxlength="45" value="<?php echo esc_attr( $s['name'] ); ?>" placeholder="<?php echo esc_attr( self::name() ); ?>" class="regular-text"></td></tr>
							<tr><th scope="row"><label for="app-short">アイコンの下の名前</label></th><td><input id="app-short" type="text" name="short_name" maxlength="12" value="<?php echo esc_attr( $s['short_name'] ); ?>" placeholder="<?php echo esc_attr( self::short_name() ); ?>"> <span class="description">12文字まで</span></td></tr>
							<tr><th scope="row">インストールの案内</th><td><label><input type="checkbox" name="install_prompt" value="1" <?php checked( $s['install_prompt'] ); ?>> 2回目に来た人に「アプリとして追加」を出す（閉じたら30日は出さない）</label></td></tr>
							<tr><th scope="row">下のタブ</th><td><label><input type="checkbox" name="tabbar" value="1" <?php checked( $s['tabbar'] ); ?>> アプリとして開いたとき、画面の下にタブ（ホーム・探す・記事・お知らせ・マイページ）と戻るボタンを出す</label></td></tr>
							<tr><th scope="row">オフライン</th><td><label><input type="checkbox" name="offline" value="1" <?php checked( $s['offline'] ); ?>> 電波が無いとき、前に見たページと「オフラインです」のページを出す（ログイン中のページは端末に残しません）</label></td></tr>
						</table>
						<h2>Google Play に出す（Android）</h2>
						<p>アプリストアに載せたいときは、<a href="https://www.pwabuilder.com/" target="_blank" rel="noopener">PWABuilder</a>（Microsoft の無料ツール）にサイトのURLを入れると、Google Play に出せるアプリの形（Trusted Web Activity）を作れます。そのときに表示される「パッケージ名」と「署名の指紋（SHA-256）」を入れてください。アプリを開いたときにブラウザのバーが出なくなります。</p>
						<table class="form-table" role="presentation">
							<tr><th scope="row"><label for="app-pkg">パッケージ名</label></th><td><input id="app-pkg" type="text" name="android_pkg" value="<?php echo esc_attr( $s['android_pkg'] ); ?>" placeholder="com.example.follofind" class="regular-text"></td></tr>
							<tr><th scope="row"><label for="app-sha">署名の指紋（SHA-256）</label></th><td><input id="app-sha" type="text" name="android_sha256" value="<?php echo esc_attr( $s['android_sha256'] ); ?>" placeholder="AB:CD:…（32組）" class="large-text code"><p class="description">複数あるときはカンマ区切り。保存すると、サイトの直下に確認用のファイル <code><?php echo esc_html( $links ); ?></code> を作ります。<?php if ( '' !== self::assetlinks_json() ) : ?> <a href="<?php echo esc_url( $links ); ?>" target="_blank" rel="noopener">確かめる</a><?php endif; ?></p></td></tr>
						</table>
						<?php submit_button( '保存' ); ?>
					</form>
					<h2>App Store に出す（iPhone）</h2>
					<p>iPhone は、Safari で「共有 →ホーム画面に追加」するだけでアプリとして使えます（通知も届きます。iOS 16.4以降）。App Store に載せたいときは、PWABuilder の「iOS」で Xcode のプロジェクトを作り、Apple Developer Program（有料・年ごと）に登録して申請します。サイトをそのまま包んだだけのアプリは審査で断られることがあるので、まずはホーム画面に追加する形をおすすめします。</p>
				</div>
				<div style="flex: 0 0 220px; text-align: center">
					<p><strong>スマホで試す</strong></p>
					<div style="background: #fff; padding: 10px; display: inline-block"><?php echo SNSCS_QR::svg( self::start_url(), 180 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 自分で作ったSVG。 ?></div>
					<p class="description">QRコードをスマホのカメラで読み取り、開いたページで「アプリとして追加」してください。</p>
					<img src="<?php echo esc_url( SNSCS_Push::icon_url() ); ?>" alt="" width="64" height="64" style="border-radius: 14px">
				</div>
			</div>
		</div>
		<?php
	}
}
