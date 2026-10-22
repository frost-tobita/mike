/**
 * 発信者ページ・記事の閲覧、滞在時間、リンクのクリックを送る（SNSCS_Tracking）。
 *
 * 滞在時間は「画面に表示されていて、最近1分以内に操作があった時間」だけを数える。
 * タブを開きっぱなしにしただけの時間は数えない。
 */
( function () {
	var config = window.snscsPageview;
	if ( ! config || ! navigator.sendBeacon || ! window.URLSearchParams ) {
		return;
	}

	var TICK = 5;
	var IDLE_LIMIT = 60000;
	var MAX_SECONDS = 600;

	function send( type, extra ) {
		var params = new URLSearchParams( {
			c: config.creator,
			k: config.kind,
			h: config.hour,
			tok: config.token,
			t: type
		} );
		Object.keys( extra || {} ).forEach( function ( key ) {
			params.append( key, extra[ key ] );
		} );
		navigator.sendBeacon( config.url, params );
	}

	send( 'view' );

	var seconds = 0;
	var reported = 0;
	var lastActive = Date.now();

	[ 'scroll', 'mousemove', 'keydown', 'touchstart', 'click' ].forEach( function ( name ) {
		window.addEventListener( name, function () {
			lastActive = Date.now();
		}, { passive: true } );
	} );

	setInterval( function () {
		if ( 'visible' === document.visibilityState && Date.now() - lastActive < IDLE_LIMIT ) {
			seconds = Math.min( MAX_SECONDS, seconds + TICK );
		}
	}, TICK * 1000 );

	function flush() {
		if ( seconds > reported ) {
			reported = seconds;
			send( 'dwell', { s: seconds } );
		}
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( 'hidden' === document.visibilityState ) {
			flush();
		} else {
			lastActive = Date.now();
		}
	} );
	window.addEventListener( 'pagehide', flush );
	// スマホではページを閉じたことが伝わらない場合があるため、ときどき送っておく。
	setInterval( flush, 30000 );

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( '[data-snscs-out]' ) : null;
		if ( link ) {
			send( 'out' );
			flush();
		}
	} );
} )();
