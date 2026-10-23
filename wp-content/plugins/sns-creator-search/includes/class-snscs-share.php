<?php
/**
 * 発信者に宣伝してもらうための素材。
 *
 *   掲載中バッジ  /snscs-badge/紹介コード.svg（?s=dark で暗い色）
 *       発信者が自分のブログやサイトに貼る画像。リンク先は紹介リンクなので、押されると紹介リンク流入として数える。
 *   シェア画像    /snscs-share/紹介コード.png（1200×630）
 *       名前・写真・ジャンル・順位を入れた画像。発信者ページの OGP 画像にも使い、SNSでシェアされたときに表示される。
 *       日本語を書くにはフォントが必要（設定、またはサーバーにあるものを自動で探す）。無ければ数字と英字だけ入れる。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Share {

	const BADGE_VAR = 'snscs_badge';
	const SHARE_VAR = 'snscs_share';
	const VERSION   = 2;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'ogp' ), 3 );
		add_filter( 'wpseo_opengraph_image', array( __CLASS__, 'seo_image' ) );
		add_filter( 'wpseo_twitter_image', array( __CLASS__, 'seo_image' ) );
		add_filter( 'rank_math/opengraph/facebook/image', array( __CLASS__, 'seo_image' ) );
		add_filter( 'rank_math/opengraph/twitter/image', array( __CLASS__, 'seo_image' ) );
		add_filter( 'aioseo_facebook_tags', array( __CLASS__, 'aioseo_tags' ) );
		add_filter( 'aioseo_twitter_tags', array( __CLASS__, 'aioseo_tags' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_images' ) );
	}

	public static function add_rewrite_rules() {
		add_rewrite_rule( '^snscs-badge/([A-Za-z0-9]{4,16})\.svg$', 'index.php?' . self::BADGE_VAR . '=$matches[1]', 'top' );
		add_rewrite_rule( '^snscs-share/([A-Za-z0-9]{4,16})\.png$', 'index.php?' . self::SHARE_VAR . '=$matches[1]', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::BADGE_VAR;
		$vars[] = self::SHARE_VAR;
		return $vars;
	}

	private static function file_url( $var, $path, $creator_id, $ext ) {
		$code = SNSCS_Scores::ref_code( $creator_id );
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/' . $path . '/' . $code . '.' . $ext );
		}
		return add_query_arg( $var, $code, home_url( '/' ) );
	}

	public static function badge_url( $creator_id, $dark = false ) {
		$url = self::file_url( self::BADGE_VAR, 'snscs-badge', $creator_id, 'svg' );
		return $dark ? add_query_arg( 's', 'dark', $url ) : $url;
	}

	public static function share_url( $creator_id ) {
		return self::file_url( self::SHARE_VAR, 'snscs-share', $creator_id, 'png' );
	}

	public static function serve() {
		$badge = get_query_var( self::BADGE_VAR );
		$share = get_query_var( self::SHARE_VAR );
		if ( ( '' === $badge || ! is_string( $badge ) ) && ( '' === $share || ! is_string( $share ) ) ) {
			return;
		}
		$creator_id = SNSCS_Scores::creator_for_code( '' !== $badge ? $badge : $share );
		if ( ! $creator_id || 'publish' !== get_post_status( $creator_id ) ) {
			status_header( 404 );
			exit;
		}
		if ( '' !== $badge ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$dark = isset( $_GET['s'] ) && 'dark' === $_GET['s'];
			header( 'Content-Type: image/svg+xml; charset=utf-8' );
			header( 'Cache-Control: public, max-age=3600' );
			header( 'X-Content-Type-Options: nosniff' );
			echo self::badge_svg( $creator_id, $dark ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 中の文字は badge_svg() でエスケープ済み。
			exit;
		}
		$file = self::share_file( $creator_id );
		if ( ! $file ) {
			status_header( 404 );
			exit;
		}
		header( 'Content-Type: image/png' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/* --------------------------------------------------------------------
	 * 掲載中バッジ（SVG）
	 * ------------------------------------------------------------------ */

	/**
	 * 文字の幅のおおよその見積もり（全角は1文字、半角は0.55文字）。
	 */
	private static function text_width( $text, $size ) {
		$width = 0;
		foreach ( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			$width += strlen( $char ) > 1 ? $size : $size * 0.58;
		}
		return (int) ceil( $width );
	}

	/**
	 * 掲載中バッジ。総合100位以内なら順位も入れる。
	 */
	public static function badge_svg( $creator_id, $dark = false ) {
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$standing = SNSCS_Scores::standing( $creator_id );
		$right    = ( $standing && $standing['rank'] <= 100 ) ? sprintf( '掲載中・総合%d位', $standing['rank'] ) : '掲載中';
		$size     = 12;
		$left_w   = self::text_width( $site, $size ) + 20;
		$right_w  = self::text_width( $right, $size ) + 20;
		$width    = $left_w + $right_w;
		$bg_left  = $dark ? '#0f172a' : '#1e3a8a';
		$bg_right = $dark ? '#f59e0b' : '#e0e7ff';
		$fg_right = $dark ? '#0f172a' : '#1e3a8a';
		$label    = $site . ' ' . $right;
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="24" viewBox="0 0 %1$d 24" role="img" aria-label="%2$s"><title>%2$s</title><rect width="%1$d" height="24" rx="5" fill="%3$s"/><rect x="%4$d" width="%5$d" height="24" rx="5" fill="%6$s"/><rect x="%4$d" width="6" height="24" fill="%6$s"/><g font-family="\'Hiragino Sans\',\'Noto Sans JP\',\'Yu Gothic\',Meiryo,sans-serif" font-size="%7$d" font-weight="700"><text x="10" y="16.5" fill="#ffffff">%8$s</text><text x="%9$d" y="16.5" fill="%10$s">%11$s</text></g></svg>',
			$width,
			esc_attr( $label ),
			$bg_left,
			$left_w,
			$right_w,
			$bg_right,
			$size,
			esc_html( $site ),
			$left_w + 10,
			$fg_right,
			esc_html( $right )
		);
	}

	/**
	 * 発信者が自分のサイトに貼るHTML。
	 */
	public static function badge_html( $creator_id, $dark = false ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return sprintf( '<a href="%s" target="_blank" rel="noopener"><img src="%s" alt="%s" height="24"></a>', esc_url( SNSCS_Referrals::link_for( $creator_id ) ), esc_url( self::badge_url( $creator_id, $dark ) ), esc_attr( $site . 'に掲載中' ) );
	}

	/* --------------------------------------------------------------------
	 * シェア画像（PNG）
	 * ------------------------------------------------------------------ */

	/**
	 * 日本語を書けるフォント（設定 → サーバーにあるもの の順に探す）。
	 */
	public static function font() {
		$setting = (string) SNSCS_Hub::setting( 'share_font' );
		$candidates = array_merge(
			'' !== $setting ? array( $setting ) : array(),
			array_merge( (array) glob( SNSCS_DIR . 'assets/fonts/*.ttf' ), (array) glob( SNSCS_DIR . 'assets/fonts/*.otf' ), (array) glob( SNSCS_DIR . 'assets/fonts/*.ttc' ) ),
			array(
				'/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc',
				'/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
				'/usr/share/fonts/noto-cjk/NotoSansCJK-Bold.ttc',
				'/usr/share/fonts/google-noto-cjk/NotoSansCJK-Bold.ttc',
				'/usr/share/fonts/truetype/fonts-japanese-gothic.ttf',
				'/usr/share/fonts/opentype/ipaexfont-gothic/ipaexg.ttf',
				'/usr/share/fonts/truetype/takao-gothic/TakaoPGothic.ttf',
				'/usr/share/fonts/truetype/vlgothic/VL-PGothic-Regular.ttf',
				'/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
				'/usr/share/fonts/wqy-zenhei/wqy-zenhei.ttc',
			)
		);
		foreach ( $candidates as $path ) {
			if ( $path && is_readable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	private static function dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'snscs-share/';
	}

	/**
	 * シェア画像のファイル（無ければ作る）。中身（名前・順位・写真）が変わったら作り直す。
	 *
	 * @return string ファイルの場所。作れなかったら空文字。
	 */
	public static function share_file( $creator_id ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return '';
		}
		$data = self::share_data( $creator_id );
		$key  = md5( wp_json_encode( $data ) . self::font() . self::VERSION );
		$dir  = self::dir();
		$file = $dir . $creator_id . '-' . substr( $key, 0, 12 ) . '.png';
		if ( is_readable( $file ) ) {
			return $file;
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		self::delete_images( $creator_id );
		$image = self::draw( $data );
		if ( ! $image ) {
			return '';
		}
		imagepng( $image, $file, 6 );
		imagedestroy( $image );
		return is_readable( $file ) ? $file : '';
	}

	public static function delete_images( $post_id ) {
		if ( SNSCS_Post_Types::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		foreach ( (array) glob( self::dir() . (int) $post_id . '-*.png' ) as $old ) {
			wp_delete_file( $old );
		}
	}

	/**
	 * 画像に入れる中身。
	 */
	public static function share_data( $creator_id ) {
		$standing = SNSCS_Scores::standing( $creator_id );
		$genres   = wp_get_object_terms( $creator_id, SNSCS_Post_Types::TAX_GENRE, array( 'fields' => 'names' ) );
		$accounts = SNSCS_Accounts::get_for_creator( $creator_id );
		$total    = 0;
		foreach ( $accounts as $account ) {
			$total += (int) $account['followers'];
		}
		$rank_line = '';
		if ( $standing && $standing['rank'] <= 100 && $standing['total'] >= 10 ) {
			$rank_line = sprintf( '総合ランキング %d位', $standing['rank'] );
		}
		return array(
			'name'      => get_the_title( $creator_id ),
			'genres'    => is_wp_error( $genres ) ? '' : implode( '・', array_slice( $genres, 0, 3 ) ),
			'sns'       => implode( ' / ', array_unique( array_map( 'snscs_platform_label', wp_list_pluck( $accounts, 'platform' ) ) ) ),
			'followers' => $total ? '合計フォロワー ' . snscs_format_count( $total ) : '',
			'rank'      => $rank_line,
			'rank_no'   => $standing ? (int) $standing['rank'] : 0,
			'photo'     => (int) get_post_thumbnail_id( $creator_id ),
			'site'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'host'      => wp_parse_url( home_url(), PHP_URL_HOST ),
		);
	}

	/**
	 * 1200×630 の画像を描く。
	 *
	 * @return resource|GdImage|false
	 */
	public static function draw( array $data ) {
		$w     = 1200;
		$h     = 630;
		$image = imagecreatetruecolor( $w, $h );
		if ( ! $image ) {
			return false;
		}
		// 背景: 上から下へ紺色のグラデーション。
		for ( $y = 0; $y < $h; $y++ ) {
			$t = $y / $h;
			imageline( $image, 0, $y, $w, $y, imagecolorallocate( $image, (int) ( 30 + 20 * $t ), (int) ( 58 + 40 * $t ), (int) ( 138 + 60 * $t ) ) );
		}
		$white  = imagecolorallocate( $image, 255, 255, 255 );
		$ink    = imagecolorallocate( $image, 17, 24, 39 );
		$muted  = imagecolorallocate( $image, 75, 85, 99 );
		$accent = imagecolorallocate( $image, 245, 158, 11 );
		$navy   = imagecolorallocate( $image, 30, 58, 138 );

		// 白いカード。
		self::rounded_rect( $image, 50, 50, $w - 50, $h - 110, 28, $white );

		// 写真（無ければ頭文字の丸）。
		$photo_x = 100;
		$photo_y = 110;
		$size    = 240;
		$photo   = $data['photo'] ? get_attached_file( $data['photo'] ) : '';
		$src     = ( $photo && is_readable( $photo ) ) ? @imagecreatefromstring( (string) file_get_contents( $photo ) ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $src ) {
			$sw   = imagesx( $src );
			$sh   = imagesy( $src );
			$side = min( $sw, $sh );
			imagecopyresampled( $image, $src, $photo_x, $photo_y, (int) ( ( $sw - $side ) / 2 ), (int) ( ( $sh - $side ) / 2 ), $size, $size, $side, $side );
			imagedestroy( $src );
		} else {
			imagefilledellipse( $image, $photo_x + $size / 2, $photo_y + $size / 2, $size, $size, $navy );
		}

		$font = self::font();
		$x    = $photo_x + $size + 50;
		if ( $font ) {
			$name = self::fit( $data['name'], $font, 54, $w - 100 - $x );
			imagettftext( $image, $name[1], 0, $x, 185, $ink, $font, $name[0] );
			$y = 245;
			foreach ( array( $data['genres'], $data['sns'], $data['followers'] ) as $line ) {
				if ( '' !== $line ) {
					$fitted = self::fit( $line, $font, 26, $w - 100 - $x );
					imagettftext( $image, $fitted[1], 0, $x, $y, $muted, $font, $fitted[0] );
					$y += 46;
				}
			}
			if ( '' !== $data['rank'] ) {
				self::rounded_rect( $image, $x, 400, $x + 520, 470, 16, $accent );
				imagettftext( $image, 32, 0, $x + 24, 448, $ink, $font, $data['rank'] );
			}
			if ( ! $src ) {
				$initial = mb_substr( $data['name'], 0, 1 );
				$box     = imagettfbbox( 96, 0, $font, $initial );
				imagettftext( $image, 96, 0, (int) ( $photo_x + $size / 2 - ( $box[2] - $box[0] ) / 2 ), $photo_y + $size / 2 + 48, $white, $font, $initial );
			}
			$footer = self::fit( $data['site'] . '  ' . $data['host'], $font, 30, $w - 100 );
			imagettftext( $image, $footer[1], 0, 60, $h - 45, $white, $font, $footer[0] );
		} else {
			// フォントが無いときは、組み込みの文字（英数字だけ）を大きくして描く。
			if ( $data['rank_no'] && '' !== $data['rank'] ) {
				self::big_text( $image, 'No.' . $data['rank_no'], $x, 180, 10, $ink );
				self::big_text( $image, 'RANKING', $x, 330, 5, $accent );
			}
			self::big_text( $image, (string) $data['host'], 60, $h - 85, 4, $white );
		}
		return $image;
	}

	/**
	 * 幅に収まるよう文字を小さくし、それでも長ければ末尾を「…」にする。
	 *
	 * @return array{0:string,1:int} [文字, 大きさ]
	 */
	public static function fit( $text, $font, $size, $max_width ) {
		$measure = function ( $t, $s ) use ( $font ) {
			$box = imagettfbbox( $s, 0, $font, $t );
			return $box[2] - $box[0];
		};
		while ( $size > 20 && $measure( $text, $size ) > $max_width ) {
			$size -= 2;
		}
		while ( mb_strlen( $text ) > 1 && $measure( $text, $size ) > $max_width ) {
			$text = mb_substr( $text, 0, mb_strlen( $text ) - 2 ) . '…';
		}
		return array( $text, $size );
	}

	public static function rounded_rect( $image, $x1, $y1, $x2, $y2, $r, $color ) {
		imagefilledrectangle( $image, $x1 + $r, $y1, $x2 - $r, $y2, $color );
		imagefilledrectangle( $image, $x1, $y1 + $r, $x2, $y2 - $r, $color );
		foreach ( array( array( $x1 + $r, $y1 + $r ), array( $x2 - $r, $y1 + $r ), array( $x1 + $r, $y2 - $r ), array( $x2 - $r, $y2 - $r ) ) as $c ) {
			imagefilledellipse( $image, $c[0], $c[1], $r * 2, $r * 2, $color );
		}
	}

	/**
	 * 組み込みの文字（英数字）を拡大して描く。
	 */
	private static function big_text( $image, $text, $x, $y, $scale, $color ) {
		$font = 5;
		$tw   = imagefontwidth( $font ) * strlen( $text );
		$th   = imagefontheight( $font );
		$tmp  = imagecreatetruecolor( $tw, $th );
		imagealphablending( $tmp, false );
		imagesavealpha( $tmp, true );
		imagefill( $tmp, 0, 0, imagecolorallocatealpha( $tmp, 0, 0, 0, 127 ) );
		$rgb = imagecolorsforindex( $image, $color );
		imagestring( $tmp, $font, 0, 0, $text, imagecolorallocate( $tmp, $rgb['red'], $rgb['green'], $rgb['blue'] ) );
		imagealphablending( $image, true );
		imagecopyresized( $image, $tmp, $x, $y, 0, 0, $tw * $scale, $th * $scale, $tw, $th );
		imagedestroy( $tmp );
	}

	/* --------------------------------------------------------------------
	 * OGP（SNSでシェアされたときの表示）
	 * ------------------------------------------------------------------ */

	private static function current_creator() {
		return is_singular( SNSCS_Post_Types::POST_TYPE ) && SNSCS_Hub::setting( 'ogp' ) ? (int) get_queried_object_id() : 0;
	}

	public static function seo_image( $image ) {
		$id = self::current_creator();
		return $id ? self::share_url( $id ) : $image;
	}

	public static function aioseo_tags( $tags ) {
		$id = self::current_creator();
		if ( $id && is_array( $tags ) ) {
			foreach ( array( 'og:image', 'og:image:secure_url', 'twitter:image' ) as $key ) {
				if ( isset( $tags[ $key ] ) ) {
					$tags[ $key ] = self::share_url( $id );
				}
			}
		}
		return $tags;
	}

	public static function ogp() {
		$id = self::current_creator();
		if ( ! $id || defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
			return;
		}
		$post  = get_post( $id );
		$desc  = wp_trim_words( SNSCS_Search::excerpt( $post ), 60, '…' );
		$tags  = array(
			'og:type'        => 'profile',
			'og:title'       => get_the_title( $id ) . ' | ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'og:description' => $desc,
			'og:url'         => get_permalink( $id ),
			'og:site_name'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'og:image'       => self::share_url( $id ),
			'og:image:width' => '1200',
			'og:image:height' => '630',
			'twitter:card'   => 'summary_large_image',
		);
		foreach ( $tags as $property => $content ) {
			$attr = 0 === strpos( $property, 'twitter:' ) ? 'name' : 'property';
			printf( '<meta %s="%s" content="%s">' . "\n", esc_attr( $attr ), esc_attr( $property ), esc_attr( $content ) );
		}
	}

	/* --------------------------------------------------------------------
	 * マイページ: シェアとバッジ
	 * ------------------------------------------------------------------ */

	public static function render_portal( $creator_id ) {
		$position = SNSCS_Rankings::position( $creator_id );
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$link     = SNSCS_Referrals::link_for( $creator_id );
		$lines    = array();
		if ( $position['overall'] && $position['overall'] <= 100 ) {
			$lines[] = sprintf( '総合ランキング%d位', $position['overall'] );
		}
		if ( $position['rising'] ) {
			$lines[] = sprintf( '急上昇ランキング%d位', $position['rising'] );
		}
		if ( $position['invite_rank'] ) {
			$lines[] = sprintf( '今月の招待ランキング%d位', $position['invite_rank'] );
		}
		$text = $lines ? sprintf( '「%s」で%sになりました！ありがとうございます。', $site, implode( '・', $lines ) ) : sprintf( '「%s」に発信者として掲載されています。', $site );
		?>
		<section class="snscs-panel snscs-sharekit" id="snscs-sharekit">
			<h2 class="snscs-panel__title">シェア画像と掲載中バッジ</h2>
			<dl class="snscs-referral__stats">
				<div><dt>総合ランキング</dt><dd><?php echo $position['overall'] ? esc_html( number_format_i18n( $position['overall'] ) . '位' ) : '—'; ?><?php if ( $position['previous'] ) : ?> <small>（先月 <?php echo esc_html( number_format_i18n( $position['previous'] ) ); ?>位）</small><?php endif; ?></dd></div>
				<div><dt>急上昇ランキング</dt><dd><?php echo $position['rising'] ? esc_html( $position['rising'] . '位' ) : '圏外'; ?></dd></div>
				<div><dt>今月の招待</dt><dd><?php echo esc_html( $position['invites_month'] . '人' ); ?><?php echo $position['invite_rank'] ? ' <small>（' . esc_html( $position['invite_rank'] ) . '位）</small>' : ''; ?></dd></div>
			</dl>

			<h3 class="snscs-sharekit__heading">シェア画像</h3>
			<p>あなたのページがSNSでシェアされると、この画像が表示されます（順位は1時間ごとに更新）。投稿に使ってもかまいません。</p>
			<p><img class="snscs-sharekit__image" src="<?php echo esc_url( add_query_arg( 'v', time() >> 10, self::share_url( $creator_id ) ) ); ?>" alt="シェア画像" width="600" height="315" loading="lazy"></p>
			<div class="snscs-share">
				<a class="snscs-share__button snscs-share__button--x" href="<?php echo esc_url( 'https://x.com/intent/tweet?' . http_build_query( array( 'text' => $text, 'url' => $link ) ) ); ?>" target="_blank" rel="noopener">Xでシェア</a>
				<a class="snscs-share__button snscs-share__button--line" href="<?php echo esc_url( 'https://social-plugins.line.me/lineit/share?' . http_build_query( array( 'url' => $link ) ) ); ?>" target="_blank" rel="noopener">LINEで送る</a>
				<a class="snscs-share__button snscs-share__button--fb" href="<?php echo esc_url( 'https://www.facebook.com/sharer/sharer.php?' . http_build_query( array( 'u' => $link ) ) ); ?>" target="_blank" rel="noopener">Facebookで共有</a>
				<a class="snscs-button" href="<?php echo esc_url( self::share_url( $creator_id ) ); ?>" download>画像を保存</a>
			</div>
			<p class="snscs-help">シェアのリンクはあなたの紹介リンクなので、見に来た人は紹介リンク流入として数えます。</p>

			<h3 class="snscs-sharekit__heading">掲載中バッジ</h3>
			<p>ブログやホームページに貼ってください。押した人はあなたのページに移動し、紹介リンク流入として数えます。</p>
			<?php foreach ( array( false => '明るい色', true => '暗い色' ) as $dark => $label ) : ?>
				<div class="snscs-sharekit__badge">
					<p><img src="<?php echo esc_url( self::badge_url( $creator_id, (bool) $dark ) ); ?>" alt="<?php echo esc_attr( $site . 'に掲載中' ); ?>" height="24"> <small><?php echo esc_html( $label ); ?></small></p>
					<label class="screen-reader-text" for="snscs-badge-<?php echo (int) $dark; ?>">貼り付け用のHTML（<?php echo esc_html( $label ); ?>）</label>
					<textarea id="snscs-badge-<?php echo (int) $dark; ?>" rows="3" readonly onclick="this.select();"><?php echo esc_textarea( self::badge_html( $creator_id, (bool) $dark ) ); ?></textarea>
					<button type="button" class="snscs-button" onclick="var t=document.getElementById('snscs-badge-<?php echo (int) $dark; ?>');t.select();navigator.clipboard&&navigator.clipboard.writeText(t.value);this.textContent='コピーしました';">HTMLをコピー</button>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * 発信者ページ: 見ている人が友だちに教えるためのシェアボタン。
	 */
	public static function visitor_buttons( $creator_id ) {
		$url  = get_permalink( $creator_id );
		$text = sprintf( '%sさんのSNSアカウントまとめ', get_the_title( $creator_id ) );
		return '<div class="snscs-share snscs-share--small">'
			. SNSCS_Recommend::fav_button( $creator_id )
			. '<a class="snscs-share__button snscs-share__button--x" href="' . esc_url( 'https://x.com/intent/tweet?' . http_build_query( array( 'text' => $text, 'url' => $url ) ) ) . '" target="_blank" rel="noopener">Xでシェア</a>'
			. '<a class="snscs-share__button snscs-share__button--line" href="' . esc_url( 'https://social-plugins.line.me/lineit/share?' . http_build_query( array( 'url' => $url ) ) ) . '" target="_blank" rel="noopener">LINEで送る</a>'
			. '</div>';
	}
}
