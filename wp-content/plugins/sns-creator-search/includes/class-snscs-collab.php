<?php
/**
 * 発信者どうしの「コラボ相手探し」。
 *
 *   募集: 発信者がマイページで「コラボ相手を募集中」にし、やりたいコラボ（相互紹介・ライブ配信・動画の共演・
 *         企画・記事の寄稿）と、ひとこと（200字まで）を書く。
 *   探す: [sns_collab]（機能ページ「コラボ相手探し」）で、募集中の発信者をジャンル・SNS・フォロワー数で探す。
 *         マイページには「あなたに合いそうなコラボ相手」（似ている発信者のうち募集中の人）。
 *   申し込み: 掲載中の発信者だけが、募集中の発信者に申し込める（メッセージ20〜500字。1日5件まで、
 *         同じ相手への返事待ちは1件まで）。相手にお知らせ（サイト内・メール・スマホ通知）。
 *   返事: 承諾すると、お互いのメールアドレスを伝え合う（承諾のときにだけ。お断りは相手に理由を伝えない）。
 *   招待: コラボしたい相手がまだ掲載されていなければ、招待リンクで誘える（SNSCS_Invites）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Collab {

	const META_OPEN  = '_snscs_collab_open';
	const META_NOTE  = '_snscs_collab_note';
	const META_KINDS = '_snscs_collab_kinds';
	const PER_DAY    = 5;
	const PENDING    = 0;
	const ACCEPTED   = 1;
	const DECLINED   = 2;

	public static function kinds() {
		return array(
			'shoutout' => '相互紹介',
			'live'     => 'ライブ配信',
			'video'    => '動画の共演',
			'project'  => '企画・キャンペーン',
			'article'  => '記事の寄稿',
		);
	}

	public static function init() {
		add_shortcode( 'sns_collab', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_snscs_collab_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_snscs_collab_request', array( __CLASS__, 'handle_request' ) );
		add_action( 'admin_post_snscs_collab_respond', array( __CLASS__, 'handle_respond' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_collabs';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  from_creator bigint(20) unsigned NOT NULL,
  to_creator bigint(20) unsigned NOT NULL,
  message text NOT NULL,
  status tinyint(3) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  responded_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY to_status (to_creator,status),
  KEY from_created (from_creator,created_at)
) {$charset};"
		);
	}

	public static function is_open( $creator_id ) {
		return (bool) get_post_meta( $creator_id, self::META_OPEN, true ) && 'publish' === get_post_status( $creator_id );
	}

	private static function user_of( $creator_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'snscs_creator_id' AND meta_value = %d LIMIT 1", $creator_id ) );
	}

	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	/**
	 * お知らせの文（collab: 申し込みが届いた / collab_ok: 承諾された）。
	 */
	public static function describe( $id, $accepted = false ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return null;
		}
		$who = html_entity_decode( get_the_title( $accepted ? (int) $row['to_creator'] : (int) $row['from_creator'] ), ENT_QUOTES, 'UTF-8' );
		return array(
			'text' => $accepted ? $who . 'さんが、コラボの申し込みを承諾しました。連絡先はマイページで確認できます。' : $who . 'さんから、コラボの申し込みが届きました。',
			'url'  => snscs_portal_url() . '#snscs-collab',
		);
	}

	/* --------------------------------------------------------------------
	 * 操作
	 * ------------------------------------------------------------------ */

	private static function back( $notice, $to = '' ) {
		wp_safe_redirect( add_query_arg( 'snscs_collab', $notice, $to ? $to : snscs_portal_url() ) . '#snscs-collab' );
		exit;
	}

	private static function my_creator() {
		$id = is_user_logged_in() ? SNSCS_Social::own_creator( get_current_user_id() ) : 0;
		return ( $id && 'publish' === get_post_status( $id ) ) ? $id : 0;
	}

	public static function handle_settings() {
		check_admin_referer( 'snscs_collab_settings', 'snscs_nonce' );
		$me = is_user_logged_in() ? SNSCS_Social::own_creator( get_current_user_id() ) : 0;
		if ( ! $me ) {
			self::back( 'error' );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() で確認済み。
		update_post_meta( $me, self::META_OPEN, empty( $_POST['open'] ) ? 0 : 1 );
		update_post_meta( $me, self::META_NOTE, mb_substr( sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ), 0, 200 ) );
		update_post_meta( $me, self::META_KINDS, array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['kinds'] ?? array() ) ), array_keys( self::kinds() ) ) ) );
		// phpcs:enable
		self::back( 'saved' );
	}

	/**
	 * @return int|WP_Error 申し込みのID。
	 */
	public static function request( $from, $to, $message ) {
		global $wpdb;
		$message = trim( sanitize_textarea_field( $message ) );
		if ( ! $from || ! self::is_open( $to ) || $from === $to ) {
			return new WP_Error( 'snscs_collab', 'この発信者には申し込めません。' );
		}
		if ( mb_strlen( $message ) < 20 || mb_strlen( $message ) > 500 ) {
			return new WP_Error( 'snscs_collab', 'メッセージは20〜500字で書いてください。' );
		}
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE from_creator = %d AND created_at >= %s", $from, gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - DAY_IN_SECONDS ) ) );
		if ( $today >= self::PER_DAY ) {
			return new WP_Error( 'snscs_collab', '申し込みは1日' . self::PER_DAY . '件までです。' );
		}
		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %d AND ((from_creator = %d AND to_creator = %d) OR (from_creator = %d AND to_creator = %d))", self::PENDING, $from, $to, $to, $from ) );
		// phpcs:enable
		if ( $pending ) {
			return new WP_Error( 'snscs_collab', 'この相手とは、返事待ちの申し込みがあります。' );
		}
		$wpdb->insert( $table, array( 'from_creator' => $from, 'to_creator' => $to, 'message' => $message, 'status' => self::PENDING, 'created_at' => current_time( 'mysql' ) ) );
		$id   = (int) $wpdb->insert_id;
		$user = self::user_of( $to );
		if ( $user ) {
			SNSCS_Inbox::add( $user, 'collab', $id );
		}
		return $id;
	}

	public static function handle_request() {
		check_admin_referer( 'snscs_collab_request', 'snscs_nonce' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() で確認済み。
		$to     = absint( $_POST['to'] ?? 0 );
		$result = self::request( self::my_creator(), $to, (string) wp_unslash( $_POST['message'] ?? '' ) );
		// phpcs:enable
		$page = SNSCS_Hub::page_url( 'collab' );
		self::back( is_wp_error( $result ) ? 'error-' . rawurlencode( $result->get_error_message() ) : 'sent', $page );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function respond( $id, $creator_id, $accept ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row || (int) $row['to_creator'] !== (int) $creator_id || self::PENDING !== (int) $row['status'] ) {
			return new WP_Error( 'snscs_collab', 'この申し込みには返事できません。' );
		}
		$wpdb->update( self::table(), array( 'status' => $accept ? self::ACCEPTED : self::DECLINED, 'responded_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
		if ( $accept ) {
			$user = self::user_of( (int) $row['from_creator'] );
			if ( $user ) {
				SNSCS_Inbox::add( $user, 'collab_ok', $id );
			}
		}
		return true;
	}

	public static function handle_respond() {
		check_admin_referer( 'snscs_collab_respond', 'snscs_nonce' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() で確認済み。
		$result = self::respond( absint( $_POST['id'] ?? 0 ), self::my_creator(), 'accept' === ( $_POST['answer'] ?? '' ) );
		// phpcs:enable
		self::back( is_wp_error( $result ) ? 'error' : 'answered' );
	}

	/* --------------------------------------------------------------------
	 * 表示
	 * ------------------------------------------------------------------ */

	/**
	 * 募集中の発信者（総合点の高い順）。
	 *
	 * @return int[]
	 */
	public static function open_ids( array $args = array() ) {
		$args  = wp_parse_args( $args, array( 'genre' => '', 'sns' => '', 'min' => 0, 'max' => 0, 'limit' => 30 ) );
		$query = array(
			'post_type'      => SNSCS_Post_Types::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 300,
			'fields'         => 'ids',
			'meta_key'       => self::META_OPEN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'no_found_rows'  => true,
		);
		if ( '' !== $args['genre'] ) {
			$query['tax_query'] = array( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'field' => 'slug', 'terms' => $args['genre'] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		$ids = array_map( 'intval', get_posts( $query ) );
		if ( ! $ids ) {
			return array();
		}
		$accounts = SNSCS_Accounts::get_for_creators( $ids );
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$scores = wp_list_pluck( $wpdb->get_results( 'SELECT creator_id, score FROM ' . SNSCS_Scores::table() . ' WHERE creator_id IN (' . implode( ',', $ids ) . ')', ARRAY_A ), 'score', 'creator_id' );
		$ids    = array_values(
			array_filter(
				$ids,
				function ( $id ) use ( $accounts, $args ) {
					$list = $accounts[ $id ] ?? array();
					if ( '' !== $args['sns'] ) {
						$list = wp_list_pluck( wp_list_filter( $list, array( 'platform' => $args['sns'] ) ), 'followers' );
					} else {
						$list = wp_list_pluck( $list, 'followers' );
					}
					if ( '' !== $args['sns'] && ! $list ) {
						return false;
					}
					$f = $list ? max( array_map( 'intval', $list ) ) : 0;
					return ( ! $args['min'] || $f >= $args['min'] ) && ( ! $args['max'] || $f <= $args['max'] );
				}
			)
		);
		usort(
			$ids,
			function ( $a, $b ) use ( $scores ) {
				return ( (float) ( $scores[ $b ] ?? 0 ) ) <=> ( (float) ( $scores[ $a ] ?? 0 ) );
			}
		);
		return array_slice( $ids, 0, (int) $args['limit'] );
	}

	private static function request_form( $to ) {
		$me = self::my_creator();
		if ( ! $me ) {
			return '<p class="snscs-help"><a href="' . esc_url( snscs_portal_url() ) . '">発信者として掲載されると</a>、申し込めます。</p>';
		}
		if ( $me === $to ) {
			return '<p class="snscs-help">あなたの募集です。</p>';
		}
		ob_start();
		?>
		<details class="snscs-collab__apply">
			<summary class="snscs-button snscs-button--primary">コラボを申し込む</summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="snscs_collab_request">
				<input type="hidden" name="to" value="<?php echo (int) $to; ?>">
				<?php wp_nonce_field( 'snscs_collab_request', 'snscs_nonce' ); ?>
				<label for="snscs-collab-msg-<?php echo (int) $to; ?>">メッセージ（20〜500字。やりたいことと、あなたの発信の紹介を）</label>
				<textarea id="snscs-collab-msg-<?php echo (int) $to; ?>" name="message" rows="5" minlength="20" maxlength="500" required class="snscs-input"></textarea>
				<p class="snscs-help">相手が承諾すると、お互いのメールアドレスが伝わります。</p>
				<button type="submit" class="snscs-button snscs-button--primary">送る</button>
			</form>
		</details>
		<?php
		return ob_get_clean();
	}

	private static function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['snscs_collab'] ) ? sanitize_text_field( wp_unslash( $_GET['snscs_collab'] ) ) : '';
		if ( '' === $code ) {
			return '';
		}
		$map = array( 'saved' => '募集の設定を保存しました。', 'sent' => 'コラボを申し込みました。返事はお知らせで届きます。', 'answered' => '返事を送りました。', 'error' => 'うまくいきませんでした。' );
		$msg = 0 === strpos( $code, 'error-' ) ? substr( $code, 6 ) : ( $map[ $code ] ?? '' );
		return '' !== $msg ? '<p class="snscs-notice' . ( 0 === strpos( $code, 'error' ) ? ' snscs-notice--error' : '' ) . '" role="status">' . esc_html( $msg ) . '</p>' : '';
	}

	private static function card_extra( $id ) {
		$kinds = array_intersect_key( self::kinds(), array_flip( (array) get_post_meta( $id, self::META_KINDS, true ) ) );
		$note  = (string) get_post_meta( $id, self::META_NOTE, true );
		$html  = '<div class="snscs-collab__extra">';
		if ( $kinds ) {
			$html .= '<ul class="snscs-chips">';
			foreach ( $kinds as $label ) {
				$html .= '<li><span class="snscs-chip">' . esc_html( $label ) . '</span></li>';
			}
			$html .= '</ul>';
		}
		if ( '' !== $note ) {
			$html .= '<p class="snscs-collab__note">' . esc_html( $note ) . '</p>';
		}
		return $html . self::request_form( $id ) . '</div>';
	}

	public static function shortcode() {
		SNSCS_Hub::remember_page( 'collab' );
		wp_enqueue_style( 'snscs' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$args = array(
			'genre' => isset( $_GET['cg'] ) ? sanitize_title( wp_unslash( $_GET['cg'] ) ) : '',
			'sns'   => isset( $_GET['cs'] ) && array_key_exists( sanitize_key( $_GET['cs'] ), snscs_platforms() ) ? sanitize_key( $_GET['cs'] ) : '',
			'min'   => isset( $_GET['cmin'] ) ? absint( $_GET['cmin'] ) : 0,
			'max'   => isset( $_GET['cmax'] ) ? absint( $_GET['cmax'] ) : 0,
		);
		// phpcs:enable
		$genres = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => true ) );
		$ids    = self::open_ids( $args );
		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo self::notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		?>
		<div class="snscs-collab" id="snscs-collab">
			<p class="snscs-feature__lead">コラボ相手を募集している発信者です。相互紹介・ライブ配信・動画の共演など、一緒に発信すると、お互いのフォロワーに知ってもらえます。</p>
			<form class="snscs-collab__filter" method="get">
				<select name="cg" aria-label="ジャンル"><option value="">すべてのジャンル</option>
					<?php foreach ( is_wp_error( $genres ) ? array() : $genres as $g ) : ?>
						<option value="<?php echo esc_attr( urldecode( $g->slug ) ); ?>" <?php selected( $args['genre'], $g->slug ); ?>><?php echo esc_html( $g->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="cs" aria-label="SNS"><option value="">すべてのSNS</option>
					<?php foreach ( snscs_platforms() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $args['sns'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="cmin" aria-label="フォロワー数"><option value="0">フォロワー数を問わない</option>
					<?php foreach ( array( 1000 => '1,000人以上', 10000 => '1万人以上', 100000 => '10万人以上' ) as $v => $label ) : ?>
						<option value="<?php echo (int) $v; ?>" <?php selected( $args['min'], $v ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="snscs-button">絞り込む</button>
			</form>
			<?php if ( $ids ) : ?>
				<ul class="snscs-collab__list">
					<?php foreach ( SNSCS_Personalize::items( $ids ) as $item ) : ?>
						<li class="snscs-collab__item">
							<?php SNSCS_Frontend::render_cards( array( $item ) ); ?>
							<?php echo self::card_extra( $item['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="snscs-results__empty">この条件で募集中の発信者は、まだいません。</p>
			<?php endif; ?>
			<p class="snscs-help">募集するには、<a href="<?php echo esc_url( snscs_portal_url() . '#snscs-collab' ); ?>">発信者マイページ</a>の「コラボ相手探し」で「募集中」にしてください。</p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * マイページ: 募集の設定・合いそうな相手・届いた申し込み・送った申し込み。
	 */
	public static function render_portal( $creator_id ) {
		global $wpdb;
		$open  = (bool) get_post_meta( $creator_id, self::META_OPEN, true );
		$kinds = (array) get_post_meta( $creator_id, self::META_KINDS, true );
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$received = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE to_creator = %d ORDER BY created_at DESC LIMIT 20", $creator_id ), ARRAY_A );
		$sent     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE from_creator = %d ORDER BY created_at DESC LIMIT 20", $creator_id ), ARRAY_A );
		// phpcs:enable
		$suggest = array_slice(
			array_values(
				array_filter(
					array_keys( SNSCS_Similar::find( $creator_id, 20 ) ),
					array( __CLASS__, 'is_open' )
				)
			),
			0,
			4
		);
		$status  = array( self::PENDING => '返事待ち', self::ACCEPTED => '承諾', self::DECLINED => 'お断り' );
		$contact = function ( $other ) {
			$user = get_userdata( self::user_of( $other ) );
			return $user ? $user->user_email : '';
		};
		echo self::notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		?>
		<section class="snscs-panel snscs-collab-portal" id="snscs-collab">
			<h2 class="snscs-panel__title">コラボ相手探し</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="snscs_collab_settings">
				<?php wp_nonce_field( 'snscs_collab_settings', 'snscs_nonce' ); ?>
				<p><label><input type="checkbox" name="open" value="1" <?php checked( $open ); ?>> コラボ相手を募集中にする（<a href="<?php echo esc_url( SNSCS_Hub::page_url( 'collab' ) ); ?>">コラボ相手探し</a>に載ります）</label></p>
				<p>やりたいコラボ:
					<?php foreach ( self::kinds() as $key => $label ) : ?>
						<label class="snscs-collab__kind"><input type="checkbox" name="kinds[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $kinds, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</p>
				<p><label for="snscs-collab-note">ひとこと（200字まで）</label><textarea id="snscs-collab-note" name="note" rows="3" maxlength="200" class="snscs-input"><?php echo esc_textarea( (string) get_post_meta( $creator_id, self::META_NOTE, true ) ); ?></textarea></p>
				<button type="submit" class="snscs-button">保存</button>
			</form>

			<?php if ( $suggest ) : ?>
				<h3 class="snscs-sharekit__heading">あなたに合いそうなコラボ相手（募集中）</h3>
				<?php SNSCS_Frontend::render_cards( SNSCS_Personalize::items( $suggest ) ); ?>
				<p><a href="<?php echo esc_url( SNSCS_Hub::page_url( 'collab' ) ); ?>">募集中の発信者をもっと見る</a></p>
			<?php endif; ?>

			<h3 class="snscs-sharekit__heading">届いた申し込み</h3>
			<?php if ( ! $received ) : ?>
				<p class="snscs-help">まだありません。</p>
			<?php endif; ?>
			<?php foreach ( $received as $row ) : ?>
				<div class="snscs-collab__req">
					<p><strong><a href="<?php echo esc_url( get_permalink( (int) $row['from_creator'] ) ); ?>" target="_blank"><?php echo esc_html( get_the_title( (int) $row['from_creator'] ) ); ?></a></strong>（<?php echo esc_html( $status[ (int) $row['status'] ] ); ?>・<?php echo esc_html( mysql2date( 'n月j日', $row['created_at'] ) ); ?>）</p>
					<p class="snscs-collab__message"><?php echo nl2br( esc_html( $row['message'] ) ); ?></p>
					<?php if ( self::PENDING === (int) $row['status'] ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="snscs-collab__answer">
							<input type="hidden" name="action" value="snscs_collab_respond">
							<input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
							<?php wp_nonce_field( 'snscs_collab_respond', 'snscs_nonce' ); ?>
							<button type="submit" name="answer" value="accept" class="snscs-button snscs-button--primary">承諾する（メールアドレスを伝え合う）</button>
							<button type="submit" name="answer" value="decline" class="snscs-button">お断りする</button>
						</form>
					<?php elseif ( self::ACCEPTED === (int) $row['status'] ) : ?>
						<p class="snscs-collab__contact">連絡先: <?php echo esc_html( $contact( (int) $row['from_creator'] ) ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<?php if ( $sent ) : ?>
				<h3 class="snscs-sharekit__heading">送った申し込み</h3>
				<ul>
					<?php foreach ( $sent as $row ) : ?>
						<li><?php echo esc_html( get_the_title( (int) $row['to_creator'] ) . '（' . $status[ (int) $row['status'] ] . '）' ); ?>
							<?php if ( self::ACCEPTED === (int) $row['status'] ) : ?>
								— 連絡先: <?php echo esc_html( $contact( (int) $row['to_creator'] ) ); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="snscs-help">コラボしたい相手が、まだ掲載されていなければ、<a href="<?php echo esc_url( snscs_portal_url() . '#snscs-invite' ); ?>">招待リンク</a>で誘いましょう。</p>
		</section>
		<?php
	}
}
