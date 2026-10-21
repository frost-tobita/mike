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

	/**
	 * SNSごとの該当人数を数える（インデックスのタブに表示する数）。
	 * $args['platform'] は無視し、それ以外の条件で数える。
	 *
	 * @param array $args SNSCS_Search::normalize_args() で整えた検索条件。
	 * @return array<string,int> SNSのキー => 人数。キー '' は全SNS合計の人数（重複なし）。
	 */
	public function count_by_platform( array $args );
}
