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
		add_action( 'snscs_creator_withdrawn', array( __CLASS__, 'withdrawn' ), 10, 2 );
		add_action( 'snscs_article_submitted', array( __CLASS__, 'article_submitted' ) );
		add_action( 'snscs_article_approved', array( __CLASS__, 'article_approved' ) );
		add_action( 'snscs_article_rejected', array( __CLASS__, 'article_rejected' ), 10, 2 );
	}

	public static function article_submitted( $article_id ) {
		$creator_id = SNSCS_Articles::creator_of( $article_id );
		$pending    = get_post_meta( $article_id, '_snscs_pending', true );
		$title      = is_array( $pending ) ? $pending['title'] : get_the_title( $article_id );
		$body       = implode(
			"\n",
			array(
				'発信者「' . get_the_title( $creator_id ) . '」から記事の審査の申請が届きました。',
				'',
				'タイトル: ' . $title,
				'',
				'審査はこちら:',
				SNSCS_Articles_Review::url( $article_id ),
			)
		);
		wp_mail( get_option( 'admin_email' ), self::subject( '記事の審査待ち: ' . $title ), $body );
	}

	public static function article_approved( $article_id ) {
		$user = get_userdata( (int) get_post_field( 'post_author', $article_id ) );
		if ( ! $user ) {
			return;
		}
		$body = implode(
			"\n",
			array(
				'記事「' . get_the_title( $article_id ) . '」の審査が完了し、公開されました。',
				'',
				get_permalink( $article_id ),
				'',
				'SNSで紹介すると、あなたの発信者ページへの流入が増えます。',
			)
		);
		wp_mail( $user->user_email, self::subject( '記事が公開されました' ), $body );
	}

	public static function article_rejected( $article_id, $note ) {
		$user = get_userdata( (int) get_post_field( 'post_author', $article_id ) );
		if ( ! $user ) {
			return;
		}
		$body = implode(
			"\n",
			array(
				'記事「' . get_the_title( $article_id ) . '」は、このままでは公開できませんでした。',
				'',
				'【運営からのメッセージ】',
				$note,
				'',
				'マイページから記事を直して、もう一度申請できます:',
				snscs_portal_url() . '#snscs-articles',
			)
		);
		wp_mail( $user->user_email, self::subject( '記事の確認のお願い' ), $body );
	}

	/**
	 * 退会の完了を本人に、退会があったことを運営に知らせる。
	 */
	public static function withdrawn( $email, $name ) {
		$body = implode(
			"\n",
			array(
				"{$name} さん",
				'',
				'退会の手続きが完了しました。',
				'登録情報と掲載情報は、すべて削除しました。',
				'',
				'これまでご利用いただき、ありがとうございました。',
				'お心当たりのない場合は、このメールに返信してお知らせください。',
			)
		);
		wp_mail( $email, self::subject( '退会が完了しました' ), $body );

		wp_mail( get_option( 'admin_email' ), self::subject( "退会: {$name}" ), "発信者「{$name}」が退会しました。登録情報と掲載情報は削除済みです。" );
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
