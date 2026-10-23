/**
 * 推しリスト（SNSCS_Lists）: 発信者ページの「推しリストに追加」と、リストのいいね。
 */
( function () {
	var config = window.snscsLists;
	if ( ! config || ! window.fetch ) {
		return;
	}

	function api( path, method, body ) {
		return fetch( config.rest + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function el( tag, attrs, text ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			n.setAttribute( k, attrs[ k ] );
		} );
		if ( text ) {
			n.textContent = text;
		}
		return n;
	}

	function openPanel( button ) {
		var creator = button.getAttribute( 'data-snscs-list-add' );
		var panel = button.nextElementSibling;
		var open = button.getAttribute( 'aria-expanded' ) === 'true';
		button.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
		panel.hidden = open;
		if ( open ) {
			return;
		}
		panel.textContent = '読み込み中…';
		api( '/mine?creator=' + creator, 'GET' ).then( function ( lists ) {
			panel.textContent = '';
			var form = el( 'form', { class: 'snscs-list-add__form' } );
			( lists || [] ).forEach( function ( list, i ) {
				var label = el( 'label', { class: 'snscs-list-add__choice' } );
				var radio = el( 'input', { type: 'radio', name: 'snscs-list', value: String( list.id ) } );
				if ( list.has ) {
					radio.disabled = true;
				} else if ( ! form.querySelector( 'input:checked' ) ) {
					radio.checked = true;
				}
				label.appendChild( radio );
				label.appendChild( document.createTextNode( ' ' + list.title + '（' + list.items + '人）' + ( list.has ? ' — 追加済み' : '' ) ) );
				form.appendChild( label );
			} );
			var newLabel = el( 'label', { class: 'snscs-list-add__choice' } );
			var newRadio = el( 'input', { type: 'radio', name: 'snscs-list', value: '0' } );
			if ( ! form.querySelector( 'input:checked' ) ) {
				newRadio.checked = true;
			}
			newLabel.appendChild( newRadio );
			newLabel.appendChild( document.createTextNode( ' 新しいリスト: ' ) );
			var title = el( 'input', { type: 'text', maxlength: '60', class: 'snscs-input', placeholder: '例: 私の推し料理インスタグラマー' } );
			title.addEventListener( 'focus', function () {
				newRadio.checked = true;
			} );
			newLabel.appendChild( title );
			form.appendChild( newLabel );
			var submit = el( 'button', { type: 'submit', class: 'snscs-button snscs-button--primary' }, '追加する' );
			form.appendChild( submit );
			var status = el( 'p', { class: 'snscs-list-add__status', role: 'status' } );
			form.appendChild( status );
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				var chosen = form.querySelector( 'input[name="snscs-list"]:checked' );
				var body = { creator: Number( creator ), list: chosen ? Number( chosen.value ) : 0, title: title.value };
				submit.disabled = true;
				api( '/add', 'POST', body ).then( function ( data ) {
					submit.disabled = false;
					status.textContent = data.message || '';
					if ( data.ok && data.url ) {
						status.appendChild( document.createTextNode( ' ' ) );
						status.appendChild( el( 'a', { href: data.url }, 'リストを見る' ) );
						status.appendChild( document.createTextNode( '・' ) );
						status.appendChild( el( 'a', { href: data.edit }, 'ひとことを書く' ) );
					}
				} );
			} );
			panel.appendChild( form );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var add = event.target.closest ? event.target.closest( '[data-snscs-list-add]' ) : null;
		if ( add ) {
			event.preventDefault();
			openPanel( add );
			return;
		}
		var like = event.target.closest ? event.target.closest( '[data-snscs-list-like]' ) : null;
		if ( like ) {
			event.preventDefault();
			like.disabled = true;
			api( '/like', 'POST', { list: Number( like.getAttribute( 'data-snscs-list-like' ) ) } ).then( function ( data ) {
				like.disabled = false;
				if ( data.ok ) {
					like.classList.toggle( 'is-on', data.on );
					like.setAttribute( 'aria-pressed', data.on ? 'true' : 'false' );
					like.querySelector( 'span' ).textContent = String( data.likes );
				}
			} );
		}
	} );
} )();
