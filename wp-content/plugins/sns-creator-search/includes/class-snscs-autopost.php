<?php
/**
 * SNS運用コラムの自動執筆・自動投稿（管理画面「発信者 → 記事の自動投稿」）。
 *
 * 流れ:
 *   1. 書きためが足りなくなると、テーマ一覧から次のテーマを選び、Claude（Anthropic の AI）に
 *      まとめて執筆を頼む（Message Batches API。料金が半分になり、ほとんどは1時間以内に仕上がる。
 *      サーバーで長い時間待たないので、レンタルサーバーでも止まりにくい）。
 *   2. 仕上がった記事を下書きとして保存（目次・アイキャッチ画像つき）。5000字前後・見出し構成を確認し、
 *      足りないものは使わない。
 *   3. 決めた時刻（初期: 7時と19時）に1本ずつ公開する（「下書きのまま止める」設定なら運営にメールで知らせる）。
 *
 * 記事の種類（SNSCS_Column_Kinds）: SNS運用コラムと「ジャンル × SNS」の攻略ガイドを交互に書きため、
 * トレンド記事（急上昇した言葉）と月刊SNSトレンドレポートは、その日・その月に頼んで仕上がりしだい公開する。
 * 記事の中のサイト内リンクは SNSCS_Autolink が表示のときに付ける。
 *
 * API キーは wp-config.php の SNSCS_ANTHROPIC_API_KEY（おすすめ）か、管理画面で入れる。
 * WordPress を置くサーバーの多くでは Anthropic の PHP SDK（PHP 8.1 以上・大きなライブラリ）が使えないため、
 * WordPress 標準の通信機能で API を直接呼ぶ。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Autopost {

	const OPTION      = 'snscs_autopost';
	const STATE       = 'snscs_autopost_state';
	const LOG         = 'snscs_autopost_log';
	const CRON_HOOK   = 'snscs_autopost_tick';
	const ADMIN_PAGE  = 'snscs-autopost';
	const META_READY  = '_snscs_autopost_ready';
	const META_AI     = '_snscs_ai_written';
	const MIN_CHARS   = 3500;
	const MIN_H2      = 4;
	const MAX_TOKENS  = 16000;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_snscs_autopost_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_snscs_autopost_run', array( __CLASS__, 'handle_run' ) );
		add_filter( 'the_content', array( __CLASS__, 'append_note' ), 30 );
	}

	/* --------------------------------------------------------------------
	 * 設定
	 * ------------------------------------------------------------------ */

	public static function models() {
		return array(
			'claude-opus-5-5'   => 'Claude Opus 5.5（いちばん質が高い。おすすめ）',
			'claude-sonnet-5-5' => 'Claude Sonnet 5.5（料金はおよそ半分）',
		);
	}

	public static function defaults() {
		return array(
			'enabled'  => 0,
			'per_day'  => 2,
			'times'    => '07:00,19:00',
			'mode'     => 'publish',
			'model'    => 'claude-opus-5-5',
			'api_key'  => '',
			'topics'   => implode( "\n", self::default_topics() ),
			'ai_note'  => 1,
			'eyecatch' => 1,
			// 記事の種類（SNSCS_Column_Kinds）とサイト内リンク（SNSCS_Autolink）。
			'trend_per_day' => 1,
			'report'        => 1,
			'guides'        => 1,
			'autolink'      => 1,
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function api_key() {
		if ( defined( 'SNSCS_ANTHROPIC_API_KEY' ) && SNSCS_ANTHROPIC_API_KEY ) {
			return (string) SNSCS_ANTHROPIC_API_KEY;
		}
		return (string) self::settings()['api_key'];
	}

	/**
	 * 公開する時刻（"07:00" の形、早い順）。1日の本数に合わせて足りなければ等間隔で補う。
	 *
	 * @return string[]
	 */
	public static function slots() {
		$s     = self::settings();
		$times = array();
		foreach ( explode( ',', (string) $s['times'] ) as $time ) {
			$time = trim( $time );
			if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m ) ) {
				$times[] = sprintf( '%02d:%02d', $m[1], $m[2] );
			}
		}
		$times = array_values( array_unique( $times ) );
		sort( $times );
		$per_day = max( 1, (int) $s['per_day'] );
		for ( $i = count( $times ); $i < $per_day; $i++ ) {
			$times[] = sprintf( '%02d:00', min( 23, 7 + (int) floor( $i * 14 / $per_day ) ) );
		}
		$times = array_slice( array_values( array_unique( $times ) ), 0, $per_day );
		sort( $times );
		return $times;
	}

	/**
	 * はじめに入っているテーマ（コラムのネタ帳より詳しく、検索されやすい形）。
	 */
	public static function default_topics() {
		return array_merge(
			array(
				'Instagramのフォロワーを増やす方法：初心者が最初の1000人に届くまでの具体的な手順',
				'Xの伸びる投稿の作り方：反応が集まる書き出し・構成・投稿時間の考え方',
				'YouTubeチャンネルの始め方：企画・撮影・編集・公開までの完全ガイド',
				'TikTokで再生数を伸ばすコツ：最初の2秒・構成・続けるための仕組み',
				'Threadsの使い方と伸ばし方：会話が生まれるアカウント運用',
				'Substackでニュースレターを始める方法：テーマ決めから読者を増やすまで',
				'SNSのプロフィールの作り方：フォローされるプロフィールの書き方と実例の型',
				'SNS運用の分析方法：見るべき数字と、数字から次の投稿を決める手順',
				'エンゲージメント率の上げ方：保存・コメント・シェアが増える投稿の設計',
				'企業から案件が来る発信者になるには：実績の見せ方と問い合わせ導線',
				'PR投稿の正しい書き方：ステルスマーケティング規制と「広告」表示の基本',
				'SNSの炎上を防ぐ方法：投稿前のチェックリストと起きたときの対応',
				'顔出しなしでSNS発信を続ける方法：ジャンル選びと表現の工夫',
				'SNS発信のネタ切れを防ぐ方法：ネタ帳の作り方と企画の広げ方',
				'複数のSNSを運用するコツ：役割分担と1つの素材の使い回し',
				'ショート動画の作り方：スマホだけで撮影・編集する手順',
				'ライブ配信の始め方：準備・当日の進め方・配信後の活用',
				'noteで記事を販売する方法：売れる記事の企画と値段の決め方',
				'Brainなどコンテンツ販売の始め方：準備と注意点',
				'SNSで地域の魅力を発信する方法：地元のファンを増やすコツ',
				'SNS発信と本業・子育ての両立：続けられる時間の使い方',
				'ハッシュタグの選び方：見つけてもらうための考え方と注意点',
				'SNSのコラボ企画のやり方：相手の探し方から進め方まで',
				'SNS運用の目標の立て方：フォロワー数だけに頼らない目標設計',
				'プレゼント企画の正しいやり方：景品表示法とSNSのルールの注意点',
				'SNSで写真をきれいに撮るコツ：光・構図・加工の基本',
				'SNS発信者のための著作権・肖像権の基本',
				'ブログとSNSを組み合わせた集客方法：検索とSNSの両方から見つけてもらう',
				'SNS発信を収益化する方法：広告・案件・販売・ファンクラブの比較',
				'スランプのときのSNS運用：やる気が落ちたときの立て直し方',
			),
			SNSCS_Columns::ideas()
		);
	}

	/* --------------------------------------------------------------------
	 * 定期処理
	 * ------------------------------------------------------------------ */

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	private static function state() {
		return wp_parse_args( (array) get_option( self::STATE, array() ), array( 'batches' => array(), 'published' => array(), 'used' => array(), 'guides' => array(), 'trend_done' => array(), 'report_done' => '', 'last_kind' => '' ) );
	}

	private static function save_state( array $state ) {
		update_option( self::STATE, $state, false );
	}

	public static function log( $message, $level = 'info' ) {
		$log = (array) get_option( self::LOG, array() );
		array_unshift( $log, array( 'at' => current_time( 'mysql' ), 'level' => $level, 'message' => (string) $message ) );
		update_option( self::LOG, array_slice( $log, 0, 50 ), false );
	}

	/**
	 * 1時間ごと: 仕上がった記事を受け取り、時刻になった分を公開し、書きためが足りなければ次を頼む。
	 */
	public static function tick() {
		$settings = self::settings();
		self::collect();
		if ( ! $settings['enabled'] ) {
			return;
		}
		self::publish_due();
		self::maybe_order();
		self::order_kinds();
	}

	/**
	 * トレンド記事・月刊レポートを、その日・その月の分だけ頼む。
	 *
	 * @param bool $force 時刻と「今月はもう頼んだ」を無視する（管理画面のボタン）。
	 * @return int 頼んだ本数。
	 */
	public static function order_kinds( $force = false ) {
		if ( '' === self::api_key() || ( ! $force && ! self::settings()['enabled'] ) ) {
			return 0;
		}
		$state = self::state();
		$due   = SNSCS_Column_Kinds::due_items( $state, $force );
		if ( '' !== $due['report_month'] ) {
			$state['report_done'] = $due['report_month'];
			self::save_state( $state );
		}
		if ( ! $due['items'] ) {
			return 0;
		}
		return self::order( $due['items'] ) ? count( $due['items'] ) : 0;
	}

	/**
	 * 書きためる記事を $count 本ぶん選ぶ（コラムと攻略ガイドを交互に）。
	 *
	 * @return array<string,array>
	 */
	public static function next_items( $count ) {
		$state  = self::state();
		$items  = array();
		$guides = (array) $state['guides'];
		$last   = (string) $state['last_kind'];
		for ( $i = 0; $i < $count; $i++ ) {
			$guide = ( self::settings()['guides'] && 'guide' !== $last ) ? SNSCS_Column_Kinds::next_guide( $guides ) : null;
			if ( $guide ) {
				$guides[] = $guide['sns'] . '|' . $guide['genre']->slug;
				$items[ 'gd-' . time() . '-' . $i ] = array( 'topic' => SNSCS_Column_Kinds::guide_topic( $guide['sns'], $guide['genre']->name ), 'kind' => 'guide', 'sns' => $guide['sns'], 'genre' => $guide['genre']->slug, 'count' => $guide['count'] );
				$last = 'guide';
			} else {
				$items[ 'ap-' . time() . '-' . $i ] = array( 'topic' => self::next_topic( array_column( $items, 'topic' ) ), 'kind' => 'column' );
				$last = 'column';
			}
		}
		return $items;
	}

	/**
	 * 公開を待っている記事（自動投稿の書きため）。古い順。
	 *
	 * @return int[]
	 */
	public static function stock() {
		return get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'draft',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'meta_key'       => self::META_READY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}

	/**
	 * 今日の公開時刻を過ぎた分だけ、書きためから公開する。
	 *
	 * @return int 公開した本数。
	 */
	public static function publish_due() {
		$state = self::state();
		$today = current_time( 'Y-m-d' );
		$now   = current_time( 'H:i' );
		$due   = count(
			array_filter(
				self::slots(),
				function ( $slot ) use ( $now ) {
					return $slot <= $now;
				}
			)
		);
		$done  = isset( $state['published'][ $today ] ) ? (int) $state['published'][ $today ] : 0;
		$count = 0;
		$stock = self::stock();
		$mode  = self::settings()['mode'];
		while ( $done < $due && $stock ) {
			$post_id = array_shift( $stock );
			delete_post_meta( $post_id, self::META_READY );
			if ( 'publish' === $mode ) {
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish', 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', true ) ) );
				self::log( '公開しました: ' . get_the_title( $post_id ) );
			} else {
				self::log( '下書きを用意しました（確認して公開してください）: ' . get_the_title( $post_id ) );
				wp_mail( get_option( 'admin_email' ), '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] 自動執筆の記事を確認してください', "次の記事が下書きとしてできあがりました。内容を確認して公開してください。\n\n" . get_the_title( $post_id ) . "\n" . admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
			}
			$done++;
			$count++;
		}
		// 古い日付の記録は消す。
		$state['published'] = array( $today => $done );
		self::save_state( $state );
		return $count;
	}

	/**
	 * 書きためが2日分より少なく、頼んでいる途中のものが無ければ、足りない分を頼む。
	 */
	public static function maybe_order() {
		$state = self::state();
		if ( $state['batches'] || '' === self::api_key() ) {
			return false;
		}
		$want = max( 1, (int) self::settings()['per_day'] ) * 2 - count( self::stock() );
		if ( $want <= 0 ) {
			return false;
		}
		return self::order( self::next_items( min( 5, $want ) ) );
	}

	/**
	 * まだ使っていないテーマを1つ。無ければ空文字（AI にテーマから考えてもらう）。
	 */
	public static function next_topic( array $taken = array() ) {
		$state = self::state();
		$used  = array_flip( (array) $state['used'] );
		foreach ( preg_split( '/\R/u', (string) self::settings()['topics'] ) as $topic ) {
			$topic = trim( $topic );
			if ( '' !== $topic && ! isset( $used[ $topic ] ) && ! in_array( $topic, $taken, true ) && ! get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'title' => $topic, 'fields' => 'ids', 'numberposts' => 1 ) ) ) {
				return $topic;
			}
		}
		return '';
	}

	/* --------------------------------------------------------------------
	 * 執筆の依頼（プロンプト）
	 * ------------------------------------------------------------------ */

	public static function system_prompt() {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return apply_filters(
			'snscs_autopost_system_prompt',
			<<<PROMPT
あなたは、SNSで発信する人のためのメディア「{$site}」の編集者兼ライターです。{$site}は、X・YouTube・Instagram・Threads・TikTok・Substackで発信している人を探せる検索サイトで、SNS運用に役立つコラムを掲載しています。

読者は、これからSNS発信を始める人から、伸び悩んでいる中級者までです。専門用語には短い説明を添え、すぐに試せる具体的な手順と例を中心に書いてください。

記事の条件:
- 日本語、です・ます調。本文は全角換算で4500〜6000字（目安5000字）。
- 構成: リード文（2〜3段落。この記事で何が分かるか）→ 本文の章（h2）を5〜7個。各章は h3 で2〜4つに分け、手順・具体例・よくある失敗と対処を入れる → 最後の章は「まとめ」で、要点の箇条書きと、今日からできる最初の一歩。
- 少なくとも1か所に、すぐ使えるチェックリスト（ul）か、比較の表（table）を入れる。
- 使えるHTMLタグは h2, h3, p, ul, ol, li, strong, table, thead, tbody, tr, th, td, blockquote だけ。h1、画像、リンク、スタイルは使わない。
- 統計や調査の数字、人名・企業名の事例、SNSの最新の仕様・アルゴリズムを、確かめられない形で断定しない。仕様や数値に触れるときは「変わることがあるので公式の案内を確認してください」と添える。架空の体験談を事実のように書かない。
- 法律（著作権、景品表示法、ステルスマーケティング規制など）や税金・お金に関わる内容は一般的な説明にとどめ、必要に応じて専門家や公式情報の確認をすすめる。
- 読者を不安にさせる誇張、楽して稼げるといった表現、特定の商品の宣伝はしない。
- 本文の中で、同じジャンルやSNSで活躍している発信者を{$site}で探して参考にする、という使い方を自然に1回だけ紹介してよい（宣伝のくり返しはしない）。

返す内容: title（32字前後。検索されやすい言葉を前半に）、excerpt（記事の要約。100〜120字）、sns（記事が主に扱うSNSのキー。全SNS向けなら空）、genres（与えたジャンル一覧から当てはまるものだけ。無ければ空）、body_html（本文のHTML。タイトルは含めない）。
PROMPT
		);
	}

	/**
	 * 1本分の依頼文。
	 *
	 * @param string $topic   テーマ（空なら AI が選ぶ）。
	 * @param string $rewrite 書き直す元の記事の本文（書き直しのとき）。
	 */
	public static function user_prompt( $topic, $rewrite = '' ) {
		$titles = get_posts( array( 'post_type' => 'post', 'post_status' => array( 'publish', 'draft', 'future' ), 'posts_per_page' => 80, 'category' => SNSCS_Columns::category_id(), 'fields' => 'ids', 'no_found_rows' => true ) );
		$titles = array_map( 'get_the_title', $titles );
		$genres = get_terms( array( 'taxonomy' => SNSCS_Post_Types::TAX_GENRE, 'hide_empty' => false, 'fields' => 'names', 'number' => 100 ) );
		$trends = SNSCS_Trends::lists( 10 );

		$lines = array();
		if ( '' !== $rewrite ) {
			$lines[] = '次の記事を、条件どおりの充実した記事（5000字前後）に書き直してください。テーマと題名の方向性は変えずに、内容を大きく深めてください。';
			$lines[] = 'テーマ: ' . $topic;
			$lines[] = '元の記事（参考）:';
			$lines[] = wp_strip_all_tags( $rewrite );
		} elseif ( '' !== $topic ) {
			$lines[] = '次のテーマで記事を書いてください。';
			$lines[] = 'テーマ: ' . $topic;
		} else {
			$lines[] = 'SNS運用に役立つ記事のテーマを1つ選んで、記事を書いてください。下の「すでにある記事」と内容が重ならないテーマにしてください。';
			if ( $trends['popular'] ) {
				$lines[] = 'サイトでよく検索されている言葉（テーマ選びのヒント）: ' . implode( '、', $trends['popular'] );
			}
		}
		$lines[] = '';
		$lines[] = 'SNSのキー: ' . implode( ', ', array_keys( snscs_platforms() ) ) . '（' . implode( '、', snscs_platforms() ) . '）';
		if ( $genres && ! is_wp_error( $genres ) ) {
			$lines[] = 'ジャンル一覧: ' . implode( '、', $genres );
		}
		if ( $titles ) {
			$lines[] = 'すでにある記事（同じ内容のくり返しは避ける）: ' . implode( ' / ', array_slice( $titles, 0, 80 ) );
		}
		return implode( "\n", $lines );
	}

	public static function schema() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'title', 'excerpt', 'sns', 'genres', 'body_html' ),
			'properties'           => array(
				'title'     => array( 'type' => 'string' ),
				'excerpt'   => array( 'type' => 'string' ),
				'sns'       => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array_keys( snscs_platforms() ) ) ),
				'genres'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'body_html' => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Messages API に送る中身（バッチでも、すぐに1本作るときも同じ）。
	 */
	public static function params( $topic, $rewrite = '', array $item = array() ) {
		$prompt = '' === $rewrite ? SNSCS_Column_Kinds::user_prompt( $item ) : null;
		return array(
			'model'         => self::settings()['model'],
			'max_tokens'    => self::MAX_TOKENS,
			'system'        => self::system_prompt(),
			'output_config' => array(
				'effort' => 'high',
				'format' => array( 'type' => 'json_schema', 'schema' => self::schema() ),
			),
			'messages'      => array(
				array( 'role' => 'user', 'content' => null !== $prompt ? $prompt : self::user_prompt( $topic, $rewrite ) ),
			),
		);
	}

	/* --------------------------------------------------------------------
	 * API の呼び出し
	 * ------------------------------------------------------------------ */

	private static function request( $method, $path, $body = null, array $extra_headers = array(), $timeout = 60 ) {
		return SNSCS_Claude::request( $method, $path, $body, $extra_headers, $timeout );
	}

	/**
	 * まとめて執筆を頼む（Message Batches API）。
	 *
	 * @param array<string,array{topic:string,rewrite?:int}> $items custom_id => テーマ（書き直しなら元の記事ID）。
	 * @return bool
	 */
	public static function order( array $items ) {
		$requests = array();
		foreach ( $items as $cid => $item ) {
			$rewrite    = ! empty( $item['rewrite'] ) ? (string) get_post_field( 'post_content', $item['rewrite'] ) : '';
			$requests[] = array( 'custom_id' => $cid, 'params' => self::params( $item['topic'], $rewrite, $item ) );
		}
		$batch = self::request( 'POST', '/v1/messages/batches', array( 'requests' => $requests ) );
		if ( is_wp_error( $batch ) || empty( $batch['id'] ) ) {
			self::log( '執筆の依頼に失敗しました: ' . ( is_wp_error( $batch ) ? $batch->get_error_message() : '不明な応答' ), 'error' );
			return false;
		}
		$state              = self::state();
		$state['batches'][] = array( 'id' => (string) $batch['id'], 'created' => time(), 'items' => $items );
		$today              = current_time( 'Y-m-d' );
		foreach ( $items as $item ) {
			$kind = $item['kind'] ?? 'column';
			if ( 'guide' === $kind ) {
				$state['guides'][]   = $item['sns'] . '|' . $item['genre'];
				$state['last_kind'] = 'guide';
			} elseif ( 'trend' === $kind ) {
				$state['trend_done'][ $item['term'] ] = $today;
			} elseif ( 'column' === $kind && empty( $item['rewrite'] ) ) {
				if ( '' !== $item['topic'] ) {
					$state['used'][] = $item['topic'];
				}
				$state['last_kind'] = 'column';
			}
		}
		// トレンド記事の記録は30日分だけ残す。
		$state['trend_done'] = array_filter(
			(array) $state['trend_done'],
			function ( $day ) {
				return $day >= gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -30 days' ) );
			}
		);
		self::save_state( $state );
		self::log( sprintf( '%d本の執筆を依頼しました（多くは1時間以内に仕上がります）。', count( $items ) ) );
		return true;
	}

	/**
	 * 依頼中のまとめを確認し、終わったものの記事を受け取る。
	 *
	 * @return int 保存した記事の数。
	 */
	public static function collect() {
		$state = self::state();
		if ( ! $state['batches'] ) {
			return 0;
		}
		$saved = 0;
		$keep  = array();
		foreach ( $state['batches'] as $batch ) {
			$info = self::request( 'GET', '/v1/messages/batches/' . rawurlencode( $batch['id'] ) );
			if ( is_wp_error( $info ) ) {
				// 25時間たっても確認できないものは諦める（バッチは24時間で期限切れ）。
				if ( time() - (int) $batch['created'] < 25 * HOUR_IN_SECONDS ) {
					$keep[] = $batch;
				} else {
					self::log( '執筆の依頼を確認できないため取り消しました: ' . $info->get_error_message(), 'error' );
				}
				continue;
			}
			if ( 'ended' !== ( $info['processing_status'] ?? '' ) ) {
				$keep[] = $batch;
				continue;
			}
			$rows = SNSCS_Claude::batch_results( $batch['id'] );
			if ( is_wp_error( $rows ) ) {
				$keep[] = $batch;
				continue;
			}
			foreach ( $rows as $row ) {
				if ( ! isset( $batch['items'][ $row['custom_id'] ] ) ) {
					continue;
				}
				$item = $batch['items'][ $row['custom_id'] ];
				if ( 'succeeded' !== ( $row['result']['type'] ?? '' ) ) {
					self::log( '執筆できませんでした（' . ( $item['topic'] ? $item['topic'] : 'おまかせ' ) . '）: ' . ( $row['result']['type'] ?? '不明' ), 'error' );
					continue;
				}
				$post_id = self::save_article( $row['result']['message'], $item );
				if ( ! is_wp_error( $post_id ) ) {
					$saved++;
				} else {
					self::log( $post_id->get_error_message() . '（' . ( $item['topic'] ? $item['topic'] : 'おまかせ' ) . '）', 'error' );
				}
			}
		}
		$fresh            = self::state();
		$fresh['batches'] = $keep;
		self::save_state( $fresh );
		return $saved;
	}

	/**
	 * すぐに1本作る（管理画面のテスト用。数分かかる）。
	 *
	 * @return int|WP_Error 下書きの投稿ID。
	 */
	public static function generate_now( $topic ) {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 900 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		$body              = self::params( $topic );
		$body['fallbacks'] = 'default';
		$message           = self::request( 'POST', '/v1/messages', $body, array( 'anthropic-beta' => 'server-side-fallback-2026-07-01' ), 600 );
		if ( is_wp_error( $message ) ) {
			return $message;
		}
		if ( '' !== $topic ) {
			$state           = self::state();
			$state['used'][] = $topic;
			self::save_state( $state );
		}
		return self::save_article( $message, array( 'topic' => $topic, 'ready' => false ) );
	}

	/* --------------------------------------------------------------------
	 * 記事の保存
	 * ------------------------------------------------------------------ */

	/**
	 * API の返事から記事を作る（書き直しなら元の記事を更新）。
	 *
	 * @param array $message Messages API の message。
	 * @param array $item    topic、rewrite（書き直す記事ID）、ready（書きためにするか。初期 true）。
	 * @return int|WP_Error
	 */
	public static function save_article( array $message, array $item ) {
		$stop = $message['stop_reason'] ?? '';
		if ( 'refusal' === $stop ) {
			return new WP_Error( 'snscs_refusal', 'AIがこのテーマの執筆を断りました' );
		}
		if ( 'max_tokens' === $stop ) {
			return new WP_Error( 'snscs_cut', '記事が長くなりすぎて途中で切れました' );
		}
		$data = json_decode( SNSCS_Claude::text_of( $message ), true );
		if ( ! is_array( $data ) || empty( $data['title'] ) || empty( $data['body_html'] ) ) {
			return new WP_Error( 'snscs_format', '返ってきた記事の形が正しくありません' );
		}
		$body  = self::clean_body( (string) $data['body_html'] );
		$chars = mb_strlen( preg_replace( '/\s+/u', '', wp_strip_all_tags( $body ) ) );
		$h2    = preg_match_all( '/<h2[\s>]/i', $body );
		if ( $chars < SNSCS_Column_Kinds::min_chars( $item ) || $h2 < self::MIN_H2 ) {
			return new WP_Error( 'snscs_short', sprintf( '記事が条件に届きませんでした（%d字・見出し%d個）', $chars, $h2 ) );
		}
		$content = SNSCS_Column_Kinds::decorate( self::with_toc( $body ), $item );
		$kind    = $item['kind'] ?? 'column';
		$title   = mb_substr( sanitize_text_field( $data['title'] ), 0, 80 );
		$excerpt = mb_substr( sanitize_text_field( (string) $data['excerpt'] ), 0, 200 );

		if ( ! empty( $item['rewrite'] ) ) {
			$post_id = (int) $item['rewrite'];
			wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $content, 'post_excerpt' => $excerpt ) ) );
			update_post_meta( $post_id, self::META_AI, 1 );
			self::log( sprintf( '書き直しました（%d字）: %s', $chars, get_the_title( $post_id ) ) );
			return $post_id;
		}

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'     => 'post',
					'post_status'   => 'draft',
					'post_title'    => $title,
					'post_excerpt'  => $excerpt,
					'post_content'  => $content,
					'post_category' => array_filter( array( SNSCS_Columns::category_id() ) ),
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$sns = array_values( array_intersect( (array) $data['sns'], array_keys( snscs_platforms() ) ) );
		if ( $sns ) {
			wp_set_object_terms( $post_id, $sns, SNSCS_Columns::TAX_SNS );
		}
		$genre_ids = array();
		foreach ( (array) $data['genres'] as $name ) {
			$term = get_term_by( 'name', sanitize_text_field( $name ), SNSCS_Post_Types::TAX_GENRE );
			if ( $term ) {
				$genre_ids[] = (int) $term->term_id;
			}
		}
		if ( $genre_ids ) {
			wp_set_object_terms( $post_id, $genre_ids, SNSCS_Post_Types::TAX_GENRE );
		}
		update_post_meta( $post_id, self::META_AI, 1 );
		SNSCS_Column_Kinds::remember( $post_id, $item );
		$timely = in_array( $kind, array( 'trend', 'report' ), true );
		if ( ! $timely && ( ! isset( $item['ready'] ) || $item['ready'] ) ) {
			update_post_meta( $post_id, self::META_READY, 1 );
		}
		if ( self::settings()['eyecatch'] ) {
			self::make_eyecatch( $post_id, $title, 'guide' === $kind ? array( $item['sns'] ) : $sns, 'column' === $kind ? '' : SNSCS_Column_Kinds::labels()[ $kind ] );
		}
		self::log( sprintf( '%sができました（%d字・見出し%d個）: %s', SNSCS_Column_Kinds::labels()[ $kind ], $chars, $h2, $title ) );
		// トレンド記事・レポートは鮮度が大事なので、書きためずにすぐ公開する。
		if ( $timely && ( ! isset( $item['ready'] ) || $item['ready'] ) ) {
			if ( self::settings()['enabled'] && 'publish' === self::settings()['mode'] ) {
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish', 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', true ) ) );
				self::log( '公開しました: ' . $title );
			} else {
				wp_mail( get_option( 'admin_email' ), '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . SNSCS_Column_Kinds::labels()[ $kind ] . 'を確認してください', "次の記事が下書きとしてできあがりました。内容を確認して公開してください。\n\n" . $title . "\n" . admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
			}
		}
		return (int) $post_id;
	}

	/**
	 * 本文のHTMLを、許可したタグだけにする。
	 */
	public static function clean_body( $html ) {
		$allowed = array();
		foreach ( array( 'h2', 'h3', 'p', 'ul', 'ol', 'li', 'strong', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'blockquote', 'br' ) as $tag ) {
			$allowed[ $tag ] = array();
		}
		return trim( wp_kses( $html, $allowed ) );
	}

	/**
	 * 章（h2）に印を付け、本文の前に目次を入れる。
	 */
	public static function with_toc( $body ) {
		$items = array();
		$n     = 0;
		$body  = preg_replace_callback(
			'#<h2>(.*?)</h2>#is',
			function ( $m ) use ( &$items, &$n ) {
				$n++;
				$items[] = '<li><a href="#section-' . $n . '">' . wp_strip_all_tags( $m[1] ) . '</a></li>';
				return '<h2 id="section-' . $n . '">' . $m[1] . '</h2>';
			},
			$body
		);
		if ( count( $items ) < 3 ) {
			return $body;
		}
		// リード文（最初の h2 より前）のあとに目次を置く。
		$toc = '<nav class="snscs-toc" aria-label="目次"><p class="snscs-toc__title">目次</p><ol>' . implode( '', $items ) . '</ol></nav>';
		$pos = strpos( $body, '<h2 id="section-1"' );
		return false === $pos ? $toc . $body : substr( $body, 0, $pos ) . $toc . substr( $body, $pos );
	}

	/**
	 * タイトル入りのアイキャッチ画像（1200×630）を作って設定する。
	 */
	public static function make_eyecatch( $post_id, $title, array $sns, $kind_label = '' ) {
		$font = SNSCS_Share::font();
		if ( ! $font || ! function_exists( 'imagecreatetruecolor' ) ) {
			return 0;
		}
		$palette = array(
			'x'         => array( 15, 23, 42 ),
			'youtube'   => array( 185, 28, 28 ),
			'instagram' => array( 190, 24, 93 ),
			'threads'   => array( 31, 41, 55 ),
			'tiktok'    => array( 13, 148, 136 ),
			'substack'  => array( 194, 65, 12 ),
		);
		$rgb   = $sns && isset( $palette[ $sns[0] ] ) ? $palette[ $sns[0] ] : array( 29, 78, 216 );
		$image = imagecreatetruecolor( 1200, 630 );
		for ( $y = 0; $y < 630; $y++ ) {
			$t = $y / 630 * 0.35;
			imageline( $image, 0, $y, 1200, $y, imagecolorallocate( $image, (int) ( $rgb[0] + ( 255 - $rgb[0] ) * $t * 0.4 ), (int) ( $rgb[1] + ( 255 - $rgb[1] ) * $t * 0.4 ), (int) ( $rgb[2] + ( 255 - $rgb[2] ) * $t * 0.4 ) ) );
		}
		$white = imagecolorallocate( $image, 255, 255, 255 );
		$soft  = imagecolorallocatealpha( $image, 255, 255, 255, 60 );
		$label = '' !== $kind_label ? $kind_label : ( $sns ? implode( ' / ', array_map( 'snscs_platform_label', $sns ) ) . ' 運用コラム' : 'SNS運用コラム' );
		// テーマによって画像の左右や上下が切り取られるため、文字は中央の範囲（幅780px）にまとめる。
		$center = function ( $text, $size, $y, $color ) use ( $image, $font ) {
			$box = imagettfbbox( $size, 0, $font, $text );
			imagettftext( $image, $size, 0, (int) ( 600 - ( $box[2] - $box[0] ) / 2 ), $y, $color, $font, $text );
		};

		// タイトルを中央の幅に合わせて折り返す（最大4行）。
		$size  = 44;
		$lines = array();
		$line  = '';
		foreach ( preg_split( '//u', $title, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			$box = imagettfbbox( $size, 0, $font, $line . $char );
			if ( $box[2] - $box[0] > 780 && '' !== $line ) {
				$lines[] = $line;
				$line    = '';
			}
			$line .= $char;
		}
		$lines[] = $line;
		if ( count( $lines ) > 4 ) {
			$lines    = array_slice( $lines, 0, 4 );
			$lines[3] = mb_substr( $lines[3], 0, mb_strlen( $lines[3] ) - 1 ) . '…';
		}
		$height = count( $lines ) * 72;
		$y      = (int) ( 315 - $height / 2 + 50 );
		$center( $label, 24, $y - 70, $soft );
		foreach ( $lines as $text ) {
			$center( $text, $size, $y, $white );
			$y += 72;
		}
		$center( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 22, $y + 30, $soft );

		ob_start();
		imagepng( $image );
		$png = ob_get_clean();
		imagedestroy( $image );
		$upload = wp_upload_bits( 'snscs-column-' . $post_id . '.png', null, $png );
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}
		$attachment = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => $title,
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$post_id
		);
		if ( ! $attachment || is_wp_error( $attachment ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $upload['file'] ) );
		set_post_thumbnail( $post_id, $attachment );
		return (int) $attachment;
	}

	/**
	 * AIで書いた記事の最後に、そのことを書き添える（設定でオフにできる）。
	 */
	public static function append_note( $content ) {
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() || ! get_post_meta( get_the_ID(), self::META_AI, true ) || ! self::settings()['ai_note'] ) {
			return $content;
		}
		return $content . '<p class="snscs-ai-note">この記事は生成AIを活用して作成し、編集部で公開しています。SNSの仕様や制度は変わることがあるため、最新の情報は各サービスの公式の案内でご確認ください。</p>';
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, '記事の自動投稿', '記事の自動投稿', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	public static function admin_url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE . '&page=' . self::ADMIN_PAGE ) );
	}

	private static function verify( $action ) {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snscs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snscs_nonce'] ) ), $action ) ) {
			wp_die( 'この操作は許可されていません。' );
		}
	}

	public static function handle_save() {
		self::verify( 'snscs_autopost_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verify() で確認済み。
		$in       = wp_unslash( $_POST );
		$settings = self::settings();
		$settings['enabled']  = empty( $in['enabled'] ) ? 0 : 1;
		$settings['ai_note']  = empty( $in['ai_note'] ) ? 0 : 1;
		$settings['eyecatch'] = empty( $in['eyecatch'] ) ? 0 : 1;
		$settings['report']   = empty( $in['report'] ) ? 0 : 1;
		$settings['guides']   = empty( $in['guides'] ) ? 0 : 1;
		$settings['autolink'] = empty( $in['autolink'] ) ? 0 : 1;
		$settings['trend_per_day'] = min( 3, max( 0, (int) ( $in['trend_per_day'] ?? 1 ) ) );
		$settings['per_day']  = min( 5, max( 1, (int) ( $in['per_day'] ?? 2 ) ) );
		$settings['times']    = sanitize_text_field( (string) ( $in['times'] ?? '' ) );
		$settings['mode']     = 'draft' === ( $in['mode'] ?? '' ) ? 'draft' : 'publish';
		$settings['model']    = array_key_exists( $in['model'] ?? '', self::models() ) ? $in['model'] : 'claude-opus-5-5';
		$settings['topics']   = sanitize_textarea_field( (string) ( $in['topics'] ?? '' ) );
		$key                  = trim( sanitize_text_field( (string) ( $in['api_key'] ?? '' ) ) );
		if ( '' !== $key ) {
			$settings['api_key'] = $key;
		}
		if ( ! empty( $in['forget_key'] ) ) {
			$settings['api_key'] = '';
		}
		// phpcs:enable
		update_option( self::OPTION, $settings, false );
		wp_safe_redirect( self::admin_url( array( 'snscs_msg' => 'saved' ) ) );
		exit;
	}

	public static function handle_run() {
		self::verify( 'snscs_autopost_run' );
		$do  = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$msg = '';
		if ( 'now' === $do ) {
			$result = self::generate_now( self::next_topic() );
			if ( is_wp_error( $result ) ) {
				self::log( 'すぐに作る: ' . $result->get_error_message(), 'error' );
				$msg = 'failed';
			} else {
				$msg = 'made-' . $result;
			}
		} elseif ( 'order' === $do ) {
			$state = self::state();
			if ( $state['batches'] ) {
				$msg = 'busy';
			} else {
				$msg = self::order( self::next_items( max( 1, (int) self::settings()['per_day'] ) ) ) ? 'ordered' : 'failed';
			}
		} elseif ( 'rewrite' === $do ) {
			$items = array();
			foreach ( SNSCS_Columns::starters() as $starter ) {
				$found = get_posts( array( 'post_type' => 'post', 'post_status' => array( 'publish', 'draft', 'pending', 'future' ), 'title' => $starter['title'], 'fields' => 'ids', 'numberposts' => 1 ) );
				if ( $found && ! get_post_meta( $found[0], self::META_AI, true ) ) {
					$items[ 'rw-' . $found[0] ] = array( 'topic' => $starter['title'], 'rewrite' => (int) $found[0] );
				}
			}
			$msg = $items ? ( self::order( $items ) ? 'ordered' : 'failed' ) : 'nothing';
		} elseif ( 'kinds' === $do ) {
			$msg = self::order_kinds( true ) ? 'ordered' : 'no-kinds';
		} elseif ( 'check' === $do ) {
			self::tick();
			$msg = 'checked';
		}
		wp_safe_redirect( self::admin_url( array( 'snscs_msg' => $msg ) ) );
		exit;
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s        = self::settings();
		$state    = self::state();
		$stock    = self::stock();
		$has_key  = '' !== self::api_key();
		$from_cfg = defined( 'SNSCS_ANTHROPIC_API_KEY' ) && SNSCS_ANTHROPIC_API_KEY;
		$log      = (array) get_option( self::LOG, array() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg      = isset( $_GET['snscs_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['snscs_msg'] ) ) : '';
		$notices  = array(
			'saved'   => array( 'success', '設定を保存しました。' ),
			'ordered' => array( 'success', '執筆を依頼しました。仕上がるまで（多くは1時間以内）お待ちください。この画面の「仕上がりを確認」で受け取れます。' ),
			'busy'    => array( 'warning', 'いま依頼中の記事があります。仕上がってから、もう一度お試しください。' ),
			'failed'  => array( 'error', 'うまくいきませんでした。下の「記録」を確認してください。' ),
			'nothing' => array( 'warning', '書き直すスターター記事が見つかりませんでした（「SNS運用コラム」の画面で下書きを追加してから押してください）。' ),
			'checked' => array( 'success', '仕上がりを確認しました。' ),
			'no-kinds' => array( 'warning', 'いま書けるトレンド記事・レポートはありませんでした（急上昇した言葉が少ない、今日の本数に達した、前の月の検索の記録が少ない、など）。下の「記録」も確認してください。' ),
		);
		?>
		<div class="wrap">
			<h1>記事の自動投稿</h1>
			<?php if ( 0 === strpos( $msg, 'made-' ) ) : ?>
				<?php $made = (int) substr( $msg, 5 ); ?>
				<div class="notice notice-success"><p>記事ができました（下書き）: <a href="<?php echo esc_url( get_edit_post_link( $made ) ); ?>"><?php echo esc_html( get_the_title( $made ) ); ?></a> ・ <a href="<?php echo esc_url( get_preview_post_link( $made ) ); ?>" target="_blank">プレビュー</a></p></div>
			<?php elseif ( isset( $notices[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notices[ $msg ][0] ); ?>"><p><?php echo esc_html( $notices[ $msg ][1] ); ?></p></div>
			<?php endif; ?>

			<p>AI（Anthropic の Claude）が、章立てのしっかりした記事を書き、自動で公開します。目次とタイトル入りのアイキャッチ画像も自動で付けます。記事の種類は次の4つです。</p>
			<ul style="list-style: disc; padding-left: 20px">
				<li><strong>SNS運用コラム</strong>と<strong>攻略ガイド</strong>（「料理ジャンルでInstagramを伸ばす方法」のように、発信者の多いジャンル × SNS から順に）を交互に書きため、決めた時刻に1本ずつ公開</li>
				<li><strong>トレンド記事</strong>: ジャンルで急上昇した言葉を、その日のうちに記事にしてすぐ公開</li>
				<li><strong>月刊SNSトレンドレポート</strong>: 毎月1日に、前の月の検索・フォロワーの伸びの数字から</li>
			</ul>
			<p>人気の発信者の一覧やレポートの表は、AIではなく、このサイトの本物のデータを差し込みます（発信者の一覧は表示のたびに最新）。記事の中には、トレンドのページ・特集ページ・発信者診断へのリンクも自動で付きます。</p>

			<table class="widefat striped" style="max-width: 900px">
				<tbody>
					<tr><th style="width: 220px">状態</th><td><strong><?php echo $s['enabled'] ? 'オン' : 'オフ'; ?></strong><?php echo $has_key ? '' : '（APIキーが未設定です）'; ?></td></tr>
					<tr><th>公開を待っている記事</th><td><?php echo (int) count( $stock ); ?>本</td></tr>
					<tr><th>執筆を依頼中</th><td><?php echo (int) array_sum( array_map( function ( $b ) { return count( $b['items'] ); }, $state['batches'] ) ); ?>本</td></tr>
					<tr><th>公開する時刻</th><td><?php echo esc_html( implode( '、', self::slots() ) ); ?>（今日公開した数: <?php echo (int) ( $state['published'][ current_time( 'Y-m-d' ) ] ?? 0 ); ?>本）</td></tr>
					<tr><th>次のテーマ</th><td><?php echo esc_html( self::next_topic() ? self::next_topic() : '（テーマ一覧を使い切ったため、AIがテーマを選びます）' ); ?></td></tr>
					<?php $guide = $s['guides'] ? SNSCS_Column_Kinds::next_guide( (array) $state['guides'] ) : null; ?>
					<tr><th>次の攻略ガイド</th><td><?php echo $guide ? esc_html( SNSCS_Column_Kinds::guide_topic( $guide['sns'], $guide['genre']->name ) . '（掲載中' . number_format_i18n( $guide['count'] ) . '人）' ) : '—'; ?></td></tr>
					<?php $cands = $s['trend_per_day'] ? SNSCS_Column_Kinds::trend_candidates( (array) $state['trend_done'] ) : array(); ?>
					<tr><th>トレンド記事の候補</th><td><?php echo $cands ? esc_html( implode( '、', array_map( function ( $c ) { return '「' . $c['term'] . '」（7日で' . $c['visitors'] . '人）'; }, array_slice( $cands, 0, 5 ) ) ) ) : 'いまはありません（急上昇した言葉が出ると候補になります）'; ?></td></tr>
					<tr><th>月刊レポート</th><td><?php echo $state['report_done'] ? esc_html( '最後に作った月: ' . $state['report_done'] ) : 'まだありません'; ?>（毎月1日の8時以降に、前の月の分を作ります）</td></tr>
				</tbody>
			</table>

			<h2>すぐに試す</h2>
			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>" style="display: flex; flex-wrap: wrap; gap: 8px">
				<input type="hidden" name="action" value="snscs_autopost_run">
				<?php wp_nonce_field( 'snscs_autopost_run', 'snscs_nonce' ); ?>
				<button class="button button-primary" name="do" value="now" <?php disabled( ! $has_key ); ?> onclick="this.textContent='執筆中です（2〜5分ほどかかります）…';">1本すぐに書いてみる（下書き）</button>
				<button class="button" name="do" value="order" <?php disabled( ! $has_key ); ?>>1日分をまとめて依頼する</button>
				<button class="button" name="do" value="rewrite" <?php disabled( ! $has_key ); ?> onclick="return confirm( 'スターター記事（短い版）を、5000字前後の記事に書き直します。公開中の記事も、同じURLのまま中身が新しくなります。よろしいですか？' );">スターター記事を5000字版に書き直す</button>
				<button class="button" name="do" value="kinds" <?php disabled( ! $has_key ); ?>>トレンド記事・前の月のレポートをいま依頼する</button>
				<button class="button" name="do" value="check">仕上がりを確認</button>
			</form>
			<p class="description">「1本すぐに書いてみる」は画面が数分止まります。ふだんの自動投稿は、まとめて依頼する方式（料金が半分）で、サーバーで待たずに進みます。</p>

			<form method="post" action="<?php echo esc_url( snscs_post_url() ); ?>">
				<input type="hidden" name="action" value="snscs_autopost_save">
				<?php wp_nonce_field( 'snscs_autopost_save', 'snscs_nonce' ); ?>
				<h2>設定</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">自動投稿</th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> 自動で執筆して投稿する</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-ap-key">Anthropic の APIキー</label></th>
						<td>
							<?php if ( $from_cfg ) : ?>
								<p>wp-config.php に設定されたキーを使っています。</p>
							<?php else : ?>
								<input id="snscs-ap-key" type="password" name="api_key" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $has_key ? '設定済み（変えるときだけ入力）' : 'sk-ant-...'; ?>">
								<?php if ( $has_key ) : ?>
									<label><input type="checkbox" name="forget_key" value="1"> 保存したキーを消す</label>
								<?php endif; ?>
							<?php endif; ?>
							<p class="description">platform.claude.com で作成したキー。より安全にするには、wp-config.php に <code>define( 'SNSCS_ANTHROPIC_API_KEY', 'sk-ant-...' );</code> と書いてください（その場合はこの欄は使いません）。料金の目安は下を見てください。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-ap-per">1日の本数</label></th>
						<td><input id="snscs-ap-per" type="number" name="per_day" min="1" max="5" value="<?php echo (int) $s['per_day']; ?>" class="small-text"> 本</td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-ap-times">公開する時刻</label></th>
						<td><input id="snscs-ap-times" type="text" name="times" value="<?php echo esc_attr( $s['times'] ); ?>" class="regular-text">
						<p class="description">カンマ区切り（例: 07:00,19:00）。WordPress の定期処理は1時間ごとなので、最大1時間ほどずれます。</p></td>
					</tr>
					<tr>
						<th scope="row">公開のしかた</th>
						<td>
							<label><input type="radio" name="mode" value="publish" <?php checked( $s['mode'], 'publish' ); ?>> 時刻になったら自動で公開する</label><br>
							<label><input type="radio" name="mode" value="draft" <?php checked( $s['mode'], 'draft' ); ?>> 下書きのまま止め、メールで知らせる（運営が確認してから公開）</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-ap-model">AIのモデル</label></th>
						<td>
							<select id="snscs-ap-model" name="model">
								<?php foreach ( self::models() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['model'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">記事に付けるもの</th>
						<td>
							<label><input type="checkbox" name="eyecatch" value="1" <?php checked( $s['eyecatch'] ); ?>> タイトル入りのアイキャッチ画像</label><br>
							<label><input type="checkbox" name="ai_note" value="1" <?php checked( $s['ai_note'] ); ?>> 記事の最後に「生成AIを活用して作成」の一文</label>
						</td>
					</tr>
					<tr>
						<th scope="row">記事の種類</th>
						<td>
							<label><input type="checkbox" name="guides" value="1" <?php checked( $s['guides'] ); ?>> 攻略ガイド（ジャンル × SNS）をコラムと交互に書く</label><br>
							<label>トレンド記事を1日に <input type="number" name="trend_per_day" min="0" max="3" value="<?php echo (int) $s['trend_per_day']; ?>" class="small-text"> 本まで書く（0で書かない。上の「1日の本数」とは別）</label><br>
							<label><input type="checkbox" name="report" value="1" <?php checked( $s['report'] ); ?>> 毎月1日に、月刊SNSトレンドレポートを書く</label>
						</td>
					</tr>
					<tr>
						<th scope="row">サイト内リンク</th>
						<td><label><input type="checkbox" name="autolink" value="1" <?php checked( $s['autolink'] ); ?>> コラムの中の言葉に、トレンドのページ・特集ページへのリンクを自動で付け、最後に「関連するページ」を出す（これまでの記事にも付きます）</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="snscs-ap-topics">テーマ一覧</label></th>
						<td><textarea id="snscs-ap-topics" name="topics" rows="12" class="large-text"><?php echo esc_textarea( $s['topics'] ); ?></textarea>
						<p class="description">1行に1テーマ。上から順に使います（同じタイトルの記事があるテーマは飛ばします）。使い切ると、すでにある記事と重ならないテーマをAIが選びます。</p></td>
					</tr>
				</table>
				<?php submit_button( '設定を保存' ); ?>
			</form>

			<h2>料金の目安</h2>
			<p>1本（5000字前後）あたり、Claude Opus 5.5 でおよそ10〜20円（まとめて依頼する方式で半額になった場合）。1日2本なら月に600〜1,200円ほどです。実際の料金は platform.claude.com の利用状況で確認できます。</p>

			<h2>記録</h2>
			<table class="widefat striped" style="max-width: 900px">
				<thead><tr><th style="width: 160px">日時</th><th>内容</th></tr></thead>
				<tbody>
					<?php if ( ! $log ) : ?>
						<tr><td colspan="2">まだありません。</td></tr>
					<?php endif; ?>
					<?php foreach ( $log as $row ) : ?>
						<tr><td><?php echo esc_html( $row['at'] ); ?></td><td<?php echo 'error' === $row['level'] ? ' style="color: #b32d2e"' : ''; ?>><?php echo esc_html( $row['message'] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
