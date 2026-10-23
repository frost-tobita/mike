<?php
/**
 * 検索のための文字の扱い（正規化・単語への分割・言い換え辞書）。
 *
 * 日本語は単語の区切りが無いため、2文字ずつ区切った「2文字の組（バイグラム）」を単語として扱う。
 *   「時短レシピ」→ 時短 / 短レ / レシ / シピ
 * 英数字はひとまとまりの単語（ハンドル名の _ . - でも区切る）。
 * 文字はそろえてから数える: 全角英数字→半角、半角カナ→全角、ひらがな→カタカナ、大文字→小文字。
 * これで「いんすた」「インスタ」「ｲﾝｽﾀ」は同じ単語になる。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_Text {

	/** 1つの文章から数える単語の上限（長い紹介文で記録が膨らまないように）。 */
	const MAX_TERMS = 400;

	/**
	 * 文字をそろえる。
	 */
	public static function normalize( $text ) {
		$text = mb_convert_kana( (string) $text, 'asKVC', 'UTF-8' );
		return mb_strtolower( trim( preg_replace( '/\s+/u', ' ', $text ) ), 'UTF-8' );
	}

	/**
	 * 文章を単語に分ける。
	 *
	 * @return array<string,int> 単語 => 出てきた回数。
	 */
	public static function tokens( $text ) {
		$text  = self::normalize( $text );
		$terms = array();
		// 英数字のかたまりと、それ以外（日本語など）のかたまりに分ける。記号は区切り。
		preg_match_all( '/[a-z0-9]+|[\p{Han}\p{Katakana}\p{Hiragana}ー]+/u', $text, $m );
		foreach ( $m[0] as $run ) {
			if ( preg_match( '/^[a-z0-9]+$/', $run ) ) {
				if ( strlen( $run ) >= 2 || ctype_digit( $run ) ) {
					$terms[] = substr( $run, 0, 40 );
				}
				continue;
			}
			$chars = preg_split( '//u', $run, -1, PREG_SPLIT_NO_EMPTY );
			if ( 1 === count( $chars ) ) {
				$terms[] = $chars[0];
				continue;
			}
			for ( $i = 0; $i < count( $chars ) - 1; $i++ ) {
				$terms[] = $chars[ $i ] . $chars[ $i + 1 ];
			}
		}
		$counts = array_count_values( $terms );
		return array_slice( $counts, 0, self::MAX_TERMS, true );
	}

	/**
	 * 単語を数字（記録を小さく、検索を速くするため）にする。
	 */
	public static function hash( $term ) {
		return (int) sprintf( '%u', crc32( $term ) );
	}

	/**
	 * 言い換え辞書（管理画面で編集。1行に1グループ、カンマ区切り）。
	 *
	 * @return array<int,string[]> そろえた語のグループ。
	 */
	public static function synonyms() {
		static $groups = null;
		if ( null !== $groups ) {
			return $groups;
		}
		$groups = array();
		foreach ( preg_split( '/\R/u', (string) SNSCS_Learner::setting( 'synonyms' ) ) as $line ) {
			$words = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize' ), explode( ',', str_replace( array( '、', '，' ), ',', $line ) ) ), 'strlen' ) ) );
			if ( count( $words ) >= 2 ) {
				$groups[] = $words;
			}
		}
		return $groups;
	}

	public static function default_synonyms() {
		return implode(
			"\n",
			array(
				'instagram,インスタ,インスタグラム,insta',
				'youtube,ユーチューブ,ようつべ,yt',
				'tiktok,ティックトック,ティクトク',
				'x,twitter,ツイッター,エックス',
				'threads,スレッズ',
				'substack,サブスタック,ニュースレター',
				'料理,レシピ,ごはん,自炊,クッキング',
				'美容,コスメ,メイク,スキンケア',
				'ゲーム,ゲーム実況,実況',
				'旅行,旅,おでかけ,トラベル',
				'筋トレ,トレーニング,ダイエット,フィットネス',
				'投資,資産運用,株,nisa',
				'子育て,育児,ママ,パパ',
				'ペット,犬,猫,いぬ,ねこ',
				'ファッション,コーデ,服',
				'キャンプ,アウトドア',
				'カフェ,喫茶店',
				'プログラミング,エンジニア,コード',
			)
		);
	}

	/**
	 * 検索の言葉を「グループ」に分ける。1つの空白区切りの言葉が1グループで、
	 * 言い換え辞書に載っていれば、言い換えの語の単語も同じグループに入れる（重みは少し下げる）。
	 *
	 * @return array<int,array{label:string,terms:array<int,float>}> グループごとの単語（数字） => 重み。
	 */
	public static function query_groups( $q ) {
		$groups = array();
		foreach ( array_slice( preg_split( '/[\s]+/u', self::normalize( $q ), -1, PREG_SPLIT_NO_EMPTY ), 0, 5 ) as $word ) {
			$terms    = array();
			$variants = array();
			foreach ( array_keys( self::tokens( $word ) ) as $term ) {
				$terms[ self::hash( $term ) ] = 1.0;
			}
			$variants[] = array_keys( $terms );
			foreach ( self::synonyms() as $group ) {
				if ( in_array( $word, $group, true ) ) {
					foreach ( $group as $alt ) {
						if ( $alt === $word ) {
							continue;
						}
						$variant = array();
						foreach ( array_keys( self::tokens( $alt ) ) as $term ) {
							$hash      = self::hash( $term );
							$variant[] = $hash;
							if ( ! isset( $terms[ $hash ] ) ) {
								$terms[ $hash ] = 0.7;
							}
						}
						if ( $variant ) {
							$variants[] = $variant;
						}
					}
				}
			}
			// AIが作った意味の近い言葉（SNSCS_Query）も、どれか1つがそろえば一致とみなす（重みは低め）。
			foreach ( SNSCS_Query::expansions( $word ) as $alt ) {
				$variant = array();
				foreach ( array_keys( self::tokens( $alt ) ) as $term ) {
					$hash      = self::hash( $term );
					$variant[] = $hash;
					if ( ! isset( $terms[ $hash ] ) ) {
						$terms[ $hash ] = 0.5;
					}
				}
				if ( $variant ) {
					$variants[] = $variant;
				}
			}
			if ( $terms ) {
				$groups[] = array( 'label' => $word, 'terms' => $terms, 'variants' => array_values( array_filter( $variants ) ) );
			}
		}
		return $groups;
	}

	/**
	 * 2つの文字列の違い（文字の入れ替え・追加・削除の回数）。日本語にも使える。
	 */
	public static function distance( $a, $b ) {
		$a = preg_split( '//u', $a, -1, PREG_SPLIT_NO_EMPTY );
		$b = preg_split( '//u', $b, -1, PREG_SPLIT_NO_EMPTY );
		$prev = range( 0, count( $b ) );
		foreach ( $a as $i => $ca ) {
			$cur = array( $i + 1 );
			foreach ( $b as $j => $cb ) {
				$cur[] = min( $prev[ $j + 1 ] + 1, $cur[ $j ] + 1, $prev[ $j ] + ( $ca === $cb ? 0 : 1 ) );
			}
			$prev = $cur;
		}
		return end( $prev );
	}
}
