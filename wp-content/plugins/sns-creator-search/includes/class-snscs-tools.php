<?php
/**
 * SNS運用の無料ツール（何度も使いに来てもらうための入口）。
 *
 *   [sns_tool_engagement] エンゲージメント率の計算（SNSごとの目安と比べる）
 *   [sns_tool_diagnosis]  SNS発信タイプ診断（6問。結果は URL で共有できる）
 *   [sns_tools]           両方をまとめたページ
 *
 * どちらも入力をページのURL（GET）で受け取り、サーバーで計算する（JavaScript が無くても動く）。
 * 入力した数字や答えは保存しない。結果の下から、合う発信者・コラム・特集へ案内する。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Tools {

	public static function init() {
		add_shortcode( 'sns_tools', array( __CLASS__, 'shortcode_page' ) );
		add_shortcode( 'sns_tool_engagement', array( __CLASS__, 'engagement' ) );
		add_shortcode( 'sns_tool_diagnosis', array( __CLASS__, 'diagnosis' ) );
	}

	private static function get( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 保存しない計算のための入力。
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	private static function number( $key ) {
		return max( 0, (int) preg_replace( '/[^\d]/', '', mb_convert_kana( self::get( $key ), 'n' ) ) );
	}

	public static function shortcode_page() {
		SNSCS_Hub::remember_page( 'tools' );
		return SNSCS_Hub::quick_nav() . self::engagement() . self::diagnosis();
	}

	/* --------------------------------------------------------------------
	 * エンゲージメント率
	 * ------------------------------------------------------------------ */

	/**
	 * SNSごとの計算方法と目安（一般によく言われる水準。あくまで目安）。
	 *
	 * @return array<string,array{base:string,low:float,high:float}>
	 */
	public static function benchmarks() {
		return apply_filters(
			'snscs_engagement_benchmarks',
			array(
				'x'         => array( 'base' => 'followers', 'low' => 0.5, 'high' => 1.0 ),
				'youtube'   => array( 'base' => 'views', 'low' => 2.0, 'high' => 5.0 ),
				'instagram' => array( 'base' => 'followers', 'low' => 1.0, 'high' => 3.0 ),
				'threads'   => array( 'base' => 'followers', 'low' => 1.0, 'high' => 3.0 ),
				'tiktok'    => array( 'base' => 'views', 'low' => 3.0, 'high' => 6.0 ),
				'substack'  => array( 'base' => 'followers', 'low' => 0.5, 'high' => 2.0 ),
			)
		);
	}

	public static function engagement() {
		wp_enqueue_style( 'snscs' );
		$benchmarks = self::benchmarks();
		$platform   = sanitize_key( self::get( 'er_sns' ) );
		$platform   = isset( $benchmarks[ $platform ] ) ? $platform : 'instagram';
		$followers  = self::number( 'er_followers' );
		$views      = self::number( 'er_views' );
		$actions    = self::number( 'er_likes' ) + self::number( 'er_comments' ) + self::number( 'er_saves' );
		$submitted  = '' !== self::get( 'er_sns' );
		$bench      = $benchmarks[ $platform ];
		$base       = 'views' === $bench['base'] ? $views : $followers;
		$rate       = ( $submitted && $base > 0 ) ? $actions / $base * 100 : null;

		ob_start();
		?>
		<section class="snscs-tool" id="snscs-engagement">
			<h2 class="snscs-tool__title">エンゲージメント率の計算</h2>
			<p>1つの投稿についた「いいね・コメント・保存（シェア）」の数から、反応の良さを計算します。入力した数字は保存しません。</p>
			<form class="snscs-tool__form" method="get" action="#snscs-engagement">
				<label class="snscs-field"><span>SNS</span>
					<select name="er_sns">
						<?php foreach ( $benchmarks as $key => $def ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $platform, $key ); ?>><?php echo esc_html( snscs_platform_label( $key ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="snscs-field"><span>フォロワー数</span><input type="text" inputmode="numeric" name="er_followers" value="<?php echo $followers ? (int) $followers : ''; ?>" placeholder="例: 12000"></label>
				<label class="snscs-field"><span>再生（表示）回数 <small>YouTube・TikTokは必須</small></span><input type="text" inputmode="numeric" name="er_views" value="<?php echo $views ? (int) $views : ''; ?>" placeholder="例: 30000"></label>
				<label class="snscs-field"><span>いいね</span><input type="text" inputmode="numeric" name="er_likes" value="<?php echo esc_attr( self::get( 'er_likes' ) ); ?>" placeholder="例: 350"></label>
				<label class="snscs-field"><span>コメント</span><input type="text" inputmode="numeric" name="er_comments" value="<?php echo esc_attr( self::get( 'er_comments' ) ); ?>" placeholder="例: 20"></label>
				<label class="snscs-field"><span>保存・シェア・リポスト</span><input type="text" inputmode="numeric" name="er_saves" value="<?php echo esc_attr( self::get( 'er_saves' ) ); ?>" placeholder="例: 40"></label>
				<div><button type="submit" class="snscs-button snscs-button--primary">計算する</button></div>
			</form>

			<?php if ( $submitted ) : ?>
				<div class="snscs-tool__result" role="status">
					<?php if ( null === $rate ) : ?>
						<p><?php echo esc_html( 'views' === $bench['base'] ? '再生（表示）回数を入れてください。' : 'フォロワー数を入れてください。' ); ?></p>
					<?php else : ?>
						<?php
						if ( $rate >= $bench['high'] ) {
							$level = array( 'high', 'とても良い反応です', 'ファンとの関係ができています。反応の良い投稿の型を続け、プロフィールや固定投稿から次の行動（リンク先）へ案内しましょう。' );
						} elseif ( $rate >= $bench['low'] ) {
							$level = array( 'mid', '平均的な反応です', '問いかけで終わる投稿や、保存したくなる「まとめ」型の投稿を増やすと、反応が伸びやすくなります。' );
						} else {
							$level = array( 'low', '反応を伸ばす余地があります', '投稿する時間帯を変えてみる、1枚目（冒頭）で何の話か分かるようにする、コメントに返信する、などから試してみましょう。' );
						}
						?>
						<p class="snscs-tool__rate snscs-tool__rate--<?php echo esc_attr( $level[0] ); ?>"><?php echo esc_html( number_format( $rate, 2 ) ); ?>%</p>
						<p><strong><?php echo esc_html( $level[1] ); ?></strong>（<?php echo esc_html( snscs_platform_label( $platform ) ); ?>の目安: <?php echo esc_html( $bench['low'] . '〜' . $bench['high'] ); ?>%）</p>
						<p><?php echo esc_html( $level[2] ); ?></p>
						<p class="snscs-help">計算式: 反応の合計 ÷ <?php echo esc_html( 'views' === $bench['base'] ? '再生（表示）回数' : 'フォロワー数' ); ?> × 100。目安は一般によく言われる水準で、ジャンルや規模によって変わります。</p>
					<?php endif; ?>
					<p class="snscs-tool__next">
						<a class="snscs-button" href="<?php echo esc_url( SNSCS_Features::url( $platform ) ); ?>"><?php echo esc_html( snscs_platform_label( $platform ) ); ?>で人気の発信者を見る</a>
					</p>
				</div>
				<?php echo SNSCS_Columns::render( snscs_platform_label( $platform ) . 'の運用コラム', array( 'sns' => array( $platform ), 'count' => 3 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/* --------------------------------------------------------------------
	 * SNS発信タイプ診断
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,array{name:string,desc:string,sns:string[],tip:string}>
	 */
	public static function types() {
		return apply_filters(
			'snscs_diagnosis_types',
			array(
				'story'     => array( 'name' => '共感ストーリー型', 'desc' => '日々の体験や気持ちを言葉にして、共感でつながるタイプ。飾らない発信がファンを増やします。', 'sns' => array( 'instagram', 'threads', 'substack' ), 'tip' => '「その時どう感じたか」まで書くと、読んだ人が自分ごとにしてくれます。' ),
				'knowhow'   => array( 'name' => 'ノウハウ解説型', 'desc' => '知っていることを分かりやすく整理して伝えるタイプ。保存・シェアされやすく、信頼が積み上がります。', 'sns' => array( 'x', 'youtube', 'substack' ), 'tip' => '1投稿1テーマ。「結論 → 理由 → 具体例」の順にすると伝わりやすくなります。' ),
				'visual'    => array( 'name' => 'ビジュアル表現型', 'desc' => '写真やデザイン、世界観で魅せるタイプ。ひと目で伝わる強さがあります。', 'sns' => array( 'instagram', 'tiktok' ), 'tip' => '色や構図をそろえると、プロフィールを開いた人がフォローしたくなります。' ),
				'entertain' => array( 'name' => 'エンタメ型', 'desc' => '楽しさやテンポで人を引きつけるタイプ。短い動画との相性が抜群です。', 'sns' => array( 'tiktok', 'youtube' ), 'tip' => '最初の2秒で「何が起きるか」を見せると、最後まで見てもらえます。' ),
				'community' => array( 'name' => 'コミュニティ型', 'desc' => '会話や交流を楽しみ、人と人をつなぐタイプ。コメント欄が盛り上がります。', 'sns' => array( 'threads', 'x' ), 'tip' => '問いかけで終わる投稿や、フォロワーの声を紹介する投稿を増やしましょう。' ),
				'news'      => array( 'name' => '情報速報型', 'desc' => '新しい情報をいち早く届けるタイプ。スピードと正確さが武器です。', 'sns' => array( 'x', 'substack' ), 'tip' => '出典を添えて、要点を3行にまとめると信頼されます。' ),
			)
		);
	}

	/**
	 * 質問（答え => 加点するタイプ）。
	 */
	public static function questions() {
		return apply_filters(
			'snscs_diagnosis_questions',
			array(
				array( 'q' => '発信したいことに近いのは？', 'a' => array( '自分の体験や気持ち' => array( 'story' => 2 ), '役立つ知識やコツ' => array( 'knowhow' => 2 ), '写真・作品・デザイン' => array( 'visual' => 2 ), '面白いこと・ネタ' => array( 'entertain' => 2 ) ) ),
				array( 'q' => '得意な表現は？', 'a' => array( '長めの文章' => array( 'story' => 1, 'knowhow' => 1 ), '短い一言' => array( 'news' => 1, 'community' => 1 ), '写真・画像' => array( 'visual' => 2 ), '動画・しゃべり' => array( 'entertain' => 2 ) ) ),
				array( 'q' => 'フォロワーとの関係で大事にしたいのは？', 'a' => array( '共感してもらうこと' => array( 'story' => 2 ), '役に立つと思ってもらうこと' => array( 'knowhow' => 2 ), 'わいわい交流すること' => array( 'community' => 2 ), 'いち早く知ってもらうこと' => array( 'news' => 2 ) ) ),
				array( 'q' => '投稿のペースは？', 'a' => array( '毎日何回も' => array( 'news' => 1, 'community' => 1 ), '毎日1回くらい' => array( 'visual' => 1, 'entertain' => 1 ), '週に数回、じっくり' => array( 'knowhow' => 1, 'story' => 1 ) ) ),
				array( 'q' => '人から言われてうれしいのは？', 'a' => array( '「わかる！」' => array( 'story' => 2 ), '「勉強になった」' => array( 'knowhow' => 2 ), '「きれい！」「おしゃれ！」' => array( 'visual' => 2 ), '「笑った！」' => array( 'entertain' => 2 ) ) ),
				array( 'q' => 'ニュースや流行には？', 'a' => array( 'すぐ反応したい' => array( 'news' => 2 ), 'みんなと話題にしたい' => array( 'community' => 2 ), '自分の視点で解説したい' => array( 'knowhow' => 1 ), 'あまり追わない' => array( 'story' => 1, 'visual' => 1 ) ) ),
			)
		);
	}

	public static function diagnosis() {
		wp_enqueue_style( 'snscs' );
		$types     = self::types();
		$questions = self::questions();
		$shared    = sanitize_key( self::get( 'snscs_type' ) );
		$result    = isset( $types[ $shared ] ) ? $shared : '';
		$answers   = array();
		$complete  = true;
		foreach ( $questions as $i => $question ) {
			$answer = self::get( 'dq' . $i );
			if ( '' === $answer || ! isset( $question['a'][ $answer ] ) ) {
				$complete = false;
				continue;
			}
			$answers[ $i ] = $answer;
		}
		if ( ! $result && $complete ) {
			$scores = array_fill_keys( array_keys( $types ), 0 );
			foreach ( $answers as $i => $answer ) {
				foreach ( $questions[ $i ]['a'][ $answer ] as $type => $points ) {
					$scores[ $type ] += $points;
				}
			}
			arsort( $scores );
			$result = (string) key( $scores );
		}

		ob_start();
		?>
		<section class="snscs-tool" id="snscs-diagnosis">
			<h2 class="snscs-tool__title">SNS発信タイプ診断</h2>
			<?php if ( $result ) : ?>
				<?php
				$type  = $types[ $result ];
				$share = add_query_arg( 'snscs_type', $result, strtok( (string) get_permalink(), '?' ) ) . '#snscs-diagnosis';
				$text  = sprintf( 'わたしのSNS発信タイプは「%s」でした！', $type['name'] );
				?>
				<div class="snscs-tool__result" role="status">
					<p class="snscs-tool__type"><?php echo esc_html( $shared ? 'この人の発信タイプは' : 'あなたの発信タイプは' ); ?><strong><?php echo esc_html( $type['name'] ); ?></strong></p>
					<p><?php echo esc_html( $type['desc'] ); ?></p>
					<p><strong>伸ばすコツ:</strong> <?php echo esc_html( $type['tip'] ); ?></p>
					<p><strong>相性の良いSNS:</strong></p>
					<ul class="snscs-chips">
						<?php foreach ( $type['sns'] as $sns ) : ?>
							<li><a class="snscs-chip" href="<?php echo esc_url( SNSCS_Features::url( $sns ) ); ?>"><?php echo esc_html( snscs_platform_label( $sns ) ); ?>の人気発信者を見る</a></li>
						<?php endforeach; ?>
					</ul>
					<div class="snscs-share">
						<a class="snscs-share__button snscs-share__button--x" href="<?php echo esc_url( 'https://x.com/intent/tweet?' . http_build_query( array( 'text' => $text, 'url' => $share ) ) ); ?>" target="_blank" rel="noopener">結果をXでシェア</a>
						<a class="snscs-share__button snscs-share__button--line" href="<?php echo esc_url( 'https://social-plugins.line.me/lineit/share?' . http_build_query( array( 'url' => $share ) ) ); ?>" target="_blank" rel="noopener">LINEで送る</a>
					</div>
					<p><a href="<?php echo esc_url( strtok( (string) get_permalink(), '?' ) . '#snscs-diagnosis' ); ?>"><?php echo esc_html( $shared ? '自分も診断してみる' : 'もう一度診断する' ); ?></a></p>
				</div>
				<?php echo SNSCS_Columns::render( 'このタイプにおすすめのコラム', array( 'sns' => $type['sns'], 'count' => 3 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エスケープ済みのHTML。 ?>
			<?php else : ?>
				<p>6つの質問に答えると、あなたに合った発信のスタイルと、相性の良いSNSが分かります（答えは保存しません）。</p>
				<form class="snscs-tool__form snscs-quiz" method="get" action="#snscs-diagnosis">
					<?php foreach ( $questions as $i => $question ) : ?>
						<fieldset class="snscs-quiz__q">
							<legend>Q<?php echo (int) $i + 1; ?>. <?php echo esc_html( $question['q'] ); ?></legend>
							<?php foreach ( array_keys( $question['a'] ) as $answer ) : ?>
								<label class="snscs-check"><input type="radio" name="dq<?php echo (int) $i; ?>" value="<?php echo esc_attr( $answer ); ?>" required <?php checked( isset( $answers[ $i ] ) && $answers[ $i ] === $answer ); ?>> <span><?php echo esc_html( $answer ); ?></span></label>
							<?php endforeach; ?>
						</fieldset>
					<?php endforeach; ?>
					<div><button type="submit" class="snscs-button snscs-button--primary">診断する</button></div>
				</form>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}
}
