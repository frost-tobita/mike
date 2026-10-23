/**
 * サービスワーカー（SNSCS_App・SNSCS_Push）。サイトの直下（/?snscs_sw=1）から読み込まれ、
 * 先頭に設定（SNSCS_SW: version・offline・precache・home）が付く。
 *
 * ページ（画面の移動）: まずサーバーから取り、取れたら端末に覚える（最大40ページ）。取れないときは覚えたページ、
 *                      それも無ければ「オフラインです」のページ。4秒待っても返事が無いときは、覚えたページを先に出す。
 *                      ログイン中のページ（X-SNSCS-Private）や、覚えてはいけないページ（no-store・private）は覚えない。
 * 画像・CSS・JavaScript・文字（同じサイトのもの）: 覚えたものをすぐ使い、裏で新しくする（最大150個）。
 * 管理画面・ログイン・送信・データのやりとり（/wp-json/）などは、何もせずサーバーへ。
 * プッシュ通知: 受け取って表示し、押されたらそのページを開く。
 */
var CONFIG = self.SNSCS_SW || { version: '0', offline: '', precache: [], home: '/' };
var PAGES = 'snscs-pages-' + CONFIG.version;
var ASSETS = 'snscs-assets-' + CONFIG.version;
var MAX_PAGES = 40;
var MAX_ASSETS = 150;

self.addEventListener( 'install', function ( event ) {
	event.waitUntil(
		caches.open( ASSETS ).then( function ( cache ) {
			return Promise.all( ( CONFIG.precache || [] ).map( function ( url ) {
				return cache.add( new Request( url, { credentials: 'same-origin' } ) ).catch( function () {} );
			} ) );
		} ).then( function () {
			return self.skipWaiting();
		} )
	);
} );

self.addEventListener( 'activate', function ( event ) {
	event.waitUntil(
		caches.keys().then( function ( keys ) {
			return Promise.all( keys.filter( function ( key ) {
				return 0 === key.indexOf( 'snscs-' ) && key !== PAGES && key !== ASSETS;
			} ).map( function ( key ) {
				return caches.delete( key );
			} ) );
		} ).then( function () {
			if ( self.registration.navigationPreload ) {
				return self.registration.navigationPreload.enable().catch( function () {} );
			}
		} ).then( function () {
			return self.clients.claim();
		} )
	);
} );

/** サーバーへそのまま送るもの。 */
function skip( url ) {
	var p = url.pathname;
	if ( /\/wp-admin\/|\/wp-login\.php|\/wp-json\/|\/xmlrpc\.php|\/wp-cron\.php|\/feed\/?$|\/ad-click\//.test( p ) ) {
		return true;
	}
	return /(?:^|&)(snscs_fp|snscs_sw|snscs_manifest|snscs_pr|snscs_bio_kit|snscs_bio_qr|snscs_bio_confirm|snscs_bio_unsub|preview|customize_changeset_uuid|action|_wpnonce|snscs_share_text|snscs_share_url|snscs_ad_click)=/.test( url.search.slice( 1 ) );
}

function trim( name, max ) {
	return caches.open( name ).then( function ( cache ) {
		return cache.keys().then( function ( keys ) {
			if ( keys.length <= max ) {
				return;
			}
			return Promise.all( keys.slice( 0, keys.length - max ).map( function ( key ) {
				return cache.delete( key );
			} ) );
		} );
	} );
}

function cacheable( response ) {
	if ( ! response || 200 !== response.status || 'basic' !== response.type ) {
		return false;
	}
	var cc = response.headers.get( 'Cache-Control' ) || '';
	return ! response.headers.get( 'X-SNSCS-Private' ) && ! /no-store|private/i.test( cc );
}

function page( event ) {
	var request = event.request;
	var network = ( event.preloadResponse || Promise.resolve() ).then( function ( preloaded ) {
		return preloaded || fetch( request );
	} ).then( function ( response ) {
		if ( cacheable( response ) ) {
			var copy = response.clone();
			caches.open( PAGES ).then( function ( cache ) {
				return cache.put( request, copy );
			} ).then( function () {
				return trim( PAGES, MAX_PAGES );
			} );
		}
		return response;
	} );
	var fallback = function () {
		return caches.match( request, { cacheName: PAGES } ).then( function ( cached ) {
			if ( cached ) {
				return cached;
			}
			return ( CONFIG.offline ? caches.match( CONFIG.offline ) : Promise.resolve() ).then( function ( offline ) {
				return offline || Response.error();
			} );
		} );
	};
	// 4秒待っても返事が無ければ、覚えたページがあればそれを先に出す（電波が弱いとき）。
	var slow = new Promise( function ( resolve ) {
		setTimeout( function () {
			caches.match( request, { cacheName: PAGES } ).then( resolve );
		}, 4000 );
	} );
	return Promise.race( [
		network.catch( fallback ),
		slow.then( function ( cached ) {
			return cached || network.catch( fallback );
		} ),
	] );
}

function asset( request ) {
	return caches.open( ASSETS ).then( function ( cache ) {
		return cache.match( request ).then( function ( cached ) {
			var fresh = fetch( request ).then( function ( response ) {
				if ( response && 200 === response.status && ( 'basic' === response.type || 'cors' === response.type ) ) {
					cache.put( request, response.clone() ).then( function () {
						return trim( ASSETS, MAX_ASSETS );
					} );
				}
				return response;
			} );
			if ( cached ) {
				fresh.catch( function () {} );
				return cached;
			}
			return fresh;
		} );
	} );
}

self.addEventListener( 'fetch', function ( event ) {
	var request = event.request;
	if ( 'GET' !== request.method ) {
		return;
	}
	var url = new URL( request.url );
	if ( url.origin !== self.location.origin || skip( url ) ) {
		return;
	}
	if ( 'navigate' === request.mode ) {
		event.respondWith( page( event ) );
		return;
	}
	if ( [ 'style', 'script', 'image', 'font' ].indexOf( request.destination ) !== -1 || /\/wp-content\/|\/wp-includes\//.test( url.pathname ) ) {
		event.respondWith( asset( request ) );
	}
} );

/** ページから: ログアウトしたら覚えたページを消す。 */
self.addEventListener( 'message', function ( event ) {
	if ( event.data && 'snscs-clear-pages' === event.data.type ) {
		event.waitUntil( caches.delete( PAGES ) );
	}
} );

/* ------------------------------------------------------------------
 * プッシュ通知（SNSCS_Push）
 * ---------------------------------------------------------------- */

self.addEventListener( 'push', function ( event ) {
	var data = {};
	try {
		data = event.data ? event.data.json() : {};
	} catch ( e ) {
		data = { body: event.data ? event.data.text() : '' };
	}
	event.waitUntil(
		self.registration.showNotification( data.title || 'お知らせ', {
			body: data.body || '',
			icon: data.icon || undefined,
			badge: data.icon || undefined,
			tag: data.tag || undefined,
			renotify: !! data.tag,
			data: { url: data.url || '/' },
		} )
	);
} );

self.addEventListener( 'notificationclick', function ( event ) {
	event.notification.close();
	var url = event.notification.data && event.notification.data.url ? event.notification.data.url : '/';
	event.waitUntil(
		self.clients.matchAll( { type: 'window', includeUncontrolled: true } ).then( function ( list ) {
			for ( var i = 0; i < list.length; i++ ) {
				if ( list[ i ].url === url && 'focus' in list[ i ] ) {
					return list[ i ].focus();
				}
			}
			return self.clients.openWindow( url );
		} )
	);
} );
