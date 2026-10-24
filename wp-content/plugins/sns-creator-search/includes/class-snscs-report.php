<?php
/**
 * 発信者の「月間成長レポート」（シェアしたくなる画像で、発信者自身に広めてもらう）。
 *
 * 毎月1日（7時以降の最初の1時間ごとの処理）に、前の月の数字を発信者ごとにまとめて保存する（snscs_reports）。
 *   フォロワーの増減（推移の記録から）・ページを見た人・記事を読んだ人・検索結果に表示された回数・
 *   紹介リンクから来た人・お気に入り・このサイトでのフォロー・記事へのいいねとリポスト・公開した記事・ジャンル内の順位
 * いちばん見栄えのする数字を大きく出し（フォロワーの増加 → 閲覧 → 順位 …）、ほかを最大4つ並べた
 * 縦長の画像（1080×1350。Instagram・X・Threads 向け）を作る。数字が0のものは出さない。
 * シェア用のページ（/snscs-report/発信者ID-年月-署名/）は、画像が SNS のカードに出るよう OGP を付け、
 * 「この発信者のページを見る」（紹介リンク）と「あなたも無料で登録」（この発信者の招待リンク）を置く。
 * URLの署名がないと見られない（他人の数字を勝手に見られないように）。
 * 作ったら、数字のある発信者にお知らせ（サイト内・メール・スマホ通知）を送る。
 * 推移の記録が無い発信者のため、毎月1日に全員のフォロワー数を記録しておく（翌月から増減が出る）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Report {

	const QUERY_VAR = 'snscs_report';
	const HOUR      = 7;
	const VERSION   = 3;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_action( 'snscs_inbox_cron', array( __CLASS__, 'monthly' ), 5 );
		add_action( 'admin_post_snscs_report_build', array( __CLASS__, 'handle_build' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_reports';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  creator_id bigint(20) unsigned NOT NULL,
  month char(7) NOT NULL,
  data text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (creator_id,month),
  KEY month (month)
) {$charset};"
		);
	}

	public static function add_rewrite_rule() {
		add_rewrite_rule( '^snscs-report/([0-9]+-[0-9]{6}-[a-f0-9]{10})(\.png)?/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]$matches[2]', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/* --------------------------------------------------------------------
	 * URL
	 * ------------------------------------------------------------------ */

	private static function sign( $creator_id, $ym ) {
		return substr( hash_hmac( 'sha256', 'report|' . (int) $creator_id . '|' . $ym, wp_salt( 'auth' ) ), 0, 10 );
	}

	public static function key( $creator_id, $month ) {
		$ym = str_replace( '-', '', $month );
		return (int) $creator_id . '-' . $ym . '-' . self::sign( $creator_id, $ym );
	}

	public static function page_url( $creator_id, $month ) {
		return home_url( '/snscs-report/' . self::key( $creator_id, $month ) . '/' );
	}

	public static function image_url( $creator_id, $month ) {
		return home_url( '/snscs-report/' . self::key( $creator_id, $month ) . '.png' );
	}

	/**
	 * URL の鍵を確かめる。
	 *
	 * @return array{0:int,1:string}|null [発信者ID, 'YYYY-MM']
	 */
	public static function parse( $key ) {
		if ( ! preg_match( '/^(\d+)-(\d{4})(\d{2})-([a-f0-9]{10})$/', (string) $key, $m ) ) {
			return null;
		}
		return hash_equals( self::sign( (int) $m[1], $m[2] . $m[3] ), $m[4] ) ? array( (int) $m[1], $m[2] . '-' . $m[3] ) : null;
	}

	/* --------------------------------------------------------------------
	 * 数字を集める
	 * ------------------------------------------------------------------ */

	/**
	 * 全員（または指定した発信者）の、その月の数字をまとめて計算する。
	 *
	 * @param string $month 'YYYY-MM'。
	 * @param int[]  $only  空なら掲載中の全員。
	 * @return array<int,array> 発信者ID => 数字。
	 */
	public static function compute( $month, array $only = array() ) {
		global $wpdb;
		$from = $month . '-01';
		$to   = gmdate( 'Y-m-d', strtotime( $from . ' +1 month' ) ); // 翌月1日（含まない）
		$ids  = $only ? array_map( 'intval', $only ) : array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", SNSCS_Post_Types::POST_TYPE ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in  = implode( ',', $ids );
		$out = array_fill_keys( $ids, array() );
		$put = function ( $rows, $key ) use ( &$out ) {
			foreach ( $rows as $row ) {
				if ( isset( $out[ (int) $row['id'] ] ) ) {
					$out[ (int) $row['id'] ][ $key ] = (int) $row['n'];
				}
			}
		};
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$daily = SNSCS_Tracking::daily_table();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT creator_id AS id, SUM(views) AS v, SUM(article_views) AS a, SUM(favs) AS f FROM {$daily} WHERE creator_id IN ({$in}) AND day >= %s AND day < %s GROUP BY creator_id", $from, $to ), ARRAY_A ) as $row ) {
			$out[ (int) $row['id'] ]['views']         = (int) $row['v'];
			$out[ (int) $row['id'] ]['article_views'] = (int) $row['a'];
			$out[ (int) $row['id'] ]['favs']          = (int) $row['f'];
		}
		$put( $wpdb->get_results( $wpdb->prepare( 'SELECT creator_id AS id, COUNT(*) AS n FROM ' . SNSCS_Referrals::table() . " WHERE creator_id IN ({$in}) AND clicked_on >= %s AND clicked_on < %s GROUP BY creator_id", $from, $to ), ARRAY_A ), 'referrals' );
		$put( $wpdb->get_results( $wpdb->prepare( 'SELECT creator_id AS id, COUNT(*) AS n FROM ' . SNSCS_Feedback::events_table() . " WHERE creator_id IN ({$in}) AND day >= %s AND day < %s GROUP BY creator_id", $from, $to ), ARRAY_A ), 'search' );
		$put( $wpdb->get_results( $wpdb->prepare( 'SELECT creator_id AS id, COUNT(*) AS n FROM ' . SNSCS_Social::follows_table() . " WHERE creator_id IN ({$in}) AND created_at >= %s AND created_at < %s GROUP BY creator_id", $from, $to ), ARRAY_A ), 'site_follows' );
		$put(
			$wpdb->get_results(
				$wpdb->prepare(
					'SELECT pm.meta_value AS id, COUNT(*) AS n FROM ' . SNSCS_Social::reactions_table() . " r INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = r.article_id AND pm.meta_key = '_snscs_creator_id' AND pm.meta_value IN ({$in}) WHERE r.type IN (%d, %d) AND r.created_at >= %s AND r.created_at < %s GROUP BY pm.meta_value",
					SNSCS_Social::LIKE,
					SNSCS_Social::REPOST,
					$from,
					$to
				),
				ARRAY_A
			),
			'reactions'
		);
		$put( $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_value AS id, COUNT(*) AS n FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_snscs_creator_id' AND pm.meta_value IN ({$in}) WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_date >= %s AND p.post_date < %s GROUP BY pm.meta_value", SNSCS_Articles::POST_TYPE, $from, $to ), ARRAY_A ), 'articles' );

		// フォロワーの増減: SNSごとに、月の中で最初と最後の記録の差（翌月1日の記録も「月末」として使う）。
		$history = SNSCS_Sync::history_table();
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT creator_id, platform, day, followers FROM {$history} WHERE creator_id IN ({$in}) AND day >= %s AND day <= %s ORDER BY creator_id, platform, day", $from, $to ), ARRAY_A );
		// phpcs:enable
		$first = array();
		$last  = array();
		foreach ( $rows as $row ) {
			$k = $row['creator_id'] . '|' . $row['platform'];
			if ( ! isset( $first[ $k ] ) ) {
				$first[ $k ] = $row;
			}
			$last[ $k ] = $row;
		}
		foreach ( $first as $k => $start ) {
			if ( $last[ $k ]['day'] === $start['day'] ) {
				continue;
			}
			$id = (int) $start['creator_id'];
			$out[ $id ]['followers_gain'] = ( $out[ $id ]['followers_gain'] ?? 0 ) + (int) $last[ $k ]['followers'] - (int) $start['followers'];
			$out[ $id ]['followers_base'] = ( $out[ $id ]['followers_base'] ?? 0 ) + (int) $start['followers'];
		}

		// ジャンル内の順位（総合点で。月を作った時点のもの）。
		$scores = SNSCS_Scores::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ranked = $wpdb->get_results( $wpdb->prepare( "SELECT st.creator_id, st.score, MIN(tt.term_id) AS genre FROM {$scores} st INNER JOIN {$wpdb->posts} p ON p.ID = st.creator_id AND p.post_status = 'publish' AND p.post_type = %s INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = st.creator_id INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s WHERE st.scored_at IS NOT NULL GROUP BY st.creator_id, st.score ORDER BY st.score DESC", SNSCS_Post_Types::POST_TYPE, SNSCS_Post_Types::TAX_GENRE ), ARRAY_A );
		$count = array();
		foreach ( $ranked as $row ) {
			$genre           = (int) $row['genre'];
			$count[ $genre ] = ( $count[ $genre ] ?? 0 ) + 1;
			$id              = (int) $row['creator_id'];
			if ( isset( $out[ $id ] ) ) {
				$out[ $id ]['genre_rank']  = $count[ $genre ];
				$out[ $id ]['genre_id']    = $genre;
			}
		}
		foreach ( $out as $id => $data ) {
			if ( isset( $data['genre_id'] ) ) {
				$out[ $id ]['genre_total'] = $count[ $data['genre_id'] ];
			}
		}
		return $out;
	}

	/**
	 * 1人分の、前の月と比べた数字つきのレポート（保存されたもの）。
	 */
	public static function get( $creator_id, $month ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$json = $wpdb->get_var( $wpdb->prepare( 'SELECT data FROM ' . self::table() . ' WHERE creator_id = %d AND month = %s', $creator_id, $month ) );
		$data = $json ? json_decode( $json, true ) : null;
		return is_array( $data ) ? $data : null;
	}

	public static function latest_month( $creator_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT month FROM ' . self::table() . ' WHERE creator_id = %d ORDER BY month DESC LIMIT 1', $creator_id ) );
	}

	/**
	 * 画像とページに出す項目（大きく出す1つ ＋ ほか最大4つ）。0のものは出さない。
	 *
	 * @return array{headline:array|null,items:array}
	 */
	public static function highlights( array $d, $previous = null ) {
		$growth = function ( $key ) use ( $d, $previous ) {
			if ( ! is_array( $previous ) || empty( $previous[ $key ] ) || empty( $d[ $key ] ) ) {
				return '';
			}
			$pct = round( ( $d[ $key ] - $previous[ $key ] ) / $previous[ $key ] * 100 );
			return $pct > 0 ? '先月より+' . $pct . '%' : '';
		};
		$num   = function ( $n ) {
			return number_format_i18n( (int) $n );
		};
		$cands = array();
		if ( ! empty( $d['followers_gain'] ) && $d['followers_gain'] > 0 ) {
			$pct     = ! empty( $d['followers_base'] ) ? round( $d['followers_gain'] / $d['followers_base'] * 100, 1 ) : 0;
			$cands[] = array( 'label' => 'フォロワーが増えました', 'value' => '+' . $num( $d['followers_gain'] ), 'unit' => '人', 'note' => $pct >= 0.1 ? '先月末より+' . $pct . '%' : '' );
		}
		if ( ! empty( $d['views'] ) ) {
			$cands[] = array( 'label' => 'ページを見た人', 'value' => $num( $d['views'] ), 'unit' => '人', 'note' => $growth( 'views' ) );
		}
		if ( ! empty( $d['genre_rank'] ) && $d['genre_rank'] <= 10 && ! empty( $d['genre_total'] ) && $d['genre_total'] >= 10 ) {
			$term    = get_term( (int) $d['genre_id'] );
			$cands[] = array( 'label' => ( $term && ! is_wp_error( $term ) ? $term->name : 'ジャンル' ) . 'ジャンルで', 'value' => $num( $d['genre_rank'] ), 'unit' => '位', 'note' => $num( $d['genre_total'] ) . '人中' );
		}
		foreach ( array(
			'search'        => array( '検索結果に表示', '回' ),
			'article_views' => array( '記事を読んだ人', '人' ),
			'referrals'     => array( 'リンクから来た人', '人' ),
			'reactions'     => array( 'いいね・リポスト', '件' ),
			'site_follows'  => array( 'このサイトでフォロー', '人' ),
			'favs'          => array( 'お気に入り登録', '人' ),
			'articles'      => array( '公開した記事', '本' ),
		) as $key => $def ) {
			if ( ! empty( $d[ $key ] ) ) {
				$cands[] = array( 'label' => $def[0], 'value' => $num( $d[ $key ] ), 'unit' => $def[1], 'note' => $growth( $key ) );
			}
		}
		// 大きく出すのは、いちばん見栄えのするもの: フォロワーの増加（10人以上）→ ジャンル内の順位（10位以内）→ 数のいちばん大きいもの。
		$pick = null;
		foreach ( $cands as $i => $c ) {
			if ( ( 'フォロワーが増えました' === $c['label'] && (int) $d['followers_gain'] >= 10 ) || '位' === $c['unit'] ) {
				$pick = $i;
				break;
			}
		}
		if ( null === $pick && $cands ) {
			$best = -1;
			foreach ( $cands as $i => $c ) {
				$v = (int) str_replace( array( ',', '+' ), '', $c['value'] );
				if ( $v > $best ) {
					$best = $v;
					$pick = $i;
				}
			}
		}
		$headline = null === $pick ? null : $cands[ $pick ];
		if ( null !== $pick ) {
			array_splice( $cands, $pick, 1 );
		}
		return array( 'headline' => $headline, 'items' => array_slice( $cands, 0, 4 ) );
	}

	/* --------------------------------------------------------------------
	 * 毎月の作成
	 * ------------------------------------------------------------------ */

	/**
	 * 毎月1日（7時以降）: 全員のフォロワー数を記録し、前の月のレポートを作ってお知らせする。
	 */
	public static function monthly( $force = false ) {
		$today = current_time( 'Y-m-d' );
		$month = gmdate( 'Y-m', strtotime( current_time( 'Y-m-01' ) . ' -1 month' ) );
		if ( ! $force && ( '01' !== substr( $today, 8, 2 ) || (int) current_time( 'G' ) < self::HOUR || get_option( 'snscs_report_month' ) === $month ) ) {
			return 0;
		}
		update_option( 'snscs_report_month', $month, false );
		self::snapshot_followers();
		return self::build( $month, true );
	}

	/**
	 * 全員のいまのフォロワー数を、今日の推移として記録する（自動更新していないSNSも、翌月から増減を出せるように）。
	 */
	public static function snapshot_followers() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . SNSCS_Sync::history_table() . ' (creator_id, platform, day, followers) SELECT creator_id, platform, %s, followers FROM ' . SNSCS_Accounts::table() . ' ON DUPLICATE KEY UPDATE followers = VALUES(followers)', current_time( 'Y-m-d' ) ) );
	}

	/**
	 * その月のレポートを全員分作って保存する。
	 *
	 * @return int 作った数。
	 */
	public static function build( $month, $notify = false ) {
		global $wpdb;
		$all  = self::compute( $month );
		$rows = array();
		$now  = current_time( 'mysql' );
		$made = 0;
		foreach ( $all as $id => $data ) {
			$h = self::highlights( $data );
			if ( ! $h['headline'] ) {
				continue;
			}
			$rows[] = $wpdb->prepare( '(%d, %s, %s, %s)', $id, $month, wp_json_encode( $data ), $now );
			++$made;
			if ( $notify ) {
				SNSCS_Inbox::add( (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'snscs_creator_id' AND meta_value = %d LIMIT 1", $id ) ), 'monthly_report', $id );
			}
		}
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'INSERT INTO ' . self::table() . ' (creator_id, month, data, created_at) VALUES ' . implode( ',', $chunk ) . ' ON DUPLICATE KEY UPDATE data = VALUES(data), created_at = VALUES(created_at)' );
		}
		update_option( 'snscs_report_built', array( 'month' => $month, 'count' => $made, 'at' => $now ), false );
		return $made;
	}

	public static function handle_build() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_report_build' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
		$month = isset( $_POST['month'] ) && preg_match( '/^\d{4}-\d{2}$/', sanitize_text_field( wp_unslash( $_POST['month'] ) ) ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : gmdate( 'Y-m', strtotime( current_time( 'Y-m-01' ) . ' -1 month' ) );
		$made  = self::build( $month, ! empty( $_POST['notify'] ) );
		wp_safe_redirect( add_query_arg( array( 'snscs_msg' => 'report', 'n' => $made ), admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . SNSCS_Hub::ADMIN_PAGE ) ) );
		exit;
	}

	public static function on_delete_post( $post_id ) {
		global $wpdb;
		if ( SNSCS_Post_Types::POST_TYPE === get_post_type( $post_id ) ) {
			$wpdb->delete( self::table(), array( 'creator_id' => (int) $post_id ), array( '%d' ) );
			foreach ( (array) glob( self::dir() . (int) $post_id . '-*.png' ) as $file ) {
				wp_delete_file( $file );
			}
		}
	}

	/* --------------------------------------------------------------------
	 * 画像
	 * ------------------------------------------------------------------ */

	private static function dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'snscs-report/';
	}

	public static function month_label( $month ) {
		return (int) substr( $month, 0, 4 ) . '年' . (int) substr( $month, 5, 2 ) . '月';
	}

	/**
	 * 画像のファイル（無ければ作る）。
	 */
	public static function image_file( $creator_id, $month ) {
		$data = self::get( $creator_id, $month );
		if ( ! $data || ! function_exists( 'imagecreatetruecolor' ) ) {
			return '';
		}
		$prev = self::get( $creator_id, gmdate( 'Y-m', strtotime( $month . '-01 -1 month' ) ) );
		$h    = self::highlights( $data, $prev );
		$key  = substr( md5( wp_json_encode( $h ) . get_the_title( $creator_id ) . get_post_thumbnail_id( $creator_id ) . SNSCS_Share::font() . self::VERSION ), 0, 10 );
		$file = self::dir() . (int) $creator_id . '-' . str_replace( '-', '', $month ) . '-' . $key . '.png';
		if ( is_readable( $file ) ) {
			return $file;
		}
		if ( ! wp_mkdir_p( self::dir() ) ) {
			return '';
		}
		foreach ( (array) glob( self::dir() . (int) $creator_id . '-' . str_replace( '-', '', $month ) . '-*.png' ) as $old ) {
			wp_delete_file( $old );
		}
		$image = self::draw( $creator_id, $month, $h );
		if ( ! $image ) {
			return '';
		}
		imagepng( $image, $file, 6 );
		imagedestroy( $image );
		return is_readable( $file ) ? $file : '';
	}

	/**
	 * 1080×1350 の画像を描く。
	 */
	public static function draw( $creator_id, $month, array $h ) {
		$w     = 1080;
		$ht    = 1350;
		$font  = SNSCS_Share::font();
		$image = imagecreatetruecolor( $w, $ht );
		if ( ! $image || ! $font || ! $h['headline'] ) {
			return false;
		}
		imageantialias( $image, true );
		// 背景: 紺 → 紫のグラデーション。
		for ( $y = 0; $y < $ht; $y++ ) {
			$t = $y / $ht;
			imageline( $image, 0, $y, $w, $y, imagecolorallocate( $image, (int) ( 30 + 60 * $t ), (int) ( 58 - 20 * $t ), (int) ( 138 + 40 * $t ) ) );
		}
		$white  = imagecolorallocate( $image, 255, 255, 255 );
		$soft   = imagecolorallocate( $image, 199, 210, 254 );
		$ink    = imagecolorallocate( $image, 17, 24, 39 );
		$muted  = imagecolorallocate( $image, 90, 99, 115 );
		$accent = imagecolorallocate( $image, 245, 158, 11 );
		$green  = imagecolorallocate( $image, 22, 128, 61 );
		$panel  = imagecolorallocate( $image, 243, 244, 246 );
		$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$text   = function ( $str, $size, $x, $y, $color, $max = 0, $center = false ) use ( $image, $font, $w ) {
			if ( $max ) {
				list( $str, $size ) = SNSCS_Share::fit( $str, $font, $size, $max );
			}
			if ( $center ) {
				$box = imagettfbbox( $size, 0, $font, $str );
				$x   = (int) ( ( $w - ( $box[2] - $box[0] ) ) / 2 );
			}
			imagettftext( $image, $size, 0, $x, $y, $color, $font, $str );
		};

		// 上: サイト名と月。
		$text( $site, 28, 70, 110, $soft, 940 );
		$text( self::month_label( $month ) . 'の成長レポート', 46, 70, 185, $white, 940 );

		// 写真と名前。
		$photo = get_post_thumbnail_id( $creator_id ) ? get_attached_file( get_post_thumbnail_id( $creator_id ) ) : '';
		$src   = ( $photo && is_readable( $photo ) ) ? @imagecreatefromstring( (string) file_get_contents( $photo ) ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$name  = get_the_title( $creator_id );
		if ( $src ) {
			$side = min( imagesx( $src ), imagesy( $src ) );
			imagefilledellipse( $image, 140, 300, 148, 148, $white );
			$tmp = imagecreatetruecolor( 140, 140 );
			imagecopyresampled( $tmp, $src, 0, 0, (int) ( ( imagesx( $src ) - $side ) / 2 ), (int) ( ( imagesy( $src ) - $side ) / 2 ), 140, 140, $side, $side );
			// 丸く切り抜く（円の外を背景に戻す）。
			for ( $yy = 0; $yy < 140; $yy++ ) {
				for ( $xx = 0; $xx < 140; $xx++ ) {
					if ( ( $xx - 69.5 ) ** 2 + ( $yy - 69.5 ) ** 2 <= 70 * 70 ) {
						imagesetpixel( $image, 70 + $xx, 230 + $yy, imagecolorat( $tmp, $xx, $yy ) );
					}
				}
			}
			imagedestroy( $tmp );
			imagedestroy( $src );
		} else {
			imagefilledellipse( $image, 140, 300, 140, 140, $white );
			$initial = mb_substr( $name, 0, 1 );
			$box     = imagettfbbox( 56, 0, $font, $initial );
			imagettftext( $image, 56, 0, (int) ( 140 - ( $box[2] - $box[0] ) / 2 ), 328, $ink, $font, $initial );
		}
		$text( $name, 44, 240, 318, $white, 770 );

		// 大きな数字のカード。
		SNSCS_Share::rounded_rect( $image, 60, 420, $w - 60, 760, 36, $white );
		$hl = $h['headline'];
		$text( $hl['label'], 34, 0, 495, $muted, 880, true );
		list( $value, $vsize ) = SNSCS_Share::fit( $hl['value'], $font, 150, 760 );
		$vbox  = imagettfbbox( $vsize, 0, $font, $value );
		$ubox  = imagettfbbox( 48, 0, $font, $hl['unit'] );
		$total = ( $vbox[2] - $vbox[0] ) + 16 + ( $ubox[2] - $ubox[0] );
		$vx    = (int) ( ( $w - $total ) / 2 );
		imagettftext( $image, $vsize, 0, $vx, 670, 0 === strpos( $hl['value'], '+' ) ? $green : $ink, $font, $value );
		imagettftext( $image, 48, 0, $vx + ( $vbox[2] - $vbox[0] ) + 16, 670, $ink, $font, $hl['unit'] );
		if ( '' !== $hl['note'] ) {
			$text( $hl['note'], 30, 0, 728, $accent, 880, true );
		}

		// ほかの数字（2列×2段）。
		foreach ( $h['items'] as $i => $item ) {
			$col = $i % 2;
			$row = intdiv( $i, 2 );
			$x1  = 60 + $col * 490;
			$y1  = 800 + $row * 200;
			SNSCS_Share::rounded_rect( $image, $x1, $y1, $x1 + 470, $y1 + 180, 28, $panel );
			$text( $item['label'], 26, $x1 + 32, $y1 + 52, $muted, 410 );
			$text( $item['value'] . $item['unit'], 54, $x1 + 32, $y1 + 125, $ink, 410 );
			if ( '' !== $item['note'] ) {
				$text( $item['note'], 22, $x1 + 32, $y1 + 162, $green, 410 );
			}
		}

		// 数字が少ないときは、空いたところにひとこと。
		if ( count( $h['items'] ) <= 2 ) {
			$y = count( $h['items'] ) ? 1080 : 880;
			$text( '掲載中の発信者には、毎月このレポートが届きます', 30, 0, $y, $soft, 940, true );
			$text( 'プロフィールのリンクから、ぜひページを見てください', 30, 0, $y + 56, $soft, 940, true );
		}

		// 下: サイトの案内。
		$text( wp_parse_url( home_url(), PHP_URL_HOST ) . ' で発信者を探す・無料で登録', 28, 0, $ht - 50, $white, 960, true );
		return $image;
	}

	/* --------------------------------------------------------------------
	 * シェア用のページと画像を出す
	 * ------------------------------------------------------------------ */

	public static function serve() {
		$var = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $var ) {
			return;
		}
		$png    = '.png' === substr( $var, -4 );
		$parsed = self::parse( $png ? substr( $var, 0, -4 ) : $var );
		$ok     = $parsed && 'publish' === get_post_status( $parsed[0] ) && self::get( $parsed[0], $parsed[1] );
		if ( ! $ok ) {
			status_header( 404 );
			nocache_headers();
			echo 'Not found';
			exit;
		}
		list( $creator_id, $month ) = $parsed;
		if ( $png ) {
			$file = self::image_file( $creator_id, $month );
			if ( ! $file ) {
				status_header( 404 );
				exit;
			}
			header( 'Content-Type: image/png' );
			header( 'Cache-Control: public, max-age=86400' );
			readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			exit;
		}
		self::render_page( $creator_id, $month );
		exit;
	}

	/**
	 * シェアされたURLを開いた人が見るページ（テーマを使わない軽いページ）。
	 */
	private static function render_page( $creator_id, $month ) {
		$name  = get_the_title( $creator_id );
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title = sprintf( '%sさんの%sの成長レポート | %s', $name, self::month_label( $month ), $site );
		$data  = self::get( $creator_id, $month );
		$h     = self::highlights( $data, self::get( $creator_id, gmdate( 'Y-m', strtotime( $month . '-01 -1 month' ) ) ) );
		$desc  = $h['headline'] ? $h['headline']['label'] . ' ' . $h['headline']['value'] . $h['headline']['unit'] : '';
		$img   = self::image_url( $creator_id, $month );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $title ); ?></title>
