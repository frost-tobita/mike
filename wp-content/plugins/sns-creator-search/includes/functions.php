<?php
/**
 * 共通の小さな関数。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 対応SNSの一覧（キー => 表示名）。
 * 追加したい場合は `snscs_platforms` フィルターで増やせる。
 *
 * @return array<string,string>
 */
function snscs_platforms() {
	// この並び順のまま、検索画面のインデックス（タブ）に表示される。
	$platforms = array(
		'x'         => 'X',
		'youtube'   => 'YouTube',
		'instagram' => 'Instagram',
		'threads'   => 'Threads',
		'tiktok'    => 'TikTok',
		'substack'  => 'Substack',
	);

	return apply_filters( 'snscs_platforms', $platforms );
}

/**
 * SNSのキーを表示名に変換する。
 *
 * @param string $key SNSのキー。
 * @return string
 */
function snscs_platform_label( $key ) {
	$platforms = snscs_platforms();
	return isset( $platforms[ $key ] ) ? $platforms[ $key ] : $key;
}

/**
 * フォロワー数を日本語の読みやすい形にする（例: 12,300 → 1.2万）。
 *
 * @param int $number 数値。
 * @return string
 */
function snscs_format_count( $number ) {
	$number = (int) $number;

	if ( $number >= 100000000 ) {
		return snscs_trim_decimal( $number / 100000000 ) . '億';
	}
	if ( $number >= 10000 ) {
		return snscs_trim_decimal( $number / 10000 ) . '万';
	}

	return number_format_i18n( $number );
}

/**
 * 小数第1位までにし、末尾の「.0」を取る。
 *
 * @param float $value 値。
 * @return string
 */
function snscs_trim_decimal( $value ) {
	$value = floor( $value * 10 ) / 10;
	return rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' );
}

/**
 * ハンドル名からプロフィールURLを作る。
 *
 * @param string $platform SNSのキー。
 * @param string $handle   ハンドル名（@なし）。
 * @return string
 */
function snscs_profile_url( $platform, $handle ) {
	$patterns = array(
		'x'         => 'https://x.com/%s',
		'youtube'   => 'https://www.youtube.com/@%s',
		'instagram' => 'https://www.instagram.com/%s/',
		'threads'   => 'https://www.threads.net/@%s',
		'tiktok'    => 'https://www.tiktok.com/@%s',
		'substack'  => 'https://%s.substack.com/',
	);
	$patterns = apply_filters( 'snscs_profile_url_patterns', $patterns );

	if ( ! isset( $patterns[ $platform ] ) || '' === $handle ) {
		return '';
	}

	return sprintf( $patterns[ $platform ], rawurlencode( $handle ) );
}

/**
 * 発信者ポータル（登録・編集ページ）のURL。
 *
 * @return string
 */
function snscs_portal_url() {
	$page_id = (int) get_option( 'snscs_portal_page_id' );
	$url     = $page_id ? get_permalink( $page_id ) : '';
	return $url ? $url : home_url( '/' );
}

/**
 * ユーザーが発信者（一般の登録者）かどうか。
 *
 * @param WP_User|int|null $user ユーザー。省略時は現在のユーザー。
 * @return bool
 */
/**
 * 本登録のメンバーか（1.27）。登録は1つにまとめ、運営が承認して発信者ページが掲載されると本登録。
 * それまでは仮登録で、サイトを見ることと申請の確認・修正だけができる。運営者（投稿を編集できる人）は常に本登録扱い。
 */
function snscs_is_full_member( $user = null ) {
	$user = $user ? ( $user instanceof WP_User ? $user : get_userdata( $user ) ) : wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return false;
	}
	if ( user_can( $user, 'edit_posts' ) ) {
		return true;
	}
	$creator = SNSCS_Submissions::creator_id_for_user( $user->ID );
	return $creator && 'publish' === get_post_status( $creator );
}

function snscs_is_creator_user( $user = null ) {
	$user = $user ? ( $user instanceof WP_User ? $user : get_userdata( $user ) ) : wp_get_current_user();
	return $user && $user->exists() && in_array( SNSCS_Portal::ROLE, (array) $user->roles, true ) && ! user_can( $user, 'edit_posts' );
}

/**
 * 画面に出すハンドル名。Substack は「名前.substack.com」、それ以外は「@名前」。
 *
 * @param string $platform SNSのキー。
 * @param string $handle   ハンドル名（@なし）。
 * @return string
 */
