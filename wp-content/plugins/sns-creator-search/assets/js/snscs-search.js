/**
 * 検索窓のサジェスト（入力中の候補）と、検索結果での行動の記録（SNSCS_Feedback / SNSCS_Index）。
 *
 * 記録すること（学習のもと）:
 *   click  : 検索結果の発信者を押した
 *   good   : 押した先のページを30秒以上見た、またはSNS・リンクへ進んだ（満足）
 *   bounce : 押して15秒以内に検索結果へ戻ってきた（期待と違った）
 * 押した発信者は、このタブの sessionStorage にだけ一時的に覚える。
 */
( function () {
	var config = window.snscsSearch;
	if ( ! config ) {
		return;
	}
	var KEY = 'snscs_sclick';

	function beacon( type, qid, tok, creator ) {
		if ( ! navigator.sendBeacon || ! window.URLSearchParams ) {
			return;
		}
		navigator.sendBeacon( config.sfb, new URLSearchParams( { t: type, q: qid, tok: tok, c: creator } ) );
	}

	function read() {
		try {
			return JSON.parse( window.sessionStorage.getItem( KEY ) || 'null' );
		} catch ( e ) {
			return null;
		}
	}

	function write( value ) {
		try {
			if ( value ) {
				window.sessionStorage.setItem( KEY, JSON.stringify( value ) );
			} else {
				window.sessionStorage.removeItem( KEY );
			}
		} catch ( e ) {}
	}

	/**
	 * このタブで押した発信者を、タブを閉じると消えるクッキー（snscs_ss・最大5人）に覚える。
	 * 次の検索で、その人に近い発信者を上の方へ寄せる（SNSCS_Ranker）。
	 * 「好みに合わせる」をオフにしている人・ブラウザで「追跡しない」を選んでいる人は覚えない。
	 */
	function rememberSession( creator ) {
		if ( /(?:^|; )snscs_pf_off=1/.test( document.cookie ) || ( navigator.globalPrivacyControl && ! /(?:^|; )snscs_pf_off=0/.test( document.cookie ) ) ) {
			return;
		}
		var match = document.cookie.match( /(?:^|; )snscs_ss=([^;]*)/ );
		var ids = match ? decodeURIComponent( match[ 1 ] ).split( ',' ).filter( Boolean ) : [];
		ids = [ String( creator ) ].concat( ids.filter( function ( id ) {
			return id !== String( creator );
		} ) ).slice( 0, 5 );
		document.cookie = 'snscs_ss=' + encodeURIComponent( ids.join( ',' ) ) + '; path=/; SameSite=Lax' + ( 'https:' === location.protocol ? '; Secure' : '' );
	}

	/* ------------------------------------------------------------------
	 * 検索結果のページ
	 * ---------------------------------------------------------------- */

	if ( config.qid ) {
		// 押してすぐ戻ってきた = 期待と違った（「戻る」でページがそのまま復元された場合も調べる）。
		var checkBounce = function () {
			var last = read();
			if ( last && ! last.good && Date.now() - last.at < 15000 ) {
				beacon( 'bounce', last.qid, last.tok, last.c );
			}
			write( null );
		};
		checkBounce();
		window.addEventListener( 'pageshow', function ( event ) {
			if ( event.persisted ) {
				checkBounce();
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest ? event.target.closest( '[data-snscs-cid] a[href]' ) : null;
			if ( ! link || link.closest( '.snscs-fav' ) ) {
				return;
			}
			var card = link.closest( '[data-snscs-cid]' );
			var creator = card.getAttribute( 'data-snscs-cid' );
			rememberSession( creator );
			// SNSへ直接進んだ場合も「押して満足した」とみなす。
			var external = link.hasAttribute( 'data-snscs-out' );
			beacon( 'click', config.qid, config.tok, creator );
			if ( external ) {
				beacon( 'good', config.qid, config.tok, creator );
			} else {
				write( { qid: config.qid, tok: config.tok, c: creator, at: Date.now(), good: false } );
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * 押した先の発信者ページ
	 * ---------------------------------------------------------------- */

	var ctx = window.snscsCtx;
	var pending = read();
	if ( ! config.qid && pending && ctx && 'creator' === ctx.type && String( ctx.id ) === String( pending.c ) && Date.now() - pending.at < 30 * 60000 ) {
		var markGood = function () {
			var current = read();
			if ( current && ! current.good ) {
				current.good = true;
				write( current );
				beacon( 'good', current.qid, current.tok, current.c );
			}
		};
		var seconds = 0;
		var timer = setInterval( function () {
			if ( 'visible' === document.visibilityState ) {
				seconds += 5;
			}
			if ( seconds >= 30 ) {
				clearInterval( timer );
				markGood();
			}
		}, 5000 );
		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest && event.target.closest( '[data-snscs-out]' ) ) {
				markGood();
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * サジェスト（入力中の候補）
	 * ---------------------------------------------------------------- */

	var inputs = document.querySelectorAll( 'input[name="snscs_q"]' );
	Array.prototype.forEach.call( inputs, function ( input, index ) {
		if ( ! window.fetch ) {
			return;
		}
		var list = document.createElement( 'datalist' );
		list.id = 'snscs-suggest-' + index;
		input.setAttribute( 'list', list.id );
		input.setAttribute( 'autocomplete', 'off' );
		input.parentNode.appendChild( list );
		var timer = null;
		var lastQuery = '';
		input.addEventListener( 'input', function () {
			clearTimeout( timer );
			var q = input.value.trim();
			if ( ! q || q === lastQuery ) {
				return;
			}
			timer = setTimeout( function () {
				lastQuery = q;
				fetch( config.suggest + ( config.suggest.indexOf( '?' ) >= 0 ? '&' : '?' ) + 'q=' + encodeURIComponent( q ) )
					.then( function ( response ) {
						return response.ok ? response.json() : [];
					} )
					.then( function ( items ) {
						list.innerHTML = '';
						( items || [] ).forEach( function ( item ) {
							var option = document.createElement( 'option' );
							option.value = item.label;
							list.appendChild( option );
						} );
					} )
					.catch( function () {} );
			}, 200 );
		} );
	} );
} )();
