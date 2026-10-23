/**
 * 質問箱（SNSCS_QA）の「参考になった」ボタン。
 */
( function () {
	var config = window.snscsQA;
	if ( ! config || ! window.fetch ) {
		return;
	}
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-snscs-helpful]' ) : null;
		if ( ! button || button.disabled ) {
			return;
		}
		button.disabled = true;
		fetch( config.rest, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( { answer: Number( button.getAttribute( 'data-snscs-helpful' ) ) } ),
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				button.disabled = false;
				if ( data && data.ok ) {
					button.classList.toggle( 'is-on', data.on );
					button.querySelector( 'span' ).textContent = String( data.helpful );
				}
			} )
			.catch( function () {
				button.disabled = false;
			} );
	} );
} )();
