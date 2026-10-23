<?php
/**
 * SNSアカウント専用テーブルの作成と読み書き。
 *
 * アカウント情報は WordPress 標準の postmeta ではなく専用テーブルに置く。
 * 「SNSの種類」「フォロワー数」での絞り込み・並び替えを、件数が増えても
 * 索引（インデックス）を使って速く行えるようにするため。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Accounts {

	/**
	 * @return string テーブル名（接頭辞付き）。
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_accounts';
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta の書式に合わせ、各行の区切りや空白は崩さないこと。
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  creator_id bigint(20) unsigned NOT NULL,
  platform varchar(32) NOT NULL,
  handle varchar(191) NOT NULL,
  url varchar(255) NOT NULL DEFAULT '',
  followers bigint(20) unsigned NOT NULL DEFAULT 0,
  verified tinyint(1) unsigned NOT NULL DEFAULT 0,
  synced_at datetime DEFAULT NULL,
  external_id varchar(100) NOT NULL DEFAULT '',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY platform_handle (platform,handle),
  KEY creator_id (creator_id),
  KEY platform_followers (platform,followers),
  KEY followers (followers)
) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * 1人の発信者のアカウント一覧を取得する。
	 *
	 * @param int $creator_id 発信者の投稿ID。
	 * @return array<int,array>
	 */
	public static function get_for_creator( $creator_id ) {
		$map = self::get_for_creators( array( $creator_id ) );
		return isset( $map[ $creator_id ] ) ? $map[ $creator_id ] : array();
	}

	/**
	 * 複数の発信者のアカウントを1回の問い合わせでまとめて取得する。
	 *
	 * @param int[] $creator_ids 発信者の投稿ID。
	 * @return array<int,array<int,array>> 発信者ID => アカウント配列（フォロワー数の多い順）。
	 */
	public static function get_for_creators( array $creator_ids ) {
		global $wpdb;

		$creator_ids = array_values( array_filter( array_map( 'absint', $creator_ids ) ) );
		if ( empty( $creator_ids ) ) {
			return array();
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $creator_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE creator_id IN ({$placeholders}) ORDER BY followers DESC", $creator_ids ), ARRAY_A );

		$map = array();
		foreach ( $rows as $row ) {
			$row['creator_id'] = (int) $row['creator_id'];
			$row['followers']  = (int) $row['followers'];
			$map[ $row['creator_id'] ][] = $row;
		}

		return $map;
	}

	/**
	 * 発信者のアカウントを丸ごと入れ替える。
	 *
	 * @param int   $creator_id 発信者の投稿ID。
	 * @param array $accounts   platform / handle / url / followers を持つ配列のリスト。
	 * @return string[] 他の発信者が既に登録済みで保存できなかったアカウント（「SNS名: ハンドル」）。
	 */
	public static function replace_for_creator( $creator_id, array $accounts ) {
		global $wpdb;

		$table      = self::table();
		$creator_id = absint( $creator_id );
		$skipped    = array();

		// 公式APIで確認済みの数（SNSCS_Sync）は、申告の数で上書きしないよう覚えておく。
		$verified = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT platform, handle, followers, verified, synced_at, external_id FROM {$table} WHERE creator_id = %d AND verified > 0", $creator_id ), ARRAY_A ) as $row ) {
			$verified[ $row['platform'] . '|' . strtolower( $row['handle'] ) ] = $row;
		}

		$wpdb->delete( $table, array( 'creator_id' => $creator_id ), array( '%d' ) );

		foreach ( $accounts as $account ) {
			$account = self::sanitize( $account );
			if ( null === $account ) {
				continue;
			}

			$owner = $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT creator_id FROM {$table} WHERE platform = %s AND handle = %s", $account['platform'], $account['handle'] )
			);
			if ( null !== $owner ) {
				$skipped[] = snscs_platform_label( $account['platform'] ) . ': ' . $account['handle'];
				continue;
			}

			$keep = isset( $verified[ $account['platform'] . '|' . strtolower( $account['handle'] ) ] ) ? $verified[ $account['platform'] . '|' . strtolower( $account['handle'] ) ] : null;
			$wpdb->insert(
				$table,
				array(
					'creator_id'  => $creator_id,
					'platform'    => $account['platform'],
					'handle'      => $account['handle'],
					'url'         => $account['url'],
					'followers'   => $keep ? (int) $keep['followers'] : $account['followers'],
					'verified'    => $keep ? (int) $keep['verified'] : 0,
					'synced_at'   => $keep ? $keep['synced_at'] : null,
					'external_id' => $keep ? $keep['external_id'] : '',
					'updated_at'  => current_time( 'mysql', true ),
				),
				array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
			);
		}

		do_action( 'snscs_accounts_replaced', $creator_id );
		return $skipped;
	}

	public static function delete_for_creator( $creator_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'creator_id' => absint( $creator_id ) ), array( '%d' ) );
	}

	/**
	 * ハンドル名をそろえる（先頭の @ と前後の空白を外す）。
	 */
	public static function normalize_handle( $platform, $handle ) {
		unset( $platform );
		return mb_substr( ltrim( trim( (string) $handle ), '@' ), 0, 191 );
	}

	/**
	 * 入力値を整える。保存できない内容なら null。
	 *
	 * @param array $account 入力値。
	 * @return array|null
	 */
	public static function sanitize( $account ) {
		if ( ! is_array( $account ) ) {
			return null;
		}

		$platform = isset( $account['platform'] ) ? sanitize_key( $account['platform'] ) : '';
		$handle   = isset( $account['handle'] ) ? trim( sanitize_text_field( $account['handle'] ) ) : '';
		$handle   = ltrim( $handle, '@' );

		if ( '' === $handle || ! array_key_exists( $platform, snscs_platforms() ) ) {
			return null;
		}

		return array(
			'platform'  => $platform,
			'handle'    => mb_substr( $handle, 0, 191 ),
			'url'       => isset( $account['url'] ) ? esc_url_raw( $account['url'] ) : '',
			'followers' => isset( $account['followers'] ) ? absint( str_replace( ',', '', (string) $account['followers'] ) ) : 0,
		);
	}
}
