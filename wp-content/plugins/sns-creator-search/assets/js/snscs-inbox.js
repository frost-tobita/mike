/**
 * 未読のお知らせの数を、サイト内の「会員ページ」へのリンク（メニュー・ナビ）に付ける（SNSCS_Inbox）。
 * ページのキャッシュに左右されないよう、表示したあとに数だけを取りに行く。
 */
( function () {
	var config = window.snscsInbox;
	if ( ! config || ! window.fetch ) {
		return;
	}
	fetch( config.count, { credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce } } )
		.then( function ( response ) {
			return response.ok ? response.json() : { unread: 0 };
		} )
		.then( function ( data ) {
			var unread = data && data.unread ? data.unread : 0;
			if ( ! unread ) {
				return;
			}
			var base = config.member.replace( /\/$/, '' );
			var links = document.querySelectorAll( 'a[href]' );
			Array.prototype.forEach.call( links, function ( link ) {
				// メニューなどの「会員ページ」そのものへのリンクだけ（?user= などの付いたリンクは除く）。
				var href = link.getAttribute( 'href' ).replace( /\/$/, '' );
				if ( href !== base || link.querySelector( '.snscs-unread' ) || link.closest( '.snscs-member__tabs' ) ) {
					return;
				}
				var badge = document.createElement( 'span' );
				badge.className = 'snscs-unread';
				badge.textContent = unread > 99 ? '99+' : String( unread );
				badge.setAttribute( 'aria-label', '未読のお知らせ ' + unread + '件' );
				link.appendChild( badge );
			} );
		} )
		.catch( function () {} );
} )();
