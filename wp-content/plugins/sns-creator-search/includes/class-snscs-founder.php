<?php
/**
 * 創設メンバーの徽章（1.23）。
 *
 * - 先着（設定した人数まで）で掲載された発信者に、通し番号（Nº 001〜）を付ける。番号は一度付けたら変わらない
 *   （あとでほかの人が掲載をやめても、詰めたり付け直したりしない）。投稿メタ _snscs_founder_no
 * - 徽章は4つのデザインから運営が選ぶ（サイトのページの設定「創設メンバーの徽章」）:
 *     noir（黒と金）・midnight（ブランドの青とプラチナ・月桂樹）・emerald（深い緑と金・月桂樹）・platinum（白とプラチナ）
 * - 大きな徽章（/?snscs_founder_badge=発信者ID）: 通し番号を入れた SVG。文字は輪郭（パス）にしてあるので、
 *   どの端末でも同じ見た目で、拡大してもにじまない
 * - 小さな徽章（assets/badges/founder-○○-mini.svg）: 検索結果のカード・マイリンクなど、20〜48px で使う
 * - 1.24: 徽章とメダルの下の弧は「FOLLOFIND · Nº 001」（通し番号は 001〜。3けたにそろえ、1000人目は 1000）。
 *   文字は輪郭（assets/badges/founder-serial.json の Jura）を弧に沿って並べる。メダルの画像には文字が無く、上に重ねる
 * - 1.24: 記念メダル（assets/badges/founder-○○-medal.webp）: 徽章を本物の金属のメダルのように仕上げた写真風の画像。
 *   発信者ページで大きく見せる。「記念カードを保存」で、メダル・通し番号・名前・参加日の入った 1200×630 の画像を
 *   その場（ブラウザ）で作って保存・共有できる（背景は founder-○○-card-bg.webp。文字は端末のフォントで書くので、
 *   サーバーに日本語のフォントが無くても作れる）。画像の作り方は .claude/skills/follofind-image
 * デザインの考え方は docs/badges/philosophy.md（Quiet Insignia）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Founder {

	const META      = '_snscs_founder_no';
	const NEXT      = 'snscs_founder_next';
	const QUERY_VAR = 'snscs_founder_badge';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'serve' ), 1 );
	}

	/**
	 * デザイン => [名前, 説明, 文字の色（徽章の横の名札に使う）]
	 */
	public static function designs() {
		return array(
			'noir'     => array( 'ノワール（黒と金）', '黒いエナメルに金。放射状の彫り。いちばん格式のある印象', '#e7cd85' ),
			'midnight' => array( 'ミッドナイト（青とプラチナ）', 'ブランドの青にプラチナと月桂樹。サイトの色と調和する', '#e8edf3' ),
			'emerald'  => array( 'エメラルド（緑と金）', '深い緑に金の月桂樹。勲章のような重み', '#e9cf86' ),
			'platinum' => array( 'プラチナ（白と銀）', '白とプラチナだけの静かなデザイン。明るいページになじむ', '#2b3240' ),
		);
	}

	public static function design() {
		$d = (string) SNSCS_Hub::setting( 'founder_badge' );
		return array_key_exists( $d, self::designs() ) ? $d : 'noir';
	}

	/**
	 * 通し番号（創設メンバーでなければ 0）。先着の人数に入っていて、まだ番号が無ければ、ここで付ける。
	 */
	public static function number( $creator_id ) {
		$creator_id = (int) $creator_id;
		$no         = (int) get_post_meta( $creator_id, self::META, true );
		if ( $no ) {
			return 'publish' === get_post_status( $creator_id ) ? $no : 0;
		}
		$founders = SNSCS_Portal_Top::founders();
		if ( ! isset( $founders[ $creator_id ] ) ) {
			return 0;
		}
		$limit = (int) SNSCS_Hub::setting( 'founders_limit' );
		// 掲載の早い順に番号を付ける（同じ時に何人か来ても、前の人から順に）。
		foreach ( array_keys( $founders ) as $id ) {
			if ( (int) get_post_meta( $id, self::META, true ) ) {
				continue;
			}
			$next = max( 1, (int) get_option( self::NEXT, 1 ) );
			if ( $next > $limit ) {
				break;
			}
			update_post_meta( $id, self::META, $next );
			update_option( self::NEXT, $next + 1, false );
			if ( (int) $id === $creator_id ) {
				return $next;
			}
		}
		return (int) get_post_meta( $creator_id, self::META, true );
	}

	public static function serial( $no ) {
		return str_pad( (string) (int) $no, 3, '0', STR_PAD_LEFT );
	}

	/**
	 * 更新のとき（1.24）: 創設メンバーの人数が前の初期値（100人）のままなら、1000人にする（一度だけ）。
	 */
	public static function install() {
		if ( get_option( 'snscs_founders_1000' ) ) {
			return;
		}
		$settings = (array) get_option( SNSCS_Hub::OPTION, array() );
		if ( isset( $settings['founders_limit'] ) && 100 === (int) $settings['founders_limit'] ) {
			$settings['founders_limit'] = 1000;
			update_option( SNSCS_Hub::OPTION, $settings );
			delete_transient( 'snscs_pt_founders' );
		}
		update_option( 'snscs_founders_1000', 1, false );
	}

	/**
	 * 弧の下側に沿って文字を並べる（左から右へ読める向き）。$r は文字の下の線の半径。
	 *
	 * @return string path の並び。
	 */
	public static function arc( $text, $font, $size, $tracking, $r, $c ) {
		static $fonts = null;
		if ( null === $fonts ) {
			$fonts = json_decode( (string) file_get_contents( SNSCS_DIR . 'assets/badges/founder-serial.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		$g     = $fonts[ $font ];
		$sc    = $size / $g['upm'];
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$total = 0;
		foreach ( $chars as $ch ) {
			$total += ( $g['glyphs'][ $ch ]['w'] ?? 0 ) * $sc + $tracking;
		}
		$span  = rad2deg( ( $total - $tracking ) / $r );
		$pos   = 0;
		$paths = '';
		foreach ( $chars as $ch ) {
			$glyph = $g['glyphs'][ $ch ] ?? array( 'd' => '', 'w' => 0 );
			$gw    = $glyph['w'] * $sc;
			$theta = 180 + $span / 2 - rad2deg( ( $pos + $gw / 2 ) / $r );
			$a     = deg2rad( $theta - 90 );
			if ( '' !== $glyph['d'] ) {
				$paths .= sprintf(
					'<path d="%s" transform="translate(%.2f %.2f) rotate(%.2f) scale(%.5f %.5f) translate(%d 0)"/>',
					esc_attr( $glyph['d'] ),
					$c + $r * cos( $a ),
					$c + $r * sin( $a ),
					$theta - 180,
					$sc,
					-$sc,
					(int) round( -$glyph['w'] / 2 )
				);
			}
			$pos += $gw + $tracking;
		}
		return $paths;
	}

	/**
	 * 記念メダル（写真風の画像）の上に重ねる、下の弧の文字（SVG）。金属の浮き彫りに見えるよう、影と光を重ねる。
	 */
	public static function medal_text( $no, $design = '' ) {
		$design = array_key_exists( $design, self::designs() ) ? $design : self::design();
		// デザイン => [文字の下の線の半径, 大きさ, 字の間, 金属の色（上→下）, 彫り込み（プラチナ）]
		$styles = array(
			'noir'     => array( 256, 22.5, 5.2, array( '#fbedc0', '#e3c374', '#a87b26' ), false ),
			'midnight' => array( 256, 22.5, 5.2, array( '#ffffff', '#dfe4ea', '#9aa3ae' ), false ),
			'emerald'  => array( 257, 23, 5.2, array( '#fbedc0', '#e3c374', '#a87b26' ), false ),
			'platinum' => array( 257, 23, 5.2, array( '#6b7380', '#4a515c', '#353b45' ), true ),
		);
		list( $r, $size, $tracking, $metal, $engraved ) = $styles[ $design ];
		$paths = self::arc( 'FOLLOFIND · Nº ' . self::serial( $no ), 'medium', $size, $tracking, $r, 320 );
		$id    = 'snscs-fm-' . $design;
		return '<svg class="snscs-founder__medal-text" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" width="640" height="640" aria-hidden="true">'
			. '<defs><linearGradient id="' . $id . '" gradientUnits="userSpaceOnUse" x1="0" y1="500" x2="0" y2="585">'
			. '<stop offset="0" stop-color="' . $metal[0] . '"/><stop offset="0.5" stop-color="' . $metal[1] . '"/><stop offset="1" stop-color="' . $metal[2] . '"/></linearGradient></defs>'
			. ( $engraved
				? '<g fill="#ffffff" opacity="0.9" transform="translate(0 0.9)">' . $paths . '</g>'
				: '<g fill="#000000" opacity="0.55" transform="translate(0.3 1.1)">' . $paths . '</g><g fill="#ffffff" opacity="0.35" transform="translate(0 -0.5)">' . $paths . '</g>' )
			. '<g fill="url(#' . $id . ')" stroke="url(#' . $id . ')" stroke-width="0.45">' . $paths . '</g></svg>';
	}

	/**
	 * 記念メダル（画像と、その上の文字）。
	 */
	public static function medal_html( $no, $design = '', $alt = '' ) {
		// 重ね方は style にも書く（管理画面や、テーマの CSS が強いページでもずれないように）。
		return '<span class="snscs-founder__medal" style="position:relative;display:block">'
			. '<img class="snscs-founder__emblem" src="' . esc_url( self::medal_url( $design ) ) . '" alt="' . esc_attr( $alt ) . '" width="128" height="128" loading="lazy" style="display:block;width:100%;height:100%;max-width:none;margin:0">'
			. str_replace( '<svg ', '<svg style="position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none" ', self::medal_text( $no, $design ) ) . '</span>';
	}

	public static function url( $creator_id ) {
		return add_query_arg( array( self::QUERY_VAR => (int) $creator_id, 'v' => self::design() . self::number( $creator_id ) ), home_url( '/' ) );
	}

	public static function medal_url( $design = '' ) {
		$design = array_key_exists( $design, self::designs() ) ? $design : self::design();
		return SNSCS_URL . 'assets/badges/founder-' . $design . '-medal.webp?ver=' . SNSCS_VERSION;
	}

	public static function mini_url() {
		return SNSCS_URL . 'assets/badges/founder-' . self::design() . '-mini.svg?ver=' . SNSCS_VERSION;
	}

	/**
	 * 通し番号の入った徽章（SVG の文字列）。
	 */
	public static function svg( $no, $design = '' ) {
		$design = array_key_exists( $design, self::designs() ) ? $design : self::design();
		$svg    = (string) file_get_contents( SNSCS_DIR . 'assets/badges/founder-' . $design . '.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$arc    = self::arc( 'FOLLOFIND · Nº ' . self::serial( $no ), 'light', 30, 11, 396, 500 );
		return str_replace( '<!--SERIAL-->', '<g fill="' . esc_attr( self::designs()[ $design ][2] ) . '" opacity="0.92">' . $arc . '</g>', $svg );
	}

	public static function serve() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$no = self::number( absint( $_GET[ self::QUERY_VAR ] ) );
		if ( ! $no ) {
			status_header( 404 );
			exit;
		}
		header( 'Content-Type: image/svg+xml; charset=utf-8' );
		header( 'Cache-Control: public, max-age=86400' );
		header( 'X-Content-Type-Options: nosniff' );
		echo self::svg( $no ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 決まった SVG と、エスケープした文字の形。
		exit;
	}

	/* --------------------------------------------------------------------
	 * 表示
	 * ------------------------------------------------------------------ */

	/**
	 * 発信者ページ: 大きな徽章と説明。
	 */
	public static function card_html( $creator_id ) {
		$no = self::number( $creator_id );
		if ( ! $no ) {
			return '';
		}
		$design = self::design();
		$label  = '創設メンバー Nº ' . self::serial( $no );
		$card   = array(
			'medal'  => self::medal_url( $design ),
			'bg'     => SNSCS_URL . 'assets/badges/founder-' . $design . '-card-bg.webp?ver=' . SNSCS_VERSION,
			'color'  => self::designs()[ $design ][2],
			'light'  => 'platinum' !== $design,
			'serial' => 'Nº ' . self::serial( $no ),
			'name'   => html_entity_decode( get_the_title( $creator_id ), ENT_QUOTES, 'UTF-8' ),
			'date'   => get_the_date( 'Y.m.d', $creator_id ),
			'site'   => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			'file'   => 'follofind-founder-' . self::serial( $no ) . '.png',
		);
		wp_enqueue_script( 'snscs-founder', SNSCS_URL . 'assets/js/snscs-founder.js', array(), SNSCS_VERSION, true );
		return '<div class="snscs-founder snscs-founder--' . esc_attr( $design ) . '">'
			. self::medal_html( $no, $design, $label )
			. '<div class="snscs-founder__text"><span class="snscs-founder__eyebrow">FOUNDING MEMBER</span><strong>' . esc_html( $label ) . '</strong>'
			. '<span>' . esc_html( sprintf( 'サイトの立ち上げから参加している、先着%s人だけの発信者です。', number_format_i18n( (int) SNSCS_Hub::setting( 'founders_limit' ) ) ) ) . '</span>'
			. '<button type="button" class="snscs-founder__save" data-snscs-founder-card="' . esc_attr( wp_json_encode( $card ) ) . '">記念カードを保存</button></div></div>';
	}

	/**
	 * 名前の横などに付ける小さな徽章。
	 */
	public static function mini_html( $creator_id, $size = 20 ) {
		$no = self::number( $creator_id );
		if ( ! $no ) {
			return '';
		}
		$label = '創設メンバー Nº ' . self::serial( $no );
		return '<img class="snscs-founder-mini" src="' . esc_url( self::mini_url() ) . '" alt="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '" width="' . (int) $size . '" height="' . (int) $size . '">';
	}
}
