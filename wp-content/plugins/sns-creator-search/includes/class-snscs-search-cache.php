<?php
/**
 * 検索の結果を少しのあいだ覚えておく（1.21。発信者が増えても速いままにするため）。
 *
 * - 覚えるのは「並べた順番（発信者IDの並び）と人数」「絞り込みの件数」だけ。表示のたびの記録（学習用）は毎回行う
 * - 一人ひとりに合わせた並び（好み・さっき見た人）や、並べ方を混ぜて比べている検索は覚えない
 * - 5分たつか、点数の計算し直し・発信者の掲載や変更があったら、まとめて古いものとして扱う（世代の番号を進める）
 * - サーバーに速いキャッシュ（Redis・Memcached など）があればそれを使う。無ければ一時保存（transient）を使い、
 *   データベースの行を増やしすぎないよう、時間のかかる大きな検索（200人以上）のときだけ覚える
 * - 索引の入れ直しは、同じ処理の中で何度呼ばれても最後に1回だけ行う（SNSCS_Index::queue）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Search_Cache {

	const TTL        = 300;
	const GEN_OPTION = 'snscs_search_gen';

	private static $bump = false;

	public static function init() {
		add_action( 'shutdown', array( __CLASS__, 'flush_bump' ) );
		add_action( 'save_post_' . SNSCS_Post_Types::POST_TYPE, array( __CLASS__, 'bump' ) );
		add_action( 'snscs_submission_approved', array( __CLASS__, 'bump' ) );
		add_action( 'update_option_snscs_scores_updated', array( __CLASS__, 'bump' ) );
		add_action( 'add_option_snscs_scores_updated', array( __CLASS__, 'bump' ) );
	}

	public static function generation() {
		return (int) get_option( self::GEN_OPTION, 1 );
	}

	/**
	 * 覚えたものを古いものとして扱う（同じ処理の中では最後に1回だけ）。
	 */
	public static function bump() {
		self::$bump = true;
	}

	public static function flush_bump() {
		if ( self::$bump ) {
			self::$bump = false;
			update_option( self::GEN_OPTION, self::generation() + 1, false );
		}
	}

	/**
	 * 速いキャッシュがあるか（無ければ大きな検索だけ覚える）。
	 */
	public static function fast() {
		return wp_using_ext_object_cache();
	}

	private static function key( $group, $parts ) {
		return 'snscs_sc_' . $group . '_' . md5( wp_json_encode( array( $parts, self::generation() ) ) );
	}

	public static function get( $group, $parts ) {
		$key = self::key( $group, $parts );
		$hit = self::fast() ? wp_cache_get( $key, 'snscs_search' ) : get_transient( $key );
		return false === $hit ? null : $hit;
	}

	/**
	 * @param int $size 結果の大きさ（人数）。速いキャッシュが無いときは、200人以上のときだけ覚える。
	 */
	public static function set( $group, $parts, $value, $size = 0 ) {
		if ( ! self::fast() && $size < 200 ) {
			return;
		}
		$key = self::key( $group, $parts );
		if ( self::fast() ) {
			wp_cache_set( $key, $value, 'snscs_search', self::TTL );
		} else {
			set_transient( $key, $value, self::TTL );
		}
	}
}
