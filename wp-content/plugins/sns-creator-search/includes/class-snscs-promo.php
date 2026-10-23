<?php
/**
 * 検索結果の「PR枠」（検索連動型の広告。1.21）。
 *
 * 発信者が「キーワード」「ジャンル」と「1クリックの上限の値段（入札）」「1日の予算」を決めて申し込み、運営が確かめてから出す。
 * キーワードやジャンルに合う検索の1ページ目で、結果の上に「PR」と書いた枠で出す（自然な順位はそのまま）。
 *
 * どの申し込みを出すか（Google 広告と同じ考え方）:
 *   出す順位 = 入札 × 質
 *     質 = 押されやすさ（直近30日の (クリック + 1) ÷ (表示 + 40) を、全体のふつう 2.5% で割ったもの。0.3〜3）× 合い方
 *     合い方 = キーワードに合う 1.0 / ジャンルだけ合う 0.8
 *   払う値段 = 次の順位の人に勝つのに必要なだけ（次の人の「出す順位」÷ 自分の質 + 1円）。入札より高くはならない。
 *   値段は表示ではなく、押されたときだけかかる（同じ人が同じ日に何度押しても1回分）。1日の予算・全体の予算を超えたら出さない。
 * 支払いは、運営が毎月の金額（管理画面の「使った金額」）をもとに請求する。
 * 不正対策で順位を下げている発信者は出さない。ロボットと運営の表示・クリックは数えない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Promo {

	const OPTION     = 'snscs_promo_settings';
	const ADMIN_PAGE = 'snscs-promo';
	const QUERY_VAR  = 'snscs_pr';
	const BASE_CTR   = 0.025;
	const STATUSES   = array(
		'pending'  => '確認待ち',
		'active'   => '掲載中',
		'paused'   => '一時停止',
		'rejected' => '見送り',
		'ended'    => '終了',
	);

	private static $shown = array();

	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_click' ), 3 );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_snscs_promo_apply', array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_snscs_promo_admin', array( __CLASS__, 'handle_admin' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_creator' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_promos';
	}

	public static function stats_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_promo_stats';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		$stats   = self::stats_table();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  creator_id bigint(20) unsigned NOT NULL,
  keywords varchar(500) NOT NULL DEFAULT '',
  genres varchar(500) NOT NULL DEFAULT '',
  bid int(10) unsigned NOT NULL DEFAULT 0,
  daily_budget int(10) unsigned NOT NULL DEFAULT 0,
  total_budget int(10) unsigned NOT NULL DEFAULT 0,
  status varchar(10) NOT NULL DEFAULT 'pending',
  start_date date DEFAULT NULL,
  end_date date DEFAULT NULL,
  note varchar(200) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY creator_id (creator_id),
  KEY status (status)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$stats} (
  promo_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  impressions int(10) unsigned NOT NULL DEFAULT 0,
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  spent int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (promo_id,day)
) {$charset};"
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), array( 'on' => 1, 'slots' => 1, 'reserve' => 10 ) );
	}

	/* --------------------------------------------------------------------
	 * 申し込み
	 * ------------------------------------------------------------------ */

	public static function for_creator( $creator_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE creator_id = %d ORDER BY id DESC LIMIT 1', $creator_id ), ARRAY_A );
	}

	/**
	 * @return array<int,array{impressions:int,clicks:int,spent:int,today_spent:int,month_spent:int}>
	 */
	public static function stats( array $ids, $days = 30 ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return array();
		}
		$today = current_time( 'Y-m-d' );
		$since = gmdate( 'Y-m-d', strtotime( $today . ' -' . ( $days - 1 ) . ' days' ) );
		$month = current_time( 'Y-m-01' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT promo_id, SUM(IF(day >= %s, impressions, 0)) AS imp, SUM(IF(day >= %s, clicks, 0)) AS clk, SUM(IF(day >= %s, spent, 0)) AS spent, SUM(IF(day = %s, spent, 0)) AS today_spent, SUM(IF(day >= %s, spent, 0)) AS month_spent, SUM(spent) AS total_spent FROM ' . self::stats_table() . ' WHERE promo_id IN (' . implode( ',', $ids ) . ') GROUP BY promo_id', $since, $since, $since, $today, $month ), ARRAY_A );
		$out  = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = array( 'impressions' => 0, 'clicks' => 0, 'spent' => 0, 'today_spent' => 0, 'month_spent' => 0, 'total_spent' => 0 );
		}
		foreach ( $rows as $r ) {
			$out[ (int) $r['promo_id'] ] = array(
				'impressions' => (int) $r['imp'],
				'clicks'      => (int) $r['clk'],
				'spent'       => (int) $r['spent'],
				'today_spent' => (int) $r['today_spent'],
				'month_spent' => (int) $r['month_spent'],
				'total_spent' => (int) $r['total_spent'],
			);
		}
		return $out;
	}

	private static function clean_keywords( $raw ) {
		$out = array();
		foreach ( preg_split( '/[,、，\n]+/u', (string) $raw ) as $word ) {
			$word = SNSCS_Text::normalize( mb_substr( trim( sanitize_text_field( $word ) ), 0, 20 ) );
			if ( mb_strlen( $word ) >= 2 ) {
				$out[] = $word;
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 10 );
	}

	public static function handle_apply() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 下で確かめる。
		if ( ! is_user_logged_in() || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_promo_apply' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$creator_id = SNSCS_Submissions::creator_id_for_user( get_current_user_id() );
		$back       = function ( $notice ) {
			wp_safe_redirect( add_query_arg( 'snscs_notice', $notice, snscs_portal_url() ) . '#snscs-promo' );
			exit;
		};
		if ( ! $creator_id ) {
			$back( 'promo_invalid' );
		}
		$s        = self::settings();
		$num      = function ( $key ) {
			return isset( $_POST[ $key ] ) ? absint( preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST[ $key ] ) ) ) : 0;
		};
		$keywords = self::clean_keywords( isset( $_POST['keywords'] ) ? wp_unslash( $_POST['keywords'] ) : '' );
		$genres   = array();
		foreach ( isset( $_POST['genres'] ) ? (array) wp_unslash( $_POST['genres'] ) : array() as $slug ) {
			$slug = sanitize_title( $slug );
			if ( get_term_by( 'slug', $slug, SNSCS_Post_Types::TAX_GENRE ) ) {
				$genres[] = $slug;
			}
		}
		$bid   = min( 10000, $num( 'bid' ) );
		$daily = min( 1000000, $num( 'daily_budget' ) );
		$total = min( 100000000, $num( 'total_budget' ) );
		$date  = function ( $key ) {
			$v = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : null;
		};
		// phpcs:enable
		if ( ( ! $keywords && ! $genres ) || $bid < (int) $s['reserve'] || $daily < $bid ) {
			$back( 'promo_invalid' );
		}
		$data = array(
			'creator_id'   => $creator_id,
			'keywords'     => implode( ',', $keywords ),
			'genres'       => implode( ',', array_unique( $genres ) ),
			'bid'          => $bid,
			'daily_budget' => $daily,
			'total_budget' => $total,
			'start_date'   => $date( 'start_date' ),
			'end_date'     => $date( 'end_date' ),
			'updated_at'   => current_time( 'mysql', true ),
		);
		$current = self::for_creator( $creator_id );
		if ( $current && ! in_array( $current['status'], array( 'ended', 'rejected' ), true ) ) {
			// 言葉が変わったら、もう一度確かめる。値段・予算・期間だけの変更は、そのまま反映する。
			if ( $current['keywords'] !== $data['keywords'] || $current['genres'] !== $data['genres'] ) {
				$data['status'] = 'pending';
			}
			$wpdb->update( self::table(), $data, array( 'id' => (int) $current['id'] ) );
		} else {
			$wpdb->insert( self::table(), array_merge( $data, array( 'status' => 'pending', 'created_at' => current_time( 'mysql', true ) ) ) );
		}
		$back( 'promo_saved' );
	}

	/* --------------------------------------------------------------------
	 * 検索結果に出す
	 * ------------------------------------------------------------------ */

	/**
	 * いまの検索に出す申し込み（オークション）。
	 *
	 * @return array<int,array{promo:array,price:int}>
	 */
	public static function auction( array $args ) {
		global $wpdb;
		$s = self::settings();
		if ( ! $s['on'] || 1 !== (int) $args['page'] || ( '' === $args['q'] && '' === $args['genre'] ) ) {
			return array();
		}
		$today = current_time( 'Y-m-d' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$promos = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status = 'active' AND (start_date IS NULL OR start_date <= %s) AND (end_date IS NULL OR end_date >= %s) LIMIT 500", $today, $today ), ARRAY_A );
		if ( ! $promos ) {
			return array();
		}
		$q         = SNSCS_Text::normalize( $args['q'] );
		$words     = '' !== $q ? preg_split( '/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY ) : array();
		$stats     = self::stats( wp_list_pluck( $promos, 'id' ) );
		$penalties = SNSCS_Integrity::penalties();
		$bids      = array();
		foreach ( $promos as $p ) {
			$id = (int) $p['id'];
			$st = $stats[ $id ];
			if ( 'publish' !== get_post_status( (int) $p['creator_id'] ) || isset( $penalties[ (int) $p['creator_id'] ] ) || in_array( (int) $p['creator_id'], $args['exclude'] ?? array(), true ) ) {
				continue;
			}
			if ( $st['today_spent'] + (int) $p['bid'] > (int) $p['daily_budget'] || ( (int) $p['total_budget'] && $st['total_spent'] + (int) $p['bid'] > (int) $p['total_budget'] ) ) {
				continue;
			}
			$match = 0.0;
			foreach ( array_filter( array_map( array( 'SNSCS_Text', 'normalize' ), explode( ',', $p['keywords'] ) ) ) as $kw ) {
				foreach ( $words as $w ) {
					if ( $kw === $w || ( mb_strlen( $w ) >= 2 && false !== mb_strpos( $kw, $w ) ) || false !== mb_strpos( $q, $kw ) ) {
						$match = 1.0;
						break 2;
					}
				}
			}
			if ( ! $match && '' !== $args['genre'] && in_array( $args['genre'], explode( ',', $p['genres'] ), true ) ) {
				$match = 0.8;
			}
			if ( ! $match ) {
				continue;
			}
			$ctr     = ( $st['clicks'] + 1 ) / ( $st['impressions'] + 40 );
			$quality = min( 3.0, max( 0.3, $ctr / self::BASE_CTR ) ) * $match;
			$bids[]  = array( 'promo' => $p, 'quality' => $quality, 'rank' => (int) $p['bid'] * $quality );
		}
		usort(
			$bids,
			function ( $a, $b ) {
				return $b['rank'] <=> $a['rank'];
			}
		);
		$out   = array();
		$slots = max( 1, min( 3, (int) $s['slots'] ) );
		$seen  = array();
		foreach ( $bids as $i => $b ) {
			if ( count( $out ) >= $slots ) {
				break;
			}
			if ( isset( $seen[ (int) $b['promo']['creator_id'] ] ) ) {
				continue;
			}
			$seen[ (int) $b['promo']['creator_id'] ] = true;
			$next  = $bids[ $i + 1 ]['rank'] ?? 0;
			$price = $next > 0 ? (int) floor( $next / $b['quality'] ) + 1 : (int) $s['reserve'];
			$out[] = array( 'promo' => $b['promo'], 'price' => max( (int) $s['reserve'], min( (int) $b['promo']['bid'], $price ) ) );
		}
		return $out;
	}

	private static function token( $id, $price, $day = null ) {
		return substr( hash_hmac( 'sha256', 'pr|' . $id . '|' . $price . '|' . ( $day ? $day : current_time( 'Y-m-d' ) ), wp_salt( 'nonce' ) ), 0, 16 );
	}

	public static function click_url( $id, $price ) {
		return add_query_arg( array( self::QUERY_VAR => (int) $id, 'p' => (int) $price, 't' => self::token( $id, $price ) ), home_url( '/' ) );
	}

	private static function counts() {
		return ! SNSCS_Referrals::is_bot_request() && ! current_user_can( 'manage_options' );
	}

	private static function add_stat( $id, $column, $by = 1 ) {
		global $wpdb;
		$column = in_array( $column, array( 'impressions', 'clicks', 'spent' ), true ) ? $column : 'impressions';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::stats_table() . " (promo_id, day, {$column}) VALUES (%d, %s, %d) ON DUPLICATE KEY UPDATE {$column} = {$column} + VALUES({$column})", $id, current_time( 'Y-m-d' ), $by ) );
	}

	/**
	 * 検索結果の上の PR 枠（エスケープ済みのHTML）。
	 */
	public static function render( array $args ) {
		$winners = self::auction( $args );
		if ( ! $winners ) {
			return '';
		}
		$html = '<div class="snscs-pr" aria-label="PR（広告）"><ul class="snscs-cards">';
		foreach ( $winners as $w ) {
			$id   = (int) $w['promo']['id'];
			$post = get_post( (int) $w['promo']['creator_id'] );
			if ( ! $post || isset( self::$shown[ $id ] ) ) {
				continue;
			}
			self::$shown[ $id ] = true;
			if ( self::counts() ) {
				self::add_stat( $id, 'impressions' );
			}
			$item  = SNSCS_Search::format_creator( $post, SNSCS_Accounts::get_for_creator( $post->ID ) );
			$url   = self::click_url( $id, $w['price'] );
			$html .= '<li class="snscs-card snscs-card--pr"><span class="snscs-card__pr">PR</span>';
			if ( $item['thumbnail'] ) {
				$html .= '<img class="snscs-card__image" src="' . esc_url( $item['thumbnail'] ) . '" alt="" loading="lazy" width="64" height="64">';
			}
			$html .= '<div class="snscs-card__body"><h3 class="snscs-card__name"><a href="' . esc_url( $url ) . '" rel="sponsored nofollow">' . esc_html( $item['name'] ) . '</a></h3>';
			if ( $item['genres'] ) {
				$html .= '<p class="snscs-card__tags">' . implode( '', array_map( function ( $g ) { return '<span class="snscs-tag">' . esc_html( $g ) . '</span>'; }, $item['genres'] ) ) . '</p>';
			}
			$html .= '<p class="snscs-card__excerpt">' . esc_html( wp_trim_words( $item['excerpt'], 40, '…' ) ) . '</p>';
			$html .= '<p class="snscs-card__prmeta">' . esc_html( implode( ' ・ ', array_map( function ( $a ) { return $a['platform_label'] . ' ' . $a['followers_label']; }, array_slice( $item['accounts'], 0, 3 ) ) ) ) . '</p></div></li>';
		}
		return $html . '</ul></div>';
	}

	/**
	 * PR 枠が押されたとき: 数えて（同じ人の同じ日は1回分だけ値段をかけて）、発信者のページへ。
	 */
	public static function handle_click() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 合言葉はURLのトークン。
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		$id    = absint( $_GET[ self::QUERY_VAR ] );
		$price = isset( $_GET['p'] ) ? absint( $_GET['p'] ) : 0;
		$tok   = isset( $_GET['t'] ) ? sanitize_key( wp_unslash( $_GET['t'] ) ) : '';
		// phpcs:enable
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$promo = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		$url   = $promo ? get_permalink( (int) $promo['creator_id'] ) : '';
		if ( ! $promo || ! $url ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$valid = hash_equals( self::token( $id, $price ), $tok ) || hash_equals( self::token( $id, $price, gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 day' ) ) ), $tok );
		if ( $valid && self::counts() && $price <= (int) $promo['bid'] ) {
			$key = 'snscs_prc_' . md5( $id . '|' . SNSCS_Referrals::visitor_hash() );
			if ( ! get_transient( $key ) ) {
				set_transient( $key, 1, DAY_IN_SECONDS );
				self::add_stat( $id, 'clicks' );
				self::add_stat( $id, 'spent', max( 0, $price ) );
			}
		}
		wp_safe_redirect( $url );
		exit;
	}

	/* --------------------------------------------------------------------
	 * 発信者のマイページ
	 * ------------------------------------------------------------------ */

	public static function render_portal( $creator_id ) {
		$s       = self::settings();
		if ( ! $s['on'] ) {
			return;
		}
		$p       = self::for_creator( $creator_id );
		$st      = $p ? self::stats( array( (int) $p['id'] ) )[ (int) $p['id'] ] : null;
		$genres  = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => false, 'number' => 200 ) );
		$chosen  = $p ? explode( ',', $p['genres'] ) : wp_get_object_terms( $creator_id, SNSCS_Post_Types::TAX_GENRE, array( 'fields' => 'slugs' ) );
		?>
		<section class="snscs-panel snscs-promo" id="snscs-promo">
			<h2 class="snscs-panel__title">PR枠（検索結果のいちばん上に出す）</h2>
			<p>決めたキーワードやジャンルで探した人の検索結果の上に、「PR」の枠であなたを出せます。<strong>押されたときだけ</strong>料金がかかります（表示だけなら無料）。料金は毎月、運営からご請求します。</p>
			<?php if ( $p ) : ?>
				<ul class="snscs-biolink__stats">
					<li><strong><?php echo esc_html( self::STATUSES[ $p['status'] ] ?? $p['status'] ); ?></strong>いまの状態</li>
					<li><strong><?php echo esc_html( number_format_i18n( $st['impressions'] ) ); ?></strong>表示（30日）</li>
					<li><strong><?php echo esc_html( number_format_i18n( $st['clicks'] ) ); ?></strong>クリック（30日）</li>
					<li><strong><?php echo esc_html( '¥' . number_format_i18n( $st['month_spent'] ) ); ?></strong>今月の料金</li>
				</ul>
			<?php endif; ?>
			<form class="snscs-fields" method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
				<input type="hidden" name="action" value="snscs_promo_apply">
				<?php wp_nonce_field( 'snscs_promo_apply', 'snscs_nonce' ); ?>
				<label class="snscs-field"><span>キーワード（カンマ区切り・10個まで）</span><input type="text" name="keywords" maxlength="300" value="<?php echo esc_attr( $p ? str_replace( ',', ', ', $p['keywords'] ) : '' ); ?>" placeholder="キャンプ飯, ソロキャンプ, アウトドア料理"></label>
				<fieldset class="snscs-field"><legend>ジャンル（このジャンルで探した人にも出す）</legend>
					<div class="snscs-bioed__chips">
						<?php foreach ( is_wp_error( $genres ) ? array() : $genres as $g ) : ?>
							<label class="snscs-bioed__chip"><input type="checkbox" name="genres[]" value="<?php echo esc_attr( $g->slug ); ?>" <?php checked( in_array( $g->slug, (array) $chosen, true ) ); ?>><span><?php echo esc_html( $g->name ); ?></span></label>
						<?php endforeach; ?>
					</div>
				</fieldset>
				<label class="snscs-field"><span>1クリックの上限（円・<?php echo (int) $s['reserve']; ?>円から）</span><input type="number" name="bid" min="<?php echo (int) $s['reserve']; ?>" max="10000" value="<?php echo esc_attr( $p ? (string) $p['bid'] : (string) max( 30, (int) $s['reserve'] ) ); ?>"></label>
				<label class="snscs-field"><span>1日の予算（円）</span><input type="number" name="daily_budget" min="<?php echo (int) $s['reserve']; ?>" value="<?php echo esc_attr( $p ? (string) $p['daily_budget'] : '1000' ); ?>"></label>
				<label class="snscs-field"><span>全体の予算（円・空欄なら上限なし）</span><input type="number" name="total_budget" min="0" value="<?php echo esc_attr( $p && $p['total_budget'] ? (string) $p['total_budget'] : '' ); ?>"></label>
				<label class="snscs-field"><span>期間</span><span><input type="date" name="start_date" value="<?php echo esc_attr( $p['start_date'] ?? '' ); ?>"> 〜 <input type="date" name="end_date" value="<?php echo esc_attr( $p['end_date'] ?? '' ); ?>"></span></label>
				<p class="snscs-help">実際の料金は、競う相手がいなければ<?php echo (int) $s['reserve']; ?>円、いれば「次の人に勝つのに必要なだけ」で、上限を超えません。押されやすい人（写真や紹介文が分かりやすい人）ほど、安い料金で上に出ます。キーワードを変えると、運営がもう一度確かめます。</p>
				<div><button type="submit" class="snscs-button snscs-button--primary"><?php echo $p ? '変更を保存' : 'PR枠に申し込む'; ?></button></div>
			</form>
		</section>
		<?php
	}

	/**
	 * 企業の方へのページ: PR枠の案内（発信者向け）。
	 */
	public static function plan_html() {
		if ( ! self::settings()['on'] ) {
			return '';
		}
		return '<section class="snscs-bizplan"><h2>発信者の方へ: PR枠</h2><p>検索結果のいちばん上に「PR」の枠で出せます。キーワードとジャンル、1クリックの上限と1日の予算を決めて、<a href="' . esc_url( snscs_portal_url() ) . '#snscs-promo">マイページ</a>から申し込めます。押されたときだけ料金がかかります。</p></section>';
	}

	/* --------------------------------------------------------------------
	 * 運営の画面
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, 'PR枠（検索連動型）', 'PR枠（検索連動型）', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	public static function handle_admin() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_promo_admin' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$do = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		if ( 'settings' === $do ) {
			update_option(
				self::OPTION,
				array(
					'on'      => empty( $_POST['on'] ) ? 0 : 1,
					'slots'   => min( 3, max( 1, isset( $_POST['slots'] ) ? absint( $_POST['slots'] ) : 1 ) ),
					'reserve' => min( 1000, max( 1, isset( $_POST['reserve'] ) ? absint( $_POST['reserve'] ) : 10 ) ),
				),
				false
			);
		} elseif ( isset( self::STATUSES[ $do ] ) ) {
			$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
			$wpdb->update( self::table(), array( 'status' => $do, 'note' => isset( $_POST['note'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['note'] ) ), 0, 200 ) : '', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::ADMIN_PAGE . '&done=1' ) );
		exit;
	}

	public static function render_admin() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = self::settings();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$promos = $wpdb->get_results( 'SELECT * FROM ' . self::table() . " ORDER BY FIELD(status, 'pending', 'active', 'paused', 'ended', 'rejected'), id DESC LIMIT 300", ARRAY_A );
		$stats  = self::stats( wp_list_pluck( $promos, 'id' ) );
		?>
		<div class="wrap">
			<h1>PR枠（検索連動型）</h1>
			<p>発信者が申し込んだPR枠です。キーワードを確かめて「掲載する」を押すと、合う検索の結果の上に「PR」の枠で出ます（入札 × 押されやすさ で順番を決め、料金は次の人に勝つのに必要なだけ）。料金は押されたときだけかかります。<strong>「今月の料金」をもとに、毎月ご請求ください。</strong></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 12px 0">
				<input type="hidden" name="action" value="snscs_promo_admin"><input type="hidden" name="do" value="settings">
				<?php wp_nonce_field( 'snscs_promo_admin', 'snscs_nonce' ); ?>
				<label><input type="checkbox" name="on" value="1" <?php checked( $s['on'] ); ?>> PR枠を使う</label>
				　1ページに <input type="number" name="slots" min="1" max="3" value="<?php echo (int) $s['slots']; ?>" class="small-text"> 枠
				　最低の料金 <input type="number" name="reserve" min="1" max="1000" value="<?php echo (int) $s['reserve']; ?>" class="small-text"> 円
				<button class="button">保存</button>
			</form>
			<table class="widefat striped">
				<thead><tr><th>発信者</th><th>キーワード・ジャンル</th><th style="text-align: right">入札</th><th style="text-align: right">1日の予算</th><th>期間</th><th style="text-align: right">表示・クリック（30日）</th><th style="text-align: right">今月の料金</th><th>状態</th></tr></thead>
				<tbody>
					<?php if ( ! $promos ) : ?>
						<tr><td colspan="8">まだ申し込みはありません。</td></tr>
					<?php endif; ?>
					<?php foreach ( $promos as $p ) : ?>
						<?php $st = $stats[ (int) $p['id'] ]; ?>
						<tr>
							<td><a href="<?php echo esc_url( get_permalink( (int) $p['creator_id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( (int) $p['creator_id'] ) ); ?></a></td>
							<td><?php echo esc_html( str_replace( ',', '、', $p['keywords'] ) ); ?><?php echo '' !== $p['genres'] ? '<br><span class="description">ジャンル: ' . esc_html( str_replace( ',', '、', $p['genres'] ) ) . '</span>' : ''; ?></td>
							<td style="text-align: right">¥<?php echo esc_html( number_format_i18n( (int) $p['bid'] ) ); ?></td>
							<td style="text-align: right">¥<?php echo esc_html( number_format_i18n( (int) $p['daily_budget'] ) ); ?></td>
							<td><?php echo esc_html( ( $p['start_date'] ?: '—' ) . ' 〜 ' . ( $p['end_date'] ?: '—' ) ); ?></td>
							<td style="text-align: right"><?php echo esc_html( number_format_i18n( $st['impressions'] ) . ' / ' . number_format_i18n( $st['clicks'] ) ); ?></td>
							<td style="text-align: right">¥<?php echo esc_html( number_format_i18n( $st['month_spent'] ) ); ?></td>
							<td>
								<strong><?php echo esc_html( self::STATUSES[ $p['status'] ] ?? $p['status'] ); ?></strong>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="snscs_promo_admin"><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
									<?php wp_nonce_field( 'snscs_promo_admin', 'snscs_nonce' ); ?>
									<?php if ( 'active' !== $p['status'] ) : ?><button class="button button-small button-primary" name="do" value="active">掲載する</button><?php endif; ?>
									<?php if ( 'active' === $p['status'] ) : ?><button class="button button-small" name="do" value="paused">一時停止</button><?php endif; ?>
									<?php if ( 'pending' === $p['status'] ) : ?><button class="button button-small" name="do" value="rejected">見送る</button><?php endif; ?>
									<?php if ( in_array( $p['status'], array( 'active', 'paused' ), true ) ) : ?><button class="button button-small" name="do" value="ended">終了</button><?php endif; ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function delete_for_creator( $post_id ) {
		global $wpdb;
		if ( SNSCS_Post_Types::POST_TYPE === get_post_type( $post_id ) ) {
			$wpdb->update( self::table(), array( 'status' => 'ended' ), array( 'creator_id' => (int) $post_id ) );
		}
	}
}
