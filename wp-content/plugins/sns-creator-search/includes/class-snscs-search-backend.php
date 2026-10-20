<?php
/**
 * 検索エンジン部分の「差し替え口」。
 *
 * 最初は WordPress と同じデータベース（MySQL）で検索する。
 * 件数が大きくなったら OpenSearch などの検索専用ソフトを使う実装を作り、
 * `snscs_search_backend` フィルターで差し替える。画面側は変更不要。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface SNSCS_Search_Backend {

	/**
	 * 条件に合う発信者を探す。
	 *
	 * @param array $args SNSCS_Search::normalize_args() で整えた検索条件。
	 * @return array{ids:int[],total:int} 現在ページの発信者IDと、全体の件数。
	 */
	public function search( array $args );
}
