<?php
/**
 * おすすめの発信者・似ている発信者。どちらも総合点（SNSCS_Scores）の高い順。
 *
 *   [sns_recommended count="6" sns="instagram" genre="ジャンルのスラッグ" title="見出し"]
 *     トップページなどに「おすすめの発信者」を出す。
 *   発信者ページの下には「似ている発信者」（同じジャンル、または同じSNSで活動している人）を自動で出す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Recommend {

	public static function init() {
		add_shortcode( 'sns_recommended', array( __CLASS__, 'shortcode' ) );
	}

	public static function shortcode( $atts ) {
		$atts   = shortcode_atts( array( 'count' => 6, 'sns' => '', 'genre' => '', 'title' => 'おすすめの発信者' ), $atts, 'sns_recommended' );
		$result = SNSCS_Search::run(
			array(
				'platform' => $atts['sns'],
				'genre'    => $atts['genre'],
				'sort'     => 'recommended',
				'per_page' => min( 24, max( 1, (int) $atts['count'] ) ),
			)
		);
		return self::render( sanitize_text_field( $atts['title'] ), $result['items'] );
	}

	/**
	 * 似ている発信者: 同じジャンル、または同じSNSで活動している人を、総合点の高い順に。
	 *
	 * @return int[] 発信者ID。
	 */
	public static function similar_ids( $creator_id, $limit = 4 ) {
		global $wpdb;

		$genre_tt  = wp_get_object_terms( $creator_id, SNSCS_Post_Types::TAX_GENRE, array( 'fields' => 'tt_ids' ) );
		$platforms = array_values( array_unique( wp_list_pluck( SNSCS_Accounts::get_for_creator( $creator_id ), 'platform' ) ) );

		$conditions  = array();
		$genre_first = '';
		$params      = array( SNSCS_Post_Types::POST_TYPE, $creator_id );
		if ( $genre_tt && ! is_wp_error( $genre_tt ) ) {
			$same_genre   = "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr WHERE tr.object_id = p.ID AND tr.term_taxonomy_id IN (" . implode( ',', array_map( 'intval', $genre_tt ) ) . '))';
			$conditions[] = $same_genre;
			// 同じジャンルの人を先に並べる。
			$genre_first = "({$same_genre}) DESC, ";
		}
		if ( $platforms ) {
			$accounts     = SNSCS_Accounts::table();
			$conditions[] = "EXISTS (SELECT 1 FROM {$accounts} a WHERE a.creator_id = p.ID AND a.platform IN (" . implode( ',', array_fill( 0, count( $platforms ), '%s' ) ) . '))';
			$params       = array_merge( $params, $platforms );
		}
		if ( ! $conditions ) {
			return array();
		}
		$params[] = $limit;

		$stats = SNSCS_Scores::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$sql = "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$stats} st ON st.creator_id = p.ID
			WHERE p.post_type = %s AND p.post_status = 'publish' AND p.ID <> %d AND (" . implode( ' OR ', $conditions ) . ")
			ORDER BY {$genre_first}COALESCE(st.score, 0) DESC, p.ID DESC LIMIT %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
	}

	public static function similar_html( $creator_id ) {
		$ids = self::similar_ids( $creator_id );
		if ( ! $ids ) {
			return '';
		}
		_prime_post_caches( $ids );
		$accounts = SNSCS_Accounts::get_for_creators( $ids );
		$items    = array();
		foreach ( $ids as $id ) {
			$items[] = SNSCS_Search::format_creator( get_post( $id ), isset( $accounts[ $id ] ) ? $accounts[ $id ] : array() );
		}
		return self::render( '似ている発信者', $items );
	}

	/**
	 * 発信者のカード一覧。
	 *
	 * @param string $heading 見出し。
	 * @param array  $items   SNSCS_Search::format_creator() の配列。
	 * @return string
	 */
	public static function render( $heading, array $items ) {
		if ( ! $items ) {
			return '';
		}
		wp_enqueue_style( 'snscs' );
		ob_start();
		?>
		<section class="snscs-reco">
			<h2 class="snscs-reco__title"><?php echo esc_html( $heading ); ?></h2>
			<ul class="snscs-reco__list">
				<?php foreach ( $items as $item ) : ?>
					<?php $main = $item['accounts'] ? $item['accounts'][0] : null; ?>
					<li>
						<a href="<?php echo esc_url( $item['url'] ); ?>">
							<?php if ( $item['thumbnail'] ) : ?>
								<img class="snscs-reco__photo" src="<?php echo esc_url( $item['thumbnail'] ); ?>" alt="" width="56" height="56" loading="lazy">
							<?php else : ?>
								<span class="snscs-reco__initial" aria-hidden="true"><?php echo esc_html( mb_substr( $item['name'], 0, 1 ) ); ?></span>
							<?php endif; ?>
							<strong><?php echo esc_html( $item['name'] ); ?></strong>
							<?php if ( $item['genres'] ) : ?>
								<span class="snscs-reco__meta"><?php echo esc_html( implode( '・', $item['genres'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( $main ) : ?>
								<span class="snscs-reco__meta"><?php echo esc_html( $main['platform_label'] . ' ' . $main['followers_label'] ); ?></span>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
		return ob_get_clean();
	}
}
