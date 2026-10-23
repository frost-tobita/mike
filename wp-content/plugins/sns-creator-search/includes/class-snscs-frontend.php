<?php
/**
 * サイト表側: 検索フォームと結果一覧（ショートコード）、発信者ページのアカウント表示。
 *
 * 固定ページに [sns_creator_search] と書くと検索画面が表示される。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Frontend {

	/**
	 * URLのパラメーター名 => 検索条件のキー。
	 * WordPress 本体の `s` や `page` とぶつからないよう接頭辞を付ける。
	 */
	const PARAMS = array(
		'snscs_q'        => 'q',
		'snscs_platform' => 'platform',
		'snscs_genre'    => 'genre',
		'snscs_region'   => 'region',
		'snscs_min'      => 'min_followers',
		'snscs_max'      => 'max_followers',
		'snscs_sort'     => 'sort',
		'snscs_page'     => 'page',
	);

	public static function init() {
		add_shortcode( 'sns_creator_search', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'the_content', array( __CLASS__, 'append_accounts' ) );
	}

	public static function register_assets() {
		wp_register_style( 'snscs', SNSCS_URL . 'assets/css/snscs.css', array(), SNSCS_VERSION );
		if ( is_singular( SNSCS_Post_Types::POST_TYPE ) ) {
			wp_enqueue_style( 'snscs' );
		}
	}

	/**
	 * @return array<int,string> フォロワー数の選択肢（値 => 表示名）。
	 */
	private static function follower_options() {
		return array(
			1000    => '1,000人',
			10000   => '1万人',
			50000   => '5万人',
			100000  => '10万人',
			500000  => '50万人',
			1000000 => '100万人',
		);
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'per_page' => 20 ), $atts, 'sns_creator_search' );
		wp_enqueue_style( 'snscs' );

		// このページを「検索ページ」として覚える（コラムから検索へ案内するときに使う）。
		if ( is_singular() && (int) get_option( 'snscs_search_page_id' ) !== get_the_ID() ) {
			update_option( 'snscs_search_page_id', get_the_ID() );
		}

		$raw = array( 'per_page' => $atts['per_page'], 'log' => true );
		foreach ( self::PARAMS as $param => $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 閲覧用の検索フォームのため不要。
			if ( isset( $_GET[ $param ] ) ) {
				$raw[ $key ] = wp_unslash( $_GET[ $param ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}

		// おすすめ順は、閲覧の傾向があれば一人ひとりに合わせる（その場合はページをキャッシュさせない）。
		$profile = SNSCS_Personalize::profile();
		if ( $profile && ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		$result = SNSCS_Search::run( $raw, true, $profile ? array( 'profile' => $profile ) : null );
		$args   = $result['args'];
		SNSCS_Feedback::enqueue_search( isset( $result['qid'] ) ? $result['qid'] : '' );

		// 検索の傾向（選んだ条件・キーワード）を、この人の好みの記録と人気キーワードに使う。
		SNSCS_Personalize::set_search_context( $args );
		SNSCS_Personalize::print_search_context();
		if ( 1 === $args['page'] && '' !== $args['q'] ) {
			SNSCS_Trends::record( $args['q'], $result['total'] );
		}

		// 何も指定していない最初の画面か。
		$landing = '' === $args['q'] && '' === $args['genre'] && '' === $args['region'] && ! $args['min_followers'] && ! $args['max_followers'] && 1 === $args['page'];

		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		self::render_index( $result );
		self::render_form( $args );
		if ( $landing ) {
			echo SNSCS_Trends::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
			echo SNSCS_Personalize::placeholder( 'recent' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		}
		self::render_results( $result );
		echo SNSCS_Features::related_html( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		if ( $result['items'] ) {
			echo SNSCS_Personalize::placeholder( $landing ? 'foryou' : 'recent' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		}
		echo SNSCS_Columns::for_search( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo SNSCS_Newsletter::mini_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo SNSCS_Legal::links_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		return ob_get_clean();
	}

	/**
	 * SNSごとのインデックス（タブ）。各タブに、今の条件での該当人数を表示する。
	 */
	private static function render_index( array $result ) {
		$current = $result['args']['platform'];
		$tabs    = array_merge( array( '' => 'すべて' ), snscs_platforms() );
		?>
		<nav class="snscs-index" aria-label="SNSで切り替え">
			<ul class="snscs-index__list">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<?php
					$url   = remove_query_arg( 'snscs_page', add_query_arg( 'snscs_platform', $key ) );
					$count = isset( $result['counts'][ $key ] ) ? $result['counts'][ $key ] : 0;
					$class = 'snscs-index__tab snscs-index__tab--' . ( '' === $key ? 'all' : $key );
					?>
					<li>
						<a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $url ); ?>"<?php echo $key === $current ? ' aria-current="page"' : ''; ?>>
							<span class="snscs-index__label"><?php echo esc_html( $label ); ?></span>
							<span class="snscs-index__count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
	}

	private static function render_form( array $args ) {
		?>
		<form class="snscs-form" method="get" action="<?php echo esc_url( get_permalink() ); ?>">
			<?php // SNSはインデックス（タブ）で選ぶ。絞り込み直しても選んだタブを保つ。 ?>
			<input type="hidden" name="snscs_platform" value="<?php echo esc_attr( $args['platform'] ); ?>">
			<div class="snscs-form__row">
				<label class="snscs-field snscs-field--wide">
					<span>キーワード</span>
					<input type="search" name="snscs_q" value="<?php echo esc_attr( $args['q'] ); ?>" placeholder="名前・紹介文・ハンドルで探す">
				</label>
			</div>
			<div class="snscs-form__row">
				<label class="snscs-field">
					<span>ジャンル</span>
					<?php self::term_dropdown( 'snscs_genre', SNSCS_Post_Types::TAX_GENRE, $args['genre'] ); ?>
				</label>
				<label class="snscs-field">
					<span>地域</span>
					<?php self::term_dropdown( 'snscs_region', SNSCS_Post_Types::TAX_REGION, $args['region'] ); ?>
				</label>
			</div>
			<div class="snscs-form__row">
				<label class="snscs-field">
					<span>フォロワー数（以上）</span>
					<?php self::follower_dropdown( 'snscs_min', $args['min_followers'] ); ?>
				</label>
				<label class="snscs-field">
					<span>フォロワー数（以下）</span>
					<?php self::follower_dropdown( 'snscs_max', $args['max_followers'] ); ?>
				</label>
				<label class="snscs-field">
					<span>並び順</span>
					<select name="snscs_sort">
						<?php foreach ( SNSCS_Search::sort_options() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $args['sort'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
			<button type="submit" class="snscs-button">検索する</button>
		</form>
		<?php
	}

	private static function term_dropdown( $name, $taxonomy, $selected ) {
		wp_dropdown_categories(
			array(
				'taxonomy'        => $taxonomy,
				'name'            => $name,
				'id'              => $name,
				'value_field'     => 'slug',
				'selected'        => $selected,
				'show_option_all' => 'すべて',
				'option_none_value' => '',
				'hierarchical'    => true,
				'hide_empty'      => false,
				'orderby'         => 'name',
			)
		);
	}

	private static function follower_dropdown( $name, $selected ) {
		?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<option value="">指定なし</option>
			<?php foreach ( self::follower_options() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (int) $selected, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private static function render_results( array $result ) {
		// 一覧の途中の広告は、指定した人数のあとに1回だけ（その人数より少ないページには出さない）。
		$infeed_after = (int) SNSCS_Ads::settings()['infeed_after'];
		?>
		<div class="snscs-results">
			<p class="snscs-results__count">
				<?php echo esc_html( sprintf( '%s人の発信者が見つかりました', number_format_i18n( $result['total'] ) ) ); ?>
			</p>
			<?php if ( ! empty( $result['personalized'] ) ) : ?>
				<p class="snscs-results__personal">「おすすめ順」は、あなたがよく見るジャンルやSNSに合わせて並べています。<button type="button" class="snscs-linklike" data-snscs-personal-off>やめる</button></p>
			<?php endif; ?>

			<?php echo SNSCS_Ads::render( 'search_top' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 広告枠のHTML。 ?>

			<?php if ( empty( $result['items'] ) ) : ?>
				<?php self::render_empty( $result['args'], isset( $result['did_you_mean'] ) ? $result['did_you_mean'] : '' ); ?>
			<?php else : ?>
				<?php self::render_cards( $result['items'], $infeed_after ); ?>
				<?php self::render_pagination( $result ); ?>
				<?php echo SNSCS_Ads::render( 'search_bottom' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 広告枠のHTML。 ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * 発信者のカード一覧（検索結果・特集ページ・ランキングで使う）。
	 *
	 * @param array $items        SNSCS_Search::format_creator() の配列。
	 * @param int   $infeed_after 何人目のあとに広告（一覧の途中）を出すか。0 なら出さない。
	 * @param array $badges       発信者ID => カードの左上に出す文字（順位など）。
	 * @param array $notes        発信者ID => 名前の下に出す一言（「先月から↑3」など）。
	 */
	public static function render_cards( array $items, $infeed_after = 0, array $badges = array(), array $notes = array() ) {
		wp_enqueue_style( 'snscs' );
		?>
		<ul class="snscs-cards">
			<?php foreach ( $items as $index => $item ) : ?>
				<li class="snscs-card<?php echo isset( $badges[ $item['id'] ] ) ? ' snscs-card--ranked' : ''; ?>" data-snscs-cid="<?php echo (int) $item['id']; ?>">
					<?php if ( isset( $badges[ $item['id'] ] ) ) : ?>
						<span class="snscs-card__rank"><?php echo esc_html( $badges[ $item['id'] ] ); ?></span>
					<?php endif; ?>
					<?php if ( $item['thumbnail'] ) : ?>
						<img class="snscs-card__image" src="<?php echo esc_url( $item['thumbnail'] ); ?>" alt="" loading="lazy" width="64" height="64">
					<?php endif; ?>
					<div class="snscs-card__body">
						<h3 class="snscs-card__name"><a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['name'] ); ?></a> <?php echo SNSCS_Recommend::fav_button( $item['id'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済み。 ?></h3>
						<?php if ( isset( $notes[ $item['id'] ] ) ) : ?>
							<p class="snscs-card__note"><?php echo esc_html( $notes[ $item['id'] ] ); ?></p>
						<?php endif; ?>
						<?php if ( $item['genres'] || $item['regions'] ) : ?>
							<p class="snscs-card__tags">
								<?php foreach ( array_merge( $item['genres'], $item['regions'] ) as $tag ) : ?>
									<span class="snscs-tag"><?php echo esc_html( $tag ); ?></span>
								<?php endforeach; ?>
							</p>
						<?php endif; ?>
						<?php if ( $item['excerpt'] ) : ?>
							<p class="snscs-card__excerpt"><?php echo esc_html( wp_trim_words( $item['excerpt'], 60, '…' ) ); ?></p>
						<?php endif; ?>
						<?php self::render_accounts( $item['accounts'] ); ?>
					</div>
				</li>
				<?php if ( $infeed_after && $index + 1 === $infeed_after && $index + 1 < count( $items ) ) : ?>
					<?php $ad = SNSCS_Ads::render( 'search_infeed' ); ?>
					<?php if ( '' !== $ad ) : ?>
						<li class="snscs-card snscs-card--ad"><?php echo $ad; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 広告枠のHTML。 ?></li>
					<?php endif; ?>
				<?php endif; ?>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * 結果が0人のとき: 条件を1つずつ外した場合の人数を示し、そのまま探し直せるようにする。
	 */
	private static function render_empty( array $args, $did_you_mean = '' ) {
		$relax = array(
			'q'             => array( 'snscs_q', 'キーワード「%s」を外す' ),
			'genre'         => array( 'snscs_genre', 'ジャンルの指定を外す' ),
			'region'        => array( 'snscs_region', '地域の指定を外す' ),
			'platform'      => array( 'snscs_platform', 'SNSを「すべて」にする' ),
			'min_followers' => array( 'snscs_min', 'フォロワー数（以上）の指定を外す' ),
			'max_followers' => array( 'snscs_max', 'フォロワー数（以下）の指定を外す' ),
		);
		$options = array();
		foreach ( $relax as $key => $def ) {
			if ( empty( $args[ $key ] ) ) {
				continue;
			}
			$try          = $args;
			$try[ $key ]  = is_int( $args[ $key ] ) ? 0 : '';
			$try['page']  = 1;
			$try['per_page'] = 1;
			$total        = SNSCS_Search::backend()->search( array_merge( $try, array( 'exclude' => array(), 'personal_order' => null ) ) )['total'];
			if ( $total > 0 ) {
				$options[] = array(
					'label' => sprintf( $def[1], $args[ $key ] ),
					'url'   => remove_query_arg( array( $def[0], 'snscs_page' ) ),
					'total' => $total,
				);
			}
		}
		?>
		<div class="snscs-results__empty">
			<p>条件に合う発信者がいません。</p>
			<?php if ( '' !== $did_you_mean ) : ?>
				<p class="snscs-results__dym">もしかして: <a href="<?php echo esc_url( add_query_arg( 'snscs_q', rawurlencode( $did_you_mean ), remove_query_arg( 'snscs_page' ) ) ); ?>"><strong><?php echo esc_html( $did_you_mean ); ?></strong></a></p>
			<?php endif; ?>
			<?php if ( $options ) : ?>
				<p>条件をゆるめると見つかります:</p>
				<ul class="snscs-chips">
					<?php foreach ( $options as $option ) : ?>
						<li><a class="snscs-chip" href="<?php echo esc_url( $option['url'] ); ?>"><?php echo esc_html( $option['label'] ); ?>（<?php echo esc_html( number_format_i18n( $option['total'] ) ); ?>人）</a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		echo SNSCS_Trends::render( 'ほかの人はこんな言葉で探しています' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo SNSCS_Personalize::placeholder( 'foryou' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
	}

	/**
	 * @param array $accounts SNSCS_Search::format_creator() の accounts。
	 */
	public static function render_accounts( array $accounts, $growth = false ) {
		if ( empty( $accounts ) ) {
			return;
		}
		?>
		<ul class="snscs-accounts">
			<?php foreach ( $accounts as $account ) : ?>
				<li class="snscs-account snscs-account--<?php echo esc_attr( $account['platform'] ); ?>">
					<span class="snscs-account__platform"><?php echo esc_html( $account['platform_label'] ); ?></span>
					<?php if ( $account['url'] ) : ?>
						<a href="<?php echo esc_url( $account['url'] ); ?>" target="_blank" rel="noopener nofollow" data-snscs-out="1"><?php echo esc_html( snscs_display_handle( $account['platform'], $account['handle'] ) ); ?></a>
					<?php else : ?>
						<span><?php echo esc_html( snscs_display_handle( $account['platform'], $account['handle'] ) ); ?></span>
					<?php endif; ?>
					<span class="snscs-account__followers"><?php echo esc_html( $account['followers_label'] ); ?></span>
					<?php if ( ! empty( $account['verified'] ) ) : ?>
						<span class="snscs-verified" title="<?php echo esc_attr( 2 === $account['verified'] ? '本人がSNSと連携して確認したアカウントです。フォロワー数は公式の数を毎日更新しています。' : 'フォロワー数は、SNSの公式の数を自動で更新しています。' ); ?>"><?php echo 2 === $account['verified'] ? '✓ 本人確認済み' : '✓ 公式の数'; ?></span>
					<?php endif; ?>
					<?php if ( $growth && ! empty( $account['creator_id'] ) ) : ?>
						<?php $diff = SNSCS_Sync::growth( $account['creator_id'], $account['platform'] ); ?>
						<?php if ( null !== $diff && 0 !== $diff ) : ?>
							<span class="snscs-account__growth<?php echo $diff < 0 ? ' is-down' : ''; ?>"><?php echo esc_html( sprintf( '30日で%s%s', $diff > 0 ? '+' : '−', number_format_i18n( abs( $diff ) ) ) ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	private static function render_pagination( array $result ) {
		if ( $result['total_pages'] < 2 ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'snscs_page', '%#%' ),
				'format'    => '',
				'current'   => $result['page'],
				'total'     => $result['total_pages'],
				'prev_text' => '前へ',
				'next_text' => '次へ',
			)
		);

		if ( $links ) {
			echo '<nav class="snscs-pagination">' . wp_kses_post( $links ) . '</nav>';
		}
	}

	/**
	 * 発信者が登録した「その他のリンク」（ブログ・企業サイトなど）。
	 * 本人が登録したリンクなので、検索エンジン向けに ugc（利用者が投稿したリンク）を付ける。
	 *
	 * @param array $links SNSCS_Submissions::links_for() の戻り値。
	 */
	public static function render_links( array $links, array $stores = array() ) {
		if ( empty( $links ) && empty( $stores ) ) {
			return;
		}
		$labels = snscs_profile_stores();
		?>
		<ul class="snscs-links" aria-label="コンテンツ販売・ブログとその他のリンク">
			<?php foreach ( $stores as $key => $handle ) : ?>
				<li>
					<a class="snscs-links__store snscs-links__store--<?php echo esc_attr( $key ); ?>" href="<?php echo esc_url( sprintf( $labels[ $key ]['url'], rawurlencode( $handle ) ) ); ?>" target="_blank" rel="nofollow ugc noopener" data-snscs-out="1">
						<strong><?php echo esc_html( $labels[ $key ]['label'] ); ?></strong>
						<?php echo esc_html( $handle ); ?>
					</a>
				</li>
			<?php endforeach; ?>
			<?php foreach ( $links as $link ) : ?>
				<li>
					<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="nofollow ugc noopener" data-snscs-out="1">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"></path><path d="M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"></path></svg>
						<?php echo esc_html( $link['label'] ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * 発信者の個別ページの本文の下に、SNSアカウント一覧を表示する。
	 */
	public static function append_accounts( $content ) {
		static $running = false;
		if ( $running || ! is_singular( SNSCS_Post_Types::POST_TYPE ) || ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		$running = true;

		$accounts = SNSCS_Search::format_accounts( SNSCS_Accounts::get_for_creator( get_the_ID() ) );

		$id = get_the_ID();

		// 本文の前: パンくず（特集へ）と、お気に入り・シェアのボタン。
		ob_start();
		echo SNSCS_Features::creator_links_html( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo SNSCS_Social::follow_button( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo SNSCS_Share::visitor_buttons( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		$before = ob_get_clean();

		ob_start();
		echo SNSCS_Invites::badge( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo '<h2 class="snscs-single__heading">SNSアカウント</h2>';
		self::render_accounts( $accounts, true );
		self::render_links( SNSCS_Submissions::links_for( $id ), SNSCS_Submissions::stores_for( $id ) );
		echo SNSCS_Inquiries::form_html( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo SNSCS_Articles::render_list( 'この発信者の記事', SNSCS_Articles::for_creator( $id, 'publish', 5 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo SNSCS_Social::reposts_html( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo SNSCS_Interviews::for_creator_html( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		// 回遊: 一緒に見られている発信者（予測）→ 似ている発信者 → 最近見た発信者（この人のブラウザの記録）。
		echo SNSCS_Personalize::also_viewed_html( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo SNSCS_Recommend::similar_html( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo SNSCS_Personalize::placeholder( 'recent' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo SNSCS_Columns::for_creator( $id, $accounts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		$running = false;
		return $before . $content . ob_get_clean();
	}
}
