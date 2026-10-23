<?php
/**
 * 検索の言葉を読み解く（1.21）。
 *
 * ■ 普通の文章で検索
 *   「大阪のカフェ好きで、インスタ1万人以上の人」→ 地域「大阪府」・SNS「Instagram」・フォロワー「1万人以上」で絞り込み、
 *   残った言葉「カフェ」で探す。読み取るもの:
 *     SNS        : インスタ・ユーチューバー・ティックトック などの言い方
 *     地域・ジャンル: 登録されている名前（「大阪」のように都・府・県を省いても可）と、言い換え辞書の言葉
 *     フォロワー数: 「1万人以上」「5000人以下」「1万〜5万」「10k」など
 *   「の・で・好きな・人・発信者」のような言葉は外す。自分で選んだ絞り込みは上書きしない。
 *   人の名前（発信者名・ハンドル）や、ジャンル・地域の名前だけの検索は、これまでどおりに探す。
 *   「入力したとおりに探す」（snscs_exact=1）で読み解きをやめられる。
 *
 * ■ 意味の近い言葉（AIの言い換え）
 *   結果が少なかった言葉を順番待ちに入れ、1時間ごとに Claude にまとめて依頼して、意味の近い言葉（「ゆる家事」→
 *   時短家事・ずぼら家事・家事ラク）を作って覚える（同じ言葉は二度と依頼しない）。検索では、その言葉でも探す（重みは低め）。
 *   設定（検索アルゴリズムの画面）でオンにしたときだけ。
 *
 * ■ 探されているのに、いない
 *   結果が0件だった言葉を、日ごとの人数で記録する（同じ人の同じ言葉は1日1回。誰が調べたかは残さない。90日で消す）。
 *   管理画面「探されているのに、いない」に、発信者の勧誘や記事作りの材料として出す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Query {

	const EXPAND_KEEP_DAYS = 180;
	const ZERO_KEEP_DAYS   = 90;
	const EXPAND_STATE     = 'snscs_qexp_state';
	const ADMIN_PAGE       = 'snscs-wanted';

	/** SNSの言い方 => SNSのキー（長いものから順に調べる）。 */
	const PLATFORM_WORDS = array(
		'インスタグラマー' => 'instagram',
		'インスタグラム'   => 'instagram',
		'インスタ'         => 'instagram',
		'instagram'        => 'instagram',
		'insta'            => 'instagram',
		'ユーチューバー'   => 'youtube',
		'ユーチューブ'     => 'youtube',
		'youtuber'         => 'youtube',
		'youtube'          => 'youtube',
		'ようつべ'         => 'youtube',
		'ティックトッカー' => 'tiktok',
		'ティックトック'   => 'tiktok',
		'ティクトク'       => 'tiktok',
		'tiktoker'         => 'tiktok',
		'tiktok'           => 'tiktok',
		'ツイッター'       => 'x',
		'twitter'          => 'x',
		'エックス'         => 'x',
		'スレッズ'         => 'threads',
		'threads'          => 'threads',
		'サブスタック'     => 'substack',
		'substack'         => 'substack',
	);

	/** 言葉の前後から外す助詞など。 */
	const PARTICLES = '(?:の|で|が|を|な|に|と|は|も|や)';

	/** それだけなら外す言葉。 */
	const FILLERS = array( '人', 'ひと', '方', 'かた', '発信者', 'クリエイター', 'インフルエンサー', 'さん', 'アカウント', '好き', '好きな', '系', '在住', '住み', '住んでいる', 'について', 'している', 'してる', 'やってる', 'やっている', '探す', '探して', '探したい', 'おすすめ', 'オススメ', 'いる', 'いない', 'フォロワー', 'フォロワー数', '以上', '以下', 'くらい', '程度', 'ぐらい', '人気', '有名', '発信', '発信中', '発信してる', '発信している', '投稿', '投稿してる', '投稿している', '活動', '活動中', '活動してる', '活動している', '紹介', '紹介してる', '紹介している', 'やってる人', '見つけたい', '教えて' );

	public static function init() {
		add_action( 'snscs_hourly_engine', array( __CLASS__, 'expand_collect' ), 20 );
		add_action( 'snscs_hourly_engine', array( __CLASS__, 'expand_order' ), 25 );
		add_action( 'snscs_nightly_engine', array( __CLASS__, 'purge' ), 40 );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
	}

	public static function expand_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_qexp';
	}

	public static function zero_table() {
		global $wpdb;
		return $wpdb->prefix . 'snscs_zero_queries';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$expand  = self::expand_table();
		$zero    = self::zero_table();
		dbDelta(
			"CREATE TABLE {$expand} (
  term_hash int(10) unsigned NOT NULL,
  term varchar(60) NOT NULL,
  words text NULL,
  status tinyint(1) unsigned NOT NULL DEFAULT 0,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (term_hash),
  KEY status_hits (status,hits)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$zero} (
  day date NOT NULL,
  term_hash int(10) unsigned NOT NULL,
  term varchar(100) NOT NULL,
  visitors int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,term_hash),
  KEY term_hash (term_hash)
) {$charset};"
		);
	}

	/* --------------------------------------------------------------------
	 * 普通の文章で検索
	 * ------------------------------------------------------------------ */

	/**
	 * 地域・ジャンルの名前 => [スラッグ, 正式な名前]（長い名前から順）。
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function term_names( $taxonomy ) {
		$key   = 'snscs_qterms_' . $taxonomy;
		$names = wp_cache_get( $key, 'snscs' );
		if ( is_array( $names ) ) {
			return $names;
		}
		$names = array();
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 500 ) );
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$full = SNSCS_Text::normalize( $term->name );
			if ( mb_strlen( $full ) >= 2 ) {
				$names[ $full ] = array( $term->slug, $term->name, true );
			}
			// 「大阪府」→「大阪」、「東京都」→「東京」（北海道はそのまま）。
			if ( SNSCS_Post_Types::TAX_REGION === $taxonomy && preg_match( '/^(.{2,})(都|府|県)$/u', $term->name, $m ) ) {
				$short = SNSCS_Text::normalize( $m[1] );
				if ( ! isset( $names[ $short ] ) ) {
					$names[ $short ] = array( $term->slug, $term->name, true );
				}
			}
		}
		// 言い換え辞書: ジャンル名と同じグループの言葉は、そのジャンルとして読む（「レシピ」→ 料理）。
		if ( SNSCS_Post_Types::TAX_GENRE === $taxonomy ) {
			foreach ( SNSCS_Text::synonyms() as $group ) {
				foreach ( $group as $word ) {
					if ( isset( $names[ $word ] ) ) {
						foreach ( $group as $alt ) {
							if ( mb_strlen( $alt ) >= 2 && ! isset( $names[ $alt ] ) && ! isset( self::PLATFORM_WORDS[ $alt ] ) ) {
								// 言い換えの言葉は、絞り込みにはしない（「レシピ」で探した人に、料理以外のジャンルの人も出すため）。
								$names[ $alt ] = array( $names[ $word ][0], $names[ $word ][1], false );
							}
						}
						break;
					}
				}
			}
		}
		uksort(
			$names,
			function ( $a, $b ) {
				return mb_strlen( $b ) <=> mb_strlen( $a );
			}
		);
		wp_cache_set( $key, $names, 'snscs', 600 );
		return $names;
	}

	/**
	 * 数（「1.5万」「5,000」「10k」）を人数に。
	 */
	private static function number( $digits, $unit ) {
		$n = (float) str_replace( ',', '', $digits );
		switch ( mb_strtolower( (string) $unit ) ) {
			case '万':
				$n *= 10000;
				break;
			case '千':
				$n *= 1000;
				break;
			case 'k':
				$n *= 1000;
				break;
			case 'm':
				$n *= 1000000;
				break;
		}
		return (int) round( $n );
	}

	/**
	 * 言葉を読み解く。
	 *
	 * @param string $q    入力された言葉。
	 * @param array  $args いまの絞り込み（選んであるものは上書きしない）。
	 * @return array{q:string,set:array,labels:string[]}|null 読み解けなかった（そのまま探す）ときは null。
	 */
	public static function parse( $q, array $args = array() ) {
		// L: ひらがなを残した形（助詞を見分ける）、K: ひらがなをカタカナにした形（索引と同じ。名前を探す）。
		// 1文字ずつ対応しているので、K で見つけた場所を L からも同じように消せる。
		$l = mb_strtolower( trim( preg_replace( '/\s+/u', ' ', mb_convert_kana( (string) $q, 'asKV', 'UTF-8' ) ) ), 'UTF-8' );
		if ( '' === $l || mb_strlen( $l ) > 100 ) {
			return null;
		}
		$k   = mb_convert_kana( $l, 'C', 'UTF-8' );
		$cut = function ( $pos, $len ) use ( &$l, &$k ) {
			$l = mb_substr( $l, 0, $pos ) . ' ' . mb_substr( $l, $pos + $len );
			$k = mb_substr( $k, 0, $pos ) . ' ' . mb_substr( $k, $pos + $len );
		};
		$set    = array();
		$labels = array();
		$found  = array();

		// フォロワー数（範囲 → 以上・以下）。
		$num = '(\d+(?:[.,]\d+)*)\s*(万|千|k|m)?';
		$hit = null;
		if ( preg_match( '/(?:フォロワー\s*(?:数)?\s*(?:が)?\s*)?' . $num . '\s*人?\s*(?:〜|~|ー|-|から)\s*' . $num . '\s*人?(?:まで)?/u', $l, $m ) && preg_match( '/フォロワー|万|千|k|m|人/u', $m[0] ) ) {
			$set['min_followers'] = self::number( $m[1], $m[2] );
			$set['max_followers'] = self::number( $m[3], $m[4] ?? '' );
			$hit                  = $m[0];
		} elseif ( preg_match( '/(フォロワー\s*(?:数)?\s*(?:が)?\s*)?' . $num . '\s*(人)?\s*(以上|超え?|より多い|以下|未満|まで|より少ない)?/u', $l, $m ) && ( '' !== $m[1] || '' !== ( $m[3] ?? '' ) || '' !== ( $m[4] ?? '' ) || '' !== ( $m[5] ?? '' ) ) ) {
			$n = self::number( $m[2], $m[3] ?? '' );
			if ( $n >= 10 ) {
				if ( in_array( $m[5] ?? '', array( '以下', '未満', 'まで', 'より少ない' ), true ) ) {
					$set['max_followers'] = $n;
				} else {
					$set['min_followers'] = $n;
				}
				$hit = $m[0];
			}
		}
		if ( null !== $hit ) {
			$cut( mb_strpos( $l, $hit ), mb_strlen( $hit ) );
			$found[] = 'followers';
		}

		// SNS。
		foreach ( self::PLATFORM_WORDS as $word => $platform ) {
			$w   = SNSCS_Text::normalize( $word );
			$pos = mb_strpos( $k, $w );
			if ( false !== $pos && array_key_exists( $platform, snscs_platforms() ) ) {
				$set['platform'] = $platform;
				$labels[]        = snscs_platform_label( $platform );
				$cut( $pos, mb_strlen( $w ) );
				$found[] = 'platform';
				break;
			}
		}

		// 地域・ジャンル（登録されている名前と、言い換え辞書の言葉）。
		foreach ( array( 'region' => SNSCS_Post_Types::TAX_REGION, 'genre' => SNSCS_Post_Types::TAX_GENRE ) as $key => $taxonomy ) {
			foreach ( self::term_names( $taxonomy ) as $name => $term ) {
				$pos = mb_strpos( $k, (string) $name );
				if ( false !== $pos && empty( $term[2] ) ) {
					// 言い換えの言葉はそのまま残す（言葉として探す）。同じ分類のほかの名前は探し続ける。
					continue;
				}
				if ( false !== $pos ) {
					$set[ $key ] = $term[0];
					$labels[]    = $term[1];
					$cut( $pos, mb_strlen( (string) $name ) );
					$found[] = $key;
					break;
				}
			}
		}
		if ( ! $found ) {
			return null;
		}

		if ( isset( $set['min_followers'] ) || isset( $set['max_followers'] ) ) {
			$min = $set['min_followers'] ?? 0;
			$max = $set['max_followers'] ?? 0;
			if ( $min && $max && $max < $min ) {
				list( $set['min_followers'], $set['max_followers'] ) = array( $max, $min );
				list( $min, $max ) = array( $max, $min );
			}
			$labels[] = 'フォロワー' . ( $min && $max ? snscs_format_count( $min ) . '〜' . snscs_format_count( $max ) . '人' : ( $min ? snscs_format_count( $min ) . '人以上' : snscs_format_count( $max ) . '人以下' ) );
		}

		// 残った言葉から、助詞や「人・発信者」のような言葉を外す。
		$rest = array();
		// 漢字・カタカナ・英数字に挟まれた助詞で区切る（「大阪のカフェ」→ 大阪 / カフェ。「きのこ」は区切らない）。
		$l = preg_replace( '/(?<=[\p{Han}\p{Katakana}ーa-z0-9])' . self::PARTICLES . '(?=[\p{Han}\p{Katakana}a-z0-9])/u', ' ', $l );
		foreach ( preg_split( '/[\s、。,・!！?？]+/u', $l, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			$token = preg_replace( '/^' . self::PARTICLES . '+/u', '', $token );
			$token = preg_replace( '/' . self::PARTICLES . '+$/u', '', $token );
			// 終わりの「〜好きな人」「〜を発信している発信者」「〜をしている」などを、変わらなくなるまで外す。
			do {
				$before = $token;
				// 「人」は、ひらがなのあとのときだけ外す（「芸人」「料理人」は残す）。
				$token  = preg_replace( '/(発信者|クリエイター|インフルエンサー|アカウント|さん|の人|な人|の方|(?<=[ぁ-ん])人)$/u', '', $token );
				$token  = preg_replace( '/(好きな?|系|在住|住み)$/u', '', $token );
				$token  = preg_replace( '/(?:を|で)?(発信|投稿|活動|紹介)?(して(い)?る|している|する|中|やって(い)?る)$/u', '', $token );
				$token  = preg_replace( '/' . self::PARTICLES . '+$/u', '', $token );
			} while ( $token !== $before && '' !== $token );
			if ( '' === $token || in_array( $token, self::FILLERS, true ) || ( mb_strlen( $token ) < 2 && ! preg_match( '/[a-z0-9]/', $token ) ) ) {
				continue;
			}
			$rest[] = $token;
		}
		$rest = implode( ' ', array_unique( $rest ) );

		// ジャンル・地域の名前だけの検索は、これまでどおり（言葉で探して並べる）。
		if ( 1 === count( $found ) && in_array( $found[0], array( 'genre', 'region' ), true ) && '' === $rest ) {
			return null;
		}

		// 自分で選んだ絞り込みは上書きしない。
		foreach ( array_keys( $set ) as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				unset( $set[ $key ] );
			}
		}
		return array( 'q' => $rest, 'set' => $set, 'labels' => array_values( array_unique( $labels ) ) );
	}

	/* --------------------------------------------------------------------
	 * 意味の近い言葉（AIの言い換え）
	 * ------------------------------------------------------------------ */

	/**
	 * 1つの言葉の、覚えている言い換え。
	 *
	 * @return string[]
	 */
	public static function expansions( $word ) {
		static $cache = array();
		$word = SNSCS_Text::normalize( $word );
		if ( isset( $cache[ $word ] ) ) {
			return $cache[ $word ];
		}
		$cache[ $word ] = array();
		if ( ! SNSCS_Learner::setting( 'ai_expand' ) || mb_strlen( $word ) < 2 ) {
			return $cache[ $word ];
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$words = $wpdb->get_var( $wpdb->prepare( 'SELECT words FROM ' . self::expand_table() . ' WHERE term_hash = %d AND status = 1', SNSCS_Text::hash( $word ) ) );
		if ( $words ) {
			$cache[ $word ] = array_values( array_filter( array_map( 'trim', explode( ',', $words ) ) ) );
		}
		return $cache[ $word ];
	}

	/**
	 * 結果が少なかった言葉を、言い換えの順番待ちに入れる。
	 */
	public static function queue_expansion( $q, $total ) {
		global $wpdb;
		if ( ! SNSCS_Learner::setting( 'ai_expand' ) || $total >= 10 || SNSCS_Referrals::is_bot_request() ) {
			return;
		}
		// 依頼には入力したままの言葉（ひらがなのまま）を使い、覚えるときの番号は、そろえた形から作る。
		foreach ( array_slice( preg_split( '/\s+/u', trim( (string) $q ), -1, PREG_SPLIT_NO_EMPTY ), 0, 3 ) as $word ) {
			$word = SNSCS_Trends::normalize( $word );
			if ( '' === $word || mb_strlen( $word ) > 20 ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::expand_table() . ' (term_hash, term, status, hits, updated_at) VALUES (%d, %s, 0, 1, %s) ON DUPLICATE KEY UPDATE hits = hits + 1', SNSCS_Text::hash( SNSCS_Text::normalize( $word ) ), $word, current_time( 'mysql', true ) ) );
		}
	}

	public static function expand_params( $term ) {
		return array(
			'model'         => 'claude-opus-5-5',
			'max_tokens'    => 600,
			'system'        => 'あなたはSNS発信者の検索サイトの検索担当です。利用者が入力した検索の言葉と同じ意味、またはほぼ同じものを探している人が使う別の言い方を、日本語で3〜8個挙げてください。発信者の紹介文やタグに書かれていそうな言葉（例: 「ゆる家事」→ 時短家事、ずぼら家事、家事ラク、家事効率化）にしてください。意味が広がりすぎる言葉（「生活」「趣味」など）、人名、SNSの名前、評価の言葉は入れないでください。言葉の意味が分からないときは空の配列にしてください。',
			'output_config' => array(
				'effort' => 'low',
				'format' => array(
					'type'   => 'json_schema',
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'words' ),
						'properties'           => array( 'words' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ),
					),
				),
			),
			'messages'      => array( array( 'role' => 'user', 'content' => '検索の言葉: ' . $term ) ),
		);
	}

	/**
	 * 順番待ちの言葉を、まとめて依頼する（1時間ごと。1日の上限まで）。
	 *
	 * @return int 依頼した数。
	 */
	public static function expand_order() {
		global $wpdb;
		if ( ! SNSCS_Learner::setting( 'ai_expand' ) || ! SNSCS_Claude::has_key() ) {
			return 0;
		}
		$state = (array) get_option( self::EXPAND_STATE, array() );
		if ( ! empty( $state['batch'] ) ) {
			return 0;
		}
		$today = current_time( 'Y-m-d' );
		$used  = ( $state['day'] ?? '' ) === $today ? (int) ( $state['used'] ?? 0 ) : 0;
		$limit = max( 0, (int) SNSCS_Learner::setting( 'ai_expand_per_day' ) - $used );
		if ( ! $limit ) {
			return 0;
		}
		// 2人以上が調べた言葉から（1回だけの打ち間違いなどに使わないため）。
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT term_hash, term FROM ' . self::expand_table() . ' WHERE status = 0 AND hits >= 2 ORDER BY hits DESC LIMIT %d', min( 200, $limit ) ), ARRAY_A );
		if ( ! $rows ) {
			return 0;
		}
		$requests = array();
		$items    = array();
		foreach ( $rows as $row ) {
			$cid               = 'qx-' . $row['term_hash'];
			$items[ $cid ]     = (int) $row['term_hash'];
			$requests[]        = array( 'custom_id' => $cid, 'params' => self::expand_params( $row['term'] ) );
		}
		$batch = SNSCS_Claude::request( 'POST', '/v1/messages/batches', array( 'requests' => $requests ) );
		if ( is_wp_error( $batch ) || empty( $batch['id'] ) ) {
			update_option( self::EXPAND_STATE, array( 'day' => $today, 'used' => $used, 'error' => is_wp_error( $batch ) ? $batch->get_error_message() : '不明な応答', 'at' => current_time( 'mysql' ) ), false );
			return 0;
		}
		update_option( self::EXPAND_STATE, array( 'batch' => $batch['id'], 'items' => $items, 'created' => time(), 'day' => $today, 'used' => $used + count( $items ), 'at' => current_time( 'mysql' ) ), false );
		return count( $items );
	}

	/**
	 * 仕上がった言い換えを受け取る（1時間ごと）。
	 *
	 * @return int 受け取った数。
	 */
	public static function expand_collect() {
		global $wpdb;
		$state = (array) get_option( self::EXPAND_STATE, array() );
		if ( empty( $state['batch'] ) ) {
			return 0;
		}
		$info = SNSCS_Claude::request( 'GET', '/v1/messages/batches/' . rawurlencode( $state['batch'] ) );
		if ( is_wp_error( $info ) || 'ended' !== ( $info['processing_status'] ?? '' ) ) {
			if ( time() - (int) $state['created'] > 25 * HOUR_IN_SECONDS ) {
				unset( $state['batch'], $state['items'] );
				update_option( self::EXPAND_STATE, $state, false );
			}
			return 0;
		}
		$rows = SNSCS_Claude::batch_results( $state['batch'] );
		if ( is_wp_error( $rows ) ) {
			return 0;
		}
		$done  = 0;
		$table = self::expand_table();
		foreach ( $rows as $row ) {
			if ( ! isset( $state['items'][ $row['custom_id'] ] ) ) {
				continue;
			}
			$hash  = $state['items'][ $row['custom_id'] ];
			$words = array();
			if ( 'succeeded' === ( $row['result']['type'] ?? '' ) ) {
				$data = json_decode( SNSCS_Claude::text_of( $row['result']['message'] ), true );
				foreach ( (array) ( $data['words'] ?? array() ) as $word ) {
					// 表示にも使うので、AIが書いたままの形で覚える（探すときは SNSCS_Text がそろえる）。
					$word = mb_substr( trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $word ) ) ), 0, 20 );
					if ( mb_strlen( $word ) >= 2 && false === strpos( $word, ',' ) ) {
						$words[] = $word;
					}
				}
			}
			$words = array_slice( array_values( array_unique( $words ) ), 0, 8 );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET words = %s, status = %d, updated_at = %s WHERE term_hash = %d", implode( ',', $words ), $words ? 1 : 2, current_time( 'mysql', true ), $hash ) );
			++$done;
		}
		unset( $state['batch'], $state['items'] );
		$state['done'] = $done;
		update_option( self::EXPAND_STATE, $state, false );
		return $done;
	}

	/* --------------------------------------------------------------------
	 * 探されているのに、いない（0件の言葉）
	 * ------------------------------------------------------------------ */

	public static function record_zero( $q ) {
		global $wpdb;
		$term = SNSCS_Trends::normalize( $q );
		if ( '' === $term || SNSCS_Referrals::is_bot_request() || current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		$hash = SNSCS_Text::hash( $term );
		// 同じ人の同じ言葉は、1日1回だけ数える。
		$key = 'snscs_zq_' . md5( $hash . '|' . SNSCS_Referrals::visitor_hash() );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::zero_table() . ' (day, term_hash, term, visitors) VALUES (%s, %d, %s, 1) ON DUPLICATE KEY UPDATE visitors = visitors + 1', current_time( 'Y-m-d' ), $hash, $term ) );
	}

	/**
	 * 直近の0件の言葉（人数の多い順）。
	 *
	 * @return array<int,array{term:string,visitors:int,days:int,last:string}>
	 */
	public static function zero_terms( $days = 30, $limit = 100 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . ( $days - 1 ) . ' days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT MAX(term) AS term, SUM(visitors) AS visitors, COUNT(*) AS days, MAX(day) AS last FROM ' . self::zero_table() . ' WHERE day >= %s GROUP BY term_hash ORDER BY visitors DESC, last DESC LIMIT %d', $since, $limit ), ARRAY_A );
		return array_map(
			function ( $r ) {
				return array( 'term' => $r['term'], 'visitors' => (int) $r['visitors'], 'days' => (int) $r['days'], 'last' => $r['last'] );
			},
			$rows
		);
	}

	public static function purge() {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::zero_table() . ' WHERE day < %s', gmdate( 'Y-m-d', strtotime( $today . ' -' . self::ZERO_KEEP_DAYS . ' days' ) ) ) );
		// 依頼しなかった順番待ちは、半年で消す（言い換えができたものは残す）。
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::expand_table() . ' WHERE status = 0 AND updated_at < %s', gmdate( 'Y-m-d H:i:s', time() - self::EXPAND_KEEP_DAYS * DAY_IN_SECONDS ) ) );
		// phpcs:enable
	}

	/* --------------------------------------------------------------------
	 * 管理画面
	 * ------------------------------------------------------------------ */

	public static function add_menu() {
		add_submenu_page( 'edit.php?post_type=' . SNSCS_Post_Types::POST_TYPE, '探されているのに、いない', '探されているのに、いない', 'manage_options', self::ADMIN_PAGE, array( __CLASS__, 'render_admin' ) );
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$days  = isset( $_GET['days'] ) && in_array( (int) $_GET['days'], array( 7, 30, 90 ), true ) ? (int) $_GET['days'] : 30;
		$rows  = self::zero_terms( $days, 200 );
		$state = (array) get_option( self::EXPAND_STATE, array() );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exp   = $wpdb->get_row( 'SELECT SUM(status = 0) AS waiting, SUM(status = 1) AS done, SUM(status = 2) AS none FROM ' . self::expand_table(), ARRAY_A );
		?>
		<div class="wrap">
			<h1>探されているのに、いない</h1>
			<p>検索で<strong>1人も見つからなかった言葉</strong>です。人数は「その言葉で調べた人」の数です（同じ人は1日1回）。<br>この言葉で発信している人を招待したり、コラムを書いたり、言い換え辞書に足したりするときの材料にしてください。</p>
			<p>
				<?php foreach ( array( 7, 30, 90 ) as $d ) : ?>
					<a class="button<?php echo $d === $days ? ' button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'days', $d ) ); ?>">直近<?php echo (int) $d; ?>日</a>
				<?php endforeach; ?>
			</p>
			<?php if ( $rows ) : ?>
				<table class="widefat striped" style="max-width: 900px">
					<thead><tr><th>言葉</th><th style="text-align: right">調べた人</th><th style="text-align: right">日数</th><th>最後に調べられた日</th><th>いまの結果</th></tr></thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $row['term'] ); ?></strong></td>
								<td style="text-align: right"><?php echo esc_html( number_format_i18n( $row['visitors'] ) ); ?>人</td>
								<td style="text-align: right"><?php echo (int) $row['days']; ?>日</td>
								<td><?php echo esc_html( $row['last'] ); ?></td>
								<td><a href="<?php echo esc_url( add_query_arg( 'snscs_q', rawurlencode( $row['term'] ), snscs_search_url() ) ); ?>" target="_blank" rel="noopener">検索してみる</a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p>まだありません。</p>
			<?php endif; ?>

			<h2>AIの言い換え</h2>
			<p>結果が少なかった言葉に、意味の近い言葉を作って検索に使います（「検索アルゴリズム」の画面でオン・オフと1日の上限を設定）。
				いま: <?php echo SNSCS_Learner::setting( 'ai_expand' ) ? 'オン' : 'オフ'; ?> ・ 作成済み <?php echo esc_html( number_format_i18n( (int) ( $exp['done'] ?? 0 ) ) ); ?>語 ・ 順番待ち <?php echo esc_html( number_format_i18n( (int) ( $exp['waiting'] ?? 0 ) ) ); ?>語<?php echo ! empty( $state['batch'] ) ? '（依頼中）' : ''; ?><?php echo ! empty( $state['error'] ) ? ' <span style="color:#b32d2e">' . esc_html( $state['error'] ) . '</span>' : ''; ?>
			</p>
		</div>
		<?php
	}
}
