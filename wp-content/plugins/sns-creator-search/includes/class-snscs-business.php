<?php
/**
 * 企業向けプラン（1.21。発信者を探してPRなどを相談したい企業・ブランド向けの有料メニュー）。
 *
 * プランの人ができること:
 *   - 検索結果の一覧の保存（CSV。500人まで。名前・URL・ジャンル・地域・SNSごとのフォロワー数・伸び率・相談の受付）
 *   - 比べる人数が10人まで（ふつうは4人）、検索の保存が30件まで（ふつうは5件）
 *   - 比べる画面から、選んだ発信者（相談を受け付けている人だけ。1回10人まで・1日30人まで）にまとめて相談する
 *     （1人ずつ、ふだんの「お仕事の相談」と同じ形で届く。宛先のメールアドレスは知らせない）
 * 申し込み: 企業の方へのページ（[sns_business]）のフォーム → 運営にメールが届く。
 * 有効にする: 運営がユーザーの編集画面で「企業向けプランの期限」を入れる（お支払いは、請求書など運営の方法で）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Business {

	const META        = '_snscs_biz_until';
	const COMPARE_MAX = 10;
	const SAVED_MAX   = 30;
	const EXPORT_MAX  = 500;
	const BULK_MAX    = 10;
	const BULK_DAY    = 30;

	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'profile_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_field' ) );
		add_action( 'admin_post_snscs_export_search', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_snscs_bulk_inquiry', array( __CLASS__, 'handle_bulk' ) );
		add_action( 'admin_post_snscs_biz_apply', array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_nopriv_snscs_biz_apply', array( __CLASS__, 'handle_apply' ) );
	}

	/**
	 * 企業向けプランを使えるか（サイト管理者は確かめのため使える）。
	 */
	public static function is_biz( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$until = (string) get_user_meta( $user_id, self::META, true );
		return '' !== $until && $until >= current_time( 'Y-m-d' );
	}

	/* --------------------------------------------------------------------
	 * 運営: ユーザーの編集画面
	 * ------------------------------------------------------------------ */

	public static function profile_field( $user ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<h2>企業向けプラン</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="snscs_biz_until">プランの期限</label></th>
				<td><input type="date" id="snscs_biz_until" name="snscs_biz_until" value="<?php echo esc_attr( (string) get_user_meta( $user->ID, self::META, true ) ); ?>">
				<?php wp_nonce_field( 'snscs_biz_until', 'snscs_biz_nonce' ); ?>
				<p class="description">この日まで、一覧の保存（CSV）・10人まで比べる・まとめて相談・検索の保存30件が使えます。空欄なら使えません。</p></td>
			</tr>
		</table>
		<?php
	}

	public static function save_profile_field( $user_id ) {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_biz_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_biz_nonce'] ) ), 'snscs_biz_until' ) ) {
			return;
		}
		$value = isset( $_POST['snscs_biz_until'] ) ? sanitize_text_field( wp_unslash( $_POST['snscs_biz_until'] ) ) : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			update_user_meta( $user_id, self::META, $value );
		} else {
			delete_user_meta( $user_id, self::META );
		}
	}

	/* --------------------------------------------------------------------
	 * 一覧の保存（CSV）
	 * ------------------------------------------------------------------ */

	public static function export_button_html( array $args, $total ) {
		if ( ! $total ) {
			return '';
		}
		if ( ! self::is_biz() ) {
			return '';
		}
		$params = array( 'action' => 'snscs_export_search', '_wpnonce' => wp_create_nonce( 'snscs_export_search' ) );
		foreach ( array( 'q', 'platform', 'genre', 'region', 'min_followers', 'max_followers', 'sort' ) as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				$params[ 's_' . $key ] = rawurlencode( (string) $args[ $key ] );
			}
		}
		return '<a class="snscs-button snscs-export" href="' . esc_url( add_query_arg( $params, snscs_post_url() ) ) . '">⬇ この結果を一覧で保存（CSV・' . esc_html( number_format_i18n( min( self::EXPORT_MAX, $total ) ) ) . '人）</a>';
	}

	public static function handle_export() {
		global $wpdb;
		if ( ! self::is_biz() || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'snscs_export_search' ) ) {
			wp_die( 'この操作は、企業向けプランでご利用いただけます。' );
		}
		$raw = array();
		foreach ( array( 'q', 'platform', 'genre', 'region', 'min_followers', 'max_followers', 'sort' ) as $key ) {
			$raw[ $key ] = isset( $_GET[ 's_' . $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ 's_' . $key ] ) ) : '';
		}
		$items = array();
		for ( $page = 1; $page <= (int) ceil( self::EXPORT_MAX / 100 ); $page++ ) {
			$result = SNSCS_Search::run( array_merge( $raw, array( 'per_page' => 100, 'page' => $page ) ) );
			$items  = array_merge( $items, $result['items'] );
			if ( $page >= $result['total_pages'] ) {
				break;
			}
		}
		$items     = array_slice( $items, 0, self::EXPORT_MAX );
		$ids       = array_map( 'intval', wp_list_pluck( $items, 'id' ) );
		$growth    = array();
		if ( $ids ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$growth = wp_list_pluck( $wpdb->get_results( 'SELECT creator_id, growth FROM ' . SNSCS_Scores::table() . ' WHERE creator_id IN (' . implode( ',', $ids ) . ')', ARRAY_A ), 'growth', 'creator_id' );
		}
		$platforms = snscs_platforms();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="creators-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array_merge( array( '名前', 'ページ', 'ジャンル', '地域' ), array_map( function ( $l ) { return $l . 'のフォロワー'; }, array_values( $platforms ) ), array( '合計フォロワー', 'この1か月の伸び（%）', 'お仕事の相談', 'フォロワー数の確認' ) ) );
		foreach ( $items as $item ) {
			$by = array();
			foreach ( $item['accounts'] as $a ) {
				$by[ $a['platform'] ] = $a;
			}
			$row = array( $item['name'], $item['url'], implode( '・', $item['genres'] ), implode( '・', $item['regions'] ) );
			foreach ( array_keys( $platforms ) as $key ) {
				$row[] = isset( $by[ $key ] ) ? $by[ $key ]['followers'] : '';
			}
			$row[] = array_sum( wp_list_pluck( $item['accounts'], 'followers' ) );
			$row[] = isset( $growth[ $item['id'] ] ) ? round( (float) $growth[ $item['id'] ] * 100, 1 ) : '';
			$row[] = SNSCS_Inquiries::settings_for( $item['id'] )['on'] ? '受付中' : '';
			$row[] = array_filter( wp_list_pluck( $item['accounts'], 'verified' ) ) ? '公式に確認済み' : '自己申告';
			fputcsv( $out, $row );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/* --------------------------------------------------------------------
	 * まとめて相談
	 * ------------------------------------------------------------------ */

	public static function bulk_form( array $ids ) {
		$accepting = array();
		foreach ( $ids as $id ) {
			if ( SNSCS_Inquiries::settings_for( $id )['on'] ) {
				$accepting[] = (int) $id;
			}
		}
		$biz_url = SNSCS_Hub::page_url( 'business' );
		if ( ! self::is_biz() ) {
			return $biz_url ? '<p class="snscs-bulk__teaser">💼 <a href="' . esc_url( $biz_url ) . '#snscs-biz-plan">企業向けプラン</a>なら、10人まで比べて、選んだ発信者にまとめて相談できます。一覧の保存（CSV）もできます。</p>' : '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sent = isset( $_GET['snscs_bulk'] ) ? absint( $_GET['snscs_bulk'] ) : -1;
		ob_start();
		?>
		<section class="snscs-bulk" id="snscs-bulk">
			<h3>選んだ発信者にまとめて相談する</h3>
			<?php if ( $sent >= 0 ) : ?>
				<p class="snscs-notice snscs-notice--success" role="status"><?php echo esc_html( sprintf( '%d人に相談を送りました。返信は、入力したメールアドレスに直接届きます。', $sent ) ); ?></p>
			<?php endif; ?>
			<?php if ( ! $accepting ) : ?>
				<p class="snscs-help">選んだ発信者の中に、お仕事の相談を受け付けている人がいません。</p>
			<?php else : ?>
				<p class="snscs-help"><?php echo esc_html( sprintf( '相談を受け付けている%d人（%s）に、1人ずつ届きます。宛先の発信者どうしには、ほかの宛先は知らされません。', count( $accepting ), implode( '・', array_map( 'get_the_title', $accepting ) ) ) ); ?></p>
				<form class="snscs-fields" method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
					<input type="hidden" name="action" value="snscs_bulk_inquiry">
					<input type="hidden" name="ids" value="<?php echo esc_attr( implode( ',', array_slice( $accepting, 0, self::BULK_MAX ) ) ); ?>">
					<input type="hidden" name="all" value="<?php echo esc_attr( implode( ',', array_map( 'intval', $ids ) ) ); ?>">
					<?php wp_nonce_field( 'snscs_bulk_inquiry', 'snscs_nonce' ); ?>
					<label class="snscs-field"><span>会社名・団体名</span><input type="text" name="company" maxlength="100" autocomplete="organization"></label>
					<label class="snscs-field"><span>お名前（必須）</span><input type="text" name="name" maxlength="60" required autocomplete="name"></label>
					<label class="snscs-field"><span>メールアドレス（必須・返信先）</span><input type="email" name="email" maxlength="190" required value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>"></label>
					<label class="snscs-field"><span>ご相談の種類</span><select name="category"><?php foreach ( SNSCS_Inquiries::categories() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
					<label class="snscs-field"><span>ご予算（1人あたり）</span><select name="budget"><?php foreach ( SNSCS_Inquiries::budgets() as $budget ) : ?><option value="<?php echo esc_attr( $budget ); ?>"><?php echo esc_html( $budget ); ?></option><?php endforeach; ?></select></label>
					<label class="snscs-field"><span>ご相談の内容（必須）</span><textarea name="message" rows="6" maxlength="<?php echo (int) SNSCS_Inquiries::MAX_MESSAGE; ?>" required></textarea></label>
					<label class="snscs-check"><input type="checkbox" name="agree" value="1" required> <span><?php echo wp_kses_post( SNSCS_Legal::link_or_text( 'terms' ) ); ?>と<?php echo wp_kses_post( SNSCS_Legal::link_or_text( 'privacy' ) ); ?>に同意し、入力内容を選んだ発信者に送ることに同意します。</span></label>
					<div><button type="submit" class="snscs-button snscs-button--primary">まとめて相談する</button></div>
				</form>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	public static function handle_bulk() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 下で確かめる。
		if ( ! self::is_biz() || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_bulk_inquiry' ) ) {
			wp_die( 'この操作は、企業向けプランでご利用いただけます。' );
		}
		$field    = function ( $key, $max ) {
			return isset( $_POST[ $key ] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ), 0, $max ) : '';
		};
		$ids      = array_slice( array_unique( array_filter( array_map( 'absint', explode( ',', $field( 'ids', 500 ) ) ) ) ), 0, self::BULK_MAX );
		$company  = $field( 'company', 100 );
		$name     = $field( 'name', 60 );
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$category = sanitize_key( $field( 'category', 30 ) );
		$budget   = $field( 'budget', 30 );
		$message  = isset( $_POST['message'] ) ? mb_substr( trim( sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) ), 0, SNSCS_Inquiries::MAX_MESSAGE ) : '';
		// phpcs:enable
		$cats = SNSCS_Inquiries::categories();
		$all  = array_slice( array_unique( array_filter( array_map( 'absint', explode( ',', $field( 'all', 500 ) ) ) ) ), 0, self::COMPARE_MAX );
		$back = add_query_arg( SNSCS_Search_UI::COMPARE_PARAM, implode( ',', $all ? $all : $ids ), snscs_search_url() );
		if ( '' === $name || ! is_email( $email ) || mb_strlen( $message ) < 10 || empty( $_POST['agree'] ) || ! isset( $cats[ $category ] ) || ! in_array( $budget, SNSCS_Inquiries::budgets(), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_safe_redirect( $back . '#snscs-bulk' );
			exit;
		}
		$user    = get_current_user_id();
		$day_key = 'snscs_bulk_' . $user . '_' . current_time( 'Ymd' );
		$used    = (int) get_transient( $day_key );
		$sent    = 0;
		foreach ( $ids as $id ) {
			$settings = SNSCS_Inquiries::settings_for( $id );
			if ( $used + $sent >= self::BULK_DAY || 'publish' !== get_post_status( $id ) || ! $settings['on'] || ( $settings['cats'] && ! in_array( $category, $settings['cats'], true ) ) ) {
				continue;
			}
			$wpdb->insert( SNSCS_Inquiries::table(), array( 'creator_id' => $id, 'company' => $company, 'name' => $name, 'email' => $email, 'category' => $cats[ $category ], 'budget' => $budget, 'message' => $message, 'created_at' => current_time( 'mysql', true ) ) );
			SNSCS_Inquiries::notify( $id, array( 'company' => $company, 'name' => $name, 'email' => $email, 'budget' => $budget, 'message' => $message, 'category' => $cats[ $category ] ) );
			++$sent;
		}
		set_transient( $day_key, $used + $sent, DAY_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'snscs_bulk', $sent, $back ) . '#snscs-bulk' );
		exit;
	}

	/* --------------------------------------------------------------------
	 * 企業の方へのページ: プランの案内と申し込み
	 * ------------------------------------------------------------------ */

	public static function plan_html() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['snscs_biz'] ) ? sanitize_key( $_GET['snscs_biz'] ) : '';
		$items = array(
			array( '⬇', '検索結果を一覧で保存', '条件に合う発信者を、500人までCSVで保存。SNSごとのフォロワー数・伸び率・相談の受付も入ります。' ),
			array( '⚖️', '10人まで並べて比べる', 'フォロワー数・この1か月の伸び・記事の数・相談の受付を、横に並べて比べられます（ふつうは4人まで）。' ),
			array( '✉️', 'まとめて相談', '比べた発信者のうち、相談を受け付けている人に、同じ内容を1人ずつ届けられます。' ),
			array( '🔔', '条件の保存とお知らせ', '探している条件を30件まで保存し、新しい発信者が加わるとお知らせで届きます。' ),
		);
		ob_start();
		?>
		<section class="snscs-bizplan" id="snscs-biz-plan">
			<h2>企業向けプラン</h2>
			<p>たくさんの発信者から、効率よく探して相談したい企業・ブランドの方向けの有料メニューです。<?php echo esc_html( (string) apply_filters( 'snscs_business_price_text', '料金は、ご利用の規模に合わせてご案内します。下のフォームからお問い合わせください。' ) ); ?></p>
			<ul class="snscs-mylink__features">
				<?php foreach ( $items as $f ) : ?>
					<li><span class="snscs-mylink__icon" aria-hidden="true"><?php echo esc_html( $f[0] ); ?></span><strong><?php echo esc_html( $f[1] ); ?></strong><span><?php echo esc_html( $f[2] ); ?></span></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( self::is_biz() ) : ?>
				<p class="snscs-notice snscs-notice--success">このアカウントでは、企業向けプランをお使いいただけます。<a href="<?php echo esc_url( snscs_search_url() ); ?>">発信者を探す</a></p>
			<?php elseif ( 'sent' === $state ) : ?>
				<p class="snscs-notice snscs-notice--success" role="status">お問い合わせを受け付けました。担当者からご連絡します。</p>
			<?php else : ?>
				<?php if ( '' !== $state ) : ?>
					<p class="snscs-notice snscs-notice--error" role="alert">入力内容を確かめて、もう一度お試しください。</p>
				<?php endif; ?>
				<form class="snscs-fields" method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
					<input type="hidden" name="action" value="snscs_biz_apply">
					<input type="hidden" name="snscs_form" value="<?php echo esc_attr( snscs_form_token() ); ?>">
					<p class="snscs-hp" aria-hidden="true"><label>ウェブサイト<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
					<label class="snscs-field"><span>会社名・団体名（必須）</span><input type="text" name="company" maxlength="100" required autocomplete="organization"></label>
					<label class="snscs-field"><span>お名前（必須）</span><input type="text" name="name" maxlength="60" required autocomplete="name"></label>
					<label class="snscs-field"><span>メールアドレス（必須）</span><input type="email" name="email" maxlength="190" required autocomplete="email"></label>
					<label class="snscs-field"><span>ご利用の目的・規模など</span><textarea name="message" rows="4" maxlength="1000"></textarea></label>
					<label class="snscs-check"><input type="checkbox" name="agree" value="1" required> <span><?php echo wp_kses_post( SNSCS_Legal::link_or_text( 'privacy' ) ); ?>に同意します。</span></label>
					<div><button type="submit" class="snscs-button snscs-button--primary">企業向けプランについて問い合わせる</button></div>
				</form>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	public static function handle_apply() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 合言葉（snscs_form_token）で確認する。
		$back = function ( $code ) {
			$url = SNSCS_Hub::page_url( 'business' );
			wp_safe_redirect( add_query_arg( 'snscs_biz', $code, $url ? $url : home_url( '/' ) ) . '#snscs-biz-plan' );
			exit;
		};
		if ( ! empty( $_POST['website'] ) || ! snscs_check_form_token( isset( $_POST['snscs_form'] ) ? sanitize_text_field( wp_unslash( $_POST['snscs_form'] ) ) : '', 3 ) ) {
			$back( 'expired' );
		}
		$field   = function ( $key, $max ) {
			return isset( $_POST[ $key ] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ), 0, $max ) : '';
		};
		$company = $field( 'company', 100 );
		$name    = $field( 'name', 60 );
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$message = isset( $_POST['message'] ) ? mb_substr( trim( sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) ), 0, 1000 ) : '';
		if ( '' === $company || '' === $name || ! is_email( $email ) || empty( $_POST['agree'] ) ) {
			$back( 'invalid' );
		}
		// phpcs:enable
		if ( snscs_rate_limited( 'biz-apply', 3 ) ) {
			$back( 'limit' );
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		wp_mail(
			snscs_operator_info()['email'],
			'[' . $site . '] 企業向けプランのお問い合わせ（' . $company . '）',
			implode( "\n", array( '会社名: ' . $company, 'お名前: ' . $name, 'メールアドレス: ' . $email, '', $message, '', '有効にするときは、管理画面の「ユーザー」でこの方のアカウントを開き、「企業向けプランの期限」を入れてください（アカウントが無いときは、メンバー登録をお願いしてください）。' ) ),
			array( 'Reply-To: ' . $email )
		);
		$back( 'sent' );
	}
}
