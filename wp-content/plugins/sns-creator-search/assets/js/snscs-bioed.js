/**
 * マイページの「マイリンクのデザイン」: テンプレート・選んだらすぐ右のプレビューに出す。
 */
( function () {
	'use strict';
	var ed = document.querySelector( '.snscs-bioed' );
	if ( ! ed ) {
		return;
	}
	var form = ed.querySelector( '.snscs-bioed__form' );
	var frame = ed.querySelector( 'iframe' );
	var custom = ed.querySelector( '.snscs-bioed__custom' );
	var params = { theme: 'th', shape: 'sh', button: 'bs', font: 'fo', ring: 'ri', layout: 'ly', bg: 'cb', btn: 'cc' };
	var timer = null;

	function value( name ) {
		var el = form.querySelector( '[name="' + name + '"]:checked' ) || form.querySelector( 'input[type="color"][name="' + name + '"]' );
		return el ? el.value : '';
	}

	function refresh() {
		clearTimeout( timer );
		timer = setTimeout( function () {
			var url = new URL( ed.getAttribute( 'data-preview' ) );
			Object.keys( params ).forEach( function ( name ) {
				var v = value( name );
				if ( v ) {
					url.searchParams.set( params[ name ], v );
				}
			} );
			if ( frame && frame.src !== url.toString() ) {
				frame.src = url.toString();
			}
			if ( custom ) {
				custom.hidden = 'custom' !== value( 'theme' );
			}
		}, 250 );
	}

	form.addEventListener( 'change', refresh );
	form.addEventListener( 'input', function ( e ) {
		if ( 'color' === e.target.type ) {
			refresh();
		}
	} );

	ed.querySelectorAll( '.snscs-bioed__tpl' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var tpl = JSON.parse( b.getAttribute( 'data-tpl' ) || '{}' );
			Object.keys( tpl ).forEach( function ( name ) {
				var el = form.querySelector( '[name="' + name + '"][value="' + tpl[ name ] + '"]' );
				if ( el ) {
					el.checked = true;
				}
			} );
			ed.querySelectorAll( '.snscs-bioed__tpl' ).forEach( function ( o ) {
				o.classList.toggle( 'is-on', o === b );
			} );
			refresh();
		} );
	} );
} )();
