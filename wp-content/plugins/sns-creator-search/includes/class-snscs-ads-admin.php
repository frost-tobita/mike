<?php
/**
 * 管理画面「広告枠」: 広告の登録・編集、一覧（表示回数・クリック数）、設定。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Ads_Admin {

	const NONCE         = 'snscs_save_ad';
	const SETTINGS_PAGE = 'snscs-ad-settings';

	public static function init() {
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_block_editor' ), 10, 2 );
		add_action( 'add_meta_boxes_' . SNSCS_Ads::POST_TYPE, array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . SNSCS_Ads::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'manage_' . SNSCS_Ads::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . SNSCS_Ads::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_post_snscs_ad_settings', array( __CLASS__, 'save_settings' ) );
	}

	public static function disable_block_editor( $use, $post_type ) {
		return SNSCS_Ads::POST_TYPE === $post_type ? false : $use;
	}

	public static function title_placeholder( $text, $post ) {
		return SNSCS_Ads::POST_TYPE === $post->post_type ? '管理用の名前（例: 秋のキャンプ用品セール）' : $text;
	}

	public static function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( $screen && SNSCS_Ads::POST_TYPE === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_media();
		}
	}

	/* --------------------------------------------------------------------
	 * 編集画面
	 * ------------------------------------------------------------------ */

	public static function add_meta_boxes() {
		add_meta_box( 'snscs_ad', '広告の内容', array( __CLASS__, 'render_meta_box' ), SNSCS_Ads::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'snscs_ad_help', '使い方', array( __CLASS__, 'render_help' ), SNSCS_Ads::POST_TYPE, 'side', 'default' );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );
		$ad    = SNSCS_Ads::get( $post->ID );
		$image = $ad['image_id'] ? wp_get_attachment_image_url( $ad['image_id'], 'medium' ) : '';
		if ( 'auto-draft' === $post->post_status ) {
			$ad['slots'] = array( 'search_top' );
		}
		?>
		<table class="form-table snscs-ad-form" role="presentation">
			<tr>
				<th scope="row">広告の種類</th>
				<td>
					<fieldset>
						<?php foreach ( SNSCS_Ads::types() as $key => $label ) : ?>
							<label style="display: block; margin-bottom: 6px">
								<input type="radio" name="snscs_ad[type]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $ad['type'], $key ); ?>>
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				</td>
			</tr>

			<tr class="snscs-ad-field" data-types="content">
				<th scope="row">販売サイト</th>
				<td>
					<select name="snscs_ad[platform]">
						<?php foreach ( SNSCS_Ads::content_platforms() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $ad['platform'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Brain・Tips などで商品ページを開き、アフィリエイト（紹介）用のリンクをコピーして、下の「リンク先URL」に貼ってください。</p>
				</td>
			</tr>
			<tr class="snscs-ad-field" data-types="content">
				<th scope="row"><label for="snscs-ad-title">商品名</label></th>
				<td><input type="text" id="snscs-ad-title" name="snscs_ad[title]" class="large-text" value="<?php echo esc_attr( $ad['title'] ); ?>" placeholder="例: ゼロから始めるInstagram運用の教科書"></td>
			</tr>
			<tr class="snscs-ad-field" data-types="content">
				<th scope="row"><label for="snscs-ad-desc">ひとこと説明</label></th>
				<td>
					<input type="text" id="snscs-ad-desc" name="snscs_ad[desc]" class="large-text" value="<?php echo esc_attr( $ad['desc'] ); ?>" placeholder="例: プロフィール作りから投稿の型まで、運用の基本をまとめた一冊">
					<p class="description">「必ず稼げる」など、誇大な表現は避けてください（景品表示法などに触れるおそれがあります）。</p>
				</td>
			</tr>
			<tr class="snscs-ad-field" data-types="content">
				<th scope="row"><label for="snscs-ad-price">価格</label></th>
				<td><input type="text" id="snscs-ad-price" name="snscs_ad[price]" class="regular-text" value="<?php echo esc_attr( $ad['price'] ); ?>" placeholder="例: 2,980円"></td>
			</tr>

			<tr class="snscs-ad-field" data-types="image content">
				<th scope="row">画像</th>
				<td>
					<input type="hidden" name="snscs_ad[image_id]" id="snscs-ad-image-id" value="<?php echo (int) $ad['image_id']; ?>">
					<div id="snscs-ad-image-preview" style="margin-bottom: 8px">
						<?php if ( $image ) : ?>
							<img src="<?php echo esc_url( $image ); ?>" alt="" style="max-width: 320px; height: auto; border: 1px solid #ddd">
						<?php endif; ?>
					</div>
					<button type="button" class="button" id="snscs-ad-image-select">画像を選ぶ</button>
					<button type="button" class="button-link-delete" id="snscs-ad-image-remove" style="margin-left: 8px">画像を外す</button>
					<p class="description">表示する場所に合った大きさの画像を選んでください（下の「表示する場所」に推奨サイズがあります）。</p>
				</td>
			</tr>
			<tr class="snscs-ad-field" data-types="image content">
				<th scope="row"><label for="snscs-ad-link">リンク先URL</label></th>
				<td><input type="url" id="snscs-ad-link" name="snscs_ad[link]" class="large-text" value="<?php echo esc_attr( $ad['link'] ); ?>" placeholder="https://"></td>
			</tr>
			<tr class="snscs-ad-field" data-types="image">
				<th scope="row"><label for="snscs-ad-alt">画像の説明</label></th>
				<td>
					<input type="text" id="snscs-ad-alt" name="snscs_ad[alt]" class="large-text" value="<?php echo esc_attr( $ad['alt'] ); ?>" placeholder="例: キャンプ用品が最大30%オフ">
					<p class="description">画像が見えない人（読み上げソフトの利用者など）に伝わる文章です。</p>
				</td>
			</tr>

			<tr class="snscs-ad-field" data-types="html adsense">
				<th scope="row"><label for="snscs-ad-code">広告コード</label></th>
				<td>
					<textarea id="snscs-ad-code" name="snscs_ad[code]" rows="8" class="large-text code" placeholder="アフィリエイト会社やGoogleアドセンスの管理画面でコピーしたコードを、そのまま貼り付けてください。"><?php echo esc_textarea( $ad['code'] ); ?></textarea>
					<p class="description snscs-ad-field" data-types="adsense">アドセンスの「広告ユニット」のコードを貼ってください。横幅に合わせて大きさが変わる「レスポンシブ」がおすすめです。</p>
					<?php if ( ! current_user_can( 'unfiltered_html' ) ) : ?>
						<p class="description" style="color: #b32d2e">このアカウントにはスクリプトを保存する権限がないため、コードの一部が取り除かれます。</p>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th scope="row">表示する場所</th>
				<td>
					<fieldset>
						<?php foreach ( SNSCS_Ads::slots() as $key => $slot ) : ?>
							<label style="display: block; margin-bottom: 6px">
								<input type="checkbox" name="snscs_ad[slots][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $ad['slots'], true ) ); ?>>
								<?php echo esc_html( $slot['label'] ); ?>
								<span class="description">（<?php echo esc_html( $slot['size'] ); ?>）</span>
								<?php if ( ! SNSCS_Ads::slot_enabled( $key ) ) : ?>
									<span style="color: #b32d2e">※この枠は設定でオフになっています</span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row">内容に合わせて出す</th>
				<td>
					<label for="snscs-ad-keywords">言葉（カンマ区切り）</label><br>
					<input type="text" id="snscs-ad-keywords" name="snscs_ad[keywords]" class="large-text" value="<?php echo esc_attr( implode( ', ', $ad['keywords'] ) ); ?>" placeholder="キャンプ, アウトドア, ソロキャンプ">
					<p style="margin: 8px 0 4px">ジャンル</p>
					<?php $snscs_genres = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => false, 'number' => 200 ) ); ?>
					<?php foreach ( is_wp_error( $snscs_genres ) ? array() : $snscs_genres as $snscs_g ) : ?>
						<label style="display: inline-block; margin: 0 12px 4px 0"><input type="checkbox" name="snscs_ad[genres][]" value="<?php echo esc_attr( $snscs_g->slug ); ?>" <?php checked( in_array( $snscs_g->slug, $ad['genres'], true ) ); ?>> <?php echo esc_html( $snscs_g->name ); ?></label>
					<?php endforeach; ?>
					<p class="description">どちらかを決めると、その言葉で検索したページ・そのジャンルの検索や発信者のページにだけ出します（合うページでは、ほかの広告より優先）。空ならどのページにも出します。</p>
				</td>
			</tr>
			<tr>
				<th scope="row">掲載期間</th>
				<td>
					<label><input type="date" name="snscs_ad[start]" value="<?php echo esc_attr( $ad['start'] ); ?>"> から</label>
					<label style="margin-left: 8px"><input type="date" name="snscs_ad[end]" value="<?php echo esc_attr( $ad['end'] ); ?>"> まで</label>
					<p class="description">空欄なら期間の制限なし。キャンペーン期間だけ出したいときに使います。</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="snscs-ad-weight">表示の割合</label></th>
				<td>
					<input type="number" id="snscs-ad-weight" name="snscs_ad[weight]" min="1" max="10" step="1" value="<?php echo (int) $ad['weight']; ?>" class="small-text">
					<p class="description">同じ場所に複数の広告があるとき、数字が大きいほど多く表示されます（例: 2 と 1 なら 2:1 の割合）。</p>
				</td>
			</tr>
		</table>
		<script>
			( function () {
				var form = document.querySelector( '.snscs-ad-form' );
				function sync() {
					var checked = form.querySelector( 'input[name="snscs_ad[type]"]:checked' );
					var type = checked ? checked.value : 'image';
					form.querySelectorAll( '.snscs-ad-field' ).forEach( function ( el ) {
						el.style.display = el.getAttribute( 'data-types' ).split( ' ' ).indexOf( type ) === -1 ? 'none' : '';
					} );
				}
				form.querySelectorAll( 'input[name="snscs_ad[type]"]' ).forEach( function ( el ) {
					el.addEventListener( 'change', sync );
				} );
				sync();

				var frame;
				document.getElementById( 'snscs-ad-image-select' ).addEventListener( 'click', function () {
					if ( ! frame ) {
						frame = wp.media( { title: '広告の画像を選ぶ', button: { text: 'この画像を使う' }, library: { type: 'image' }, multiple: false } );
						frame.on( 'select', function () {
							var file = frame.state().get( 'selection' ).first().toJSON();
							document.getElementById( 'snscs-ad-image-id' ).value = file.id;
							var img = document.createElement( 'img' );
							img.src = ( file.sizes && file.sizes.medium ) ? file.sizes.medium.url : file.url;
							img.style.cssText = 'max-width: 320px; height: auto; border: 1px solid #ddd';
							var preview = document.getElementById( 'snscs-ad-image-preview' );
							preview.innerHTML = '';
							preview.appendChild( img );
						} );
					}
					frame.open();
				} );
				document.getElementById( 'snscs-ad-image-remove' ).addEventListener( 'click', function () {
					document.getElementById( 'snscs-ad-image-id' ).value = '';
					document.getElementById( 'snscs-ad-image-preview' ).innerHTML = '';
				} );
			} )();
		</script>
		<?php
	}

	public static function render_help() {
		?>
		<ol style="padding-left: 1.2em; margin: 0">
			<li>種類を選んで、画像とリンク、商品の情報、またはコードを入れる</li>
			<li>表示する場所を選ぶ</li>
			<li>「公開」を押すと掲載が始まる</li>
		</ol>
		<p>止めたいときは「ステータス」を「下書き」にして更新します。</p>
		<p>トップページなど自分で置く枠は、表示したい場所にショートコードを書きます。<br><code>[sns_ad slot="top_side"]</code></p>
		<p><a href="<?php echo esc_url( self::settings_url() ); ?>">広告枠の設定（オン・オフ、ads.txt）</a></p>
		<?php
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 下で項目ごとに整える。
		$in = isset( $_POST['snscs_ad'] ) && is_array( $_POST['snscs_ad'] ) ? wp_unslash( $_POST['snscs_ad'] ) : array();

		$type  = isset( $in['type'] ) && array_key_exists( $in['type'], SNSCS_Ads::types() ) ? $in['type'] : SNSCS_Ads::TYPE_IMAGE;
		$slots = array_values( array_intersect( isset( $in['slots'] ) ? (array) $in['slots'] : array(), array_keys( SNSCS_Ads::slots() ) ) );
		$date  = function ( $key ) use ( $in ) {
			$value = isset( $in[ $key ] ) ? (string) $in[ $key ] : '';
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		};
		$code = isset( $in['code'] ) ? (string) $in['code'] : '';
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$code = wp_kses_post( $code );
		}

		update_post_meta( $post_id, '_snscs_ad_type', $type );
		update_post_meta( $post_id, '_snscs_ad_slots', $slots );
		update_post_meta( $post_id, '_snscs_ad_image_id', isset( $in['image_id'] ) ? absint( $in['image_id'] ) : 0 );
		update_post_meta( $post_id, '_snscs_ad_link', isset( $in['link'] ) ? esc_url_raw( $in['link'] ) : '' );
		update_post_meta( $post_id, '_snscs_ad_alt', isset( $in['alt'] ) ? sanitize_text_field( $in['alt'] ) : '' );
		update_post_meta( $post_id, '_snscs_ad_code', $code );
		update_post_meta( $post_id, '_snscs_ad_start', $date( 'start' ) );
		update_post_meta( $post_id, '_snscs_ad_end', $date( 'end' ) );
		update_post_meta( $post_id, '_snscs_ad_weight', min( 10, max( 1, isset( $in['weight'] ) ? (int) $in['weight'] : 1 ) ) );
		update_post_meta( $post_id, '_snscs_ad_platform', isset( $in['platform'] ) && array_key_exists( $in['platform'], SNSCS_Ads::content_platforms() ) ? $in['platform'] : 'other' );
		update_post_meta( $post_id, '_snscs_ad_title', isset( $in['title'] ) ? sanitize_text_field( $in['title'] ) : '' );
		update_post_meta( $post_id, '_snscs_ad_desc', isset( $in['desc'] ) ? sanitize_text_field( $in['desc'] ) : '' );
		update_post_meta( $post_id, '_snscs_ad_price', isset( $in['price'] ) ? sanitize_text_field( $in['price'] ) : '' );
		$keywords = array();
		foreach ( preg_split( '/[,、，\n]+/u', isset( $in['keywords'] ) ? (string) $in['keywords'] : '' ) as $word ) {
			$word = SNSCS_Text::normalize( mb_substr( trim( sanitize_text_field( $word ) ), 0, 20 ) );
			if ( '' !== $word && false === strpos( $word, ',' ) ) {
				$keywords[] = $word;
			}
		}
		update_post_meta( $post_id, '_snscs_ad_keywords', implode( ',', array_slice( array_unique( $keywords ), 0, 30 ) ) );
		update_post_meta( $post_id, '_snscs_ad_genres', implode( ',', array_filter( array_map( 'sanitize_title', isset( $in['genres'] ) ? (array) $in['genres'] : array() ) ) ) );

		SNSCS_Ads::flush_cache();
	}

	/* --------------------------------------------------------------------
	 * 一覧
	 * ------------------------------------------------------------------ */

	public static function columns( $columns ) {
		return array(
			'cb'           => $columns['cb'],
			'title'        => '広告',
			'snscs_type'   => '種類',
			'snscs_slots'  => '表示する場所',
			'snscs_period' => '掲載期間',
			'snscs_stats'  => '表示 / クリック（30日）',
			'date'         => $columns['date'],
		);
	}

	public static function column_content( $column, $post_id ) {
		static $stats = null;
		$ad           = SNSCS_Ads::get( $post_id );

		switch ( $column ) {
			case 'snscs_type':
				$types = SNSCS_Ads::types();
				echo esc_html( $types[ $ad['type'] ] );
				break;
			case 'snscs_slots':
				$slots = SNSCS_Ads::slots();
				$names = array();
				foreach ( $ad['slots'] as $key ) {
					if ( isset( $slots[ $key ] ) ) {
						$names[] = $slots[ $key ]['label'];
					}
				}
				echo $names ? esc_html( implode( '／', $names ) ) : '<span style="color: #b32d2e">未選択</span>';
				break;
			case 'snscs_period':
				echo esc_html( ( $ad['start'] ? $ad['start'] : '指定なし' ) . ' 〜 ' . ( $ad['end'] ? $ad['end'] : '指定なし' ) );
				break;
			case 'snscs_stats':
				if ( null === $stats ) {
					$stats = SNSCS_Ads::stats( 30 );
				}
				$s = isset( $stats[ $post_id ] ) ? $stats[ $post_id ] : array( 'impressions' => 0, 'clicks' => 0 );
				if ( SNSCS_Ads::is_linked_type( $ad['type'] ) ) {
					$rate = $s['impressions'] ? $s['clicks'] / $s['impressions'] * 100 : 0;
					printf( '%s回 / %s回（%s%%）', esc_html( number_format_i18n( $s['impressions'] ) ), esc_html( number_format_i18n( $s['clicks'] ) ), esc_html( number_format_i18n( $rate, 1 ) ) );
				} else {
					printf( '%s回 / <span class="description">各社の管理画面で確認</span>', esc_html( number_format_i18n( $s['impressions'] ) ) );
				}
				break;
		}
	}

	/* --------------------------------------------------------------------
	 * 設定
	 * ------------------------------------------------------------------ */

	public static function settings_url() {
		return admin_url( 'edit.php?post_type=' . SNSCS_Ads::POST_TYPE . '&page=' . self::SETTINGS_PAGE );
	}

	public static function add_settings_page() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Ads::POST_TYPE, '広告枠の設定', '設定', 'manage_options', self::SETTINGS_PAGE, array( __CLASS__, 'render_settings' ) );
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = SNSCS_Ads::settings();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saved = isset( $_GET['snscs_done'] );
		?>
		<div class="wrap">
			<h1>広告枠の設定</h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
				<input type="hidden" name="action" value="snscs_ad_settings">
				<?php wp_nonce_field( 'snscs_ad_settings', 'snscs_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">使う広告枠</th>
						<td>
							<fieldset>
								<?php foreach ( SNSCS_Ads::slots() as $key => $slot ) : ?>
									<label style="display: block; margin-bottom: 8px">
										<input type="checkbox" name="enabled_slots[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $settings['enabled_slots'], true ) ); ?>>
										<?php echo esc_html( $slot['label'] ); ?>
										<?php if ( 0 === strpos( $key, 'top_' ) ) : ?>
											<code>[sns_ad slot="<?php echo esc_html( $key ); ?>"]</code>
										<?php elseif ( 0 === strpos( $key, 'column_' ) ) : ?>
											<span class="description">（コラム記事の下に自動で表示）</span>
										<?php elseif ( 0 === strpos( $key, 'article_' ) ) : ?>
											<span class="description">（発信者が書いた記事の下に自動で表示）</span>
										<?php else : ?>
											<span class="description">（検索結果に自動で表示）</span>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description">広告が多すぎると使いにくくなるため、最初は「検索結果：一覧の下」をオフにしています。1つの枠に出る広告は1回の表示につき1つです。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-infeed">一覧の途中に出す位置</label></th>
						<td><input id="snscs-infeed" name="infeed_after" type="number" min="1" max="20" value="<?php echo (int) $settings['infeed_after']; ?>" class="small-text"> 人目のあと（1ページに1回だけ）</td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-label">広告ラベル</label></th>
						<td>
							<input id="snscs-label" name="label" type="text" value="<?php echo esc_attr( $settings['label'] ); ?>" class="regular-text">
							<p class="description">すべての広告の左上に表示されます（例: 広告、PR、スポンサー）。ステルスマーケティング規制（景品表示法）のため、空にはできません。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-ads-txt">ads.txt の内容</label></th>
						<td>
							<textarea id="snscs-ads-txt" name="ads_txt" rows="4" class="large-text code" placeholder="google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0"><?php echo esc_textarea( $settings['ads_txt'] ); ?></textarea>
							<p class="description">Googleアドセンスの管理画面に表示される1行を貼ってください。<a href="<?php echo esc_url( home_url( '/ads.txt' ) ); ?>" target="_blank">今の ads.txt を見る</a></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-head">サイト全体の &lt;head&gt; に入れるコード</label></th>
						<td>
							<textarea id="snscs-head" name="head_code" rows="4" class="large-text code" placeholder="&lt;script async src=&quot;https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-…&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/script&gt;"><?php echo esc_textarea( $settings['head_code'] ); ?></textarea>
							<p class="description">アドセンスの審査（サイトの確認）で求められるコードを貼ります。自動広告をオンにすると、上の枠とは別にGoogleが広告を増やすことがあるため、アドセンス側で自動広告はオフにしておくのがおすすめです。</p>
						</td>
					</tr>
				</table>
				<?php submit_button( '設定を保存' ); ?>
			</form>
		</div>
		<?php
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), 'snscs_ad_settings' ) ) {
			wp_die( 'この操作は許可されていません。' );
		}

		$label     = isset( $_POST['label'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['label'] ) ) ) : '';
		$head_code = isset( $_POST['head_code'] ) ? (string) wp_unslash( $_POST['head_code'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 下で権限を確認。
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$head_code = '';
		}

		update_option(
			'snscs_ads_settings',
			array(
				'label'         => '' !== $label ? $label : '広告',
				'enabled_slots' => array_values( array_intersect( isset( $_POST['enabled_slots'] ) ? (array) wp_unslash( $_POST['enabled_slots'] ) : array(), array_keys( SNSCS_Ads::slots() ) ) ),
				'known_slots'   => array_keys( SNSCS_Ads::slots() ),
				'infeed_after'  => min( 20, max( 1, isset( $_POST['infeed_after'] ) ? (int) $_POST['infeed_after'] : 5 ) ),
				'ads_txt'       => isset( $_POST['ads_txt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ads_txt'] ) ) : '',
				'head_code'     => $head_code,
			)
		);

		wp_safe_redirect( add_query_arg( 'snscs_done', 1, self::settings_url() ) );
		exit;
	}
}
