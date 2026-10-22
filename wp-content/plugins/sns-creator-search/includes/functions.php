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
