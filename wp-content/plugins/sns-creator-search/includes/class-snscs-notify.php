<?php
/**
 * 審査に関するお知らせメール。
 *   申請が届いた → 運営（サイト管理者のメールアドレス）へ
 *   承認・差し戻し → 発信者へ
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Notify {

	public static function init() {
		add_action( 'snscs_submission_created', array( __CLASS__, 'to_admin' ) );
		add_action( 'snscs_submission_approved', array( __CLASS__, 'approved' ) );
		add_action( 'snscs_submission_rejected', array( __CLASS__, 'rejected' ) );
	}

	public static function to_admin( $id ) {
		$row = SNSCS_Submissions::get( $id );
		if ( ! $row ) {
			return;
		}

		$type = SNSCS_Submissions::TYPE_NEW === $row['type'] ? '新規登録' : '登録内容の変更';
		$body = implode(
			"\n",
			array(
				"発信者から{$type}の申請が届きました。",
				'',
				'表示名: ' . $row['data']['name'],
				'',
				'審査はこちら:',
				SNSCS_Review_Admin::detail_url( $id ),
			)
		);

		wp_mail( get_option( 'admin_email' ), self::subject( "審査待ち: {$row['data']['name']}（{$type}）" ), $body );
	}

	public static function approved( $id ) {
		$row  = SNSCS_Submissions::get( $id );
		$user = $row ? get_userdata( $row['user_id'] ) : null;
		if ( ! $user ) {
			return;
		}

		$body = implode(
			"\n",
			array(
				"{$row['data']['name']} さん",
				'',
				'審査が完了し、登録内容が掲載されました。',
				'',
				'掲載ページ:',
				get_permalink( $row['creator_id'] ),
				'',
				'登録内容はいつでもマイページから変更を申請できます:',
				snscs_portal_url(),
			)
		);

		wp_mail( $user->user_email, self::subject( '掲載されました' ), $body );
	}

	public static function rejected( $id ) {
		$row  = SNSCS_Submissions::get( $id );
		$user = $row ? get_userdata( $row['user_id'] ) : null;
		if ( ! $user ) {
			return;
		}

		$body = implode(
			"\n",
			array(
				"{$row['data']['name']} さん",
				'',
				'申請内容を確認しましたが、このままでは掲載できませんでした。',
				'',
				'【運営からのメッセージ】',
				$row['note'],
				'',
				'マイページから内容を直して、もう一度申請できます:',
				snscs_portal_url(),
			)
		);

		wp_mail( $user->user_email, self::subject( '申請内容の確認のお願い' ), $body );
	}

	private static function subject( $text ) {
		return '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . $text;
	}
}
