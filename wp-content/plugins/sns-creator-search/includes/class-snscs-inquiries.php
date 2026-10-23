<?php
/**
 * 企業からの「お仕事の相談」。
 *
 *   - 発信者はマイページで「相談を受け付ける」と、受けられる仕事の種類を選べる（選ぶだけなので審査なし）
 *   - 受け付けている発信者のページに相談フォームを出す。相談は発信者にメールで届き、返信すると相手に直接届く
 *     （発信者のメールアドレスは相手に見せない）
 *   - 機械的な送信を防ぐため、表示から送信までの時間・見えない入力欄・送信回数の上限・URLの数で判定する
 *   - [sns_business]: 企業向けの案内ページ（探し方と、相談を受け付けている発信者の一覧）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Inquiries {

	const META        = '_snscs_inquiry';
	const MAX_MESSAGE = 2000;
	const ADMIN_PAGE  = 'snscs-inquiries';

	public static function init() {
		add_action( 'admin_post_nopriv_snscs_inquiry', array( __CLASS__, 'handle_send' ) );
		add_action( 'admin_post_snscs_inquiry', array( __CLASS__, 'handle_send' ) );
		add_action( 'admin_post_snscs_inquiry_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_snscs_inquiry_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_creator' ) );
		add_shortcode( 'sns_business', array( __CLASS__, 'shortcode_business' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_inquiries';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  creator_id bigint(20) unsigned NOT NULL,
  company varchar(100) NOT NULL DEFAULT '',
  name varchar(60) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  category varchar(30) NOT NULL DEFAULT '',
  budget varchar(30) NOT NULL DEFAULT '',
  message text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY creator_id (creator_id),
  KEY created_at (created_at)
) {$charset};"
		);
	}

	public static function categories() {
		return apply_filters(
			'snscs_inquiry_categories',
			array(
				'pr'      => 'PR投稿・タイアップ',
				'review'  => '商品・サービスのレビュー',
				'event'   => 'イベント・ライブ配信への出演',
				'writing' => '記事・コラムの執筆',
				'talk'    => '講演・セミナー',
				'consult' => 'SNS運用の相談・講師',
				'other'   => 'その他',
			)
		);
	}

	public static function budgets() {
		return array( '未定・相談したい', '〜3万円', '3万〜10万円', '10万〜30万円', '30万円〜' );
	}

	/**
	 * 発信者の受け付け設定。
	 *
	 * @return array{on:bool,cats:string[]}
	 */
	public static function settings_for( $creator_id ) {
		$saved = get_post_meta( $creator_id, self::META, true );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'on'   => ! empty( $saved['on'] ),
			'cats' => array_values( array_intersect( isset( $saved['cats'] ) ? (array) $saved['cats'] : array(), array_keys( self::categories() ) ) ),
		);
	}

	/* --------------------------------------------------------------------
	 * 発信者ページ: 相談フォーム
	 * ------------------------------------------------------------------ */

	public static function form_html( $creator_id ) {
		$settings = self::settings_for( $creator_id );
		if ( ! $settings['on'] ) {
			return '';
		}
		$cats = $settings['cats'] ? array_intersect_key( self::categories(), array_flip( $settings['cats'] ) ) : self::categories();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sent = isset( $_GET['snscs_inquiry'] ) ? sanitize_key( $_GET['snscs_inquiry'] ) : '';
		$name = get_the_title( $creator_id );
		ob_start();
		?>
		<section class="snscs-inquiry" id="snscs-inquiry">
			<h2 class="snscs-single__heading">お仕事の相談</h2>
			<?php if ( 'sent' === $sent ) : ?>
				<div class="snscs-notice snscs-notice--success" role="status">相談を送りました。<?php echo esc_html( $name ); ?>さんからの返信をお待ちください（確認のメールをお送りしました）。</div>
			<?php elseif ( '' !== $sent ) : ?>
				<div class="snscs-notice snscs-notice--error" role="alert"><?php echo esc_html( 'limit' === $sent ? '短い時間に何度も送信されたため、受け付けを止めています。しばらくしてからお試しください。' : ( 'expired' === $sent ? '送信を確認できませんでした。ページを読み込み直し、入力してから少し時間をおいて送信してください。' : '入力内容を確認して、もう一度お試しください。' ) ); ?></div>
			<?php endif; ?>
			<p><?php echo esc_html( $name ); ?>さんは、次のお仕事の相談を受け付けています: <?php echo esc_html( implode( '・', $cats ) ); ?></p>
			<details class="snscs-inquiry__details"<?php echo ( '' !== $sent && 'sent' !== $sent ) ? ' open' : ''; ?>>
				<summary class="snscs-button snscs-button--primary">相談する</summary>
				<form class="snscs-fields snscs-inquiry__form" method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
					<input type="hidden" name="action" value="snscs_inquiry">
					<input type="hidden" name="creator" value="<?php echo (int) $creator_id; ?>">
					<input type="hidden" name="snscs_form" value="<?php echo esc_attr( snscs_form_token() ); ?>">
					<p class="snscs-hp" aria-hidden="true"><label>ウェブサイト<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
					<label class="snscs-field"><span>会社名・団体名（個人の方は空欄）</span><input type="text" name="company" maxlength="100" autocomplete="organization"></label>
					<label class="snscs-field"><span>お名前（必須）</span><input type="text" name="name" maxlength="60" required autocomplete="name"></label>
					<label class="snscs-field"><span>メールアドレス（必須・返信先）</span><input type="email" name="email" maxlength="190" required autocomplete="email"></label>
					<label class="snscs-field"><span>ご相談の種類</span>
						<select name="category">
							<?php foreach ( $cats as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="snscs-field"><span>ご予算</span>
						<select name="budget">
							<?php foreach ( self::budgets() as $budget ) : ?>
								<option value="<?php echo esc_attr( $budget ); ?>"><?php echo esc_html( $budget ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="snscs-field"><span>ご相談の内容（必須・<?php echo (int) self::MAX_MESSAGE; ?>文字まで）</span><textarea name="message" rows="6" maxlength="<?php echo (int) self::MAX_MESSAGE; ?>" required placeholder="内容・時期・掲載するSNSなどをご記入ください"></textarea></label>
					<label class="snscs-check"><input type="checkbox" name="agree" value="1" required> <span><?php echo wp_kses_post( SNSCS_Legal::link_or_text( 'terms' ) ); ?>と<?php echo wp_kses_post( SNSCS_Legal::link_or_text( 'privacy' ) ); ?>に同意し、入力内容を<?php echo esc_html( $name ); ?>さんに送ることに同意します。</span></label>
					<div><button type="submit" class="snscs-button snscs-button--primary">送信する</button></div>
					<p class="snscs-help">相談は<?php echo esc_html( $name ); ?>さんにメールで届き、返信はあなたのメールアドレスに直接届きます。営業目的の一斉送信や、発信者への迷惑行為は禁止です。</p>
				</form>
			</details>
		</section>
		<?php
		return ob_get_clean();
	}

	public static function handle_send() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 合言葉（snscs_form_token）で確認する。
		$creator_id = isset( $_POST['creator'] ) ? absint( $_POST['creator'] ) : 0;
		if ( ! $creator_id || 'publish' !== get_post_status( $creator_id ) || SNSCS_Post_Types::POST_TYPE !== get_post_type( $creator_id ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$back = function ( $code ) use ( $creator_id ) {
			wp_safe_redirect( add_query_arg( 'snscs_inquiry', $code, get_permalink( $creator_id ) ) . '#snscs-inquiry' );
			exit;
		};
		$settings = self::settings_for( $creator_id );
		if ( ! $settings['on'] ) {
			$back( 'invalid' );
		}
		if ( ! empty( $_POST['website'] ) || ! snscs_check_form_token( isset( $_POST['snscs_form'] ) ? sanitize_text_field( wp_unslash( $_POST['snscs_form'] ) ) : '', 5 ) ) {
			$back( 'expired' );
		}
		$field   = function ( $key, $max ) {
			return isset( $_POST[ $key ] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ), 0, $max ) : '';
		};
		$company  = $field( 'company', 100 );
		$name     = $field( 'name', 60 );
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$category = sanitize_key( $field( 'category', 30 ) );
		$budget   = $field( 'budget', 30 );
		$message  = isset( $_POST['message'] ) ? mb_substr( trim( sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) ), 0, self::MAX_MESSAGE ) : '';
		$agree    = ! empty( $_POST['agree'] );
		// phpcs:enable
		$cats = self::categories();
		if ( '' === $name || ! is_email( $email ) || mb_strlen( $message ) < 10 || ! $agree || ! isset( $cats[ $category ] ) || ! in_array( $budget, self::budgets(), true ) || preg_match_all( '#https?://#i', $message ) > 3 ) {
			$back( 'invalid' );
		}
		if ( snscs_rate_limited( 'inquiry', 3 ) || snscs_rate_limited( 'inquiry-creator-' . $creator_id, 10, DAY_IN_SECONDS ) ) {
			$back( 'limit' );
		}

		$wpdb->insert(
			self::table(),
			array(
				'creator_id' => $creator_id,
				'company'    => $company,
				'name'       => $name,
				'email'      => $email,
				'category'   => $cats[ $category ],
				'budget'     => $budget,
				'message'    => $message,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		self::notify( $creator_id, compact( 'company', 'name', 'email', 'budget', 'message' ) + array( 'category' => $cats[ $category ] ) );
		$back( 'sent' );
	}

	private static function notify( $creator_id, array $row ) {
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$creator = get_the_title( $creator_id );
		$from    = ( '' !== $row['company'] ? $row['company'] . ' ' : '' ) . $row['name'];
		$details = array(
			'差出人: ' . $from,
			'メールアドレス: ' . $row['email'],
			'ご相談の種類: ' . $row['category'],
			'ご予算: ' . $row['budget'],
			'',
			$row['message'],
		);
		$user = get_userdata( (int) get_post_field( 'post_author', $creator_id ) );
		$to   = ( $user && snscs_is_creator_user( $user ) ) ? $user->user_email : get_option( 'admin_email' );
		wp_mail(
			$to,
			'[' . $site . '] お仕事の相談が届きました（' . $from . '様）',
			implode( "\n", array_merge( array( $creator . ' さん', '', $site . ' のあなたのページから、お仕事の相談が届きました。', 'このメールに返信すると、相談した方に直接届きます。', '', '---' ), $details, array( '---', '', '※ 心当たりのない営業や迷惑な内容は、運営までお知らせください。', '相談の受け付けは、マイページでいつでも止められます: ' . snscs_portal_url() ) ) ),
			array( 'Reply-To: ' . $row['email'] )
		);
		wp_mail(
			$row['email'],
			'[' . $site . '] ' . $creator . 'さんへの相談を受け付けました',
			implode( "\n", array_merge( array( $row['name'] . ' 様', '', $creator . 'さんへのご相談を受け付けました。返信は' . $creator . 'さんから直接届きます。', '（お返事をお約束するものではありません）', '', '---' ), $details, array( '---', $site, home_url( '/' ) ) ) )
		);
		if ( SNSCS_Hub::setting( 'inquiry_copy_admin' ) ) {
			wp_mail( get_option( 'admin_email' ), '[' . $site . '] お仕事の相談（' . $creator . ' さんあて）', implode( "\n", $details ) );
		}
	}

	/* --------------------------------------------------------------------
	 * マイページ
	 * ------------------------------------------------------------------ */

	public static function render_portal( $creator_id ) {
		global $wpdb;
		$settings = self::settings_for( $creator_id );
		$table    = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE creator_id = %d ORDER BY id DESC LIMIT 10", $creator_id ), ARRAY_A );
		?>
		<section class="snscs-panel snscs-inquiry-settings" id="snscs-inquiry-settings">
			<h2 class="snscs-panel__title">お仕事の相談</h2>
			<p>受け付けると、あなたのページに企業などからの「お仕事の相談」フォームが付きます。相談はメールで届き、あなたのメールアドレスは相手に見えません。</p>
			<form class="snscs-fields" method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
				<input type="hidden" name="action" value="snscs_inquiry_settings">
				<?php wp_nonce_field( 'snscs_inquiry_settings', 'snscs_nonce' ); ?>
				<label class="snscs-check"><input type="checkbox" name="on" value="1" <?php checked( $settings['on'] ); ?>> <span>お仕事の相談を受け付ける</span></label>
				<fieldset>
					<legend>受けられるお仕事（選ばなければ、すべて）</legend>
					<?php foreach ( self::categories() as $key => $label ) : ?>
						<label class="snscs-check snscs-check--inline"><input type="checkbox" name="cats[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $settings['cats'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<div><button type="submit" class="snscs-button">保存する</button></div>
			</form>
			<?php if ( $rows ) : ?>
				<h3 class="snscs-sharekit__heading">届いた相談（最新10件）</h3>
				<ul class="snscs-inquiry-list">
					<?php foreach ( $rows as $row ) : ?>
						<li>
							<strong><?php echo esc_html( ( '' !== $row['company'] ? $row['company'] . ' ' : '' ) . $row['name'] ); ?></strong>
							<small><?php echo esc_html( get_date_from_gmt( $row['created_at'], 'Y年n月j日' ) . '・' . $row['category'] . '・' . $row['budget'] ); ?></small>
							<p><?php echo esc_html( wp_trim_words( $row['message'], 60, '…' ) ); ?></p>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="snscs-help">返信は、届いたメールから行ってください。</p>
			<?php endif; ?>
		</section>
		<?php
	}

	public static function handle_settings() {
		if ( ! is_user_logged_in() || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_inquiry_settings' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$creator_id = SNSCS_Submissions::creator_id_for_user( get_current_user_id() );
		if ( $creator_id ) {
			$cats = isset( $_POST['cats'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['cats'] ) ), array_keys( self::categories() ) ) ) : array();
			update_post_meta( $creator_id, self::META, array( 'on' => ! empty( $_POST['on'] ), 'cats' => $cats ) );
		}
		wp_safe_redirect( add_query_arg( 'snscs_notice', 'inquiry_saved', snscs_portal_url() ) . '#snscs-inquiry-settings' );
		exit;
	}

	public static function delete_for_creator( $post_id ) {
		global $wpdb;
		if ( SNSCS_Post_Types::POST_TYPE === get_post_type( $post_id ) ) {
			$wpdb->delete( self::table(), array( 'creator_id' => $post_id ), array( '%d' ) );
		}
	}

	/* --------------------------------------------------------------------
	 * 企業向けページ
	 * ------------------------------------------------------------------ */

	/**
	 * 相談を受け付けている発信者（総合点の高い順）。
	 *
	 * @return int[]
	 */
	public static function accepting_ids( $limit = 30 ) {
		global $wpdb;
		$stats = SNSCS_Scores::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value LIKE %s LEFT JOIN {$stats} st ON st.creator_id = p.ID WHERE p.post_type = %s AND p.post_status = 'publish' ORDER BY COALESCE(st.score, 0) DESC LIMIT %d", self::META, '%"on";b:1%', SNSCS_Post_Types::POST_TYPE, $limit ) ) );
	}

	public static function shortcode_business() {
		SNSCS_Hub::remember_page( 'business' );
		wp_enqueue_style( 'snscs' );
		$ids = self::accepting_ids();
		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		?>
		<section class="snscs-business">
			<p class="snscs-feature__lead">PR・タイアップ・イベント出演・執筆など、SNSで発信している人にお仕事を相談できます。掲載料・仲介手数料はかかりません。</p>
			<ol class="snscs-business__steps">
				<li><strong>探す</strong> ジャンル・SNS・地域・フォロワー数から、<a href="<?php echo esc_url( snscs_search_url() ); ?>">発信者を検索</a>するか、<?php echo SNSCS_Hub::page_url( 'ranking' ) ? '<a href="' . esc_url( SNSCS_Hub::page_url( 'ranking' ) ) . '">ランキング</a>や' : ''; ?><?php echo SNSCS_Hub::page_url( 'features' ) ? '<a href="' . esc_url( SNSCS_Hub::page_url( 'features' ) ) . '">特集</a>' : '特集'; ?>から選びます。</li>
				<li><strong>相談する</strong> 発信者のページの「お仕事の相談」から、内容とご予算を送ります（相談を受け付けている発信者のみ）。</li>
				<li><strong>やりとり</strong> 発信者からの返信が、あなたのメールに直接届きます。以降のやりとりと契約は当事者どうしで行ってください。</li>
			</ol>
			<p class="snscs-help">PR投稿をお願いする場合は、広告であることを投稿で明らかにしてもらう必要があります（ステルスマーケティング規制）。</p>
		</section>
		<?php
		if ( $ids ) {
			echo '<h2 class="snscs-feature-links__title">お仕事の相談を受け付けている発信者</h2>';
			SNSCS_Frontend::render_cards( SNSCS_Personalize::items( $ids ) );
		}
		return ob_get_clean();
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, 'お仕事の相談', 'お仕事の相談', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	public static function handle_delete() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_inquiry_delete_' . $id ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::ADMIN_PAGE ) );
		exit;
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A );
		?>
		<div class="wrap">
			<h1>お仕事の相談</h1>
			<p>発信者のページから送られた相談の記録です（最新100件）。迷惑な送信があれば削除してください。相談を受け付けている発信者: <?php echo count( self::accepting_ids( 10000 ) ); ?>人</p>
			<table class="widefat striped">
				<thead><tr><th>日時</th><th>あて先</th><th>差出人</th><th>種類・予算</th><th>内容</th><th></th></tr></thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="6">まだ相談はありません。</td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $row['created_at'], 'Y-m-d H:i' ) ); ?></td>
							<td><?php echo esc_html( get_the_title( (int) $row['creator_id'] ) ); ?></td>
							<td><?php echo esc_html( ( '' !== $row['company'] ? $row['company'] . ' ' : '' ) . $row['name'] ); ?><br><small><?php echo esc_html( $row['email'] ); ?></small></td>
							<td><?php echo esc_html( $row['category'] ); ?><br><small><?php echo esc_html( $row['budget'] ); ?></small></td>
							<td><?php echo nl2br( esc_html( wp_trim_words( $row['message'], 80, '…' ) ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>" onsubmit="return confirm( '削除しますか？' );">
									<input type="hidden" name="action" value="snscs_inquiry_delete">
									<input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
									<?php wp_nonce_field( 'snscs_inquiry_delete_' . $row['id'], 'snscs_nonce' ); ?>
									<button type="submit" class="button-link button-link-delete">削除</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
