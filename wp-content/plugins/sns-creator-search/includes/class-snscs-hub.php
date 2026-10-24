<?php
/**
 * 集客・回遊のための機能ページと、その設定（管理画面「発信者 → 集客・回遊」）。
 *
 * 機能ページ（固定ページにショートコードを書いたもの。ボタン1つでまとめて作れる）:
 *   ランキング / 特集（ジャンル×SNS×地域の自動ページ）/ 無料ツール / お気に入り / カレンダー / メールマガジン / 企業の方へ
 *
 * どのページの上にも「サイト内ナビ」（検索・ランキング・特集・ツール・お気に入り）を出し、ページ間を行き来しやすくする。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Hub {

	const OPTION     = 'snscs_hub_settings';
	const PAGES      = 'snscs_hub_pages';
	const ADMIN_PAGE = 'snscs-hub';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_snscs_hub_pages', array( __CLASS__, 'handle_create_pages' ) );
		add_action( 'admin_post_snscs_hub_settings', array( __CLASS__, 'handle_save' ) );
		add_shortcode( 'sns_quick_nav', array( __CLASS__, 'quick_nav' ) );
	}

	/**
	 * 機能ページの一覧。
	 *
	 * @return array<string,array{title:string,slug:string,shortcode:string,nav:string}>
	 */
	public static function pages() {
		return array(
			'ranking'    => array( 'title' => '発信者ランキング', 'slug' => 'ranking', 'shortcode' => '[sns_ranking]', 'nav' => 'ランキング' ),
			'features'   => array( 'title' => '特集から探す', 'slug' => 'find', 'shortcode' => '[sns_features]', 'nav' => '特集' ),
			'tools'      => array( 'title' => 'SNS運用の無料ツール', 'slug' => 'tools', 'shortcode' => '[sns_tools]', 'nav' => '無料ツール' ),
			'favorites'  => array( 'title' => 'お気に入りの発信者', 'slug' => 'favorites', 'shortcode' => '[sns_favorites]', 'nav' => 'お気に入り' ),
			'stories'    => array( 'title' => 'みんなの記事', 'slug' => 'stories', 'shortcode' => '[sns_stories]', 'nav' => 'みんなの記事' ),
			'trends'     => array( 'title' => 'トレンド', 'slug' => 'trends', 'shortcode' => '[sns_trends]', 'nav' => 'トレンド' ),
			'shindan'    => array( 'title' => 'あなたに合う発信者診断', 'slug' => 'shindan', 'shortcode' => '[sns_shindan]', 'nav' => '発信者診断' ),
			'awards'     => array( 'title' => '発信者アワード（ファン投票）', 'slug' => 'awards', 'shortcode' => '[sns_awards]', 'nav' => 'アワード' ),
			'collab'     => array( 'title' => 'コラボ相手探し', 'slug' => 'collab', 'shortcode' => '[sns_collab]', 'nav' => '' ),
			'similar'    => array( 'title' => '似ている発信者', 'slug' => 'similar', 'shortcode' => '[sns_similar]', 'nav' => '' ),
			'lists'      => array( 'title' => 'みんなの推しリスト', 'slug' => 'lists', 'shortcode' => '[sns_lists]', 'nav' => '推しリスト' ),
			'qa'         => array( 'title' => 'SNS運用の質問箱', 'slug' => 'qa', 'shortcode' => '[sns_qa]', 'nav' => '質問箱' ),
			'profile-check' => array( 'title' => 'SNSプロフィールAI診断', 'slug' => 'profile-check', 'shortcode' => '[sns_profile_check]', 'nav' => 'AIプロフィール診断' ),
			'press'      => array( 'title' => 'プレス・データ', 'slug' => 'press', 'shortcode' => '[sns_press]', 'nav' => '' ),
			'glossary'   => array( 'title' => 'SNS用語集', 'slug' => 'glossary', 'shortcode' => '[sns_glossary]', 'nav' => '用語集' ),
			'mylink'     => array( 'title' => 'マイリンク（無料のリンクまとめ）', 'slug' => 'mylink', 'shortcode' => '[sns_mylink]', 'nav' => '' ),
			'embed'      => array( 'title' => 'ブログに貼れるランキング', 'slug' => 'blog-parts', 'shortcode' => '[sns_embed]', 'nav' => '' ),
			'member'     => array( 'title' => '会員ページ', 'slug' => 'member', 'shortcode' => '[sns_member]', 'nav' => 'フォロー中' ),
			'calendar'   => array( 'title' => 'SNS発信カレンダー', 'slug' => 'calendar', 'shortcode' => '[sns_calendar]', 'nav' => 'カレンダー' ),
			'newsletter' => array( 'title' => 'メールマガジン', 'slug' => 'newsletter', 'shortcode' => '[sns_newsletter]', 'nav' => '' ),
			'business'   => array( 'title' => '企業の方へ（お仕事のご相談）', 'slug' => 'business', 'shortcode' => '[sns_business]', 'nav' => '' ),
		);
	}

	/**
	 * 作成済みの機能ページのID（無い・ゴミ箱のものは 0）。
	 */
	public static function page_id( $key ) {
		$ids = (array) get_option( self::PAGES, array() );
		$id  = isset( $ids[ $key ] ) ? (int) $ids[ $key ] : 0;
		return ( $id && 'page' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) ? $id : 0;
	}

	/**
	 * 公開中の機能ページのURL（無ければ空文字）。
	 */
	public static function page_url( $key ) {
		$id = self::page_id( $key );
		return ( $id && 'publish' === get_post_status( $id ) ) ? get_permalink( $id ) : '';
	}

	/**
	 * ショートコードが表示されたページを、その機能のページとして覚える（手で作ったページにも対応）。
	 */
	public static function remember_page( $key ) {
		if ( ! is_singular( 'page' ) || ! in_the_loop() ) {
			return;
		}
		$ids = (array) get_option( self::PAGES, array() );
		if ( empty( $ids[ $key ] ) || (int) $ids[ $key ] !== get_the_ID() ) {
			if ( ! empty( $ids[ $key ] ) && self::page_id( $key ) ) {
				return;
			}
			$ids[ $key ] = get_the_ID();
			update_option( self::PAGES, $ids );
			do_action( 'snscs_hub_page_changed', $key );
		}
	}

	/* --------------------------------------------------------------------
	 * 設定
	 * ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'personalize'        => 1,
			'personal_strength'  => 30,
			'feature_min'        => 3,
			'ogp'                => 1,
			'share_font'         => '',
			'trend_min_visitors' => 3,
			'search_trend_count' => 8,
			'founders_limit'     => 1000,
			'founder_tiers'      => array( 50, 200, 500 ),
			'portal_scope'       => 'plugin',
			'brand_on'           => 1,
			'brand_tagline'      => 'SNS特化型サーチエンジン',
			'hero_title'         => 'SNSで活躍する発信者が、きっと見つかる',
			'hero_text'          => 'X・YouTube・Instagram・TikTokなどの発信者を、ジャンルやSNSから探せます。',
			'trend_block'        => '',
			'trend_alerts'       => 1,
			'newsletter_auto'    => 0,
			'newsletter_day'     => 1,
			'newsletter_hour'    => 8,
			'inquiry_copy_admin' => 1,
			'quick_nav'          => 1,
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function setting( $key ) {
		$settings = self::settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/* --------------------------------------------------------------------
	 * サイト内ナビ
	 * ------------------------------------------------------------------ */

	/**
	 * ページを見分けるための形: パスと、ページを決めるクエリ（page_id など）。
	 */
	public static function url_key( $url ) {
		$parts = wp_parse_url( (string) $url );
		$query = array();
		wp_parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$keep = array_intersect_key( $query, array_flip( array( 'page_id', 'p', 'pagename', 'post_type' ) ) );
		ksort( $keep );
		return untrailingslashit( (string) ( $parts['path'] ?? '' ) ) . ( $keep ? '?' . http_build_query( $keep ) : '' );
	}

	/**
	 * 検索・ランキング・特集・ツール・お気に入りへのリンク。いまいるページは強調する。
	 */
	public static function quick_nav() {
		if ( ! self::setting( 'quick_nav' ) ) {
			return '';
		}
		$links = array( 'search' => array( snscs_search_url(), '発信者を探す' ) );
		foreach ( self::pages() as $key => $page ) {
			$url = '' !== $page['nav'] ? self::page_url( $key ) : '';
			if ( $url ) {
				$links[ $key ] = array( $url, $page['nav'] );
			}
		}
		if ( count( $links ) < 2 ) {
			return '';
		}
		wp_enqueue_style( 'snscs' );
		// パーマリンクが「基本」（?page_id=17 の形）でも、ページごとに見分ける（どのURLも「/」なので、パスだけで比べると全部が「いまのページ」になる）。
		$current = isset( $_SERVER['REQUEST_URI'] ) ? self::url_key( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$html    = '<nav class="snscs-quicknav" aria-label="サイト内ナビ"><ul>';
		foreach ( $links as $key => $link ) {
			$here  = self::url_key( $link[0] ) === $current;
			$html .= sprintf(
				'<li><a class="snscs-quicknav__link snscs-quicknav__link--%s" href="%s"%s>%s</a></li>',
				esc_attr( $key ),
				esc_url( $link[0] ),
				$here ? ' aria-current="page"' : '',
				esc_html( $link[1] )
			);
		}
		return $html . '</ul></nav>';
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, '集客・回遊', '集客・回遊', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	public static function admin_url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::ADMIN_PAGE ) );
	}

	/**
	 * 機能ページをまとめて作る（作成済みのものは作らない）。
	 *
	 * @param string[]|null $only 作るページ（null ならすべて）。
	 * @return int 作った数。
	 */
	public static function create_pages( $only = null ) {
		$ids     = (array) get_option( self::PAGES, array() );
		$created = 0;
		foreach ( self::pages() as $key => $page ) {
			if ( self::page_id( $key ) || ( null !== $only && ! in_array( $key, $only, true ) ) ) {
				continue;
			}
			$id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'page',
						'post_title'   => $page['title'],
						'post_name'    => $page['slug'],
						'post_content' => '<!-- wp:shortcode -->' . $page['shortcode'] . '<!-- /wp:shortcode -->',
						'post_status'  => 'publish',
					)
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				$ids[ $key ] = (int) $id;
				$created++;
			}
		}
		update_option( self::PAGES, $ids );
		do_action( 'snscs_hub_page_changed', 'all' );
		return $created;
	}

	public static function handle_create_pages() {
		self::verify( 'snscs_hub_pages' );
		$created = self::create_pages();
		wp_safe_redirect( self::admin_url( array( 'snscs_created' => $created ) ) );
		exit;
	}

	public static function handle_save() {
		self::verify( 'snscs_hub_settings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verify() で確認済み。
		$in       = wp_unslash( $_POST );
		$settings = self::settings();
		foreach ( array( 'personalize', 'ogp', 'newsletter_auto', 'inquiry_copy_admin', 'quick_nav', 'trend_alerts', 'brand_on' ) as $key ) {
			$settings[ $key ] = empty( $in[ $key ] ) ? 0 : 1;
		}
		$settings['personal_strength']  = min( 80, max( 0, (int) ( $in['personal_strength'] ?? 30 ) ) );
		$settings['feature_min']        = min( 50, max( 1, (int) ( $in['feature_min'] ?? 3 ) ) );
		$settings['trend_min_visitors'] = min( 100, max( 1, (int) ( $in['trend_min_visitors'] ?? 3 ) ) );
		$settings['search_trend_count'] = min( 20, max( 0, (int) ( $in['search_trend_count'] ?? 8 ) ) );
		$settings['founders_limit']     = min( 100000, max( 0, (int) ( $in['founders_limit'] ?? 1000 ) ) );
		$tiers                          = array_map( 'absint', array_slice( array_values( (array) ( $in['founder_tiers'] ?? array() ) ), 0, 3 ) );
		$settings['founder_tiers']      = 3 === count( $tiers ) && $tiers[0] > 0 && $tiers[0] < $tiers[1] && $tiers[1] < $tiers[2] ? $tiers : array( 50, 200, 500 );
		$settings['brand_tagline']      = mb_substr( sanitize_text_field( (string) ( $in['brand_tagline'] ?? '' ) ), 0, 40 );
		$settings['portal_scope']       = in_array( $in['portal_scope'] ?? '', array( 'top', 'plugin', 'site' ), true ) ? $in['portal_scope'] : 'plugin';
		$settings['hero_title']         = mb_substr( sanitize_text_field( (string) ( $in['hero_title'] ?? '' ) ), 0, 60 );
		$settings['hero_text']          = mb_substr( sanitize_text_field( (string) ( $in['hero_text'] ?? '' ) ), 0, 120 );
		if ( '' === $settings['hero_title'] ) {
			$settings['hero_title'] = self::defaults()['hero_title'];
		}
		SNSCS_Portal_Top::forget_counts();
		$settings['newsletter_day']     = min( 6, max( 0, (int) ( $in['newsletter_day'] ?? 1 ) ) );
		$settings['newsletter_hour']    = min( 23, max( 0, (int) ( $in['newsletter_hour'] ?? 8 ) ) );
		$settings['trend_block']        = sanitize_textarea_field( (string) ( $in['trend_block'] ?? '' ) );
		$font                           = trim( sanitize_text_field( (string) ( $in['share_font'] ?? '' ) ) );
		$settings['share_font']         = ( '' === $font || ( preg_match( '/\.(ttf|otf|ttc)$/i', $font ) && is_readable( $font ) ) ) ? $font : $settings['share_font'];
		// phpcs:enable
		update_option( self::OPTION, $settings );
		SNSCS_Newsletter::schedule( true );
		wp_safe_redirect( self::admin_url( array( 'snscs_saved' => 1 ) ) );
		exit;
	}

	private static function verify( $action ) {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), $action ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = self::settings();
		$days = array( '日', '月', '火', '水', '木', '金', '土' );
		$font = SNSCS_Share::font();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1>集客・回遊</h1>
			<?php if ( isset( $_GET['snscs_created'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( '機能ページを%d件作成しました。', (int) $_GET['snscs_created'] ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['snscs_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['snscs_msg'] ) && 'report' === $_GET['snscs_msg'] ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( '成長レポートを%d人分作成しました。', isset( $_GET['n'] ) ? (int) $_GET['n'] : 0 ) ); ?></p></div>
			<?php endif; ?>
			<?php // phpcs:enable ?>

			<h2>月間成長レポート</h2>
			<?php $built = get_option( 'snscs_report_built' ); ?>
			<p>毎月1日の朝に、前の月の数字をまとめた画像を発信者ごとに作り、お知らせ（サイト内・メール・スマホ通知）で届けます。発信者がSNSでシェアすると、見た人がサイトに来ます。<?php echo $built ? esc_html( sprintf( '前回: %s分を%s人に作成（%s）。', $built['month'], number_format_i18n( $built['count'] ), $built['at'] ) ) : ''; ?></p>
			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
				<input type="hidden" name="action" value="snscs_report_build">
				<?php wp_nonce_field( 'snscs_report_build', 'snscs_nonce' ); ?>
				<label>対象の月 <input type="month" name="month" value="<?php echo esc_attr( gmdate( 'Y-m', strtotime( current_time( 'Y-m-01' ) . ' -1 month' ) ) ); ?>"></label>
				<label><input type="checkbox" name="notify" value="1"> 発信者にお知らせする</label>
				<button type="submit" class="button">今すぐ作成</button>
				<?php if ( ! SNSCS_Share::font() ) : ?>
					<p class="description" style="color:#b32d2e">日本語のフォントが見つからないため、画像を作れません。下の「シェア画像のフォント」を設定してください。</p>
				<?php endif; ?>
			</form>

			<h2>機能ページ</h2>
			<p>下のボタンで、まだ無いページをまとめて作成・公開します。作ったページはメニューやトップページからリンクしてください。</p>
			<table class="widefat striped" style="max-width: 900px">
				<thead><tr><th>ページ</th><th>ショートコード</th><th>状態</th></tr></thead>
				<tbody>
					<?php foreach ( self::pages() as $key => $page ) : ?>
						<?php $id = self::page_id( $key ); ?>
						<tr>
							<td><?php echo esc_html( $page['title'] ); ?></td>
							<td><code><?php echo esc_html( $page['shortcode'] ); ?></code></td>
							<td>
								<?php if ( $id ) : ?>
									<a href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank">表示</a> ・ <a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>">編集</a>
									<?php echo 'publish' !== get_post_status( $id ) ? '（非公開）' : ''; ?>
								<?php else : ?>
									未作成
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
				<input type="hidden" name="action" value="snscs_hub_pages">
				<?php wp_nonce_field( 'snscs_hub_pages', 'snscs_nonce' ); ?>
				<?php submit_button( '機能ページをまとめて作成', 'primary' ); ?>
			</form>

			<?php SNSCS_Portal_Top::render_admin_box(); ?>

			<h2>トップページに置けるショートコード</h2>
			<table class="widefat striped" style="max-width: 900px">
				<tbody>
					<tr><td><code>[sns_for_you]</code></td><td>あなたへのおすすめ（閲覧の傾向から予測）・最近見た発信者・おすすめのコラム</td></tr>
					<tr><td><code>[sns_trending]</code></td><td>人気・急上昇の検索キーワード</td></tr>
					<tr><td><code>[sns_spotlight]</code></td><td>今月の注目（急上昇の発信者と、招待ランキング上位のアンバサダー）</td></tr>
					<tr><td><code>[sns_feature_links]</code></td><td>人気の特集ページへのリンク</td></tr>
					<tr><td><code>[sns_calendar_link]</code></td><td>今日の日付と近い予定、「カレンダーを開く」ボタン</td></tr>
					<tr><td><code>[sns_newsletter_form]</code></td><td>メールマガジンの登録欄（小さめ）</td></tr>
					<tr><td><code>[sns_tool_engagement]</code> <code>[sns_tool_diagnosis]</code></td><td>無料ツールを個別に置く</td></tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
				<input type="hidden" name="action" value="snscs_hub_settings">
				<?php wp_nonce_field( 'snscs_hub_settings', 'snscs_nonce' ); ?>

				<h2>あなたへのおすすめ（パーソナライズ）</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">使う</th>
						<td><label><input type="checkbox" name="personalize" value="1" <?php checked( $s['personalize'] ); ?>> 見た発信者・読んだ記事・検索の傾向から、おすすめと「おすすめ順」の検索結果を一人ひとりに合わせる</label>
						<p class="description">傾向は訪問者のブラウザの中にだけ保存し、サーバーには個人ごとの記録を残しません。訪問者はいつでもオフにできます。</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="personal_strength">合わせる強さ</label></th>
						<td><input id="personal_strength" type="number" name="personal_strength" min="0" max="80" value="<?php echo (int) $s['personal_strength']; ?>" class="small-text"> %
						<p class="description">「おすすめ順」の点数のうち、何%を一人ひとりの好みで決めるか。残りは総合点（全員共通）です。初期値 30%。</p></td>
					</tr>
				</table>

				<h2>特集ページ・検索キーワード</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="feature_min">特集ページの最低人数</label></th>
						<td><input id="feature_min" type="number" name="feature_min" min="1" max="50" value="<?php echo (int) $s['feature_min']; ?>" class="small-text"> 人
						<p class="description">この人数より少ない組み合わせのページは、検索エンジンに載せない設定（noindex）にし、サイトマップにも入れません（中身の薄いページは評価を下げるため）。</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="trend_min_visitors">人気キーワードの条件</label></th>
						<td><input id="trend_min_visitors" type="number" name="trend_min_visitors" min="1" max="100" value="<?php echo (int) $s['trend_min_visitors']; ?>" class="small-text"> 人以上が検索した言葉だけ表示
						<p class="description">1人だけが調べた言葉（個人名など）が表に出ないようにするための条件です。</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="search_trend_count">検索窓の下のトレンドワード</label></th>
						<td><input id="search_trend_count" type="number" name="search_trend_count" min="0" max="20" value="<?php echo (int) $s['search_trend_count']; ?>" class="small-text"> 個まで表示
						<p class="description">トップページの検索窓のすぐ下に、いま検索が増えている言葉（急上昇）と人気の言葉を並べます。足りないときは発信者の多いジャンルで補います。0 にすると表示しません。初期値 8個。</p></td>
					</tr>
					<tr>
						<th scope="row">ブランド（FolloFind）</th>
						<td><label><input type="checkbox" name="brand_on" value="1" <?php checked( $s['brand_on'] ); ?>> ヘッダー・フッターに FolloFind のロゴを出し、ブラウザのタブとスマホのホーム画面にアイコンを出す</label>
						<p><label for="brand_tagline">ひとこと（ロゴの下）</label><br><input id="brand_tagline" type="text" name="brand_tagline" maxlength="40" value="<?php echo esc_attr( $s['brand_tagline'] ); ?>" class="regular-text"></p>
						<p class="description">WordPress の「サイトアイコン」を設定しているときは、タブのアイコンはそちらを使います。SNSでシェアされたときの画像は、<a href="<?php echo esc_url( SNSCS_Brand::url( 'follofind-og.jpg' ) ); ?>" target="_blank">こちら（1200×630）</a>を保存して、テーマやSEOのプラグインの「OGP画像」に設定してください。</p></td>
					</tr>
					<tr>
						<th scope="row">ポータルの見た目にするページ</th>
						<td>
							<?php foreach ( array( 'top' => 'トップページだけ（テンプレート「SNS検索ポータル（全幅）」を選んだページ）', 'plugin' => 'このプラグインのページすべて（おすすめ）: 機能ページ・検索・マイページ・会員ページ・規約・発信者・記事・コラム', 'site' => 'サイト全体（すべての固定ページ・投稿）' ) as $snscs_k => $snscs_l ) : ?>
								<label style="display:block;margin:4px 0"><input type="radio" name="portal_scope" value="<?php echo esc_attr( $snscs_k ); ?>" <?php checked( $s['portal_scope'], $snscs_k ); ?>> <?php echo esc_html( $snscs_l ); ?></label>
							<?php endforeach; ?>
							<p class="description">選んだページは、トップページと同じヘッダー・フッター・右の列で表示し、テーマ（AFFINGER など）の見た目は使いません。広告・アクセス解析のコードはそのまま動きます。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hero_title">トップの看板の見出し</label></th>
						<td><input id="hero_title" type="text" name="hero_title" maxlength="60" value="<?php echo esc_attr( $s['hero_title'] ); ?>" class="regular-text">
						<p><label for="hero_text">説明</label><br><input id="hero_text" type="text" name="hero_text" maxlength="120" value="<?php echo esc_attr( $s['hero_text'] ); ?>" class="large-text"></p>
						<p class="description">トップページの中央のいちばん上に出す、サイトの紹介です。掲載が10人を超えると、人数・ジャンル数も出します。</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="founders_limit">創設セイントの人数</label></th>
						<td>先着 <input id="founders_limit" type="number" name="founders_limit" min="0" max="100000" value="<?php echo (int) $s['founders_limit']; ?>" class="small-text"> 人
						<p class="description">最初に掲載された発信者から、この人数までに「創設セイント」のバッジを付けます（プロフィールとマイリンク）。掲載がこの人数に届くまで、トップページに募集の案内を出します。0 にすると、募集の案内もバッジも出しません。初期値 1000人。</p></td>
					</tr>
					<tr>
						<th scope="row">創設セイントのランク</th>
						<td>
							<?php $snscs_b = SNSCS_Founder::bounds(); $snscs_i = 0; ?>
							<?php foreach ( SNSCS_Founder::tiers() as $snscs_k => $snscs_r ) : ?>
								<div style="display: inline-flex; flex-direction: column; align-items: center; width: 150px; margin: 0 12px 12px 0; text-align: center; vertical-align: top">
									<span style="display: block; width: 110px; height: 110px"><?php echo SNSCS_Founder::medal_html( $snscs_r[0], $snscs_k ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 決まった画像と、エスケープした文字の形。 ?></span>
									<strong><?php echo esc_html( SNSCS_Founder::name( $snscs_k ) ); ?></strong>
									<span>Nº <?php echo esc_html( SNSCS_Founder::serial( $snscs_r[0] ) ); ?> 〜
										<?php if ( $snscs_i < 3 ) : ?>
											<input type="number" name="founder_tiers[]" min="1" max="100000" value="<?php echo (int) $snscs_b[ $snscs_i ]; ?>" style="width: 76px" aria-label="<?php echo esc_attr( SNSCS_Founder::name( $snscs_k ) . 'の最後の番号' ); ?>">
										<?php else : ?>
											<?php echo esc_html( SNSCS_Founder::serial( $snscs_r[1] ) ); ?>
										<?php endif; ?>
									</span>
								</div>
								<?php $snscs_i++; ?>
							<?php endforeach; ?>
							<p class="description">メダルの色は番号で決まります（上ほど貴重。初期値 ノワール 〜50・ミッドナイト 〜200・エメラルド 〜500・プラチナ 〜最後まで）。Nº 001〜010 は「FIRST 10」の刻印付き。番号（Nº 001〜）は掲載された順に一度だけ付き、あとから変わりません。メダルの下に「FOLLOFIND · Nº 001」のように入ります。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="trend_block">表示しない言葉</label></th>
						<td><textarea id="trend_block" name="trend_block" rows="4" class="large-text"><?php echo esc_textarea( $s['trend_block'] ); ?></textarea>
						<p class="description">1行に1つ。この言葉を含むキーワードは「人気のキーワード」に出しません。</p></td>
					</tr>
					<tr>
						<th scope="row">発信者へのトレンドのお知らせ</th>
						<td><label><input type="checkbox" name="trend_alerts" value="1" <?php checked( $s['trend_alerts'] ); ?>> ジャンルで急上昇した言葉を、そのジャンルの発信者に知らせる（お知らせ・スマホ通知。1日1回まで）</label>
						<p class="description">話題の記事が増え、トレンドのページが充実します。同じ言葉は14日間、同じジャンルにくり返し知らせません。</p></td>
					</tr>
				</table>

				<h2>シェア画像・バッジ</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">OGP</th>
						<td><label><input type="checkbox" name="ogp" value="1" <?php checked( $s['ogp'] ); ?>> 発信者ページに、SNSでシェアしたときの画像（順位入り）などの情報（OGP）を出力する</label>
						<p class="description">Yoast SEO・All in One SEO・Rank Math を使っている場合は、それらの画像だけを差し替えます。テーマ（AFFINGERなど）でOGPを出している場合は、二重にならないよう、どちらかをオフにしてください。</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="share_font">日本語フォント</label></th>
						<td><input id="share_font" type="text" name="share_font" value="<?php echo esc_attr( $s['share_font'] ); ?>" class="large-text" placeholder="/path/to/NotoSansJP-Bold.ttf">
						<p class="description">シェア画像に名前などの日本語を書くためのフォントファイル（.ttf / .otf / .ttc）のサーバー上の場所。空欄ならサーバーにあるものを自動で探します。
						現在使用中: <?php echo $font ? '<code>' . esc_html( $font ) . '</code>' : '<strong>見つかりません（画像には数字と英字だけが入ります）</strong>'; ?></p></td>
					</tr>
				</table>

				<h2>メールマガジン</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">自動配信</th>
						<td><label><input type="checkbox" name="newsletter_auto" value="1" <?php checked( $s['newsletter_auto'] ); ?>> 毎週、自動で配信する</label>
						<p class="description">中身は「今週伸びた発信者」「新しく掲載された発信者」「新しいコラム・記事」から自動で作ります。まず「メールマガジン」画面のテスト送信で確認してからオンにしてください。</p></td>
					</tr>
					<tr>
						<th scope="row">配信日時</th>
						<td>
							<select name="newsletter_day">
								<?php foreach ( $days as $i => $label ) : ?>
									<option value="<?php echo (int) $i; ?>" <?php selected( (int) $s['newsletter_day'], $i ); ?>><?php echo esc_html( $label ); ?>曜日</option>
								<?php endforeach; ?>
							</select>
							<select name="newsletter_hour">
								<?php for ( $h = 0; $h < 24; $h++ ) : ?>
									<option value="<?php echo (int) $h; ?>" <?php selected( (int) $s['newsletter_hour'], $h ); ?>><?php echo (int) $h; ?>時ごろ</option>
								<?php endfor; ?>
							</select>
						</td>
					</tr>
				</table>

				<h2>そのほか</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">お仕事の相談</th>
						<td><label><input type="checkbox" name="inquiry_copy_admin" value="1" <?php checked( $s['inquiry_copy_admin'] ); ?>> 企業からの相談が届いたら、運営にも知らせる</label></td>
					</tr>
					<tr>
						<th scope="row">サイト内ナビ</th>
						<td><label><input type="checkbox" name="quick_nav" value="1" <?php checked( $s['quick_nav'] ); ?>> 検索・ランキング・特集などのページの上に、ページ間を移動するリンクを出す</label></td>
					</tr>
				</table>
				<?php submit_button( '設定を保存' ); ?>
			</form>
		</div>
		<?php
	}
}
