<?php
/**
 * プライバシーポリシー・利用規約・利用上の注意事項のページを作る。
 *
 * 管理画面「発信者 → 規約ページ」で運営者名などを入れてボタンを押すと、
 * includes/legal/ のひな形に値を入れた固定ページを3つ作る（最初は下書き）。
 * 作ったページは普通の固定ページなので、その後は自由に編集できる。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Legal {

	const PAGE = 'snscs-legal';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_snscs_legal_create', array( __CLASS__, 'handle_create' ) );
		add_shortcode( 'sns_legal_links', array( __CLASS__, 'links_html' ) );
	}

	/**
	 * @return array<string,array{title:string,slug:string}>
	 */
	public static function pages() {
		return array(
			'terms'   => array( 'title' => '利用規約', 'slug' => 'terms' ),
			'privacy' => array( 'title' => 'プライバシーポリシー', 'slug' => 'privacy-policy' ),
			'notes'   => array( 'title' => '利用上の注意事項', 'slug' => 'notes' ),
		);
	}

	/**
	 * 作成済みのページID（無い・ゴミ箱のものは 0）。
	 */
	public static function page_id( $key ) {
		$ids = (array) get_option( 'snscs_legal_pages', array() );
		$id  = isset( $ids[ $key ] ) ? (int) $ids[ $key ] : 0;
		return ( $id && 'page' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) ? $id : 0;
	}

	/**
	 * 公開中の規約ページへのリンク（フッターや登録画面に置く）。
	 */
	public static function links_html() {
		$links = array();
		foreach ( self::pages() as $key => $page ) {
			$id = self::page_id( $key );
			if ( $id && 'publish' === get_post_status( $id ) ) {
				$links[] = sprintf( '<a href="%s">%s</a>', esc_url( get_permalink( $id ) ), esc_html( get_the_title( $id ) ) );
			}
		}
		return $links ? '<nav class="snscs-legal-links" aria-label="規約">' . implode( '<span aria-hidden="true"> ・ </span>', $links ) . '</nav>' : '';
	}

	/**
	 * 公開中なら「<a>タイトル</a>」、そうでなければタイトルの文字だけ。
	 */
	public static function link_or_text( $key ) {
		$pages = self::pages();
		$id    = self::page_id( $key );
		if ( $id && 'publish' === get_post_status( $id ) ) {
			return sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( get_permalink( $id ) ), esc_html( $pages[ $key ]['title'] ) );
		}
		return esc_html( $pages[ $key ]['title'] );
	}

	/* --------------------------------------------------------------------
	 * 作成
	 * ------------------------------------------------------------------ */

	/**
	 * 3つのページを作る。作成済みのページは作り直さない。
	 *
	 * @param array $info    operator / address / email / court / date。
	 * @param bool  $publish すぐに公開するか（false なら下書き）。
	 * @return array<string,string> キー => 'created' または 'exists'。
	 */
	public static function create( array $info, $publish ) {
		$ids    = (array) get_option( 'snscs_legal_pages', array() );
		$result = array();
		$status = $publish ? 'publish' : 'draft';

		// 先にページだけ用意して、互いへのリンク先（ページID）を決める。
		$new = array();
		foreach ( self::pages() as $key => $page ) {
			if ( self::page_id( $key ) ) {
				$result[ $key ] = 'exists';
				continue;
			}

			$id = 0;
			// WordPress が最初から用意している「プライバシーポリシー」の下書きがあれば、それを使う。
			if ( 'privacy' === $key ) {
				$default = (int) get_option( 'wp_page_for_privacy_policy' );
				if ( $default && 'draft' === get_post_status( $default ) ) {
					$id = $default;
				}
			}
			if ( ! $id ) {
				$id = wp_insert_post(
					array(
						'post_type'   => 'page',
						'post_title'  => $page['title'],
						'post_name'   => $page['slug'],
						'post_status' => 'draft',
					)
				);
			}

			$ids[ $key ]    = (int) $id;
			$new[]          = $key;
			$result[ $key ] = 'created';
		}
		update_option( 'snscs_legal_pages', $ids );

		foreach ( $new as $key ) {
			$pages = self::pages();
			wp_update_post(
				wp_slash(
					array(
						'ID'           => $ids[ $key ],
						'post_title'   => $pages[ $key ]['title'],
						'post_name'    => $pages[ $key ]['slug'],
						'post_content' => self::fill( self::template( $key ), $info, $ids ),
						'post_status'  => $status,
					)
				)
			);
		}

		if ( ! empty( $ids['privacy'] ) ) {
			update_option( 'wp_page_for_privacy_policy', (int) $ids['privacy'] );
		}

		return $result;
	}

	private static function template( $key ) {
		return (string) require SNSCS_DIR . 'includes/legal/' . $key . '.php';
	}

	/**
	 * ひな形の %%名前%% を実際の値に置き換える。入っていない項目は【 】で目立たせる。
	 */
	public static function fill( $template, array $info, array $ids ) {
		$text = function ( $key, $placeholder ) use ( $info ) {
			$value = isset( $info[ $key ] ) ? trim( (string) $info[ $key ] ) : '';
			return esc_html( '' !== $value ? $value : '【' . $placeholder . '】' );
		};
		$url = function ( $key ) use ( $ids ) {
			// ページIDのURLは、公開後に設定が変わっても必ず正しいページに届く。
			return esc_url( empty( $ids[ $key ] ) ? home_url( '/' ) : home_url( '/?page_id=' . (int) $ids[ $key ] ) );
		};
		$date = isset( $info['date'] ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $info['date'], $m )
			? sprintf( '%d年%d月%d日', $m[1], $m[2], $m[3] )
			: '【制定日】';

		return strtr(
			$template,
			array(
				'%%operator%%'    => $text( 'operator', '運営者名' ),
				'%%address%%'     => $text( 'address', '所在地' ),
				'%%email%%'       => $text( 'email', 'お問い合わせ先メールアドレス' ),
				'%%court%%'       => $text( 'court', '運営者の所在地を管轄する地方裁判所' ),
				'%%date%%'        => esc_html( $date ),
				'%%site%%'        => esc_html( get_bloginfo( 'name' ) ),
				'%%keep_days%%'   => (int) SNSCS_Referrals::KEEP_DAYS,
				'%%inflow_days%%' => (int) SNSCS_Scores::inflow_days(),
				'%%ad_label%%'    => esc_html( SNSCS_Ads::settings()['label'] ),
				'%%privacy_url%%' => $url( 'privacy' ),
				'%%terms_url%%'   => $url( 'terms' ),
				'%%notes_url%%'   => $url( 'notes' ),
				'%%portal_url%%'  => esc_url( snscs_portal_url() ),
			)
		);
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function url() {
		return admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::PAGE );
	}

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, '規約ページ', '規約ページ', 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$info = wp_parse_args( (array) get_option( 'snscs_legal_info', array() ), array( 'operator' => '', 'address' => '', 'email' => get_option( 'admin_email' ), 'court' => '', 'date' => current_time( 'Y-m-d' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$done = isset( $_GET['snscs_done'] ) ? sanitize_key( $_GET['snscs_done'] ) : '';
		$labels = array(
			'publish' => '公開中',
			'draft'   => '下書き',
			'pending' => 'レビュー待ち',
			'private' => '非公開',
		);
		?>
		<div class="wrap">
			<h1>規約ページ</h1>
			<?php if ( 'published' === $done ) : ?>
				<div class="notice notice-success is-dismissible"><p>ページを作成して公開しました。【 】で囲まれた項目が残っていないか確認してください。</p></div>
			<?php elseif ( 'drafted' === $done ) : ?>
				<div class="notice notice-success is-dismissible"><p>ページを下書きとして作成しました。内容を確認してから公開してください。</p></div>
			<?php endif; ?>

			<p>利用規約・プライバシーポリシー・利用上の注意事項のページを、このサイトの機能（発信者の登録と審査、紹介リンク、おすすめ順、広告）に合わせた文面で作成します。</p>
			<p><strong>公開する前に、必ず内容を確認してください。</strong>事業の内容に合わせた修正や、弁護士など専門家への確認をおすすめします。</p>

			<table class="widefat striped" style="max-width: 800px; margin: 16px 0 24px">
				<thead><tr><th>ページ</th><th>状態</th><th></th></tr></thead>
				<tbody>
					<?php foreach ( self::pages() as $key => $page ) : ?>
						<?php $id = self::page_id( $key ); ?>
						<tr>
							<td><strong><?php echo esc_html( $page['title'] ); ?></strong></td>
							<td><?php echo $id ? esc_html( isset( $labels[ get_post_status( $id ) ] ) ? $labels[ get_post_status( $id ) ] : get_post_status( $id ) ) : '未作成'; ?></td>
							<td>
								<?php if ( $id ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>">編集</a> ・
									<a href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank"><?php echo 'publish' === get_post_status( $id ) ? '表示' : 'プレビュー'; ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2>ページを作成する</h2>
			<p>入力した内容が文面の中に入ります。空欄の項目は【運営者名】のように表示されるので、あとでページを編集して埋めてください。作成済みのページは作り直しません（作り直したいときは、そのページをゴミ箱に入れてから作成してください）。</p>
			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
				<input type="hidden" name="action" value="snscs_legal_create">
				<?php wp_nonce_field( 'snscs_legal_create', 'snscs_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="snscs-operator">運営者名</label></th>
						<td><input id="snscs-operator" name="operator" type="text" class="regular-text" value="<?php echo esc_attr( $info['operator'] ); ?>" placeholder="例: 株式会社〇〇 / 山田太郎"></td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-address">所在地</label></th>
						<td>
							<input id="snscs-address" name="address" type="text" class="large-text" value="<?php echo esc_attr( $info['address'] ); ?>" placeholder="例: 東京都〇〇区〇〇 1-2-3">
							<p class="description">個人で運営する場合は、「請求があれば遅滞なく開示します」と書く方法もあります。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-email">お問い合わせ先</label></th>
						<td><input id="snscs-email" name="email" type="text" class="regular-text" value="<?php echo esc_attr( $info['email'] ); ?>" placeholder="例: support@example.com"></td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-court">裁判所（もめたときの裁判の場所）</label></th>
						<td>
							<input id="snscs-court" name="court" type="text" class="regular-text" value="<?php echo esc_attr( $info['court'] ); ?>" placeholder="例: 東京地方裁判所">
							<p class="description">ふつうは、運営者の所在地を受け持つ地方裁判所にします。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-date">制定日</label></th>
						<td><input id="snscs-date" name="date" type="date" value="<?php echo esc_attr( $info['date'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row">公開</th>
						<td><label><input type="checkbox" name="publish" value="1"> 作成と同時に公開する（チェックしなければ下書きとして作成）</label></td>
					</tr>
				</table>
				<?php submit_button( '3つのページを作成' ); ?>
			</form>

			<h2>リンクの置き方</h2>
			<p>登録画面の同意欄と、検索ページ・発信者マイページの下には、公開中の規約ページへのリンクが自動で表示されます。サイト全体のフッターに置くときは、「外観 → メニュー（またはエディター）」でフッターにページを追加するか、次のショートコードを使ってください。</p>
			<p><code>[sns_legal_links]</code></p>
		</div>
		<?php
	}

	public static function handle_create() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_legal_create' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}

		$info = array();
		foreach ( array( 'operator', 'address', 'email', 'court', 'date' ) as $key ) {
			$info[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}
		update_option( 'snscs_legal_info', $info );

		$publish = ! empty( $_POST['publish'] );
		self::create( $info, $publish );

		wp_safe_redirect( add_query_arg( 'snscs_done', $publish ? 'published' : 'drafted', self::url() ) );
		exit;
	}
}
