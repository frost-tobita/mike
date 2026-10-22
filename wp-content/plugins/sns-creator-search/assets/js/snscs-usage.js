/**
 * ログイン中の発信者の、このサイトの利用状況を送る（SNSCS_Usage）。
 *
 * 利用時間は「画面に表示されていて、最近1分以内に操作があった時間」だけを数え、
 * 前回送ってから増えた分だけを送る。
 */
( function () {
	var config = window.snscsUsage;
	if ( ! config || ! navigator.sendBeacon || ! window.URLSearchParams ) {
		return;
	}

	var TICK = 5;
	var IDLE_LIMIT = 60000;

	function send( type, extra ) {
		var params = new URLSearchParams( { t: type, _wpnonce: config.nonce } );
		Object.keys( extra || {} ).forEach( function ( key ) {
			params.append( key, extra[ key ] );
		} );
		navigator.sendBeacon( config.url, params );
	}

	send( 'ping' );

	var unsent = 0;
	var lastActive = Date.now();

	[ 'scroll', 'mousemove', 'keydown', 'touchstart', 'click' ].forEach( function ( name ) {
		window.addEventListener( name, function () {
			lastActive = Date.now();
		}, { passive: true } );
	} );

	setInterval( function () {
		if ( 'visible' === document.visibilityState && Date.now() - lastActive < IDLE_LIMIT ) {
			unsent += TICK;
		}
	}, TICK * 1000 );

	function flush() {
		if ( unsent > 0 ) {
			send( 'time', { s: unsent } );
			unsent = 0;
		}
	}

	setInterval( flush, 30000 );
	document.addEventListener( 'visibilitychange', function () {
		if ( 'hidden' === document.visibilityState ) {
			flush();
		} else {
			lastActive = Date.now();
		}
	} );
	window.addEventListener( 'pagehide', flush );
} )();