function snscs_display_handle( $platform, $handle ) {
	return 'substack' === $platform ? $handle . '.substack.com' : '@' . $handle;
}

/**
 * 検索ページ（[sns_creator_search] を置いた固定ページ）のURL。
 *
 * @return string
 */
function snscs_search_url() {
	$page_id = (int) get_option( 'snscs_search_page_id' );
	$url     = $page_id ? get_permalink( $page_id ) : '';
	return $url ? $url : home_url( '/' );
}

/**
 * 発信者ページに載せられる「コンテンツ販売・ブログ」（SNSとは別。フォロワー数や検索のタブには使わない）。
 * 追加したい場合は `snscs_profile_stores` フィルターで増やせる。
 *
 * @return array<string,array{label:string,url:string,placeholder:string}> url の %s にアカウント名が入る。
 */
function snscs_profile_stores() {
	return apply_filters(
		'snscs_profile_stores',
		array(
			'note'  => array(
				'label'       => 'note',
				'url'         => 'https://note.com/%s',
				'placeholder' => 'note.com/ のあとの名前、またはURL',
			),
			'brain' => array(
				'label'       => 'Brain',
				'url'         => 'https://brain-market.com/u/%s',
				'placeholder' => 'brain-market.com/u/ のあとの名前、またはURL',
			),
		)
	);
}

/**
 * 「コンテンツ販売・ブログ」のURLやアカウント名から、アカウント名だけを取り出す。
 *
 * @param string $store キー（note、brain）。
 * @param string $value 入力値。
 * @return string 使えない形なら空文字。
 */
function snscs_store_handle( $store, $value ) {
	$value = trim( (string) $value );
	if ( preg_match( '#^https?://#i', $value ) ) {
		$path     = (string) wp_parse_url( $value, PHP_URL_PATH );
		$segments = array_values( array_filter( explode( '/', $path ) ) );
		// Brain は brain-market.com/u/名前 の形。
		if ( 'brain' === $store && isset( $segments[0], $segments[1] ) && 'u' === $segments[0] ) {
			$value = $segments[1];
		} else {
			$value = $segments ? $segments[0] : '';
		}
	}
	$value = ltrim( $value, '@' );
	return preg_match( '/^[A-Za-z0-9_.-]{1,64}$/', $value ) ? $value : '';
}

/**
 * ログインしていない人も使うフォーム（メールマガジン・お仕事の相談）の合言葉。
 * ページがキャッシュされても使えるよう、作った時刻と署名だけを入れる（24時間有効）。
 *
 * @return string
 */
function snscs_form_token() {
	$time = time();
	return $time . '.' . substr( hash_hmac( 'sha256', 'snscs-form|' . $time, wp_salt( 'nonce' ) ), 0, 20 );
}

/**
 * フォームの合言葉を確かめる。表示から $min_seconds 秒未満の送信（機械的な送信）も断る。
 *
 * @param string $token       snscs_form_token() の値。
 * @param int    $min_seconds 表示から送信までの最短秒数。
 * @return bool
 */
function snscs_check_form_token( $token, $min_seconds = 3 ) {
	if ( ! preg_match( '/^(\d{9,11})\.([a-f0-9]{20})$/', (string) $token, $m ) ) {
		return false;
	}
	$age = time() - (int) $m[1];
	return $age >= $min_seconds && $age <= DAY_IN_SECONDS && hash_equals( substr( hash_hmac( 'sha256', 'snscs-form|' . $m[1], wp_salt( 'nonce' ) ), 0, 20 ), $m[2] );
}

/**
 * 同じ人（日付入りのハッシュ）からの送信回数を数え、上限を超えたら true。
 *
 * @param string $action 何の送信か。
 * @param int    $max    期間内の上限。
 * @param int    $window 期間（秒）。
 * @return bool
 */
function snscs_rate_limited( $action, $max, $window = HOUR_IN_SECONDS ) {
	$key   = 'snscs_rl_' . md5( $action . '|' . SNSCS_Referrals::visitor_hash() );
	$count = (int) get_transient( $key );
	if ( $count >= $max ) {
		return true;
	}
	set_transient( $key, $count + 1, $window );
	return false;
}

/**
 * メールの送り主の情報（規約ページの作成で入力した運営者情報）。
 *
 * @return array{operator:string,email:string,address:string}
 */
