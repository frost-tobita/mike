<?php
/**
 * 管理画面: 発信者の編集画面に「SNSアカウント」の入力欄を追加する。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Admin {

	const NONCE = 'snscs_save_accounts';

	/** @var array<int,array>|null 一覧画面用に先読みしたアカウント。 */
	private static $list_accounts = null;

	public static function init() {
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_block_editor' ), 10, 2 );
		add_action( 'add_meta_boxes_' . SNSCS_Post_Types::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . SNSCS_Post_Types::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'delete' ) );
		add_action( 'admin_notices', array( __CLASS__, 'skipped_notice' ) );
		add_filter( 'manage_' . SNSCS_Post_Types::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . SNSCS_Post_Types::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
	}

	/**
	 * 発信者の編集はシンプルな旧エディターで行う（入力欄が見やすく、保存時の通知も出せるため）。
	 */
	public static function disable_block_editor( $use, $post_type ) {
		return SNSCS_Post_Types::POST_TYPE === $post_type ? false : $use;
	}

	public static function add_meta_box() {
		add_meta_box( 'snscs_accounts', 'SNSアカウント', array( __CLASS__, 'render_meta_box' ), SNSCS_Post_Types::POST_TYPE, 'normal', 'high' );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$rows = SNSCS_Accounts::get_for_creator( $post->ID );
		for ( $i = 0; $i < 3; $i++ ) {
			$rows[] = array( 'platform' => '', 'handle' => '', 'url' => '', 'followers' => '' );
		}
		?>
		<p>この発信者が持っているSNSアカウントを入力してください。ハンドルが空の行は保存されません。</p>
		<table class="widefat striped" id="snscs-accounts-table" style="table-layout: fixed;">
			<thead>
				<tr>
					<th>SNS</th>
					<th>ハンドル（@なし）</th>
					<th>プロフィールURL</th>
					<th>フォロワー数</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $i => $row ) : ?>
					<?php self::render_row( $i, $row ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button" id="snscs-add-row">行を追加</button></p>
		<script type="text/html" id="snscs-row-template">
			<?php self::render_row( '__i__', array( 'platform' => '', 'handle' => '', 'url' => '', 'followers' => '' ) ); ?>
		</script>
		<script>
			( function () {
				var next = <?php echo (int) count( $rows ); ?>;
				document.getElementById( 'snscs-add-row' ).addEventListener( 'click', function () {
					var html = document.getElementById( 'snscs-row-template' ).innerHTML.replace( /__i__/g, next++ );
					document.querySelector( '#snscs-accounts-table tbody' ).insertAdjacentHTML( 'beforeend', html );
				} );
			} )();
		</script>
		<?php
	}

	private static function render_row( $i, array $row ) {
		$name = 'snscs_accounts[' . $i . ']';
		?>
		<tr>
			<td>
				<select class="widefat" name="<?php echo esc_attr( $name ); ?>[platform]">
					<option value="">選択してください</option>
					<?php foreach ( snscs_platforms() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row['platform'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[handle]" value="<?php echo esc_attr( $row['handle'] ); ?>"></td>
			<td><input type="url" class="widefat" name="<?php echo esc_attr( $name ); ?>[url]" value="<?php echo esc_attr( $row['url'] ); ?>" placeholder="https://"></td>
			<td><input type="number" class="widefat" min="0" step="1" name="<?php echo esc_attr( $name ); ?>[followers]" value="<?php echo esc_attr( $row['followers'] ); ?>"></td>
		</tr>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// 各値は SNSCS_Accounts::sanitize() で整える。
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$accounts = isset( $_POST['snscs_accounts'] ) && is_array( $_POST['snscs_accounts'] ) ? wp_unslash( $_POST['snscs_accounts'] ) : array();
		$skipped  = SNSCS_Accounts::replace_for_creator( $post_id, $accounts );

		if ( $skipped ) {
			set_transient( 'snscs_skipped_' . get_current_user_id(), $skipped, 60 );
		}
	}

	public static function delete( $post_id ) {
		if ( SNSCS_Post_Types::POST_TYPE === get_post_type( $post_id ) ) {
			SNSCS_Accounts::delete_for_creator( $post_id );
		}
	}

	public static function skipped_notice() {
		$key     = 'snscs_skipped_' . get_current_user_id();
		$skipped = get_transient( $key );
		if ( ! $skipped ) {
			return;
		}
		delete_transient( $key );
		?>
		<div class="notice notice-warning is-dismissible">
			<p>次のアカウントは別の発信者に登録済みのため保存しませんでした:</p>
			<ul>
				<?php foreach ( (array) $skipped as $label ) : ?>
					<li><?php echo esc_html( $label ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	public static function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['snscs_accounts'] = 'SNS / フォロワー';
			}
		}
		return $new;
	}

	public static function column_content( $column, $post_id ) {
		if ( 'snscs_accounts' !== $column ) {
			return;
		}

		// 一覧の全行分を最初の1回でまとめて読み込む。
		if ( null === self::$list_accounts ) {
			global $wp_query;
			self::$list_accounts = SNSCS_Accounts::get_for_creators( wp_list_pluck( (array) $wp_query->posts, 'ID' ) );
		}

		$accounts = isset( self::$list_accounts[ $post_id ] ) ? self::$list_accounts[ $post_id ] : array();
		if ( empty( $accounts ) ) {
			echo '—';
			return;
		}

		$parts = array();
		foreach ( $accounts as $account ) {
			$parts[] = esc_html( snscs_platform_label( $account['platform'] ) . ' ' . snscs_format_count( $account['followers'] ) );
		}
		echo implode( '<br>', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
