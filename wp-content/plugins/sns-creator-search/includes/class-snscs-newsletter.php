<?php
/**
 * メールマガジン（週1回「今週の注目発信者」を届け、サイトに戻ってきてもらう）。
 *
 *   登録     : メールアドレスを入れる → 確認メールのリンクを押して登録完了（ダブルオプトイン）
 *   中身     : 今週伸びた発信者・新しく掲載された発信者・好きなジャンルの注目・新しいコラムと記事・人気のキーワード（自動で作る）
 *   配信     : 設定した曜日・時刻に、50通ずつ順番に送る（サーバーに負担をかけないため）
 *   配信停止 : どのメールにも配信停止のリンクを入れる（メールソフトの「登録解除」ボタンにも対応）
 *
 * 特定電子メール法に合わせ、同意の記録（確認した日時）を残し、送り主と配信停止の方法をメールに書く。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Newsletter {

	const CRON_HOOK  = 'snscs_newsletter_tick';
	const BATCH      = 50;
	const ADMIN_PAGE = 'snscs-newsletter';

	public static function init() {
		add_shortcode( 'sns_newsletter', array( __CLASS__, 'shortcode_page' ) );
		add_shortcode( 'sns_newsletter_form', array( __CLASS__, 'mini_form' ) );
		add_action( 'admin_post_nopriv_snscs_newsletter_subscribe', array( __CLASS__, 'handle_subscribe' ) );
		add_action( 'admin_post_snscs_newsletter_subscribe', array( __CLASS__, 'handle_subscribe' ) );
		add_action( 'admin_post_nopriv_snscs_newsletter_unsubscribe', array( __CLASS__, 'handle_unsubscribe' ) );
		add_action( 'admin_post_snscs_newsletter_unsubscribe', array( __CLASS__, 'handle_unsubscribe' ) );
		add_action( 'admin_post_snscs_newsletter_admin', array( __CLASS__, 'handle_admin' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_links' ), 1 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );
		add_action( self::CRON_HOOK . '_more', array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_subscribers';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(190) NOT NULL,
  token char(32) NOT NULL,
  status varchar(12) NOT NULL DEFAULT 'pending',
  genres varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  confirmed_at datetime DEFAULT NULL,
  last_issue varchar(32) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY email (email),
  UNIQUE KEY token (token),
  KEY status (status)
) {$charset};"
		);
	}

	public static function schedule( $reset = false ) {
		if ( $reset ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	private static function page_url( $args = array() ) {
		$url = SNSCS_Hub::page_url( 'newsletter' );
		return add_query_arg( $args, $url ? $url : home_url( '/' ) );
	}

	/* --------------------------------------------------------------------
	 * 登録フォーム
	 * ------------------------------------------------------------------ */

	private static function form( $full ) {
		wp_enqueue_style( 'snscs' );
		$genres = $full ? get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => false, 'parent' => 0, 'number' => 40 ) ) : array();
		ob_start();
		?>
		<form class="snscs-newsletter__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="snscs_newsletter_subscribe">
			<input type="hidden" name="snscs_form" value="<?php echo esc_attr( snscs_form_token() ); ?>">
			<input type="hidden" name="back" value="<?php echo esc_attr( $full ? '' : get_permalink() ); ?>">
			<p class="snscs-hp" aria-hidden="true"><label>ウェブサイト<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
			<div class="snscs-newsletter__row">
				<label class="screen-reader-text" for="snscs-nl-email-<?php echo $full ? 'full' : 'mini'; ?>">メールアドレス</label>
				<input id="snscs-nl-email-<?php echo $full ? 'full' : 'mini'; ?>" type="email" name="email" required maxlength="190" placeholder="メールアドレス" autocomplete="email">
				<button type="submit" class="snscs-button snscs-button--primary">無料で登録</button>
			</div>
			<?php if ( $genres && ! is_wp_error( $genres ) ) : ?>
				<fieldset class="snscs-newsletter__genres">
					<legend>好きなジャンル（選ぶと、そのジャンルの注目発信者も届きます）</legend>
					<?php foreach ( $genres as $genre ) : ?>
						<label class="snscs-check snscs-check--inline"><input type="checkbox" name="genres[]" value="<?php echo (int) $genre->term_id; ?>"> <?php echo esc_html( $genre->name ); ?></label>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>
			<p class="snscs-help">登録すると確認メールが届きます。メール内のリンクを押すと登録が完了します。配信はいつでも停止できます（<?php echo wp_kses_post( SNSCS_Legal::link_or_text( 'privacy' ) ); ?>）。</p>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * 検索結果やランキングの下に出す、小さな登録欄。
	 */
	public static function mini_form() {
		ob_start();
		?>
		<aside class="snscs-newsletter snscs-newsletter--mini">
			<p class="snscs-newsletter__title">週1回、注目の発信者をメールでお届けします</p>
			<?php echo self::form( false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>
		</aside>
		<?php
		return ob_get_clean();
	}

	private static function message() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code     = isset( $_GET['snscs_nl_msg'] ) ? sanitize_key( $_GET['snscs_nl_msg'] ) : '';
		$messages = array(
			'sent'      => array( 'success', '確認メールを送りました。メール内のリンクを押すと登録が完了します（届かないときは迷惑メールのフォルダもご確認ください）。' ),
			'confirmed' => array( 'success', '登録が完了しました。次の配信からお届けします。' ),
			'already'   => array( 'success', 'このメールアドレスはすでに登録されています。' ),
			'stopped'   => array( 'success', '配信を停止しました。これまでご購読ありがとうございました。' ),
			'invalid'   => array( 'error', 'メールアドレスを確認して、もう一度お試しください。' ),
			'expired'   => array( 'error', '送信を確認できませんでした。ページを読み込み直し、少し時間をおいてから、もう一度お試しください。' ),
			'limit'     => array( 'error', '短い時間に何度も送信されたため、受け付けを止めています。しばらくしてからお試しください。' ),
			'notfound'  => array( 'error', 'リンクが正しくないか、すでに使われています。' ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return '';
		}
		return sprintf( '<div class="snscs-notice snscs-notice--%s" role="status">%s</div>', esc_attr( $messages[ $code ][0] ), esc_html( $messages[ $code ][1] ) );
	}

	/**
	 * [sns_newsletter]: メールマガジンのページ。
	 */
	public static function shortcode_page() {
		SNSCS_Hub::remember_page( 'newsletter' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$unsub = isset( $_GET['snscs_nl_unsub'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_GET['snscs_nl_unsub'] ) ) : '';
		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		echo self::message(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		if ( 32 === strlen( $unsub ) ) {
			?>
			<section class="snscs-panel">
				<h2 class="snscs-panel__title">配信の停止</h2>
				<p>下のボタンを押すと、メールマガジンの配信を停止します。</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="snscs_newsletter_unsubscribe">
					<input type="hidden" name="t" value="<?php echo esc_attr( $unsub ); ?>">
					<button type="submit" class="snscs-button snscs-button--danger">配信を停止する</button>
				</form>
			</section>
			<?php
			return ob_get_clean();
		}
		?>
		<section class="snscs-newsletter">
			<p class="snscs-newsletter__lead">毎週1回、こんな内容をお届けします（無料）。</p>
			<ul class="snscs-newsletter__list">
				<li>今週、注目が集まった発信者（急上昇）</li>
				<li>新しく掲載された発信者</li>
				<li>あなたの好きなジャンルの人気発信者</li>
				<li>SNS運用に役立つ新しいコラムと、発信者の記事</li>
				<li>いま人気の検索キーワード</li>
			</ul>
			<?php echo self::form( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/* --------------------------------------------------------------------
	 * 登録・確認・停止
	 * ------------------------------------------------------------------ */

	/**
	 * 結果を知らせるページへ戻る（メールマガジンのページがあればそこ、無ければ元のページ）。
	 */
	private static function back( $code, $back = '' ) {
		$back = '' !== $back ? wp_validate_redirect( $back, '' ) : '';
		$url  = ( SNSCS_Hub::page_url( 'newsletter' ) || '' === $back ) ? self::page_url() : $back;
		wp_safe_redirect( add_query_arg( 'snscs_nl_msg', $code, $url ) );
		exit;
	}

	public static function handle_subscribe() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 合言葉（snscs_form_token）で確認する。
		$back  = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! empty( $_POST['website'] ) || ! snscs_check_form_token( isset( $_POST['snscs_form'] ) ? sanitize_text_field( wp_unslash( $_POST['snscs_form'] ) ) : '' ) ) {
			self::back( 'expired', $back );
		}
		if ( ! is_email( $email ) ) {
			self::back( 'invalid', $back );
		}
		if ( snscs_rate_limited( 'newsletter', 5 ) ) {
			self::back( 'limit', $back );
		}
		$genres = isset( $_POST['genres'] ) ? array_slice( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['genres'] ) ) ), 0, 10 ) : array();
		// phpcs:enable

		$table = self::table();
		$email = strtolower( $email );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ), ARRAY_A );
		if ( $row && 'active' === $row['status'] ) {
			self::back( 'already', $back );
		}
		$token = $row ? $row['token'] : bin2hex( random_bytes( 16 ) );
		if ( $row ) {
			$wpdb->update( $table, array( 'status' => 'pending', 'genres' => implode( ',', $genres ) ), array( 'id' => $row['id'] ) );
		} else {
			$wpdb->insert(
				$table,
				array(
					'email'      => $email,
					'token'      => $token,
					'status'     => 'pending',
					'genres'     => implode( ',', $genres ),
					'created_at' => current_time( 'mysql', true ),
				)
			);
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$body = implode(
			"\n",
			array(
				$site . ' のメールマガジンへの登録ありがとうございます。',
				'',
				'下のリンクを押すと登録が完了します（このメールに心当たりがない場合は、何もせずに削除してください）。',
				add_query_arg( array( 'snscs_nl' => 'confirm', 't' => $token ), home_url( '/' ) ),
				'',
				'---',
				snscs_operator_info()['operator'],
				home_url( '/' ),
			)
		);
		wp_mail( $email, '[' . $site . '] メールマガジン登録の確認', $body );
		self::back( 'sent', $back );
	}

	/**
	 * 確認メールのリンクと、配信停止のリンク。
	 */
	public static function handle_links() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 本人だけが知るトークンで確認する。
		$action = isset( $_GET['snscs_nl'] ) ? sanitize_key( $_GET['snscs_nl'] ) : '';
		if ( '' === $action ) {
			return;
		}
		$token = isset( $_GET['t'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_GET['t'] ) ) : '';
		// phpcs:enable
		nocache_headers();
		if ( 'unsub' === $action ) {
			// メールソフトの「登録解除」ボタン（ワンクリック）は POST で届く。
			if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				self::unsubscribe( $token );
				status_header( 200 );
				exit;
			}
			// リンクを開いただけでは止めない（メールの安全チェックが自動で開くことがあるため）。確認のボタンを出す。
			wp_safe_redirect( self::page_url( array( 'snscs_nl_unsub' => $token ) ) );
			exit;
		}
		if ( 'confirm' === $action ) {
			global $wpdb;
			$table = self::table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = 32 === strlen( $token ) ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", $token ), ARRAY_A ) : null;
			if ( ! $row ) {
				wp_safe_redirect( self::page_url( array( 'snscs_nl_msg' => 'notfound' ) ) );
				exit;
			}
			if ( 'active' !== $row['status'] ) {
				$wpdb->update( $table, array( 'status' => 'active', 'confirmed_at' => current_time( 'mysql', true ) ), array( 'id' => $row['id'] ) );
			}
			wp_safe_redirect( self::page_url( array( 'snscs_nl_msg' => 'confirmed' ) ) );
			exit;
		}
	}

	public static function handle_unsubscribe() {
		$token = isset( $_POST['t'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_POST['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 本人だけが知るトークンで確認する。
		$done  = self::unsubscribe( $token );
		wp_safe_redirect( self::page_url( array( 'snscs_nl_msg' => $done ? 'stopped' : 'notfound' ) ) );
		exit;
	}

	public static function unsubscribe( $token ) {
		global $wpdb;
		if ( 32 !== strlen( (string) $token ) ) {
			return false;
		}
		return (bool) $wpdb->update( self::table(), array( 'status' => 'unsubscribed' ), array( 'token' => $token ) ) || (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE token = %s', $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function unsubscribe_url( $token ) {
		return add_query_arg( array( 'snscs_nl' => 'unsub', 't' => $token ), home_url( '/' ) );
	}

	/* --------------------------------------------------------------------
	 * 号の中身
	 * ------------------------------------------------------------------ */

	/**
	 * 1号分の中身（好きなジャンルごとに少し変わる）。
	 *
	 * @param int[] $genres 購読者の好きなジャンル。
	 * @return array{subject:string,sections:array}
	 */
	public static function build_issue( array $genres = array() ) {
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$sections = array();
		$used     = array();

		$card = function ( $item, $note = '' ) {
			return array(
				'title' => $item['name'],
				'url'   => $item['url'],
				'note'  => '' !== $note ? $note : implode( '・', $item['genres'] ),
				'image' => $item['thumbnail'],
			);
		};

		$rising = SNSCS_Rankings::rising( 5 );
		if ( $rising ) {
			$rows = array();
			foreach ( SNSCS_Personalize::items( array_keys( $rising ) ) as $item ) {
				$used[] = $item['id'];
				$rows[] = $card( $item, sprintf( 'ページを見た人 +%s人', number_format_i18n( $rising[ $item['id'] ]['cur'] - $rising[ $item['id'] ]['prev'] ) ) );
			}
			$sections[] = array( 'heading' => '今週の注目（急上昇）', 'rows' => $rows );
		}

		if ( $genres ) {
			$names = array();
			foreach ( $genres as $genre_id ) {
				$term = get_term( (int) $genre_id, SNSCS_Post_Types::TAX_GENRE );
				if ( $term && ! is_wp_error( $term ) ) {
					$names[] = $term->slug;
				}
			}
			$rows = array();
			foreach ( array_slice( $names, 0, 3 ) as $slug ) {
				$result = SNSCS_Search::run( array( 'genre' => $slug, 'sort' => 'recommended', 'per_page' => 2, 'exclude' => $used ) );
				foreach ( $result['items'] as $item ) {
					$used[] = $item['id'];
					$rows[] = $card( $item );
				}
			}
			if ( $rows ) {
				$sections[] = array( 'heading' => 'あなたの好きなジャンルの人気発信者', 'rows' => $rows );
			}
		}

		$new = get_posts(
			array(
				'post_type'      => SNSCS_Post_Types::POST_TYPE,
				'posts_per_page' => 5,
				'date_query'     => array( array( 'after' => '8 days ago' ) ),
				'post__not_in'   => $used,
				'fields'         => 'ids',
			)
		);
		if ( $new ) {
			$sections[] = array(
				'heading' => '新しく掲載された発信者',
				'rows'    => array_map( $card, SNSCS_Personalize::items( $new ) ),
			);
		}

		$posts = get_posts(
			array(
				'post_type'      => array( 'post', SNSCS_Articles::POST_TYPE ),
				'posts_per_page' => 6,
				'date_query'     => array( array( 'after' => '8 days ago' ) ),
			)
		);
		$rows = array();
		foreach ( $posts as $post ) {
			if ( 'post' === $post->post_type && ! SNSCS_Columns::is_column( $post ) ) {
				continue;
			}
			$creator = SNSCS_Articles::POST_TYPE === $post->post_type ? SNSCS_Articles::creator_of( $post->ID ) : 0;
			$rows[]  = array(
				'title' => get_the_title( $post ),
				'url'   => get_permalink( $post ),
				'note'  => $creator ? get_the_title( $creator ) . 'の記事' : 'SNS運用コラム',
				'image' => '',
			);
		}
		if ( $rows ) {
			$sections[] = array( 'heading' => '新しいコラム・記事', 'rows' => array_slice( $rows, 0, 5 ) );
		}

		$trends = SNSCS_Trends::lists( 6 );
		if ( $trends['popular'] ) {
			$rows = array();
			foreach ( $trends['popular'] as $term ) {
				$rows[] = array( 'title' => $term, 'url' => add_query_arg( 'snscs_q', rawurlencode( $term ), snscs_search_url() ), 'note' => '', 'image' => '' );
			}
			$sections[] = array( 'heading' => 'いま人気の検索キーワード', 'rows' => $rows, 'inline' => true );
		}

		$lead = $rising ? SNSCS_Personalize::items( array( key( $rising ) ) ) : array();
		return array(
			'subject'  => '[' . $site . '] 今週の注目発信者' . ( $lead ? '：' . $lead[0]['name'] . 'さん ほか' : '' ),
			'sections' => $sections,
		);
	}

	/**
	 * メール本文（HTML とテキスト）。
	 *
	 * @return array{html:string,text:string}
	 */
	public static function render_issue( array $issue, $token ) {
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$operator = snscs_operator_info();
		$unsub    = self::unsubscribe_url( $token );
		$track    = function ( $url ) {
			return add_query_arg( array( 'utm_source' => 'newsletter', 'utm_medium' => 'email' ), $url );
		};

		$html = '<div style="max-width:600px;margin:0 auto;font-family:sans-serif;color:#111827;line-height:1.7">';
		$html .= '<h1 style="font-size:20px;color:#1e3a8a">' . esc_html( $site ) . ' 週刊ニュース</h1>';
		$text  = $site . " 週刊ニュース\n\n";
		foreach ( $issue['sections'] as $section ) {
			$html .= '<h2 style="font-size:17px;border-bottom:2px solid #e0e7ff;padding-bottom:4px;margin-top:28px">' . esc_html( $section['heading'] ) . '</h2>';
			$text .= '■ ' . $section['heading'] . "\n";
			if ( ! empty( $section['inline'] ) ) {
				$links = array();
				foreach ( $section['rows'] as $row ) {
					$links[] = '<a href="' . esc_url( $track( $row['url'] ) ) . '" style="display:inline-block;margin:0 6px 6px 0;padding:4px 12px;border-radius:999px;background:#eef2ff;color:#1e3a8a;text-decoration:none">' . esc_html( $row['title'] ) . '</a>';
					$text   .= '・' . $row['title'] . "\n";
				}
				$html .= '<p>' . implode( '', $links ) . '</p>';
			} else {
				foreach ( $section['rows'] as $row ) {
					$html .= '<p style="margin:12px 0">';
					if ( $row['image'] ) {
						$html .= '<img src="' . esc_url( $row['image'] ) . '" alt="" width="48" height="48" style="float:left;margin-right:12px;border-radius:50%">';
					}
					$html .= '<a href="' . esc_url( $track( $row['url'] ) ) . '" style="font-weight:bold;color:#1e3a8a">' . esc_html( $row['title'] ) . '</a>';
					if ( '' !== $row['note'] ) {
						$html .= '<br><span style="color:#4b5563;font-size:13px">' . esc_html( $row['note'] ) . '</span>';
					}
					$html .= '</p><div style="clear:both"></div>';
					$text .= '・' . $row['title'] . ( '' !== $row['note'] ? '（' . $row['note'] . '）' : '' ) . "\n  " . $track( $row['url'] ) . "\n";
				}
			}
			$text .= "\n";
		}
		$html .= '<p style="margin-top:28px"><a href="' . esc_url( $track( snscs_search_url() ) ) . '" style="display:inline-block;padding:10px 20px;background:#1e3a8a;color:#fff;border-radius:6px;text-decoration:none">発信者を探す</a></p>';
		$footer = array(
			'このメールは、' . $site . ' のメールマガジンに登録された方にお送りしています。',
			'配信の停止: ' . $unsub,
			'送信者: ' . $operator['operator'] . ( '' !== $operator['address'] ? '（' . $operator['address'] . '）' : '' ),
			'お問い合わせ: ' . $operator['email'],
		);
		$html .= '<hr style="border:0;border-top:1px solid #e5e7eb;margin-top:32px"><p style="font-size:12px;color:#6b7280">' . implode( '<br>', array_map( 'esc_html', $footer ) ) . '</p></div>';
		$html  = str_replace( esc_html( $unsub ), '<a href="' . esc_url( $unsub ) . '">' . esc_html( $unsub ) . '</a>', $html );
		$text .= "---\n" . implode( "\n", $footer ) . "\n";
		return array( 'html' => $html, 'text' => $text );
	}

	/**
	 * 1通送る。
	 */
	public static function send( $email, $token, array $issue ) {
		$body    = self::render_issue( $issue, $token );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'List-Unsubscribe: <' . self::unsubscribe_url( $token ) . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		);
		return wp_mail( $email, $issue['subject'], $body['html'], $headers );
	}

	/* --------------------------------------------------------------------
	 * 配信
	 * ------------------------------------------------------------------ */

	/**
	 * 1時間ごと（と、配信中は数分ごと）に呼ばれる。配信日時になったら号を始め、50通ずつ送る。
	 */
	public static function tick() {
		$issue = (string) get_option( 'snscs_newsletter_issue', '' );
		if ( SNSCS_Hub::setting( 'newsletter_auto' ) ) {
			$now  = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- 地域の曜日・時刻で判断するため。
			$week = gmdate( 'o-\WW', $now );
			if ( (int) gmdate( 'w', $now ) === (int) SNSCS_Hub::setting( 'newsletter_day' ) && (int) gmdate( 'G', $now ) >= (int) SNSCS_Hub::setting( 'newsletter_hour' ) && 0 !== strpos( $issue, $week ) ) {
				$issue = $week;
				update_option( 'snscs_newsletter_issue', $issue );
			}
		}
		if ( '' !== $issue ) {
			self::send_batch( $issue );
		}
	}

	/**
	 * まだこの号を送っていない購読者に、50通まで送る。残りがあれば数分後にまた呼ぶ。
	 *
	 * @return int 送った数。
	 */
	public static function send_batch( $issue ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, email, token, genres FROM {$table} WHERE status = 'active' AND last_issue <> %s ORDER BY id LIMIT %d", $issue, self::BATCH ), ARRAY_A );
		$built = array();
		foreach ( $rows as $row ) {
			$genres = array_filter( array_map( 'absint', explode( ',', $row['genres'] ) ) );
			$key    = implode( ',', $genres );
			if ( ! isset( $built[ $key ] ) ) {
				$built[ $key ] = self::build_issue( $genres );
			}
			// 送れなかった人にも同じ号を何度も送らないよう、先に記録する。
			$wpdb->update( $table, array( 'last_issue' => $issue ), array( 'id' => $row['id'] ) );
			self::send( $row['email'], $row['token'], $built[ $key ] );
		}
		if ( count( $rows ) === self::BATCH && ! wp_next_scheduled( self::CRON_HOOK . '_more' ) ) {
			wp_schedule_single_event( time() + 3 * MINUTE_IN_SECONDS, self::CRON_HOOK . '_more' );
		}
		return count( $rows );
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, 'メールマガジン', 'メールマガジン', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	private static function admin_url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::ADMIN_PAGE ) );
	}

	public static function counts() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		return array_merge( array( 'active' => 0, 'pending' => 0, 'unsubscribed' => 0 ), array_map( 'intval', wp_list_pluck( $rows, 'n', 'status' ) ) );
	}

	public static function handle_admin() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_newsletter_admin' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$do = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		if ( 'test' === $do ) {
			$user = wp_get_current_user();
			self::send( $user->user_email, str_repeat( '0', 32 ), self::build_issue() );
			$msg = 'test';
		} elseif ( 'send' === $do ) {
			$issue = 'manual-' . current_time( 'YmdHis' );
			update_option( 'snscs_newsletter_issue', $issue );
			self::send_batch( $issue );
			$msg = 'sending';
		} else {
			$msg = '';
		}
		wp_safe_redirect( self::admin_url( array( 'snscs_msg' => $msg ) ) );
		exit;
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$counts = self::counts();
		$issue  = self::render_issue( self::build_issue(), str_repeat( '0', 32 ) );
		$table  = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$latest = $wpdb->get_results( "SELECT email, status, created_at, confirmed_at FROM {$table} ORDER BY id DESC LIMIT 20", ARRAY_A );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['snscs_msg'] ) ? sanitize_key( $_GET['snscs_msg'] ) : '';
		?>
		<div class="wrap">
			<h1>メールマガジン</h1>
			<?php if ( 'test' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p>あなたのメールアドレスにテスト送信しました。</p></div>
			<?php elseif ( 'sending' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p>配信を始めました。50通ずつ順番に送ります。</p></div>
			<?php endif; ?>
			<p>購読中: <strong><?php echo (int) $counts['active']; ?>人</strong>　確認待ち: <?php echo (int) $counts['pending']; ?>人　停止: <?php echo (int) $counts['unsubscribed']; ?>人</p>
			<p>自動配信: <strong><?php echo SNSCS_Hub::setting( 'newsletter_auto' ) ? 'オン' : 'オフ'; ?></strong>（<a href="<?php echo esc_url( SNSCS_Hub::admin_url() ); ?>">「集客・回遊」で設定</a>）</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
				<input type="hidden" name="action" value="snscs_newsletter_admin">
				<input type="hidden" name="do" value="test">
				<?php wp_nonce_field( 'snscs_newsletter_admin', 'snscs_nonce' ); ?>
				<?php submit_button( '自分にテスト送信', 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block" onsubmit="return confirm( '購読中の全員に、今の内容で配信します。よろしいですか？' );">
				<input type="hidden" name="action" value="snscs_newsletter_admin">
				<input type="hidden" name="do" value="send">
				<?php wp_nonce_field( 'snscs_newsletter_admin', 'snscs_nonce' ); ?>
				<?php submit_button( '今すぐ全員に配信', 'primary', 'submit', false ); ?>
			</form>

			<h2>次の号のプレビュー（好きなジャンルを選んでいない人向け）</h2>
			<div style="background:#fff;border:1px solid #dcdcde;padding:16px;max-width:680px">
				<?php echo wp_kses_post( $issue['html'] ); ?>
			</div>

			<h2>最近の登録</h2>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th>メールアドレス</th><th>状態</th><th>登録日時</th><th>確認日時（同意の記録）</th></tr></thead>
				<tbody>
					<?php foreach ( $latest as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['email'] ); ?></td>
							<td><?php echo esc_html( array( 'active' => '購読中', 'pending' => '確認待ち', 'unsubscribed' => '停止' )[ $row['status'] ] ?? $row['status'] ); ?></td>
							<td><?php echo esc_html( get_date_from_gmt( $row['created_at'], 'Y-m-d H:i' ) ); ?></td>
							<td><?php echo $row['confirmed_at'] ? esc_html( get_date_from_gmt( $row['confirmed_at'], 'Y-m-d H:i' ) ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
