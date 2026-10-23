/**
 * 月間アワードの「投票する」ボタン（SNSCS_Awards）。
 */
( function () {
	var config = window.snscsAwards;
	if ( ! config || ! window.fetch ) {
		return;
	}
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-snscs-vote]' ) : null;
		if ( ! button || button.disabled ) {
			return;
		}
		var genre = button.getAttribute( 'data-genre' );
		button.disabled = true;
		fetch( config.rest, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( { creator: Number( button.getAttribute( 'data-creator' ) ), genre: Number( genre ) } ),
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				var row = button.parentNode;
				var note = row.querySelector( '.snscs-award__msg' ) || document.createElement( 'p' );
				note.className = 'snscs-award__msg';
				note.setAttribute( 'role', 'status' );
				note.textContent = data && data.message ? data.message : '投票できませんでした。';
				row.appendChild( note );
				if ( data && data.ok ) {
					// 同じジャンルのほかのボタンは、もう一度押せるように戻す（投票先の変更）。
					document.querySelectorAll( '[data-snscs-vote][data-genre="' + genre + '"]' ).forEach( function ( other ) {
						if ( other !== button && other.classList.contains( 'is-on' ) ) {
							other.classList.remove( 'is-on' );
							other.disabled = false;
							other.textContent = '投票する';
						}
					} );
					button.classList.add( 'is-on' );
					button.textContent = '投票済み';
				} else {
					button.disabled = false;
				}
			} )
			.catch( function () {
				button.disabled = false;
			} );
	} );
} )();
