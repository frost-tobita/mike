/**
 * 創設メンバーの記念カード（SNSCS_Founder）。
 *
 * 「記念カードを保存」を押すと、背景・メダル・通し番号・名前・参加日を 1200×630 の画像にして、
 * スマホでは共有メニュー（Xなどに直接）、パソコンでは PNG の保存にする。
 * 文字は端末のフォントで書くので、サーバーに日本語のフォントが無くても作れる。
 * メダルの下の弧の文字（FOLLOFIND · Nº 001）は、ページのメダルに重ねてある SVG をそのまま使う。
 */
( function () {
	var W = 1200;
	var H = 630;
	var SANS = '"Hiragino Sans", "Hiragino Kaku Gothic ProN", "Noto Sans JP", "Yu Gothic", Meiryo, sans-serif';
	var SERIF = '"Cormorant Garamond", "Hoefler Text", Georgia, "Times New Roman", serif';

	function load( src ) {
		return new Promise( function ( resolve, reject ) {
			var img = new Image();
			img.onload = function () {
				resolve( img );
			};
			img.onerror = reject;
			img.src = src;
		} );
	}

	// 字の間を空けて書く（canvas の letterSpacing が無いブラウザでも同じ見た目にする）。
	function spaced( ctx, text, x, y, gap ) {
		Array.prototype.forEach.call( text, function ( ch ) {
			ctx.fillText( ch, x, y );
			x += ctx.measureText( ch ).width + gap;
		} );
	}

	// 幅に収まるまで小さくする。
	function fit( ctx, text, weight, size, min, max, family ) {
		do {
			ctx.font = weight + ' ' + size + 'px ' + family;
			size -= 2;
		} while ( ctx.measureText( text ).width > max && size >= min );
		if ( ctx.measureText( text ).width > max ) {
			while ( text.length > 1 && ctx.measureText( text + '…' ).width > max ) {
				text = text.slice( 0, -1 );
			}
			text += '…';
		}
		return text;
	}

	function svgUrl( svg ) {
		return svg ? 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent( svg ) : '';
	}

	function draw( d, overlay ) {
		var srcs = [ load( d.bg ), load( d.medal ) ];
		if ( overlay ) {
			srcs.push( load( svgUrl( overlay ) ) );
		}
		return Promise.all( srcs ).then( function ( imgs ) {
			var canvas = document.createElement( 'canvas' );
			canvas.width = W;
			canvas.height = H;
			var ctx = canvas.getContext( '2d' );
			ctx.drawImage( imgs[ 0 ], 0, 0, W, H );

			// メダル（左）: 下に柔らかい影を落とす。
			var size = 470;
			var mx = 70;
			var my = ( H - size ) / 2;
			ctx.save();
			ctx.shadowColor = 'rgba(0,0,0,' + ( d.light ? 0.55 : 0.28 ) + ')';
			ctx.shadowBlur = 40;
			ctx.shadowOffsetY = 18;
			ctx.drawImage( imgs[ 1 ], mx, my, size, size );
			ctx.restore();
			if ( imgs[ 2 ] ) {
				ctx.drawImage( imgs[ 2 ], mx, my, size, size );
			}

			// 文字（右）
			var x = 620;
			var max = W - x - 70;
			var text = d.light ? '#f5f1e6' : '#1f2733';
			ctx.textBaseline = 'alphabetic';

			ctx.fillStyle = d.color;
			ctx.font = '500 20px ' + SANS;
			spaced( ctx, 'FOUNDING MEMBER', x, 178, 7 );

			ctx.font = '400 116px ' + SERIF;
			ctx.fillText( d.serial, x - 4, 300 );

			// Nº 001〜010: 番号の右上に小さく「FIRST 10 ★」の札。
			if ( d.first ) {
				var tx = x - 4 + ctx.measureText( d.serial ).width + 22;
				ctx.font = '600 15px ' + SANS;
				var tw = 0;
				Array.prototype.forEach.call( 'FIRST 10 ★', function ( ch ) {
					tw += ctx.measureText( ch ).width + 3;
				} );
				ctx.strokeStyle = d.color;
				ctx.lineWidth = 1.5;
				ctx.strokeRect( tx, 212, tw + 22, 32 );
				spaced( ctx, 'FIRST 10 ★', tx + 12, 234, 3 );
			}

			ctx.fillStyle = d.color;
			ctx.globalAlpha = 0.7;
			ctx.fillRect( x, 334, 64, 2 );
			ctx.globalAlpha = 1;

			ctx.fillStyle = text;
			ctx.fillText( fit( ctx, d.name, '700', 46, 26, max, SANS ), x, 404 );

			ctx.globalAlpha = 0.78;
			ctx.font = '400 22px ' + SANS;
			ctx.fillText( 'FolloFind 創設メンバー' + ( d.rank ? '・' + d.rank : '' ), x, 448 );
			ctx.fillText( d.date + ' 参加', x, 482 );

			ctx.globalAlpha = 0.55;
			ctx.font = '500 16px ' + SANS;
			spaced( ctx, d.site.toUpperCase(), x, H - 70, 4 );
			ctx.globalAlpha = 1;
			return canvas;
		} );
	}

	function save( canvas, d ) {
		return new Promise( function ( resolve ) {
			canvas.toBlob( function ( blob ) {
				var file = window.File ? new File( [ blob ], d.file, { type: 'image/png' } ) : null;
				// スマホ: 共有メニュー。使えない・やめたときは保存にする。
				if ( file && navigator.canShare && navigator.canShare( { files: [ file ] } ) && /Android|iPhone|iPad|iPod/i.test( navigator.userAgent ) ) {
					navigator.share( { files: [ file ], text: d.name + ' は FolloFind の創設メンバー ' + d.serial + ' です' } ).then( resolve, resolve );
					return;
				}
				var a = document.createElement( 'a' );
				a.href = URL.createObjectURL( blob );
				a.download = d.file;
				document.body.appendChild( a );
				a.click();
				setTimeout( function () {
					URL.revokeObjectURL( a.href );
					a.remove();
				}, 1000 );
				resolve();
			}, 'image/png' );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest && event.target.closest( '[data-snscs-founder-card]' );
		if ( ! button || button.disabled ) {
			return;
		}
		var d;
		try {
			d = JSON.parse( button.getAttribute( 'data-snscs-founder-card' ) );
		} catch ( e ) {
			return;
		}
		var label = button.textContent;
		button.disabled = true;
		button.textContent = '作成中…';
		var root = button.closest( '.snscs-founder' );
		var text = root && root.querySelector( '.snscs-founder__medal-text' );
		var overlay = text ? new XMLSerializer().serializeToString( text ).replace( / style="[^"]*"/, '' ) : '';
		draw( d, overlay ).then( function ( canvas ) {
			return save( canvas, d );
		} ).catch( function () {
			window.alert( '記念カードを作れませんでした。時間をおいて、もう一度お試しください。' );
		} ).then( function () {
			button.disabled = false;
			button.textContent = label;
		} );
	} );

	// 試し用: window.snscsFounderCard( data, 文字のSVG ) で canvas を返す。
	window.snscsFounderCard = draw;
} )();
