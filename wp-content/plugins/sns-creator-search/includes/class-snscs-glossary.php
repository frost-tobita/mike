<?php
/**
 * SNS用語集（「インプレッションとは」のような検索の入口）。
 *
 *   一覧    : 機能ページ「SNS用語集」（[sns_glossary]）。五十音の行ごとに並べ、その場で絞り込める
 *   用語    : /glossary/{英語のスラッグ}/（SNSCS_Subpages）。ひとことの説明・本文・関連する用語・
 *            その言葉が出てくる質問とコラム・トレンドのページへのリンク。DefinedTerm の構造化データ
 *   用語の管理: 管理画面の「SNS用語集」（投稿と同じように書ける。読み・英語名・関連する用語を付ける）
 *   AIの下書き: 言葉を入れると、AIが読み・説明・本文の下書きを作る（下書きで保存。確認してから公開）
 *   コラムの自動リンク: 公開中の用語は、コラムの本文で最初に出てきたところにリンクが付く（SNSCS_Autolink）
 * 最初の40語は includes/glossary/seed.php から1度だけ入れる。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Glossary {

	const POST_TYPE    = 'snscs_term';
	const QUERY_VAR    = 'snscs_term';
	const META_YOMI    = '_snscs_yomi';
	const META_EN      = '_snscs_term_en';
	const META_RELATED = '_snscs_related';
	const SEEDED       = 'snscs_glossary_seeded';
	const ADMIN_PAGE   = 'snscs-glossary-ai';
	const MODEL        = 'claude-sonnet-5-5';
	const AI_MAX       = 5;

	/** 五十音の行 => 行の頭になる文字（ひらがな） */
	const ROWS = array(
		'あ' => 'あいうえおぁぃぅぇぉゔ',
		'か' => 'かきくけこがぎぐげご',
		'さ' => 'さしすせそざじずぜぞ',
		'た' => 'たちつてとだぢづでどっ',
		'な' => 'なにぬねの',
		'は' => 'はひふへほばびぶべぼぱぴぷぺぽ',
		'ま' => 'まみむめも',
		'や' => 'やゆよゃゅょ',
		'ら' => 'らりるれろ',
		'わ' => 'わをんゎ',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'maybe_seed' ), 100 );
		SNSCS_Subpages::route( 'glossary', self::QUERY_VAR );
		SNSCS_Subpages::sitemap( 'snscsterm', array( __CLASS__, 'sitemap_urls' ), array( __CLASS__, 'sitemap_count' ) );
		add_action( 'template_redirect', array( __CLASS__, 'resolve' ), 5 );
		add_shortcode( 'sns_glossary', array( __CLASS__, 'shortcode' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_meta' ), 10, 2 );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'forget' ), 20 );
		add_action( 'deleted_post', array( __CLASS__, 'forget' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_type_link', array( __CLASS__, 'permalink' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_snscs_glossary_ai', array( __CLASS__, 'handle_ai' ) );
	}

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => 'SNS用語集',
					'singular_name' => '用語',
					'add_new'       => '用語を追加',
					'add_new_item'  => '用語を追加',
					'edit_item'     => '用語を編集',
					'all_items'     => 'SNS用語集',
					'search_items'  => '用語を検索',
					'not_found'     => '用語がありません',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE,
				'show_in_rest'    => false,
				'supports'        => array( 'title', 'editor', 'excerpt' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * 最初の用語を1度だけ入れる（同じスラッグがあれば入れない）。
	 */
	public static function maybe_seed() {
		if ( get_option( self::SEEDED ) ) {
			return;
		}
		update_option( self::SEEDED, 1 );
		$seed = include SNSCS_DIR . 'includes/glossary/seed.php';
		foreach ( (array) $seed as $t ) {
			if ( self::by_slug( $t[0], 'any' ) ) {
				continue;
			}
			$id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => self::POST_TYPE,
						'post_status'  => 'publish',
						'post_title'   => $t[1],
						'post_name'    => $t[0],
						'post_excerpt' => $t[4],
						'post_content' => '<p>' . implode( "</p>\n\n<p>", array_map( 'esc_html', $t[5] ) ) . '</p>',
					)
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				update_post_meta( $id, self::META_YOMI, $t[2] );
				update_post_meta( $id, self::META_EN, $t[3] );
				update_post_meta( $id, self::META_RELATED, implode( ',', $t[6] ) );
			}
		}
		self::forget();
	}

	/* --------------------------------------------------------------------
	 * 用語の読み出し
	 * ------------------------------------------------------------------ */

	/**
	 * @return WP_Post|null
	 */
	public static function by_slug( $slug, $status = 'publish' ) {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'name'             => sanitize_title( $slug ),
				'post_status'      => $status,
				'numberposts'      => 1,
				'suppress_filters' => true,
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * 公開中の用語（読みの順）。1時間ごとに作り直す。
	 *
	 * @return array<int,array{id:int,slug:string,name:string,yomi:string,en:string,short:string}>
	 */
	public static function all() {
		$cached = get_transient( 'snscs_glossary_all' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out = array();
		foreach ( get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 2000, 'suppress_filters' => true ) ) as $p ) {
			$yomi  = (string) get_post_meta( $p->ID, self::META_YOMI, true );
			$out[] = array(
				'id'    => (int) $p->ID,
				'slug'  => $p->post_name,
				'name'  => $p->post_title,
				'yomi'  => '' !== $yomi ? $yomi : mb_convert_kana( $p->post_title, 'c' ),
				'en'    => (string) get_post_meta( $p->ID, self::META_EN, true ),
				'short' => $p->post_excerpt,
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $a['yomi'], $b['yomi'] );
			}
		);
		set_transient( 'snscs_glossary_all', $out, HOUR_IN_SECONDS );
		return $out;
	}

	public static function forget() {
		delete_transient( 'snscs_glossary_all' );
		delete_transient( SNSCS_Autolink::CACHE );
	}

	public static function url( $slug ) {
		return SNSCS_Subpages::url( 'glossary', $slug );
	}

	public static function permalink( $link, $post ) {
		return ( self::POST_TYPE === $post->post_type && 'publish' === $post->post_status && self::url( $post->post_name ) ) ? self::url( $post->post_name ) : $link;
	}

	/**
	 * 読みの最初の文字から、五十音の行（英字・数字は「英数」）。
	 */
	public static function row_of( $yomi ) {
		$first = mb_substr( mb_convert_kana( (string) $yomi, 'c' ), 0, 1 );
		foreach ( self::ROWS as $row => $chars ) {
			if ( '' !== $first && false !== mb_strpos( $chars, $first ) ) {
				return $row;
			}
		}
		return '英数';
	}

	/**
	 * 本文から、ほかの用語の言葉にリンクを付ける（自分自身は除く）。
	 */
	public static function link_terms( $html, $self_slug = '' ) {
		$targets = array();
		foreach ( self::all() as $t ) {
			if ( $t['slug'] !== $self_slug && mb_strlen( $t['name'] ) >= 2 ) {
				$targets[ mb_strtolower( preg_replace( '/（.*?）/u', '', $t['name'] ) ) ] = self::url( $t['slug'] );
			}
		}
		uksort(
			$targets,
			function ( $a, $b ) {
				return mb_strlen( $b ) - mb_strlen( $a );
			}
		);
		return SNSCS_Autolink::apply( $html, $targets );
	}

	/**
	 * 自動リンクに使う「言葉 => URL」（コラム用。SNSCS_Autolink から呼ぶ）。
	 *
	 * @return array<string,string>
	 */
	public static function autolink_targets() {
		$out = array();
		if ( ! SNSCS_Hub::page_id( 'glossary' ) ) {
			return $out;
		}
		foreach ( self::all() as $t ) {
			$word = mb_strtolower( preg_replace( '/（.*?）/u', '', $t['name'] ) );
			// 短すぎる言葉（「保存」など）は、ふつうの文にも出てくるので、3文字以上だけにする。
			if ( mb_strlen( $word ) >= 3 ) {
				$out[ $word ] = self::url( $t['slug'] );
			}
		}
		return $out;
	}

	/* --------------------------------------------------------------------
	 * 用語のページ
	 * ------------------------------------------------------------------ */

	private static $current = null;

	public static function resolve() {
		$value = SNSCS_Subpages::value( 'glossary' );
		if ( '' === $value ) {
			return;
		}
		$post = self::by_slug( $value );
		if ( ! $post ) {
			SNSCS_Subpages::not_found();
			return;
		}
		$name   = $post->post_title;
		$en     = (string) get_post_meta( $post->ID, self::META_EN, true );
		$url    = self::url( $post->post_name );
		$index  = SNSCS_Hub::page_url( 'glossary' );
		$crumbs = array( array( home_url( '/' ), 'トップ' ), array( $index, 'SNS用語集' ), array( $url, $name ) );
		$short  = '' !== $post->post_excerpt ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( $post->post_content ), 60, '…' );
		$term   = array(
			'@type'            => 'DefinedTerm',
			'@id'              => $url . '#term',
			'name'             => $name,
			'description'      => $short,
			'url'              => $url,
			'inDefinedTermSet' => array( '@type' => 'DefinedTermSet', 'name' => 'SNS用語集', 'url' => $index ),
		);
		if ( '' !== $en ) {
			$term['alternateName'] = $en;
		}
		self::$current = array( 'post' => $post, 'en' => $en, 'url' => $url, 'crumbs' => $crumbs, 'short' => $short );
		SNSCS_Subpages::set_current(
			array(
				'page_key'    => 'glossary',
				'title'       => $name . 'とは？意味・使い方をわかりやすく｜SNS用語集',
				'heading'     => $name . 'とは',
				'description' => $name . 'とは、' . $short,
				'url'         => $url,
				'indexable'   => true,
				'jsonld'      => array( SNSCS_Subpages::breadcrumb_ld( $crumbs ), $term ),
			)
		);
	}

	public static function current() {
		return self::$current;
	}

	public static function shortcode() {
		SNSCS_Hub::remember_page( 'glossary' );
		wp_enqueue_style( 'snscs' );
		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		if ( self::$current ) {
			self::render_term( self::$current );
		} else {
			self::render_index();
		}
		return ob_get_clean();
	}

	private static function render_index() {
		$groups = array();
		foreach ( self::all() as $t ) {
			$groups[ self::row_of( $t['yomi'] ) ][] = $t;
		}
		$order = array_merge( array_keys( self::ROWS ), array( '英数' ) );
		$ld    = array(
			'@context'       => 'https://schema.org',
			'@type'          => 'DefinedTermSet',
			'name'           => 'SNS用語集',
			'url'            => SNSCS_Hub::page_url( 'glossary' ),
			'hasDefinedTerm' => array_map(
				function ( $t ) {
					return array( '@type' => 'DefinedTerm', 'name' => $t['name'], 'url' => self::url( $t['slug'] ) );
				},
				array_slice( self::all(), 0, 300 )
			),
		);
		?>
		<p class="snscs-feature__lead">SNSでよく使われる言葉を、初めての人にもわかるように説明します。気になる言葉を探してみてください。</p>
		<p class="snscs-glossary__search">
			<label for="snscs-glossary-q" class="screen-reader-text">用語を絞り込む</label>
			<input type="search" id="snscs-glossary-q" placeholder="例: インプレッション" autocomplete="off">
		</p>
		<nav class="snscs-glossary__rows" aria-label="五十音">
			<?php foreach ( $order as $row ) : ?>
				<?php if ( ! empty( $groups[ $row ] ) ) : ?>
					<a href="#snscs-gl-<?php echo esc_attr( md5( $row ) ); ?>"><?php echo esc_html( $row ); ?></a>
				<?php else : ?>
					<span aria-hidden="true"><?php echo esc_html( $row ); ?></span>
				<?php endif; ?>
			<?php endforeach; ?>
		</nav>
		<?php foreach ( $order as $row ) : ?>
			<?php if ( empty( $groups[ $row ] ) ) { continue; } ?>
			<section class="snscs-glossary__group" id="snscs-gl-<?php echo esc_attr( md5( $row ) ); ?>">
				<h2><?php echo esc_html( '英数' === $row ? '英数字' : $row . '行' ); ?></h2>
				<ul class="snscs-glossary__list">
					<?php foreach ( $groups[ $row ] as $t ) : ?>
						<li data-q="<?php echo esc_attr( mb_strtolower( $t['name'] . ' ' . $t['yomi'] . ' ' . $t['en'] ) ); ?>">
							<a href="<?php echo esc_url( self::url( $t['slug'] ) ); ?>"><strong><?php echo esc_html( $t['name'] ); ?></strong></a>
							<span><?php echo esc_html( $t['short'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endforeach; ?>
		<p class="snscs-glossary__none" hidden>見つかりませんでした。<a href="<?php echo esc_url( SNSCS_Hub::page_url( 'qa' ) ); ?>">質問箱</a>で聞いてみましょう。</p>
		<script>
		( function () {
			var q = document.getElementById( 'snscs-glossary-q' );
			if ( ! q ) { return; }
			q.addEventListener( 'input', function () {
				var v = q.value.trim().toLowerCase(), any = false;
				document.querySelectorAll( '.snscs-glossary__group' ).forEach( function ( g ) {
					var shown = 0;
					g.querySelectorAll( 'li' ).forEach( function ( li ) {
						var ok = ! v || li.getAttribute( 'data-q' ).indexOf( v ) !== -1;
						li.hidden = ! ok;
						shown += ok ? 1 : 0;
					} );
					g.hidden = ! shown;
					any = any || shown > 0;
				} );
				document.querySelector( '.snscs-glossary__none' ).hidden = any;
			} );
		} )();
		</script>
		<script type="application/ld+json"><?php echo wp_json_encode( $ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ); ?></script>
		<?php
	}

	/**
	 * 関連する用語（指定したもの → 本文でこの言葉を使っている用語）。
	 *
	 * @return array<int,array>
	 */
	public static function related( WP_Post $post, $limit = 6 ) {
		$all    = self::all();
		$by_slug = array_column( $all, null, 'slug' );
		$out    = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) get_post_meta( $post->ID, self::META_RELATED, true ) ) ) ) as $slug ) {
			if ( isset( $by_slug[ $slug ] ) && $slug !== $post->post_name ) {
				$out[ $slug ] = $by_slug[ $slug ];
			}
		}
		$word = preg_replace( '/（.*?）/u', '', $post->post_title );
		foreach ( $all as $t ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			if ( $t['slug'] !== $post->post_name && ! isset( $out[ $t['slug'] ] ) && mb_strlen( $word ) >= 2 && false !== mb_strpos( (string) get_post_field( 'post_content', $t['id'] ), $word ) ) {
				$out[ $t['slug'] ] = $t;
			}
		}
		return array_slice( array_values( $out ), 0, $limit );
	}

	/**
	 * その言葉が出てくる質問（回答のあるもの）。
	 */
	public static function questions( $word, $limit = 5 ) {
		global $wpdb;
		if ( ! SNSCS_Hub::page_id( 'qa' ) || mb_strlen( $word ) < 2 ) {
			return array();
		}
		$like = '%' . $wpdb->esc_like( $word ) . '%';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, answers FROM ' . SNSCS_QA::questions_table() . ' WHERE status = 1 AND answers > 0 AND (title LIKE %s OR body LIKE %s) ORDER BY answers DESC, id DESC LIMIT %d', $like, $like, $limit ), ARRAY_A );
	}

	/**
	 * その言葉が出てくるコラム。
	 *
	 * @return WP_Post[]
	 */
	public static function columns_for( $word, $limit = 4 ) {
		$cat = SNSCS_Columns::category_id();
		if ( ! $cat || mb_strlen( $word ) < 2 ) {
			return array();
		}
		return get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'cat' => $cat, 's' => $word, 'numberposts' => $limit, 'suppress_filters' => false ) );
	}

	private static function render_term( array $c ) {
		$post    = $c['post'];
		$word    = preg_replace( '/（.*?）/u', '', $post->post_title );
		$related = self::related( $post );
		$qs      = self::questions( $word );
		$cols    = self::columns_for( $word );
		$trend   = SNSCS_Hub::page_id( 'trends' ) && in_array( mb_strtolower( $word ), SNSCS_Trend_Pages::indexable_terms( 300 ), true ) ? SNSCS_Trend_Pages::url( mb_strtolower( $word ) ) : '';
		$en_url  = class_exists( 'SNSCS_English' ) ? SNSCS_English::glossary_url( $post ) : '';
		echo SNSCS_Subpages::breadcrumbs_html( $c['crumbs'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		?>
		<article class="snscs-glossary__term">
			<p class="snscs-glossary__reading">
				<?php echo esc_html( '読み: ' . get_post_meta( $post->ID, self::META_YOMI, true ) ); ?>
				<?php if ( '' !== $c['en'] ) : ?>
					<span><?php echo esc_html( '英語: ' . $c['en'] ); ?></span>
				<?php endif; ?>
			</p>
			<div class="snscs-glossary__short">
				<p class="snscs-glossary__label">ひとことで言うと</p>
				<p><?php echo esc_html( $c['short'] ); ?></p>
			</div>
			<div class="snscs-glossary__body">
				<?php echo self::link_terms( wpautop( wp_kses_post( $post->post_content ) ), $post->post_name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() 済み。 ?>
			</div>
			<p class="snscs-help"><?php echo esc_html( '更新日: ' . get_the_modified_date( 'Y年n月j日', $post ) ); ?><?php if ( $en_url ) : ?> ・ <a href="<?php echo esc_url( $en_url ); ?>" hreflang="en" lang="en">English</a><?php endif; ?></p>
		</article>

		<?php if ( $trend ) : ?>
			<p class="snscs-glossary__trend">📈 「<?php echo esc_html( $word ); ?>」は、いまこのサイトで検索が増えています。<a href="<?php echo esc_url( $trend ); ?>">トレンドを見る</a></p>
		<?php endif; ?>

		<?php if ( $related ) : ?>
			<h2 class="snscs-single__heading">関連する用語</h2>
			<ul class="snscs-glossary__list snscs-glossary__list--cards">
				<?php foreach ( $related as $t ) : ?>
					<li><a href="<?php echo esc_url( self::url( $t['slug'] ) ); ?>"><strong><?php echo esc_html( $t['name'] ); ?></strong></a><span><?php echo esc_html( $t['short'] ); ?></span></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( $qs ) : ?>
			<h2 class="snscs-single__heading">「<?php echo esc_html( $word ); ?>」についての質問</h2>
			<ul class="snscs-glossary__links">
				<?php foreach ( $qs as $q ) : ?>
					<li><a href="<?php echo esc_url( SNSCS_QA::url( (int) $q['id'] ) ); ?>"><?php echo esc_html( $q['title'] ); ?></a> <span class="snscs-help"><?php echo esc_html( '回答' . (int) $q['answers'] . '件' ); ?></span></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( $cols ) : ?>
			<h2 class="snscs-single__heading">「<?php echo esc_html( $word ); ?>」が出てくるコラム</h2>
			<ul class="snscs-glossary__links">
				<?php foreach ( $cols as $col ) : ?>
					<li><a href="<?php echo esc_url( get_permalink( $col ) ); ?>"><?php echo esc_html( get_the_title( $col ) ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<div class="snscs-glossary__cta">
			<p><strong>言葉の意味がわかったら、実際にやってみましょう</strong></p>
			<p>
				<?php if ( SNSCS_Hub::page_url( 'profile-check' ) ) : ?>
					<a class="snscs-button snscs-button--primary" href="<?php echo esc_url( SNSCS_Hub::page_url( 'profile-check' ) ); ?>">AIでプロフィールを診断する</a>
				<?php endif; ?>
				<?php if ( SNSCS_Hub::page_url( 'tools' ) ) : ?>
					<a class="snscs-button" href="<?php echo esc_url( SNSCS_Hub::page_url( 'tools' ) ); ?>">SNS運用の無料ツール</a>
				<?php endif; ?>
				<?php if ( SNSCS_Hub::page_url( 'qa' ) ) : ?>
					<a class="snscs-button" href="<?php echo esc_url( SNSCS_Hub::page_url( 'qa' ) ); ?>">発信者に質問する</a>
				<?php endif; ?>
			</p>
		</div>
		<p><a href="<?php echo esc_url( SNSCS_Hub::page_url( 'glossary' ) ); ?>">← 用語の一覧へ</a></p>
		<?php
	}

	/* --------------------------------------------------------------------
	 * サイトマップ
	 * ------------------------------------------------------------------ */

	public static function sitemap_urls( $offset, $limit ) {
		return array_map(
			function ( $t ) {
				return self::url( $t['slug'] );
			},
			array_slice( self::all(), $offset, $limit )
		);
	}

	public static function sitemap_count() {
		return SNSCS_Hub::page_id( 'glossary' ) ? count( self::all() ) : 0;
	}

	/* --------------------------------------------------------------------
	 * 管理画面: 読み・英語名・関連する用語
	 * ------------------------------------------------------------------ */

	public static function add_meta_box() {
		add_meta_box( 'snscs-term-meta', '用語の情報', array( __CLASS__, 'render_meta_box' ), self::POST_TYPE, 'side', 'high' );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'snscs_term_meta', 'snscs_term_nonce' );
		?>
		<p><label>読み（ひらがな。並べる順に使います）<br><input type="text" name="snscs_yomi" class="widefat" value="<?php echo esc_attr( get_post_meta( $post->ID, self::META_YOMI, true ) ); ?>"></label></p>
		<p><label>英語名<br><input type="text" name="snscs_term_en" class="widefat" value="<?php echo esc_attr( get_post_meta( $post->ID, self::META_EN, true ) ); ?>"></label></p>
		<p><label>関連する用語（スラッグをカンマ区切り）<br><input type="text" name="snscs_related" class="widefat" value="<?php echo esc_attr( get_post_meta( $post->ID, self::META_RELATED, true ) ); ?>"></label></p>
		<p class="description">URLは「スラッグ」（英語の小文字・ハイフン）で決まります。抜粋は「ひとことで言うと」に使います。</p>
		<?php
	}

	public static function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['snscs_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_term_nonce'] ) ), 'snscs_term_meta' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$yomi = isset( $_POST['snscs_yomi'] ) ? mb_convert_kana( sanitize_text_field( wp_unslash( $_POST['snscs_yomi'] ) ), 'cHV' ) : '';
		update_post_meta( $post_id, self::META_YOMI, $yomi );
		update_post_meta( $post_id, self::META_EN, isset( $_POST['snscs_term_en'] ) ? sanitize_text_field( wp_unslash( $_POST['snscs_term_en'] ) ) : '' );
		$related = isset( $_POST['snscs_related'] ) ? array_filter( array_map( 'sanitize_title', explode( ',', sanitize_text_field( wp_unslash( $_POST['snscs_related'] ) ) ) ) ) : array();
		update_post_meta( $post_id, self::META_RELATED, implode( ',', $related ) );
	}

	public static function columns( $cols ) {
		return array_slice( $cols, 0, 2, true ) + array( 'snscs_yomi' => '読み', 'snscs_en' => '英語名' ) + array_slice( $cols, 2, null, true );
	}

	public static function column( $col, $post_id ) {
		if ( 'snscs_yomi' === $col ) {
			echo esc_html( get_post_meta( $post_id, self::META_YOMI, true ) );
		} elseif ( 'snscs_en' === $col ) {
			echo esc_html( get_post_meta( $post_id, self::META_EN, true ) );
		}
	}

	/* --------------------------------------------------------------------
	 * 管理画面: AIの下書き
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, '用語集のAI下書き', '用語集のAI下書き', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	private static function schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'terms' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'name'       => array( 'type' => 'string' ),
							'yomi'       => array( 'type' => 'string' ),
							'en'         => array( 'type' => 'string' ),
							'slug'       => array( 'type' => 'string' ),
							'short'      => array( 'type' => 'string' ),
							'paragraphs' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
							'related'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						),
						'required'             => array( 'name', 'yomi', 'en', 'slug', 'short', 'paragraphs', 'related' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'terms' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * 言葉の一覧から、下書きを作る。
	 *
	 * @param string[] $words 言葉（最大 AI_MAX）。
	 * @return int|WP_Error 作った数。
	 */
	public static function draft_with_ai( array $words ) {
		$words = array_slice( array_values( array_unique( array_filter( array_map( 'trim', $words ) ) ) ), 0, self::AI_MAX );
		if ( ! $words ) {
			return 0;
		}
		$have = implode( ', ', wp_list_pluck( self::all(), 'slug' ) );
		$system = 'あなたは日本のSNS運用の専門家で、初心者向けの用語集を書く編集者です。指定された言葉ごとに、次を作ってください。'
			. ' name: 用語の表記（一般的な表記）。 yomi: 読み（ひらがなだけ。英字の略語もひらがなの読みに）。 en: 英語での名前。 slug: 英語の小文字とハイフンだけのURL用の名前。'
			. ' short: 「ひとことで言うと」の説明（40字前後、「〜のこと。」で終える）。 paragraphs: 本文の段落（2〜3段落。各120〜200字。中学生にもわかる言葉で、意味・なぜ大切か・使い方のコツの順。不確かな数字や断定は書かない。日本の法律に触れるときは一般的な説明にとどめる）。'
			. ' related: 関連する用語のslug（既にあるもの: ' . $have . ' から選ぶ。最大4つ）。'
			. ' SNSと関係のない言葉や、説明すべきでない言葉は、その言葉を飛ばしてください。';
		$message = SNSCS_Claude::request(
			'POST',
			'/v1/messages',
			array(
				'model'         => self::MODEL,
				'max_tokens'    => 8000,
				'system'        => $system,
				'output_config' => array( 'format' => array( 'type' => 'json_schema', 'schema' => self::schema() ) ),
				'messages'      => array( array( 'role' => 'user', 'content' => "用語集に入れる言葉:\n" . implode( "\n", $words ) ) ),
			),
			array(),
			120
		);
		if ( is_wp_error( $message ) ) {
			return $message;
		}
		$data = json_decode( SNSCS_Claude::text_of( $message ), true );
		if ( ! is_array( $data ) || empty( $data['terms'] ) ) {
			return new WP_Error( 'snscs_glossary_ai', 'AIの答えを読めませんでした。' );
		}
		$made = 0;
		foreach ( $data['terms'] as $t ) {
			$slug = sanitize_title( $t['slug'] );
			$name = sanitize_text_field( $t['name'] );
			if ( '' === $slug || '' === $name || self::by_slug( $slug, 'any' ) ) {
				continue;
			}
			$paras = array_filter( array_map( 'sanitize_textarea_field', (array) $t['paragraphs'] ) );
			$id    = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => self::POST_TYPE,
						'post_status'  => 'draft',
						'post_title'   => $name,
						'post_name'    => $slug,
						'post_excerpt' => sanitize_text_field( $t['short'] ),
						'post_content' => '<p>' . implode( "</p>\n\n<p>", array_map( 'esc_html', $paras ) ) . '</p>',
					)
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				update_post_meta( $id, self::META_YOMI, mb_convert_kana( sanitize_text_field( $t['yomi'] ), 'cHV' ) );
				update_post_meta( $id, self::META_EN, sanitize_text_field( $t['en'] ) );
				$known = wp_list_pluck( self::all(), 'slug' );
				update_post_meta( $id, self::META_RELATED, implode( ',', array_slice( array_values( array_intersect( array_map( 'sanitize_title', (array) $t['related'] ), $known ) ), 0, 4 ) ) );
				$made++;
			}
		}
		return $made;
	}

	/**
	 * 用語集に入れるとよい言葉の候補（質問箱・トレンドでよく出る言葉から、まだ無いもの）。
	 *
	 * @return string[]
	 */
	public static function suggestions( $limit = 20 ) {
		$have = array_map( 'mb_strtolower', wp_list_pluck( self::all(), 'name' ) );
		$out  = array();
		if ( SNSCS_Hub::page_id( 'trends' ) ) {
			foreach ( SNSCS_Trend_Pages::indexable_terms( 100 ) as $term ) {
				if ( ! in_array( $term, $have, true ) && mb_strlen( $term ) >= 3 && preg_match( '/[ァ-ヶー]{3,}|[a-z]{3,}/u', $term ) ) {
					$out[] = $term;
				}
			}
		}
		return array_slice( $out, 0, $limit );
	}

	public static function handle_ai() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		check_admin_referer( 'snscs_glossary_ai', 'snscs_nonce' );
		$words = isset( $_POST['words'] ) ? preg_split( '/[\r\n,、]+/u', sanitize_textarea_field( wp_unslash( $_POST['words'] ) ) ) : array();
		$made  = self::draft_with_ai( (array) $words );
		$args  = is_wp_error( $made ) ? array( 'snscs_err' => rawurlencode( $made->get_error_message() ) ) : array( 'snscs_made' => (int) $made );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::ADMIN_PAGE ) ) );
		exit;
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$made = isset( $_GET['snscs_made'] ) ? absint( $_GET['snscs_made'] ) : null;
		$err  = isset( $_GET['snscs_err'] ) ? sanitize_text_field( wp_unslash( $_GET['snscs_err'] ) ) : '';
		// phpcs:enable
		$drafts = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'draft', 'numberposts' => 50 ) );
		$sugg   = self::suggestions();
		?>
		<div class="wrap">
			<h1>用語集のAI下書き</h1>
			<?php if ( null !== $made ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $made . '件の下書きを作りました。内容を確かめてから「公開」してください。' ); ?></p></div>
			<?php elseif ( '' !== $err ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $err ); ?></p></div>
			<?php endif; ?>
			<p>公開中の用語: <strong><?php echo esc_html( count( self::all() ) ); ?></strong>語 ・ <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) ); ?>">用語の一覧・編集</a> ・ <a href="<?php echo esc_url( SNSCS_Hub::page_url( 'glossary' ) ); ?>" target="_blank">用語集のページ</a></p>
			<?php if ( ! SNSCS_Claude::has_key() ) : ?>
				<p>AIの下書きには、「記事の自動投稿」の画面でAPIキーを設定してください。用語は「用語を追加」から手で書くこともできます。</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="snscs_glossary_ai">
					<?php wp_nonce_field( 'snscs_glossary_ai', 'snscs_nonce' ); ?>
					<p><label for="snscs-gl-words">用語集に入れたい言葉（1行に1つ、1回に<?php echo (int) self::AI_MAX; ?>つまで）</label></p>
					<textarea id="snscs-gl-words" name="words" rows="6" class="large-text" placeholder="例:&#10;エンゲージメント&#10;ストーリーズ"></textarea>
					<p><button class="button button-primary">AIで下書きを作る</button> <span class="description">下書きとして保存します。事実の誤りがないか、公開の前に必ず確かめてください。</span></p>
				</form>
			<?php endif; ?>
			<?php if ( $sugg ) : ?>
				<h2>候補（トレンドで検索が多く、用語集にまだ無い言葉）</h2>
				<p><?php echo esc_html( implode( '、', $sugg ) ); ?></p>
			<?php endif; ?>
			<?php if ( $drafts ) : ?>
				<h2>確認待ちの下書き</h2>
				<ul>
					<?php foreach ( $drafts as $d ) : ?>
						<li><a href="<?php echo esc_url( get_edit_post_link( $d->ID ) ); ?>"><?php echo esc_html( $d->post_title ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}
