<?php
/**
 * 月間アワード（ジャンルごとのファン投票）。
 *
 *   投票: 会員が、ジャンルごとに月1人（月の途中で変えられる）。発信者本人は自分に投票できない。
 *         投票はすぐにできるが、数えるのは登録から3日たったアカウントの票だけ（作ったばかりのアカウントでの水増しを防ぐ）。
 *         同じ端末・同じ日（日付入りのハッシュ）からの票は、ジャンルごとに2アカウント分まで。
 *         不正対策で順位を下げられている発信者は、受賞の対象にしない。
 *   ページ: [sns_awards]（機能ページ「アワード」）。今月の途中経過（ジャンルのタブ）と、これまでの受賞者。
 *   発信者ページ: 「今月の○○アワードに投票する」（いまの順位と票数）、受賞の印。
 *   締め: 毎月1日に前の月を締め、ジャンルごとに上位3人（3票以上）を受賞にする。受賞者にはお知らせ
 *         （サイト内・メール・スマホ通知）と受賞画像（1080×1080。SNSでシェア用）。
 *   マイページ: いまの順位・票数、フォロワーに投票を呼びかける文とリンク（紹介リンク）、受賞画像。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Awards {

	const RESULTS   = 'snscs_award_results';
	const CLOSED    = 'snscs_award_closed';
	const MIN_VOTES = 3;
	const PER_HASH  = 2;
	const IMAGE_VAR = 'snscs_award_img';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( SNSCS_Inbox::CRON_HOOK, array( __CLASS__, 'maybe_close' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_image' ), 0 );
		add_shortcode( 'sns_awards', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_script' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_award_votes';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  month char(7) NOT NULL,
  genre_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  creator_id bigint(20) unsigned NOT NULL,
  visitor char(16) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (month,genre_id,user_id),
  KEY month_creator (month,creator_id)
) {$charset};"
		);
	}

	public static function month() {
		return current_time( 'Y-m' );
	}

	public static function month_label( $month ) {
		return gmdate( 'Y年n月', strtotime( $month . '-01' ) );
	}

	/* --------------------------------------------------------------------
	 * 投票
	 * ------------------------------------------------------------------ */

	public static function register_routes() {
		register_rest_route(
			'snscs/v1',
			'/award/vote',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( __CLASS__, 'rest_vote' ),
				'args'                => array(
					'creator' => array( 'type' => 'integer', 'required' => true ),
					'genre'   => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public static function vote( $user_id, $creator_id, $genre_id ) {
		global $wpdb;
		if ( SNSCS_Post_Types::POST_TYPE !== get_post_type( $creator_id ) || 'publish' !== get_post_status( $creator_id ) || ! has_term( (int) $genre_id, SNSCS_Post_Types::TAX_GENRE, $creator_id ) ) {
			return new WP_Error( 'snscs_award', 'この発信者には投票できません。' );
		}
		if ( SNSCS_Social::own_creator( $user_id ) === (int) $creator_id ) {
			return new WP_Error( 'snscs_award', '自分には投票できません。' );
		}
		$wpdb->replace(
			self::table(),
			array(
				'month'      => self::month(),
				'genre_id'   => (int) $genre_id,
				'user_id'    => (int) $user_id,
				'creator_id' => (int) $creator_id,
				'visitor'    => substr( SNSCS_Referrals::visitor_hash(), 0, 16 ),
				'created_at' => current_time( 'mysql' ),
			)
		);
		wp_cache_delete( 'snscs_award_' . self::month(), 'snscs' );
		return true;
	}

	public static function rest_vote( WP_REST_Request $request ) {
		$result = self::vote( get_current_user_id(), (int) $request['creator'], (int) $request['genre'] );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => $result->get_error_message() ), 400 );
		}
		$user  = wp_get_current_user();
		$young = strtotime( $user->user_registered ) > time() - SNSCS_Social::MIN_AGE_DAYS * DAY_IN_SECONDS;
		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => $young ? '投票しました！登録から3日たつと票に数えられます（今月中の投票が対象です）。' : '投票しました！ありがとうございます。',
			),
			200
		);
	}

	/**
	 * 会員がそのジャンルで今月投票した発信者（なければ0）。
	 */
	public static function my_vote( $user_id, $genre_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT creator_id FROM ' . self::table() . ' WHERE month = %s AND genre_id = %d AND user_id = %d', self::month(), $genre_id, $user_id ) );
	}

	/* --------------------------------------------------------------------
	 * 集計
	 * ------------------------------------------------------------------ */

	/**
	 * 月の票（数える票だけ）。
	 *
	 * @param string $month 対象の月。
	 * @param string $until この時点で登録から3日たっているアカウントだけ（締めのときは月末）。
	 * @return array<int,array<int,int>> ジャンルID => [発信者ID => 票数]（多い順）
	 */
	public static function tally( $month, $until = '' ) {
		$cache_key = 'snscs_award_' . $month;
		if ( '' === $until ) {
			$cached = wp_cache_get( $cache_key, 'snscs' );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '' !== $until ? $until : current_time( 'mysql', true ) ) - SNSCS_Social::MIN_AGE_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows      = $wpdb->get_results( $wpdb->prepare( 'SELECT v.genre_id, v.creator_id, v.visitor, DATE(v.created_at) AS d FROM ' . self::table() . " v INNER JOIN {$wpdb->users} u ON u.ID = v.user_id AND u.user_registered <= %s WHERE v.month = %s", $cutoff, $month ), ARRAY_A );
		$per_hash  = array();
		$out       = array();
		$penalties = SNSCS_Integrity::penalties();
		foreach ( $rows as $row ) {
			$c = (int) $row['creator_id'];
			if ( ( $penalties[ $c ] ?? 1 ) < 1 ) {
				continue;
			}
			$hk = $row['genre_id'] . '|' . $row['visitor'] . '|' . $row['d'];
			if ( '' !== $row['visitor'] && ( $per_hash[ $hk ] = ( $per_hash[ $hk ] ?? 0 ) + 1 ) > self::PER_HASH ) {
				continue;
			}
			$out[ (int) $row['genre_id'] ][ $c ] = ( $out[ (int) $row['genre_id'] ][ $c ] ?? 0 ) + 1;
		}
		foreach ( $out as &$list ) {
			arsort( $list );
		}
		unset( $list );
		if ( '' === $until ) {
			wp_cache_set( $cache_key, $out, 'snscs', 5 * MINUTE_IN_SECONDS );
		}
		return $out;
	}

	/**
	 * 今月の順位と票数（そのジャンルで票がなければ null）。
	 *
	 * @return array{rank:int,votes:int}|null
	 */
	public static function standing( $creator_id, $genre_id ) {
		$list = self::tally( self::month() )[ (int) $genre_id ] ?? array();
		if ( ! isset( $list[ $creator_id ] ) ) {
			return null;
		}
		$rank = 1;
		foreach ( $list as $votes ) {
			if ( $votes > $list[ $creator_id ] ) {
				++$rank;
			}
		}
		return array( 'rank' => $rank, 'votes' => $list[ $creator_id ] );
	}

	/**
	 * 前の月を締める（毎月1日以降の最初の見回りで1回）。
	 */
	public static function maybe_close( $force_month = '' ) {
		$month = '' !== $force_month ? $force_month : gmdate( 'Y-m', strtotime( current_time( 'Y-m-01' ) . ' -1 month' ) );
		if ( '' === $force_month && get_option( self::CLOSED ) === $month ) {
			return 0;
		}
		update_option( self::CLOSED, $month, false );
		$end     = gmdate( 'Y-m-t 23:59:59', strtotime( $month . '-01' ) );
		$results = (array) get_option( self::RESULTS, array() );
		$winners = 0;
		$month_r = array();
		foreach ( self::tally( $month, get_gmt_from_date( $end ) ) as $genre_id => $list ) {
			$rank = 0;
			foreach ( $list as $creator_id => $votes ) {
				if ( $votes < self::MIN_VOTES || ++$rank > 3 ) {
					break;
				}
				$month_r[ $genre_id ][] = array( (int) $creator_id, (int) $votes );
				global $wpdb;
				$user = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'snscs_creator_id' AND meta_value = %d LIMIT 1", $creator_id ) );
				if ( $user ) {
					SNSCS_Inbox::add( $user, 'award', (int) $creator_id );
				}
				++$winners;
			}
		}
		if ( $month_r ) {
			$results[ $month ] = $month_r;
			krsort( $results );
			update_option( self::RESULTS, array_slice( $results, 0, 36, true ), false );
			do_action( 'snscs_awards_closed', $month, $month_r );
		}
		return $winners;
	}

	/**
	 * 発信者の受賞（新しい順）。
	 *
	 * @return array<int,array{month:string,genre:WP_Term,rank:int,votes:int}>
	 */
	public static function wins( $creator_id ) {
		$out = array();
		foreach ( (array) get_option( self::RESULTS, array() ) as $month => $genres ) {
			foreach ( $genres as $genre_id => $list ) {
				foreach ( $list as $i => $row ) {
					if ( (int) $row[0] === (int) $creator_id ) {
						$term = get_term( (int) $genre_id, SNSCS_Post_Types::TAX_GENRE );
						if ( $term && ! is_wp_error( $term ) ) {
							$out[] = array( 'month' => $month, 'genre' => $term, 'rank' => $i + 1, 'votes' => (int) $row[1] );
						}
					}
				}
			}
		}
		return $out;
	}

	/**
	 * 最近（2か月以内）の受賞の短い表記（順位バッジ用）。例: 「9月 料理アワード1位」
	 */
	public static function recent_label( $creator_id ) {
		foreach ( self::wins( $creator_id ) as $win ) {
			if ( $win['month'] >= gmdate( 'Y-m', strtotime( current_time( 'Y-m-01' ) . ' -2 month' ) ) ) {
				return gmdate( 'n月', strtotime( $win['month'] . '-01' ) ) . ' ' . $win['genre']->name . 'アワード' . $win['rank'] . '位';
			}
		}
		return '';
	}

	/* --------------------------------------------------------------------
	 * 表示
	 * ------------------------------------------------------------------ */

	public static function page_url( $genre_slug = '' ) {
		$url = SNSCS_Hub::page_url( 'awards' );
		return ( $url && '' !== $genre_slug ) ? add_query_arg( 'award_genre', $genre_slug, $url ) : $url;
	}

	public static function register_script() {
		wp_register_script( 'snscs-awards', SNSCS_URL . 'assets/js/snscs-awards.js', array(), SNSCS_VERSION, true );
		wp_localize_script(
			'snscs-awards',
			'snscsAwards',
			array(
				'rest'  => esc_url_raw( rest_url( 'snscs/v1/award/vote' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * 投票ボタン（ログインしていなければ会員登録へ）。
	 */
	public static function vote_button( $creator_id, $genre_id, $label = '投票する' ) {
		wp_enqueue_script( 'snscs-awards' );
		if ( ! is_user_logged_in() ) {
			$back = get_permalink( $creator_id ) . '#snscs-award';
			$url  = SNSCS_Social::member_url( array( 'redirect_to' => $back ) );
			return $url ? '<a class="snscs-button snscs-button--primary" href="' . esc_url( $url ) . '">無料の会員登録をして投票する</a>' : '';
		}
		$mine = self::my_vote( get_current_user_id(), $genre_id ) === (int) $creator_id;
		return sprintf(
			'<button type="button" class="snscs-button snscs-button--primary snscs-award__vote%s" data-snscs-vote data-creator="%d" data-genre="%d"%s>%s</button>',
			$mine ? ' is-on' : '',
			(int) $creator_id,
			(int) $genre_id,
			$mine ? ' disabled' : '',
			$mine ? '投票済み' : esc_html( $label )
		);
	}

	/**
	 * 発信者ページ: 投票の欄と受賞の印。
	 */
	public static function creator_box( $creator_id ) {
		if ( ! SNSCS_Hub::page_id( 'awards' ) ) {
			return '';
		}
		$genres = get_the_terms( $creator_id, SNSCS_Post_Types::TAX_GENRE );
		if ( ! $genres || is_wp_error( $genres ) ) {
			return '';
		}
		wp_enqueue_style( 'snscs' );
		$name = html_entity_decode( get_the_title( $creator_id ), ENT_QUOTES, 'UTF-8' );
		$html = '<section class="snscs-award" id="snscs-award">';
		$wins = self::wins( $creator_id );
		if ( $wins ) {
			$html .= '<ul class="snscs-award__wins">';
			foreach ( array_slice( $wins, 0, 3 ) as $win ) {
				$html .= '<li>🏆 ' . esc_html( self::month_label( $win['month'] ) . ' ' . $win['genre']->name . 'アワード ' . $win['rank'] . '位' ) . '</li>';
			}
			$html .= '</ul>';
		}
		$html .= '<h2 class="snscs-award__title">' . esc_html( self::month_label( self::month() ) . 'の発信者アワード（ファン投票）' ) . '</h2>';
		foreach ( array_slice( $genres, 0, 2 ) as $genre ) {
			$st    = self::standing( $creator_id, $genre->term_id );
			$html .= '<div class="snscs-award__row"><p>' . esc_html( $genre->name . 'アワード' . ( $st ? '：いま' . $st['rank'] . '位（' . $st['votes'] . '票）' : '：まだ票がありません' ) ) . '</p>' . self::vote_button( $creator_id, $genre->term_id, $name . 'さんに投票する' ) . '</div>';
		}
		$html .= '<p class="snscs-help">投票は月に1ジャンル1人まで（月の途中で変えられます）。<a href="' . esc_url( self::page_url() ) . '">今月の順位を見る</a></p>';
		return $html . '</section>';
	}

	public static function shortcode() {
		SNSCS_Hub::remember_page( 'awards' );
		wp_enqueue_style( 'snscs' );
		$genres = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => true ) );
		if ( ! $genres || is_wp_error( $genres ) ) {
			return '';
		}
		$tally   = self::tally( self::month() );
		$current = isset( $_GET['award_genre'] ) ? get_term_by( 'slug', sanitize_title( wp_unslash( $_GET['award_genre'] ) ), SNSCS_Post_Types::TAX_GENRE ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $current ) {
			// 票の多いジャンルから。
			usort(
				$genres,
				function ( $a, $b ) use ( $tally ) {
					return array_sum( $tally[ $b->term_id ] ?? array() ) - array_sum( $tally[ $a->term_id ] ?? array() );
				}
			);
			$current = $genres[0];
		}
		$end = gmdate( 'n月j日', strtotime( current_time( 'Y-m-t' ) ) );
		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		?>
		<div class="snscs-awards">
			<p class="snscs-awards__lead"><?php echo esc_html( self::month_label( self::month() ) . 'の発信者アワード。ジャンルごとに、いちばん応援したい発信者に投票してください（投票は' . $end . 'まで。会員なら誰でも、無料で投票できます）。' ); ?></p>
			<nav class="snscs-trendtabs" aria-label="アワードのジャンル"><ul>
				<?php foreach ( $genres as $genre ) : ?>
					<li><a class="snscs-trendtabs__tab<?php echo $genre->term_id === $current->term_id ? ' is-current' : ''; ?>" href="<?php echo esc_url( self::page_url( $genre->slug ) ); ?>"><?php echo esc_html( $genre->name ); ?></a></li>
				<?php endforeach; ?>
			</ul></nav>
			<h2 class="snscs-trendpage__title"><?php echo esc_html( $current->name . 'アワード いまの順位' ); ?></h2>
			<?php
			$list = array_slice( $tally[ $current->term_id ] ?? array(), 0, 10, true );
			if ( $list ) {
				$badges = array();
				$notes  = array();
				$rank   = 0;
				foreach ( $list as $id => $votes ) {
					$badges[ $id ] = ( ++$rank ) . '位';
					$notes[ $id ]  = $votes . '票';
				}
				SNSCS_Frontend::render_cards( SNSCS_Personalize::items( array_keys( $list ) ), 0, $badges, $notes );
			} else {
				echo '<p class="snscs-results__empty">まだ票がありません。発信者のページの「投票する」から投票できます。</p>';
			}
			?>
			<p class="snscs-feature__more"><a class="snscs-button" href="<?php echo esc_url( SNSCS_Features::url( '', $current->slug ) ); ?>"><?php echo esc_html( $current->name . 'の発信者を探して投票する' ); ?></a></p>
			<?php
			$results = (array) get_option( self::RESULTS, array() );
			if ( $results ) :
				?>
				<h2 class="snscs-trendpage__title">これまでの受賞者</h2>
				<?php foreach ( array_slice( $results, 0, 3, true ) as $month => $by_genre ) : ?>
					<h3><?php echo esc_html( self::month_label( $month ) ); ?></h3>
					<ul class="snscs-award__history">
						<?php foreach ( $by_genre as $genre_id => $rows ) : ?>
							<?php $g = get_term( (int) $genre_id, SNSCS_Post_Types::TAX_GENRE ); ?>
							<?php if ( $g && ! is_wp_error( $g ) && $rows ) : ?>
								<li><strong><?php echo esc_html( $g->name ); ?></strong>：
									<?php
									$names = array();
									foreach ( $rows as $i => $row ) {
										$names[] = ( $i + 1 ) . '位 <a href="' . esc_url( get_permalink( $row[0] ) ) . '">' . esc_html( get_the_title( $row[0] ) ) . '</a>';
									}
									echo implode( '　', $names ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
									?>
								</li>
							<?php endif; ?>
						<?php endforeach; ?>
					</ul>
				<?php endforeach; ?>
			<?php endif; ?>
			<p class="snscs-help">票に数えるのは、登録から3日たった会員の票です。同じ端末からたくさんのアカウントで投票した分や、不正な順位上げをしている発信者への票は数えません。毎月1日に前の月を締め、ジャンルごとに3票以上の上位3人が受賞します。</p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * マイページ: 今月の順位と、投票の呼びかけ。
	 */
	public static function render_portal( $creator_id ) {
		if ( ! SNSCS_Hub::page_id( 'awards' ) ) {
			return;
		}
		$genres = get_the_terms( $creator_id, SNSCS_Post_Types::TAX_GENRE );
		if ( ! $genres || is_wp_error( $genres ) ) {
			return;
		}
		$link = SNSCS_Referrals::link_for( $creator_id ) . '#snscs-award';
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$text = sprintf( '「%s」の%sの発信者アワードに参加しています！応援してくれる方は投票をお願いします🙏（無料・1分）', $site, self::month_label( self::month() ) );
		?>
		<section class="snscs-panel snscs-award-portal" id="snscs-award">
			<h2 class="snscs-panel__title"><?php echo esc_html( self::month_label( self::month() ) . 'の発信者アワード' ); ?></h2>
			<ul>
				<?php foreach ( array_slice( $genres, 0, 2 ) as $genre ) : ?>
					<?php $st = self::standing( $creator_id, $genre->term_id ); ?>
					<li><?php echo esc_html( $genre->name . 'アワード：' . ( $st ? 'いま' . $st['rank'] . '位（' . $st['votes'] . '票）' : 'まだ票がありません' ) ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p>フォロワーに投票を呼びかけましょう。受賞すると、受賞画像と「アワード受賞」の印が付き、順位バッジにも入ります。</p>
			<div class="snscs-share">
				<a class="snscs-share__button snscs-share__button--x" href="<?php echo esc_url( 'https://x.com/intent/tweet?' . http_build_query( array( 'text' => $text, 'url' => $link ) ) ); ?>" target="_blank" rel="noopener">Xで呼びかける</a>
				<a class="snscs-share__button snscs-share__button--threads" href="<?php echo esc_url( 'https://www.threads.net/intent/post?' . http_build_query( array( 'text' => $text . ' ' . $link ) ) ); ?>" target="_blank" rel="noopener">Threadsで呼びかける</a>
				<a class="snscs-share__button snscs-share__button--line" href="<?php echo esc_url( 'https://social-plugins.line.me/lineit/share?' . http_build_query( array( 'url' => $link ) ) ); ?>" target="_blank" rel="noopener">LINEで送る</a>
			</div>
			<p class="snscs-help">投票用のリンク（あなたの紹介リンク）: <input type="text" readonly value="<?php echo esc_attr( $link ); ?>" onclick="this.select();" class="snscs-input"></p>
			<?php foreach ( array_slice( self::wins( $creator_id ), 0, 3 ) as $win ) : ?>
				<?php $img = self::image_url( $creator_id, $win['month'] ); ?>
				<div class="snscs-award-portal__win">
					<p>🏆 <?php echo esc_html( self::month_label( $win['month'] ) . ' ' . $win['genre']->name . 'アワード ' . $win['rank'] . '位（' . $win['votes'] . '票）' ); ?></p>
					<p><img src="<?php echo esc_url( $img ); ?>" alt="受賞画像" width="300" height="300" loading="lazy"></p>
					<p><a class="snscs-button" href="<?php echo esc_url( $img ); ?>" download>受賞画像を保存</a>
						<a class="snscs-share__button snscs-share__button--x" href="<?php echo esc_url( 'https://x.com/intent/tweet?' . http_build_query( array( 'text' => sprintf( '「%s」の%s %sアワードで%d位をいただきました！投票してくださった皆さん、ありがとうございます🎉', $site, self::month_label( $win['month'] ), $win['genre']->name, $win['rank'] ), 'url' => SNSCS_Referrals::link_for( $creator_id ) ) ) ); ?>" target="_blank" rel="noopener">受賞をXでシェア</a></p>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/* --------------------------------------------------------------------
	 * 受賞画像（1080×1080）
	 * ------------------------------------------------------------------ */

	public static function image_url( $creator_id, $month ) {
		return add_query_arg( array( self::IMAGE_VAR => (int) $creator_id, 'm' => $month ), home_url( '/' ) );
	}

	public static function serve_image() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET[ self::IMAGE_VAR ] ) ) {
			return;
		}
		$creator = absint( $_GET[ self::IMAGE_VAR ] );
		$month   = isset( $_GET['m'] ) ? preg_replace( '/[^0-9-]/', '', (string) wp_unslash( $_GET['m'] ) ) : '';
		// phpcs:enable
		$win = null;
		foreach ( self::wins( $creator ) as $w ) {
			if ( $w['month'] === $month ) {
				$win = $w;
				break;
			}
		}
		$image = $win ? self::draw( $creator, $win ) : false;
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

	public static function draw( $creator_id, array $win ) {
		$font = SNSCS_Share::font();
		if ( ! $font || ! function_exists( 'imagecreatetruecolor' ) ) {
			return false;
		}
		$s     = 1080;
		$image = imagecreatetruecolor( $s, $s );
		imageantialias( $image, true );
		for ( $y = 0; $y < $s; $y++ ) {
			$t = $y / $s;
			imageline( $image, 0, $y, $s, $y, imagecolorallocate( $image, (int) ( 120 + 60 * $t ), (int) ( 53 + 20 * $t ), (int) ( 15 + 10 * $t ) ) );
		}
		$gold  = imagecolorallocate( $image, 253, 224, 71 );
		$white = imagecolorallocate( $image, 255, 255, 255 );
		$soft  = imagecolorallocate( $image, 254, 243, 199 );
		$ink   = imagecolorallocate( $image, 31, 41, 55 );
		$text  = function ( $str, $size, $y, $color, $max = 940 ) use ( $image, $font, $s ) {
			list( $str, $size ) = SNSCS_Share::fit( $str, $font, $size, $max );
			$box = imagettfbbox( $size, 0, $font, $str );
			imagettftext( $image, $size, 0, (int) ( ( $s - ( $box[2] - $box[0] ) ) / 2 ), $y, $color, $font, $str );
		};
		$text( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 30, 120, $soft );
		$text( self::month_label( $win['month'] ) . ' 発信者アワード', 46, 210, $white );
		// メダル。
		imagefilledellipse( $image, 540, 420, 300, 300, $gold );
		imagefilledellipse( $image, 540, 420, 250, 250, imagecolorallocate( $image, 234, 179, 8 ) );
		$text( $win['rank'] . '位', 96, 465, $ink, 220 );
		$text( $win['genre']->name . 'アワード', 54, 660, $gold );
		SNSCS_Share::rounded_rect( $image, 90, 710, $s - 90, 880, 28, $white );
		$text( html_entity_decode( get_the_title( $creator_id ), ENT_QUOTES, 'UTF-8' ) . 'さん', 52, 815, $ink, 860 );
		$text( 'ファン投票 ' . $win['votes'] . '票　投票してくださった皆さん、ありがとうございます', 26, 960, $soft );
		$text( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 24, 1030, $soft );
		return $image;
	}
}
