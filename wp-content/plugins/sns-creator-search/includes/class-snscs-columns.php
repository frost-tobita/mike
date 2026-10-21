<?php
/**
 * SNS運用コラム（ブログ）。
 *
 * WordPress の「投稿」のうち、カテゴリ「SNS運用コラム」の記事をコラムとして扱う。
 * 記事には「対象SNS」と「ジャンル」を付けられ、出す場所に合ったものが自動で選ばれる。
 *
 * 出す場所:
 *   - 検索結果の下（いま選んでいるSNS・ジャンルに合うもの）
 *   - 発信者ページの下（その発信者のSNS・ジャンルに合うもの）
 *   - 発信者マイページ（発信者向け）
 *   - ショートコード [sns_columns] を書いた場所（トップページなど）
 * コラム記事の下には「この話題の発信者を探す」ボタンと広告枠（コラム：記事の下）を出す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Columns {

	const CATEGORY_SLUG = 'sns-column';
	const TAX_SNS       = 'snscs_sns';
	const ADMIN_PAGE    = 'snscs-columns';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 11 );
		add_action( 'init', array( __CLASS__, 'maybe_setup' ), 20 );
		add_shortcode( 'sns_columns', array( __CLASS__, 'shortcode' ) );
		add_filter( 'the_content', array( __CLASS__, 'append_to_column' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_snscs_add_starter_columns', array( __CLASS__, 'handle_add_starters' ) );
	}

	/**
	 * 投稿に「対象SNS」を付けられるようにし、「ジャンル」も投稿で使えるようにする。
	 */
	public static function register() {
		register_taxonomy(
			self::TAX_SNS,
			'post',
			array(
				'labels'            => array(
					'name'          => '対象SNS',
					'singular_name' => '対象SNS',
					'add_new_item'  => '対象SNSを追加',
				),
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'column-sns' ),
			)
		);
		register_taxonomy_for_object_type( SNSCS_Post_Types::TAX_GENRE, 'post' );
	}

	/**
	 * 有効化・更新のあとに一度だけ、カテゴリと「対象SNS」の項目を用意する。
	 * （タクソノミーの登録後でないと作れないため init で行う）
	 */
	public static function maybe_setup() {
		if ( ! get_option( 'snscs_columns_setup' ) ) {
			return;
		}
		delete_option( 'snscs_columns_setup' );

		if ( ! get_term_by( 'slug', self::CATEGORY_SLUG, 'category' ) ) {
			wp_insert_term( 'SNS運用コラム', 'category', array( 'slug' => self::CATEGORY_SLUG, 'description' => 'SNSの運用に役立つコラム' ) );
		}
		foreach ( snscs_platforms() as $key => $label ) {
			if ( ! get_term_by( 'slug', $key, self::TAX_SNS ) ) {
				wp_insert_term( $label, self::TAX_SNS, array( 'slug' => $key ) );
			}
		}
	}

	public static function category_id() {
		$term = get_term_by( 'slug', self::CATEGORY_SLUG, 'category' );
		return $term ? (int) $term->term_id : 0;
	}

	public static function is_column( $post = null ) {
		$post = get_post( $post );
		return $post && 'post' === $post->post_type && self::category_id() && has_category( self::category_id(), $post );
	}

	/* --------------------------------------------------------------------
	 * 記事の選び方
	 * ------------------------------------------------------------------ */

	/**
	 * 条件に合うコラムを選ぶ。合うものが足りなければ新しい順で埋める。
	 *
	 * @param array $args sns（SNSのキーの配列）、genres（ジャンルのID配列）、count、exclude（除く投稿ID）。
	 * @return WP_Post[]
	 */
	public static function find( array $args ) {
		$args = wp_parse_args( $args, array( 'sns' => array(), 'genres' => array(), 'count' => 3, 'exclude' => array() ) );
		$cat  = self::category_id();
		if ( ! $cat ) {
			return array();
		}

		$base = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'cat'                 => $cat,
			'posts_per_page'      => (int) $args['count'],
			'post__not_in'        => array_map( 'intval', (array) $args['exclude'] ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);

		$posts    = array();
		$tax_list = array();
		if ( $args['sns'] ) {
			$tax_list[] = array( 'taxonomy' => self::TAX_SNS, 'field' => 'slug', 'terms' => (array) $args['sns'] );
		}
		if ( $args['genres'] ) {
			$tax_list[] = array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'field' => 'term_id', 'terms' => array_map( 'intval', (array) $args['genres'] ) );
		}
		if ( $tax_list ) {
			$query = $base;
			// 「SNSかジャンルが合う」を1つのまとまりにする。WP_Query は cat の条件を一番外側に足すため、
			// 外側を AND にしておかないと「コラムなら何でも」に広がってしまう。
			$query['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'relation' => 'AND',
				array_merge( array( 'relation' => 'OR' ), $tax_list ),
			);
			$posts = get_posts( $query );
		}

		if ( count( $posts ) < $args['count'] ) {
			$fill                   = $base;
			$fill['posts_per_page'] = $args['count'] - count( $posts );
			$fill['post__not_in']   = array_merge( $base['post__not_in'], wp_list_pluck( $posts, 'ID' ) );
			$posts                  = array_merge( $posts, get_posts( $fill ) );
		}

		return $posts;
	}

	/* --------------------------------------------------------------------
	 * 表示
	 * ------------------------------------------------------------------ */

	/**
	 * コラムの一覧（見出し付き）。記事が1本も無ければ空文字。
	 *
	 * @param string $heading 見出し。
	 * @param array  $args    find() の条件。
	 * @return string
	 */
	public static function render( $heading, array $args ) {
		$posts = self::find( $args );
		if ( ! $posts ) {
			return '';
		}
		wp_enqueue_style( 'snscs' );

		ob_start();
		?>
		<section class="snscs-columns" aria-label="<?php echo esc_attr( $heading ); ?>">
			<div class="snscs-columns__head">
				<h2 class="snscs-columns__title"><?php echo esc_html( $heading ); ?></h2>
				<a class="snscs-columns__more" href="<?php echo esc_url( get_category_link( self::category_id() ) ); ?>">コラムをもっと読む</a>
			</div>
			<ul class="snscs-columns__list">
				<?php foreach ( $posts as $post ) : ?>
					<li class="snscs-column-card">
						<a href="<?php echo esc_url( get_permalink( $post ) ); ?>">
							<?php if ( has_post_thumbnail( $post ) ) : ?>
								<?php echo get_the_post_thumbnail( $post, 'medium', array( 'class' => 'snscs-column-card__image', 'loading' => 'lazy', 'alt' => '' ) ); ?>
							<?php endif; ?>
							<span class="snscs-column-card__body">
								<?php $sns = get_the_terms( $post, self::TAX_SNS ); ?>
								<?php if ( $sns && ! is_wp_error( $sns ) ) : ?>
									<span class="snscs-column-card__sns"><?php echo esc_html( implode( '・', wp_list_pluck( $sns, 'name' ) ) ); ?></span>
								<?php endif; ?>
								<strong class="snscs-column-card__name"><?php echo esc_html( get_the_title( $post ) ); ?></strong>
								<span class="snscs-column-card__date"><?php echo esc_html( get_the_date( 'Y年n月j日', $post ) ); ?></span>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * [sns_columns count="6" sns="instagram" genre="料理のスラッグ" title="見出し"]
	 */
	public static function shortcode( $atts ) {
		$atts   = shortcode_atts( array( 'count' => 6, 'sns' => '', 'genre' => '', 'title' => 'SNS運用コラム' ), $atts, 'sns_columns' );
		$genres = array();
		if ( '' !== $atts['genre'] ) {
			$term   = get_term_by( 'slug', sanitize_title( $atts['genre'] ), SNSCS_Post_Types::TAX_GENRE );
			$genres = $term ? array( $term->term_id ) : array();
		}
		return self::render(
			sanitize_text_field( $atts['title'] ),
			array(
				'count'  => min( 20, max( 1, (int) $atts['count'] ) ),
				'sns'    => array_filter( array( sanitize_key( $atts['sns'] ) ) ),
				'genres' => $genres,
			)
		);
	}

	/**
	 * 検索結果の下に出すコラム。
	 */
	public static function for_search( array $search_args ) {
		$genres = array();
		if ( '' !== $search_args['genre'] ) {
			$term   = get_term_by( 'slug', $search_args['genre'], SNSCS_Post_Types::TAX_GENRE );
			$genres = $term ? array( $term->term_id ) : array();
		}
		$label = '' !== $search_args['platform'] ? snscs_platform_label( $search_args['platform'] ) . 'の運用コラム' : 'SNS運用コラム';
		return self::render( $label, array( 'sns' => array_filter( array( $search_args['platform'] ) ), 'genres' => $genres, 'count' => 3 ) );
	}

	/**
	 * 発信者ページの下に出すコラム。
	 */
	public static function for_creator( $creator_id, array $accounts ) {
		return self::render(
			'関連するSNS運用コラム',
			array(
				'sns'    => array_values( array_unique( wp_list_pluck( $accounts, 'platform' ) ) ),
				'genres' => wp_get_object_terms( $creator_id, SNSCS_Post_Types::TAX_GENRE, array( 'fields' => 'ids' ) ),
				'count'  => 3,
			)
		);
	}

	/**
	 * コラム記事の下に「この話題の発信者を探す」と広告枠を付ける。
	 */
	public static function append_to_column( $content ) {
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() || ! self::is_column() ) {
			return $content;
		}

		$post_id = get_the_ID();
		$sns     = get_the_terms( $post_id, self::TAX_SNS );
		$genres  = get_the_terms( $post_id, SNSCS_Post_Types::TAX_GENRE );
		$query   = array();
		$label   = '発信者を探す';
		if ( $sns && ! is_wp_error( $sns ) && 1 === count( $sns ) && array_key_exists( $sns[0]->slug, snscs_platforms() ) ) {
			$query['snscs_platform'] = $sns[0]->slug;
			$label                   = $sns[0]->name . 'の発信者を探す';
		}
		if ( $genres && ! is_wp_error( $genres ) ) {
			$query['snscs_genre'] = $genres[0]->slug;
			$label                = $genres[0]->name . ( isset( $query['snscs_platform'] ) ? '×' . $sns[0]->name : '' ) . 'の発信者を探す';
		}

		wp_enqueue_style( 'snscs' );
		ob_start();
		?>
		<aside class="snscs-column-cta">
			<p class="snscs-column-cta__text">この記事の話題で発信している人を、<?php echo esc_html( get_bloginfo( 'name' ) ); ?>で探してみましょう。</p>
			<a class="snscs-button snscs-button--primary snscs-column-cta__button" href="<?php echo esc_url( add_query_arg( $query, snscs_search_url() ) ); ?>"><?php echo esc_html( $label ); ?></a>
		</aside>
		<?php
		echo SNSCS_Ads::render( 'column_bottom' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 広告枠のHTML。
		echo self::render( 'あわせて読みたいコラム', array( 'sns' => $sns && ! is_wp_error( $sns ) ? wp_list_pluck( $sns, 'slug' ) : array(), 'count' => 3, 'exclude' => array( $post_id ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済み。
		return $content . ob_get_clean();
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, 'SNS運用コラム', 'SNS運用コラム', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	public static function admin_url() {
		return admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::ADMIN_PAGE );
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$cat   = self::category_id();
		$count = $cat ? (int) get_category( $cat )->count : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$added = isset( $_GET['snscs_added'] ) ? (int) $_GET['snscs_added'] : -1;
		?>
		<div class="wrap">
			<h1>SNS運用コラム</h1>
			<?php if ( $added >= 0 ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( 'スターター記事を%d本、下書きとして追加しました。内容を確認してから公開してください。', $added ) ); ?></p></div>
			<?php endif; ?>

			<p>カテゴリ「SNS運用コラム」の投稿が、コラムとしてサイトのあちこちに表示されます。公開中のコラム: <strong><?php echo (int) $count; ?>本</strong></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php' ) ); ?>">コラムを書く</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?category_name=' . self::CATEGORY_SLUG ) ); ?>">コラムの一覧</a>
			</p>

			<h2>書き方</h2>
			<ol>
				<li>「投稿 → 新規投稿」で記事を書く</li>
				<li>右側の設定で、カテゴリ「SNS運用コラム」にチェックを入れる</li>
				<li>「対象SNS」（X、Instagram など）と「ジャンル」を選ぶ（選んだSNS・ジャンルに合う場所に優先して表示されます）</li>
				<li>アイキャッチ画像を設定すると、一覧に画像付きで表示されます</li>
			</ol>

			<h2>表示される場所</h2>
			<table class="widefat striped" style="max-width: 900px">
				<thead><tr><th>場所</th><th>表示されるコラム</th></tr></thead>
				<tbody>
					<tr><td>検索結果の下</td><td>いま選んでいるSNSタブ・ジャンルに合うもの3本</td></tr>
					<tr><td>発信者ページの下</td><td>その発信者のSNS・ジャンルに合うもの3本</td></tr>
					<tr><td>発信者マイページ</td><td>新しいもの3本（発信者向け）</td></tr>
					<tr><td>コラム記事の下</td><td>「この話題の発信者を探す」ボタン、広告枠（コラム：記事の下）、関連するコラム3本</td></tr>
					<tr><td>トップページなど</td><td><code>[sns_columns]</code> と書いた場所（例: <code>[sns_columns count="6" sns="instagram"]</code>）</td></tr>
				</tbody>
			</table>

			<h2>スターター記事</h2>
			<p>集客の最初の一歩として、SNS運用の基本を解説した記事を6本用意しています。下書きとして追加されるので、内容を確認・追記してから公開してください（同じタイトルの記事があるものは追加しません）。</p>
			<ul style="list-style: disc; padding-left: 1.4em">
				<?php foreach ( self::starters() as $starter ) : ?>
					<li><?php echo esc_html( $starter['title'] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="snscs_add_starter_columns">
				<?php wp_nonce_field( 'snscs_add_starter_columns', 'snscs_nonce' ); ?>
				<?php submit_button( 'スターター記事を下書きで追加', 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	public static function starters() {
		return require SNSCS_DIR . 'includes/columns/starters.php';
	}

	/**
	 * スターター記事を下書きで追加する。同じタイトルの投稿があるものは飛ばす。
	 *
	 * @return int 追加した本数。
	 */
	public static function add_starters() {
		$cat   = self::category_id();
		$added = 0;
		foreach ( self::starters() as $starter ) {
			$exists = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'title' => $starter['title'], 'numberposts' => 1, 'fields' => 'ids' ) );
			if ( $exists ) {
				continue;
			}
			$id = wp_insert_post(
				wp_slash(
					array(
						'post_type'     => 'post',
						'post_status'   => 'draft',
						'post_title'    => $starter['title'],
						'post_excerpt'  => $starter['excerpt'],
						'post_content'  => $starter['content'],
						'post_category' => $cat ? array( $cat ) : array(),
					)
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				wp_set_object_terms( $id, $starter['sns'], self::TAX_SNS );
				$added++;
			}
		}
		return $added;
	}

	public static function handle_add_starters() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_add_starter_columns' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$added = self::add_starters();
		wp_safe_redirect( add_query_arg( 'snscs_added', $added, self::admin_url() ) );
		exit;
	}
}
