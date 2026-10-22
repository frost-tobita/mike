<?php
/**
 * マイリンク（SNSのプロフィール欄に貼る「リンクまとめ」ページ）。
 *
 *   /@ID  （IDは発信者が決める。審査あり。決めるまでは紹介コードで /@紹介コード）
 *
 * - スマホで見やすい1枚のページ（テーマの見た目を使わない）に、SNS・note/Brain・その他のリンク・
 *   最新の記事・お仕事の相談を大きなボタンで並べる。デザインは6種類から選べる（選ぶだけなので審査なし）
 * - 開かれたら紹介リンク流入として数え（表示順位に効く）、閲覧・滞在時間・リンクのクリックも数える
 * - どのリンクが何回押されたかを、発信者のマイページに表示する
 * - ページの下に「ほかの発信者を探す」「あなたもマイリンクを作る（無料）」を出し、サイトへの入口にする
 *   （後者は発信者の招待リンクなので、登録されれば招待として数える）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Biolink {

	const QUERY_VAR  = 'snscs_bio';
	const META_NAME  = '_snscs_linkname';
	const META_THEME = '_snscs_link_theme';
	const MAX_NAME   = 30;
	const KEEP_DAYS  = 90;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'render' ), 0 );
		add_action( 'admin_post_snscs_link_theme', array( __CLASS__, 'handle_theme' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_creator' ) );
		add_action( SNSCS_Scores::CRON_HOOK, array( __CLASS__, 'purge_old' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_link_clicks';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  creator_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  link_key varchar(40) NOT NULL,
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (creator_id,day,link_key)
) {$charset};"
		);
	}

	public static function add_rewrite_rule() {
		add_rewrite_rule( '^@([A-Za-z0-9_-]{3,30})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/* --------------------------------------------------------------------
	 * ID とURL
	 * ------------------------------------------------------------------ */

	/**
	 * IDに使えない言葉（サイトの機能や、運営と間違えられやすいもの）。
	 */
	public static function reserved() {
		return apply_filters( 'snscs_reserved_linknames', array( 'admin', 'administrator', 'root', 'support', 'help', 'info', 'official', 'staff', 'system', 'wp', 'wordpress', 'api', 'login', 'mypage', 'search', 'ranking', 'find', 'tools', 'favorites', 'newsletter', 'business', 'calendar', 'join', 'snscs', 'unei', 'koushiki', 'test' ) );
	}

	/**
	 * IDとして使えるか。使えなければ理由を返す。
	 *
	 * @return string エラーの文。使えるなら空文字。
	 */
	public static function validate_name( $name, $creator_id = 0 ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-z0-9_-]{3,' . self::MAX_NAME . '}$/', $name ) ) {
			return 'マイリンクのIDは、半角英数字・ハイフン・アンダーバーで3〜30文字にしてください。';
		}
		if ( in_array( $name, self::reserved(), true ) ) {
			return 'そのマイリンクのIDは使えません。別のIDにしてください。';
		}
		$taken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s AND post_id <> %d LIMIT 1", self::META_NAME, $name, (int) $creator_id ) );
		// 紹介コード（決める前のマイリンク）とも重ならないようにする。
		$code = SNSCS_Scores::creator_for_code( $name );
		if ( $taken || ( $code && $code !== (int) $creator_id ) ) {
			return 'そのマイリンクのIDは、ほかの発信者が使っています。別のIDにしてください。';
		}
		return '';
	}

	/**
	 * IDから発信者（掲載中の人だけ）。決めたIDが無ければ紹介コードとしても探す。
	 */
	public static function creator_for( $name ) {
		global $wpdb;
		$name = (string) $name;
		$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", self::META_NAME, strtolower( $name ) ) );
		if ( ! $id ) {
			$id = SNSCS_Scores::creator_for_code( $name );
		}
		return ( $id && 'publish' === get_post_status( $id ) && SNSCS_Post_Types::POST_TYPE === get_post_type( $id ) ) ? $id : 0;
	}

	public static function name_for( $creator_id ) {
		$name = (string) get_post_meta( $creator_id, self::META_NAME, true );
		return '' !== $name ? $name : SNSCS_Scores::ref_code( $creator_id );
	}

	public static function url( $creator_id ) {
		$name = self::name_for( $creator_id );
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/@' . $name );
		}
		return add_query_arg( self::QUERY_VAR, $name, home_url( '/' ) );
	}

	/* --------------------------------------------------------------------
	 * デザイン
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,array{label:string,bg:string,text:string,muted:string,button:string,button_text:string,border:string}>
	 */
	public static function themes() {
		return apply_filters(
			'snscs_link_themes',
			array(
				'simple'  => array( 'label' => 'シンプル', 'bg' => '#f5f7fb', 'text' => '#111827', 'muted' => '#4b5563', 'button' => '#ffffff', 'button_text' => '#111827', 'border' => '#e5e7eb' ),
				'dark'    => array( 'label' => 'ダーク', 'bg' => '#0f172a', 'text' => '#f8fafc', 'muted' => '#cbd5e1', 'button' => '#1e293b', 'button_text' => '#f8fafc', 'border' => '#334155' ),
				'sakura'  => array( 'label' => 'さくら', 'bg' => 'linear-gradient(180deg,#fff1f5,#ffe4ec)', 'text' => '#4a1d2f', 'muted' => '#7a4a5d', 'button' => '#ffffff', 'button_text' => '#9d174d', 'border' => '#fbcfe8' ),
				'forest'  => array( 'label' => 'フォレスト', 'bg' => 'linear-gradient(180deg,#ecfdf5,#d1fae5)', 'text' => '#064e3b', 'muted' => '#336b57', 'button' => '#065f46', 'button_text' => '#ffffff', 'border' => '#065f46' ),
				'ocean'   => array( 'label' => 'オーシャン', 'bg' => 'linear-gradient(180deg,#1e3a8a,#2563eb)', 'text' => '#ffffff', 'muted' => '#dbeafe', 'button' => 'rgba(255,255,255,.14)', 'button_text' => '#ffffff', 'border' => 'rgba(255,255,255,.5)' ),
				'sunset'  => array( 'label' => 'サンセット', 'bg' => 'linear-gradient(160deg,#f97316,#db2777)', 'text' => '#ffffff', 'muted' => '#ffedd5', 'button' => '#ffffff', 'button_text' => '#9a3412', 'border' => '#ffffff' ),
			)
		);
	}

	public static function theme_for( $creator_id ) {
		$theme = (string) get_post_meta( $creator_id, self::META_THEME, true );
		return array_key_exists( $theme, self::themes() ) ? $theme : 'simple';
	}

	public static function handle_theme() {
		if ( ! is_user_logged_in() || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_link_theme' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$creator_id = SNSCS_Submissions::creator_id_for_user( get_current_user_id() );
		$theme      = isset( $_POST['theme'] ) ? sanitize_key( $_POST['theme'] ) : '';
		if ( $creator_id && array_key_exists( $theme, self::themes() ) ) {
			update_post_meta( $creator_id, self::META_THEME, $theme );
		}
		wp_safe_redirect( add_query_arg( 'snscs_notice', 'link_theme_saved', snscs_portal_url() ) . '#snscs-biolink' );
		exit;
	}

	/* --------------------------------------------------------------------
	 * クリックの記録
	 * ------------------------------------------------------------------ */

	/**
	 * リンクのクリックを1回数える（どのリンクかの分析用。表示順位には使わない）。
	 */
	public static function record_click( $creator_id, $key ) {
		global $wpdb;
		if ( ! preg_match( '/^(sns|store|link|page|inquiry|article):[a-z0-9_]{1,20}$/', $key ) ) {
			return;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (creator_id, day, link_key, clicks) VALUES (%d, %s, %s, 1) ON DUPLICATE KEY UPDATE clicks = clicks + 1", $creator_id, current_time( 'Y-m-d' ), $key ) );
	}

	/**
	 * 直近の日数のリンクごとのクリック数（多い順）。
	 *
	 * @return array<string,int>
	 */
	public static function clicks( $creator_id, $days = 30 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . ( $days - 1 ) . ' days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT link_key, SUM(clicks) AS n FROM {$table} WHERE creator_id = %d AND day >= %s GROUP BY link_key ORDER BY n DESC", $creator_id, $since ), ARRAY_A );
		return array_map( 'intval', wp_list_pluck( $rows, 'n', 'link_key' ) );
	}

	public static function purge_old() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . self::KEEP_DAYS . ' days' ) ) ) );
	}

	public static function delete_for_creator( $post_id ) {
		global $wpdb;
		if ( SNSCS_Post_Types::POST_TYPE === get_post_type( $post_id ) ) {
			$wpdb->delete( self::table(), array( 'creator_id' => $post_id ), array( '%d' ) );
		}
	}

	/* --------------------------------------------------------------------
	 * ページ
	 * ------------------------------------------------------------------ */

	/**
	 * ページに並べるボタン。
	 *
	 * @return array<int,array{key:string,label:string,sub:string,url:string,kind:string}>
	 */
	public static function buttons( $creator_id ) {
		$buttons = array();
		foreach ( SNSCS_Search::format_accounts( SNSCS_Accounts::get_for_creator( $creator_id ) ) as $account ) {
			if ( $account['url'] ) {
				$buttons[] = array(
					'key'   => 'sns:' . $account['platform'],
					'label' => $account['platform_label'],
					'sub'   => snscs_display_handle( $account['platform'], $account['handle'] ) . ( $account['followers'] ? '・' . $account['followers_label'] : '' ),
					'url'   => $account['url'],
					'kind'  => 'sns sns--' . $account['platform'],
				);
			}
		}
		$stores = snscs_profile_stores();
		foreach ( SNSCS_Submissions::stores_for( $creator_id ) as $key => $handle ) {
			$buttons[] = array(
				'key'   => 'store:' . $key,
				'label' => $stores[ $key ]['label'],
				'sub'   => $handle,
				'url'   => sprintf( $stores[ $key ]['url'], rawurlencode( $handle ) ),
				'kind'  => 'store store--' . $key,
			);
		}
		foreach ( SNSCS_Submissions::links_for( $creator_id ) as $i => $link ) {
			$buttons[] = array(
				'key'   => 'link:' . $i,
				'label' => $link['label'],
				'sub'   => (string) wp_parse_url( $link['url'], PHP_URL_HOST ),
				'url'   => $link['url'],
				'kind'  => 'link',
			);
		}
		return $buttons;
	}

	/**
	 * クリックの記録のキーを、人が読める名前に。
	 */
	public static function key_label( $creator_id, $key ) {
		foreach ( self::buttons( $creator_id ) as $button ) {
			if ( $button['key'] === $key ) {
				return $button['label'];
			}
		}
		$fixed = array( 'page:profile' => '詳しいプロフィール', 'inquiry:form' => 'お仕事の相談' );
		if ( isset( $fixed[ $key ] ) ) {
			return $fixed[ $key ];
		}
		return 0 === strpos( $key, 'article:' ) ? '記事' : $key;
	}

	/**
	 * /@ID を開いたとき: 数えて、マイリンクのページを出す。
	 */
	public static function render() {
		$name = get_query_var( self::QUERY_VAR );
		if ( '' === $name || ! is_string( $name ) ) {
			return;
		}
		$creator_id = self::creator_for( $name );
		if ( ! $creator_id ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		// 紹介コードのURLで来て、IDが決まっていれば、IDのURLにそろえる。
		$own = (string) get_post_meta( $creator_id, self::META_NAME, true );
		if ( '' !== $own && strtolower( $name ) !== $own && get_option( 'permalink_structure' ) ) {
			wp_safe_redirect( self::url( $creator_id ), 301 );
			exit;
		}

		// SNSのプロフィールから来た人 = 発信者が連れてきた人なので、紹介リンク流入として数える。
		SNSCS_Referrals::count_visit( $creator_id, SNSCS_Scores::ref_code( $creator_id ) );

		$own_view = is_user_logged_in() && (int) get_post_field( 'post_author', $creator_id ) === get_current_user_id();
		$tracking = ( $own_view || current_user_can( 'edit_others_posts' ) ) ? null : SNSCS_Tracking::config( $creator_id, 'profile' );

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo self::page_html( $creator_id, $tracking ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- page_html() の中でエスケープ済み。
		exit;
	}

	/**
	 * マイリンクのページ全体のHTML。
	 *
	 * @param int        $creator_id 発信者。
	 * @param array|null $tracking   計測の設定（本人・運営が見るときは null）。
	 * @param string     $theme      デザイン（プレビュー用。省略時は保存したもの）。
	 */
	public static function page_html( $creator_id, $tracking = null, $theme = '' ) {
		$post     = get_post( $creator_id );
		$themes   = self::themes();
		$theme    = array_key_exists( $theme, $themes ) ? $theme : self::theme_for( $creator_id );
		$t        = $themes[ $theme ];
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$name     = get_the_title( $creator_id );
		$photo    = get_the_post_thumbnail_url( $creator_id, 'medium' );
		$bio      = wp_trim_words( SNSCS_Search::excerpt( $post ), 80, '…' );
		$genres   = wp_get_object_terms( $creator_id, SNSCS_Post_Types::TAX_GENRE, array( 'fields' => 'names' ) );
		$articles = SNSCS_Articles::for_creator( $creator_id, 'publish', 3 );
		$inquiry  = SNSCS_Inquiries::settings_for( $creator_id )['on'];
		$profile  = get_permalink( $creator_id );
		$join     = SNSCS_Invites::join_url( $creator_id );
		$search   = snscs_search_url();
		$desc     = sprintf( '%sのSNS・リンクまとめ', $name );

		ob_start();
		?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $name . ' | ' . $site ); ?></title>
<meta name="description" content="<?php echo esc_attr( $desc . '。' . $bio ); ?>">
<link rel="canonical" href="<?php echo esc_url( self::url( $creator_id ) ); ?>">
<meta property="og:type" content="profile">
<meta property="og:title" content="<?php echo esc_attr( $name . ' | ' . $site ); ?>">
<meta property="og:description" content="<?php echo esc_attr( $desc ); ?>">
<meta property="og:url" content="<?php echo esc_url( self::url( $creator_id ) ); ?>">
<meta property="og:image" content="<?php echo esc_url( SNSCS_Share::share_url( $creator_id ) ); ?>">
<meta name="twitter:card" content="summary_large_image">
<style>
*{box-sizing:border-box}
html{min-height:100%;background:<?php echo esc_html( $t['bg'] ); ?>}
body{margin:0;color:<?php echo esc_html( $t['text'] ); ?>;font-family:"Hiragino Sans","Noto Sans JP","Yu Gothic",Meiryo,system-ui,sans-serif;line-height:1.6}
.bio{max-width:560px;margin:0 auto;padding:40px 16px 32px;text-align:center}
.bio__photo{width:104px;height:104px;border-radius:50%;object-fit:cover;border:3px solid <?php echo esc_html( $t['border'] ); ?>}
.bio__initial{display:inline-flex;align-items:center;justify-content:center;width:104px;height:104px;border-radius:50%;background:<?php echo esc_html( $t['button'] ); ?>;color:<?php echo esc_html( $t['button_text'] ); ?>;font-size:44px;font-weight:700;border:3px solid <?php echo esc_html( $t['border'] ); ?>}
.bio__name{margin:12px 0 4px;font-size:1.4em}
.bio__genres{margin:0 0 8px;color:<?php echo esc_html( $t['muted'] ); ?>;font-size:.85em}
.bio__text{margin:0 auto 24px;color:<?php echo esc_html( $t['muted'] ); ?>;font-size:.92em;text-align:left;max-width:30em}
.bio__list{display:grid;gap:12px;margin:0 0 24px;padding:0;list-style:none}
.bio__btn{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:58px;padding:10px 16px;border:2px solid <?php echo esc_html( $t['border'] ); ?>;border-radius:14px;background:<?php echo esc_html( $t['button'] ); ?>;color:<?php echo esc_html( $t['button_text'] ); ?>;text-decoration:none;font-weight:700;transition:transform .1s}
.bio__btn:hover,.bio__btn:focus-visible{transform:translateY(-2px)}
.bio__btn small{font-weight:400;font-size:.8em;opacity:.8;word-break:break-all}
.bio__heading{margin:28px 0 10px;font-size:.95em;color:<?php echo esc_html( $t['muted'] ); ?>}
.bio__foot{margin-top:36px;padding-top:20px;border-top:1px solid <?php echo esc_html( $t['border'] ); ?>;font-size:.85em}
.bio__foot a{color:<?php echo esc_html( $t['text'] ); ?>}
.bio__foot p{margin:6px 0}
.bio__make{display:inline-block;margin-top:8px;padding:6px 16px;border:1px solid <?php echo esc_html( $t['border'] ); ?>;border-radius:999px;text-decoration:none}
</style>
</head>
<body>
<main class="bio">
	<?php if ( $photo ) : ?>
		<img class="bio__photo" src="<?php echo esc_url( $photo ); ?>" alt="" width="104" height="104">
	<?php else : ?>
		<span class="bio__initial" aria-hidden="true"><?php echo esc_html( mb_substr( $name, 0, 1 ) ); ?></span>
	<?php endif; ?>
	<h1 class="bio__name"><?php echo esc_html( $name ); ?></h1>
	<?php if ( $genres && ! is_wp_error( $genres ) ) : ?>
		<p class="bio__genres"><?php echo esc_html( implode( '・', $genres ) ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $bio ) : ?>
		<p class="bio__text"><?php echo esc_html( $bio ); ?></p>
	<?php endif; ?>

	<ul class="bio__list">
		<?php foreach ( self::buttons( $creator_id ) as $button ) : ?>
			<li><a class="bio__btn bio__btn--<?php echo esc_attr( $button['kind'] ); ?>" href="<?php echo esc_url( $button['url'] ); ?>" target="_blank" rel="noopener nofollow ugc" data-snscs-out="<?php echo esc_attr( $button['key'] ); ?>"><?php echo esc_html( $button['label'] ); ?><?php if ( '' !== $button['sub'] ) : ?><small><?php echo esc_html( $button['sub'] ); ?></small><?php endif; ?></a></li>
		<?php endforeach; ?>
		<?php if ( $inquiry ) : ?>
			<li><a class="bio__btn" href="<?php echo esc_url( $profile . '#snscs-inquiry' ); ?>" data-snscs-out="inquiry:form">お仕事の相談<small>企業・団体の方はこちら</small></a></li>
		<?php endif; ?>
		<li><a class="bio__btn" href="<?php echo esc_url( $profile ); ?>" data-snscs-out="page:profile">詳しいプロフィール<small><?php echo esc_html( $site ); ?></small></a></li>
	</ul>

	<?php if ( $articles ) : ?>
		<h2 class="bio__heading">最新の記事</h2>
		<ul class="bio__list">
			<?php foreach ( $articles as $article ) : ?>
				<li><a class="bio__btn" href="<?php echo esc_url( get_permalink( $article ) ); ?>" data-snscs-out="article:<?php echo (int) $article->ID; ?>"><?php echo esc_html( get_the_title( $article ) ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<footer class="bio__foot">
		<p><a href="<?php echo esc_url( $search ); ?>"><?php echo esc_html( $site ); ?>でほかの発信者を探す</a></p>
		<a class="bio__make" href="<?php echo esc_url( $join ); ?>">あなたもマイリンクを作る（無料）</a>
	</footer>
</main>
<?php if ( $tracking ) : ?>
<script>window.snscsPageview = <?php echo wp_json_encode( $tracking ); ?>;</script>
<script src="<?php echo esc_url( SNSCS_URL . 'assets/js/snscs-pageview.js?ver=' . SNSCS_VERSION ); ?>" defer></script>
<?php endif; ?>
</body>
</html>
		<?php
		return ob_get_clean();
	}

	/* --------------------------------------------------------------------
	 * マイページ
	 * ------------------------------------------------------------------ */

	public static function render_portal( $creator_id ) {
		$url     = self::url( $creator_id );
		$current = self::theme_for( $creator_id );
		$own     = (string) get_post_meta( $creator_id, self::META_NAME, true );
		$clicks  = self::clicks( $creator_id );
		$where   = array(
			'X'         => 'プロフィールを編集 →「ウェブサイト」',
			'Instagram' => 'プロフィールを編集 →「リンク」→「外部リンクを追加」',
			'TikTok'    => 'プロフィールを編集 →「ウェブサイト」（表示できる条件があります）',
			'YouTube'   => 'チャンネルをカスタマイズ →「リンク」',
			'Threads'   => 'プロフィールを編集 →「リンク」',
		);
		?>
		<section class="snscs-panel snscs-biolink" id="snscs-biolink">
			<h2 class="snscs-panel__title">マイリンク（SNSのプロフィールに貼るリンク）</h2>
			<p>あなたのSNS・note・Brain・ブログなどを1ページにまとめた、スマホ向けのページです。各SNSのプロフィール欄に貼ってください。開かれた数は紹介リンク流入として数え、<strong>おすすめ順で上に表示されやすくなります</strong>。</p>
			<div class="snscs-referral__link">
				<label for="snscs-bio-link" class="screen-reader-text">マイリンク</label>
				<input id="snscs-bio-link" type="text" readonly value="<?php echo esc_url( $url ); ?>" onclick="this.select();">
				<button type="button" class="snscs-button snscs-button--primary" onclick="var i=document.getElementById('snscs-bio-link');i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent='コピーしました';">コピー</button>
			</div>
			<p><a class="snscs-button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">マイリンクを見る</a></p>
			<?php if ( '' === $own ) : ?>
				<p class="snscs-help">「@hanako」のような覚えやすいIDにしたいときは、下の掲載内容のフォームの「マイリンクのID」に入れて申請してください（審査のあとに切り替わります。今のURLもそのまま使えます）。</p>
			<?php endif; ?>

			<h3 class="snscs-sharekit__heading">デザイン</h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="snscs_link_theme">
				<?php wp_nonce_field( 'snscs_link_theme', 'snscs_nonce' ); ?>
				<div class="snscs-themes">
					<?php foreach ( self::themes() as $key => $theme ) : ?>
						<label class="snscs-theme">
							<input type="radio" name="theme" value="<?php echo esc_attr( $key ); ?>" <?php checked( $current, $key ); ?>>
							<span class="snscs-theme__swatch" style="background: <?php echo esc_attr( $theme['bg'] ); ?>"><span style="background: <?php echo esc_attr( $theme['button'] ); ?>; border-color: <?php echo esc_attr( $theme['border'] ); ?>"></span><span style="background: <?php echo esc_attr( $theme['button'] ); ?>; border-color: <?php echo esc_attr( $theme['border'] ); ?>"></span></span>
							<span class="snscs-theme__label"><?php echo esc_html( $theme['label'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<button type="submit" class="snscs-button">デザインを保存</button>
			</form>

			<h3 class="snscs-sharekit__heading">押されたリンク（直近30日）</h3>
			<?php if ( $clicks ) : ?>
				<?php $max = max( $clicks ); ?>
				<ul class="snscs-analysis__list">
					<?php foreach ( array_slice( $clicks, 0, 10, true ) as $key => $count ) : ?>
						<li>
							<span class="snscs-analysis__label"><?php echo esc_html( self::key_label( $creator_id, $key ) ); ?></span>
							<span class="snscs-analysis__bar" role="img" aria-label="<?php echo esc_attr( $count . '回' ); ?>"><span style="width: <?php echo esc_attr( max( 2, round( $count / $max * 100 ) ) ); ?>%"></span></span>
							<span class="snscs-analysis__point"><?php echo esc_html( number_format_i18n( $count ) ); ?>回</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="snscs-help">まだありません。マイリンクを貼ると、どのリンクがよく押されたかがここに出ます。</p>
			<?php endif; ?>

			<details class="snscs-invite__template">
				<summary>各SNSのどこに貼る？</summary>
				<ul>
					<?php foreach ( $where as $sns => $place ) : ?>
						<li><strong><?php echo esc_html( $sns ); ?></strong>: <?php echo esc_html( $place ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p class="snscs-help">SNSのアプリの画面は変わることがあります。見つからないときは「プロフィールを編集」の中を探してみてください。</p>
			</details>
		</section>
		<?php
	}
}
