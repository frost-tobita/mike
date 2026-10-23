<?php
/**
 * ブログやメディアに貼れる「人気の発信者ランキング」（埋め込み）。
 *
 * 貼り付けるコード:
 *   <div class="snscs-embed" data-api="…/wp-json/snscs/v1/embed?genre=…&sns=…&count=5" data-theme="light">
 *     <a href="（特集ページ）">料理のInstagram発信者ランキング｜サイト名</a>   ← スクリプトが動かなくても残る、ふつうのリンク
 *   </div>
 *   <script async src="…/?snscs_embed_js=1" charset="utf-8"></script>
 *
 * スクリプトは、貼った場所にランキング（順位・名前・フォロワー数）を描き、1時間ごとの最新を出す。
 * リンクには「どこから来たか」（snscs_src=embed）を付ける。どのサイトに貼られているかは、
 * 呼び出し元のドメインを（同じサイトは10分に1回）数え、作成ページで運営だけが見られる（上位50）。
 * 作成ページ: [sns_embed]（機能ページ「ブログに貼れるランキング」）。ジャンル・SNS・人数・色を選んでコードをコピー。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Embed {

	const JS_VAR = 'snscs_embed_js';
	const SITES  = 'snscs_embed_sites';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_js' ), 0 );
		add_shortcode( 'sns_embed', array( __CLASS__, 'shortcode' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'snscs/v1',
			'/embed',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest' ),
				'args'                => array(
					'genre' => array( 'type' => 'string', 'default' => '' ),
					'sns'   => array( 'type' => 'string', 'default' => '' ),
					'count' => array( 'type' => 'integer', 'default' => 5 ),
				),
			)
		);
	}

	/**
	 * ランキングの中身（1時間ごとに作り直す）。
	 */
	public static function data( $genre_slug, $sns, $count ) {
		$genre = '' !== $genre_slug ? get_term_by( 'slug', sanitize_title( $genre_slug ), SNSCS_Post_Types::TAX_GENRE ) : null;
		$sns   = array_key_exists( $sns, snscs_platforms() ) ? $sns : '';
		$count = max( 3, min( 10, (int) $count ) );
		$key   = 'snscs_embed_' . md5( ( $genre ? $genre->slug : '' ) . '|' . $sns . '|' . $count );
		$data  = get_transient( $key );
		if ( is_array( $data ) ) {
			return $data;
		}
		$result = SNSCS_Search::run( array( 'genre' => $genre ? $genre->slug : '', 'platform' => $sns, 'sort' => 'recommended', 'per_page' => $count ) );
		$items  = array();
		foreach ( $result['items'] as $i => $item ) {
			$best = null;
			foreach ( $item['accounts'] as $a ) {
				if ( ( '' === $sns || $a['platform'] === $sns ) && ( ! $best || $a['followers'] > $best['followers'] ) ) {
					$best = $a;
				}
			}
			$items[] = array(
				'rank'      => $i + 1,
				'name'      => html_entity_decode( $item['name'], ENT_QUOTES, 'UTF-8' ),
				'url'       => add_query_arg( 'snscs_src', 'embed', $item['url'] ),
				'image'     => $item['thumbnail'],
				'followers' => $best ? $best['platform_label'] . ' ' . $best['followers_label'] : '',
			);
		}
		$data = array(
			'title' => self::title( $genre, $sns ),
			'url'   => add_query_arg( 'snscs_src', 'embed', SNSCS_Features::url( $sns, $genre ? $genre->slug : '' ) ),
			'site'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'home'  => add_query_arg( 'snscs_src', 'embed', home_url( '/' ) ),
			'items' => $items,
		);
		set_transient( $key, $data, HOUR_IN_SECONDS );
		return $data;
	}

	public static function title( $genre, $sns ) {
		return ( $genre ? $genre->name . 'の' : '' ) . ( '' !== $sns ? snscs_platform_label( $sns ) : 'SNS' ) . '発信者ランキング';
	}

	public static function rest( WP_REST_Request $request ) {
		self::count_site();
		$response = new WP_REST_Response( self::data( (string) $request['genre'], sanitize_key( (string) $request['sns'] ), (int) $request['count'] ), 200 );
		$response->header( 'Access-Control-Allow-Origin', '*' );
		$response->header( 'Cache-Control', 'public, max-age=600' );
		return $response;
	}

	/**
	 * どのサイトに貼られているか（呼び出し元のドメインを日ごとに数える。自分のサイトは除く）。
	 */
	private static function count_site() {
		$from = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) $_SERVER['HTTP_ORIGIN'] : ( isset( $_SERVER['HTTP_REFERER'] ) ? (string) $_SERVER['HTTP_REFERER'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$host = strtolower( (string) wp_parse_url( $from, PHP_URL_HOST ) );
		if ( '' === $host || wp_parse_url( home_url(), PHP_URL_HOST ) === $host || ! preg_match( '/^[a-z0-9.-]{3,100}$/', $host ) ) {
			return;
		}
		// 同じサイトは10分に1回だけ数える（人気のサイトに貼られても、書き込みが増えすぎないように）。
		$seen = 'snscs_embed_seen_' . md5( $host );
		if ( get_transient( $seen ) ) {
			return;
		}
		set_transient( $seen, 1, 10 * MINUTE_IN_SECONDS );
		$sites          = (array) get_option( self::SITES, array() );
		$sites[ $host ] = array( 'count' => (int) ( $sites[ $host ]['count'] ?? 0 ) + 1, 'last' => current_time( 'Y-m-d' ) );
		uasort(
			$sites,
			function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);
		update_option( self::SITES, array_slice( $sites, 0, 50, true ), false );
	}

	public static function js_url() {
		return add_query_arg( self::JS_VAR, 1, home_url( '/' ) );
	}

	public static function api_url( $genre_slug, $sns, $count ) {
		return add_query_arg( array_filter( array( 'genre' => $genre_slug, 'sns' => $sns, 'count' => (int) $count ) ), rest_url( 'snscs/v1/embed' ) );
	}

	/**
	 * 貼り付け用のコード。
	 */
	public static function code( $genre_slug, $sns, $count, $theme ) {
		$genre = '' !== $genre_slug ? get_term_by( 'slug', $genre_slug, SNSCS_Post_Types::TAX_GENRE ) : null;
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return sprintf(
			'<div class="snscs-embed" data-api="%s" data-theme="%s"><a href="%s">%s｜%s</a></div>' . "\n" . '<script async src="%s" charset="utf-8"></script>',
			esc_url( self::api_url( $genre ? $genre->slug : '', $sns, $count ) ),
			'dark' === $theme ? 'dark' : 'light',
			esc_url( SNSCS_Features::url( $sns, $genre ? $genre->slug : '' ) ),
			esc_html( self::title( $genre, $sns ) ),
			esc_html( $site ),
			esc_url( self::js_url() )
		);
	}

	/**
	 * 貼ったページで動くスクリプト（ほかのサイトのページを壊さないよう、名前と見た目を閉じ込める）。
	 */
	public static function serve_js() {
		if ( empty( $_GET[ self::JS_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: public, max-age=86400' );
		header( 'X-Content-Type-Options: nosniff' );
		echo <<<'JS'
(function () {
	if (!window.fetch) { return; }
	var css = '.snscs-embed__box{font-family:system-ui,-apple-system,"Hiragino Sans","Noto Sans JP",sans-serif;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;max-width:420px;background:#fff;color:#111827;line-height:1.5}'
		+ '.snscs-embed__box.is-dark{background:#111827;color:#f9fafb;border-color:#374151}'
		+ '.snscs-embed__title{font-weight:700;font-size:15px;margin:0 0 8px}'
		+ '.snscs-embed__list{list-style:none;margin:0;padding:0}'
		+ '.snscs-embed__item{display:flex;align-items:center;gap:10px;padding:6px 0;border-top:1px solid rgba(127,127,127,.2)}'
		+ '.snscs-embed__rank{flex:0 0 22px;font-weight:800;color:#4f46e5;text-align:center}'
		+ '.snscs-embed__img{width:36px;height:36px;border-radius:50%;object-fit:cover;background:#e0e7ff;flex:0 0 36px}'
		+ '.snscs-embed__name{font-weight:700;color:inherit;text-decoration:none}'
		+ '.snscs-embed__name:hover{text-decoration:underline}'
		+ '.snscs-embed__meta{display:block;font-size:12px;opacity:.7}'
		+ '.snscs-embed__foot{margin:8px 0 0;font-size:12px;text-align:right}'
		+ '.snscs-embed__foot a{color:#4f46e5}';
	if (!document.getElementById('snscs-embed-style')) {
		var style = document.createElement('style');
		style.id = 'snscs-embed-style';
		style.textContent = css;
		document.head.appendChild(style);
	}
	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text) { n.textContent = text; }
		return n;
	}
	Array.prototype.forEach.call(document.querySelectorAll('.snscs-embed[data-api]:not([data-done])'), function (box) {
		box.setAttribute('data-done', '1');
		fetch(box.getAttribute('data-api')).then(function (r) { return r.json(); }).then(function (data) {
			if (!data || !data.items || !data.items.length) { return; }
			var wrap = el('div', 'snscs-embed__box' + (box.getAttribute('data-theme') === 'dark' ? ' is-dark' : ''));
			wrap.appendChild(el('p', 'snscs-embed__title', data.title));
			var list = el('ol', 'snscs-embed__list');
			data.items.forEach(function (item) {
				var li = el('li', 'snscs-embed__item');
				li.appendChild(el('span', 'snscs-embed__rank', String(item.rank)));
				if (item.image) {
					var img = el('img', 'snscs-embed__img');
					img.src = item.image; img.alt = ''; img.loading = 'lazy'; img.width = 36; img.height = 36;
					li.appendChild(img);
				} else {
					li.appendChild(el('span', 'snscs-embed__img'));
				}
				var body = el('span');
				var a = el('a', 'snscs-embed__name', item.name);
				a.href = item.url; a.target = '_blank'; a.rel = 'noopener';
				body.appendChild(a);
				if (item.followers) { body.appendChild(el('span', 'snscs-embed__meta', item.followers)); }
				li.appendChild(body);
				list.appendChild(li);
			});
			wrap.appendChild(list);
			// 元のリンク（タイトルと出典）は残す。
			var foot = el('p', 'snscs-embed__foot');
			var original = box.querySelector('a');
			if (original) { original.textContent = 'もっと見る｜' + data.site; foot.appendChild(original); }
			wrap.appendChild(foot);
			box.innerHTML = '';
			box.appendChild(wrap);
		}).catch(function () {});
	});
})();
JS;
		exit;
	}

	/**
	 * 作成ページ。
	 */
	public static function shortcode() {
		SNSCS_Hub::remember_page( 'embed' );
		wp_enqueue_style( 'snscs' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$genre = isset( $_GET['eg'] ) ? get_term_by( 'slug', sanitize_title( wp_unslash( $_GET['eg'] ) ), SNSCS_Post_Types::TAX_GENRE ) : null;
		$sns   = isset( $_GET['es'] ) && array_key_exists( sanitize_key( $_GET['es'] ), snscs_platforms() ) ? sanitize_key( $_GET['es'] ) : '';
		$count = isset( $_GET['en'] ) ? max( 3, min( 10, absint( $_GET['en'] ) ) ) : 5;
		$theme = isset( $_GET['et'] ) && 'dark' === $_GET['et'] ? 'dark' : 'light';
		// phpcs:enable
		$code   = self::code( $genre ? $genre->slug : '', $sns, $count, $theme );
		$genres = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => true ) );
		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		?>
		<div class="snscs-embedmaker">
			<p class="snscs-feature__lead">ブログやメディアの記事に、人気のSNS発信者ランキングを無料で貼れます。中身は毎日自動で新しくなります。まとめ記事や、紹介記事の補足にどうぞ。</p>
			<form method="get" class="snscs-collab__filter">
				<select name="eg" aria-label="ジャンル"><option value="">すべてのジャンル</option>
					<?php foreach ( is_wp_error( $genres ) ? array() : $genres as $g ) : ?>
						<option value="<?php echo esc_attr( urldecode( $g->slug ) ); ?>" <?php selected( $genre && $genre->term_id === $g->term_id ); ?>><?php echo esc_html( $g->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="es" aria-label="SNS"><option value="">すべてのSNS</option>
					<?php foreach ( snscs_platforms() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $sns, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="en" aria-label="人数">
					<?php foreach ( array( 3, 5, 10 ) as $n ) : ?>
						<option value="<?php echo (int) $n; ?>" <?php selected( $count, $n ); ?>><?php echo (int) $n; ?>人</option>
					<?php endforeach; ?>
				</select>
				<select name="et" aria-label="色">
					<option value="light" <?php selected( $theme, 'light' ); ?>>明るい色</option>
					<option value="dark" <?php selected( $theme, 'dark' ); ?>>暗い色</option>
				</select>
				<button type="submit" class="snscs-button">見た目を確かめる</button>
			</form>
			<h2 class="snscs-trendpage__title">見本</h2>
			<?php echo $code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- code() でエスケープ済み。 ?>
			<h2 class="snscs-trendpage__title">貼り付けるコード</h2>
			<textarea id="snscs-embed-code" rows="6" readonly class="snscs-input" onclick="this.select();"><?php echo esc_textarea( $code ); ?></textarea>
			<p><button type="button" class="snscs-button snscs-button--primary" onclick="var t=document.getElementById('snscs-embed-code');t.select();navigator.clipboard&&navigator.clipboard.writeText(t.value);this.textContent='コピーしました';">コードをコピー</button></p>
			<p class="snscs-help">WordPress なら「カスタムHTML」ブロックに貼ってください。ランキングは1時間ごとに新しくなります。</p>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<?php $sites = (array) get_option( self::SITES, array() ); ?>
				<h2 class="snscs-trendpage__title">貼られているサイト（運営だけに表示）</h2>
				<?php if ( $sites ) : ?>
					<ul>
						<?php foreach ( $sites as $host => $info ) : ?>
							<li><?php echo esc_html( $host . '（表示 ' . number_format_i18n( $info['count'] ) . '回・最後 ' . $info['last'] . '）' ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="snscs-help">まだありません。</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
