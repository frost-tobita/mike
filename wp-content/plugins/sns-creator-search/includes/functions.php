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
	$platforms = array(
		'x'         => 'X (Twitter)',
		'instagram' => 'Instagram',
		'tiktok'    => 'TikTok',
		'youtube'   => 'YouTube',
		'threads'   => 'Threads',
		'bluesky'   => 'Bluesky',
		'facebook'  => 'Facebook',
		'twitch'    => 'Twitch',
		'note'      => 'note',
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
