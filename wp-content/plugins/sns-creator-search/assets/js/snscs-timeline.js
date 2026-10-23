/**
 * トップページの「あなたへのおすすめ」（下へ延々と続くタイムライン）と、
 * パソコンの画面で左右の列がスクロールについてくる動き（SNSCS_Timeline）。
 */
( function () {
	'use strict';

	/* ------------------------------------------------------------------
	 * 続きを読み込む
	 * ---------------------------------------------------------------- */
	var tl = document.querySelector( '.snscs-tl' );
	if ( tl && window.snscsTimeline && window.fetch ) {
		var list = tl.querySelector( '.snscs-tl__list' );
		var spinner = tl.querySelector( '.snscs-tl__spinner' );
		var moreBtn = tl.querySelector( '.snscs-tl__more' );
		var end = tl.querySelector( '.snscs-tl__end' );
		var split = function ( s ) {
			return s ? s.split( ',' ).filter( Boolean ) : [];
		};
		var state = {
			page: 1,
			articles: split( tl.getAttribute( 'data-articles' ) ),
			creators: split( tl.getAttribute( 'data-creators' ) ),
			more: '1' === tl.getAttribute( 'data-more' ),
			busy: false,
		};

		var finish = function () {
			state.more = false;
			moreBtn.hidden = true;
			end.hidden = false;
		};

		var load = function () {
			if ( state.busy || ! state.more ) {
				return;
			}
			state.busy = true;
			spinner.hidden = false;
			moreBtn.hidden = true;
			var url = new URL( window.snscsTimeline.rest );
			url.searchParams.set( 'page', state.page + 1 );
			url.searchParams.set( 'xa', state.articles.slice( -400 ).join( ',' ) );
			url.searchParams.set( 'xc', state.creators.slice( -400 ).join( ',' ) );
			fetch( url.toString(), { credentials: 'same-origin', headers: { 'X-WP-Nonce': window.snscsTimeline.nonce } } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( data ) {
					state.page++;
					if ( data.html ) {
						list.insertAdjacentHTML( 'beforeend', data.html );
					}
					state.articles = state.articles.concat( ( data.articles || [] ).map( String ) );
					state.creators = state.creators.concat( ( data.creators || [] ).map( String ) );
					if ( ! data.more || ! data.html ) {
						finish();
					} else {
						moreBtn.hidden = !! observer;
					}
				} )
				.catch( function () {
					moreBtn.hidden = false;
				} )
				.then( function () {
					state.busy = false;
					spinner.hidden = true;
					sticky();
				} );
		};

		var observer = null;
		if ( 'IntersectionObserver' in window ) {
			observer = new IntersectionObserver(
				function ( entries ) {
					if ( entries[ 0 ].isIntersecting ) {
						load();
					}
				},
				{ rootMargin: '800px 0px' }
			);
			observer.observe( tl.querySelector( '.snscs-tl__status' ) );
			if ( state.more ) {
				moreBtn.hidden = true;
			}
		}
		moreBtn.addEventListener( 'click', load );
	}

	/* ------------------------------------------------------------------
	 * 左右の列がついてくる（パソコンの画面）
	 * 列が画面より短ければ上にくっつき、長ければ下の端まで読んだところで下にくっつく（X と同じ）。
	 * ---------------------------------------------------------------- */
	var columns = document.querySelectorAll( '.snscs-pt-columns > .snscs-pt-left, .snscs-pt-columns > .snscs-pt-right, .snscs-pm__side' );
	var wide = window.matchMedia ? window.matchMedia( '(min-width: 1100px)' ) : null;

	function sticky() {
		Array.prototype.forEach.call( columns, function ( col ) {
			var isSide = col.classList.contains( 'snscs-pm__side' );
			var on = isSide ? window.innerWidth >= 960 : !! ( wide && wide.matches );
			if ( ! on ) {
				col.style.position = '';
				col.style.top = '';
				return;
			}
			var gap = 16;
			var h = col.offsetHeight;
			col.style.position = 'sticky';
			col.style.alignSelf = 'start';
			col.style.top = ( h + gap * 2 <= window.innerHeight ? gap : window.innerHeight - h - gap ) + 'px';
		} );
	}

	if ( columns.length ) {
		sticky();
		window.addEventListener( 'resize', sticky );
		window.addEventListener( 'load', sticky );
		if ( 'ResizeObserver' in window ) {
			var ro = new ResizeObserver( sticky );
			Array.prototype.forEach.call( columns, function ( col ) {
				ro.observe( col );
			} );
		}
	}
} )();
