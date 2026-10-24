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
		return str_pad( (string) (int) $no, max( 3, strlen( (string) (int) SNSCS_Hub::setting( 'founders_limit' ) ) ), '0', STR_PAD_LEFT );
	}

	public static function url( $creator_id ) {
		return add_query_arg( array( self::QUERY_VAR => (int) $creator_id, 'v' => self::design() . self::number( $creator_id ) ), home_url( '/' ) );
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
		$g      = json_decode( (string) file_get_contents( SNSCS_DIR . 'assets/badges/founder-serial.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$text   = 'Nº ' . self::serial( $no );
		$sc     = $g['size'] / $g['upm'];
		$total  = 0;
		foreach ( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			$total += ( $g['glyphs'][ $ch ]['w'] ?? 0 ) * $sc + $g['tracking'];
		}
		$x     = 500 - ( $total - $g['tracking'] ) / 2;
		$paths = '';
		foreach ( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			$glyph = $g['glyphs'][ $ch ] ?? null;
			if ( ! $glyph ) {
				continue;
			}
			if ( '' !== $glyph['d'] ) {
				$paths .= sprintf( '<path d="%s" transform="translate(%.2f %.2f) scale(%.5f %.5f)"/>', esc_attr( $glyph['d'] ), $x, $g['baseline'], $sc, -$sc );
			}
			$x += $glyph['w'] * $sc + $g['tracking'];
		}
		return str_replace( '<!--SERIAL-->', '<g fill="' . esc_attr( self::designs()[ $design ][2] ) . '">' . $paths . '</g>', $svg );
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
		$label = '創設メンバー Nº ' . self::serial( $no );
		return '<div class="snscs-founder snscs-founder--' . esc_attr( self::design() ) . '">'
			. '<img class="snscs-founder__emblem" src="' . esc_url( self::url( $creator_id ) ) . '" alt="' . esc_attr( $label ) . '" width="112" height="112" loading="lazy">'
			. '<div class="snscs-founder__text"><span class="snscs-founder__eyebrow">FOUNDING MEMBER</span><strong>' . esc_html( $label ) . '</strong>'
			. '<span>' . esc_html( sprintf( 'サイトの立ち上げから参加している、先着%s人だけの発信者です。', number_format_i18n( (int) SNSCS_Hub::setting( 'founders_limit' ) ) ) ) . '</span></div></div>';
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