<meta name="robots" content="noindex">
<meta property="og:type" content="article">
<meta property="og:title" content="<?php echo esc_attr( $title ); ?>">
<meta property="og:description" content="<?php echo esc_attr( $desc ); ?>">
<meta property="og:url" content="<?php echo esc_url( self::page_url( $creator_id, $month ) ); ?>">
<meta property="og:site_name" content="<?php echo esc_attr( $site ); ?>">
<meta property="og:image" content="<?php echo esc_url( $img ); ?>">
<meta property="og:image:width" content="1080">
<meta property="og:image:height" content="1350">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?php echo esc_url( $img ); ?>">
<style>
body{margin:0;background:linear-gradient(180deg,#1e3a8a,#5a2ab2);min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,"Hiragino Sans","Noto Sans JP",sans-serif;color:#111827}
.r{max-width:520px;margin:0 auto;padding:24px 16px 48px}
.r img{display:block;width:100%;height:auto;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.35)}
.r a{display:block;margin-top:14px;padding:16px;border-radius:999px;text-align:center;font-weight:700;text-decoration:none}
.r .p{background:#fff;color:#1e3a8a}.r .s{background:rgba(255,255,255,.14);color:#fff;border:1px solid rgba(255,255,255,.5)}
.r p{color:#e0e7ff;font-size:14px;text-align:center;line-height:1.7}
</style>
</head>
<body>
<main class="r">
<img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $name . 'さんの' . self::month_label( $month ) . 'の成長レポート' ); ?>" width="1080" height="1350">
<a class="p" href="<?php echo esc_url( SNSCS_Referrals::link_for( $creator_id ) ); ?>"><?php echo esc_html( $name ); ?>さんのページを見る</a>
<a class="s" href="<?php echo esc_url( SNSCS_Invites::join_url( $creator_id ) ); ?>">あなたも無料で登録して、毎月レポートを受け取る</a>
<p><?php echo esc_html( $site ); ?>は、SNSの発信者を探せるサイトです。掲載中の発信者には、毎月この成長レポートが届きます。</p>
</main>
</body>
</html>
		<?php
	}

	/* --------------------------------------------------------------------
	 * 発信者のマイページ
	 * ------------------------------------------------------------------ */

	public static function render_portal( $creator_id ) {
		$month = self::latest_month( $creator_id );
		if ( '' === $month ) {
			?>
			<section class="snscs-panel" id="snscs-report">
				<h2 class="snscs-panel__title">月間成長レポート</h2>
				<p>毎月1日に、前の月のあなたの数字（フォロワーの増加・ページを見た人・順位など）をまとめた画像が届きます。SNSでシェアすると、見た人があなたのページに来てくれます。</p>
			</section>
			<?php
			return;
		}
		$page  = self::page_url( $creator_id, $month );
		$img   = self::image_url( $creator_id, $month );
		$h     = self::highlights( self::get( $creator_id, $month ), self::get( $creator_id, gmdate( 'Y-m', strtotime( $month . '-01 -1 month' ) ) ) );
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$text  = sprintf( '%sの成長レポートが届きました！%s %s%s #%s', self::month_label( $month ), $h['headline']['label'], $h['headline']['value'], $h['headline']['unit'], preg_replace( '/\s+/u', '', $site ) );
		$file  = sanitize_file_name( 'report-' . $month . '.png' );
		wp_enqueue_script( 'snscs-report', SNSCS_URL . 'assets/js/snscs-report.js', array(), SNSCS_VERSION, true );
		?>
		<section class="snscs-panel snscs-report" id="snscs-report">
			<h2 class="snscs-panel__title"><?php echo esc_html( self::month_label( $month ) ); ?>の成長レポート</h2>
			<p>シェアすると、見た人はあなたのページへ進めます（紹介リンクとして数えます）。そこから掲載した人は、あなたの招待として数えます。</p>
			<div class="snscs-report__body">
				<img class="snscs-report__image" src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( self::month_label( $month ) . 'の成長レポート' ); ?>" width="1080" height="1350" loading="lazy">
				<div class="snscs-report__actions">
					<button type="button" class="snscs-button snscs-button--primary" data-snscs-report-share data-image="<?php echo esc_url( $img ); ?>" data-file="<?php echo esc_attr( $file ); ?>" data-text="<?php echo esc_attr( $text ); ?>" data-url="<?php echo esc_url( $page ); ?>" hidden>画像をシェア（Instagram など）</button>
					<a class="snscs-share__button snscs-share__button--x" href="<?php echo esc_url( 'https://x.com/intent/tweet?' . http_build_query( array( 'text' => $text, 'url' => $page ) ) ); ?>" target="_blank" rel="noopener">Xでシェア</a>
					<a class="snscs-share__button snscs-share__button--threads" href="<?php echo esc_url( 'https://www.threads.net/intent/post?' . http_build_query( array( 'text' => $text . ' ' . $page ) ) ); ?>" target="_blank" rel="noopener">Threadsでシェア</a>
					<a class="snscs-share__button snscs-share__button--line" href="<?php echo esc_url( 'https://social-plugins.line.me/lineit/share?' . http_build_query( array( 'url' => $page ) ) ); ?>" target="_blank" rel="noopener">LINEで送る</a>
					<a class="snscs-button" href="<?php echo esc_url( $img ); ?>" download="<?php echo esc_attr( $file ); ?>">画像を保存</a>
					<label class="snscs-field"><span>投稿の文（コピーして使えます）</span><textarea rows="3" readonly onclick="this.select();"><?php echo esc_textarea( $text . "\n" . $page ); ?></textarea></label>
					<p class="snscs-help">Instagram には「画像を保存」してから投稿し、プロフィールのリンク（FolloLink）で案内するのがおすすめです。</p>
				</div>
			</div>
		</section>
		<?php
	}
}
