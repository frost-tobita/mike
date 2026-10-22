<?php
/**
 * アルゴリズムハック（検索順位を不正に上げる行為）への対策。
 *
 * 見つけたら、その発信者の点数に「減点の倍率」（0.1〜1）を掛けて順位を下げる。
 *   総合点       : 倍率を掛ける（おすすめ順・ランキングなど全部に効く）
 *   キーワード検索: 言葉との一致（関連度）の部分にも倍率を掛ける（SNSCS_Ranker）
 *
 * 見つけるもの（毎回の点数計算の前に判定）:
 *   名前の詰め込み   : 名前にジャンル・地域などの言葉を3つ以上並べる、名前が長すぎる       → ×0.5
 *   紹介文の詰め込み : 同じ言葉のくり返し、ハッシュタグの羅列                              → ×0.5
 *   紹介文のコピー   : ほかの発信者とまったく同じ紹介文（最初に掲載された人は対象外）     → ×0.6
 *   クリックの水増し : 同じ人が同じ発信者を何度も押す（2回目からは数えず、多ければ減点）  → ×0.5
 *   釣り             : 検索で押されるのに、ほとんど全員がすぐ戻ってくる                    → ×0.7
 *   招待の水増し     : 招待して掲載された人の大半が、まったく見られていない（捨てアカウント） → ×0.7
 * 理由が複数あれば倍率を掛け合わせる（下限 0.1）。
 * 運営は発信者ごとに「問題なし（対象外）」「手動で下げる」を選べる。発信者のマイページには、直し方つきでお知らせする。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Integrity {

	const OPTION     = 'snscs_integrity_enabled';
	const ADMIN_PAGE = 'snscs-integrity';
	const FLOOR      = 0.1;
	const MANUAL     = 0.2;

	/** 判定の名前 => [倍率, 運営向けの説明, 発信者向けの直し方（空なら直し方を出さない）] */
	public static function rules() {
		return array(
			'name_stuffing'     => array( 0.5, '名前に検索用の言葉を詰め込んでいる', '名前（表示名）には、ふだん名乗っている名前だけを入れてください。ジャンルや地域は専用の欄で選べます。' ),
			'text_stuffing'     => array( 0.5, '紹介文に同じ言葉やハッシュタグを詰め込んでいる', '紹介文で同じ言葉をくり返したり、ハッシュタグを並べたりせず、あなたの発信内容を文章で紹介してください。' ),
			'duplicate_profile' => array( 0.6, 'ほかの発信者と紹介文がまったく同じ', '紹介文を、あなた自身の言葉で書き直してください。' ),
			'click_farm'        => array( 0.5, '同じ人からのくり返しクリックが多い', '' ),
			'clickbait'         => array( 0.7, '検索で押されても、ほとんどの人がすぐ戻ってしまう', '名前・紹介文が、実際の発信内容と合っているか見直してください。' ),
			'invite_farm'       => array( 0.7, '招待した発信者の大半が、まったく見られていない', '' ),
		);
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_snscs_integrity', array( __CLASS__, 'handle_admin' ) );
		add_action( 'snscs_nightly_engine', array( __CLASS__, 'purge' ), 40 );
		add_action( 'add_meta_boxes_' . SNSCS_Post_Types::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . SNSCS_Post_Types::POST_TYPE, array( __CLASS__, 'save_meta' ) );
	}

	public static function add_meta_box() {
		add_meta_box( 'snscs-integrity', '表示順位（不正対策）', array( __CLASS__, 'render_meta_box' ), SNSCS_Post_Types::POST_TYPE, 'side' );
	}

	public static function render_meta_box( $post ) {
		$status = self::status( $post->ID );
		$rules  = self::rules();
		wp_nonce_field( 'snscs_integrity_meta', 'snscs_integrity_nonce' );
		?>
		<p>いまの倍率: <strong>×<?php echo esc_html( round( $status['penalty'], 2 ) ); ?></strong></p>
		<?php foreach ( $status['reasons'] as $key ) : ?>
			<p class="description">・<?php echo esc_html( isset( $rules[ $key ] ) ? $rules[ $key ][1] : $key ); ?></p>
		<?php endforeach; ?>
		<select name="snscs_integrity_manual" style="width: 100%">
			<option value="" <?php selected( $status['manual'], '' ); ?>>自動の判定にまかせる</option>
			<option value="exempt" <?php selected( $status['manual'], 'exempt' ); ?>>問題なし（対象外にする）</option>
			<option value="demote" <?php selected( $status['manual'], 'demote' ); ?>>手動で順位を下げる</option>
		</select>
		<?php
	}

	public static function save_meta( $post_id ) {
		if ( ! isset( $_POST['snscs_integrity_nonce'], $_POST['snscs_integrity_manual'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_integrity_nonce'] ) ), 'snscs_integrity_meta' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$mode = sanitize_key( wp_unslash( $_POST['snscs_integrity_manual'] ) );
		$mode = in_array( $mode, array( 'exempt', 'demote' ), true ) ? $mode : '';
		if ( self::status( $post_id )['manual'] === $mode ) {
			return;
		}
		self::set_manual( $post_id, $mode );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_integrity';
	}

	public static function seen_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_click_seen';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		$seen    = self::seen_table();
		dbDelta(
			"CREATE TABLE {$table} (
  creator_id bigint(20) unsigned NOT NULL,
  auto_penalty double NOT NULL DEFAULT 1,
  penalty double NOT NULL DEFAULT 1,
  reasons varchar(255) NOT NULL DEFAULT '',
  manual varchar(10) NOT NULL DEFAULT '',
  dup_clicks int(10) unsigned NOT NULL DEFAULT 0,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (creator_id),
  KEY penalty (penalty)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$seen} (
  day date NOT NULL,
  visitor char(16) NOT NULL,
  creator_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (day,visitor,creator_id)
) {$charset};"
		);
	}

	/**
	 * 運営の判断を保存し、倍率をすぐ反映する（総合点は数分以内の計算し直しで反映）。
	 */
	private static function set_manual( $creator_id, $mode ) {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (creator_id, manual) VALUES (%d, %s) ON DUPLICATE KEY UPDATE manual = VALUES(manual)", $creator_id, $mode ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET penalty = IF(manual = 'exempt', 1, IF(manual = 'demote', LEAST(auto_penalty, %f), auto_penalty)) WHERE creator_id = %d", self::MANUAL, $creator_id ) );
		// phpcs:enable
		wp_cache_delete( 'snscs_penalties', 'snscs' );
		SNSCS_Scores::schedule_soon();
	}

	public static function enabled() {
		return (bool) get_option( self::OPTION, 1 );
	}

	/* --------------------------------------------------------------------
	 * クリックの重複を数えない
	 * ------------------------------------------------------------------ */

	/**
	 * 検索結果のクリックが、この人のこの発信者への今日はじめてのクリックか。
	 * 2回目以降は学習に使わず、発信者ごとに「くり返しクリック」として数える。
	 */
	public static function first_click( $creator_id ) {
		global $wpdb;
		$day     = current_time( 'Y-m-d' );
		$visitor = substr( SNSCS_Referrals::visitor_hash(), 0, 16 );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$new = (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::seen_table() . ' (day, visitor, creator_id) VALUES (%s, %s, %d)', $day, $visitor, $creator_id ) );
		if ( $new ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::table() . ' (creator_id, dup_clicks) VALUES (%d, 1) ON DUPLICATE KEY UPDATE dup_clicks = dup_clicks + 1', $creator_id ) );
		return false;
	}

	/**
	 * 毎晩: 古い「押した記録」を消す。くり返しクリックの数は少しずつ減らす（7日でおよそ半分）。
	 */
	public static function purge() {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::seen_table() . ' WHERE day < %s', gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 day' ) ) ) );
		$wpdb->query( 'UPDATE ' . self::table() . ' SET dup_clicks = FLOOR(dup_clicks * 0.9) WHERE dup_clicks > 0' );
		// phpcs:enable
	}

	/* --------------------------------------------------------------------
	 * 判定
	 * ------------------------------------------------------------------ */

	/**
	 * 全員を判定して保存し、発信者ID => 倍率（1未満の人だけ）を返す。点数計算の最初に呼ぶ。
	 *
	 * @param int[] $creators 掲載中の発信者。
	 * @return array<int,float>
	 */
	public static function detect( array $creators ) {
		global $wpdb;
		$table   = self::table();
		$reasons = array_fill_keys( $creators, array() );
		if ( self::enabled() && $creators ) {
			self::detect_content( $reasons );
			self::detect_behavior( $reasons );
		}
		$rules = self::rules();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$manual = wp_list_pluck( $wpdb->get_results( "SELECT creator_id, manual FROM {$table} WHERE manual <> ''", ARRAY_A ), 'manual', 'creator_id' );
		$now    = current_time( 'mysql' );
		$rows   = array();
		$out    = array();
		foreach ( $reasons as $id => $list ) {
			$auto = 1.0;
			foreach ( $list as $key ) {
				$auto *= $rules[ $key ][0];
			}
			$auto = max( self::FLOOR, $auto );
			$mode = isset( $manual[ $id ] ) ? $manual[ $id ] : '';
			$pen  = 'exempt' === $mode ? 1.0 : ( 'demote' === $mode ? min( $auto, self::MANUAL ) : $auto );
			if ( $pen < 1 ) {
				$out[ $id ] = $pen;
			}
			if ( $list || $mode ) {
				$rows[] = $wpdb->prepare( '(%d, %f, %f, %s, %s)', $id, $auto, $pen, implode( ',', $list ), $now );
			}
		}
		// 判定が外れた人は元に戻す（運営の指定とくり返しクリックの数は残す）。
		$wpdb->query( "UPDATE {$table} SET auto_penalty = 1, penalty = IF(manual = 'demote', " . self::MANUAL . ", 1), reasons = '' WHERE reasons <> '' OR penalty < 1" );
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$table} (creator_id, auto_penalty, penalty, reasons, updated_at) VALUES " . implode( ',', $chunk ) . ' ON DUPLICATE KEY UPDATE auto_penalty = VALUES(auto_penalty), penalty = VALUES(penalty), reasons = VALUES(reasons), updated_at = VALUES(updated_at)' );
		}
		// phpcs:enable
		wp_cache_delete( 'snscs_penalties', 'snscs' );
		update_option( 'snscs_integrity_info', array( 'count' => count( $out ), 'at' => $now ), false );
		return $out;
	}

	/**
	 * 名前・紹介文の詰め込みと、紹介文のコピー。
	 */
	private static function detect_content( array &$reasons ) {
		global $wpdb;
		$keywords = self::keywords();
		$first    = array();
		foreach ( array_chunk( array_keys( $reasons ), 2000 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$posts = $wpdb->get_results( "SELECT ID, post_title, post_content, post_date FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', $chunk ) . ') ORDER BY post_date, ID', ARRAY_A );
			foreach ( $posts as $post ) {
				$id = (int) $post['ID'];
				if ( self::name_stuffed( $post['post_title'], $keywords ) ) {
					$reasons[ $id ][] = 'name_stuffing';
				}
				$bio = wp_strip_all_tags( $post['post_content'] );
				if ( self::text_stuffed( $bio ) ) {
					$reasons[ $id ][] = 'text_stuffing';
				}
				$norm = preg_replace( '/[\s\p{P}\p{S}]+/u', '', SNSCS_Text::normalize( $bio ) );
				if ( mb_strlen( $norm ) >= 60 ) {
					$hash = md5( $norm );
					$first[ $hash ][] = array( $id, $post['post_date'] );
				}
			}
		}
		foreach ( $first as $list ) {
			if ( count( $list ) < 2 ) {
				continue;
			}
			usort(
				$list,
				function ( $a, $b ) {
					return strcmp( $a[1], $b[1] ) ?: $a[0] - $b[0]; // phpcs:ignore WordPress.PHP.DisallowShortTernary.Found
				}
			);
			// 最初に掲載された人は、コピーされた側なので対象外。
			foreach ( array_slice( $list, 1 ) as $item ) {
				$reasons[ $item[0] ][] = 'duplicate_profile';
			}
		}
	}

	/**
	 * 名前の詰め込みかどうかを調べるための言葉（ジャンル・地域・言い換え辞書）。
	 *
	 * @return string[]
	 */
	public static function keywords() {
		$words = array();
		foreach ( array( SNSCS_Post_Types::TAX_GENRE, SNSCS_Post_Types::TAX_REGION ) as $taxonomy ) {
			$names = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'names', 'number' => 500 ) );
			$words = array_merge( $words, is_wp_error( $names ) ? array() : $names );
		}
		foreach ( SNSCS_Text::synonyms() as $group ) {
			$words = array_merge( $words, $group );
		}
		$words = array_map( array( 'SNSCS_Text', 'normalize' ), $words );
		return array_values(
			array_unique(
				array_filter(
					$words,
					function ( $w ) {
						return mb_strlen( $w ) >= 2;
					}
				)
			)
		);
	}

	public static function name_stuffed( $name, array $keywords ) {
		$norm = SNSCS_Text::normalize( $name );
		if ( mb_strlen( $norm ) > 40 ) {
			return true;
		}
		// 【料理】【レシピ】【時短】… や「料理｜レシピ｜時短｜…」のような区切りの羅列。
		if ( preg_match_all( '/[【\[［(（]/u', $norm ) >= 3 || count( preg_split( '/[|｜\/／・,、]/u', $norm ) ) >= 4 ) {
			return true;
		}
		$found = array();
		foreach ( $keywords as $word ) {
			if ( false !== mb_strpos( $norm, $word ) ) {
				// 「ゲーム」と「ゲーム実況」のように重なる言葉は1つと数える。
				foreach ( $found as $i => $other ) {
					if ( false !== mb_strpos( $other, $word ) ) {
						continue 2;
					}
					if ( false !== mb_strpos( $word, $other ) ) {
						unset( $found[ $i ] );
					}
				}
				$found[] = $word;
			}
		}
		return count( $found ) >= 3;
	}

	public static function text_stuffed( $text ) {
		$norm = SNSCS_Text::normalize( $text );
		// ハッシュタグの羅列。
		if ( preg_match_all( '/[#＃][^\s#＃]+/u', $norm ) >= 15 ) {
			return true;
		}
		// 区切られた同じ言葉のくり返し（6回以上、かつ全体の1割以上）。
		$words = preg_split( '/[\s、。,.・\/|｜#＃!！?？]+/u', $norm, -1, PREG_SPLIT_NO_EMPTY );
		$words = array_filter(
			$words,
			function ( $w ) {
				return mb_strlen( $w ) >= 2;
			}
		);
		if ( count( $words ) >= 10 ) {
			$counts = array_count_values( $words );
			$top    = max( $counts );
			if ( $top >= 6 && $top / count( $words ) >= 0.1 ) {
				return true;
			}
		}
		// 区切りの無いくり返し（例: 料理料理料理…）。2文字の組の偏りで見る。
		$tokens = SNSCS_Text::tokens( $norm );
		$total  = array_sum( $tokens );
		return $total >= 60 && max( $tokens ) / $total >= 0.12;
	}

	/**
	 * クリックの水増し・釣り・招待の水増し。
	 */
	private static function detect_behavior( array &$reasons ) {
		global $wpdb;
		$table    = self::table();
		$feedback = SNSCS_Feedback::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT i.creator_id, i.dup_clicks, COALESCE(f.clicks, 0) AS clicks FROM {$table} i LEFT JOIN {$feedback} f ON f.creator_id = i.creator_id WHERE i.dup_clicks >= 15", ARRAY_A );
		foreach ( $rows as $row ) {
			if ( isset( $reasons[ (int) $row['creator_id'] ] ) && (int) $row['dup_clicks'] >= (int) $row['clicks'] ) {
				$reasons[ (int) $row['creator_id'] ][] = 'click_farm';
			}
		}
		$rows = $wpdb->get_results( "SELECT creator_id FROM {$feedback} WHERE clicks >= 30 AND good < clicks * 0.05 AND bounce >= clicks * 0.6", ARRAY_A );
		foreach ( $rows as $row ) {
			if ( isset( $reasons[ (int) $row['creator_id'] ] ) ) {
				$reasons[ (int) $row['creator_id'] ][] = 'clickbait';
			}
		}
		// 招待した人のうち、30日間だれにも見られていない人の割合。
		$daily  = SNSCS_Tracking::daily_table();
		$viewed = array_flip( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT creator_id FROM {$daily} WHERE day >= %s AND views > 0", gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -30 days' ) ) ) ) ) );
		// phpcs:enable
		$children = array();
		foreach ( SNSCS_Invites::parents() as $child => $parent ) {
			$children[ $parent ][] = $child;
		}
		foreach ( $children as $parent => $list ) {
			if ( count( $list ) < 5 || ! isset( $reasons[ $parent ] ) ) {
				continue;
			}
			$idle = count( array_diff_key( array_flip( $list ), $viewed ) );
			if ( $idle / count( $list ) >= 0.8 ) {
				$reasons[ $parent ][] = 'invite_farm';
			}
		}
	}

	/* --------------------------------------------------------------------
	 * 使う側
	 * ------------------------------------------------------------------ */

	/**
	 * 倍率が1未満の発信者（発信者ID => 倍率）。
	 *
	 * @return array<int,float>
	 */
	public static function penalties() {
		$cached = wp_cache_get( 'snscs_penalties', 'snscs' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( 'SELECT creator_id, penalty FROM ' . self::table() . ' WHERE penalty < 1', ARRAY_A );
		$out  = array_map( 'floatval', wp_list_pluck( $rows, 'penalty', 'creator_id' ) );
		wp_cache_set( 'snscs_penalties', $out, 'snscs', 300 );
		return $out;
	}

	/**
	 * 1人分の状態（マイページ・管理画面用）。
	 */
	public static function status( $creator_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE creator_id = %d', $creator_id ), ARRAY_A );
		if ( ! $row ) {
			return array( 'penalty' => 1.0, 'reasons' => array(), 'manual' => '' );
		}
		return array(
			'penalty' => (float) $row['penalty'],
			'reasons' => array_filter( explode( ',', (string) $row['reasons'] ) ),
			'manual'  => (string) $row['manual'],
		);
	}

	/**
	 * 発信者のマイページに出すお知らせ（順位を下げているときだけ）。
	 */
	public static function render_notice( $creator_id ) {
		$status = self::status( $creator_id );
		if ( $status['penalty'] >= 1 ) {
			return;
		}
		$rules = self::rules();
		$fixes = array();
		foreach ( $status['reasons'] as $key ) {
			if ( isset( $rules[ $key ] ) && '' !== $rules[ $key ][2] ) {
				$fixes[] = $rules[ $key ][2];
			}
		}
		?>
		<div class="snscs-notice snscs-notice--error">
			<p><strong>現在、検索結果での表示順位を下げています。</strong></p>
			<?php if ( $fixes ) : ?>
				<ul>
					<?php foreach ( $fixes as $fix ) : ?>
						<li><?php echo esc_html( $fix ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p>内容を直して申請し、承認されると、次の点数計算（1日以内）で元に戻ります。</p>
			<?php else : ?>
				<p>不自然なアクセスや登録が見つかったためです。心当たりがない場合は、運営までお問い合わせください。</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, '不正対策', '不正対策', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	private static function admin_url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::ADMIN_PAGE ) );
	}

	public static function handle_admin() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_integrity' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 上で確認済み。
		$do  = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		$id  = isset( $_POST['creator_id'] ) ? absint( $_POST['creator_id'] ) : 0;
		$msg = '';
		if ( 'settings' === $do ) {
			update_option( self::OPTION, empty( $_POST['enabled'] ) ? 0 : 1, false );
			$msg = 'saved';
		} elseif ( $id && in_array( $do, array( 'exempt', 'demote', 'auto' ), true ) && SNSCS_Post_Types::POST_TYPE === get_post_type( $id ) ) {
			self::set_manual( $id, 'auto' === $do ? '' : $do );
			$msg = 'manual';
		}
		// phpcs:enable
		if ( $msg ) {
			SNSCS_Scores::schedule_soon();
		}
		wp_safe_redirect( self::admin_url( array( 'snscs_msg' => $msg ) ) );
		exit;
	}

	private static function button( $id, $do, $label, $primary = false ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline">
			<?php wp_nonce_field( 'snscs_integrity', 'snscs_nonce' ); ?>
			<input type="hidden" name="action" value="snscs_integrity">
			<input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>">
			<input type="hidden" name="creator_id" value="<?php echo (int) $id; ?>">
			<button type="submit" class="button<?php echo $primary ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$rules = self::rules();
		$info  = get_option( 'snscs_integrity_info', array() );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( 'SELECT i.*, p.post_title FROM ' . self::table() . " i INNER JOIN {$wpdb->posts} p ON p.ID = i.creator_id WHERE i.reasons <> '' OR i.manual <> '' OR i.dup_clicks > 0 ORDER BY i.penalty ASC, i.dup_clicks DESC LIMIT 300", ARRAY_A );
		$msg  = isset( $_GET['snscs_msg'] ) ? sanitize_key( $_GET['snscs_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1>不正対策（アルゴリズムハック）</h1>
			<?php if ( $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo 'saved' === $msg ? '保存しました。' : '変更しました。数分以内に点数を計算し直します。'; ?></p></div>
			<?php endif; ?>
			<p>検索順位を不正に上げようとする行為を自動で見つけ、その発信者の点数に倍率を掛けて順位を下げます。判定は点数を計算し直すたび（毎日）に行い、直れば自動で元に戻ります。発信者のマイページには、直し方つきでお知らせが出ます。</p>

			<h2>見つけるもの</h2>
			<table class="widefat striped" style="max-width: 900px">
				<thead><tr><th>判定</th><th style="text-align: right">倍率</th></tr></thead>
				<tbody>
					<?php foreach ( $rules as $rule ) : ?>
						<tr><td><?php echo esc_html( $rule[1] ); ?></td><td style="text-align: right">×<?php echo esc_html( $rule[0] ); ?></td></tr>
					<?php endforeach; ?>
					<tr><td>運営が「手動で下げる」を選んだ</td><td style="text-align: right">×<?php echo esc_html( self::MANUAL ); ?></td></tr>
				</tbody>
			</table>
			<p class="description">理由が複数あるときは掛け合わせます（下限 ×<?php echo esc_html( self::FLOOR ); ?>）。また、同じ人が同じ発信者を同じ日に何度押しても、検索の学習には1回として数えます。</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'snscs_integrity', 'snscs_nonce' ); ?>
				<input type="hidden" name="action" value="snscs_integrity">
				<input type="hidden" name="do" value="settings">
				<p><label><input type="checkbox" name="enabled" value="1" <?php checked( self::enabled() ); ?>> 自動の判定を使う</label> <button type="submit" class="button">保存</button></p>
			</form>

			<h2>対象の発信者<?php echo ! empty( $info['at'] ) ? esc_html( sprintf( '（%s の判定で %s人）', $info['at'], number_format_i18n( $info['count'] ) ) ) : ''; ?></h2>
			<table class="widefat striped">
				<thead><tr><th>発信者</th><th>理由</th><th style="text-align: right">くり返しクリック</th><th style="text-align: right">倍率</th><th>運営の判断</th></tr></thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="5">いまは対象の発信者はいません。</td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $row['creator_id'] ) ); ?>"><?php echo esc_html( $row['post_title'] ); ?></a> <a href="<?php echo esc_url( get_permalink( $row['creator_id'] ) ); ?>" target="_blank">表示</a></td>
							<td>
								<?php
								$labels = array();
								foreach ( array_filter( explode( ',', $row['reasons'] ) ) as $key ) {
									$labels[] = isset( $rules[ $key ] ) ? $rules[ $key ][1] : $key;
								}
								echo esc_html( $labels ? implode( ' / ', $labels ) : '—' );
								?>
							</td>
							<td style="text-align: right"><?php echo esc_html( number_format_i18n( $row['dup_clicks'] ) ); ?></td>
							<td style="text-align: right"><strong>×<?php echo esc_html( round( (float) $row['penalty'], 2 ) ); ?></strong></td>
							<td>
								<?php if ( 'exempt' === $row['manual'] ) : ?>
									問題なし（対象外）
								<?php elseif ( 'demote' === $row['manual'] ) : ?>
									手動で下げている
								<?php endif; ?>
								<?php
								if ( 'exempt' !== $row['manual'] ) {
									self::button( $row['creator_id'], 'exempt', '問題なし' );
								}
								if ( 'demote' !== $row['manual'] ) {
									self::button( $row['creator_id'], 'demote', '手動で下げる' );
								}
								if ( '' !== $row['manual'] ) {
									self::button( $row['creator_id'], 'auto', '自動の判定に戻す' );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">ここに無い発信者を手動で下げるときは、発信者の編集画面の「表示順位（不正対策）」から選べます。</p>
		</div>
		<?php
	}
}
