<?php
/**
 * 公開ランキング（[sns_ranking]）と「今月の注目」（[sns_spotlight]）。
 *
 *   総合     : おすすめ順の総合点（全員共通。一人ひとりの好みは混ぜない）。SNS・ジャンルで絞り込める
 *   急上昇   : 直近30日にページを見た人数が、その前の30日より増えた人数の多い順
 *   招待     : 今月、招待した発信者が掲載された人数（アンバサダー）。お金などの報酬はなく、ここに載ることが「ごほうび」
 *   新着     : 最近掲載された発信者
 *
 * 毎月1日に前の月の総合ランキング（上位100人）を保存し、「先月から↑3」のような動きと、過去の月のランキングを出す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Rankings {

	const SIZE          = 50;
	const SNAPSHOT_SIZE = 100;

	public static function init() {
		add_shortcode( 'sns_ranking', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'sns_spotlight', array( __CLASS__, 'shortcode_spotlight' ) );
		add_action( SNSCS_Scores::CRON_HOOK, array( __CLASS__, 'maybe_snapshot' ), 20 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_rank_history';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  month char(7) NOT NULL,
  creator_id bigint(20) unsigned NOT NULL,
  rank_no smallint(5) unsigned NOT NULL,
  score double NOT NULL DEFAULT 0,
  PRIMARY KEY  (month,creator_id),
  KEY creator_id (creator_id)
) {$charset};"
		);
	}

	public static function tabs() {
		return array(
			'overall' => '総合',
			'rising'  => '急上昇',
			'invites' => '招待',
			'new'     => '新着',
		);
	}

	/* --------------------------------------------------------------------
	 * 計算
	 * ------------------------------------------------------------------ */

	/**
	 * 月が変わったら、前の月の総合ランキングを保存する（点数計算の定期処理から呼ばれる）。
	 */
	public static function maybe_snapshot() {
		$previous = gmdate( 'Y-m', strtotime( current_time( 'Y-m-01' ) . ' -1 day' ) );
		$saved    = get_option( 'snscs_rank_snapshot' );
		if ( $saved === $previous ) {
			return;
		}
		// 使い始めた月は保存しない（今の順位を「先月」として残さないため）。次の月の初めから保存する。
		if ( false === $saved ) {
			update_option( 'snscs_rank_snapshot', $previous );
			return;
		}
		self::snapshot( $previous );
	}

	/**
	 * 今の総合ランキングを、指定した月のものとして保存する。
	 */
	public static function snapshot( $month ) {
		global $wpdb;
		$rows  = SNSCS_Scores::top( self::SNAPSHOT_SIZE );
		$table = self::table();
		$wpdb->delete( $table, array( 'month' => $month ), array( '%s' ) );
		$values = array();
		foreach ( $rows as $i => $row ) {
			$values[] = $wpdb->prepare( '(%s, %d, %d, %f)', $month, $row['creator_id'], $i + 1, $row['score'] );
		}
		if ( $values ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "INSERT INTO {$table} (month, creator_id, rank_no, score) VALUES " . implode( ',', $values ) );
		}
		update_option( 'snscs_rank_snapshot', $month );
	}

	/**
	 * 保存してある月（新しい順）。
	 *
	 * @return string[]
	 */
	public static function months() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_col( "SELECT DISTINCT month FROM {$table} ORDER BY month DESC LIMIT 24" );
	}

	/**
	 * ある月の順位（発信者ID => 順位）。
	 */
	public static function ranks_of( $month ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT creator_id, rank_no FROM {$table} WHERE month = %s ORDER BY rank_no", $month ), ARRAY_A );
		return array_map( 'intval', wp_list_pluck( $rows, 'rank_no', 'creator_id' ) );
	}

	/**
	 * 急上昇: 直近30日に見た人数と、その前の30日の人数。
	 *
	 * @return array<int,array{cur:int,prev:int}> 増えた人数の多い順。
	 */
	public static function rising( $limit = self::SIZE, $platform = '' ) {
		global $wpdb;
		$daily = SNSCS_Tracking::daily_table();
		$today = current_time( 'Y-m-d' );
		$mid   = gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) );
		$start = gmdate( 'Y-m-d', strtotime( $today . ' -59 days' ) );
		$where = '';
		$params = array( $mid, $mid, $start, SNSCS_Post_Types::POST_TYPE );
		if ( '' !== $platform ) {
			$accounts = SNSCS_Accounts::table();
			$where    = " AND EXISTS (SELECT 1 FROM {$accounts} a WHERE a.creator_id = d.creator_id AND a.platform = %s)";
			$params[] = $platform;
		}
		$params[] = $limit;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT creator_id, cur, prev FROM (
					SELECT d.creator_id, SUM(CASE WHEN d.day >= %s THEN d.views ELSE 0 END) AS cur, SUM(CASE WHEN d.day < %s THEN d.views ELSE 0 END) AS prev
					FROM {$daily} d INNER JOIN {$wpdb->posts} p ON p.ID = d.creator_id AND p.post_status = 'publish'
					WHERE d.day >= %s AND p.post_type = %s{$where}
					GROUP BY d.creator_id
				) t WHERE cur >= 5 AND cur > prev ORDER BY cur - prev DESC, cur DESC LIMIT %d",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['creator_id'] ] = array( 'cur' => (int) $row['cur'], 'prev' => (int) $row['prev'] );
		}
		return $out;
	}

	/**
	 * 招待ランキング: その月に、招待した発信者が掲載された人数。
	 *
	 * @param string $month 'Y-m'。
	 * @return array<int,int> 発信者ID => 人数（多い順）。
	 */
	public static function invite_leaders( $month, $limit = 20 ) {
		global $wpdb;
		$start = $month . '-01 00:00:00';
		$end   = gmdate( 'Y-m-01 00:00:00', strtotime( $start . ' +1 month' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ref.meta_value AS inviter, COUNT(*) AS n FROM {$wpdb->postmeta} ref
				INNER JOIN {$wpdb->postmeta} jat ON jat.post_id = ref.post_id AND jat.meta_key = '_snscs_joined_at'
				INNER JOIN {$wpdb->posts} c ON c.ID = ref.post_id AND c.post_status = 'publish' AND c.post_type = %s
				INNER JOIN {$wpdb->posts} i ON i.ID = ref.meta_value AND i.post_status = 'publish' AND i.post_type = %s
				WHERE ref.meta_key = '_snscs_referrer' AND jat.meta_value >= %s AND jat.meta_value < %s
				GROUP BY ref.meta_value ORDER BY n DESC, MIN(jat.meta_value) ASC LIMIT %d",
				SNSCS_Post_Types::POST_TYPE,
				SNSCS_Post_Types::POST_TYPE,
				$start,
				$end,
				$limit
			),
			ARRAY_A
		);
		return array_map( 'intval', wp_list_pluck( $rows, 'n', 'inviter' ) );
	}

	/**
	 * 発信者1人の、ランキングでの位置（マイページ・シェア画像用）。
	 *
	 * @return array{overall:int,total:int,rising:int,invites_month:int,invite_rank:int,previous:int}
	 */
	public static function position( $creator_id ) {
		$standing = SNSCS_Scores::standing( $creator_id );
		$rising   = array_keys( self::rising( 100 ) );
		$month    = current_time( 'Y-m' );
		$leaders  = self::invite_leaders( $month, 100 );
		$snap     = get_option( 'snscs_rank_snapshot' );
		$previous = $snap ? self::ranks_of( $snap ) : array();
		return array(
			'overall'       => $standing ? (int) $standing['rank'] : 0,
			'total'         => $standing ? (int) $standing['total'] : 0,
			'rising'        => false === array_search( $creator_id, $rising, true ) ? 0 : array_search( $creator_id, $rising, true ) + 1,
			'invites_month' => isset( $leaders[ $creator_id ] ) ? $leaders[ $creator_id ] : 0,
			'invite_rank'   => isset( $leaders[ $creator_id ] ) ? array_search( $creator_id, array_keys( $leaders ), true ) + 1 : 0,
			'previous'      => isset( $previous[ $creator_id ] ) ? $previous[ $creator_id ] : 0,
		);
	}

	/* --------------------------------------------------------------------
	 * 表示
	 * ------------------------------------------------------------------ */

	private static function param( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 閲覧用の切り替え。
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	public static function shortcode() {
		SNSCS_Hub::remember_page( 'ranking' );
		wp_enqueue_style( 'snscs' );

		$tab      = self::param( 'snscs_rk' );
		$tab      = array_key_exists( $tab, self::tabs() ) ? $tab : 'overall';
		$platform = sanitize_key( self::param( 'snscs_rk_sns' ) );
		$platform = array_key_exists( $platform, snscs_platforms() ) ? $platform : '';
		$genre    = sanitize_title( self::param( 'snscs_rk_genre' ) );
		$month    = preg_match( '/^\d{4}-\d{2}$/', self::param( 'snscs_month' ) ) ? self::param( 'snscs_month' ) : '';
		$base     = remove_query_arg( array( 'snscs_rk', 'snscs_rk_sns', 'snscs_rk_genre', 'snscs_month' ) );

		ob_start();
		echo SNSCS_Hub::quick_nav(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		?>
		<nav class="snscs-index snscs-ranking__tabs" aria-label="ランキングの種類">
			<ul class="snscs-index__list">
				<?php foreach ( self::tabs() as $key => $label ) : ?>
					<li><a class="snscs-index__tab" href="<?php echo esc_url( add_query_arg( 'snscs_rk', $key, $base ) ); ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>><span class="snscs-index__label"><?php echo esc_html( $label ); ?></span></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
		if ( 'overall' === $tab || 'rising' === $tab ) {
			self::render_filters( $tab, $platform, $genre, $base );
		}

		switch ( $tab ) {
			case 'rising':
				self::render_rising( $platform );
				break;
			case 'invites':
				self::render_invites();
				break;
			case 'new':
				self::render_new();
				break;
			default:
				self::render_overall( $platform, $genre, $month, $base );
		}

		echo SNSCS_Ads::render( 'search_bottom' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 広告枠のHTML。
		echo SNSCS_Newsletter::mini_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。
		return ob_get_clean();
	}

	private static function render_filters( $tab, $platform, $genre, $base ) {
		$url = add_query_arg( 'snscs_rk', $tab, $base );
		?>
		<ul class="snscs-chips snscs-ranking__filters">
			<li><a class="snscs-chip" href="<?php echo esc_url( remove_query_arg( 'snscs_rk_sns', add_query_arg( 'snscs_rk_genre', $genre, $url ) ) ); ?>"<?php echo '' === $platform ? ' aria-current="true"' : ''; ?>>すべてのSNS</a></li>
			<?php foreach ( snscs_platforms() as $key => $label ) : ?>
				<li><a class="snscs-chip" href="<?php echo esc_url( add_query_arg( array( 'snscs_rk_sns' => $key, 'snscs_rk_genre' => $genre ), $url ) ); ?>"<?php echo $key === $platform ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?></a></li>
			<?php endforeach; ?>
		</ul>
		<?php if ( 'overall' === $tab ) : ?>
			<form class="snscs-ranking__genre" method="get" action="<?php echo esc_url( strtok( $url, '?' ) ); ?>">
				<input type="hidden" name="snscs_rk" value="overall">
				<input type="hidden" name="snscs_rk_sns" value="<?php echo esc_attr( $platform ); ?>">
				<label>ジャンル
					<?php
					wp_dropdown_categories(
						array(
							'taxonomy'          => SNSCS_Post_Types::TAX_GENRE,
							'name'              => 'snscs_rk_genre',
							'value_field'       => 'slug',
							'selected'          => $genre,
							'show_option_all'   => 'すべて',
							'option_none_value' => '',
							'hierarchical'      => true,
							'hide_empty'        => false,
						)
					);
					?>
				</label>
				<button type="submit" class="snscs-button">表示</button>
			</form>
		<?php endif; ?>
		<?php
	}

	private static function render_overall( $platform, $genre, $month, $base ) {
		$months = self::months();
		if ( '' !== $month && in_array( $month, $months, true ) ) {
			// 過去の月のランキング（保存した上位100人）。
			$ranks = self::ranks_of( $month );
			$items = SNSCS_Personalize::items( array_keys( $ranks ) );
			$badge = array();
			foreach ( $items as $item ) {
				$badge[ $item['id'] ] = $ranks[ $item['id'] ] . '位';
			}
			echo '<p class="snscs-ranking__lead">' . esc_html( sprintf( '%d年%d月の総合ランキング', (int) substr( $month, 0, 4 ), (int) substr( $month, 5, 2 ) ) ) . '</p>';
			SNSCS_Frontend::render_cards( $items, 0, $badge );
		} else {
			$result   = SNSCS_Search::run( array( 'platform' => $platform, 'genre' => '0' === $genre ? '' : $genre, 'sort' => 'recommended', 'per_page' => self::SIZE ) );
			$snap     = get_option( 'snscs_rank_snapshot' );
			$previous = ( $snap && '' === $platform && in_array( $genre, array( '', '0' ), true ) ) ? self::ranks_of( $snap ) : array();
			$badges   = array();
			$notes    = array();
			foreach ( $result['items'] as $i => $item ) {
				$rank                  = $i + 1;
				$badges[ $item['id'] ] = $rank . '位';
				if ( $previous ) {
					if ( ! isset( $previous[ $item['id'] ] ) ) {
						$notes[ $item['id'] ] = '先月からランクイン';
					} elseif ( $previous[ $item['id'] ] > $rank ) {
						$notes[ $item['id'] ] = sprintf( '先月から↑%d', $previous[ $item['id'] ] - $rank );
					} elseif ( $previous[ $item['id'] ] < $rank ) {
						$notes[ $item['id'] ] = sprintf( '先月から↓%d', $rank - $previous[ $item['id'] ] );
					}
				}
			}
			echo '<p class="snscs-ranking__lead">紹介リンク・フォロワー・ページの閲覧や滞在時間などを合わせた総合点の順位です（1時間ごとに更新）。</p>';
			if ( $result['items'] ) {
				SNSCS_Frontend::render_cards( $result['items'], (int) SNSCS_Ads::settings()['infeed_after'], $badges, $notes );
			} else {
				echo '<p class="snscs-results__empty">この条件の発信者はまだいません。</p>';
			}
		}
		if ( $months ) {
			echo '<nav class="snscs-ranking__archive" aria-label="過去のランキング"><h2 class="snscs-feature-links__title">過去の総合ランキング</h2><ul class="snscs-chips">';
			foreach ( $months as $m ) {
				printf( '<li><a class="snscs-chip" href="%s">%d年%d月</a></li>', esc_url( add_query_arg( array( 'snscs_rk' => 'overall', 'snscs_month' => $m ), $base ) ), (int) substr( $m, 0, 4 ), (int) substr( $m, 5, 2 ) );
			}
			echo '</ul></nav>';
		}
	}

	private static function render_rising( $platform ) {
		$rising = self::rising( self::SIZE, $platform );
		echo '<p class="snscs-ranking__lead">直近30日にページを見た人が、その前の30日より大きく増えた発信者です。</p>';
		if ( ! $rising ) {
			echo '<p class="snscs-results__empty">まだデータがありません。</p>';
			return;
		}
		$items  = SNSCS_Personalize::items( array_keys( $rising ) );
		$badges = array();
		$notes  = array();
		$rank   = 0;
		foreach ( $items as $item ) {
			$r                     = $rising[ $item['id'] ];
			$badges[ $item['id'] ] = ( ++$rank ) . '位';
			$notes[ $item['id'] ]  = sprintf( 'ページを見た人 %s人（前の30日より +%s人）', number_format_i18n( $r['cur'] ), number_format_i18n( $r['cur'] - $r['prev'] ) );
		}
		SNSCS_Frontend::render_cards( $items, (int) SNSCS_Ads::settings()['infeed_after'], $badges, $notes );
	}

	private static function render_invites() {
		$month   = current_time( 'Y-m' );
		$leaders = self::invite_leaders( $month );
		printf( '<p class="snscs-ranking__lead">%d月に、新しい発信者をこのサイトに招待してくれた「アンバサダー」です（招待した人が審査を通って掲載された人数）。上位の方はトップページの「今月の注目」でも紹介します。</p>', (int) substr( $month, 5, 2 ) );
		if ( ! $leaders ) {
			echo '<p class="snscs-results__empty">今月はまだいません。発信者の方は、マイページの「発信者仲間を招待する」から招待できます。</p>';
		} else {
			$items  = SNSCS_Personalize::items( array_keys( $leaders ) );
			$badges = array();
			$notes  = array();
			$rank   = 0;
			foreach ( $items as $item ) {
				$badges[ $item['id'] ] = ( ++$rank ) . '位';
				$notes[ $item['id'] ]  = sprintf( '今月 %d人を招待', $leaders[ $item['id'] ] );
			}
			SNSCS_Frontend::render_cards( $items, 0, $badges, $notes );
		}
		echo '<p class="snscs-help">招待はランキングと表示順にだけ使われ、お金などの報酬はありません。</p>';
	}

	private static function render_new() {
		$result = SNSCS_Search::run( array( 'sort' => 'newest', 'per_page' => 30 ) );
		echo '<p class="snscs-ranking__lead">最近掲載された発信者です。</p>';
		if ( $result['items'] ) {
			SNSCS_Frontend::render_cards( $result['items'], (int) SNSCS_Ads::settings()['infeed_after'] );
		}
	}

	/**
	 * [sns_spotlight]: 今月の注目（急上昇の上位と、招待ランキングの上位）。
	 */
	public static function shortcode_spotlight( $atts ) {
		$atts    = shortcode_atts( array( 'count' => 3, 'title' => '今月の注目' ), $atts, 'sns_spotlight' );
		$count   = min( 6, max( 1, (int) $atts['count'] ) );
		$rising  = SNSCS_Personalize::items( array_keys( self::rising( $count ) ) );
		$leaders = self::invite_leaders( current_time( 'Y-m' ), $count );
		$amb     = SNSCS_Personalize::items( array_keys( $leaders ) );
		foreach ( $rising as &$item ) {
			$item['reason'] = '急上昇';
		}
		unset( $item );
		foreach ( $amb as &$item ) {
			$item['reason'] = sprintf( '今月のアンバサダー（%d人を招待）', $leaders[ $item['id'] ] );
		}
		unset( $item );
		$items = array_merge( $amb, $rising );
		if ( ! $items ) {
			return '';
		}
		$html = SNSCS_Recommend::render( sanitize_text_field( $atts['title'] ), $items, array( 'reasons' => true ) );
		$url  = SNSCS_Hub::page_url( 'ranking' );
		return $url ? str_replace( '</section>', '<p class="snscs-reco__more"><a href="' . esc_url( $url ) . '">ランキングをもっと見る</a></p></section>', $html ) : $html;
	}
}
