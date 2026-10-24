<?php
/**
 * ブランド（FolloFind のロゴ・色・アイコン）。
 *
 *   ロゴ   : ヘッダー（アイコン＋文字のロゴ＋「SNS特化型サーチエンジン」）、フッター（白の文字のロゴ）、
 *            英語版・FolloLinkのページ
 *   アイコン: ブラウザのタブ（SVG・PNG）とスマホのホーム画面（apple-touch-icon）。WordPress の「サイトアイコン」を
 *            設定していればそちらを使う
 *   色     : 青 #1B27B0（文字のロゴ）・#2020D2（アイコン）、緑 #45E39C（点）
 * 「集客・回遊」の設定で、ロゴを使うかと、ひとこと（タグライン）を変えられる。
 * SNSでシェアされたときの画像（assets/brand/follofind-og.jpg）は、テーマやSEOのプラグインの「OGP画像」に設定して使う。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Brand {

	const BLUE       = '#1B27B0';
	const BLUE_LIGHT = '#2020D2';
	const GREEN      = '#45E39C';
	const NAME       = 'FolloFind';

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'icons' ), 2 );
	}

	public static function on() {
		return (bool) SNSCS_Hub::setting( 'brand_on' );
	}

	public static function url( $file ) {
		return SNSCS_URL . 'assets/brand/' . $file . '?ver=' . SNSCS_VERSION;
	}

	public static function tagline() {
		return (string) SNSCS_Hub::setting( 'brand_tagline' );
	}

	/**
	 * タブ・ホーム画面のアイコンと、スマホのブラウザの上の色。
	 */
	public static function icons() {
		if ( ! self::on() ) {
			return;
		}
		echo self::icons_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 中でエスケープ済み。
	}

	public static function icons_html() {
		$html = '<meta name="theme-color" content="' . esc_attr( self::BLUE ) . '">' . "\n";
		if ( has_site_icon() ) {
			return $html;
		}
		return $html
			. '<link rel="icon" href="' . esc_url( self::url( 'follofind-icon.svg' ) ) . '" type="image/svg+xml">' . "\n"
			. '<link rel="icon" href="' . esc_url( self::url( 'follofind-icon-32.png' ) ) . '" sizes="32x32" type="image/png">' . "\n"
			. '<link rel="icon" href="' . esc_url( self::url( 'follofind-icon-192.png' ) ) . '" sizes="192x192" type="image/png">' . "\n"
			. '<link rel="apple-touch-icon" href="' . esc_url( self::url( 'apple-touch-icon.png' ) ) . '">' . "\n";
	}

	/**
	 * ヘッダーのロゴ（アイコン＋文字のロゴ＋ひとこと）。
	 */
	public static function header_logo( $tagline = true ) {
		$t = $tagline ? self::tagline() : '';
		return '<span class="snscs-brand"><img class="snscs-brand__icon" src="' . esc_url( self::url( 'follofind-icon.svg' ) ) . '" alt="" width="40" height="40">'
			. '<span class="snscs-brand__text"><img class="snscs-brand__word" src="' . esc_url( self::url( 'follofind-wordmark.png' ) ) . '" alt="' . esc_attr( self::NAME ) . '" width="140" height="28">'
			. ( '' !== $t ? '<span class="snscs-brand__tagline">' . esc_html( $t ) . '</span>' : '' ) . '</span></span>';
	}

	/**
	 * フッターのロゴ（白）。
	 */
	public static function footer_logo() {
		$t = self::tagline();
		return '<p class="snscs-brand snscs-brand--footer"><img class="snscs-brand__icon" src="' . esc_url( self::url( 'follofind-icon.svg' ) ) . '" alt="" width="36" height="36"><span class="snscs-brand__text"><img class="snscs-brand__word" src="' . esc_url( self::url( 'follofind-wordmark-white.png' ) ) . '" alt="' . esc_attr( self::NAME ) . '" width="130" height="26">' . ( '' !== $t ? '<span class="snscs-brand__tagline">' . esc_html( $t ) . '</span>' : '' ) . '</span></p>';
	}
}
