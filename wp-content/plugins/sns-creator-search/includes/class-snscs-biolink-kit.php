<?php
/**
 * マイリンクを広めるための素材（画像）。発信者のデザインの色で、名前・写真・QRコード入りで作る。
 *
 *   ?snscs_bio_kit=発信者ID&type=story   … ストーリーズ用（1080×1920。Instagram・TikTok・LINE VOOM など）
 *   ?snscs_bio_kit=発信者ID&type=card    … シェア用カード（1200×630。X・Threads・Facebook の投稿に）
 *   ?snscs_bio_kit=発信者ID&type=qrcard  … 印刷用のQRカード（1063×638。名刺の大きさ 91×55mm を 300dpi で）
 *   &dl=1 で保存（ダウンロード）
 * 中身（名前・ID・写真・デザイン）が変わったら作り直す。作った画像はアップロードのフォルダに置いて使い回す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Biolink_Kit {

	const VERSION = 1;

	public static function types() {
		return array(
			'story'  => array( 'label' => 'ストーリーズ用', 'size' => array( 1080, 1920 ), 'note' => 'Instagram・TikTok・LINE VOOM のストーリーズに。リンクのスタンプと一緒に' ),
			'card'   => array( 'label' => 'シェア用カード', 'size' => array( 1200, 630 ), 'note' => 'X・Threads・Facebook の投稿に添えて' ),
			'qrcard' => array( 'label' => '印刷用QRカード', 'size' => array( 1063, 638 ), 'note' => '名刺の大きさ（91×55mm）。イベントや名刺の裏に' ),
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'serve' ), 1 );
	}

	public static function url( $creator_id, $type, $download = false ) {
		return add_query_arg( array_filter( array( 'snscs_bio_kit' => (int) $creator_id, 'type' => $type, 'dl' => $download ? 1 : 0 ) ), home_url( '/' ) );
	}

	public static function serve() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['snscs_bio_kit'] ) ) {
			return;
		}
		$creator_id = absint( $_GET['snscs_bio_kit'] );
		$type       = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$download   = ! empty( $_GET['dl'] );
		// phpcs:enable
		if ( ! $creator_id || ! isset( self::types()[ $type ] ) || 'publish' !== get_post_status( $creator_id ) || SNSCS_Post_Types::POST_TYPE !== get_post_type( $creator_id ) ) {
			status_header( 404 );
			exit;
		}
		$file = self::file( $creator_id, $type );
		if ( '' === $file ) {
			status_header( 404 );
			exit;
		}
		header( 'Content-Type: image/png' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Robots-Tag: noindex' );
		if ( $download ) {
			header( 'Content-Disposition: attachment; filename="mylink-' . SNSCS_Biolink::name_for( $creator_id ) . '-' . $type . '.png"' );
		}
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * 画像のファイル（無ければ作る）。
	 */
	public static function file( $creator_id, $type ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagettftext' ) || '' === SNSCS_Share::font() ) {
			return '';
		}
		$style = SNSCS_Biolink_Design::style_for( $creator_id );
		$photo = (string) get_attached_file( (int) get_post_thumbnail_id( $creator_id ) );
		$data  = array(
			'name'  => html_entity_decode( get_the_title( $creator_id ), ENT_QUOTES, 'UTF-8' ),
			'id'    => SNSCS_Biolink::name_for( $creator_id ),
			'url'   => SNSCS_Biolink::url( $creator_id ),
			'style' => $style,
			'photo' => $photo && is_readable( $photo ) ? $photo . '|' . filemtime( $photo ) : '',
		);
		$key   = substr( md5( wp_json_encode( $data ) . self::VERSION . SNSCS_Brand::on() ), 0, 12 );
		$up    = wp_upload_dir();
		$dir   = trailingslashit( $up['basedir'] ) . 'snscs-kit/';
		$file  = $dir . (int) $creator_id . '-' . $type . '-' . $key . '.png';
		if ( is_readable( $file ) ) {
			return $file;
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		foreach ( (array) glob( $dir . (int) $creator_id . '-' . $type . '-*.png' ) as $old ) {
			wp_delete_file( $old );
		}
		$image = self::draw( $type, $data, $photo && is_readable( $photo ) ? $photo : '', $creator_id );
		if ( ! $image ) {
			return '';
		}
		imagepng( $image, $file, 6 );
		imagedestroy( $image );
		return is_readable( $file ) ? $file : '';
	}

	/* --------------------------------------------------------------------
	 * 描く
	 * ------------------------------------------------------------------ */

	private static function hex( $color, $fallback ) {
		return preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $color ) ? $color : ( preg_match( '/^#[0-9a-fA-F]{3}$/', (string) $color ) ? '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3] : $fallback );
	}

	private static function alloc( $image, $hex, $alpha = 0 ) {
		$hex = ltrim( $hex, '#' );
		return imagecolorallocatealpha( $image, hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ), $alpha );
	}

	/**
	 * 上から下へのグラデーション。
	 */
	private static function gradient( $image, $w, $h, $from, $to ) {
		$a = array_map( 'hexdec', str_split( ltrim( $from, '#' ), 2 ) );
		$b = array_map( 'hexdec', str_split( ltrim( $to, '#' ), 2 ) );
		for ( $y = 0; $y < $h; $y++ ) {
			$t = $h > 1 ? $y / ( $h - 1 ) : 0;
			$c = imagecolorallocate( $image, (int) round( $a[0] + ( $b[0] - $a[0] ) * $t ), (int) round( $a[1] + ( $b[1] - $a[1] ) * $t ), (int) round( $a[2] + ( $b[2] - $a[2] ) * $t ) );
			imageline( $image, 0, $y, $w, $y, $c );
		}
	}

	/**
	 * 横の中央に文字を書く（長ければ小さくする）。
	 */
	private static function center_text( $image, $text, $size, $y, $color, $font, $max_w, $cx = null ) {
		$cx    = null === $cx ? imagesx( $image ) / 2 : $cx;
		$fit   = SNSCS_Share::fit( $text, $font, $size, $max_w );
		$box   = imagettfbbox( $fit[1], 0, $font, $fit[0] );
		$width = $box[2] - $box[0];
		imagettftext( $image, $fit[1], 0, (int) round( $cx - $width / 2 ), $y, $color, $font, $fit[0] );
	}

	private static function rounded( $image, $x1, $y1, $x2, $y2, $r, $color ) {
		SNSCS_Share::rounded_rect( $image, $x1, $y1, $x2, $y2, $r, $color );
	}

	/**
	 * 丸く切り抜いた写真（無ければ頭文字の丸）。
	 */
	private static function avatar( $image, $photo, $cx, $cy, $size, $ring_hex, $fill_hex, $text_hex, $initial, $font ) {
		$r = (int) ( $size / 2 );
		imagefilledellipse( $image, $cx, $cy, $size + 16, $size + 16, self::alloc( $image, $ring_hex ) );
		$src = $photo ? @imagecreatefromstring( (string) file_get_contents( $photo ) ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $src ) {
			$sw   = imagesx( $src );
			$sh   = imagesy( $src );
			$side = min( $sw, $sh );
			$tmp  = imagecreatetruecolor( $size, $size );
			imagecopyresampled( $tmp, $src, 0, 0, (int) ( ( $sw - $side ) / 2 ), (int) ( ( $sh - $side ) / 2 ), $size, $size, $side, $side );
			for ( $y = 0; $y < $size; $y++ ) {
				for ( $x = 0; $x < $size; $x++ ) {
					if ( ( $x - $r ) * ( $x - $r ) + ( $y - $r ) * ( $y - $r ) <= $r * $r ) {
						imagesetpixel( $image, $cx - $r + $x, $cy - $r + $y, imagecolorat( $tmp, $x, $y ) );
					}
				}
			}
			imagedestroy( $tmp );
			imagedestroy( $src );
			return;
		}
		imagefilledellipse( $image, $cx, $cy, $size, $size, self::alloc( $image, $fill_hex ) );
		$fs  = (int) ( $size * 0.4 );
		$box = imagettfbbox( $fs, 0, $font, $initial );
		imagettftext( $image, $fs, 0, (int) ( $cx - ( $box[2] - $box[0] ) / 2 - $box[0] ), (int) ( $cy + ( $box[1] - $box[7] ) / 2 - $box[1] ), self::alloc( $image, $text_hex ), $font, $initial );
	}

	/**
	 * QRコード（白い角丸の板の上に）。
	 */
	private static function qr( $image, $text, $x, $y, $size ) {
		$m = SNSCS_QR::matrix( $text );
		if ( ! $m ) {
			return;
		}
		$n    = count( $m ) + 8;
		$cell = $size / $n;
		$ink  = imagecolorallocate( $image, 17, 24, 39 );
		self::rounded( $image, $x, $y, $x + $size, $y + $size, (int) ( $size * 0.06 ), imagecolorallocate( $image, 255, 255, 255 ) );
		foreach ( $m as $r => $row ) {
			foreach ( $row as $c => $dark ) {
				if ( $dark ) {
					imagefilledrectangle( $image, (int) round( $x + ( $c + 4 ) * $cell ), (int) round( $y + ( $r + 4 ) * $cell ), (int) round( $x + ( $c + 5 ) * $cell ) - 1, (int) round( $y + ( $r + 5 ) * $cell ) - 1, $ink );
				}
			}
		}
	}

	private static function brand_line( $image, $text, $y, $color, $font, $size ) {
		self::center_text( $image, $text, $size, $y, $color, $font, imagesx( $image ) - 120 );
	}

	/**
	 * @return resource|GdImage|false
	 */
	public static function draw( $type, array $d, $photo, $creator_id ) {
		list( $w, $h ) = self::types()[ $type ]['size'];
		$font  = SNSCS_Share::font();
		$p     = SNSCS_Biolink_Design::palette( $d['style'] );
		$cols  = SNSCS_Biolink_Design::bg_colors( $p );
		$from  = self::hex( $cols[0], '#ffffff' );
		$to    = self::hex( $cols[1], $from );
		$light = SNSCS_Biolink_Design::is_light( $from );
		$text  = self::hex( $p['text'], $light ? '#111827' : '#ffffff' );
		$muted = $light ? '#4b5563' : '#e5e7eb';
		$btn   = self::hex( $p['button'], $light ? '#111827' : '#ffffff' );
		$btn_t = self::hex( $p['button_text'], SNSCS_Biolink_Design::is_light( $btn ) ? '#111827' : '#ffffff' );
		$site  = SNSCS_Brand::on() ? SNSCS_Brand::NAME : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$short = preg_replace( '#^https?://#', '', $d['url'] );
		$init  = mb_substr( $d['name'], 0, 1 );
		$qr    = add_query_arg( 's', 'qr', $d['url'] );

		$image = imagecreatetruecolor( $w, $h );
		imagealphablending( $image, true );
		if ( 'qrcard' === $type ) {
			imagefilledrectangle( $image, 0, 0, $w, $h, imagecolorallocate( $image, 255, 255, 255 ) );
			// 左の帯（デザインの色）。
			$band = imagecreatetruecolor( 420, $h );
			self::gradient( $band, 420, $h, $from, $to );
			imagecopy( $image, $band, 0, 0, 0, 0, 420, $h );
			imagedestroy( $band );
			self::qr( $image, $qr, 50, 109, 320 );
			$ink = imagecolorallocate( $image, 17, 24, 39 );
			$sub = imagecolorallocate( $image, 75, 85, 99 );
			$acc = self::alloc( $image, $light ? '#1B27B0' : $from );
			imagettftext( $image, 22, 0, 470, 150, $acc, $font, 'MY LINK' );
			$fit = SNSCS_Share::fit( $d['name'], $font, 46, 540 );
			imagettftext( $image, $fit[1], 0, 470, 240, $ink, $font, $fit[0] );
			imagettftext( $image, 26, 0, 470, 300, $sub, $font, '@' . $d['id'] );
			$fit = SNSCS_Share::fit( $short, $font, 22, 540 );
			imagettftext( $image, $fit[1], 0, 470, 420, $sub, $font, $fit[0] );
			imagettftext( $image, 22, 0, 470, 470, $ink, $font, 'SNS・お仕事の相談は、QRコードから' );
			imagettftext( $image, 18, 0, 470, 580, $sub, $font, $site . ' のマイリンク' );
			return $image;
		}

		self::gradient( $image, $w, $h, $from, $to );
		$t_c = self::alloc( $image, $text );
		$m_c = self::alloc( $image, $muted );

		if ( 'story' === $type ) {
			self::avatar( $image, $photo, (int) ( $w / 2 ), 460, 300, $btn, $btn, $btn_t, $init, $font );
			self::center_text( $image, $d['name'], 64, 760, $t_c, $font, $w - 160 );
			self::center_text( $image, '@' . $d['id'], 34, 830, $m_c, $font, $w - 160 );
			// QR の板とボタン風の帯。
			self::qr( $image, $qr, (int) ( ( $w - 560 ) / 2 ), 930, 560 );
			self::rounded( $image, 140, 1560, $w - 140, 1680, 60, self::alloc( $image, $btn ) );
			self::center_text( $image, 'リンクはこちら', 44, 1638, self::alloc( $image, $btn_t ), $font, $w - 360 );
			self::center_text( $image, $short, 28, 1760, $m_c, $font, $w - 160 );
			self::brand_line( $image, $site . '｜SNSのリンクまとめ', 1860, $m_c, $font, 24 );
			return $image;
		}

		// card（1200×630）
		self::avatar( $image, $photo, 230, 290, 260, $btn, $btn, $btn_t, $init, $font );
		$fit = SNSCS_Share::fit( $d['name'], $font, 54, 420 );
		imagettftext( $image, $fit[1], 0, 420, 250, $t_c, $font, $fit[0] );
		imagettftext( $image, 30, 0, 420, 310, $m_c, $font, '@' . $d['id'] );
		self::rounded( $image, 420, 360, 820, 440, 40, self::alloc( $image, $btn ) );
		self::center_text( $image, 'SNS・リンクまとめ', 28, 412, self::alloc( $image, $btn_t ), $font, 360, 620 );
		$fit = SNSCS_Share::fit( $short, $font, 22, 440 );
		imagettftext( $image, $fit[1], 0, 420, 500, $m_c, $font, $fit[0] );
		self::qr( $image, $qr, 880, 175, 280 );
		imagettftext( $image, 20, 0, 60, 590, $m_c, $font, $site );
		return $image;
	}
}
