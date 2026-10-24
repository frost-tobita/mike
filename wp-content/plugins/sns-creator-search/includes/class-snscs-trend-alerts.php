<?php
/**
 * 発信者へのトレンドのお知らせ。
 *
 * 毎日9時以降に1回、ジャンルごとに「急上昇」の言葉を1つ選び（直近7日に3人以上が検索、
 * 同じジャンルで14日以内に知らせていない言葉）、そのジャンルで掲載中の発信者にお知らせ
 * （サイト内・スマホ通知）を送る。1人に届くのは1日1件まで。メールでは送らない。
 * マイページには「あなたのジャンルのトレンド」を出す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Trend_Alerts {

	const HOUR        = 9;
	const QUIET_DAYS  = 14;
	const MIN_VISITOR = 3;

	public static function init() {
		add_action( SNSCS_Inbox::CRON_HOOK, array( __CLASS__, 'run' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_trend_alerts';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  day date NOT NULL,
  genre varchar(200) NOT NULL,
  term varchar(64) NOT NULL,
  visitors int(10) unsigned NOT NULL DEFAULT 0,
  notified int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY genre_day (genre(150),day)
) {$charset};"
		);
	}

	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	public static function purge_old() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE day < %s', gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . SNSCS_Trends::KEEP_DAYS . ' days' ) ) ) );
	}

	/**
	 * 1時間ごとの見回りから呼ばれる（9時以降の最初の1回だけ働く）。
	 *
	 * @param bool $force 時刻と「今日はもう送った」を無視する（確認用）。
	 * @return int お知らせを送った人数。
	 */
	public static function run( $force = false ) {
		$today = current_time( 'Y-m-d' );
		if ( ! $force && ( ! SNSCS_Hub::setting( 'trend_alerts' ) || (int) current_time( 'G' ) < self::HOUR || get_option( 'snscs_trend_alert_day' ) === $today ) ) {
			return 0;
		}
		update_option( 'snscs_trend_alert_day', $today, false );
		self::purge_old();

		global $wpdb;
		$table = self::table();
		$done  = array();
		foreach ( SNSCS_Trends::genres_with_trends() as $genre ) {
			$stats = SNSCS_Trends::stats( $genre->slug );
			$pick  = '';
			foreach ( $stats['hot'] as $term ) {
				if ( ( $stats['rising'][ $term ] ?? 0 ) < self::MIN_VISITOR ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$recent = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE genre = %s AND term = %s AND day > %s LIMIT 1", $genre->slug, $term, gmdate( 'Y-m-d', strtotime( $today . ' -' . self::QUIET_DAYS . ' days' ) ) ) );
				if ( ! $recent ) {
					$pick = $term;
					break;
				}
			}
			if ( '' === $pick ) {
				continue;
			}
			$wpdb->insert( $table, array( 'day' => $today, 'genre' => $genre->slug, 'term' => $pick, 'visitors' => (int) $stats['rising'][ $pick ] ) );
			$alert = (int) $wpdb->insert_id;
			$sent  = 0;
			foreach ( self::users_in_genre( (int) $genre->term_id ) as $user_id ) {
				if ( isset( $done[ $user_id ] ) ) {
					continue;
				}
				$done[ $user_id ] = true;
				SNSCS_Inbox::add( $user_id, 'trend', $alert );
				++$sent;
			}
			$wpdb->update( $table, array( 'notified' => $sent ), array( 'id' => $alert ) );
		}
		return count( $done );
	}

	/**
	 * そのジャンルで掲載中の発信者のメンバーID。
	 *
	 * @return int[]
	 */
	private static function users_in_genre( $term_id ) {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT um.user_id FROM {$wpdb->usermeta} um
					INNER JOIN {$wpdb->posts} p ON p.ID = um.meta_value AND p.post_type = %s AND p.post_status = 'publish'
					INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
					INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s AND tt.term_id = %d
					WHERE um.meta_key = 'snscs_creator_id'",
					SNSCS_Post_Types::POST_TYPE,
					SNSCS_Post_Types::TAX_GENRE,
					$term_id
				)
			)
		);
	}

	/**
	 * お知らせの文（SNSCS_Inbox::describe から）。
	 *
	 * @return array{text:string,url:string}|null
	 */
	public static function describe( $alert_id ) {
		$alert = self::get( $alert_id );
		$genre = $alert ? get_term_by( 'slug', $alert['genre'], SNSCS_Post_Types::TAX_GENRE ) : null;
		if ( ! $alert || ! $genre || ! SNSCS_Trends::allowed( $alert['term'] ) ) {
			return null;
		}
		return array(
			'text' => sprintf( '%sジャンルで「%s」が急上昇しています（ここ7日で%s人が検索）。この話題の記事を書くと、見つけてもらいやすくなります。', $genre->name, $alert['term'], number_format_i18n( (int) $alert['visitors'] ) ),
			'url'  => SNSCS_Trend_Pages::url( $alert['term'] ),
		);
	}

	/**
	 * マイページ: あなたのジャンルのトレンド。
	 */
	public static function render_portal( $creator_id ) {
		$genres = get_the_terms( $creator_id, SNSCS_Post_Types::TAX_GENRE );
		if ( ! $genres || is_wp_error( $genres ) ) {
			return;
		}
		$blocks = '';
		foreach ( array_slice( $genres, 0, 3 ) as $genre ) {
			$rows = SNSCS_Trends::ranking( $genre->slug, 5 );
			if ( ! $rows ) {
				continue;
			}
			$blocks .= '<h3 class="snscs-sharekit__heading">' . esc_html( $genre->name ) . '</h3><ul class="snscs-chips">';
			foreach ( $rows as $row ) {
				$blocks .= '<li><a class="snscs-chip' . ( $row['hot'] ? ' snscs-chip--hot' : '' ) . '" href="' . esc_url( SNSCS_Trend_Pages::url( $row['term'] ) ) . '">' . esc_html( $row['term'] ) . ' <span class="snscs-chip__count">' . esc_html( number_format_i18n( $row['visitors'] ) ) . '人</span></a></li>';
			}
			$blocks .= '</ul>';
		}
		if ( '' === $blocks ) {
			return;
		}
		?>
		<section class="snscs-panel snscs-mytrends" id="snscs-mytrends">
			<h2 class="snscs-panel__title">あなたのジャンルのトレンド</h2>
			<p>いま、あなたのジャンルで検索されている言葉です（↑は急上昇）。この話題の記事を書くと、キーワードのページや検索で見つけてもらいやすくなります。</p>
			<?php echo $blocks; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>
			<p><a class="snscs-button snscs-button--primary" href="<?php echo esc_url( SNSCS_Articles::editor_url() ); ?>">記事を書く</a></p>
		</section>
		<?php
	}
}
