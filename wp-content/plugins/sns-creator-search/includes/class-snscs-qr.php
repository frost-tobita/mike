<?php
/**
 * QRコードを作る（外のサービスを使わない。名刺・チラシ・イベントでマイリンクに来てもらうため）。
 *
 * 規格（JIS X 0510 / ISO/IEC 18004）のとおり、8ビットバイトのモード・誤り訂正レベルM・型番1〜10。
 * URL なら約210文字まで入る。SVG（拡大しても粗くならない）と PNG（GD があるとき）で出す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SNSCS_QR {

	/** 型番 => [全体のコード語数, ブロックの数, 1ブロックの誤り訂正コード語数]（レベルM） */
	const VERSIONS = array(
		1  => array( 26, 1, 10 ),
		2  => array( 44, 1, 16 ),
		3  => array( 70, 1, 26 ),
		4  => array( 100, 2, 18 ),
		5  => array( 134, 2, 24 ),
		6  => array( 172, 4, 16 ),
		7  => array( 196, 4, 18 ),
		8  => array( 242, 4, 22 ),
		9  => array( 292, 5, 22 ),
		10 => array( 346, 5, 26 ),
	);

	/** 型番 => 位置合わせパターンの中心 */
	const ALIGN = array(
		1  => array(),
		2  => array( 6, 18 ),
		3  => array( 6, 22 ),
		4  => array( 6, 26 ),
		5  => array( 6, 30 ),
		6  => array( 6, 34 ),
		7  => array( 6, 22, 38 ),
		8  => array( 6, 24, 42 ),
		9  => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
	);

	private $size;
	private $modules  = array();
	private $function = array();

	/**
	 * QRコードのます目（true が黒）。長すぎるときは null。
	 *
	 * @return bool[][]|null [行][列]
	 */
	public static function matrix( $text ) {
		$data = array_values( unpack( 'C*', (string) $text ) );
		foreach ( self::VERSIONS as $version => $info ) {
			$bits = 4 + ( $version < 10 ? 8 : 16 ) + 8 * count( $data );
			if ( $bits <= self::data_codewords( $version ) * 8 ) {
				$qr = new self();
				return $qr->build( $version, $data );
			}
		}
		return null;
	}

	private static function data_codewords( $version ) {
		list( $total, $blocks, $ec ) = self::VERSIONS[ $version ];
		return $total - $blocks * $ec;
	}

	/**
	 * SVG（白い余白4ます付き）。
	 */
	public static function svg( $text, $px = 240, $color = '#111827' ) {
		$m = self::matrix( $text );
		if ( ! $m ) {
			return '';
		}
		$n    = count( $m );
		$full = $n + 8;
		$path = '';
		foreach ( $m as $y => $row ) {
			foreach ( $row as $x => $dark ) {
				if ( $dark ) {
					$path .= 'M' . ( $x + 4 ) . ' ' . ( $y + 4 ) . 'h1v1h-1z';
				}
			}
		}
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 %2$d %2$d" shape-rendering="crispEdges" role="img" aria-label="QRコード"><rect width="%2$d" height="%2$d" fill="#fff"/><path d="%3$s" fill="%4$s"/></svg>',
			(int) $px,
			$full,
			$path,
			esc_attr( $color )
		);
	}

	/**
	 * PNG（印刷用）。GD が無ければ null。
	 */
	public static function png( $text, $scale = 12 ) {
		$m = self::matrix( $text );
		if ( ! $m || ! function_exists( 'imagecreatetruecolor' ) ) {
			return null;
		}
		$n     = count( $m );
		$side  = ( $n + 8 ) * $scale;
		$img   = imagecreatetruecolor( $side, $side );
		$white = imagecolorallocate( $img, 255, 255, 255 );
		$black = imagecolorallocate( $img, 17, 24, 39 );
		imagefill( $img, 0, 0, $white );
		foreach ( $m as $y => $row ) {
			foreach ( $row as $x => $dark ) {
				if ( $dark ) {
					imagefilledrectangle( $img, ( $x + 4 ) * $scale, ( $y + 4 ) * $scale, ( $x + 5 ) * $scale - 1, ( $y + 5 ) * $scale - 1, $black );
				}
			}
		}
		ob_start();
		imagepng( $img );
		imagedestroy( $img );
		return ob_get_clean();
	}

	/* --------------------------------------------------------------------
	 * 組み立て
	 * ------------------------------------------------------------------ */

	private function build( $version, array $data ) {
		$this->size = 17 + 4 * $version;
		$n          = $this->size;
		$this->modules  = array_fill( 0, $n, array_fill( 0, $n, false ) );
		$this->function = array_fill( 0, $n, array_fill( 0, $n, false ) );

		$this->draw_function_patterns( $version );
		$codewords = $this->codewords( $version, $data );
		$this->draw_codewords( $codewords );

		// 8つのマスクを試し、いちばん読み取りやすいもの（失点が少ないもの）を選ぶ。
		$best      = 0;
		$best_cost = PHP_INT_MAX;
		$base      = $this->modules;
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$this->modules = $base;
			$this->apply_mask( $mask );
			$this->draw_format( $mask );
			$cost = $this->penalty();
			if ( $cost < $best_cost ) {
				$best      = $mask;
				$best_cost = $cost;
			}
		}
		$this->modules = $base;
		$this->apply_mask( $best );
		$this->draw_format( $best );
		return $this->modules;
	}

	private function set( $x, $y, $dark ) {
		$this->modules[ $y ][ $x ]  = (bool) $dark;
		$this->function[ $y ][ $x ] = true;
	}

	private function draw_function_patterns( $version ) {
		$n = $this->size;
		for ( $i = 0; $i < $n; $i++ ) {
			$this->set( 6, $i, 0 === $i % 2 );
			$this->set( $i, 6, 0 === $i % 2 );
		}
		foreach ( array( array( 3, 3 ), array( $n - 4, 3 ), array( 3, $n - 4 ) ) as $c ) {
			for ( $dy = -4; $dy <= 4; $dy++ ) {
				for ( $dx = -4; $dx <= 4; $dx++ ) {
					$x = $c[0] + $dx;
					$y = $c[1] + $dy;
					if ( $x >= 0 && $x < $n && $y >= 0 && $y < $n ) {
						$d = max( abs( $dx ), abs( $dy ) );
						$this->set( $x, $y, 2 !== $d && 4 !== $d );
					}
				}
			}
		}
		$pos = self::ALIGN[ $version ];
		$cnt = count( $pos );
		for ( $i = 0; $i < $cnt; $i++ ) {
			for ( $j = 0; $j < $cnt; $j++ ) {
				$corner = ( 0 === $i && 0 === $j ) || ( 0 === $i && $cnt - 1 === $j ) || ( $cnt - 1 === $i && 0 === $j );
				if ( $corner ) {
					continue;
				}
				for ( $dy = -2; $dy <= 2; $dy++ ) {
					for ( $dx = -2; $dx <= 2; $dx++ ) {
						$this->set( $pos[ $i ] + $dx, $pos[ $j ] + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
					}
				}
			}
		}
		// 形式情報の場所を先に取っておく（中身はマスクを決めてから書く）。
		$this->draw_format( 0 );
		if ( $version >= 7 ) {
			$rem = $version;
			for ( $i = 0; $i < 12; $i++ ) {
				$rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
			}
			$bits = ( $version << 12 ) | $rem;
			for ( $i = 0; $i < 18; $i++ ) {
				$bit = ( $bits >> $i ) & 1;
				$a   = $n - 11 + $i % 3;
				$b   = intdiv( $i, 3 );
				$this->set( $a, $b, $bit );
				$this->set( $b, $a, $bit );
			}
		}
	}

	private function draw_format( $mask ) {
		$n    = $this->size;
		$data = $mask; // レベルMの印は 00。
		$rem  = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
		}
		$bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;
		$bit  = function ( $i ) use ( $bits ) {
			return ( $bits >> $i ) & 1;
		};
		for ( $i = 0; $i <= 5; $i++ ) {
			$this->set( 8, $i, $bit( $i ) );
		}
		$this->set( 8, 7, $bit( 6 ) );
		$this->set( 8, 8, $bit( 7 ) );
		$this->set( 7, 8, $bit( 8 ) );
		for ( $i = 9; $i < 15; $i++ ) {
			$this->set( 14 - $i, 8, $bit( $i ) );
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$this->set( $n - 1 - $i, 8, $bit( $i ) );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$this->set( 8, $n - 15 + $i, $bit( $i ) );
		}
		$this->set( 8, $n - 8, true );
	}

	/**
	 * データ（モード・文字数・本体・埋め草）に誤り訂正を付け、ブロックを交互に並べたコード語。
	 *
	 * @return int[]
	 */
	private function codewords( $version, array $data ) {
		$bits = array();
		$push = function ( $value, $len ) use ( &$bits ) {
			for ( $i = $len - 1; $i >= 0; $i-- ) {
				$bits[] = ( $value >> $i ) & 1;
			}
		};
		$push( 4, 4 );
		$push( count( $data ), $version < 10 ? 8 : 16 );
		foreach ( $data as $byte ) {
			$push( $byte, 8 );
		}
		$capacity = self::data_codewords( $version ) * 8;
		$push( 0, min( 4, $capacity - count( $bits ) ) );
		$push( 0, ( 8 - count( $bits ) % 8 ) % 8 );
		for ( $pad = 0xEC; count( $bits ) < $capacity; $pad ^= 0xEC ^ 0x11 ) {
			$push( $pad, 8 );
		}
		$bytes = array();
		foreach ( array_chunk( $bits, 8 ) as $chunk ) {
			$bytes[] = bindec( implode( '', $chunk ) );
		}

		list( $total, $num_blocks, $ec_len ) = self::VERSIONS[ $version ];
		$short     = $num_blocks - $total % $num_blocks;
		$short_len = intdiv( $total, $num_blocks );
		$divisor   = self::rs_divisor( $ec_len );
		$blocks    = array();
		$ecs       = array();
		$k         = 0;
		for ( $i = 0; $i < $num_blocks; $i++ ) {
			$len      = $short_len - $ec_len + ( $i < $short ? 0 : 1 );
			$block    = array_slice( $bytes, $k, $len );
			$k       += $len;
			$blocks[] = $block;
			$ecs[]    = self::rs_remainder( $block, $divisor );
		}
		$out = array();
		for ( $i = 0; $i <= $short_len - $ec_len; $i++ ) {
			foreach ( $blocks as $block ) {
				if ( $i < count( $block ) ) {
					$out[] = $block[ $i ];
				}
			}
		}
		for ( $i = 0; $i < $ec_len; $i++ ) {
			foreach ( $ecs as $ec ) {
				$out[] = $ec[ $i ];
			}
		}
		return $out;
	}

	private static function gf_mul( $x, $y ) {
		$z = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D );
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}
		return $z & 0xFF;
	}

	private static function rs_divisor( $degree ) {
		$result                = array_fill( 0, $degree, 0 );
		$result[ $degree - 1 ] = 1;
		$root                  = 1;
		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$result[ $j ] = self::gf_mul( $result[ $j ], $root );
				if ( $j + 1 < $degree ) {
					$result[ $j ] ^= $result[ $j + 1 ];
				}
			}
			$root = self::gf_mul( $root, 0x02 );
		}
		return $result;
	}

	private static function rs_remainder( array $data, array $divisor ) {
		$result = array_fill( 0, count( $divisor ), 0 );
		foreach ( $data as $b ) {
			$factor   = $b ^ array_shift( $result );
			$result[] = 0;
			foreach ( $divisor as $i => $coef ) {
				$result[ $i ] ^= self::gf_mul( $coef, $factor );
			}
		}
		return $result;
	}

	private function draw_codewords( array $codewords ) {
		$n     = $this->size;
		$i     = 0;
		$total = count( $codewords ) * 8;
		for ( $right = $n - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}
			for ( $vert = 0; $vert < $n; $vert++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x      = $right - $j;
					$upward = 0 === ( ( $right + 1 ) & 2 );
					$y      = $upward ? $n - 1 - $vert : $vert;
					if ( ! $this->function[ $y ][ $x ] && $i < $total ) {
						$this->modules[ $y ][ $x ] = (bool) ( ( $codewords[ $i >> 3 ] >> ( 7 - ( $i & 7 ) ) ) & 1 );
						$i++;
					}
				}
			}
		}
	}

	private function apply_mask( $mask ) {
		$n = $this->size;
		for ( $y = 0; $y < $n; $y++ ) {
			for ( $x = 0; $x < $n; $x++ ) {
				if ( $this->function[ $y ][ $x ] ) {
					continue;
				}
				switch ( $mask ) {
					case 0:
						$flip = 0 === ( $x + $y ) % 2;
						break;
					case 1:
						$flip = 0 === $y % 2;
						break;
					case 2:
						$flip = 0 === $x % 3;
						break;
					case 3:
						$flip = 0 === ( $x + $y ) % 3;
						break;
					case 4:
						$flip = 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2;
						break;
					case 5:
						$flip = 0 === $x * $y % 2 + $x * $y % 3;
						break;
					case 6:
						$flip = 0 === ( $x * $y % 2 + $x * $y % 3 ) % 2;
						break;
					default:
						$flip = 0 === ( ( $x + $y ) % 2 + $x * $y % 3 ) % 2;
				}
				if ( $flip ) {
					$this->modules[ $y ][ $x ] = ! $this->modules[ $y ][ $x ];
				}
			}
		}
	}

	/**
	 * 読み取りにくさの点（同じ色の並び・2×2のかたまり・見つける印に似た形・白黒の偏り）。
	 */
	private function penalty() {
		$n     = $this->size;
		$m     = $this->modules;
		$score = 0;
		$dark  = 0;
		for ( $pass = 0; $pass < 2; $pass++ ) {
			for ( $a = 0; $a < $n; $a++ ) {
				$run  = 0;
				$prev = null;
				$line = '';
				for ( $b = 0; $b < $n; $b++ ) {
					$v     = $pass ? $m[ $b ][ $a ] : $m[ $a ][ $b ];
					$line .= $v ? '1' : '0';
					if ( $v === $prev ) {
						$run++;
						if ( 5 === $run ) {
							$score += 3;
						} elseif ( $run > 5 ) {
							$score++;
						}
					} else {
						$run  = 1;
						$prev = $v;
					}
				}
				$score += 40 * ( substr_count( $line, '10111010000' ) + substr_count( $line, '00001011101' ) );
			}
		}
		for ( $y = 0; $y < $n; $y++ ) {
			for ( $x = 0; $x < $n; $x++ ) {
				$dark += $m[ $y ][ $x ] ? 1 : 0;
				if ( $x < $n - 1 && $y < $n - 1 && $m[ $y ][ $x ] === $m[ $y ][ $x + 1 ] && $m[ $y ][ $x ] === $m[ $y + 1 ][ $x ] && $m[ $y ][ $x ] === $m[ $y + 1 ][ $x + 1 ] ) {
					$score += 3;
				}
			}
		}
		$score += 10 * intdiv( abs( $dark * 20 - $n * $n * 10 ), $n * $n );
		return $score;
	}
}
