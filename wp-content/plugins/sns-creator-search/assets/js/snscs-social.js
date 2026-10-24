/**
 * フォロー・いいね・リポスト・ブックマークのボタン（SNSCS_Social）。
 *
 * ログイン中: サーバーに保存する。
 * ログインしていない: ブックマークだけ、このブラウザ（localStorage）に保存する。ほかのボタンはメンバー登録へ案内する。
 * ログインしたら、このブラウザのブックマークをアカウントへ移す。
 */
( function () {
	var config = window.snscsSocial;
	if ( ! config ) {
		return;
	}
	var KEY = 'snscs_bookmarks';

	function readLocal() {
		try {
			var list = JSON.parse( window.localStorage.getItem( KEY ) || '[]' );
			return Array.isArray( list ) ? list : [];
		} catch ( e ) {
			return [];
		}
	}

	function writeLocal( list ) {
		try {
			if ( list.length ) {
				window.localStorage.setItem( KEY, JSON.stringify( list.slice( 0, 200 ) ) );
			} else {
				window.localStorage.removeItem( KEY );
			}
		} catch ( e ) {}
	}

	function toast( text, link ) {
		var box = document.createElement( 'div' );
		box.className = 'snscs-toast';
		box.setAttribute( 'role', 'status' );
		box.textContent = text;
		if ( link ) {
			var a = document.createElement( 'a' );
			a.href = link.href;
			a.textContent = link.label;
			box.appendChild( document.createTextNode( ' ' ) );
			box.appendChild( a );
		}
		document.body.appendChild( box );
		setTimeout( function () {
			box.remove();
		}, link ? 6000 : 2500 );
	}

	function render( button, on, count ) {
		button.classList.toggle( 'is-on', on );
		button.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		var icon = button.querySelector( '.snscs-actions__icon' );
		if ( icon ) {
			icon.textContent = on ? button.getAttribute( 'data-icon-on' ) : button.getAttribute( 'data-icon-off' );
		}
		var label = button.querySelector( '[data-label-on]' );
		if ( label ) {
			label.textContent = on ? label.getAttribute( 'data-label-on' ) : label.getAttribute( 'data-label-off' );
		}
		if ( 'number' === typeof count ) {
			var scope = button.closest( '.snscs-follow' ) || button;
			var counter = scope.querySelector( '[data-snscs-count]' );
			if ( counter ) {
				counter.textContent = count.toLocaleString( 'ja-JP' );
			}
		}
	}

	// 同じ記事のボタン（本文の上と下）をそろえる。
	function renderAll( action, id, on, count ) {
		var list = document.querySelectorAll( '[data-snscs-social="' + action + '"][data-id="' + id + '"]' );
		Array.prototype.forEach.call( list, function ( button ) {
			render( button, on, count );
		} );
	}

	var labels = { follow: 'フォロー', follow_user: 'フォロー', like: 'いいね', repost: 'リポスト', bookmark: 'ブックマーク' };

	// ログインしていない人のブックマークは、ブラウザの記録で表示をそろえる。
	if ( ! config.loggedIn ) {
		readLocal().forEach( function ( id ) {
			renderAll( 'bookmark', id, true );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-snscs-social]' ) : null;
		if ( ! button || button.disabled ) {
			return;
		}
		var action = button.getAttribute( 'data-snscs-social' );
		var id = parseInt( button.getAttribute( 'data-id' ), 10 );
		var on = 'true' !== button.getAttribute( 'aria-pressed' );

		if ( config.provisional ) {
			toast( 'いまは仮登録です。運営の承認で本登録になると' + labels[ action ] + 'できます。', config.apply ? { href: config.apply, label: '申請の状況を見る' } : null );
			return;
		}

		if ( ! config.loggedIn ) {
			if ( 'bookmark' === action ) {
				var list = readLocal().filter( function ( x ) {
					return x !== id;
				} );
				if ( on ) {
					list.unshift( id );
				}
				writeLocal( list );
				renderAll( action, id, on );
				toast( on ? 'このブラウザにブックマークしました。' : 'ブックマークを外しました。', on && config.join ? { href: config.join, label: 'メンバー登録すると、どの端末でも見られます' } : null );
				return;
			}
			if ( config.join ) {
				toast( labels[ action ] + 'するには、メンバー登録（無料）またはログインが必要です。', { href: config.join, label: '登録・ログインへ' } );
			}
			return;
		}

		if ( 'repost' === action && ! button.hasAttribute( 'data-snscs-direct' ) ) {
			repostMenu( button, id, on );
			return;
		}
		send( button, action, id, on, '' );
	} );

	/* ------------------------------------------------------------------
	 * リポストのメニュー（そのままリポスト / ひとこと付きでリポスト / 取り消す）
	 * ---------------------------------------------------------------- */

	function closeMenus() {
		Array.prototype.forEach.call( document.querySelectorAll( '.snscs-repost-menu, .snscs-share-menu' ), function ( menu ) {
			menu.remove();
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest || ( ! event.target.closest( '.snscs-repost-menu, .snscs-share-menu' ) && ! event.target.closest( '[data-snscs-social="repost"], [data-snscs-share]' ) ) ) {
			closeMenus();
		}
	} );

	// on = 押したあとの状態（true ならまだリポストしていない）。
	function repostMenu( button, id, on ) {
		closeMenus();
		var menu = document.createElement( 'div' );
		menu.className = 'snscs-repost-menu';
		menu.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
		} );
		menu.setAttribute( 'role', 'dialog' );
		menu.setAttribute( 'aria-label', 'リポスト' );
		function item( label, handler ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.textContent = label;
			b.addEventListener( 'click', function ( event ) {
				// 中身を入れ替えるので、外側のクリック（メニューを閉じる処理）へは伝えない。
				event.stopPropagation();
				handler( event );
			} );
			menu.appendChild( b );
			return b;
		}
		function quoteForm() {
			menu.innerHTML = '';
			var area = document.createElement( 'textarea' );
			area.maxLength = 140;
			area.rows = 3;
			area.placeholder = 'この記事について、ひとこと（140文字まで）';
			var counter = document.createElement( 'span' );
			counter.className = 'snscs-repost-menu__count';
			counter.textContent = '0 / 140';
			area.addEventListener( 'input', function () {
				counter.textContent = area.value.length + ' / 140';
			} );
			menu.appendChild( area );
			menu.appendChild( counter );
			var ok = item( 'ひとこと付きでリポスト', function () {
				if ( ! area.value.trim() ) {
					area.focus();
					return;
				}
				send( button, 'repost', id, true, area.value.trim() );
				closeMenus();
			} );
			ok.className = 'is-primary';
			area.focus();
		}
		if ( ! on ) {
			item( 'ひとことを書き直す', quoteForm );
			item( 'リポストを取り消す', function () {
				send( button, 'repost', id, false, '' );
				closeMenus();
			} );
		} else {
			item( 'リポスト', function () {
				send( button, 'repost', id, true, '' );
				closeMenus();
			} );
			item( 'ひとこと付きでリポスト', quoteForm );
		}
		button.parentNode.insertBefore( menu, button.nextSibling );
	}

	/* ------------------------------------------------------------------
	 * シェア: スマホは端末の共有メニュー。パソコンは行き先を選ぶメニュー。
	 * ---------------------------------------------------------------- */

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		var area = document.createElement( 'textarea' );
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild( area );
		area.select();
		var ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {}
		area.remove();
		return ok ? Promise.resolve() : Promise.reject();
	}

	function shareMenu( button ) {
		var wasOpen = button.nextSibling && button.nextSibling.classList && button.nextSibling.classList.contains( 'snscs-share-menu' );
		closeMenus();
		if ( wasOpen ) {
			return;
		}
		var url = button.getAttribute( 'data-url' );
		var title = button.getAttribute( 'data-title' ) || '';
		var u = encodeURIComponent( url );
		var t = encodeURIComponent( title );
		var menu = document.createElement( 'div' );
		menu.className = 'snscs-share-menu';
		menu.setAttribute( 'role', 'dialog' );
		menu.setAttribute( 'aria-label', 'シェア' );
		[
			[ 'X（旧Twitter）', 'https://x.com/intent/post?text=' + t + '&url=' + u ],
			[ 'Threads', 'https://www.threads.net/intent/post?text=' + encodeURIComponent( title + ' ' + url ) ],
			[ 'LINE', 'https://social-plugins.line.me/lineit/share?url=' + u ],
			[ 'Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' + u ],
		].forEach( function ( row ) {
			var a = document.createElement( 'a' );
			a.href = row[ 1 ];
			a.target = '_blank';
			a.rel = 'noopener';
			a.textContent = row[ 0 ];
			a.addEventListener( 'click', closeMenus );
			menu.appendChild( a );
		} );
		var copy = document.createElement( 'button' );
		copy.type = 'button';
		copy.textContent = 'リンクをコピー';
		copy.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
			copyText( url ).then( function () {
				toast( 'リンクをコピーしました。' );
			}, function () {
				window.prompt( 'このリンクをコピーしてください', url );
			} );
			closeMenus();
		} );
		menu.appendChild( copy );
		button.parentNode.insertBefore( menu, button.nextSibling );
		// ボタンの真下に出す（列の幅からはみ出さないように左へ寄せる）。
		var room = button.parentNode.clientWidth - menu.offsetWidth;
		menu.style.left = Math.max( 0, Math.min( button.offsetLeft, room ) ) + 'px';
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-snscs-share]' ) : null;
		if ( ! button ) {
			return;
		}
		var url = button.getAttribute( 'data-url' );
		if ( navigator.share && /Android|iPhone|iPad|iPod/i.test( navigator.userAgent ) ) {
			navigator.share( { title: button.getAttribute( 'data-title' ) || '', url: url } ).catch( function () {} );
			return;
		}
		shareMenu( button );
	} );

	function send( button, action, id, on, comment ) {
		button.disabled = true;
		renderAll( action, id, on );
		fetch( config.rest, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( { action: action, id: id, on: on, comment: comment } ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					if ( ! response.ok ) {
						throw new Error( data && data.message ? data.message : 'error' );
					}
					return data;
				} );
			} )
			.then( function ( data ) {
				renderAll( action, id, data.on, data.count );
				// プロフィールの上の「フォロワー ○」も同じ数に。
				if ( 'follow_user' === action && 'number' === typeof data.count ) {
					Array.prototype.forEach.call( document.querySelectorAll( '[data-snscs-followers="' + id + '"]' ), function ( el ) {
						el.textContent = data.count.toLocaleString( 'ja-JP' );
					} );
				}
			} )
			.catch( function ( error ) {
				renderAll( action, id, ! on );
				toast( 'error' === error.message ? '保存できませんでした。もう一度お試しください。' : error.message );
			} )
			.then( function () {
				button.disabled = false;
				if ( 'repost' === action && on && comment ) {
					toast( 'ひとこと付きでリポストしました。フォロワーの画面に表示されます。' );
				}
			} );
	}

	/* ------------------------------------------------------------------
	 * ブックマークした記事: どこまで読んだかを覚え、「続きから読む」で戻る
	 * ---------------------------------------------------------------- */

	var body = document.querySelector( '.snscs-story' ) || document.querySelector( '.snscs-actions' );
	if ( config.article && body ) {
		var area = document.querySelector( '.snscs-story' ) || body.parentNode;
		var LOCAL_PROGRESS = 'snscs_progress';
		var readLocalProgress = function () {
			try {
				return JSON.parse( window.localStorage.getItem( LOCAL_PROGRESS ) || '{}' ) || {};
			} catch ( e ) {
				return {};
			}
		};
		var bookmarked = function () {
			var b = document.querySelector( '[data-snscs-social="bookmark"][data-id="' + config.article + '"]' );
			return b && 'true' === b.getAttribute( 'aria-pressed' );
		};
		var saved = config.loggedIn ? config.progress : ( readLocalProgress()[ config.article ] || 0 );
		var percent = function () {
			var rect = area.getBoundingClientRect();
			var seen = window.innerHeight - rect.top;
			return Math.max( 0, Math.min( 100, Math.round( seen / Math.max( 1, rect.height ) * 100 ) ) );
		};
		var max = 0;
		var timer = null;
		window.addEventListener( 'scroll', function () {
			max = Math.max( max, percent() );
			// 読み進めたら、少し止まったところで保存する（ページを閉じたときにも保存する）。
			clearTimeout( timer );
			timer = setTimeout( function () {
				report();
			}, 1500 );
		}, { passive: true } );
		var report = function () {
			if ( ! bookmarked() || max <= Math.max( 0, saved ) ) {
				return;
			}
			saved = max;
			if ( config.loggedIn ) {
				fetch( config.rest + '/progress', {
					method: 'POST',
					credentials: 'same-origin',
					keepalive: true,
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
					body: JSON.stringify( { id: config.article, p: max } ),
				} ).catch( function () {} );
			} else {
				var all = readLocalProgress();
				all[ config.article ] = max;
				try {
					window.localStorage.setItem( LOCAL_PROGRESS, JSON.stringify( all ) );
				} catch ( e ) {}
			}
		};
		document.addEventListener( 'visibilitychange', function () {
			if ( 'hidden' === document.visibilityState ) {
				report();
			}
		} );
		window.addEventListener( 'pagehide', report );

		// 「続きから読む」: 前回読んだところの少し手前へ。
		if ( '#snscs-resume' === window.location.hash && saved > 0 && saved < 90 ) {
			window.addEventListener( 'load', function () {
				var rect = area.getBoundingClientRect();
				window.scrollTo( 0, window.pageYOffset + rect.top + rect.height * saved / 100 - window.innerHeight * 0.9 );
				toast( '前回の続き（' + saved + '%）から表示しています。' );
			} );
		} else if ( saved > 0 && saved < 90 && bookmarked() ) {
			var bar = document.querySelector( '.snscs-actions' );
			if ( bar ) {
				var resume = document.createElement( 'a' );
				resume.className = 'snscs-actions__resume';
				resume.href = '#snscs-resume';
				resume.textContent = '前回の続き（' + saved + '%）から読む';
				resume.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					var rect = area.getBoundingClientRect();
					window.scrollTo( { top: window.pageYOffset + rect.top + rect.height * saved / 100 - window.innerHeight * 0.9, behavior: 'smooth' } );
				} );
				bar.appendChild( resume );
			}
		}
	}

	// ログインしたら、このブラウザのブックマークをアカウントへ移す。
	if ( config.loggedIn && readLocal().length ) {
		fetch( config.rest + '/sync', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( { bookmarks: readLocal() } ),
		} )
			.then( function ( response ) {
				if ( response.ok ) {
					readLocal().forEach( function ( id ) {
						renderAll( 'bookmark', id, true );
					} );
					writeLocal( [] );
				}
			} )
			.catch( function () {} );
	}

	// メンバーページ（ログイン前）: このブラウザのブックマーク一覧。
	var box = document.querySelector( '[data-snscs-local-bookmarks]' );
	var ids = readLocal();
	if ( box && ids.length && window.fetch ) {
		fetch( box.getAttribute( 'data-cards' ) + ( box.getAttribute( 'data-cards' ).indexOf( '?' ) >= 0 ? '&' : '?' ) + 'ids=' + ids.join( ',' ) )
			.then( function ( response ) {
				return response.ok ? response.json() : [];
			} )
			.then( function ( cards ) {
				if ( ! cards.length ) {
					return;
				}
				var ul = document.createElement( 'ul' );
				ul.className = 'snscs-feed';
				cards.forEach( function ( card ) {
					var li = document.createElement( 'li' );
					li.className = 'snscs-feed__item';
					var a = document.createElement( 'a' );
					a.className = 'snscs-feed__link';
					a.href = card.url;
					var body = document.createElement( 'span' );
					body.className = 'snscs-feed__body';
					var title = document.createElement( 'span' );
					title.className = 'snscs-feed__title';
					title.textContent = card.title;
					var meta = document.createElement( 'span' );
					meta.className = 'snscs-feed__meta';
					meta.textContent = ( card.creator ? card.creator + '　' : '' ) + card.date;
					body.appendChild( title );
					body.appendChild( meta );
					a.appendChild( body );
					li.appendChild( a );
					ul.appendChild( li );
				} );
				box.innerHTML = '';
				box.appendChild( ul );
			} )
			.catch( function () {} );
	}
} )();
