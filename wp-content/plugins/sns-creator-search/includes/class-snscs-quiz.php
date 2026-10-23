<?php
/**
 * 「あなたに合う発信者診断」（[sns_shindan]）。
 *
 * 5つの質問（ジャンル・よく使うSNS・好きな規模・地域・探し方）に答えると、タイプ名
 * （例: 「料理の目利き」タイプ）と、おすすめの発信者3人を出す。結果のURLには答えが入っていて、
 * SNSでシェアすると結果の画像（1200×630）がカードに出る。結果のページは検索エンジンに載せない。
 * 条件に合う人が3人いないときは、地域 → 規模 → SNS の順に条件をゆるめて探す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Quiz {

	const IMAGE_VAR = 'snscs_shindan_img';

	/** 好きな規模: キー => [選択肢の文, タイプ名, フォロワーの下限, 上限] */
	const SCALES = array(
		'big'  => array( '有名で影響力のある人', 'トレンドハンター', 100000, 0 ),
		'mid'  => array( 'ほどよい距離感の、実力派', '目利き', 10000, 100000 ),
		'near' => array( '身近で、応援したくなる人', '応援団長', 0, 10000 ),
		'any'  => array( 'こだわらない', '探検家', 0, 0 ),
	);

	/** 探し方: キー => [選択肢の文, 並べ方, 結果の説明] */
	const GOALS = array(
		'pick'    => array( '自分にぴったりの人', 'recommended', '見てくれている人の反応がよい発信者' ),
		'popular' => array( 'みんなが見ている人', 'followers', 'フォロワーの多い発信者' ),
		'new'     => array( '新しく始まった人', 'newest', '最近このサイトに加わった発信者' ),
	);

	/** 結果のページを表示中のとき、その中身。 */
	private static $result = null;

	public static function init() {
		add_shortcode( 'sns_shindan', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_image' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'resolve' ), 6 );
		add_action( 'wp_head', array( __CLASS__, 'ogp' ), 3 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_filter( 'wpseo_robots', array( __CLASS__, 'seo_robots' ) );
		add_filter( 'wpseo_opengraph_image', array( __CLASS__, 'seo_image' ) );
		add_filter( 'wpseo_twitter_image', array( __CLASS__, 'seo_image' ) );
		add_filter( 'rank_math/opengraph/facebook/image', array( __CLASS__, 'seo_image' ) );
		add_filter( 'rank_math/opengraph/twitter/image', array( __CLASS__, 'seo_image' ) );
		add_filter( 'wpseo_opengraph_title', array( __CLASS__, 'seo_title' ) );
		add_filter( 'rank_math/opengraph/facebook/og_title', array( __CLASS__, 'seo_title' ) );
	}

	/* --------------------------------------------------------------------
	 * 答え
	 * ------------------------------------------------------------------ */

	/**
	 * 送られてきた答えを確かめる（ジャンルが無い・おかしいときは null）。
	 *
	 * @return array{genre:WP_Term,platform:string,scale:string,region:WP_Term|null,goal:string}|null
	 */
	public static function answers( array $in ) {
		$get   = function ( $key ) use ( $in ) {
			return isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? (string) $in[ $key ] : '';
		};
		$genre = '' !== $get( 'g' ) ? get_term_by( 'slug', sanitize_title( $get( 'g' ) ), SNSCS_Post_Types::TAX_GENRE ) : null;
		if ( ! $genre ) {
			return null;
		}
		$platform = sanitize_key( $get( 'p' ) );
		$region   = '' !== $get( 'rg' ) ? get_term_by( 'slug', sanitize_title( $get( 'rg' ) ), SNSCS_Post_Types::TAX_REGION ) : null;
		$scale    = sanitize_key( $get( 'sc' ) );
		$goal     = sanitize_key( $get( 'o' ) );
		return array(
			'genre'    => $genre,
			'platform' => array_key_exists( $platform, snscs_platforms() ) ? $platform : '',
			'scale'    => array_key_exists( $scale, self::SCALES ) ? $scale : 'any',
			'region'   => $region ? $region : null,
			'goal'     => array_key_exists( $goal, self::GOALS ) ? $goal : 'pick',
		);
	}

	/**
	 * 答えをURLのパラメーターに戻す（結果のURL・画像のURL用）。
	 */
	private static function params( array $a ) {
		return array_filter(
			array(
				'g'  => urldecode( $a['genre']->slug ),
				'p'  => $a['platform'],
				'sc' => $a['scale'],
				'rg' => $a['region'] ? urldecode( $a['region']->slug ) : '',
				'o'  => $a['goal'],
			),
			'strlen'
		);
	}

	public static function page_url() {
		$url = SNSCS_Hub::page_url( 'shindan' );
		return $url ? $url : home_url( '/' );
	}

	public static function result_url( array $a ) {
		return add_query_arg( array_map( 'rawurlencode', self::params( $a ) ), self::page_url() );
	}

	public static function image_url( array $a ) {
		return add_query_arg( array_map( 'rawurlencode', array_merge( array( self::IMAGE_VAR => 1 ), self::params( $a ) ) ), home_url( '/' ) );
	}

	/**
	 * タイプ名。例: 「料理の目利き」
	 */
	public static function type_name( array $a ) {
		return $a['genre']->name . 'の' . self::SCALES[ $a['scale'] ][1];
	}

	public static function type_text( array $a ) {
		$texts = array(
			'big'  => '話題の中心にいる人をいち早くチェックして、流行を先取りするタイプ。',
			'mid'  => 'フォロワーの数より中身を大切に、本当に役立つ人を見つけるタイプ。',
			'near' => 'これから伸びる人を見つけて、近い距離で応援するのが好きなタイプ。',
			'any'  => '数にとらわれず、気になった人をどんどん見に行く好奇心旺盛なタイプ。',
		);
		return $texts[ $a['scale'] ] . 'そんなあなたに、' . $a['genre']->name . 'ジャンルから' . self::GOALS[ $a['goal'] ][2] . 'を選びました。';
	}

	/**
	 * おすすめの3人（足りなければ条件をゆるめて足す）。
	 *
	 * @return array{items:array,relaxed:string[]}
	 */
	public static function pick( array $a ) {
		$scale = self::SCALES[ $a['scale'] ];
		$args  = array(
			'genre'         => $a['genre']->slug,
			'platform'      => $a['platform'],
			'region'        => $a['region'] ? $a['region']->slug : '',
			'min_followers' => $scale[2],
			'max_followers' => $scale[3],
			'sort'          => self::GOALS[ $a['goal'] ][1],
			'per_page'      => 3,
		);
		$steps   = array(
			array( '', array() ),
			array( '地域', array( 'region' => '' ) ),
			array( '規模', array( 'min_followers' => 0, 'max_followers' => 0 ) ),
			array( 'SNS', array( 'platform' => '' ) ),
		);
		$items   = array();
		$relaxed = array();
		foreach ( $steps as $step ) {
			if ( $step[1] ) {
				$before = $args;
				$args   = array_merge( $args, $step[1] );
				if ( $before === $args ) {
					continue;
				}
				$relaxed[] = $step[0];
			}
			$result = SNSCS_Search::run( $args );
			foreach ( $result['items'] as $item ) {
				if ( count( $items ) < 3 && ! isset( $items[ $item['id'] ] ) ) {
					$items[ $item['id'] ] = $item;
				}
			}
			if ( count( $items ) >= 3 ) {
				break;
			}
		}
		return array( 'items' => array_values( $items ), 'relaxed' => $items ? $relaxed : array() );
	}

	/* --------------------------------------------------------------------
	 * 結果のページ
	 * ------------------------------------------------------------------ */

	public static function resolve() {
		$page = SNSCS_Hub::page_id( 'shindan' );
		if ( ! $page || ! is_page( $page ) || empty( $_GET['g'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$answers = self::answers( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $answers ) {
			return;
		}
		self::$result = array_merge( array( 'answers' => $answers ), self::pick( $answers ) );
		if ( ! defined( 'DONOTCACHEPAGE' ) && is_user_logged_in() ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	public static function robots( array $robots ) {
		if ( self::$result ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	public static function seo_robots( $robots ) {
		return self::$result ? 'noindex, follow' : $robots;
	}

	public static function seo_image( $image ) {
		return self::$result ? self::image_url( self::$result['answers'] ) : $image;
	}

	public static function seo_title( $title ) {
		return self::$result ? self::share_title( self::$result['answers'] ) : $title;
	}

	private static function share_title( array $a ) {
		return 'わたしは「' . self::type_name( $a ) . '」タイプでした｜あなたに合う発信者診断';
	}

	public static function ogp() {
		if ( ! self::$result || defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
			return;
		}
		$a    = self::$result['answers'];
		$tags = array(
			'og:type'         => 'website',
			'og:title'        => self::share_title( $a ),
			'og:description'  => self::type_text( $a ),
			'og:url'          => self::result_url( $a ),
			'og:site_name'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'og:image'        => self::image_url( $a ),
			'og:image:width'  => '1200',
			'og:image:height' => '630',
			'twitter:card'    => 'summary_large_image',
		);
		foreach ( $tags as $property => $content ) {
			$attr = 0 === strpos( $property, 'twitter:' ) ? 'name' : 'property';
			printf( '<meta %s="%s" content="%s">' . "\n", esc_attr( $attr ), esc_attr( $property ), esc_attr( $content ) );
		}
	}

	/* --------------------------------------------------------------------
	 * 表示
	 * ------------------------------------------------------------------ */

	public static function shortcode() {
		SNSCS_Hub::remember_page( 'shindan' );
		wp_enqueue_style( 'snscs' );
		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		if ( self::$result ) {
			self::render_result( self::$result );
		} else {
			self::render_form();
		}
		return ob_get_clean();
	}

	private static function option( $name, $value, $label, $required = false ) {
		printf(
			'<label class="snscs-quiz__opt"><input type="radio" name="%s" value="%s"%s> <span>%s</span></label>',
			esc_attr( $name ),
			esc_attr( $value ),
			$required ? ' required' : '',
			esc_html( $label )
		);
	}

	private static function render_form() {
		$genres  = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 16 ) );
		$regions = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_REGION, 'hide_empty' => true, 'parent' => 0 ) );
		if ( ! $genres || is_wp_error( $genres ) ) {
			echo '<p class="snscs-help">発信者が掲載されると、診断できるようになります。</p>';
			return;
		}
		?>
		<div class="snscs-quiz">
			<p class="snscs-quiz__lead">5つの質問に答えるだけで、あなたのタイプと、ぴったりの発信者3人がわかります（約30秒・登録不要）。</p>
			<form method="get" action="<?php echo esc_url( self::page_url() ); ?>">
				<?php if ( ! get_option( 'permalink_structure' ) ) : ?>
					<input type="hidden" name="page_id" value="<?php echo (int) SNSCS_Hub::page_id( 'shindan' ); ?>">
				<?php endif; ?>
				<fieldset class="snscs-quiz__q">
					<legend><span class="snscs-quiz__no">Q1</span>いちばん興味があるのは？</legend>
					<?php
					foreach ( $genres as $i => $genre ) {
						self::option( 'g', urldecode( $genre->slug ), $genre->name, 0 === $i );
					}
					?>
				</fieldset>
				<fieldset class="snscs-quiz__q">
					<legend><span class="snscs-quiz__no">Q2</span>よく使うSNSは？</legend>
					<?php
					foreach ( snscs_platforms() as $key => $label ) {
						self::option( 'p', $key, $label );
					}
					self::option( 'p', '', 'いろいろ使う' );
					?>
				</fieldset>
				<fieldset class="snscs-quiz__q">
					<legend><span class="snscs-quiz__no">Q3</span>どんな発信者が好き？</legend>
					<?php
					foreach ( self::SCALES as $key => $scale ) {
						self::option( 'sc', $key, $scale[0] );
					}
					?>
				</fieldset>
				<?php if ( $regions && ! is_wp_error( $regions ) ) : ?>
					<fieldset class="snscs-quiz__q">
						<legend><span class="snscs-quiz__no">Q4</span>住んでいる地域は？</legend>
						<?php
						foreach ( $regions as $region ) {
							self::option( 'rg', urldecode( $region->slug ), $region->name );
						}
						self::option( 'rg', '', 'こだわらない' );
						?>
					</fieldset>
				<?php endif; ?>
				<fieldset class="snscs-quiz__q">
					<legend><span class="snscs-quiz__no"><?php echo $regions && ! is_wp_error( $regions ) ? 'Q5' : 'Q4'; ?></span>見つけたいのは？</legend>
					<?php
					foreach ( self::GOALS as $key => $goal ) {
						self::option( 'o', $key, $goal[0] );
					}
					?>
				</fieldset>
				<p><button type="submit" class="snscs-button snscs-button--primary snscs-quiz__submit">診断する</button></p>
				<p class="snscs-help">Q1だけ必ず選んでください。ほかは選ばなくても診断できます。</p>
			</form>
		</div>
		<?php
	}

	private static function render_result( array $result ) {
		$a     = $result['answers'];
		$url   = self::result_url( $a );
		$text  = 'わたしは「' . self::type_name( $a ) . '」タイプでした！ #発信者診断';
		$image = self::image_url( $a );
		wp_enqueue_script( 'snscs-report', SNSCS_URL . 'assets/js/snscs-report.js', array(), SNSCS_VERSION, true );
		?>
		<div class="snscs-quiz snscs-quiz--result">
			<section class="snscs-quiz__type">
				<p class="snscs-quiz__you">あなたは</p>
				<p class="snscs-quiz__name">「<?php echo esc_html( self::type_name( $a ) ); ?>」タイプ</p>
				<p class="snscs-quiz__text"><?php echo esc_html( self::type_text( $a ) ); ?></p>
			</section>

			<h2 class="snscs-trendpage__title">あなたにおすすめの発信者</h2>
			<?php if ( $result['items'] ) : ?>
				<?php if ( $result['relaxed'] ) : ?>
					<p class="snscs-help"><?php echo esc_html( 'ぴったりの人が少なかったので、' . implode( '・', $result['relaxed'] ) . 'の条件を広げて選びました。' ); ?></p>
				<?php endif; ?>
				<?php SNSCS_Frontend::render_cards( $result['items'] ); ?>
			<?php else : ?>
				<p class="snscs-results__empty">このジャンルの発信者は、まだ掲載されていません。</p>
			<?php endif; ?>

			<?php if ( ! is_user_logged_in() && SNSCS_Social::member_url() ) : ?>
				<p class="snscs-quiz__cta"><a class="snscs-button snscs-button--primary" href="<?php echo esc_url( SNSCS_Social::member_url() ); ?>">無料の会員登録で、気になる発信者をフォロー</a><br><small>フォローすると、新しい記事が出たときにお知らせが届きます。</small></p>
			<?php endif; ?>

			<h2 class="snscs-trendpage__title">結果をシェア</h2>
			<p><img class="snscs-sharekit__image" src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( '診断結果「' . self::type_name( $a ) . '」タイプ' ); ?>" width="600" height="315" loading="lazy"></p>
			<div class="snscs-share">
				<button type="button" class="snscs-button snscs-button--primary" hidden data-snscs-report-share data-image="<?php echo esc_url( $image ); ?>" data-file="shindan.png" data-text="<?php echo esc_attr( $text ); ?>" data-url="<?php echo esc_url( $url ); ?>">画像をシェア</button>
				<a class="snscs-share__button snscs-share__button--x" href="<?php echo esc_url( 'https://x.com/intent/tweet?' . http_build_query( array( 'text' => $text, 'url' => $url ) ) ); ?>" target="_blank" rel="noopener">Xでシェア</a>
				<a class="snscs-share__button snscs-share__button--line" href="<?php echo esc_url( 'https://social-plugins.line.me/lineit/share?' . http_build_query( array( 'url' => $url ) ) ); ?>" target="_blank" rel="noopener">LINEで送る</a>
				<a class="snscs-share__button snscs-share__button--threads" href="<?php echo esc_url( 'https://www.threads.net/intent/post?' . http_build_query( array( 'text' => $text . ' ' . $url ) ) ); ?>" target="_blank" rel="noopener">Threadsでシェア</a>
			</div>
			<p><a href="<?php echo esc_url( self::page_url() ); ?>">もう一度診断する</a>　<a href="<?php echo esc_url( SNSCS_Features::url( '', $a['genre']->slug ) ); ?>"><?php echo esc_html( $a['genre']->name ); ?>の発信者をもっと見る</a></p>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------
	 * シェア用の画像（1200×630）
	 * ------------------------------------------------------------------ */

	public static function serve_image() {
		if ( empty( $_GET[ self::IMAGE_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$answers = self::answers( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$image   = $answers ? self::draw( $answers, self::pick( $answers )['items'] ) : false;
		if ( ! $image ) {
			status_header( 404 );
			exit;
		}
		header( 'Content-Type: image/png' );
		header( 'Cache-Control: public, max-age=86400' );
		imagepng( $image );
		imagedestroy( $image );
		exit;
	}

	/**
	 * @return resource|GdImage|false
	 */
	public static function draw( array $a, array $items ) {
		$font = SNSCS_Share::font();
		if ( ! $font || ! function_exists( 'imagecreatetruecolor' ) ) {
			return false;
		}
		$w     = 1200;
		$h     = 630;
		$image = imagecreatetruecolor( $w, $h );
		imageantialias( $image, true );
		for ( $y = 0; $y < $h; $y++ ) {
			$t = $y / $h;
			imageline( $image, 0, $y, $w, $y, imagecolorallocate( $image, (int) ( 79 - 30 * $t ), (int) ( 70 - 20 * $t ), (int) ( 229 - 60 * $t ) ) );
		}
		$white  = imagecolorallocate( $image, 255, 255, 255 );
		$soft   = imagecolorallocate( $image, 224, 231, 255 );
		$ink    = imagecolorallocate( $image, 17, 24, 39 );
		$muted  = imagecolorallocate( $image, 75, 85, 99 );
		$accent = imagecolorallocate( $image, 79, 70, 229 );
		$gold   = imagecolorallocate( $image, 245, 158, 11 );
		$text   = function ( $str, $size, $x, $y, $color, $max = 0, $center = false ) use ( $image, $font, $w ) {
			if ( $max ) {
				list( $str, $size ) = SNSCS_Share::fit( $str, $font, $size, $max );
			}
			if ( $center ) {
				$box = imagettfbbox( $size, 0, $font, $str );
				$x   = (int) ( ( $w - ( $box[2] - $box[0] ) ) / 2 );
			}
			imagettftext( $image, $size, 0, $x, $y, $color, $font, $str );
		};

		$text( 'あなたに合う発信者診断', 26, 0, 78, $soft, 1000, true );
		$text( 'わたしは', 30, 0, 140, $white, 1000, true );
		$text( '「' . self::type_name( $a ) . '」タイプ', 64, 0, 230, $white, 1080, true );

		SNSCS_Share::rounded_rect( $image, 70, 280, $w - 70, $h - 70, 24, $white );
		$text( 'おすすめの発信者', 24, 110, 332, $accent );
		$y = 392;
		foreach ( array_slice( $items, 0, 3 ) as $i => $item ) {
			imagefilledellipse( $image, 132, $y - 14, 44, 44, 0 === $i ? $gold : $accent );
			$text( (string) ( $i + 1 ), 22, 124, $y - 3, $white );
			$text( html_entity_decode( $item['name'], ENT_QUOTES, 'UTF-8' ), 30, 172, $y, $ink, 860 );
			$y += 62;
		}
		if ( ! $items ) {
			$text( 'これから掲載される発信者をお楽しみに', 28, 110, 420, $muted, 980 );
		}
		$text( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '　' . wp_parse_url( home_url(), PHP_URL_HOST ), 22, 0, $h - 26, $soft, 1000, true );
		return $image;
	}
}
