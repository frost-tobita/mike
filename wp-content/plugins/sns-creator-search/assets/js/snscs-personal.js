/**
 * 一人ひとりに合わせたおすすめ・お気に入り・最近見た発信者（SNSCS_Personalize）。
 *
 * 好みは、このブラウザの localStorage にだけ保存する（サーバーには送らない）。
 * サーバーが並べ替えに使えるよう、要約だけをクッキー snscs_pf に入れる。
 *   g: ジャンル（ID=重み） p: SNS r: 地域 c: 最近見た発信者 f: お気に入り
 * 重みは14日で半分に薄れる。オフにすると学習をやめ、最近見た記録も消す（お気に入りは残す）。
 */
( function () {
	var config = window.snscsPersonal;
	if ( ! config ) {
		return;
	}

	var KEY = 'snscs_profile';
	var HALF_LIFE_DAYS = 14;
	var MAX_RECENT = 20;
	var MAX_FAVS = 100;
	var MAX_SEEN = 30;

	/* ------------------------------------------------------------------
	 * 保存
	 * ---------------------------------------------------------------- */

	function empty() {
		return { v: 1, t: Date.now(), g: {}, p: {}, r: {}, recent: [], favs: [], seen: [] };
	}

	function load() {
		try {
			var data = JSON.parse( window.localStorage.getItem( KEY ) || 'null' );
			if ( data && 1 === data.v ) {
				return data;
			}
		} catch ( e ) {}
		return empty();
	}

	function getCookie( name ) {
		var match = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );
		return match ? decodeURIComponent( match[ 1 ] ) : '';
	}

	function setCookie( name, value, days ) {
		var expires = new Date( Date.now() + days * 86400000 ).toUTCString();
		document.cookie = name + '=' + encodeURIComponent( value ) + '; expires=' + expires + '; path=' + config.path + '; SameSite=Lax' + ( 'https:' === location.protocol ? '; Secure' : '' );
	}

	function isOff() {
		if ( '1' === getCookie( config.off ) ) {
			return true;
		}
		// ブラウザで「追跡しない（GPC）」を選んでいる人は、自分でオンにしない限りオフ。
		return !! navigator.globalPrivacyControl && '0' !== getCookie( config.off );
	}

	var learning = config.learn && ! isOff();
	var profile = load();

	function top( map, count ) {
		return Object.keys( map ).sort( function ( a, b ) {
			return map[ b ] - map[ a ];
		} ).slice( 0, count );
	}

	function summary() {
		var parts = [];
		var add = function ( prefix, map, count ) {
			var keys = top( map, count );
			if ( keys.length ) {
				parts.push( prefix + ':' + keys.map( function ( k ) {
					return k + '=' + Math.min( 999, map[ k ] ).toFixed( 2 );
				} ).join( ',' ) );
			}
		};
		if ( learning ) {
			add( 'g', profile.g, 12 );
			add( 'p', profile.p, 6 );
			add( 'r', profile.r, 6 );
			if ( profile.recent.length ) {
				parts.push( 'c:' + profile.recent.slice( 0, 10 ).join( ',' ) );
			}
		}
		if ( profile.favs.length ) {
			parts.push( 'f:' + profile.favs.slice( 0, 10 ).join( ',' ) );
		}
		return parts.join( '|' );
	}

	function save() {
		profile.t = Date.now();
		try {
			window.localStorage.setItem( KEY, JSON.stringify( profile ) );
		} catch ( e ) {}
		var value = summary();
		if ( value ) {
			setCookie( config.cookie, value, 90 );
		} else {
			setCookie( config.cookie, '', -1 );
		}
	}

	/* ------------------------------------------------------------------
	 * 学習
	 * ---------------------------------------------------------------- */

	// 前回からの日数に応じて、すべての重みを薄める。
	function decay() {
		var days = ( Date.now() - ( profile.t || Date.now() ) ) / 86400000;
		if ( days <= 0 ) {
			return;
		}
		var factor = Math.pow( 0.5, days / HALF_LIFE_DAYS );
		[ 'g', 'p', 'r' ].forEach( function ( dim ) {
			Object.keys( profile[ dim ] ).forEach( function ( k ) {
				profile[ dim ][ k ] *= factor;
				if ( profile[ dim ][ k ] < 0.05 ) {
					delete profile[ dim ][ k ];
				}
			} );
		} );
	}

	function bump( dim, keys, amount ) {
		( keys || [] ).forEach( function ( k ) {
			profile[ dim ][ k ] = ( profile[ dim ][ k ] || 0 ) + amount;
		} );
	}

	function pushFront( list, id, max ) {
		id = parseInt( id, 10 );
		var i = list.indexOf( id );
		if ( i >= 0 ) {
			list.splice( i, 1 );
		}
		list.unshift( id );
		list.length = Math.min( list.length, max );
	}

	var ctx = window.snscsCtx || null;
	var activeSeconds = 0;

	function learnFromPage() {
		if ( ! learning || ! ctx ) {
			return;
		}
		decay();
		var share = function ( list, amount ) {
			return list && list.length ? amount / Math.sqrt( list.length ) : 0;
		};
		if ( 'search' === ctx.type ) {
			bump( 'g', ctx.genres, 1 );
			bump( 'r', ctx.regions, 0.5 );
			bump( 'p', ctx.sns, 1 );
			bump( 'g', ctx.kwGenres, 0.7 );
		} else if ( 'creator' === ctx.type ) {
			bump( 'g', ctx.genres, share( ctx.genres, 1.2 ) );
			bump( 'p', ctx.sns, share( ctx.sns, 0.8 ) );
			bump( 'r', ctx.regions, share( ctx.regions, 0.4 ) );
			pushFront( profile.recent, ctx.id, MAX_RECENT );
		} else if ( 'article' === ctx.type || 'column' === ctx.type ) {
			bump( 'g', ctx.genres, share( ctx.genres, 0.8 ) );
			bump( 'p', ctx.sns, share( ctx.sns, 0.8 ) );
			pushFront( profile.seen, ctx.id, MAX_SEEN );
			if ( ctx.creator ) {
				pushFront( profile.recent, ctx.creator, MAX_RECENT );
			}
		}
		save();
	}

	// じっくり見たページほど、その中身を好きだと考える（滞在時間の重み）。
	function watchDwell() {
		if ( ! learning || ! ctx || 'search' === ctx.type ) {
			return;
		}
		var lastActive = Date.now();
		var credited = 0;
		[ 'scroll', 'mousemove', 'keydown', 'touchstart' ].forEach( function ( name ) {
			window.addEventListener( name, function () {
				lastActive = Date.now();
			}, { passive: true } );
		} );
		setInterval( function () {
			if ( 'visible' === document.visibilityState && Date.now() - lastActive < 60000 ) {
				activeSeconds += 5;
			}
		}, 5000 );
		var credit = function () {
			// 30秒を超えた分から数え、最大5分まで。
			var seconds = Math.min( 300, Math.max( 0, activeSeconds - 30 ) );
			var extra = seconds - credited;
			if ( extra <= 0 ) {
				return;
			}
			credited = seconds;
			var amount = extra / ( 'creator' === ctx.type ? 120 : 200 );
			bump( 'g', ctx.genres, amount );
			bump( 'p', ctx.sns, amount / 2 );
			save();
		};
		document.addEventListener( 'visibilitychange', function () {
			if ( 'hidden' === document.visibilityState ) {
				credit();
			}
		} );
		window.addEventListener( 'pagehide', credit );
		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest ? event.target.closest( '[data-snscs-out]' ) : null;
			if ( link && 'creator' === ctx.type ) {
				// SNSやリンクを押した = この人のジャンルにとても関心がある。
				bump( 'g', ctx.genres, 0.5 );
				credit();
				save();
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * お気に入り
	 * ---------------------------------------------------------------- */

	function sendFavBeacon( id ) {
		var pv = window.snscsPageview;
		if ( ! pv || parseInt( pv.creator, 10 ) !== id || ! navigator.sendBeacon ) {
			return;
		}
		navigator.sendBeacon( pv.url, new URLSearchParams( { c: pv.creator, k: pv.kind, h: pv.hour, tok: pv.token, t: 'fav' } ) );
	}

	function paintFav( button ) {
		var id = parseInt( button.getAttribute( 'data-snscs-fav' ), 10 );
		var on = profile.favs.indexOf( id ) >= 0;
		button.hidden = false;
		button.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		button.setAttribute( 'aria-label', on ? 'お気に入りから外す' : 'お気に入りに追加' );
		var star = button.querySelector( '.snscs-fav__star' );
		if ( star ) {
			star.textContent = on ? '★' : '☆';
		}
		var label = button.querySelector( '.snscs-fav__label' );
		if ( label ) {
			label.textContent = on ? 'お気に入り登録済み' : 'お気に入り';
		}
	}

	function initFavs( root ) {
		Array.prototype.forEach.call( ( root || document ).querySelectorAll( '[data-snscs-fav]' ), paintFav );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-snscs-fav]' ) : null;
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		profile = load();
		var id = parseInt( button.getAttribute( 'data-snscs-fav' ), 10 );
		var i = profile.favs.indexOf( id );
		if ( i >= 0 ) {
			profile.favs.splice( i, 1 );
		} else {
			pushFront( profile.favs, id, MAX_FAVS );
			if ( learning && ctx && 'creator' === ctx.type && ctx.id === id ) {
				bump( 'g', ctx.genres, 2 );
			}
			sendFavBeacon( id );
		}
		save();
		Array.prototype.forEach.call( document.querySelectorAll( '[data-snscs-fav="' + id + '"]' ), paintFav );
		showToast( i >= 0 ? 'お気に入りから外しました' : 'お気に入りに追加しました' );
	} );

	function showToast( text ) {
		var toast = document.createElement( 'div' );
		toast.className = 'snscs-toast';
		toast.setAttribute( 'role', 'status' );
		toast.textContent = text;
		if ( config.favorites && text.indexOf( '追加' ) >= 0 ) {
			var link = document.createElement( 'a' );
			link.href = config.favorites;
			link.textContent = '一覧を見る';
			toast.appendChild( document.createTextNode( ' ' ) );
			toast.appendChild( link );
		}
		document.body.appendChild( toast );
		setTimeout( function () {
			toast.remove();
		}, 3500 );
	}

	/* ------------------------------------------------------------------
	 * あとから読み込む欄（あなたへのおすすめ・最近見た・お気に入り・おすすめの記事）
	 * ---------------------------------------------------------------- */

	function fillPlaceholders() {
		var holders = document.querySelectorAll( '[data-snscs-blocks]' );
		if ( ! holders.length || ! window.fetch ) {
			return;
		}
		Array.prototype.forEach.call( holders, function ( holder ) {
			var blocks = holder.getAttribute( 'data-snscs-blocks' ).split( ',' ).filter( function ( b ) {
				if ( 'recent' === b ) {
					return learning && profile.recent.length;
				}
				if ( 'favs' === b ) {
					return profile.favs.length;
				}
				return true;
			} );
			if ( ! blocks.length ) {
				holder.innerHTML = holder.getAttribute( 'data-snscs-blocks' ).indexOf( 'favs' ) >= 0 ? '<p class="snscs-help">まだお気に入りはありません。発信者ページの「☆ お気に入り」を押すと、ここに集まります。</p>' : '';
				return;
			}
			var params = new URLSearchParams( {
				blocks: blocks.join( ',' ),
				recent: learning ? profile.recent.join( ',' ) : '',
				favs: profile.favs.join( ',' ),
				seen: profile.seen.join( ',' ),
				// いま見ている発信者（記事ページなら書いた人）は、おすすめに出さない。
				here: ctx ? ( 'creator' === ctx.type ? ctx.id : ( ctx.creator || 0 ) ) : 0
			} );
			fetch( config.rest + ( config.rest.indexOf( '?' ) >= 0 ? '&' : '?' ) + params.toString(), { credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.ok ? response.json() : {};
				} )
				.then( function ( data ) {
					holder.innerHTML = blocks.map( function ( b ) {
						return data[ b ] || '';
					} ).join( '' );
					initFavs( holder );
				} )
				.catch( function () {} );
		} );
	}

	/* ------------------------------------------------------------------
	 * オフ・リセット
	 * ---------------------------------------------------------------- */

	document.addEventListener( 'click', function ( event ) {
		var target = event.target.closest ? event.target.closest( '[data-snscs-personal-off],[data-snscs-personal-on],[data-snscs-personal-reset]' ) : null;
		if ( ! target ) {
			return;
		}
		event.preventDefault();
		if ( target.hasAttribute( 'data-snscs-personal-on' ) ) {
			setCookie( config.off, '0', 365 );
			learning = config.learn;
		} else {
			if ( target.hasAttribute( 'data-snscs-personal-off' ) && ! window.confirm( '閲覧の傾向を使ったおすすめをやめ、これまでの傾向と「最近見た発信者」を消します。お気に入りは残ります。よろしいですか？' ) ) {
				return;
			}
			var favs = profile.favs;
			profile = empty();
			profile.favs = favs;
			if ( target.hasAttribute( 'data-snscs-personal-off' ) ) {
				setCookie( config.off, '1', 365 );
				learning = false;
			}
			save();
		}
		window.location.reload();
	} );

	// 設定欄（お気に入りページ）に今の状態を表示する。
	Array.prototype.forEach.call( document.querySelectorAll( '[data-snscs-personal-state]' ), function ( el ) {
		el.textContent = learning ? 'オン（閲覧の傾向を使っています）' : 'オフ';
	} );

	learnFromPage();
	watchDwell();
	if ( ! learning ) {
		save();
	}
	initFavs( document );
	fillPlaceholders();
} )();
