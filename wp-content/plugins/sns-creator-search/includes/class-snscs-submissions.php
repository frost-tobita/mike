<?php
/**
 * 発信者からの「申請」（新規登録・変更）を保存し、審査結果を反映する。
 *
 * 流れ:
 *   発信者が送信 → 申請（審査待ち）として保存。公開中の内容はまだ変わらない。
 *   運営が承認   → 申請内容を発信者ページに反映して公開。
 *   運営が差し戻し → 理由を保存。発信者は直して再申請できる。
 *
 * 1人の発信者が同時に持てる審査待ちの申請は1件だけ。審査待ちの間に
 * 再送信すると、その申請の中身を新しい内容に置き換える。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Submissions {

	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_REJECTED = 'rejected';

	const TYPE_NEW    = 'new';
	const TYPE_UPDATE = 'update';

	const MAX_NAME = 50;
	const MAX_BIO  = 1000;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_submissions';
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta の書式に合わせ、各行の区切りや空白は崩さないこと。
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  creator_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(20) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  data longtext NOT NULL,
  note text NOT NULL,
  reviewer_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  reviewed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status_created (status,created_at),
  KEY user_status (user_id,status)
) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * @return array|null 申請1件（data は配列に戻したもの）。
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ) );
	}

	/**
	 * ユーザーの最新の申請（状態を問わない）。
	 */
	public static function latest_for_user( $user_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT 1", $user_id ), ARRAY_A ) );
	}

	public static function pending_for_user( $user_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND status = %s ORDER BY id DESC LIMIT 1", $user_id, self::STATUS_PENDING ), ARRAY_A ) );
	}

	public static function count_by_status( $status ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
	}

	/**
	 * 状態ごとの一覧。審査待ちは古い順（先に来たものから審査）、それ以外は新しい順。
	 *
	 * @return array{items:array,total:int}
	 */
	public static function query( $status, $page = 1, $per_page = 20 ) {
		global $wpdb;

		$table  = self::table();
		$order  = self::STATUS_PENDING === $status ? 'ASC' : 'DESC';
		$offset = ( max( 1, (int) $page ) - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at {$order}, id {$order} LIMIT %d, %d", $status, $offset, $per_page ), ARRAY_A );
		// phpcs:enable

		return array(
			'items' => array_map( array( __CLASS__, 'hydrate' ), $rows ),
			'total' => self::count_by_status( $status ),
		);
	}

	/**
	 * 発信者からの送信内容を保存する。
	 *
	 * @param int   $user_id 発信者ユーザー。
	 * @param array $data    sanitize() 済みの内容。
	 * @return int 申請ID。
	 */
	public static function submit( $user_id, array $data ) {
		global $wpdb;

		$table      = self::table();
		$creator_id = self::creator_id_for_user( $user_id );
		$pending    = self::pending_for_user( $user_id );
		$now        = current_time( 'mysql', true );

		if ( $pending ) {
			$wpdb->update(
				$table,
				array( 'data' => wp_json_encode( $data ), 'created_at' => $now ),
				array( 'id' => $pending['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return (int) $pending['id'];
		}

		$wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'creator_id' => $creator_id,
				'type'       => $creator_id ? self::TYPE_UPDATE : self::TYPE_NEW,
				'status'     => self::STATUS_PENDING,
				'data'       => wp_json_encode( $data ),
				'note'       => '',
				'created_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		$id = (int) $wpdb->insert_id;

		do_action( 'snscs_submission_created', $id );

		return $id;
	}

	/**
	 * 承認して、申請内容を発信者ページに反映・公開する。
	 *
	 * @return true|WP_Error
	 */
	public static function approve( $id, $reviewer_id ) {
		global $wpdb;

		$row = self::get( $id );
		if ( ! $row || self::STATUS_PENDING !== $row['status'] ) {
			return new WP_Error( 'snscs_not_pending', 'この申請は審査待ちではありません。' );
		}

		$data       = $row['data'];
		$creator_id = self::creator_id_for_user( $row['user_id'] );

		$conflicts = self::find_conflicts( $data['accounts'], $creator_id );
		if ( $conflicts ) {
			return new WP_Error( 'snscs_conflict', '次のアカウントは別の発信者が掲載中のため承認できません: ' . implode( '、', $conflicts ) );
		}

		$postarr = array(
			'post_type'    => SNSCS_Post_Types::POST_TYPE,
			'post_title'   => $data['name'],
			'post_content' => $data['bio'],
			'post_status'  => 'publish',
			'post_author'  => $row['user_id'],
		);
		if ( $creator_id ) {
			$postarr['ID'] = $creator_id;
			$result        = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$result = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$creator_id = (int) $result;
		update_user_meta( $row['user_id'], 'snscs_creator_id', $creator_id );

		wp_set_object_terms( $creator_id, $data['genres'], SNSCS_Post_Types::TAX_GENRE );
		wp_set_object_terms( $creator_id, $data['regions'], SNSCS_Post_Types::TAX_REGION );

		if ( $data['thumbnail_id'] ) {
			set_post_thumbnail( $creator_id, $data['thumbnail_id'] );
		} else {
			delete_post_thumbnail( $creator_id );
		}

		$accounts = array();
		foreach ( $data['accounts'] as $platform => $account ) {
			$accounts[] = array(
				'platform'  => $platform,
				'handle'    => $account['handle'],
				'url'       => snscs_profile_url( $platform, $account['handle'] ),
				'followers' => $account['followers'],
			);
		}
		SNSCS_Accounts::replace_for_creator( $creator_id, $accounts );

		$wpdb->update(
			self::table(),
			array(
				'status'      => self::STATUS_APPROVED,
				'creator_id'  => $creator_id,
				'reviewer_id' => $reviewer_id,
				'reviewed_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);

		do_action( 'snscs_submission_approved', $id );

		return true;
	}

	/**
	 * 差し戻す。公開中の内容はそのまま。
	 *
	 * @return true|WP_Error
	 */
	public static function reject( $id, $reviewer_id, $note ) {
		global $wpdb;

		$row = self::get( $id );
		if ( ! $row || self::STATUS_PENDING !== $row['status'] ) {
			return new WP_Error( 'snscs_not_pending', 'この申請は審査待ちではありません。' );
		}
		$note = trim( sanitize_textarea_field( $note ) );
		if ( '' === $note ) {
			return new WP_Error( 'snscs_note_required', '差し戻しの理由を入力してください。発信者に伝わります。' );
		}

		$wpdb->update(
			self::table(),
			array(
				'status'      => self::STATUS_REJECTED,
				'note'        => $note,
				'reviewer_id' => $reviewer_id,
				'reviewed_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		do_action( 'snscs_submission_rejected', $id );

		return true;
	}

	/**
	 * ユーザーに紐づく発信者ページのID（まだ無ければ 0）。
	 */
	public static function creator_id_for_user( $user_id ) {
		$creator_id = (int) get_user_meta( $user_id, 'snscs_creator_id', true );
		return ( $creator_id && SNSCS_Post_Types::POST_TYPE === get_post_type( $creator_id ) ) ? $creator_id : 0;
	}

	/**
	 * 公開中の発信者ページの内容を、申請と同じ形で取り出す（入力欄の初期値と差分表示に使う）。
	 */
	public static function creator_data( $creator_id ) {
		$post = get_post( $creator_id );
		if ( ! $post ) {
			return self::empty_data();
		}

		$accounts = array();
		foreach ( SNSCS_Accounts::get_for_creator( $creator_id ) as $account ) {
			// 入力欄はSNSごとに1つ。複数ある場合はフォロワーの多い方を使う。
			if ( ! isset( $accounts[ $account['platform'] ] ) ) {
				$accounts[ $account['platform'] ] = array(
					'handle'    => $account['handle'],
					'followers' => (int) $account['followers'],
				);
			}
		}

		return array(
			'name'         => $post->post_title,
			'bio'          => $post->post_content,
			'genres'       => wp_get_object_terms( $creator_id, SNSCS_Post_Types::TAX_GENRE, array( 'fields' => 'ids' ) ),
			'regions'      => wp_get_object_terms( $creator_id, SNSCS_Post_Types::TAX_REGION, array( 'fields' => 'ids' ) ),
			'thumbnail_id' => (int) get_post_thumbnail_id( $creator_id ),
			'accounts'     => $accounts,
		);
	}

	public static function empty_data() {
		return array(
			'name'         => '',
			'bio'          => '',
			'genres'       => array(),
			'regions'      => array(),
			'thumbnail_id' => 0,
			'accounts'     => array(),
		);
	}

	/**
	 * フォームの入力を検証して、申請データの形に整える。
	 *
	 * @param array $raw     入力値（wp_unslash 済み）。
	 * @param int   $user_id 送信したユーザー（重複チェックで自分の掲載分を除くため）。
	 * @return array{0:array,1:string[]} [整えたデータ, エラーメッセージ]
	 */
	public static function sanitize( array $raw, $user_id ) {
		$errors = array();
		$data   = self::empty_data();

		$data['name'] = trim( sanitize_text_field( isset( $raw['name'] ) ? $raw['name'] : '' ) );
		if ( '' === $data['name'] ) {
			$errors[] = '表示名を入力してください。';
		} elseif ( mb_strlen( $data['name'] ) > self::MAX_NAME ) {
			$errors[] = sprintf( '表示名は%d文字以内にしてください。', self::MAX_NAME );
		}

		$data['bio'] = trim( sanitize_textarea_field( isset( $raw['bio'] ) ? $raw['bio'] : '' ) );
		if ( mb_strlen( $data['bio'] ) > self::MAX_BIO ) {
			$errors[] = sprintf( '紹介文は%d文字以内にしてください。', self::MAX_BIO );
		}

		$data['genres']  = self::valid_term_ids( isset( $raw['genres'] ) ? $raw['genres'] : array(), SNSCS_Post_Types::TAX_GENRE );
		$data['regions'] = self::valid_term_ids( isset( $raw['regions'] ) ? $raw['regions'] : array(), SNSCS_Post_Types::TAX_REGION );
		if ( empty( $data['genres'] ) ) {
			$errors[] = 'ジャンルを1つ以上選んでください。';
		}

		$data['thumbnail_id'] = isset( $raw['thumbnail_id'] ) ? absint( $raw['thumbnail_id'] ) : 0;

		$raw_accounts = isset( $raw['accounts'] ) && is_array( $raw['accounts'] ) ? $raw['accounts'] : array();
		foreach ( snscs_platforms() as $platform => $label ) {
			$input  = isset( $raw_accounts[ $platform ] ) && is_array( $raw_accounts[ $platform ] ) ? $raw_accounts[ $platform ] : array();
			$handle = self::normalize_handle( $platform, isset( $input['handle'] ) ? (string) $input['handle'] : '' );
			if ( '' === $handle ) {
				continue;
			}
			if ( ! self::is_valid_handle( $platform, $handle ) ) {
				$errors[] = sprintf( '%sのハンドル名「%s」は使えない文字を含んでいます。', $label, $handle );
				continue;
			}
			$data['accounts'][ $platform ] = array(
				'handle'    => $handle,
				'followers' => isset( $input['followers'] ) ? absint( str_replace( array( ',', '，' ), '', (string) $input['followers'] ) ) : 0,
			);
		}

		if ( empty( $data['accounts'] ) ) {
			$errors[] = 'SNSアカウントを1つ以上入力してください。';
		} else {
			$conflicts = self::find_conflicts( $data['accounts'], self::creator_id_for_user( $user_id ) );
			if ( $conflicts ) {
				$errors[] = '次のアカウントは別の発信者が掲載中です。お心当たりがない場合は運営までご連絡ください: ' . implode( '、', $conflicts );
			}
		}

		return array( $data, $errors );
	}

	/**
	 * 「@abc」やプロフィールURLが貼り付けられても、ハンドル名だけを取り出す。
	 */
	public static function normalize_handle( $platform, $value ) {
		$value = trim( sanitize_text_field( $value ) );

		if ( preg_match( '#^https?://#i', $value ) ) {
			$parts = wp_parse_url( $value );
			if ( 'substack' === $platform && ! empty( $parts['host'] ) && preg_match( '/^([a-z0-9-]+)\.substack\.com$/i', $parts['host'], $m ) ) {
				return strtolower( $m[1] );
			}
			$segments = array_values( array_filter( explode( '/', isset( $parts['path'] ) ? $parts['path'] : '' ) ) );
			$value    = $segments ? $segments[0] : '';
		}

		return ltrim( $value, '@' );
	}

	public static function is_valid_handle( $platform, $handle ) {
		$pattern = 'substack' === $platform ? '/^[a-z0-9-]{1,63}$/i' : '/^[A-Za-z0-9._-]{1,100}$/';
		return (bool) preg_match( $pattern, $handle );
	}

	/**
	 * 他の発信者がすでに掲載しているアカウントを探す。
	 *
	 * @param array $accounts           SNSのキー => [handle, followers]。
	 * @param int   $exclude_creator_id 自分の発信者ページ（除外する）。
	 * @return string[] 「SNS名: ハンドル」の一覧。
	 */
	public static function find_conflicts( array $accounts, $exclude_creator_id ) {
		global $wpdb;

		$table     = SNSCS_Accounts::table();
		$conflicts = array();
		foreach ( $accounts as $platform => $account ) {
			$owner = (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT creator_id FROM {$table} WHERE platform = %s AND handle = %s", $platform, $account['handle'] )
			);
			if ( $owner && $owner !== (int) $exclude_creator_id ) {
				$conflicts[] = snscs_platform_label( $platform ) . ': ' . snscs_display_handle( $platform, $account['handle'] );
			}
		}

		return $conflicts;
	}

	/**
	 * 申請データを、人が読める形の項目一覧にする（審査画面・発信者画面で共通）。
	 *
	 * @return array<string,string> 項目名 => 表示文字列。
	 */
	public static function describe( array $data ) {
		$term_names = function ( $ids, $taxonomy ) {
			$names = array();
			foreach ( (array) $ids as $id ) {
				$term = get_term( (int) $id, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					$names[] = $term->name;
				}
			}
			return $names ? implode( '、', $names ) : '（なし）';
		};

		$rows = array(
			'表示名'   => '' !== $data['name'] ? $data['name'] : '（なし）',
			'紹介文'   => '' !== $data['bio'] ? $data['bio'] : '（なし）',
			'ジャンル' => $term_names( $data['genres'], SNSCS_Post_Types::TAX_GENRE ),
			'地域'     => $term_names( $data['regions'], SNSCS_Post_Types::TAX_REGION ),
			'写真'     => $data['thumbnail_id'] ? (string) $data['thumbnail_id'] : '',
		);

		foreach ( snscs_platforms() as $platform => $label ) {
			$rows[ $label ] = isset( $data['accounts'][ $platform ] )
				? snscs_display_handle( $platform, $data['accounts'][ $platform ]['handle'] ) . '（' . number_format_i18n( $data['accounts'][ $platform ]['followers'] ) . '人）'
				: '（なし）';
		}

		return $rows;
	}

	/**
	 * @return array<string,string> 状態のキー => 表示名。
	 */
	public static function status_labels() {
		return array(
			self::STATUS_PENDING  => '審査待ち',
			self::STATUS_APPROVED => '承認済み',
			self::STATUS_REJECTED => '差し戻し',
		);
	}

	private static function valid_term_ids( $ids, $taxonomy ) {
		$valid = array();
		foreach ( (array) $ids as $id ) {
			$term = get_term( absint( $id ), $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$valid[] = (int) $term->term_id;
			}
		}
		return array_values( array_unique( $valid ) );
	}

	private static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'id', 'user_id', 'creator_id', 'reviewer_id' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		$data        = json_decode( $row['data'], true );
		$row['data'] = array_merge( self::empty_data(), is_array( $data ) ? $data : array() );
		return $row;
	}
}
