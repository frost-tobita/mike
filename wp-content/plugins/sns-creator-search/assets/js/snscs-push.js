/**
 * 「この端末で通知を受け取る」ボタン（SNSCS_Push）。
 * サービスワーカーを登録し、通知の許可をもらって、宛先（購読情報）をサーバーに送る。
 */
( function () {
	var config = window.snscsPush;
	if ( ! config ) {
		return;
	}
	var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
	var panel = document.querySelector( '[data-snscs-push-panel]' );
	var status = panel ? panel.querySelector( '[data-snscs-push-status]' ) : null;
	var onButton = panel ? panel.querySelector( '[data-snscs-push="on"]' ) : null;
	var offButton = panel ? panel.querySelector( '[data-snscs-push="off"]' ) : null;

	function show( text, subscribed ) {
		if ( ! panel ) {
			return;
		}
		status.textContent = text;
		onButton.hidden = subscribed || ! supported || 'denied' === Notification.permission;
		offButton.hidden = ! subscribed;
	}

	if ( ! supported ) {
		show( 'この端末・ブラウザは通知に対応していません。iPhone の場合は「ホーム画面に追加」してから開いてください。', false );
		return;
	}

	function keyBytes( base64 ) {
		var padded = ( base64 + '===='.slice( ( base64.length + 3 ) % 4 ) ).replace( /-/g, '+' ).replace( /_/g, '/' );
		var raw = window.atob( padded );
		var out = new Uint8Array( raw.length );
		for ( var i = 0; i < raw.length; i++ ) {
			out[ i ] = raw.charCodeAt( i );
		}
		return out;
	}

	function api( method, body ) {
		return fetch( config.rest, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( body ),
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'server' );
			}
			return response.json();
		} );
	}

	var ready = navigator.serviceWorker.register( config.sw, { scope: config.scope } ).then( function () {
		return navigator.serviceWorker.ready;
	} );

	ready
		.then( function ( registration ) {
			return registration.pushManager.getSubscription();
		} )
		.then( function ( sub ) {
			if ( sub ) {
				// 登録済みの端末は、念のためサーバーにも知らせ直す（別のアカウントで登録していた場合など）。
				api( 'POST', sub.toJSON() ).catch( function () {} );
				show( 'この端末で通知を受け取っています。', true );
			} else if ( 'denied' === Notification.permission ) {
				show( '通知がブロックされています。ブラウザの設定で、このサイトの通知を許可してください。', false );
			} else {
				show( 'この端末では、まだ通知を受け取っていません。', false );
			}
		} )
		.catch( function () {
			show( '通知の準備ができませんでした。', false );
		} );

	if ( ! panel ) {
		return;
	}

	onButton.addEventListener( 'click', function () {
		onButton.disabled = true;
		Notification.requestPermission()
			.then( function ( permission ) {
				if ( 'granted' !== permission ) {
					throw new Error( 'denied' );
				}
				return ready;
			} )
			.then( function ( registration ) {
				return registration.pushManager.subscribe( { userVisibleOnly: true, applicationServerKey: keyBytes( config.key ) } );
			} )
			.then( function ( sub ) {
				return api( 'POST', sub.toJSON() );
			} )
			.then( function () {
				show( 'この端末で通知を受け取るようにしました。', true );
			} )
			.catch( function ( error ) {
				show( 'denied' === error.message ? '通知が許可されませんでした。' : '通知の登録に失敗しました。時間をおいてお試しください。', false );
			} )
			.then( function () {
				onButton.disabled = false;
			} );
	} );

	offButton.addEventListener( 'click', function () {
		ready
			.then( function ( registration ) {
				return registration.pushManager.getSubscription();
			} )
			.then( function ( sub ) {
				if ( ! sub ) {
					return null;
				}
				var endpoint = sub.endpoint;
				return sub.unsubscribe().then( function () {
					return api( 'DELETE', { endpoint: endpoint } );
				} );
			} )
			.then( function () {
				show( 'この端末の通知を止めました。', false );
			} )
			.catch( function () {
				show( '通知を止められませんでした。', true );
			} );
	} );
} )();
