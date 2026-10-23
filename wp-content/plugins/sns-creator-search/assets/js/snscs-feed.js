/**
 * 記事のおすすめ（SNSCS_Feed）。
 *   記事のページ: 閲覧と「読了」（40秒以上見た、または80%以上スクロールして15秒以上見た）を送る。
 *                 読んだ記事はブラウザのクッキーに残し、おすすめで控えめにする。
 *   おすすめの一覧: 「興味がない」を押すと、その記事を消し、次から出さない。
 */
( function () {
	var config = window.snscsFeed;
	if ( ! config || ! window.fetch ) {
		return;
	}

	function readCookie( name ) {
		var m = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([0-9.]*)' ) );
		return m && m[ 1 ] ? m[ 1 ].split( '.' ).filter( Boolean ) : [];
	}

	function remember( name, id, max ) {
		var ids = readCookie( name ).filter( function ( v ) {
			return v !== String( id );
		} );
		ids.unshift( String( id ) );
		document.cookie = name + '=' + ids.slice( 0, max ).join( '.' ) + '; path=/; max-age=' + 180 * 86400 + '; SameSite=Lax';
	}

	function send( path, body ) {
		return fetch( config.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( body ),
		} ).catch( function () {} );
	}

	// 記事のページ: 閲覧と読了。
	if ( config.article ) {
		var key = 'snscs_fq_' + config.article;
		var qid = config.qid || '';
		try {
			if ( qid ) {
				sessionStorage.setItem( key, qid );
			} else {
				qid = sessionStorage.getItem( key ) || '';
			}
		} catch ( e ) {}

		send( '/event', { article: config.article, event: 'view', qid: qid } );

		var seen = 0;
		var last = Date.now();
		var active = Date.now();
		var deepest = 0;
		var done = false;
		[ 'scroll', 'mousemove', 'keydown', 'touchstart' ].forEach( function ( type ) {
			window.addEventListener(
				type,
				function () {
					active = Date.now();
				},
				{ passive: true }
			);
		} );
		var timer = setInterval( function () {
			var now = Date.now();
			// 画面に出ていて、30秒以内に操作があった時間だけ数える。
			if ( 'visible' === document.visibilityState && now - active < 30000 ) {
				seen += ( now - last ) / 1000;
			}
			last = now;
			var doc = document.documentElement;
			var max = Math.max( 1, doc.scrollHeight - window.innerHeight );
			deepest = Math.max( deepest, window.scrollY / max );
			if ( ! done && ( seen >= 40 || ( deepest >= 0.8 && seen >= 15 ) ) ) {
				done = true;
				clearInterval( timer );
				remember( 'snscs_rd', config.article, 60 );
				send( '/event', { article: config.article, event: 'read', qid: qid } );
			}
		}, 1000 );
	}

	// おすすめの一覧: 「興味がない」。
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-snscs-hide]' ) : null;
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		var id = button.getAttribute( 'data-snscs-hide' );
		remember( 'snscs_hd', id, 100 );
		send( '/hide', { article: Number( id ) } );
		var item = button.closest( 'li' );
		if ( item ) {
			item.innerHTML = '<p class="snscs-feed__hidden">この記事を表示しないようにしました。似た記事も少なくします。</p>';
		}
	} );
} )();
