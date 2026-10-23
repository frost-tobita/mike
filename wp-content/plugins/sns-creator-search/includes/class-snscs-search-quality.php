<?php
/**
 * 検索の品質を数字で見る（1.21。管理画面「発信者 → 検索の品質」）。
 *
 * 毎日の数字（誰が調べたかは残さない。日ごとの合計だけ）:
 *   検索の回数・言葉ありの回数・0件だった回数（検索のたびに数える。ロボット・運営は除く）
 *   記録した検索のうち、何か押された割合・1位が押された割合・満足した割合・何も押さずに帰った割合、
 *   最初に押された位置の平均（MRR: 1位なら1、2位なら1/2… の平均。上で見つかるほど1に近い）
 *     … 毎晩、検索結果での行動の記録（SNSCS_Feedback）から計算する
 * 並べ方を混ぜて比べる（インターリーブ）の結果: 押された人がどちらの並びから来たかで、勝ち負けを数える。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Search_Quality {

	const ADMIN_PAGE = 'snscs-search-quality';
	const KEEP_DAYS  = 400;

	public static function init() {
		add_action( 'snscs_nightly_engine', array( __CLASS__, 'nightly' ), 15 );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_search_daily';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  day date NOT NULL,
  metric varchar(20) NOT NULL,
  value bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,metric)
) {$charset};"
		);
	}

	/**
	 * 今日の数字を1つ増やす。
	 */
	public static function count( $metric, $by = 1 ) {
		global $wpdb;
		if ( SNSCS_Referrals::is_bot_request() || current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::table() . ' (day, metric, value) VALUES (%s, %s, %d) ON DUPLICATE KEY UPDATE value = value + VALUES(value)', current_time( 'Y-m-d' ), $metric, $by ) );
	}

	/**
	 * 検索ページを開いたとき（1ページ目だけ）。
	 */
	public static function record_search( array $args, $total ) {
		self::count( 'searches' );
		if ( '' !== $args['q'] ) {
			self::count( 'searches_q' );
		}
		if ( ! $total ) {
			self::count( 'zero' );
		}
	}

	/**
	 * 行動の記録から、日ごとの数字を作り直す（直近3日分。毎晩）。
	 */
	public static function nightly() {
		global $wpdb;
		$events = SNSCS_Feedback::events_table();
		$table  = self::table();
		$since  = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -3 days' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day, COUNT(*) AS searches, SUM(any_click) AS clicked, SUM(first_pos = 1) AS pos1, SUM(any_good) AS good, SUM(any_bounce) AS bounced, SUM(IF(first_pos IS NULL, 0, 1000 / first_pos)) AS rr
				FROM (SELECT qid, MIN(day) AS day, MAX(clicked) AS any_click, MAX(good) AS any_good, MAX(bounce) AS any_bounce, MIN(CASE WHEN clicked = 1 THEN pos END) AS first_pos FROM {$events} WHERE day >= %s GROUP BY qid) t GROUP BY day",
				$since
			),
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			foreach ( array( 'ev_searches' => 'searches', 'ev_clicked' => 'clicked', 'ev_pos1' => 'pos1', 'ev_good' => 'good', 'ev_bounced' => 'bounced', 'ev_rr' => 'rr' ) as $metric => $col ) {
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (day, metric, value) VALUES (%s, %s, %d) ON DUPLICATE KEY UPDATE value = VALUES(value)", $r['day'], $metric, (int) round( (float) $r[ $col ] ) ) );
			}
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . self::KEEP_DAYS . ' days' ) ) ) );
		// phpcs:enable
	}

	/**
	 * 日ごとの数字。
	 *
	 * @return array<string,array<string,int>> 日 => 名前 => 値（新しい日から）。
	 */
	public static function daily( $days = 14 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . ( $days - 1 ) . ' days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT day, metric, value FROM ' . self::table() . ' WHERE day >= %s ORDER BY day DESC', $since ), ARRAY_A );
		$out  = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$out[ gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . $i . ' days' ) ) ] = array();
		}
		foreach ( $rows as $r ) {
			$out[ $r['day'] ][ $r['metric'] ] = (int) $r['value'];
		}
		return $out;
	}

	/**
	 * 日ごとの数字から、割合を出す。
	 *
	 * @return array{zero:?float,clicked:?float,pos1:?float,good:?float,abandon:?float,mrr:?float,searches:int}
	 */
	public static function rates( array $d ) {
		$s   = (int) ( $d['searches'] ?? 0 );
		$ev  = (int) ( $d['ev_searches'] ?? 0 );
		$pct = function ( $num, $den ) {
			return $den > 0 ? $num / $den : null;
		};
		return array(
			'searches' => $s,
			'zero'     => $pct( (int) ( $d['zero'] ?? 0 ), $s ),
			'clicked'  => $pct( (int) ( $d['ev_clicked'] ?? 0 ), $ev ),
			'pos1'     => $pct( (int) ( $d['ev_pos1'] ?? 0 ), $ev ),
			'good'     => $pct( (int) ( $d['ev_good'] ?? 0 ), $ev ),
			'abandon'  => $ev > 0 ? 1 - (int) ( $d['ev_clicked'] ?? 0 ) / $ev : null,
			'mrr'      => $pct( (int) ( $d['ev_rr'] ?? 0 ) / 1000, $ev ),
		);
	}

	/**
	 * 混ぜて比べた結果（直近14日）。押された人が多かった方の並びの「勝ち」。
	 *
	 * @return array{a:int,b:int,tie:int,z:float}
	 */
	public static function interleave_results( $days = 14 ) {
		global $wpdb;
		$events = SNSCS_Feedback::events_table();
		$since  = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . ( $days - 1 ) . ' days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT SUM(clicked * (bucket = 'a')) AS ca, SUM(clicked * (bucket = 'b')) AS cb FROM {$events} WHERE day >= %s AND pos <= 20 GROUP BY qid HAVING COUNT(DISTINCT bucket) = 2 AND (ca + cb) > 0", $since ), ARRAY_A );
		$out  = array( 'a' => 0, 'b' => 0, 'tie' => 0, 'z' => 0.0 );
		foreach ( $rows as $r ) {
			if ( (int) $r['ca'] > (int) $r['cb'] ) {
				++$out['a'];
			} elseif ( (int) $r['cb'] > (int) $r['ca'] ) {
				++$out['b'];
			} else {
				++$out['tie'];
			}
		}
		$n        = $out['a'] + $out['b'];
		$out['z'] = $n ? ( $out['b'] - $out['a'] ) / sqrt( $n ) : 0.0;
		return $out;
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, '検索の品質', '検索の品質', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$daily = self::daily( 30 );
		$sum   = array();
		$week  = array();
		$i     = 0;
		foreach ( $daily as $d ) {
			foreach ( $d as $k => $v ) {
				$sum[ $k ] = ( $sum[ $k ] ?? 0 ) + $v;
				if ( $i < 7 ) {
					$week[ $k ] = ( $week[ $k ] ?? 0 ) + $v;
				}
			}
			++$i;
		}
		$r30  = self::rates( $sum );
		$r7   = self::rates( $week );
		$pct  = function ( $v ) {
			return null === $v ? '—' : number_format_i18n( $v * 100, 1 ) . '%';
		};
		$cards = array(
			'zero'    => array( '0件になった割合', '低いほど良い。0件の言葉は「探されているのに、いない」で確かめられます。', true ),
			'clicked' => array( '何か押された割合', '高いほど良い。検索結果に、見たい人がいたということです。', false ),
			'pos1'    => array( '1位が押された割合', '高いほど良い。いちばん上に、いちばん見たい人が出ているか。', false ),
			'good'    => array( '満足した割合', '高いほど良い。押した先のページをじっくり見た、またはSNSへ進んだ。', false ),
			'abandon' => array( '何も押さずに帰った割合', '低いほど良い。', true ),
			'mrr'     => array( '最初に押された位置（MRR）', '1に近いほど良い。1位なら1、2位なら0.5、3位なら0.33… の平均。', false ),
		);
		$il = 'interleave' === SNSCS_Learner::setting( 'mode' ) ? self::interleave_results() : null;
		?>
		<div class="wrap">
			<h1>検索の品質</h1>
			<p>検索の良し悪しを、毎日の数字で見る画面です。「何か押された割合」「満足した割合」は、行動を記録した検索（「検索アルゴリズム」の記録する割合の設定）から毎晩計算します。</p>
			<table class="widefat striped" style="max-width: 980px">
				<thead><tr><th>指標</th><th style="text-align: right">直近7日</th><th style="text-align: right">直近30日</th><th>見かた</th></tr></thead>
				<tbody>
					<tr><td>検索の回数</td><td style="text-align: right"><?php echo esc_html( number_format_i18n( $r7['searches'] ) ); ?></td><td style="text-align: right"><?php echo esc_html( number_format_i18n( $r30['searches'] ) ); ?></td><td>1ページ目を開いた回数（ロボット・運営を除く）</td></tr>
					<?php foreach ( $cards as $key => $card ) : ?>
						<?php
						$a = $r7[ $key ];
						$b = $r30[ $key ];
						$better = null !== $a && null !== $b && abs( $a - $b ) > 0.005 ? ( ( $a < $b ) === $card[2] ) : null;
						?>
						<tr>
							<td><?php echo esc_html( $card[0] ); ?></td>
							<td style="text-align: right"><?php echo esc_html( 'mrr' === $key ? ( null === $a ? '—' : number_format_i18n( $a, 3 ) ) : $pct( $a ) ); ?><?php echo null === $better ? '' : ( $better ? ' <span style="color:#008a20">▲良い</span>' : ' <span style="color:#b32d2e">▼悪い</span>' ); ?></td>
							<td style="text-align: right"><?php echo esc_html( 'mrr' === $key ? ( null === $b ? '—' : number_format_i18n( $b, 3 ) ) : $pct( $b ) ); ?></td>
							<td class="description"><?php echo esc_html( $card[1] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2>日ごとの推移（直近14日）</h2>
			<table class="widefat striped" style="max-width: 980px">
				<thead><tr><th>日</th><th style="text-align: right">検索</th><th style="text-align: right">0件</th><th style="text-align: right">押された</th><th style="text-align: right">1位</th><th style="text-align: right">満足</th><th style="text-align: right">MRR</th><th style="width: 200px">押された割合</th></tr></thead>
				<tbody>
					<?php foreach ( array_slice( $daily, 0, 14, true ) as $day => $d ) : ?>
						<?php $r = self::rates( $d ); ?>
						<tr>
							<td><?php echo esc_html( $day ); ?></td>
							<td style="text-align: right"><?php echo esc_html( number_format_i18n( $r['searches'] ) ); ?></td>
							<td style="text-align: right"><?php echo esc_html( $pct( $r['zero'] ) ); ?></td>
							<td style="text-align: right"><?php echo esc_html( $pct( $r['clicked'] ) ); ?></td>
							<td style="text-align: right"><?php echo esc_html( $pct( $r['pos1'] ) ); ?></td>
							<td style="text-align: right"><?php echo esc_html( $pct( $r['good'] ) ); ?></td>
							<td style="text-align: right"><?php echo esc_html( null === $r['mrr'] ? '—' : number_format_i18n( $r['mrr'], 3 ) ); ?></td>
							<td><span style="display: block; height: 10px; border-radius: 5px; background: #e5e7eb"><span style="display: block; height: 10px; border-radius: 5px; background: #1B27B0; width: <?php echo esc_attr( (string) round( ( $r['clicked'] ?? 0 ) * 100 ) ); ?>%"></span></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2>並べ方を混ぜて比べる（インターリーブ）</h2>
			<?php if ( null === $il ) : ?>
				<p>いまは使っていません。「検索アルゴリズム」の画面で「混ぜて比べる」を選ぶと、1ページ目を、いまの並び（A）と学習した並び（B）から交互に選んで出し、どちらから来た人が押されたかで比べます。訪問者を半分に分けるA/Bテストより、ずっと少ない検索数で良し悪しが分かります。</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width: 640px">
					<thead><tr><th>結果（直近14日）</th><th style="text-align: right">検索の数</th></tr></thead>
					<tbody>
						<tr><td>A（いまの並び）の勝ち</td><td style="text-align: right"><?php echo esc_html( number_format_i18n( $il['a'] ) ); ?></td></tr>
						<tr><td>B（学習した並び）の勝ち</td><td style="text-align: right"><?php echo esc_html( number_format_i18n( $il['b'] ) ); ?></td></tr>
						<tr><td>引き分け</td><td style="text-align: right"><?php echo esc_html( number_format_i18n( $il['tie'] ) ); ?></td></tr>
					</tbody>
				</table>
				<p>
					<?php if ( $il['z'] >= 1.96 ) : ?>
						<strong>B の方が良い（偶然ではない差: 95%）。</strong>
						<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>" style="display: inline">
							<input type="hidden" name="action" value="snscs_engine_run"><?php wp_nonce_field( 'snscs_engine_run', 'snscs_nonce' ); ?>
							<button class="button button-primary" name="do" value="adopt_b">B を採用する</button>
						</form>
					<?php elseif ( $il['z'] <= -1.96 ) : ?>
						A の方が良い結果です。学習した並びは使わない方がよさそうです。
					<?php else : ?>
						まだはっきりした差はありません（z = <?php echo esc_html( number_format_i18n( $il['z'], 2 ) ); ?>）。
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
