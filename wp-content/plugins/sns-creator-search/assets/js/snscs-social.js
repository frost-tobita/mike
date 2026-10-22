/**
 * フォロー・いいね・リポスト・ブックマークのボタン（SNSCS_Social）。
 *
 * ログイン中: サーバーに保存する。
 * ログインしていない: ブックマークだけ、このブラウザ（localStorage）に保存する。ほかのボタンは会員登録へ案内する。
 * ログインしたら、このブラウザのブックマークをアカウントへ移す。
 */
( function () {
	var config = window.snscsSocial;
	if ( ! config ) {
		return;
	}
	var KEY = 'snscs_bookmarks';

	function readLocal() {
		try {
			var list = JSON.parse( window.localStorage.getItem( KEY ) || '[]' );
			return Array.isArray( list ) ? list : [];
		} catch ( e ) {
			return [];
		}
	}

	function writeLocal( list ) {
		try {
			if ( list.length ) {
				window.localStorage.setItem( KEY, JSON.stringify( list.slice( 0, 200 ) ) );
			} else {
				window.localStorage.removeItem( KEY );
			}
		} catch ( e ) {}
	}

	function toast( text, link ) {
		var box = document.createElement( 'div' );
		box.className = 'snscs-toast';
		box.setAttribute( 'role', 'status' );
		box.textContent = text;
		if ( link ) {
			var a = document.createElement( 'a' );
			a.href = link.href;
			a.textContent = link.label;
			box.appendChild( document.createTextNode( ' ' ) );
			box.appendChild( a );
		}
		document.body.appendChild( box );
		setTimeout( function () {
			box.remove();
		}, link ? 6000 : 2500 );
	}

	function render( button, on, count ) {
		button.classList.toggle( 'is-on', on );
		button.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		var icon = button.querySelector( '.snscs-actions__icon' );
		if ( icon ) {
			icon.textContent = on ? button.getAttribute( 'data-icon-on' ) : button.getAttribute( 'data-icon-off' );
		}
		var label = button.querySelector( '[data-label-on]' );
		if ( label ) {
			label.textContent = on ? label.getAttribute( 'data-label-on' ) : label.getAttribute( 'data-label-off' );
		}
		if ( 'number' === typeof count ) {
			var scope = button.closest( '.snscs-follow' ) || button;
			var counter = scope.querySelector( '[data-snscs-count]' );
			if ( counter ) {
				counter.textContent = count.toLocaleString( 'ja-JP' );
			}
		}
	}

	// 同じ記事のボタン（本文の上と下）をそろえる。
	function renderAll( action, id, on, count ) {
		var list = document.querySelectorAll( '[data-snscs-social="' + action + '"][data-id="' + id + '"]' );
		Array.prototype.forEach.call( list, function ( button ) {
			render( button, on, count );
		} );
	}

	var labels = { follow: 'フォロー', like: 'いいね', repost: 'リポスト', bookmark: 'ブックマーク' };

	// ログインしていない人のブックマークは、ブラウザの記録で表示をそろえる。
	if ( ! config.loggedIn ) {
		readLocal().forEach( function ( id ) {
			renderAll( 'bookmark', id, true );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-snscs-social]' ) : null;
		if ( ! button || button.disabled ) {
			return;
		}
		var action = button.getAttribute( 'data-snscs-social' );
		var id = parseInt( button.getAttribute( 'data-id' ), 10 );
		var on = 'true' !== button.getAttribute( 'aria-pressed' );

		if ( ! config.loggedIn ) {
			if ( 'bookmark' === action ) {
				var list = readLocal().filter( function ( x ) {
					return x !== id;
				} );
				if ( on ) {
					list.unshift( id );
				}
				writeLocal( list );
				renderAll( action, id, on );
				toast( on ? 'このブラウザにブックマークしました。' : 'ブックマークを外しました。', on && config.join ? { href: config.join, label: '会員登録すると、どの端末でも見られます' } : null );
				return;
			}
			if ( config.join ) {
				toast( labels[ action ] + 'するには、会員登録（無料）またはログインが必要です。', { href: config.join, label: '登録・ログインへ' } );
			}
			return;
		}

		button.disabled = true;
		renderAll( action, id, on );
		fetch( config.rest, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( { action: action, id: id, on: on } ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					if ( ! response.ok ) {
						throw new Error( data && data.message ? data.message : 'error' );
					}
					return data;
				} );
			} )
			.then( function ( data ) {
				renderAll( action, id, data.on, data.count );
			} )
			.catch( function ( error ) {
				renderAll( action, id, ! on );
				toast( 'error' === error.message ? '保存できませんでした。もう一度お試しください。' : error.message );
			} )
			.then( function () {
				button.disabled = false;
			} );
	} );

	// ログインしたら、このブラウザのブックマークをアカウントへ移す。
	if ( config.loggedIn && readLocal().length ) {
		fetch( config.rest + '/sync', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( { bookmarks: readLocal() } ),
		} )
			.then( function ( response ) {
				if ( response.ok ) {
					readLocal().forEach( function ( id ) {
						renderAll( 'bookmark', id, true );
					} );
					writeLocal( [] );
				}
			} )
			.catch( function () {} );
	}

	// 会員ページ（ログイン前）: このブラウザのブックマーク一覧。
	var box = document.querySelector( '[data-snscs-local-bookmarks]' );
	var ids = readLocal();
	if ( box && ids.length && window.fetch ) {
		fetch( box.getAttribute( 'data-cards' ) + ( box.getAttribute( 'data-cards' ).indexOf( '?' ) >= 0 ? '&' : '?' ) + 'ids=' + ids.join( ',' ) )
			.then( function ( response ) {
				return response.ok ? response.json() : [];
			} )
			.then( function ( cards ) {
				if ( ! cards.length ) {
					return;
				}
				var ul = document.createElement( 'ul' );
				ul.className = 'snscs-feed';
				cards.forEach( function ( card ) {
					var li = document.createElement( 'li' );
					li.className = 'snscs-feed__item';
					var a = document.createElement( 'a' );
					a.className = 'snscs-feed__link';
					a.href = card.url;
					var body = document.createElement( 'span' );
					body.className = 'snscs-feed__body';
					var title = document.createElement( 'span' );
					title.className = 'snscs-feed__title';
					title.textContent = card.title;
					var meta = document.createElement( 'span' );
					meta.className = 'snscs-feed__meta';
					meta.textContent = ( card.creator ? card.creator + '　' : '' ) + card.date;
					body.appendChild( title );
					body.appendChild( meta );
					a.appendChild( body );
					li.appendChild( a );
					ul.appendChild( li );
				} );
				box.innerHTML = '';
				box.appendChild( ul );
			} )
			.catch( function () {} );
	}
} )();
