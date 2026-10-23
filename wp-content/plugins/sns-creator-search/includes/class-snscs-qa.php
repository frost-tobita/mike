<?php
/**
 * SNS運用の質問箱（Q&A）。
 *
 *   質問 : 会員が、SNS・ジャンルを選んで質問する（題名10〜100字、本文20〜1500字、1日3件まで）。
 *   回答 : 掲載中の発信者だけが答えられる（20〜2000字、1つの質問に1回、1日20件まで、自分の質問には答えない）。
 *          質問した人にお知らせ。「参考になった」は会員が1回ずつ押せる（自分の回答は押せない）。
 *          質問した人が「ベストアンサー」を選べる（選ばれた発信者にお知らせ）。
 *   回答者バッジ: 5回以上答えた発信者に「回答者」、ベストアンサー3回以上で「ベストアンサー常連」。
 *   ページ: [sns_qa]（機能ページ「質問箱」）に新着・回答待ち・人気（SNS・ジャンルで絞り込み）と質問のフォーム。
 *          /qa/{番号}/ が質問のページ。構造化データは QAPage（Google の Q&A の検索結果）。
 *          回答が1つ以上ある質問だけ検索エンジンに載せ、サイトマップに入れる。
 *   マイページ: あなたのジャンルの回答待ちの質問と、答えた数・ベストアンサーの数。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_QA {

	const QUERY_VAR   = 'snscs_qa';
	const SEEN_KIND   = 10;
	const BADGE_COUNT = 5;
	const BADGE_BEST  = 3;

	public static function init() {
		SNSCS_Subpages::route( 'qa', self::QUERY_VAR );
		SNSCS_Subpages::sitemap( 'snscsqa', array( __CLASS__, 'sitemap_urls' ), array( __CLASS__, 'sitemap_count' ) );
		add_action( 'template_redirect', array( __CLASS__, 'resolve' ), 5 );
		add_shortcode( 'sns_qa', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_snscs_qa_ask', array( __CLASS__, 'handle_ask' ) );
		add_action( 'admin_post_snscs_qa_answer', array( __CLASS__, 'handle_answer' ) );
		add_action( 'admin_post_snscs_qa_best', array( __CLASS__, 'handle_best' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function questions_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_questions';
	}

	public static function answers_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_answers';
	}

	public static function votes_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_answer_votes';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$q       = self::questions_table();
		$a       = self::answers_table();
		$v       = self::votes_table();
		dbDelta(
			"CREATE TABLE {$q} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  title varchar(100) NOT NULL,
  body text NOT NULL,
  platform varchar(32) NOT NULL DEFAULT '',
  genre_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status tinyint(1) unsigned NOT NULL DEFAULT 1,
  answers smallint(5) unsigned NOT NULL DEFAULT 0,
  views int(10) unsigned NOT NULL DEFAULT 0,
  best_answer bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status_created (status,created_at),
  KEY genre_id (genre_id),
  KEY user_id (user_id)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$a} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  question_id bigint(20) unsigned NOT NULL,
  creator_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  body text NOT NULL,
  helpful int(10) unsigned NOT NULL DEFAULT 0,
  status tinyint(1) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY question_id (question_id),
  KEY creator_id (creator_id)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$v} (
  answer_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (answer_id,user_id)
) {$charset};"
		);
	}

	/* --------------------------------------------------------------------
	 * 読み書き
	 * ------------------------------------------------------------------ */

	public static function question( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::questions_table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	public static function answer( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::answers_table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	/**
	 * 表示する回答（ベストアンサー → 参考になった数 → 古い順）。
	 */
	public static function answers( array $q ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT a.* FROM ' . self::answers_table() . " a INNER JOIN {$wpdb->posts} p ON p.ID = a.creator_id AND p.post_status = 'publish' WHERE a.question_id = %d AND a.status = 1 ORDER BY (a.id = %d) DESC, a.helpful DESC, a.created_at ASC", $q['id'], $q['best_answer'] ), ARRAY_A );
	}

	public static function recount( $question_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::answers_table() . ' WHERE question_id = %d AND status = 1', $question_id ) );
		$wpdb->update( self::questions_table(), array( 'answers' => $n ), array( 'id' => $question_id ) );
	}

	private static function count_today( $table, $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND created_at >= %s", $user_id, gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - DAY_IN_SECONDS ) ) );
	}

	/**
	 * @return int|WP_Error
	 */
	public static function ask( $user_id, $title, $body, $platform, $genre_id ) {
		global $wpdb;
		$title = SNSCS_UGC::clean( $title, 100 );
		$body  = SNSCS_UGC::clean( $body, 1500, true );
		if ( mb_strlen( $title ) < 10 || mb_strlen( $body ) < 20 ) {
			return new WP_Error( 'snscs_qa', '題名は10字以上、本文は20字以上で書いてください。' );
		}
		if ( SNSCS_UGC::blocked( $title . ' ' . $body ) ) {
			return new WP_Error( 'snscs_qa', '使えない言葉が含まれています。' );
		}
		if ( self::count_today( self::questions_table(), $user_id ) >= 3 ) {
			return new WP_Error( 'snscs_qa', '質問は1日3件までです。' );
		}
		$genre = $genre_id ? get_term( (int) $genre_id, SNSCS_Post_Types::TAX_GENRE ) : null;
		$wpdb->insert(
			self::questions_table(),
			array(
				'user_id'    => $user_id,
				'title'      => $title,
				'body'       => $body,
				'platform'   => array_key_exists( $platform, snscs_platforms() ) ? $platform : '',
				'genre_id'   => ( $genre && ! is_wp_error( $genre ) ) ? (int) $genre->term_id : 0,
				'created_at' => current_time( 'mysql' ),
			)
		);
		// ポイントの記録で insert_id が変わるので、先に取っておく。
		$id = (int) $wpdb->insert_id;
		do_action( 'snscs_points', $user_id, 'question' );
		return $id;
	}

	/**
	 * @return int|WP_Error
	 */
	public static function reply( $user_id, $question_id, $body ) {
		global $wpdb;
		$creator = SNSCS_Social::own_creator( $user_id );
		$q       = self::question( $question_id );
		if ( ! $creator || 'publish' !== get_post_status( $creator ) ) {
			return new WP_Error( 'snscs_qa', '回答できるのは、掲載中の発信者です。' );
		}
		if ( ! $q || ! $q['status'] || (int) $q['user_id'] === (int) $user_id ) {
			return new WP_Error( 'snscs_qa', 'この質問には回答できません。' );
		}
		$body = SNSCS_UGC::clean( $body, 2000, true );
		if ( mb_strlen( $body ) < 20 ) {
			return new WP_Error( 'snscs_qa', '回答は20字以上で書いてください。' );
		}
		if ( SNSCS_UGC::blocked( $body ) ) {
			return new WP_Error( 'snscs_qa', '使えない言葉が含まれています。' );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::answers_table() . ' WHERE question_id = %d AND creator_id = %d', $question_id, $creator ) ) ) {
			return new WP_Error( 'snscs_qa', 'この質問には、もう回答しています。' );
		}
		if ( self::count_today( self::answers_table(), $user_id ) >= 20 ) {
			return new WP_Error( 'snscs_qa', '回答は1日20件までです。' );
		}
		$wpdb->insert( self::answers_table(), array( 'question_id' => $question_id, 'creator_id' => $creator, 'user_id' => $user_id, 'body' => $body, 'created_at' => current_time( 'mysql' ) ) );
		$id = (int) $wpdb->insert_id;
		self::recount( $question_id );
		SNSCS_Inbox::add( (int) $q['user_id'], 'qa_answer', $question_id, $creator );
		do_action( 'snscs_points', $user_id, 'answer' );
		return $id;
	}

	/**
	 * 「参考になった」をつける・外す。
	 *
	 * @return array{on:bool,helpful:int}|WP_Error
	 */
	public static function toggle_helpful( $user_id, $answer_id ) {
		global $wpdb;
		$a = self::answer( $answer_id );
		if ( ! $a || ! $a['status'] || (int) $a['user_id'] === (int) $user_id ) {
			return new WP_Error( 'snscs_qa', 'この回答には押せません。' );
		}
		$table = self::votes_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$on = ! $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE answer_id = %d AND user_id = %d", $answer_id, $user_id ) );
		if ( $on ) {
			$wpdb->insert( $table, array( 'answer_id' => $answer_id, 'user_id' => $user_id ) );
			do_action( 'snscs_points', (int) $a['user_id'], 'helpful_received' );
		} else {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE answer_id = %d AND user_id = %d", $answer_id, $user_id ) );
		}
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE answer_id = %d", $answer_id ) );
		// phpcs:enable
		$wpdb->update( self::answers_table(), array( 'helpful' => $n ), array( 'id' => $answer_id ) );
		return array( 'on' => $on, 'helpful' => $n );
	}

	/**
	 * 発信者の回答の記録（回答数・ベストアンサー数）とバッジ。
	 *
	 * @return array{answers:int,best:int,badge:string}
	 */
	public static function creator_stats( $creator_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$answers = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::answers_table() . ' WHERE creator_id = %d AND status = 1', $creator_id ) );
		$best    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::answers_table() . ' a INNER JOIN ' . self::questions_table() . ' q ON q.best_answer = a.id WHERE a.creator_id = %d AND a.status = 1', $creator_id ) );
		// phpcs:enable
		$badge = $best >= self::BADGE_BEST ? 'ベストアンサー常連' : ( $answers >= self::BADGE_COUNT ? '回答者' : '' );
		return array( 'answers' => $answers, 'best' => $best, 'badge' => $badge );
	}

	/**
	 * お知らせの文（qa_answer: 質問した人へ / qa_best: 発信者へ）。
	 */
	public static function describe( array $row ) {
		$q = self::question( (int) $row['object_id'] );
		if ( ! $q || ! $q['status'] ) {
			return null;
		}
		if ( 'qa_best' === $row['type'] ) {
			return array( 'text' => 'あなたの回答が、質問「' . $q['title'] . '」のベストアンサーに選ばれました！', 'url' => self::url( (int) $q['id'] ) );
		}
		$who = html_entity_decode( get_the_title( (int) $row['actor_id'] ), ENT_QUOTES, 'UTF-8' );
		return array( 'text' => ( (int) $row['count'] > 1 ? $who . 'さんほか' . ( (int) $row['count'] - 1 ) . '人' : $who . 'さん' ) . 'が、あなたの質問「' . $q['title'] . '」に回答しました。', 'url' => self::url( (int) $q['id'] ) );
	}

	/* --------------------------------------------------------------------
	 * フォームの受け取り
	 * ------------------------------------------------------------------ */

	private static function back( $url, $notice ) {
		wp_safe_redirect( add_query_arg( 'snscs_qa_msg', rawurlencode( $notice ), $url ) );
		exit;
	}

	public static function handle_ask() {
		check_admin_referer( 'snscs_qa_ask', 'snscs_nonce' );
		$base = SNSCS_Hub::page_url( 'qa' );
		if ( ! is_user_logged_in() ) {
			self::back( $base, 'ログインしてください。' );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() で確認済み。
		$id = self::ask( get_current_user_id(), wp_unslash( $_POST['title'] ?? '' ), wp_unslash( $_POST['body'] ?? '' ), sanitize_key( $_POST['platform'] ?? '' ), absint( $_POST['genre'] ?? 0 ) );
		// phpcs:enable
		if ( is_wp_error( $id ) ) {
			self::back( $base, $id->get_error_message() );
		}
		self::back( self::url( $id ), '質問しました。発信者から回答が届くと、お知らせします。' );
	}

	public static function handle_answer() {
		check_admin_referer( 'snscs_qa_answer', 'snscs_nonce' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() で確認済み。
		$qid = absint( $_POST['question'] ?? 0 );
		$id  = self::reply( get_current_user_id(), $qid, wp_unslash( $_POST['body'] ?? '' ) );
		// phpcs:enable
		self::back( self::url( $qid ) . ( is_wp_error( $id ) ? '' : '#answer-' . $id ), is_wp_error( $id ) ? $id->get_error_message() : '回答しました。ありがとうございます！' );
	}

	public static function handle_best() {
		check_admin_referer( 'snscs_qa_best', 'snscs_nonce' );
		global $wpdb;
		$a = self::answer( absint( $_POST['answer'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$q = $a ? self::question( (int) $a['question_id'] ) : null;
		if ( ! $q || (int) $q['user_id'] !== get_current_user_id() || ! $a['status'] ) {
			self::back( SNSCS_Hub::page_url( 'qa' ), '選べませんでした。' );
		}
		$wpdb->update( self::questions_table(), array( 'best_answer' => (int) $a['id'] ), array( 'id' => (int) $q['id'] ) );
		SNSCS_Inbox::add( (int) $a['user_id'], 'qa_best', (int) $q['id'] );
		do_action( 'snscs_points', (int) $a['user_id'], 'best_answer' );
		self::back( self::url( (int) $q['id'] ) . '#answer-' . (int) $a['id'], 'ベストアンサーに選びました。' );
	}

	public static function register_routes() {
		register_rest_route(
			'snscs/v1',
			'/qa/helpful',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => function ( WP_REST_Request $request ) {
					$r = self::toggle_helpful( get_current_user_id(), (int) $request['answer'] );
					return is_wp_error( $r ) ? new WP_REST_Response( array( 'ok' => false, 'message' => $r->get_error_message() ), 400 ) : new WP_REST_Response( array( 'ok' => true ) + $r, 200 );
				},
			)
		);
	}

	/* --------------------------------------------------------------------
	 * ページ
	 * ------------------------------------------------------------------ */

	public static function url( $id ) {
		return SNSCS_Subpages::url( 'qa', (int) $id );
	}

	private static $current = null;

	public static function resolve() {
		$value = SNSCS_Subpages::value( 'qa' );
		if ( '' === $value ) {
			return;
		}
		$q = ctype_digit( $value ) ? self::question( (int) $value ) : null;
		if ( ! $q || ! $q['status'] ) {
			SNSCS_Subpages::not_found();
			return;
		}
		$answers = self::answers( $q );
		$url     = self::url( (int) $q['id'] );
		$asker   = SNSCS_UGC::author_name( (int) $q['user_id'] );
		$crumbs  = array( array( home_url( '/' ), 'トップ' ), array( SNSCS_Hub::page_url( 'qa' ), '質問箱' ), array( $url, $q['title'] ) );
		$ans_ld  = function ( $a ) use ( $url ) {
			return array(
				'@type'       => 'Answer',
				'text'        => $a['body'],
				'dateCreated' => mysql2date( 'c', $a['created_at'] ),
				'upvoteCount' => (int) $a['helpful'],
				'url'         => $url . '#answer-' . (int) $a['id'],
				'author'      => array( '@type' => 'Person', 'name' => html_entity_decode( get_the_title( (int) $a['creator_id'] ), ENT_QUOTES, 'UTF-8' ), 'url' => get_permalink( (int) $a['creator_id'] ) ),
			);
		};
		$question = array(
			'@type'       => 'Question',
			'name'        => $q['title'],
			'text'        => $q['body'],
			'answerCount' => count( $answers ),
			'dateCreated' => mysql2date( 'c', $q['created_at'] ),
			'author'      => array( '@type' => 'Person', 'name' => $asker ),
		);
		$accepted = null;
		$others   = array();
		foreach ( $answers as $a ) {
			if ( (int) $a['id'] === (int) $q['best_answer'] ) {
				$accepted = $ans_ld( $a );
			} else {
				$others[] = $ans_ld( $a );
			}
		}
		if ( $accepted ) {
			$question['acceptedAnswer'] = $accepted;
		}
		if ( $others ) {
			$question['suggestedAnswer'] = $others;
		}
		self::$current = array( 'q' => $q, 'answers' => $answers, 'asker' => $asker, 'url' => $url, 'crumbs' => $crumbs );
		SNSCS_Subpages::set_current(
			array(
				'page_key'    => 'qa',
				'title'       => $q['title'] . '｜SNS運用の質問箱',
				'heading'     => $q['title'],
				'description' => mb_substr( preg_replace( '/\s+/u', ' ', $q['body'] ), 0, 120 ) . ( $answers ? '（発信者の回答' . count( $answers ) . '件）' : '' ),
				'url'         => $url,
				'indexable'   => (bool) $answers,
				'jsonld'      => array( SNSCS_Subpages::breadcrumb_ld( $crumbs ), array( '@type' => 'QAPage', 'mainEntity' => $question ) ),
			)
		);
		if ( ! SNSCS_Referrals::is_bot_request() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . SNSCS_Feed::seen_table() . ' (day, visitor, article_id, kind) VALUES (%s, %s, %d, %d)', current_time( 'Y-m-d' ), substr( SNSCS_Referrals::visitor_hash(), 0, 16 ), $q['id'], self::SEEN_KIND ) ) ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::questions_table() . ' SET views = views + 1 WHERE id = %d', $q['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}

	private static function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['snscs_qa_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['snscs_qa_msg'] ) ) : '';
		return '' !== $msg ? '<p class="snscs-notice" role="status">' . esc_html( $msg ) . '</p>' : '';
	}

	private static function tags( array $q ) {
		$html = '';
		if ( '' !== $q['platform'] ) {
			$html .= '<span class="snscs-chip">' . esc_html( snscs_platform_label( $q['platform'] ) ) . '</span> ';
		}
		$g = $q['genre_id'] ? get_term( (int) $q['genre_id'], SNSCS_Post_Types::TAX_GENRE ) : null;
		if ( $g && ! is_wp_error( $g ) ) {
			$html .= '<span class="snscs-chip">' . esc_html( $g->name ) . '</span>';
		}
		return $html;
	}

	public static function shortcode() {
		SNSCS_Hub::remember_page( 'qa' );
		wp_enqueue_style( 'snscs' );
		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo self::notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		if ( self::$current ) {
			self::render_question( self::$current );
		} else {
			self::render_index();
		}
		return ob_get_clean();
	}

	private static function ask_form() {
		if ( ! is_user_logged_in() ) {
			$url = SNSCS_Social::member_url( array( 'redirect_to' => SNSCS_Hub::page_url( 'qa' ) ) );
			return $url ? '<p><a class="snscs-button snscs-button--primary" href="' . esc_url( $url ) . '">無料の会員登録をして質問する</a></p>' : '';
		}
		$genres = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => true ) );
		ob_start();
		?>
		<details class="snscs-qa__ask">
			<summary class="snscs-button snscs-button--primary">質問する</summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="snscs_qa_ask">
				<?php wp_nonce_field( 'snscs_qa_ask', 'snscs_nonce' ); ?>
				<p><label>題名（10〜100字）<input class="snscs-input" type="text" name="title" minlength="10" maxlength="100" required placeholder="例: Instagramのリールの再生数が伸びません"></label></p>
				<p><label>くわしく（20〜1500字。いまやっていること・困っていること）<textarea class="snscs-input" name="body" rows="6" minlength="20" maxlength="1500" required></textarea></label></p>
				<p>
					<select name="platform" aria-label="SNS"><option value="">SNSを選ぶ（任意）</option>
						<?php foreach ( snscs_platforms() as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="genre" aria-label="ジャンル"><option value="0">ジャンルを選ぶ（任意）</option>
						<?php foreach ( is_wp_error( $genres ) ? array() : $genres as $g ) : ?>
							<option value="<?php echo (int) $g->term_id; ?>"><?php echo esc_html( $g->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="snscs-help">質問は公開されます。個人を特定できる情報や、URLは書かないでください（URLは自動で消えます）。</p>
				<button type="submit" class="snscs-button snscs-button--primary">質問を送る</button>
			</form>
		</details>
		<?php
		return ob_get_clean();
	}

	/**
	 * 質問の一覧。
	 *
	 * @param string $order new / open（回答待ち）/ popular
	 */
	public static function list_questions( $order = 'new', $platform = '', $genre_id = 0, $limit = 30 ) {
		global $wpdb;
		$where = 'status = 1';
		if ( 'open' === $order ) {
			$where .= ' AND answers = 0';
		}
		if ( '' !== $platform ) {
			$where .= $wpdb->prepare( ' AND platform = %s', $platform );
		}
		if ( $genre_id ) {
			$where .= $wpdb->prepare( ' AND genre_id = %d', $genre_id );
		}
		$sort = 'popular' === $order ? '(views + answers * 20) DESC' : 'created_at DESC';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::questions_table() . " WHERE {$where} ORDER BY {$sort} LIMIT %d", $limit ), ARRAY_A );
	}

	private static function question_items( array $rows ) {
		if ( ! $rows ) {
			return '<p class="snscs-results__empty">まだ質問がありません。</p>';
		}
		$html = '<ul class="snscs-qa__list">';
		foreach ( $rows as $q ) {
			$html .= '<li class="snscs-qa__item"><a class="snscs-qa__title" href="' . esc_url( self::url( (int) $q['id'] ) ) . '">' . esc_html( $q['title'] ) . '</a>'
				. '<span class="snscs-qa__meta">' . self::tags( $q ) . ' ' . ( (int) $q['answers'] ? '回答' . (int) $q['answers'] . '件' : '<strong>回答待ち</strong>' ) . '・' . esc_html( mysql2date( 'n月j日', $q['created_at'] ) ) . ( $q['best_answer'] ? '・解決済み' : '' ) . '</span></li>';
		}
		return $html . '</ul>';
	}

	private static function render_index() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$order    = isset( $_GET['qo'] ) && in_array( $_GET['qo'], array( 'open', 'popular' ), true ) ? sanitize_key( $_GET['qo'] ) : 'new';
		$platform = isset( $_GET['qs'] ) && array_key_exists( sanitize_key( $_GET['qs'] ), snscs_platforms() ) ? sanitize_key( $_GET['qs'] ) : '';
		$genre    = isset( $_GET['qg'] ) ? absint( $_GET['qg'] ) : 0;
		// phpcs:enable
		$genres = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => true ) );
		?>
		<p class="snscs-feature__lead">SNS運用の悩みを、実際に発信している人に聞いてみましょう。回答するのは、このサイトに掲載中の発信者です。</p>
		<?php echo self::ask_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>
		<nav class="snscs-member__tabs" aria-label="並べ方">
			<?php foreach ( array( 'new' => '新着', 'open' => '回答待ち', 'popular' => '人気' ) as $k => $label ) : ?>
				<a class="snscs-chip" href="<?php echo esc_url( add_query_arg( 'qo', $k ) ); ?>" <?php echo $k === $order ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<form method="get" class="snscs-collab__filter">
			<input type="hidden" name="qo" value="<?php echo esc_attr( $order ); ?>">
			<select name="qs" aria-label="SNS"><option value="">すべてのSNS</option>
				<?php foreach ( snscs_platforms() as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $platform, $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="qg" aria-label="ジャンル"><option value="0">すべてのジャンル</option>
				<?php foreach ( is_wp_error( $genres ) ? array() : $genres as $g ) : ?>
					<option value="<?php echo (int) $g->term_id; ?>" <?php selected( $genre, $g->term_id ); ?>><?php echo esc_html( $g->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="snscs-button">絞り込む</button>
		</form>
		<?php
		echo self::question_items( self::list_questions( $order, $platform, $genre ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
	}

	private static function render_question( array $c ) {
		global $wpdb;
		$q       = $c['q'];
		$me      = get_current_user_id();
		$creator = $me ? SNSCS_Social::own_creator( $me ) : 0;
		$mine    = $me && (int) $q['user_id'] === $me;
		$voted   = array();
		if ( $me && $c['answers'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$voted = array_flip( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT answer_id FROM ' . self::votes_table() . ' WHERE user_id = %d AND answer_id IN (' . implode( ',', array_map( 'intval', wp_list_pluck( $c['answers'], 'id' ) ) ) . ')', $me ) ) ) );
		}
		wp_enqueue_script( 'snscs-qa', SNSCS_URL . 'assets/js/snscs-qa.js', array(), SNSCS_VERSION, true );
		wp_localize_script( 'snscs-qa', 'snscsQA', array( 'rest' => esc_url_raw( rest_url( 'snscs/v1/qa/helpful' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
		echo SNSCS_Subpages::breadcrumbs_html( $c['crumbs'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		?>
		<article class="snscs-qa__question">
			<p class="snscs-qa__meta"><?php echo self::tags( $q ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $c['asker'] . 'さんの質問・' . mysql2date( 'Y年n月j日', $q['created_at'] ) ); ?></p>
			<div class="snscs-qa__body"><?php echo nl2br( esc_html( $q['body'] ) ); ?></div>
		</article>
		<h2 class="snscs-single__heading"><?php echo $c['answers'] ? esc_html( '発信者の回答（' . count( $c['answers'] ) . '件）' ) : 'まだ回答がありません'; ?></h2>
		<?php foreach ( $c['answers'] as $a ) : ?>
			<?php
			$cid   = (int) $a['creator_id'];
			$stats = self::creator_stats( $cid );
			$best  = (int) $a['id'] === (int) $q['best_answer'];
			?>
			<section class="snscs-qa__answer<?php echo $best ? ' is-best' : ''; ?>" id="answer-<?php echo (int) $a['id']; ?>">
				<?php if ( $best ) : ?>
					<p class="snscs-qa__best">👑 ベストアンサー</p>
				<?php endif; ?>
				<p class="snscs-qa__who">
					<?php if ( get_the_post_thumbnail_url( $cid, 'thumbnail' ) ) : ?>
						<img src="<?php echo esc_url( get_the_post_thumbnail_url( $cid, 'thumbnail' ) ); ?>" alt="" width="36" height="36" loading="lazy">
					<?php endif; ?>
					<a href="<?php echo esc_url( get_permalink( $cid ) ); ?>"><strong><?php echo esc_html( get_the_title( $cid ) ); ?></strong></a>
					<?php if ( '' !== $stats['badge'] ) : ?>
						<span class="snscs-qa__badge"><?php echo esc_html( $stats['badge'] ); ?></span>
					<?php endif; ?>
					<span class="snscs-qa__date"><?php echo esc_html( mysql2date( 'n月j日', $a['created_at'] ) ); ?></span>
				</p>
				<div class="snscs-qa__body"><?php echo nl2br( esc_html( $a['body'] ) ); ?></div>
				<p class="snscs-qa__actions">
					<?php if ( $me && (int) $a['user_id'] !== $me ) : ?>
						<button type="button" class="snscs-button snscs-qa__helpful<?php echo isset( $voted[ (int) $a['id'] ] ) ? ' is-on' : ''; ?>" data-snscs-helpful="<?php echo (int) $a['id']; ?>">参考になった <span><?php echo (int) $a['helpful']; ?></span></button>
					<?php else : ?>
						<span class="snscs-help">参考になった <?php echo (int) $a['helpful']; ?></span>
					<?php endif; ?>
					<?php if ( $mine && ! $best ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline">
							<input type="hidden" name="action" value="snscs_qa_best">
							<input type="hidden" name="answer" value="<?php echo (int) $a['id']; ?>">
							<?php wp_nonce_field( 'snscs_qa_best', 'snscs_nonce' ); ?>
							<button type="submit" class="snscs-button">ベストアンサーに選ぶ</button>
						</form>
					<?php endif; ?>
				</p>
			</section>
		<?php endforeach; ?>
		<?php if ( $creator && 'publish' === get_post_status( $creator ) && ! $mine ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="snscs-qa__answer-form">
				<input type="hidden" name="action" value="snscs_qa_answer">
				<input type="hidden" name="question" value="<?php echo (int) $q['id']; ?>">
				<?php wp_nonce_field( 'snscs_qa_answer', 'snscs_nonce' ); ?>
				<h3>発信者として回答する</h3>
				<textarea class="snscs-input" name="body" rows="6" minlength="20" maxlength="2000" required placeholder="あなたの経験から、具体的に教えてください（20〜2000字）"></textarea>
				<button type="submit" class="snscs-button snscs-button--primary">回答する</button>
				<p class="snscs-help">回答には、あなたの名前と発信者ページへのリンクが表示されます。<?php echo esc_html( self::BADGE_COUNT ); ?>回答えると「回答者」バッジが付きます。</p>
			</form>
		<?php elseif ( ! $me ) : ?>
			<p class="snscs-help">回答できるのは、このサイトに掲載中の発信者です。<a href="<?php echo esc_url( snscs_portal_url() ); ?>">発信者として掲載する（無料）</a></p>
		<?php endif; ?>
		<?php
		$related = array_filter(
			self::list_questions( 'popular', $q['platform'], (int) $q['genre_id'], 6 ),
			function ( $r ) use ( $q ) {
				return (int) $r['id'] !== (int) $q['id'];
			}
		);
		if ( $related ) {
			echo '<h2 class="snscs-single__heading">ほかの質問</h2>' . self::question_items( array_slice( $related, 0, 5 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		}
		echo '<p><a href="' . esc_url( SNSCS_Hub::page_url( 'qa' ) ) . '">質問箱のトップへ</a></p>';
	}

	/**
	 * 発信者ページ: 回答の記録と、参考になった回答。
	 */
	public static function creator_html( $creator_id ) {
		global $wpdb;
		$stats = self::creator_stats( $creator_id );
		if ( ! $stats['answers'] ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT q.* FROM ' . self::answers_table() . ' a INNER JOIN ' . self::questions_table() . ' q ON q.id = a.question_id AND q.status = 1 WHERE a.creator_id = %d AND a.status = 1 ORDER BY a.helpful DESC, a.created_at DESC LIMIT 3', $creator_id ), ARRAY_A );
		return '<section class="snscs-qa-box"><h2 class="snscs-single__heading">質問箱での回答' . ( '' !== $stats['badge'] ? ' <span class="snscs-qa__badge">' . esc_html( $stats['badge'] ) . '</span>' : '' ) . '</h2><p class="snscs-help">' . esc_html( '回答' . $stats['answers'] . '件・ベストアンサー' . $stats['best'] . '件' ) . '</p>' . self::question_items( $rows ) . '</section>';
	}

	/**
	 * マイページ: あなたのジャンルの回答待ちの質問。
	 */
	public static function render_portal( $creator_id ) {
		if ( ! SNSCS_Hub::page_id( 'qa' ) ) {
			return;
		}
		$genres = get_the_terms( $creator_id, SNSCS_Post_Types::TAX_GENRE );
		$open   = array();
		foreach ( ( $genres && ! is_wp_error( $genres ) ) ? $genres : array() as $g ) {
			$open = array_merge( $open, self::list_questions( 'open', '', (int) $g->term_id, 5 ) );
		}
		if ( count( $open ) < 5 ) {
			$open = array_merge( $open, self::list_questions( 'open', '', 0, 5 ) );
		}
		$seen = array();
		$open = array_values(
			array_filter(
				$open,
				function ( $q ) use ( &$seen ) {
					if ( isset( $seen[ $q['id'] ] ) ) {
						return false;
					}
					$seen[ $q['id'] ] = true;
					return true;
				}
			)
		);
		$stats = self::creator_stats( $creator_id );
		?>
		<section class="snscs-panel" id="snscs-qa">
			<h2 class="snscs-panel__title">質問箱</h2>
			<p><?php echo esc_html( 'あなたの回答: ' . $stats['answers'] . '件・ベストアンサー ' . $stats['best'] . '件' . ( '' !== $stats['badge'] ? '（' . $stats['badge'] . 'バッジ）' : '（' . self::BADGE_COUNT . '回答えると「回答者」バッジ）' ) ); ?></p>
			<?php if ( $open ) : ?>
				<p>回答を待っている質問です。答えると、あなたの名前と発信者ページへのリンクが表示され、知ってもらうきっかけになります。</p>
				<?php echo self::question_items( array_slice( $open, 0, 5 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>
			<?php else : ?>
				<p class="snscs-help">いま回答待ちの質問はありません。</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/* --------------------------------------------------------------------
	 * サイトマップ（回答のある質問）
	 * ------------------------------------------------------------------ */

	public static function sitemap_urls( $offset, $limit ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::questions_table() . ' WHERE status = 1 AND answers > 0 ORDER BY id DESC LIMIT %d, %d', $offset, $limit ) );
		return array_map( array( __CLASS__, 'url' ), array_map( 'intval', $ids ) );
	}

	public static function sitemap_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::questions_table() . ' WHERE status = 1 AND answers > 0' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
