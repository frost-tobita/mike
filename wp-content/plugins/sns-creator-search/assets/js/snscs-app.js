/**
 * スマホアプリとして動かす（SNSCS_App）。
 *
 * - サービスワーカーを登録する（電波が無くても前に見たページを開ける・通知を受け取る）
 * - アプリとして開いたとき（ホーム画面から）: <html> に snscs-app を付け、画面の下にタブと、左上に戻るボタンを出す
 * - まだアプリにしていない人: 2回目に来たとき「アプリとして追加」を案内する（閉じたら30日は出さない）
 *   Android・パソコンの Chrome / Edge はボタン1つで追加、iPhone・iPad は共有メニューからの手順を出す
 * - お知らせの未読の数を、タブとホーム画面のアイコン（対応する端末）に出す
 */
( function () {
	var config = window.snscsApp;
	if ( ! config ) {
		return;
	}
	var KEY = 'snscs_app';
	var root = document.documentElement;
	var ua = navigator.userAgent || '';
	var isIOS = /iphone|ipad|ipod/i.test( ua ) || ( 'MacIntel' === navigator.platform && navigator.maxTouchPoints > 1 );
	var standalone = ( window.matchMedia && ( window.matchMedia( '(display-mode: standalone)' ).matches || window.matchMedia( '(display-mode: minimal-ui)' ).matches ) ) || true === navigator.standalone;
	var deferred = null;

	function store( update ) {
		var data = {};
		try {
			data = JSON.parse( window.localStorage.getItem( KEY ) || '{}' ) || {};
		} catch ( e ) {}
		if ( update ) {
			Object.keys( update ).forEach( function ( k ) {
				data[ k ] = update[ k ];
			} );
			try {
				window.localStorage.setItem( KEY, JSON.stringify( data ) );
			} catch ( e ) {}
		}
		return data;
	}

	/* ---------------- サービスワーカー ---------------- */

	if ( 'serviceWorker' in navigator ) {
		window.addEventListener( 'load', function () {
			navigator.serviceWorker.register( config.sw, { scope: config.scope } ).catch( function () {} );
		} );
		// ログアウトしたら、端末に覚えたページを消す（同じ端末をほかの人が使うときのため）。
		var state = store();
		if ( 1 === state.user && 0 === config.user && navigator.serviceWorker.controller ) {
			navigator.serviceWorker.controller.postMessage( { type: 'snscs-clear-pages' } );
		}
	}
	store( { user: config.user } );

	/* ---------------- アプリとして開いたとき ---------------- */

	var ICONS = {
		home: '<path d="M3 11.5 12 4l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/>',
		search: '<circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="2.2"/><path d="m16 16 4.5 4.5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>',
		stories: '<path d="M5 4h14v16H5z" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round"/><path d="M8 9h8M8 13h8M8 17h5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>',
		ranking: '<path d="M4 20V11h4v9zM10 20V5h4v15zM16 20v-6h4v6z"/>',
		bell: '<path d="M12 3a6 6 0 0 0-6 6v4l-2 3h16l-2-3V9a6 6 0 0 0-6-6zM9.5 19a2.5 2.5 0 0 0 5 0z"/>',
		me: '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.2-4.2 4.4-6.3 8-6.3s6.8 2.1 8 6.3z"/>',
	};

	function current( tab ) {
		var here = location.href.split( '#' )[ 0 ].replace( /[?&]snscs_app=1/, '' );
		var url = tab.url.split( '#' )[ 0 ];
		if ( 'home' === tab.key ) {
			return here.replace( /\?$/, '' ) === url || here === url + '?';
		}
		if ( 'inbox' === tab.key ) {
			return /[?&]tab=inbox/.test( here ) && 0 === here.indexOf( url.split( '?' )[ 0 ] );
		}
		return 0 === here.indexOf( url.split( '?' )[ 0 ] ) && ! /[?&]tab=inbox/.test( here );
	}

	function tabbar() {
		var nav = document.createElement( 'nav' );
		nav.className = 'snscs-tabbar';
		nav.setAttribute( 'aria-label', 'アプリのメニュー' );
		var html = '';
		config.tabs.forEach( function ( tab ) {
			html += '<a class="snscs-tabbar__item' + ( current( tab ) ? ' is-current' : '' ) + '" href="' + tab.url.replace( /"/g, '&quot;' ) + '"' + ( current( tab ) ? ' aria-current="page"' : '' ) + ' data-tab="' + tab.key + '">'
				+ '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true">' + ( ICONS[ tab.icon ] || '' ) + '</svg>'
				+ '<span class="snscs-tabbar__label"></span>' + ( 'inbox' === tab.key ? '<span class="snscs-tabbar__badge" hidden></span>' : '' ) + '</a>';
		} );
		nav.innerHTML = html;
		// 名前は textContent で入れる（記号が入っても安全に）。
		Array.prototype.forEach.call( nav.querySelectorAll( '.snscs-tabbar__label' ), function ( el, i ) {
			el.textContent = config.tabs[ i ].label;
		} );
		document.body.appendChild( nav );

		// 戻るボタン（iPhone のアプリ表示には、ブラウザの戻るが無いため）。トップページ以外で出す。
		if ( window.history.length > 1 && ! current( config.tabs[ 0 ] ) ) {
			var back = document.createElement( 'button' );
			back.type = 'button';
			back.className = 'snscs-appback';
			back.setAttribute( 'aria-label', '戻る' );
			back.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M15 5 8 12l7 7" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
			back.addEventListener( 'click', function () {
				window.history.back();
			} );
			document.body.appendChild( back );
		}
	}

	function unread() {
		if ( ! config.unread || ! window.fetch ) {
			return;
		}
		fetch( config.unread, { credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce } } ).then( function ( r ) {
			return r.ok ? r.json() : null;
		} ).then( function ( data ) {
			var n = data ? parseInt( data.unread, 10 ) || 0 : 0;
			var badge = document.querySelector( '.snscs-tabbar__badge' );
			if ( badge ) {
				badge.hidden = ! n;
				badge.textContent = n > 99 ? '99+' : String( n );
			}
			if ( navigator.setAppBadge ) {
				( n ? navigator.setAppBadge( n ) : navigator.clearAppBadge() ).catch( function () {} );
			}
		} ).catch( function () {} );
	}

	if ( standalone ) {
		root.classList.add( 'snscs-app' );
		store( { installed: 1 } );
		if ( config.tabbar && config.tabs && config.tabs.length ) {
			if ( document.body ) {
				tabbar();
			} else {
				document.addEventListener( 'DOMContentLoaded', tabbar );
			}
		}
		unread();
		document.addEventListener( 'visibilitychange', function () {
			if ( 'visible' === document.visibilityState ) {
				unread();
			}
		} );
	}

	/* ---------------- インストールの案内 ---------------- */

	var data = store();
	var visits = data.visits || 0;
	try {
		// 1回の訪問（タブを開いているあいだ）を1回と数える。
		if ( ! window.sessionStorage.getItem( KEY ) ) {
			visits++;
			window.sessionStorage.setItem( KEY, '1' );
		}
	} catch ( e ) {}
	store( { visits: visits } );

	function boxes() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-snscs-appbox]' ), function ( box ) {
			var button = box.querySelector( '[data-snscs-install]' );
			var ios = box.querySelector( '[data-snscs-install-ios]' );
			var done = box.querySelector( '.snscs-appbox__done' );
			if ( standalone ) {
				done.hidden = false;
				button.hidden = true;
				return;
			}
			if ( isIOS ) {
				button.hidden = true;
				ios.hidden = false;
				return;
			}
			button.hidden = ! deferred;
		} );
	}

	function install() {
		if ( ! deferred ) {
			return;
		}
		deferred.prompt();
		deferred.userChoice.then( function ( choice ) {
			if ( 'accepted' === choice.outcome ) {
				store( { installed: 1 } );
				hideBanner();
			}
			deferred = null;
			boxes();
		} );
	}

	var banner = null;
	function hideBanner() {
		if ( banner ) {
			banner.remove();
			banner = null;
		}
	}

	function showBanner() {
		var d = store();
		if ( ! config.prompt || standalone || banner || d.installed || ( d.dismissed && Date.now() - d.dismissed < 30 * 86400000 ) || d.visits < 2 ) {
			return;
		}
		if ( ! deferred && ! isIOS ) {
			return;
		}
		banner = document.createElement( 'div' );
		banner.className = 'snscs-installbar';
		banner.setAttribute( 'role', 'dialog' );
		banner.setAttribute( 'aria-label', 'アプリとして追加' );
		banner.innerHTML = '<img alt="" width="44" height="44"><div class="snscs-installbar__text"><strong></strong><span></span></div>'
			+ ( isIOS ? '' : '<button type="button" class="snscs-installbar__go">追加</button>' )
			+ '<button type="button" class="snscs-installbar__close" aria-label="閉じる">×</button>';
		banner.querySelector( 'img' ).src = config.icon;
		banner.querySelector( 'strong' ).textContent = config.name + 'をアプリで使う';
		banner.querySelector( 'span' ).textContent = isIOS ? '共有ボタン（□に↑）→「ホーム画面に追加」で、アプリのように使えます' : 'ホーム画面から全画面で開けます。電波が弱くても前に見たページを開けます';
		var go = banner.querySelector( '.snscs-installbar__go' );
		if ( go ) {
			go.addEventListener( 'click', install );
		}
		banner.querySelector( '.snscs-installbar__close' ).addEventListener( 'click', function () {
			store( { dismissed: Date.now() } );
			hideBanner();
		} );
		document.body.appendChild( banner );
	}

	window.addEventListener( 'beforeinstallprompt', function ( event ) {
		event.preventDefault();
		deferred = event;
		boxes();
		showBanner();
	} );
	window.addEventListener( 'appinstalled', function () {
		store( { installed: 1 } );
		hideBanner();
	} );
	document.addEventListener( 'click', function ( event ) {
		if ( event.target.closest && event.target.closest( '[data-snscs-install]' ) ) {
			install();
		}
	} );
	var ready = function () {
		boxes();
		// iPhone の Safari には「追加」の合図が無いので、少し待ってから手順を出す。
		if ( isIOS && ! /CriOS|FxiOS|EdgiOS/.test( ua ) ) {
			setTimeout( showBanner, 2500 );
		}
	};
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
} )();
