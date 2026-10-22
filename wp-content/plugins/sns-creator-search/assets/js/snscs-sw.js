/**
 * サービスワーカー: プッシュ通知を受け取って表示し、押されたらそのページを開く（SNSCS_Push）。
 * サイトの直下（/?snscs_sw=1）から読み込まれる。ページのキャッシュはしない。
 */
self.addEventListener( 'install', function () {
	self.skipWaiting();
} );

self.addEventListener( 'activate', function ( event ) {
	event.waitUntil( self.clients.claim() );
} );

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
