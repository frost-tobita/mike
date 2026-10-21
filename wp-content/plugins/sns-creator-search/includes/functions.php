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
