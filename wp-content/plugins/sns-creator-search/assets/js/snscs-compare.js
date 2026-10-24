/**
 * 検索結果の「比べる」（1.21）。
 *
 * カードの「＋ 比べる」で選んだ発信者を、このブラウザの localStorage にだけ覚え（最大4人。企業向けプランは10人）、
 * 画面の下に「比べる（3人）」の帯を出す。押すと比べる画面（?snscs_compare=1,2,3）を開く。
 */
( function () {
	var config = window.snscsCompare;
	if ( ! config ) {
		return;
	}
	var KEY = 'snscs_cmp';

	function load() {
		try {
			var list = JSON.parse( window.localStorage.getItem( KEY ) || '[]' );
			return Array.isArray( list ) ? list.slice( 0, config.max ) : [];
		} catch ( e ) {
			return [];
		}
	}

	function save( list ) {
		try {
			window.localStorage.setItem( KEY, JSON.stringify( list ) );
		} catch ( e ) {}
	}

	var list = load();
	var tray = document.createElement( 'div' );
	tray.className = 'snscs-cmptray';
	tray.setAttribute( 'role', 'region' );
	tray.setAttribute( 'aria-label', '比べる発信者' );
	tray.hidden = true;
	tray.innerHTML = '<span class="snscs-cmptray__text"></span><a class="snscs-cmptray__go" href="#">比べる</a><button type="button" class="snscs-cmptray__clear">選び直す</button>';
	document.body.appendChild( tray );

	function url() {
		var u = new URL( config.url, window.location.href );
		u.searchParams.set( 'snscs_compare', list.join( ',' ) );
		return u.toString();
	}

	function render() {
		var buttons = document.querySelectorAll( '[data-snscs-cmp]' );
		Array.prototype.forEach.call( buttons, function ( b ) {
			var on = list.indexOf( b.getAttribute( 'data-snscs-cmp' ) ) !== -1;
			b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			b.textContent = on ? '✓ 比べる' : '＋ 比べる';
			b.classList.toggle( 'is-on', on );
		} );
		// 比べる画面そのものでは帯を出さない（表の上に重なって読みにくいため。1.27.9）。
		tray.hidden = list.length === 0 || new URL( window.location.href ).searchParams.has( 'snscs_compare' );
		tray.querySelector( '.snscs-cmptray__text' ).textContent = list.length < 2 ? 'もう1人選ぶと比べられます（' + list.length + '人）' : list.length + '人を選んでいます（最大' + config.max + '人）';
		var go = tray.querySelector( '.snscs-cmptray__go' );
		go.href = url();
		go.classList.toggle( 'is-disabled', list.length < 2 );
		go.setAttribute( 'aria-disabled', list.length < 2 ? 'true' : 'false' );
	}

	document.addEventListener( 'click', function ( event ) {
		var b = event.target.closest ? event.target.closest( '[data-snscs-cmp]' ) : null;
		if ( b ) {
			var id = b.getAttribute( 'data-snscs-cmp' );
			var i = list.indexOf( id );
			if ( i !== -1 ) {
				list.splice( i, 1 );
			} else if ( list.length < config.max ) {
				list.push( id );
			} else {
				window.alert( '比べられるのは' + config.max + '人までです。' );
			}
			save( list );
			render();
			return;
		}
		if ( event.target.closest && event.target.closest( '.snscs-cmptray__clear' ) ) {
			list = [];
			save( list );
			render();
		}
		if ( event.target.closest && event.target.closest( '.snscs-cmptray__go.is-disabled' ) ) {
			event.preventDefault();
		}
	} );
	render();
} )();