function snscs_operator_info() {
	$info = wp_parse_args( (array) get_option( 'snscs_legal_info', array() ), array( 'operator' => '', 'address' => '', 'email' => '' ) );
	return array(
		'operator' => '' !== $info['operator'] ? $info['operator'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		'email'    => '' !== $info['email'] ? $info['email'] : get_option( 'admin_email' ),
		'address'  => $info['address'],
	);
}

/**
 * フォームの送り先。サイトの表側のページでは「/?snscs_fp=1」（サイトのトップのアドレス）に送る。
 *
 * /wp-admin/admin-post.php は、セキュリティのプラグイン（SiteGuard WP Plugin の「管理ページアクセス制限」など）や、
 * サーバーの設定（/wp-admin/ のBasic認証・WAF）で、ログインしていない人には開けないことが多い。
 * そうなるとメンバー登録やお問い合わせが送れなくなるため、表側のアドレスで受けて、同じ処理（admin_post_ のフック）に渡す。
 * 管理画面の中のフォームは、これまでどおり admin-post.php に送る。
 */
function snscs_post_url() {
	return is_admin() && ! wp_doing_ajax() ? admin_url( 'admin-post.php' ) : add_query_arg( 'snscs_fp', 1, home_url( '/' ) );
}

/**
 * 表側のアドレスに届いたフォームを、admin-post.php と同じように処理する（このプラグインのものだけ）。
 */
function snscs_front_post() {
	// phpcs:disable WordPress.Security.NonceVerification -- 確認は、それぞれの処理の中で行う。
	if ( empty( $_GET['snscs_fp'] ) || is_admin() ) {
		return;
	}
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	// phpcs:enable
	if ( 0 !== strpos( $action, 'snscs_' ) ) {
		return;
	}
	$hook = ( is_user_logged_in() ? 'admin_post_' : 'admin_post_nopriv_' ) . $action;
	if ( ! has_action( $hook ) ) {
		// ログインが要る処理なのにログインしていない（ログインの期限切れなど）。
		wp_safe_redirect( is_user_logged_in() ? home_url( '/' ) : wp_login_url( wp_get_referer() ? wp_get_referer() : home_url( '/' ) ) );
		exit;
	}
	nocache_headers();
	do_action( $hook );
	exit;
}
add_action( 'wp_loaded', 'snscs_front_post' );

/**
 * キャッシュされたページでも送れるように、ログインしていない人のフォームの合言葉を開いたときに新しくする。
 * （ページのキャッシュが古いと、合言葉の期限が切れていて「登録できない」ことになるため）
 */
function snscs_public_form_actions() {
	return array( 'snscs_member_register', 'snscs_member_login', 'snscs_register' );
}

function snscs_form_keys_route() {
	register_rest_route(
		'snscs/v1',
		'/form-keys',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function () {
				$nonces = array();
				if ( ! is_user_logged_in() ) {
					foreach ( snscs_public_form_actions() as $action ) {
						$nonces[ $action ] = wp_create_nonce( $action );
					}
				}
				$response = new WP_REST_Response( array( 'nonces' => $nonces, 'ft' => snscs_form_token() ) );
				$response->header( 'Cache-Control', 'no-store, max-age=0' );
				return $response;
			},
		)
	);
}
add_action( 'rest_api_init', 'snscs_form_keys_route' );

function snscs_form_keys_script() {
	if ( is_user_logged_in() || is_admin() ) {
		return;
	}
	?>
<script>
( function () {
	var forms = document.querySelectorAll( 'form input[name="snscs_ft"], form input[name="snscs_form"], form input[name="action"][value^="snscs_"]' );
	if ( ! forms.length || ! window.fetch ) { return; }
	fetch( <?php echo wp_json_encode( esc_url_raw( rest_url( 'snscs/v1/form-keys' ) ) ); ?>, { credentials: 'same-origin', cache: 'no-store' } ).then( function ( r ) { return r.json(); } ).then( function ( keys ) {
		document.querySelectorAll( 'form' ).forEach( function ( form ) {
			var action = form.querySelector( 'input[name="action"]' );
			var nonce = form.querySelector( 'input[name="snscs_nonce"]' );
			if ( action && nonce && keys.nonces && keys.nonces[ action.value ] ) { nonce.value = keys.nonces[ action.value ]; }
			form.querySelectorAll( 'input[name="snscs_ft"], input[name="snscs_form"]' ).forEach( function ( i ) { if ( keys.ft ) { i.value = keys.ft; } } );
		} );
	} ).catch( function () {} );
} )();
</script>
	<?php
}
add_action( 'wp_footer', 'snscs_form_keys_script', 99 );
