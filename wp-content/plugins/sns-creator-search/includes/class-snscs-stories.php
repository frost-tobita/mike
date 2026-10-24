<?php
/**
 * note のような記事の公開と、X のようなリポストでの拡散。
 *
 * 公開:
 *   公開のしかた（管理画面で選ぶ）
 *     trusted（おすすめ）: これまでに記事が1本以上承認され、不正対策で順位を下げられていない発信者は、すぐ公開できる。
 *                         それ以外の人は今までどおり運営の審査を通ってから公開
 *     review             : すべて審査
 *     instant            : 全員すぐ公開（運営はあとから非公開にできる）
 *   予約投稿: 日時を決めて公開できる（審査が必要な人は、承認されたあと、その日時に公開）
 *   ハッシュタグ: 1記事5個まで。タグごとの記事一覧から探せる
 *   読みやすさ: 読了時間の目安、見出しが3つ以上あれば目次
 *
 * 見つけてもらう:
 *   「みんなの記事」ページ（[sns_stories]）: 話題 / 新着、タグで絞り込み
 *   「話題」の順位 = 直近72時間の反応の合計（いいね1・ブックマーク1.5・リポスト3・ひとこと付きリポスト4。
 *                    発信者のリポストは2倍）を、24時間ごとに半分になるよう新しさで重み付け。
 *                    登録から3日以上たったアカウントの反応だけを数える（水増し対策）
 *
 * 拡散:
 *   リポストした発信者のフォロワーの「フォロー中」とお知らせに流れる（SNSCS_Social / SNSCS_Inbox）
 *   記事の下に「この記事を広めた発信者」（ひとこと付き）を出す
 *   リポストから読まれた数を数え、記事の書き手とリポストした人に見せる（同じ人・同じ日は1回）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Stories {

	const TAX      = 'snscs_article_tag';
	const OPTION   = 'snscs_publish_mode';
	const MAX_TAGS = 5;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'the_content', array( __CLASS__, 'decorate' ), 5 );
		add_filter( 'the_content', array( __CLASS__, 'append_spread' ), 22 );
		add_filter( 'the_content', array( __CLASS__, 'append_next_reads' ), 24 );
		add_shortcode( 'sns_stories', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'sns_trending_articles', array( __CLASS__, 'trending_shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'count_via_repost' ) );
		add_action( 'admin_post_snscs_publish_mode', array( __CLASS__, 'handle_mode' ) );
	}

	public static function register() {
		register_taxonomy(
			self::TAX,
			SNSCS_Articles::POST_TYPE,
			array(
				'labels'            => array( 'name' => 'ハッシュタグ', 'singular_name' => 'ハッシュタグ' ),
				'public'            => true,
				'show_admin_column' => true,
				'hierarchical'      => false,
				'rewrite'           => array( 'slug' => 'articles/tag' ),
			)
		);
	}

	/* --------------------------------------------------------------------
	 * 公開のしかた
	 * ------------------------------------------------------------------ */

	public static function mode() {
		$mode = (string) get_option( self::OPTION, 'trusted' );
		return in_array( $mode, array( 'trusted', 'review', 'instant' ), true ) ? $mode : 'trusted';
	}

	/**
	 * この発信者は、審査なしですぐ公開できるか。
	 */
	public static function can_publish_now( $creator_id ) {
		$mode = self::mode();
		if ( 'instant' === $mode ) {
			return true;
		}
		if ( 'review' === $mode || ! $creator_id ) {
			return false;
		}
		if ( SNSCS_Integrity::status( $creator_id )['penalty'] < 1 ) {
			return false;
		}
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_snscs_creator_id' AND pm.meta_value = %d WHERE p.post_type = %s AND p.post_status = 'publish' LIMIT 1",
				$creator_id,
				SNSCS_Articles::POST_TYPE
			)
		);
	}

	public static function handle_mode() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_publish_mode' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'trusted';
		update_option( self::OPTION, in_array( $mode, array( 'trusted', 'review', 'instant' ), true ) ? $mode : 'trusted', false );
		wp_safe_redirect( add_query_arg( 'snscs_done', 'mode', SNSCS_Articles_Review::url() ) );
		exit;
	}

	/**
	 * 記事の審査画面の上に出す設定。
	 */
	public static function render_mode_form() {
		$mode    = self::mode();
		$options = array(
			'trusted' => '信頼できる発信者はすぐ公開（おすすめ）: 記事が1本以上承認され、不正対策の対象でない人は審査なしで公開。それ以外は審査',
			'review'  => 'すべて審査してから公開',
			'instant' => '全員すぐ公開（問題があれば、あとから非公開にする）',
		);
		?>
		<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>" style="margin: 12px 0 20px; padding: 12px 16px; background: #fff; border: 1px solid #dcdcde">
			<input type="hidden" name="action" value="snscs_publish_mode">
			<?php wp_nonce_field( 'snscs_publish_mode', 'snscs_nonce' ); ?>
			<strong>記事の公開のしかた</strong>
			<?php foreach ( $options as $key => $label ) : ?>
				<p style="margin: 6px 0"><label><input type="radio" name="mode" value="<?php echo esc_attr( $key ); ?>" <?php checked( $mode, $key ); ?>> <?php echo esc_html( $label ); ?></label></p>
			<?php endforeach; ?>
			<button type="submit" class="button">保存</button>
		</form>
		<?php
	}

	/* --------------------------------------------------------------------
	 * ハッシュタグ・予約投稿（SNSCS_Articles の保存から使う）
	 * ------------------------------------------------------------------ */

	/**
	 * 「#料理 #作り置き」「料理、作り置き」などから、タグの一覧（最大5個・各20文字まで）。
	 *
	 * @return string[]
	 */
	public static function parse_tags( $text ) {
		$parts = preg_split( '/[\s,、，#＃]+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		$tags  = array();
		foreach ( $parts as $part ) {
			$tag = mb_substr( sanitize_text_field( $part ), 0, 20 );
			if ( '' !== $tag && ! in_array( $tag, $tags, true ) ) {
				$tags[] = $tag;
			}
		}
		return array_slice( $tags, 0, self::MAX_TAGS );
	}

	public static function tags_text( $article_id ) {
		$names = wp_get_object_terms( $article_id, self::TAX, array( 'fields' => 'names' ) );
		return is_wp_error( $names ) || ! $names ? '' : '#' . implode( ' #', $names );
	}

	public static function set_tags( $article_id, array $tags ) {
		wp_set_object_terms( $article_id, $tags, self::TAX );
	}

	/**
	 * 「2026-11-01T09:00」形式（このサイトの時刻）を、未来なら 'Y-m-d H:i:s' に。過去・空なら空文字。
	 */
	public static function parse_publish_at( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ) {
			return '';
		}
		$local = str_replace( 'T', ' ', $value ) . ':00';
		return strtotime( $local ) > strtotime( current_time( 'mysql' ) ) + 60 ? $local : '';
	}

	/**
	 * 公開する（日時が未来なら予約）。
	 */
	public static function publish( $article_id, $publish_at = '' ) {
		if ( '' !== $publish_at ) {
			wp_update_post(
				array(
					'ID'            => $article_id,
					'post_status'   => 'future',
					'post_date'     => $publish_at,
					'post_date_gmt' => get_gmt_from_date( $publish_at ),
					'edit_date'     => true,
				)
			);
			return 'future';
		}
		wp_update_post(
			array(
				'ID'            => $article_id,
				'post_status'   => 'publish',
				'post_date'     => current_time( 'mysql' ),
				'post_date_gmt' => current_time( 'mysql', true ),
				'edit_date'     => true,
			)
		);
		return 'publish';
	}

	/* --------------------------------------------------------------------
	 * 記事のページ
	 * ------------------------------------------------------------------ */

	public static function reading_minutes( $content ) {
		$chars = mb_strlen( preg_replace( '/\s+/u', '', wp_strip_all_tags( $content ) ) );
		return max( 1, (int) round( $chars / 500 ) );
	}

	/**
	 * 本文の前に「読了時間・タグ」、見出しが3つ以上なら目次。
	 */
	public static function decorate( $content ) {
		if ( ! is_singular( SNSCS_Articles::POST_TYPE ) || ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		wp_enqueue_style( 'snscs' );
		$id   = get_the_ID();
		$tags = wp_get_object_terms( $id, self::TAX );
		$head = '<p class="snscs-story-meta"><span>約' . (int) self::reading_minutes( $content ) . '分で読めます</span>';
		foreach ( is_wp_error( $tags ) ? array() : $tags as $tag ) {
			$head .= ' <a class="snscs-story-tag" href="' . esc_url( self::stories_url( array( 'tag' => $tag->slug ) ) ) . '">#' . esc_html( $tag->name ) . '</a>';
		}
		$head .= '</p>';
		if ( false === strpos( $content, 'snscs-toc' ) ) {
			$content = SNSCS_Autopost::with_toc( $content );
		}
		return $head . '<div class="snscs-story">' . $content . '</div>';
	}

	public static function stories_url( $args = array() ) {
		$url = SNSCS_Hub::page_url( 'stories' );
		if ( ! $url ) {
			$url = get_post_type_archive_link( SNSCS_Articles::POST_TYPE );
		}
		return add_query_arg( $args, $url );
	}

	/**
	 * 記事の下: この記事を広めた発信者（ひとこと付きリポスト）と、リポストから読まれた数。
	 */
	/**
	 * 記事の下: 次に読むおすすめ（SNSCS_Feed。この記事と一緒に読まれている記事を中心に）。
	 */
	public static function append_next_reads( $content ) {
		if ( ! is_singular( SNSCS_Articles::POST_TYPE ) || ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		return $content . SNSCS_Feed::next_reads( get_the_ID() );
	}

	public static function append_spread( $content ) {
		if ( ! is_singular( array( SNSCS_Articles::POST_TYPE, 'post' ) ) || ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		return $content . self::spread_html( get_the_ID() );
	}

	public static function spread_html( $article_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT r.user_id, um.meta_value AS creator_id, r.comment, r.created_at FROM ' . SNSCS_Social::reactions_table() . " r LEFT JOIN {$wpdb->usermeta} um ON um.user_id = r.user_id AND um.meta_key = 'snscs_creator_id' WHERE r.article_id = %d AND r.type = %d ORDER BY um.meta_value IS NULL, r.comment = '', r.created_at DESC LIMIT 60", $article_id, SNSCS_Social::REPOST ), ARRAY_A );
		$via  = (int) get_post_meta( $article_id, '_snscs_via_repost', true );
		$list = '';
		$n    = 0;
		foreach ( $rows as $row ) {
			$id = (int) $row['creator_id'];
			if ( $id && 'publish' === get_post_status( $id ) ) {
				// 発信者: 発信者ページへ。
				$photo = get_the_post_thumbnail_url( $id, 'thumbnail' );
				$who   = '<a class="snscs-spread__who" href="' . esc_url( get_permalink( $id ) ) . '">' . ( $photo ? '<img src="' . esc_url( $photo ) . '" alt="" width="32" height="32" loading="lazy">' : '' ) . esc_html( get_the_title( $id ) ) . '</a>';
			} elseif ( ! $id && SNSCS_People::is_public( (int) $row['user_id'] ) ) {
				// 公開プロフィールのメンバー: プロフィールへ。
				$name = SNSCS_People::nickname( (int) $row['user_id'] );
				$who  = '<a class="snscs-spread__who" href="' . esc_url( SNSCS_People::profile_url( (int) $row['user_id'] ) ) . '"><span class="snscs-profile__avatar snscs-profile__avatar--small" aria-hidden="true">' . esc_html( mb_substr( $name, 0, 1 ) ) . '</span>' . esc_html( $name ) . '</a>';
			} else {
				continue;
			}
			$list .= '<li class="snscs-spread__item">' . $who . ( '' !== $row['comment'] ? '<p class="snscs-spread__comment">' . esc_html( $row['comment'] ) . '</p>' : '' ) . '</li>';
			if ( ++$n >= 12 ) {
				break;
			}
		}
		if ( '' === $list && ! $via ) {
			return '';
		}
		$html = '<section class="snscs-spread"><h2 class="snscs-single__heading">この記事を広めた人</h2>';
		if ( $via ) {
			$html .= '<p class="snscs-help">リポストから' . esc_html( number_format_i18n( $via ) ) . '人がこの記事を読みました。</p>';
		}
		return $html . ( $list ? '<ul class="snscs-spread__list">' . $list . '</ul>' : '' ) . '</section>';
	}

	/**
	 * リポストから読まれた数（URL の ?rp=発信者ID で判断。同じ人・同じ日・同じ記事は1回）。
	 */
	public static function count_via_repost() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ( empty( $_GET['rp'] ) && empty( $_GET['ru'] ) ) || ! is_singular( array( SNSCS_Articles::POST_TYPE, 'post' ) ) || SNSCS_Referrals::is_bot_request() ) {
			return;
		}
		$reposter = isset( $_GET['rp'] ) ? absint( $_GET['rp'] ) : 0;
		$user     = isset( $_GET['ru'] ) ? absint( $_GET['ru'] ) : 0;
		// phpcs:enable
		$article = get_queried_object_id();
		global $wpdb;
		if ( $reposter ) {
			if ( SNSCS_Post_Types::POST_TYPE !== get_post_type( $reposter ) || ( is_user_logged_in() && SNSCS_Social::own_creator( get_current_user_id() ) === $reposter ) ) {
				return;
			}
			// 実際にリポストしている人のリンクだけを数える。
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ok = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . SNSCS_Social::reactions_table() . " r INNER JOIN {$wpdb->usermeta} um ON um.user_id = r.user_id AND um.meta_key = 'snscs_creator_id' AND um.meta_value = %d WHERE r.article_id = %d AND r.type = %d LIMIT 1", $reposter, $article, SNSCS_Social::REPOST ) );
		} else {
			if ( ! SNSCS_People::is_public( $user ) || get_current_user_id() === $user ) {
				return;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ok = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . SNSCS_Social::reactions_table() . ' WHERE user_id = %d AND article_id = %d AND type = %d', $user, $article, SNSCS_Social::REPOST ) );
		}
		if ( ! $ok ) {
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$new = (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . SNSCS_Integrity::seen_table() . ' (day, visitor, creator_id) VALUES (%s, %s, %d)', current_time( 'Y-m-d' ), substr( SNSCS_Referrals::visitor_hash(), 0, 16 ), $article ) );
		if ( $new ) {
			update_post_meta( $article, '_snscs_via_repost', (int) get_post_meta( $article, '_snscs_via_repost', true ) + 1 );
			if ( $reposter ) {
				update_post_meta( $reposter, '_snscs_spread_reads', (int) get_post_meta( $reposter, '_snscs_spread_reads', true ) + 1 );
			} else {
				update_user_meta( $user, '_snscs_spread_reads', (int) get_user_meta( $user, '_snscs_spread_reads', true ) + 1 );
			}
		}
	}

	/* --------------------------------------------------------------------
	 * 話題の記事
	 * ------------------------------------------------------------------ */

	/**
	 * 話題の記事（記事ID => 点数、高い順）。
	 *
	 * @return array<int,float>
	 */
	public static function trending( $limit = 20, $tag = '' ) {
		$key    = 'snscs_trend_art_' . md5( $limit . '|' . $tag );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$now    = current_time( 'mysql' );
		$since  = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - 3 * DAY_IN_SECONDS );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - SNSCS_Social::MIN_AGE_DAYS * DAY_IN_SECONDS );
		$join   = '';
		if ( '' !== $tag ) {
			$term = get_term_by( 'slug', $tag, self::TAX );
			if ( ! $term ) {
				return array();
			}
			$join = $wpdb->prepare( " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = r.article_id AND tr.term_taxonomy_id = %d", $term->term_taxonomy_id );
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.article_id, SUM(
					CASE r.type WHEN %d THEN 1 WHEN %d THEN 1.5 ELSE IF(r.comment <> \'\', 4, 3) END
					* IF(um.meta_value IS NULL, 1, 2)
					* POW(0.5, TIMESTAMPDIFF(HOUR, r.created_at, %s) / 24)
				) AS hot
				FROM ' . SNSCS_Social::reactions_table() . " r
				INNER JOIN {$wpdb->users} u ON u.ID = r.user_id AND u.user_registered <= %s
				INNER JOIN {$wpdb->posts} p ON p.ID = r.article_id AND p.post_status = 'publish'
				LEFT JOIN {$wpdb->usermeta} um ON um.user_id = r.user_id AND um.meta_key = 'snscs_creator_id'{$join}
				WHERE r.created_at >= %s GROUP BY r.article_id ORDER BY hot DESC LIMIT %d",
				SNSCS_Social::LIKE,
				SNSCS_Social::BOOKMARK,
				$now,
				$cutoff,
				$since,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable
		$penalties = SNSCS_Integrity::penalties();
		$out       = array();
		foreach ( $rows as $row ) {
			$id          = (int) $row['article_id'];
			$creator     = SNSCS_Articles::POST_TYPE === get_post_type( $id ) ? SNSCS_Articles::creator_of( $id ) : 0;
			$out[ $id ]  = (float) $row['hot'] * ( isset( $penalties[ $creator ] ) ? $penalties[ $creator ] : 1 );
		}
		arsort( $out );
		set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * 新着の記事（発信者の記事）。
	 *
	 * @return int[]
	 */
	public static function newest( $limit = 20, $tag = '', $page = 1 ) {
		$args = array(
			'post_type'      => SNSCS_Articles::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'paged'          => $page,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);
		if ( '' !== $tag ) {
			$args['tax_query'] = array( array( 'taxonomy' => self::TAX, 'field' => 'slug', 'terms' => $tag ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		return array_map( 'intval', get_posts( $args ) );
	}

	public static function trending_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'count' => 5, 'title' => '話題の記事' ), $atts, 'sns_trending_articles' );
		$ids  = array_keys( self::trending( max( 1, min( 20, (int) $atts['count'] ) ) ) );
		if ( ! $ids ) {
			$ids = self::newest( max( 1, min( 20, (int) $atts['count'] ) ) );
		}
		if ( ! $ids ) {
			return '';
		}
		wp_enqueue_style( 'snscs' );
		$items = array();
		foreach ( $ids as $id ) {
			$items[] = array( 'id' => $id, 'note' => '' );
		}
		return '<section class="snscs-stories-box"><h2 class="snscs-single__heading">' . esc_html( $atts['title'] ) . '</h2>' . SNSCS_Social::render_list( $items ) . '<p><a href="' . esc_url( self::stories_url() ) . '">みんなの記事をもっと見る →</a></p></section>';
	}

	/* --------------------------------------------------------------------
	 * 「みんなの記事」ページ
	 * ------------------------------------------------------------------ */

	public static function shortcode() {
		wp_enqueue_style( 'snscs' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$view = isset( $_GET['view'] ) && in_array( $_GET['view'], array( 'new', 'hot' ), true ) ? sanitize_key( $_GET['view'] ) : 'foryou';
		$tag  = isset( $_GET['tag'] ) ? sanitize_title( wp_unslash( $_GET['tag'] ) ) : '';
		$page = isset( $_GET['pg'] ) ? max( 1, absint( $_GET['pg'] ) ) : 1;
		// phpcs:enable
		$term = '' !== $tag ? get_term_by( 'slug', $tag, self::TAX ) : null;
		if ( '' !== $tag && ! $term ) {
			$tag = '';
		}
		$per  = SNSCS_Social::PER_PAGE;
		$feed = array();
		if ( 'foryou' === $view && '' !== $tag ) {
			$view = 'hot';
		}
		if ( 'foryou' === $view ) {
			// 一人ひとりに合わせるので、ページをキャッシュさせない。
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			$all  = SNSCS_Feed::items();
			$feed = array_slice( $all, ( $page - 1 ) * $per, $per );
			$ids  = count( $all ) > $page * $per ? array_fill( 0, $per + 1, 0 ) : array();
			$note = 'おすすめの記事はまだありません。新着の記事を見てみましょう。';
		} elseif ( 'hot' === $view ) {
			$ids  = array_slice( array_keys( self::trending( 100, $tag ) ), ( $page - 1 ) * $per, $per + 1 );
			$note = '話題の記事はまだありません。新着の記事を見てみましょう。';
		} else {
			$ids  = self::newest( $per + 1, $tag, $page );
			$note = '記事はまだありません。';
		}
		$more  = count( $ids ) > $per;
		$items = array();
		foreach ( array_slice( $ids, 0, $per ) as $id ) {
			$items[] = array( 'id' => $id, 'note' => '' );
		}
		$base = array_filter( array( 'tag' => $tag ) );
		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		?>
		<div class="snscs-stories">
			<?php if ( $term ) : ?>
				<p class="snscs-stories__tag">#<?php echo esc_html( $term->name ); ?> の記事 <a href="<?php echo esc_url( self::stories_url( array( 'view' => $view ) ) ); ?>">（タグを外す）</a></p>
			<?php endif; ?>
			<nav class="snscs-member__tabs" aria-label="記事の並べ方">
				<?php if ( '' === $tag ) : ?>
					<a class="snscs-chip" href="<?php echo esc_url( self::stories_url() ); ?>" <?php echo 'foryou' === $view ? 'aria-current="page"' : ''; ?>>おすすめ</a>
				<?php endif; ?>
				<a class="snscs-chip" href="<?php echo esc_url( self::stories_url( array_merge( $base, array( 'view' => 'hot' ) ) ) ); ?>" <?php echo 'hot' === $view ? 'aria-current="page"' : ''; ?>>話題</a>
				<a class="snscs-chip" href="<?php echo esc_url( self::stories_url( array_merge( $base, array( 'view' => 'new' ) ) ) ); ?>" <?php echo 'new' === $view ? 'aria-current="page"' : ''; ?>>新着</a>
				<?php if ( SNSCS_Social::member_url() ) : ?>
					<a class="snscs-chip" href="<?php echo esc_url( SNSCS_Social::member_url() ); ?>">フォロー中</a>
				<?php endif; ?>
			</nav>
			<?php echo 'foryou' === $view ? SNSCS_Feed::render( $feed, $note ) : SNSCS_Social::render_list( $items, $note ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>
			<p class="snscs-member__pager">
				<?php if ( $page > 1 ) : ?>
					<a href="<?php echo esc_url( self::stories_url( array_merge( $base, array( 'view' => $view, 'pg' => $page - 1 ) ) ) ); ?>">← 前へ</a>
				<?php endif; ?>
				<?php if ( $more ) : ?>
					<a href="<?php echo esc_url( self::stories_url( array_merge( $base, array( 'view' => $view, 'pg' => $page + 1 ) ) ) ); ?>">次へ →</a>
				<?php endif; ?>
			</p>
			<?php
			$popular = get_terms( array( 'taxonomy' => self::TAX, 'orderby' => 'count', 'order' => 'DESC', 'number' => 20, 'hide_empty' => true ) );
			if ( $popular && ! is_wp_error( $popular ) ) :
				?>
				<h2 class="snscs-single__heading">人気のハッシュタグ</h2>
				<p class="snscs-chips">
					<?php foreach ( $popular as $t ) : ?>
						<a class="snscs-chip" href="<?php echo esc_url( self::stories_url( array( 'tag' => $t->slug, 'view' => $view ) ) ); ?>">#<?php echo esc_html( $t->name ); ?></a>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>
			<p class="snscs-help">記事を書けるのは、掲載中の発信者です。<a href="<?php echo esc_url( snscs_portal_url() ); ?>">マイページ</a>から書けます。</p>
		</div>
		<?php
		return ob_get_clean();
	}
}
