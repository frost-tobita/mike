<?php
/**
 * 報道向けの発表文（プレスリリースの下書き）と、プレス・データのページ。
 *
 *   下書き: 毎月1日の9時以降に、前の月の数字（SNSCS_Column_Kinds::report_data。検索の上位・急上昇・ジャンル別・
 *           公式の数での伸び・新しく掲載された発信者・アワードの結果）から、題名・本文（トピックス・調査概要・
 *           出典の書き方・運営者の情報）を作り、運営にメールで知らせる。検索の記録が30人未満の月は作らない。
 *           管理画面「発信者 → 報道向けの発表文」で、コピー・テキストで保存・作り直し・AIでリード文を整える。
 *           配信サービス（PR TIMES など）への登録は、運営が手で行う。
 *   公開  : [sns_press]（機能ページ「プレス・データ」）に月ごとの要点と、出典を書けば引用できる旨。
 *           /press/{年月}/ に、その月の発表文（記者・ブロガーが引用しやすい形）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Press {

	const OPTION     = 'snscs_press';
	const QUERY_VAR  = 'snscs_press';
	const ADMIN_PAGE = 'snscs-press';
	const HOUR       = 9;

	public static function init() {
		SNSCS_Subpages::route( 'press', self::QUERY_VAR );
		add_action( 'template_redirect', array( __CLASS__, 'resolve' ), 5 );
		add_action( SNSCS_Inbox::CRON_HOOK, array( __CLASS__, 'maybe_build' ), 50 );
		add_shortcode( 'sns_press', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 25 );
		add_action( 'admin_post_snscs_press_run', array( __CLASS__, 'handle_run' ) );
	}

	public static function all() {
		$all = (array) get_option( self::OPTION, array() );
		krsort( $all );
		return $all;
	}

	/* --------------------------------------------------------------------
	 * 作る
	 * ------------------------------------------------------------------ */

	public static function maybe_build() {
		$month = gmdate( 'Y-m', strtotime( current_time( 'Y-m-01' ) . ' -1 month' ) );
		if ( (int) current_time( 'G' ) < self::HOUR || isset( self::all()[ $month ] ) || get_option( 'snscs_press_tried' ) === $month ) {
			return false;
		}
		update_option( 'snscs_press_tried', $month, false );
		$release = self::build( $month );
		if ( $release ) {
			wp_mail( get_option( 'admin_email' ), '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] 報道向けの発表文の下書きができました', $release['title'] . "\n\n" . admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::ADMIN_PAGE . '&month=' . $month ) );
		}
		return (bool) $release;
	}

	/**
	 * その月の発表文を作って保存する（数字が少なすぎる月は作らない）。
	 *
	 * @return array|null
	 */
	public static function build( $month ) {
		$d = SNSCS_Column_Kinds::report_data( $month );
		if ( $d['searches'] < SNSCS_Column_Kinds::REPORT_MIN ) {
			return null;
		}
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$op      = snscs_operator_info();
		$num     = function ( $n ) {
			return number_format_i18n( (int) $n );
		};
		$rising  = $d['rising'][0] ?? null;
		$grow    = $d['growth'][0] ?? null;
		$topg    = $d['genres'][0] ?? null;
		$heads   = array();
		if ( $rising ) {
			$heads[] = '「' . $rising['term'] . '」の検索が' . ( $rising['previous'] ? '前月の' . round( $rising['visitors'] / max( 1, $rising['previous'] ), 1 ) . '倍に' : '急増' );
		}
		if ( $grow ) {
			$heads[] = $grow['name'] . 'ジャンルの発信者のフォロワーが最も伸びる（+' . $grow['rate'] . '%）';
		} elseif ( $topg ) {
			$heads[] = '最も検索されたジャンルは「' . $topg['name'] . '」';
		}
		$title = $d['label'] . 'のSNSトレンド' . ( $heads ? '：' . implode( '、', $heads ) : '' ) . ' ― ' . $site . '調べ';

		$topics = array();
		if ( $d['top'] ) {
			$topics[] = 'よく検索された言葉の1位は「' . $d['top'][0]['term'] . '」（' . $num( $d['top'][0]['visitors'] ) . '人）。上位は' . implode( '、', array_map( function ( $t ) { return '「' . $t['term'] . '」'; }, array_slice( $d['top'], 1, 4 ) ) ) . 'と続いた。';
		}
		if ( $d['rising'] ) {
			$topics[] = '急上昇した言葉は' . implode( '、', array_map( function ( $t ) use ( $num ) { return '「' . $t['term'] . '」（' . $num( $t['visitors'] ) . '人' . ( $t['previous'] ? '・前月比' . round( $t['visitors'] / max( 1, $t['previous'] ), 1 ) . '倍' : '・前月は記録なし' ) . '）'; }, array_slice( $d['rising'], 0, 3 ) ) ) . '。';
		}
		if ( $d['genres'] ) {
			$topics[] = 'ジャンル別では「' . $d['genres'][0]['name'] . '」がもっとも多く検索され（' . $num( $d['genres'][0]['visitors'] ) . '人）、' . ( isset( $d['genres'][1] ) ? '「' . $d['genres'][1]['name'] . '」（' . $num( $d['genres'][1]['visitors'] ) . '人）が続いた。' : '' );
		}
		if ( $d['growth'] ) {
			$topics[] = '公式のフォロワー数を記録している発信者では、「' . $d['growth'][0]['name'] . '」ジャンルの伸びがもっとも大きく、1か月で' . ( $d['growth'][0]['gain'] >= 0 ? '+' : '' ) . $num( $d['growth'][0]['gain'] ) . '人（+' . $d['growth'][0]['rate'] . '%、対象' . $num( $d['growth'][0]['creators'] ) . '人）。';
		}
		$topics[] = '新しく掲載された発信者は' . $num( $d['new'] ) . '人で、掲載中の発信者は' . $num( $d['creators'] ) . '人となった。';
		$awards = (array) ( get_option( SNSCS_Awards::RESULTS, array() )[ $month ] ?? array() );
		if ( $awards ) {
			$names = array();
			foreach ( $awards as $genre_id => $rows ) {
				$g = get_term( (int) $genre_id, SNSCS_Post_Types::TAX_GENRE );
				if ( $g && ! is_wp_error( $g ) && $rows ) {
					$names[] = $g->name . '：' . html_entity_decode( get_the_title( $rows[0][0] ), ENT_QUOTES, 'UTF-8' ) . 'さん';
				}
			}
			if ( $names ) {
				$topics[] = 'ファン投票による「発信者アワード」の1位は、' . implode( '、', array_slice( $names, 0, 5 ) ) . '。';
			}
		}
		$lead = $site . '（' . home_url( '/' ) . '）は、' . $d['label'] . 'に当サイトで行われたSNS発信者の検索と、掲載中の発信者のフォロワー数の推移をまとめた「SNSトレンドレポート」を公開しました。' . ( $heads ? implode( '、', $heads ) . 'など、' : '' ) . 'SNSでいま関心を集めている話題とジャンルが分かります。';
		$method = '調査期間：' . $d['label'] . '（1日〜末日）' . "\n" . '調査対象：' . $site . 'での発信者の検索（' . $num( $d['searches'] ) . '人のべ）、掲載中の発信者' . $num( $d['creators'] ) . '人' . "\n" . '調査方法：サイト内の検索を、言葉ごと・日ごとの人数として匿名で集計（同じ人の同じ日の検索は1回）。フォロワー数は、各SNSの公式の数を記録している発信者の月初と月末の差。';
		$credit = '出典の書き方：本リリースの数字・表を引用する際は「' . $site . '調べ」と明記し、可能であれば ' . ( SNSCS_Hub::page_url( 'press' ) ? SNSCS_Hub::page_url( 'press' ) : home_url( '/' ) ) . ' へのリンクをお願いします。';
		$about  = '【' . $site . 'について】' . "\n" . 'X・YouTube・Instagram・Threads・TikTok・Substack で発信している人を、ジャンルや地域から探せる検索サイトです。' . "\n" . '【本件に関するお問い合わせ】' . "\n" . $op['operator'] . ( '' !== $op['address'] ? '（' . $op['address'] . '）' : '' ) . "\n" . 'メール：' . $op['email'];
		$body   = $lead . "\n\n" . '■ トピックス' . "\n" . implode( "\n", array_map( function ( $t ) { return '・' . $t; }, $topics ) ) . "\n\n" . '■ 調査概要' . "\n" . $method . "\n\n" . $credit . "\n\n" . $about;

		$release = array( 'month' => $month, 'label' => $d['label'], 'title' => $title, 'lead' => $lead, 'topics' => $topics, 'body' => $body, 'created' => current_time( 'mysql' ) );
		$all             = (array) get_option( self::OPTION, array() );
		$all[ $month ]   = $release;
		krsort( $all );
		update_option( self::OPTION, array_slice( $all, 0, 24, true ), false );
		return $release;
	}

	/**
	 * AIでリード文を読みやすく整える（数字は変えさせない）。
	 *
	 * @return bool
	 */
	public static function polish( $month ) {
		$all = (array) get_option( self::OPTION, array() );
		if ( ! isset( $all[ $month ] ) || ! SNSCS_Claude::has_key() ) {
			return false;
		}
		$r       = $all[ $month ];
		$message = SNSCS_Claude::request(
			'POST',
			'/v1/messages',
			array(
				'model'         => SNSCS_Profile_AI::MODEL,
				'max_tokens'    => 1200,
				'system'        => 'あなたは企業の広報担当です。与えられた発表文の題名とリード文を、報道関係者が読みやすい自然な日本語に整えてください。数字・言葉・固有名詞は一つも変えず、足さないでください。誇張しないでください。題名は60字以内、リード文は250字以内。',
				'output_config' => array( 'format' => array( 'type' => 'json_schema', 'schema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'title', 'lead' ), 'properties' => array( 'title' => array( 'type' => 'string' ), 'lead' => array( 'type' => 'string' ) ) ) ) ),
				'messages'      => array( array( 'role' => 'user', 'content' => "題名: {$r['title']}\nリード文: {$r['lead']}\nトピックス:\n" . implode( "\n", $r['topics'] ) ) ),
			),
			array(),
			60
		);
		$data = is_wp_error( $message ) ? null : json_decode( SNSCS_Claude::text_of( $message ), true );
		if ( ! is_array( $data ) || empty( $data['title'] ) || empty( $data['lead'] ) ) {
			return false;
		}
		$r['body']        = str_replace( $r['lead'], sanitize_text_field( $data['lead'] ), $r['body'] );
		$r['title']       = mb_substr( sanitize_text_field( $data['title'] ), 0, 80 );
		$r['lead']        = sanitize_text_field( $data['lead'] );
		$r['polished']    = current_time( 'mysql' );
		$all[ $month ]    = $r;
		update_option( self::OPTION, $all, false );
		return true;
	}

	/* --------------------------------------------------------------------
	 * 公開のページ
	 * ------------------------------------------------------------------ */

	public static function url( $month ) {
		return SNSCS_Subpages::url( 'press', str_replace( '-', '', $month ) );
	}

	private static $current = null;

	public static function resolve() {
		$value = SNSCS_Subpages::value( 'press' );
		if ( '' === $value ) {
			return;
		}
		$month = preg_match( '/^(\d{4})(\d{2})$/', $value, $m ) ? $m[1] . '-' . $m[2] : '';
		$r     = self::all()[ $month ] ?? null;
		if ( ! $r ) {
			SNSCS_Subpages::not_found();
			return;
		}
		self::$current = $r;
		$url           = self::url( $month );
		$crumbs        = array( array( home_url( '/' ), 'トップ' ), array( SNSCS_Hub::page_url( 'press' ), 'プレス・データ' ), array( $url, $r['label'] ) );
		SNSCS_Subpages::set_current(
			array(
				'page_key'    => 'press',
				'title'       => $r['title'],
				'heading'     => $r['title'],
				'description' => mb_substr( $r['lead'], 0, 150 ),
				'url'         => $url,
				'indexable'   => true,
				'jsonld'      => array(
					SNSCS_Subpages::breadcrumb_ld( $crumbs ),
					array( '@type' => 'Article', 'headline' => mb_substr( $r['title'], 0, 110 ), 'datePublished' => mysql2date( 'c', $r['created'] ), 'author' => array( '@type' => 'Organization', 'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 'url' => home_url( '/' ) ) ),
				),
			)
		);
	}

	public static function shortcode() {
		SNSCS_Hub::remember_page( 'press' );
		wp_enqueue_style( 'snscs' );
		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		if ( self::$current ) {
			$r = self::$current;
			echo SNSCS_Subpages::breadcrumbs_html( array( array( home_url( '/' ), 'トップ' ), array( SNSCS_Hub::page_url( 'press' ), 'プレス・データ' ), array( self::url( $r['month'] ), $r['label'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<article class="snscs-press"><p class="snscs-help">' . esc_html( mysql2date( 'Y年n月j日', $r['created'] ) . '発表' ) . '</p><div class="snscs-press__body">' . nl2br( esc_html( $r['body'] ) ) . '</div></article>';
			echo '<p><a class="snscs-button" href="' . esc_url( SNSCS_Trends::page_url() ? SNSCS_Trends::page_url() : home_url( '/' ) ) . '">いまのトレンドを見る</a></p>';
			return ob_get_clean();
		}
		$op = snscs_operator_info();
		?>
		<p class="snscs-feature__lead">報道関係者・ブロガーの方へ。<?php echo esc_html( get_bloginfo( 'name' ) ); ?>での検索と、発信者のフォロワー数の推移から、毎月のSNSトレンドをまとめています。「<?php echo esc_html( get_bloginfo( 'name' ) ); ?>調べ」と出典を明記すれば、数字や表は自由に引用できます。</p>
		<?php foreach ( array_slice( self::all(), 0, 12, true ) as $month => $r ) : ?>
			<section class="snscs-press__item">
				<h2 class="snscs-trendpage__title"><a href="<?php echo esc_url( self::url( $month ) ); ?>"><?php echo esc_html( $r['title'] ); ?></a></h2>
				<ul><?php foreach ( array_slice( $r['topics'], 0, 3 ) as $t ) : ?><li><?php echo esc_html( $t ); ?></li><?php endforeach; ?></ul>
			</section>
		<?php endforeach; ?>
		<?php if ( ! self::all() ) : ?>
			<p class="snscs-help">最初の発表は、来月の初めに公開します。</p>
		<?php endif; ?>
		<p>取材・データのお問い合わせ: <?php echo esc_html( $op['operator'] . '（' . $op['email'] . '）' ); ?></p>
		<?php
		return ob_get_clean();
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, '報道向けの発表文', '報道向けの発表文', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	public static function handle_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		check_admin_referer( 'snscs_press_run', 'snscs_nonce' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() で確認済み。
		$month = preg_match( '/^\d{4}-\d{2}$/', (string) ( $_POST['month'] ?? '' ) ) ? (string) $_POST['month'] : '';
		$do    = sanitize_key( $_POST['do'] ?? '' );
		// phpcs:enable
		$msg = 'failed';
		if ( '' !== $month && 'build' === $do ) {
			$msg = self::build( $month ) ? 'built' : 'few';
		} elseif ( '' !== $month && 'polish' === $do ) {
			$msg = self::polish( $month ) ? 'polished' : 'failed';
		} elseif ( '' !== $month && 'download' === $do && isset( self::all()[ $month ] ) ) {
			$r = self::all()[ $month ];
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="press-' . $month . '.txt"' );
			echo $r['title'] . "\r\n\r\n" . str_replace( "\n", "\r\n", $r['body'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- テキストのファイルとして渡す。
			exit;
		}
		wp_safe_redirect( add_query_arg( array( 'month' => $month, 'snscs_msg' => $msg ), admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::ADMIN_PAGE ) ) );
		exit;
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$all = self::all();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$month = isset( $_GET['month'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $_GET['month'] ) ? (string) $_GET['month'] : (string) array_key_first( $all );
		$msg   = isset( $_GET['snscs_msg'] ) ? sanitize_key( $_GET['snscs_msg'] ) : '';
		// phpcs:enable
		$notes = array( 'built' => '作りました。', 'polished' => 'AIでリード文を整えました（数字は変えていません。念のため確認してください）。', 'few' => 'その月は検索の記録が少ないため、作りませんでした。', 'failed' => 'うまくいきませんでした。' );
		$r     = $all[ $month ] ?? null;
		$form  = function ( $do, $label, $m, $primary = false ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin-right: 6px">
				<input type="hidden" name="action" value="snscs_press_run">
				<input type="hidden" name="month" value="<?php echo esc_attr( $m ); ?>">
				<input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>">
				<?php wp_nonce_field( 'snscs_press_run', 'snscs_nonce' ); ?>
				<button class="button<?php echo $primary ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
			</form>
			<?php
		};
		?>
		<div class="wrap">
			<h1>報道向けの発表文</h1>
			<?php if ( isset( $notes[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo in_array( $msg, array( 'built', 'polished' ), true ) ? 'success' : 'warning'; ?>"><p><?php echo esc_html( $notes[ $msg ] ); ?></p></div>
			<?php endif; ?>
			<p>毎月1日に、前の月の数字から発表文の下書きを作り、メールでお知らせします。内容を確かめて、PR TIMES などの配信サービスに登録してください（ニュースサイトやブログに取り上げられると、サイトへのリンクが増えます）。公開用は <a href="<?php echo esc_url( SNSCS_Hub::page_url( 'press' ) ); ?>" target="_blank">プレス・データ</a> のページにも載ります。</p>
			<p>
				<?php $form( 'build', '前の月の分をいま作る', gmdate( 'Y-m', strtotime( current_time( 'Y-m-01' ) . ' -1 month' ) ), true ); ?>
				<?php $form( 'build', '今月の分を作る（途中経過）', current_time( 'Y-m' ) ); ?>
			</p>
			<?php if ( $all ) : ?>
				<p>月: <?php foreach ( array_keys( $all ) as $m ) : ?><a href="<?php echo esc_url( add_query_arg( 'month', $m ) ); ?>" style="margin-right: 8px<?php echo $m === $month ? '; font-weight: bold' : ''; ?>"><?php echo esc_html( $m ); ?></a><?php endforeach; ?></p>
			<?php endif; ?>
			<?php if ( $r ) : ?>
				<h2><?php echo esc_html( $r['title'] ); ?></h2>
				<textarea class="large-text" rows="24" readonly onclick="this.select();"><?php echo esc_textarea( $r['title'] . "\n\n" . $r['body'] ); ?></textarea>
				<p>
					<?php $form( 'download', 'テキストで保存', $month ); ?>
					<?php $form( 'build', '作り直す', $month ); ?>
					<?php if ( SNSCS_Claude::has_key() ) : ?>
						<?php $form( 'polish', 'AIで題名とリード文を整える', $month ); ?>
					<?php endif; ?>
				</p>
			<?php else : ?>
				<p>まだありません。</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
