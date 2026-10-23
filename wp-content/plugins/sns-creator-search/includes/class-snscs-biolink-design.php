<?php
/**
 * マイリンクのデザイン（テーマ・ボタン・書体・写真の枠・並べ方・自分の色・テンプレート）。
 *
 * 発信者ごとの設定は、投稿メタ _snscs_link_style に1つにまとめて保存する（前の版の _snscs_link_theme・_snscs_link_shape も読む）。
 *   theme  … テーマ（36種類＋自分の色。シンプル・かわいい・クール・ナチュラル・プロ）
 *   shape  … ボタンの角（角丸・まる・四角）
 *   button … ボタンの見た目（塗り・線・ふんわり影・くっきり影・ガラス）
 *   font   … 書体（標準・丸ゴシック・明朝・ポップ）。標準以外は Google Fonts から読み込む
 *   ring   … 写真の枠（なし・グラデーション・線・光）
 *   layout … 並べ方（すべてボタン／SNSはアイコンで上に並べる）
 *   bg, btn … 自分の色（テーマ「カスタム」のとき）
 * 背景の模様は CSS だけで描く（画像を読み込まない）ので、表示が速い。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Biolink_Design {

	const META = '_snscs_link_style';

	/**
	 * テーマ。pattern は背景に重ねる模様、cat は選ぶ画面の分類。
	 *
	 * @return array<string,array>
	 */
	public static function themes() {
		$t = array(
			// シンプル
			'simple'   => array( 'cat' => 'simple', 'label' => 'シンプル', 'bg' => '#f5f7fb', 'text' => '#111827', 'muted' => '#4b5563', 'button' => '#ffffff', 'button_text' => '#111827', 'border' => '#e5e7eb' ),
			'mono'     => array( 'cat' => 'simple', 'label' => 'モノクロ', 'bg' => '#ffffff', 'text' => '#000000', 'muted' => '#404040', 'button' => '#000000', 'button_text' => '#ffffff', 'border' => '#000000' ),
			'brand'    => array( 'cat' => 'simple', 'label' => 'FolloFind', 'bg' => 'linear-gradient(160deg,#1B27B0,#2020D2 60%,#3b2fe0)', 'text' => '#ffffff', 'muted' => '#dfe3ff', 'button' => '#ffffff', 'button_text' => '#1B27B0', 'border' => '#ffffff', 'accent' => '#45E39C' ),
			'grid'     => array( 'cat' => 'simple', 'label' => 'グリッド', 'bg' => '#ffffff', 'pattern' => 'linear-gradient(#eef0f4 1px,transparent 1px),linear-gradient(90deg,#eef0f4 1px,transparent 1px)', 'size' => '24px 24px', 'text' => '#111827', 'muted' => '#4b5563', 'button' => '#ffffff', 'button_text' => '#111827', 'border' => '#111827' ),
			'notebook' => array( 'cat' => 'simple', 'label' => 'ノート', 'bg' => '#fffdf7', 'pattern' => 'repeating-linear-gradient(180deg,transparent 0 31px,#dbe4f3 31px 32px)', 'size' => 'auto', 'text' => '#1e3a5f', 'muted' => '#46607f', 'button' => '#ffffff', 'button_text' => '#1e3a5f', 'border' => '#9fb3d1', 'font' => 'maru' ),
			'beige'    => array( 'cat' => 'simple', 'label' => 'ベージュ', 'bg' => '#f4efe6', 'text' => '#3a3128', 'muted' => '#6b5f52', 'button' => '#fffdf9', 'button_text' => '#3a3128', 'border' => '#e3d9c8' ),
			'ice'      => array( 'cat' => 'simple', 'label' => 'アイス', 'bg' => 'linear-gradient(180deg,#f0f7ff,#e0efff)', 'text' => '#0c2a4d', 'muted' => '#3d5a7c', 'button' => '#ffffff', 'button_text' => '#0c4a8a', 'border' => '#bcd8f5' ),
			// かわいい
			'sakura'   => array( 'cat' => 'cute', 'label' => 'さくら', 'bg' => 'linear-gradient(180deg,#fff1f5,#ffe4ec)', 'text' => '#4a1d2f', 'muted' => '#7a4a5d', 'button' => '#ffffff', 'button_text' => '#9d174d', 'border' => '#fbcfe8' ),
			'pastel'   => array( 'cat' => 'cute', 'label' => 'パステル', 'bg' => 'linear-gradient(135deg,#d9f5ec,#fde7d9 50%,#e9e3ff)', 'text' => '#33334d', 'muted' => '#5c5c7a', 'button' => '#ffffff', 'button_text' => '#5b4bd1', 'border' => '#ffffff', 'font' => 'maru' ),
			'dots'     => array( 'cat' => 'cute', 'label' => 'ドット', 'bg' => '#fff6ef', 'pattern' => 'radial-gradient(#ffc7b0 2.5px,transparent 3px)', 'size' => '22px 22px', 'text' => '#5a2a14', 'muted' => '#86553d', 'button' => '#ff7a59', 'button_text' => '#ffffff', 'border' => '#ff7a59', 'font' => 'maru' ),
			'lavender' => array( 'cat' => 'cute', 'label' => 'ラベンダー', 'bg' => 'linear-gradient(180deg,#f5f3ff,#ede9fe)', 'text' => '#2e1065', 'muted' => '#5b4a86', 'button' => '#7c3aed', 'button_text' => '#ffffff', 'border' => '#7c3aed' ),
			'stripe'   => array( 'cat' => 'cute', 'label' => 'ストライプ', 'bg' => '#fff', 'pattern' => 'repeating-linear-gradient(135deg,#fdf2f8 0 14px,#eff6ff 14px 28px)', 'size' => 'auto', 'text' => '#3b2450', 'muted' => '#6b5480', 'button' => '#ffffff', 'button_text' => '#be185d', 'border' => '#f9a8d4' ),
			'citrus'   => array( 'cat' => 'cute', 'label' => 'シトラス', 'bg' => 'linear-gradient(180deg,#fefce8,#fef08a)', 'text' => '#422006', 'muted' => '#6b4f1d', 'button' => '#ffffff', 'button_text' => '#854d0e', 'border' => '#ca8a04' ),
			'candy'    => array( 'cat' => 'cute', 'label' => 'キャンディ', 'bg' => 'linear-gradient(135deg,#ffd6e8,#d6fff1)', 'pattern' => 'radial-gradient(#ffffff 3px,transparent 3.5px)', 'size' => '28px 28px', 'text' => '#4b1d3f', 'muted' => '#77496b', 'button' => '#ff5fa2', 'button_text' => '#ffffff', 'border' => '#ff5fa2', 'font' => 'pop' ),
			// クール
			'dark'     => array( 'cat' => 'cool', 'label' => 'ダーク', 'bg' => '#0f172a', 'text' => '#f8fafc', 'muted' => '#cbd5e1', 'button' => '#1e293b', 'button_text' => '#f8fafc', 'border' => '#334155' ),
			'midnight' => array( 'cat' => 'cool', 'label' => 'ミッドナイト', 'bg' => 'linear-gradient(180deg,#0b1026,#1b1f4b)', 'pattern' => 'radial-gradient(1.5px 1.5px at 20px 30px,#fff,transparent),radial-gradient(1px 1px at 80px 70px,#c7d2fe,transparent),radial-gradient(1.5px 1.5px at 140px 20px,#fff,transparent),radial-gradient(1px 1px at 170px 120px,#e0e7ff,transparent)', 'size' => '200px 160px', 'text' => '#f1f5ff', 'muted' => '#b6c0ea', 'button' => 'rgba(255,255,255,.08)', 'button_text' => '#ffffff', 'border' => 'rgba(199,210,254,.45)', 'glass' => true ),
			'neon'     => array( 'cat' => 'cool', 'label' => 'ネオン', 'bg' => '#0a0a17', 'text' => '#ffffff', 'muted' => '#c4c4e0', 'button' => 'transparent', 'button_text' => '#ffffff', 'border' => '#ff2bd6', 'glow' => '#ff2bd6' ),
			'aurora'   => array( 'cat' => 'cool', 'label' => 'オーロラ', 'bg' => 'linear-gradient(-45deg,#0f2027,#2c5364,#3a1c71,#0f9b8e)', 'size' => '400% 400%', 'anim' => true, 'text' => '#ffffff', 'muted' => '#d9f3ef', 'button' => 'rgba(255,255,255,.14)', 'button_text' => '#ffffff', 'border' => 'rgba(255,255,255,.45)', 'glass' => true ),
			'ocean'    => array( 'cat' => 'cool', 'label' => 'オーシャン', 'bg' => 'linear-gradient(180deg,#1e3a8a,#2563eb)', 'text' => '#ffffff', 'muted' => '#dbeafe', 'button' => 'rgba(255,255,255,.14)', 'button_text' => '#ffffff', 'border' => 'rgba(255,255,255,.5)' ),
			'glass'    => array( 'cat' => 'cool', 'label' => 'ガラス', 'bg' => 'linear-gradient(135deg,#ff6a88,#ff99ac 30%,#8e7cff 70%,#4facfe)', 'text' => '#ffffff', 'muted' => '#fff1f5', 'button' => 'rgba(255,255,255,.2)', 'button_text' => '#ffffff', 'border' => 'rgba(255,255,255,.55)', 'glass' => true ),
			'sunset'   => array( 'cat' => 'cool', 'label' => 'サンセット', 'bg' => 'linear-gradient(160deg,#f97316,#db2777)', 'text' => '#ffffff', 'muted' => '#ffedd5', 'button' => '#ffffff', 'button_text' => '#9a3412', 'border' => '#ffffff' ),
			// ナチュラル
			'forest'   => array( 'cat' => 'natural', 'label' => 'フォレスト', 'bg' => 'linear-gradient(180deg,#ecfdf5,#d1fae5)', 'text' => '#064e3b', 'muted' => '#336b57', 'button' => '#065f46', 'button_text' => '#ffffff', 'border' => '#065f46' ),
			'matcha'   => array( 'cat' => 'natural', 'label' => '抹茶', 'bg' => 'linear-gradient(180deg,#f3f6e8,#dfe8c8)', 'text' => '#2f3b16', 'muted' => '#5a6a37', 'button' => '#5f7a2b', 'button_text' => '#ffffff', 'border' => '#5f7a2b', 'font' => 'mincho' ),
			'washi'    => array( 'cat' => 'natural', 'label' => '和紙', 'bg' => '#f6f0e1', 'pattern' => 'radial-gradient(circle at 20% 30%,rgba(160,130,80,.07) 0 2px,transparent 3px),radial-gradient(circle at 70% 60%,rgba(160,130,80,.06) 0 1.5px,transparent 2.5px)', 'size' => '38px 38px', 'text' => '#4a3520', 'muted' => '#76604a', 'button' => '#fffaf0', 'button_text' => '#5a3e1b', 'border' => '#cdb88f', 'font' => 'mincho' ),
			'kraft'    => array( 'cat' => 'natural', 'label' => 'クラフト', 'bg' => 'linear-gradient(180deg,#d9b88f,#c9a173)', 'text' => '#3b2716', 'muted' => '#5c4127', 'button' => '#fdf6ec', 'button_text' => '#3b2716', 'border' => '#3b2716' ),
			'coral'    => array( 'cat' => 'natural', 'label' => 'コーラル', 'bg' => 'linear-gradient(180deg,#fff4ef,#ffd9c9)', 'text' => '#5b2416', 'muted' => '#8a4a38', 'button' => '#ff6f5b', 'button_text' => '#ffffff', 'border' => '#ff6f5b' ),
			'sky'      => array( 'cat' => 'natural', 'label' => '青空', 'bg' => 'linear-gradient(180deg,#bfe3ff,#eaf6ff 70%,#ffffff)', 'text' => '#0f3557', 'muted' => '#3c607f', 'button' => '#ffffff', 'button_text' => '#0f3557', 'border' => '#ffffff' ),
			'autumn'   => array( 'cat' => 'natural', 'label' => '紅葉', 'bg' => 'linear-gradient(180deg,#fff3e2,#fbd3a8)', 'text' => '#4a1f0b', 'muted' => '#7a4426', 'button' => '#b4441b', 'button_text' => '#ffffff', 'border' => '#b4441b', 'font' => 'mincho' ),
			// プロ（お仕事の窓口にも使える落ち着いたデザイン）
			'navy'      => array( 'cat' => 'pro', 'label' => 'ネイビー', 'bg' => '#0f2344', 'text' => '#ffffff', 'muted' => '#c3cfe4', 'button' => '#ffffff', 'button_text' => '#0f2344', 'border' => '#ffffff' ),
			'corporate' => array( 'cat' => 'pro', 'label' => 'コーポレート', 'bg' => 'linear-gradient(180deg,#ffffff 0 180px,#f3f6fa 180px)', 'text' => '#0b1f3a', 'muted' => '#4a5b73', 'button' => '#0b5cd5', 'button_text' => '#ffffff', 'border' => '#0b5cd5' ),
			'charcoal'  => array( 'cat' => 'pro', 'label' => 'チャコール', 'bg' => '#26282c', 'text' => '#f3f4f6', 'muted' => '#b8bcc4', 'button' => '#34373d', 'button_text' => '#f3f4f6', 'border' => '#4a4e56' ),
			'gold'      => array( 'cat' => 'pro', 'label' => 'ブラック×ゴールド', 'bg' => 'linear-gradient(180deg,#0b0b0b,#1a1712)', 'text' => '#f5f1e6', 'muted' => '#cfc4a8', 'button' => 'transparent', 'button_text' => '#e8c66a', 'border' => '#c9a24a', 'accent' => '#c9a24a', 'font' => 'mincho' ),
			'slate'     => array( 'cat' => 'pro', 'label' => 'スレート', 'bg' => 'linear-gradient(180deg,#e9edf2,#d5dce5)', 'text' => '#1f2933', 'muted' => '#52606d', 'button' => '#1f2933', 'button_text' => '#ffffff', 'border' => '#1f2933' ),
			'bordeaux'  => array( 'cat' => 'pro', 'label' => 'ボルドー', 'bg' => '#4a0e1c', 'text' => '#fff5f6', 'muted' => '#e6c3ca', 'button' => '#fff5f6', 'button_text' => '#4a0e1c', 'border' => '#fff5f6', 'font' => 'mincho' ),
			'editorial' => array( 'cat' => 'pro', 'label' => 'エディトリアル', 'bg' => '#faf8f3', 'text' => '#111111', 'muted' => '#555555', 'button' => '#faf8f3', 'button_text' => '#111111', 'border' => '#111111', 'font' => 'mincho' ),
			'blueprint' => array( 'cat' => 'pro', 'label' => 'ブループリント', 'bg' => '#123a6b', 'pattern' => 'linear-gradient(rgba(255,255,255,.08) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.08) 1px,transparent 1px)', 'size' => '20px 20px', 'text' => '#ffffff', 'muted' => '#c9dbf2', 'button' => 'rgba(255,255,255,.1)', 'button_text' => '#ffffff', 'border' => 'rgba(255,255,255,.6)' ),
		);
		$t['custom'] = array( 'cat' => 'custom', 'label' => '自分の色', 'bg' => '#ffffff', 'text' => '#111827', 'muted' => '#4b5563', 'button' => '#111827', 'button_text' => '#ffffff', 'border' => '#111827' );
		return apply_filters( 'snscs_link_themes', $t );
	}

	public static function categories() {
		return array( 'simple' => 'シンプル', 'cute' => 'かわいい', 'cool' => 'クール', 'natural' => 'ナチュラル', 'pro' => 'プロ', 'custom' => '自分の色' );
	}

	public static function shapes() {
		return array( 'round' => array( '角丸', '14px' ), 'pill' => array( 'まる', '999px' ), 'square' => array( '四角', '4px' ) );
	}

	public static function buttons() {
		return array( 'fill' => '塗り', 'outline' => '線だけ', 'soft' => 'ふんわり影', 'hard' => 'くっきり影', 'glass' => 'ガラス' );
	}

	/**
	 * 書体 => [表示名, CSSの font-family, Google Fonts の family（無ければ空）]
	 */
	public static function fonts() {
		return array(
			'gothic' => array( '標準（ゴシック）', '"Hiragino Sans","Noto Sans JP","Yu Gothic",Meiryo,system-ui,sans-serif', '' ),
			'maru'   => array( '丸ゴシック', '"Zen Maru Gothic","Hiragino Maru Gothic ProN","Hiragino Sans",sans-serif', 'Zen+Maru+Gothic:wght@500;700' ),
			'mincho' => array( '明朝', '"Shippori Mincho","Hiragino Mincho ProN","Yu Mincho",serif', 'Shippori+Mincho:wght@500;700' ),
			'pop'    => array( 'ポップ', '"M PLUS Rounded 1c","Hiragino Maru Gothic ProN",sans-serif', 'M+PLUS+Rounded+1c:wght@500;800' ),
		);
	}

	public static function rings() {
		return array( 'none' => 'なし', 'gradient' => 'グラデーション', 'line' => '線', 'glow' => '光' );
	}

	public static function layouts() {
		return array( 'buttons' => 'すべてボタンで並べる', 'icons' => 'SNSはアイコンで上に並べる' );
	}

	/**
	 * ワンクリックで選べるテンプレート。
	 */
	public static function templates() {
		return array(
			'creator'  => array( 'label' => 'クリエイター', 'theme' => 'brand', 'shape' => 'pill', 'button' => 'soft', 'font' => 'gothic', 'ring' => 'gradient', 'layout' => 'icons' ),
			'business' => array( 'label' => 'ビジネス', 'theme' => 'mono', 'shape' => 'square', 'button' => 'outline', 'font' => 'gothic', 'ring' => 'line', 'layout' => 'buttons' ),
			'cute'     => array( 'label' => 'かわいい', 'theme' => 'pastel', 'shape' => 'pill', 'button' => 'soft', 'font' => 'maru', 'ring' => 'gradient', 'layout' => 'icons' ),
			'pop'      => array( 'label' => 'ポップ', 'theme' => 'dots', 'shape' => 'round', 'button' => 'hard', 'font' => 'pop', 'ring' => 'line', 'layout' => 'icons' ),
			'cool'     => array( 'label' => 'クール', 'theme' => 'neon', 'shape' => 'pill', 'button' => 'outline', 'font' => 'gothic', 'ring' => 'glow', 'layout' => 'icons' ),
			'glass'    => array( 'label' => 'ガラス', 'theme' => 'glass', 'shape' => 'round', 'button' => 'glass', 'font' => 'gothic', 'ring' => 'line', 'layout' => 'icons' ),
			'japanese' => array( 'label' => '和風', 'theme' => 'washi', 'shape' => 'square', 'button' => 'outline', 'font' => 'mincho', 'ring' => 'line', 'layout' => 'buttons' ),
			'pro'      => array( 'label' => 'プロ', 'theme' => 'navy', 'shape' => 'round', 'button' => 'fill', 'font' => 'gothic', 'ring' => 'line', 'layout' => 'icons' ),
			'luxury'   => array( 'label' => '高級感', 'theme' => 'gold', 'shape' => 'square', 'button' => 'outline', 'font' => 'mincho', 'ring' => 'line', 'layout' => 'buttons' ),
			'night'    => array( 'label' => '夜空', 'theme' => 'aurora', 'shape' => 'pill', 'button' => 'glass', 'font' => 'maru', 'ring' => 'glow', 'layout' => 'icons' ),
		);
	}

	public static function defaults() {
		return array( 'theme' => 'simple', 'shape' => 'round', 'button' => 'fill', 'font' => 'gothic', 'ring' => 'none', 'layout' => 'buttons', 'bg' => '#ffffff', 'btn' => '#111827' );
	}

	/**
	 * 値を正しい選択肢にそろえる。
	 */
	public static function normalize( array $in ) {
		$d   = self::defaults();
		$out = array();
		foreach ( array( 'theme' => self::themes(), 'shape' => self::shapes(), 'button' => self::buttons(), 'font' => self::fonts(), 'ring' => self::rings(), 'layout' => self::layouts() ) as $key => $choices ) {
			$v           = isset( $in[ $key ] ) ? sanitize_key( (string) $in[ $key ] ) : '';
			$out[ $key ] = array_key_exists( $v, $choices ) ? $v : $d[ $key ];
		}
		foreach ( array( 'bg', 'btn' ) as $key ) {
			$v           = isset( $in[ $key ] ) ? sanitize_hex_color( (string) $in[ $key ] ) : '';
			$out[ $key ] = $v ? $v : $d[ $key ];
		}
		return $out;
	}

	/**
	 * 発信者の設定（保存したもの。前の版の設定も読む）。
	 */
	public static function style_for( $creator_id ) {
		$saved = get_post_meta( $creator_id, self::META, true );
		if ( ! is_array( $saved ) ) {
			$saved = array(
				'theme' => (string) get_post_meta( $creator_id, SNSCS_Biolink::META_THEME, true ),
				'shape' => (string) get_post_meta( $creator_id, SNSCS_Biolink::META_SHAPE, true ),
			);
		}
		return self::normalize( $saved );
	}

	public static function save( $creator_id, array $in ) {
		$style = self::normalize( $in );
		update_post_meta( $creator_id, self::META, $style );
		// 前の版の読み出し（テーマの名前だけを見るところ）とそろえる。
		update_post_meta( $creator_id, SNSCS_Biolink::META_THEME, $style['theme'] );
		update_post_meta( $creator_id, SNSCS_Biolink::META_SHAPE, $style['shape'] );
		return $style;
	}

	/* --------------------------------------------------------------------
	 * 色
	 * ------------------------------------------------------------------ */

	private static function rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	/**
	 * 明るい色か（文字を黒にするか白にするか）。
	 */
	public static function is_light( $hex ) {
		list( $r, $g, $b ) = self::rgb( $hex );
		return ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) > 160;
	}

	/**
	 * 使うテーマの色（「自分の色」なら、選んだ色から文字の色を決める）。
	 */
	public static function palette( array $style ) {
		$themes = self::themes();
		$t      = $themes[ $style['theme'] ] ?? $themes['simple'];
		if ( 'custom' === $style['theme'] ) {
			$light_bg  = self::is_light( $style['bg'] );
			$light_btn = self::is_light( $style['btn'] );
			$t         = array_merge(
				$t,
				array(
					'bg'          => $style['bg'],
					'text'        => $light_bg ? '#111827' : '#ffffff',
					'muted'       => $light_bg ? '#4b5563' : 'rgba(255,255,255,.8)',
					'button'      => $style['btn'],
					'button_text' => $light_btn ? '#111827' : '#ffffff',
					'border'      => $style['btn'],
				)
			);
		}
		return $t;
	}

	/**
	 * 背景の最初の色（画像を作るときなどに使う）。
	 *
	 * @return string[] 1〜2色
	 */
	public static function bg_colors( array $palette ) {
		preg_match_all( '/#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b/', (string) $palette['bg'], $m );
		$colors = $m[0] ? $m[0] : array( '#ffffff' );
		return array( $colors[0], end( $colors ) );
	}

	/* --------------------------------------------------------------------
	 * ページのCSS
	 * ------------------------------------------------------------------ */

	public static function font_link( array $style ) {
		$f = self::fonts()[ $style['font'] ];
		if ( '' === $f[2] ) {
			return '';
		}
		return '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="' . esc_url( 'https://fonts.googleapis.com/css2?family=' . $f[2] . '&display=swap' ) . '">';
	}

	/**
	 * マイリンクのページのCSS（中の値はすべて決まった選択肢か、確かめた色）。
	 */
	public static function css( array $style ) {
		$t      = self::palette( $style );
		$radius = self::shapes()[ $style['shape'] ][1];
		$font   = self::fonts()[ $style['font'] ][1];
		$bg     = isset( $t['pattern'] ) ? $t['pattern'] . ',' . $t['bg'] : $t['bg'];
		$size   = isset( $t['size'] ) ? $t['size'] : 'auto';
		$glow   = $t['glow'] ?? '';
		$accent = $t['accent'] ?? '#ef4444';

		// ボタンの見た目。
		$btn = 'background:' . $t['button'] . ';color:' . $t['button_text'] . ';border:2px solid ' . $t['border'] . ';';
		switch ( $style['button'] ) {
			case 'outline':
				$btn = 'background:transparent;color:' . $t['text'] . ';border:2px solid ' . ( 'transparent' === $t['border'] ? $t['text'] : $t['border'] ) . ';';
				break;
			case 'soft':
				$btn .= 'border-color:transparent;box-shadow:0 6px 18px rgba(15,23,42,.14),0 1px 3px rgba(15,23,42,.08);';
				break;
			case 'hard':
				$btn .= 'border-color:' . $t['text'] . ';box-shadow:4px 4px 0 ' . $t['text'] . ';';
				break;
			case 'glass':
				$btn = 'background:rgba(255,255,255,.18);color:' . $t['text'] . ';border:1px solid rgba(255,255,255,.5);-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);box-shadow:0 8px 24px rgba(0,0,0,.12);';
				break;
		}
		if ( '' !== $glow && 'outline' !== $style['button'] ) {
			$btn .= 'box-shadow:0 0 12px ' . $glow . ',inset 0 0 8px rgba(255,43,214,.25);';
		} elseif ( '' !== $glow ) {
			$btn .= 'border-color:' . $glow . ';box-shadow:0 0 12px ' . $glow . ';';
		}
		$hover = 'hard' === $style['button'] ? 'transform:translate(2px,2px);box-shadow:2px 2px 0 ' . $t['text'] . ';' : 'transform:translateY(-2px);';

		// 写真の枠。
		$ring = 'border:3px solid ' . $t['border'] . ';';
		switch ( $style['ring'] ) {
			case 'gradient':
				$ring = 'border:3px solid transparent;background:linear-gradient(' . ( self::is_light( self::bg_colors( $t )[0] ) ? '#fff,#fff' : '#111,#111' ) . ') padding-box,conic-gradient(from 210deg,#feda75,#fa7e1e,#d62976,#962fbf,#4f5bd5,#feda75) border-box;padding:3px;';
				break;
			case 'line':
				$ring = 'border:3px solid ' . $t['text'] . ';';
				break;
			case 'glow':
				$ring = 'border:3px solid ' . ( '' !== $glow ? $glow : $t['border'] ) . ';box-shadow:0 0 0 6px rgba(255,255,255,.12),0 0 28px ' . ( '' !== $glow ? $glow : 'rgba(255,255,255,.55)' ) . ';';
				break;
		}

		$css  = '*{box-sizing:border-box}';
		$css .= 'html{min-height:100%;background:' . $bg . ';background-size:' . $size . ';background-attachment:fixed}';
		if ( ! empty( $t['anim'] ) ) {
			$css .= 'html{animation:bio-bg 18s ease infinite}@keyframes bio-bg{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}@media (prefers-reduced-motion:reduce){html{animation:none}}';
		}
		$css .= 'body{margin:0;color:' . $t['text'] . ';font-family:' . $font . ';line-height:1.6;-webkit-font-smoothing:antialiased}';
		$css .= '.bio{max-width:560px;margin:0 auto;padding:44px 18px 32px;text-align:center}';
		$css .= '.bio__photo,.bio__initial{width:108px;height:108px;border-radius:50%;object-fit:cover;' . $ring . '}';
		$css .= '.bio__initial{display:inline-flex;align-items:center;justify-content:center;background:' . $t['button'] . ';color:' . $t['button_text'] . ';font-size:44px;font-weight:700}';
		$css .= '.bio__name{margin:14px 0 4px;font-size:1.45em;letter-spacing:.01em}';
		$css .= '.bio__genres{margin:0 0 8px;color:' . $t['muted'] . ';font-size:.85em}';
		$css .= '.bio__badges{display:flex;flex-wrap:wrap;justify-content:center;gap:6px;margin:0 0 12px;padding:0;list-style:none}';
		$css .= '.bio__badges li{padding:2px 10px;border:1px solid ' . $t['border'] . ';border-radius:999px;font-size:.78em;font-weight:700}';
		$css .= '.bio__text{margin:0 auto 20px;color:' . $t['muted'] . ';font-size:.92em;text-align:left;max-width:30em;white-space:pre-line}';
		$css .= '.bio__icons{display:flex;flex-wrap:wrap;justify-content:center;gap:12px;margin:0 0 20px;padding:0;list-style:none}';
		$css .= '.bio__icon{display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:50%;color:' . $t['text'] . ';text-decoration:none;transition:transform .15s;' . ( 'glass' === $style['button'] || ! empty( $t['glass'] ) ? 'background:rgba(255,255,255,.16);' : 'background:transparent;' ) . '}';
		$css .= '.bio__icon svg{width:26px;height:26px}.bio__icon:hover,.bio__icon:focus-visible{transform:translateY(-2px) scale(1.06)}';
		$css .= '.bio__list{display:grid;gap:14px;margin:0 0 24px;padding:0;list-style:none}';
		$css .= '.bio__btn{position:relative;display:flex;align-items:center;gap:12px;min-height:60px;padding:10px 18px;border-radius:' . $radius . ';' . $btn . 'text-decoration:none;font-weight:700;transition:transform .12s,box-shadow .12s}';
		$css .= '.bio__btn:hover,.bio__btn:focus-visible{' . $hover . '}';
		$css .= '.bio__btn__ico{display:inline-flex;flex:0 0 28px;align-items:center;justify-content:center;width:28px;height:28px}.bio__btn__ico svg{width:24px;height:24px}';
		$css .= '.bio__btn__txt{display:flex;flex:1 1 auto;flex-direction:column;align-items:center;min-width:0;padding-right:28px}';
		$css .= '.bio__btn small{font-weight:400;font-size:.8em;opacity:.8;word-break:break-all}';
		$css .= '.bio__btn--pinned{min-height:76px;font-size:1.08em;animation:bio-pop 2.4s ease-in-out 1s 2}';
		$css .= '.bio__tag{position:absolute;top:-10px;left:14px;padding:1px 10px;border-radius:999px;background:' . $accent . ';color:' . ( self::is_light( '#' === $accent[0] ? $accent : '#ef4444' ) ? '#111827' : '#fff' ) . ';font-size:.72em;line-height:1.7}';
		$css .= '@keyframes bio-pop{0%,100%{transform:none}50%{transform:scale(1.03)}}@media (prefers-reduced-motion:reduce){.bio__btn--pinned{animation:none}.bio__btn,.bio__icon{transition:none}}';
		$css .= '.bio__heading{margin:28px 0 10px;font-size:.95em;color:' . $t['muted'] . '}';
		$css .= '.bio__similar{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:0 0 8px;padding:0;list-style:none}';
		$css .= '.bio__similar a{display:block;color:' . $t['text'] . ';font-size:.78em;text-decoration:none;line-height:1.4}';
		$css .= '.bio__similar img,.bio__similar span{display:block;width:56px;height:56px;margin:0 auto 4px;border-radius:50%;object-fit:cover;background:' . $t['button'] . ';border:2px solid ' . $t['border'] . '}';
		$css .= '.bio__foot{margin-top:36px;padding-top:20px;border-top:1px solid ' . ( 'transparent' === $t['border'] ? 'rgba(127,127,127,.3)' : $t['border'] ) . ';font-size:.85em}';
		$css .= '.bio__foot a{color:' . $t['text'] . '}.bio__foot p{margin:6px 0}';
		$css .= '.bio__brand a{display:inline-flex;align-items:center;gap:8px;font-weight:700;text-decoration:none}.bio__brand img{border-radius:6px}';
		$css .= '.bio__make{display:inline-block;margin-top:8px;padding:6px 16px;border:1px solid ' . $t['border'] . ';border-radius:999px;text-decoration:none}';
		$css .= '.bio__actions{display:flex;justify-content:center;gap:8px;margin:0 0 18px}.bio__followform{margin:0}';
		$css .= '.bio__pill{display:inline-flex;align-items:center;gap:6px;min-height:40px;padding:0 18px;border:2px solid ' . $t['border'] . ';border-radius:999px;background:' . $t['button'] . ';color:' . $t['button_text'] . ';font:inherit;font-size:.9em;font-weight:700;text-decoration:none;cursor:pointer}';
		$css .= '.bio__pill--on{opacity:.75}';
		$css .= '.bio__notice{margin:0 0 16px;padding:10px 14px;border-radius:12px;background:rgba(16,185,129,.15);font-size:.9em}';
		$css .= '.bio__ask{position:fixed;left:12px;right:12px;bottom:12px;z-index:10;max-width:536px;margin:0 auto;padding:14px 16px;border-radius:16px;background:#111827;color:#fff;box-shadow:0 8px 24px rgba(0,0,0,.3);font-size:.92em;text-align:center}';
		$css .= '.bio__ask p{margin:0 0 10px}.bio__ask button{min-height:42px;padding:0 22px;border:0;border-radius:999px;background:#fff;color:#111827;font:inherit;font-weight:700;cursor:pointer}';
		// 1.20: 応援・LINE・販売中・最新の投稿・メールの登録・期間の札。
		$ends_bg = '' !== $glow ? $glow : $accent;
		// 背の高いカードは、「まる」の形でも角丸にする（丸くつぶれないように）。
		$card_r  = '999px' === $radius ? '22px' : $radius;
		$css    .= '.bio__ends{position:absolute;top:-10px;right:14px;padding:1px 10px;border-radius:999px;background:' . $ends_bg . ';color:#fff;font-size:.7em;font-weight:700;line-height:1.7}';
		$css    .= '.bio__btn--tip{background:linear-gradient(135deg,#ff5f8f,#ff9a5a);color:#fff;border-color:transparent;box-shadow:0 6px 18px rgba(255,95,143,.35)}.bio__btn--tip .bio__btn__ico{animation:bio-beat 1.6s ease-in-out infinite}';
		$css    .= '@keyframes bio-beat{0%,100%{transform:none}15%{transform:scale(1.18)}30%{transform:none}}@media (prefers-reduced-motion:reduce){.bio__btn--tip .bio__btn__ico{animation:none}}';
		$css    .= '.bio__btn--line{background:#06c755;color:#fff;border-color:#06c755}';
		$css    .= '.bio__paid,.bio__feed{display:grid;gap:12px;margin:0 0 24px;padding:0;list-style:none;text-align:left}';
		$css    .= '.bio__card{position:relative;display:flex;align-items:center;gap:12px;padding:14px 16px;border-radius:' . $card_r . ';background:' . ( 'transparent' === $t['button'] || 0 === strpos( $t['button'], 'rgba' ) ? 'rgba(255,255,255,.1)' : $t['button'] ) . ';color:' . ( 'transparent' === $t['button'] || 0 === strpos( $t['button'], 'rgba' ) ? $t['text'] : $t['button_text'] ) . ';border:2px solid ' . $t['border'] . ';text-decoration:none;transition:transform .12s}';
		$css    .= '.bio__card:hover,.bio__card:focus-visible{transform:translateY(-2px)}.bio__card__ico{display:inline-flex;flex:0 0 40px;align-items:center;justify-content:center;width:40px;height:40px;border-radius:12px;background:rgba(127,127,127,.14)}.bio__card__ico svg{width:22px;height:22px}';
		$css    .= '.bio__card__body{display:flex;flex:1 1 auto;flex-direction:column;min-width:0}.bio__card__body strong{font-size:1em;line-height:1.35}.bio__card__body small{opacity:.75;font-size:.78em;word-break:break-all}';
		$css    .= '.bio__card__kind{align-self:flex-start;margin-bottom:2px;padding:0 8px;border-radius:999px;background:' . $accent . ';color:' . ( self::is_light( '#' === $accent[0] ? $accent : '#ef4444' ) ? '#111827' : '#fff' ) . ';font-size:.68em;font-weight:700;line-height:1.8}';
		$css    .= '.bio__card__price{flex:0 0 auto;font-size:1.15em;font-weight:800;letter-spacing:.01em}';
		$css    .= '.bio__feed a{display:flex;gap:12px;align-items:center;padding:8px;border-radius:' . $card_r . ';background:rgba(127,127,127,.1);color:' . $t['text'] . ';text-decoration:none;transition:transform .12s}.bio__feed a:hover{transform:translateY(-2px)}';
		$css    .= '.bio__feed img,.bio__feed__noimg{flex:0 0 112px;width:112px;height:63px;border-radius:8px;object-fit:cover;background:rgba(127,127,127,.2)}.bio__feed__noimg{display:inline-flex;align-items:center;justify-content:center}.bio__feed__noimg svg{width:26px;height:26px}';
		$css    .= '.bio__feed__txt{display:flex;flex-direction:column;min-width:0}.bio__feed__txt strong{font-size:.92em;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.bio__feed__src{color:' . $t['muted'] . ';font-size:.74em}';
		$css    .= '.bio__feed--top a{flex-direction:column;align-items:stretch;padding:0 0 10px;overflow:hidden}.bio__feed--top img,.bio__feed--top .bio__feed__noimg{flex:none;width:100%;height:auto;aspect-ratio:16/9;border-radius:0}.bio__feed--top .bio__feed__txt{padding:0 12px}.bio__feed--top strong{font-size:1em}';
		$css    .= '.bio__mail{margin:8px 0 24px;padding:18px 16px;border-radius:18px;background:rgba(127,127,127,.1);border:1px solid ' . ( 'transparent' === $t['border'] ? 'rgba(127,127,127,.3)' : $t['border'] ) . ';text-align:left}';
		$css    .= '.bio__mail h2{margin:0 0 6px;font-size:1.02em}.bio__mail p{margin:0 0 10px;font-size:.88em;color:' . $t['muted'] . '}.bio__mail .bio__notice{color:' . $t['text'] . '}';
		$css    .= '.bio__mailrow{display:flex;gap:8px}.bio__mailrow input{flex:1 1 auto;min-width:0;min-height:46px;padding:0 14px;border:1px solid rgba(127,127,127,.45);border-radius:12px;font:inherit;font-size:16px;color:#111827;background:#fff}';
		$css    .= '.bio__mailrow button{flex:0 0 auto;min-height:46px;padding:0 18px;border:0;border-radius:12px;background:' . ( 'transparent' === $t['button'] || 0 === strpos( $t['button'], 'rgba' ) || self::is_light( self::hex_or( $t['button'], '#ffffff' ) ) ? $t['text'] : $t['button'] ) . ';color:' . ( 'transparent' === $t['button'] || 0 === strpos( $t['button'], 'rgba' ) || self::is_light( self::hex_or( $t['button'], '#ffffff' ) ) ? self::hex_or( self::bg_colors( $t )[0], '#ffffff' ) : $t['button_text'] ) . ';font:inherit;font-weight:700;cursor:pointer}';
		$css    .= '.bio__agree{display:flex;gap:8px;align-items:flex-start;margin-top:10px;font-size:.78em;line-height:1.5}.bio__agree input{margin-top:3px}.bio__agree a{color:inherit}';
		$css    .= '.bio__hp,.bio__sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}';
		return $css;
	}

	private static function hex_or( $color, $fallback ) {
		return preg_match( '/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', (string) $color ) ? $color : $fallback;
	}

	/* --------------------------------------------------------------------
	 * ボタンのアイコン
	 * ------------------------------------------------------------------ */

	/**
	 * ボタンの種類（sns:x・store:note・link:0 など）に合うアイコン（SVG）。
	 */
	public static function icon( $key ) {
		list( $kind, $id ) = array_pad( explode( ':', (string) $key, 2 ), 2, '' );
		if ( 'sns' === $kind ) {
			return SNSCS_Portal_Top::sns_icon( $id );
		}
		if ( 'feed' === $kind ) {
			$map = array( 'yt' => 'sns:youtube', 'note' => 'store:note', 'blog' => 'article:0' );
			return self::icon( $map[ $id ] ?? 'link:0' );
		}
		$paths = array(
			'note'    => '<path fill="currentColor" d="M4 3h11.5L20 7.5V21H4zM6 5v14h12V8.3L14.7 5zm2 5h8v1.8H8zm0 3.4h8v1.8H8z"/>',
			'brain'   => '<path fill="currentColor" d="M9 3a3 3 0 0 0-3 3 3 3 0 0 0-2.5 4.7A3.2 3.2 0 0 0 5 16a3 3 0 0 0 4 3.8V3zm6 0v16.8A3 3 0 0 0 19 16a3.2 3.2 0 0 0 1.5-5.3A3 3 0 0 0 18 6a3 3 0 0 0-3-3z"/>',
			'link'    => '<path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7L11.5 6.8M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1.5-1.5"/>',
			'page'    => '<circle fill="none" stroke="currentColor" stroke-width="2.2" cx="12" cy="8" r="4"/><path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M4 21c1.2-4 4.4-6 8-6s6.8 2 8 6"/>',
			'inquiry' => '<path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" d="M3 6h18v12H3zM3 7l9 6 9-6"/>',
			'tip'     => '<path fill="currentColor" d="M12 21s-7.5-4.6-9.6-9.3C.9 8.4 2.9 4.5 6.6 4.5c2.1 0 3.6 1.2 4.4 2.6.8-1.4 2.3-2.6 4.4-2.6 3.7 0 5.7 3.9 4.2 7.2C19.5 16.4 12 21 12 21z"/>',
			'line'    => '<path fill="currentColor" d="M12 3C6.5 3 2 6.6 2 11c0 4 3.5 7.3 8.3 7.9.3.1.8.2.9.5.1.3.1.7 0 1l-.1.9c0 .3-.2 1 .9.6 1.1-.5 6-3.5 8.2-6.1C21.6 14.2 22 12.6 22 11c0-4.4-4.5-8-10-8zM8.3 13.4H6.3a.5.5 0 0 1-.5-.5V9a.5.5 0 0 1 1 0v3.4h1.5a.5.5 0 0 1 0 1zm1.9-.5a.5.5 0 0 1-1 0V9a.5.5 0 0 1 1 0zm4.7 0a.5.5 0 0 1-.9.3l-2-2.7v2.4a.5.5 0 0 1-1 0V9a.5.5 0 0 1 .9-.3l2 2.7V9a.5.5 0 0 1 1 0zm3.2-2.4a.5.5 0 0 1 0 1h-1.5v.9h1.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1-.5-.5V9a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 0 1h-1.5v.9z"/>',
			'article' => '<path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M5 4h14v16H5zM8 9h8M8 13h8M8 17h5"/>',
		);
		$path = 'store' === $kind ? ( $paths[ $id ] ?? $paths['link'] ) : ( $paths[ $kind ] ?? $paths['link'] );
		return '<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}
}
