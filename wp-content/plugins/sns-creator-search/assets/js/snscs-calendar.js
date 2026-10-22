/**
 * SNS発信カレンダー（SNSCS_Calendar）。
 *
 * 月の表示、日を選ぶとその日の予定、予定の追加・変更・削除・完了のチェック。
 * ログイン中はサーバーに保存し、ログインしていなければこのブラウザ（localStorage）に保存する。
 */
( function () {
	var config = window.snscsCalendar;
	var root = document.querySelector( '[data-snscs-calendar]' );
	if ( ! config || ! root || ! window.fetch ) {
		return;
	}

	var LOCAL_KEY = 'snscs_calendar';
	var WEEK = [ '日', '月', '火', '水', '木', '金', '土' ];
	var state = {
		year: parseInt( config.today.slice( 0, 4 ), 10 ),
		month: parseInt( config.today.slice( 5, 7 ), 10 ) - 1,
		selected: config.today,
		publicEvents: [],
		mine: []
	};

	/* ------------------------------------------------------------------
	 * 小さな道具
	 * ---------------------------------------------------------------- */

	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	function ymd( date ) {
		return date.getFullYear() + '-' + pad( date.getMonth() + 1 ) + '-' + pad( date.getDate() );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( 'text' === key ) {
				node.textContent = attrs[ key ];
			} else if ( 'on' === key.slice( 0, 2 ) ) {
				node.addEventListener( key.slice( 2 ), attrs[ key ] );
			} else if ( false !== attrs[ key ] && null !== attrs[ key ] ) {
				node.setAttribute( key, true === attrs[ key ] ? '' : attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( 'string' === typeof child ? document.createTextNode( child ) : child );
			}
		} );
		return node;
	}

	function category( key ) {
		return config.categories[ key ] || config.categories.other;
	}

	function label( date ) {
		var d = new Date( date + 'T00:00:00' );
		return ( d.getMonth() + 1 ) + '月' + d.getDate() + '日（' + WEEK[ d.getDay() ] + '）';
	}

	/* ------------------------------------------------------------------
	 * 保存先（サーバー または このブラウザ）
	 * ---------------------------------------------------------------- */

	function localAll() {
		try {
			return JSON.parse( window.localStorage.getItem( LOCAL_KEY ) || '[]' );
		} catch ( e ) {
			return [];
		}
	}

	function localWrite( items ) {
		try {
			window.localStorage.setItem( LOCAL_KEY, JSON.stringify( items ) );
		} catch ( e ) {}
	}

	function api( method, path, body ) {
		return fetch( config.rest + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( data && data.message ? data.message : '保存できませんでした。' );
				}
				return data;
			} );
		} );
	}

	function range() {
		var first = new Date( state.year, state.month, 1 );
		var start = new Date( first );
		start.setDate( 1 - first.getDay() );
		var end = new Date( start );
		end.setDate( start.getDate() + 41 );
		return { start: start, end: end };
	}

	function load() {
		var r = range();
		var from = ymd( r.start );
		var to = ymd( r.end );
		var query = ( config.rest.indexOf( '?' ) >= 0 ? '&' : '?' ) + 'from=' + from + '&to=' + to;
		return fetch( config.rest + query, { credentials: 'same-origin', headers: config.nonce ? { 'X-WP-Nonce': config.nonce } : {} } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				state.publicEvents = data.public || [];
				state.mine = config.loggedIn ? ( data.mine || [] ) : localAll().filter( function ( item ) {
					return item.date >= from && item.date <= to;
				} );
				render();
			} )
			.catch( function () {
				state.mine = config.loggedIn ? [] : localAll();
				render();
			} );
	}

	function save( item ) {
		if ( config.loggedIn ) {
			return api( 'POST', '', item );
		}
		var items = localAll();
		if ( item.id ) {
			items = items.map( function ( old ) {
				return old.id === item.id ? item : old;
			} );
		} else {
			item.id = Date.now();
			items.push( item );
		}
		localWrite( items );
		return Promise.resolve( item );
	}

	function remove( item ) {
		if ( config.loggedIn ) {
			return api( 'DELETE', '/' + item.id );
		}
		localWrite( localAll().filter( function ( old ) {
			return old.id !== item.id;
		} ) );
		return Promise.resolve();
	}

	/* ------------------------------------------------------------------
	 * 表示
	 * ---------------------------------------------------------------- */

	function eventsOn( date ) {
		return state.publicEvents.filter( function ( ev ) {
			return date >= ev.date && date <= ( ev.end || ev.date );
		} );
	}

	function mineOn( date ) {
		return state.mine.filter( function ( item ) {
			return item.date === date;
		} ).sort( function ( a, b ) {
			return ( a.time || '99' ).localeCompare( b.time || '99' );
		} );
	}

	function render() {
		var guest = root.querySelector( '.snscs-calendar__guest' );
		root.innerHTML = '';
		if ( guest ) {
			root.appendChild( guest );
		}
		root.appendChild( renderHead() );
		root.appendChild( renderGrid() );
		root.appendChild( renderDay() );
		root.appendChild( renderLegend() );
	}

	function move( diff ) {
		var d = new Date( state.year, state.month + diff, 1 );
		state.year = d.getFullYear();
		state.month = d.getMonth();
		state.selected = ymd( d );
		load();
	}

	function renderHead() {
		return el( 'div', { class: 'snscs-calendar__head' }, [
			el( 'button', { type: 'button', class: 'snscs-button', 'aria-label': '前の月', onclick: function () {
				move( -1 );
			} }, [ '‹' ] ),
			el( 'h2', { class: 'snscs-calendar__title', 'aria-live': 'polite', text: state.year + '年' + ( state.month + 1 ) + '月' } ),
			el( 'button', { type: 'button', class: 'snscs-button', 'aria-label': '次の月', onclick: function () {
				move( 1 );
			} }, [ '›' ] ),
			el( 'button', { type: 'button', class: 'snscs-button', onclick: function () {
				state.year = parseInt( config.today.slice( 0, 4 ), 10 );
				state.month = parseInt( config.today.slice( 5, 7 ), 10 ) - 1;
				state.selected = config.today;
				load();
			} }, [ '今日' ] ),
			el( 'button', { type: 'button', class: 'snscs-button snscs-button--primary snscs-calendar__add', onclick: function () {
				openForm( { date: state.selected } );
			} }, [ '＋ 予定を追加' ] )
		] );
	}

	function renderGrid() {
		var r = range();
		var table = el( 'div', { class: 'snscs-calendar__grid', role: 'grid', 'aria-label': state.year + '年' + ( state.month + 1 ) + '月' } );
		WEEK.forEach( function ( w, i ) {
			table.appendChild( el( 'div', { class: 'snscs-calendar__dow' + ( 0 === i ? ' is-sun' : 6 === i ? ' is-sat' : '' ), role: 'columnheader', text: w } ) );
		} );
		for ( var i = 0; i < 42; i++ ) {
			var d = new Date( r.start );
			d.setDate( r.start.getDate() + i );
			table.appendChild( renderCell( d ) );
		}
		return table;
	}

	function renderCell( d ) {
		var date = ymd( d );
		var events = eventsOn( date );
		var mine = mineOn( date );
		var classes = [ 'snscs-calendar__cell' ];
		if ( d.getMonth() !== state.month ) {
			classes.push( 'is-other' );
		}
		if ( date === config.today ) {
			classes.push( 'is-today' );
		}
		if ( date === state.selected ) {
			classes.push( 'is-selected' );
		}
		if ( 0 === d.getDay() ) {
			classes.push( 'is-sun' );
		}
		if ( 6 === d.getDay() ) {
			classes.push( 'is-sat' );
		}
		var chips = [];
		events.slice( 0, 1 ).forEach( function ( ev ) {
			chips.push( el( 'span', { class: 'snscs-calendar__chip is-public', text: ev.title } ) );
		} );
		mine.slice( 0, 2 ).forEach( function ( item ) {
			var chip = el( 'span', { class: 'snscs-calendar__chip' + ( item.done ? ' is-done' : '' ), text: item.title } );
			chip.style.setProperty( '--snscs-cal', category( item.category ).color );
			chips.push( chip );
		} );
		var more = events.length + mine.length - chips.length;
		if ( more > 0 ) {
			chips.push( el( 'span', { class: 'snscs-calendar__more', text: '+' + more } ) );
		}
		return el( 'button', {
			type: 'button',
			role: 'gridcell',
			class: classes.join( ' ' ),
			'aria-label': label( date ) + ( mine.length ? ' 自分の予定' + mine.length + '件' : '' ) + ( events.length ? ' みんなの予定' + events.length + '件' : '' ),
			'aria-selected': date === state.selected ? 'true' : 'false',
			onclick: function () {
				state.selected = date;
				if ( d.getMonth() !== state.month ) {
					state.year = d.getFullYear();
					state.month = d.getMonth();
					load();
				} else {
					render();
				}
			},
			ondblclick: function () {
				openForm( { date: date } );
			}
		}, [ el( 'span', { class: 'snscs-calendar__num', text: String( d.getDate() ) } ) ].concat( chips ) );
	}

	function renderDay() {
		var date = state.selected;
		var box = el( 'section', { class: 'snscs-calendar__day', 'aria-label': label( date ) + 'の予定' } );
		box.appendChild( el( 'div', { class: 'snscs-calendar__day-head' }, [
			el( 'h3', { text: label( date ) } ),
			el( 'button', { type: 'button', class: 'snscs-button', onclick: function () {
				openForm( { date: date } );
			} }, [ '＋ この日に追加' ] )
		] ) );

		var events = eventsOn( date );
		var mine = mineOn( date );
		if ( ! events.length && ! mine.length ) {
			box.appendChild( el( 'p', { class: 'snscs-help', text: '予定はありません。「＋ この日に追加」から、投稿予定や撮影の予定を入れてみましょう。' } ) );
		}

		if ( events.length ) {
			var pub = el( 'ul', { class: 'snscs-calendar__list' } );
			events.forEach( function ( ev ) {
				pub.appendChild( el( 'li', { class: 'snscs-calendar__item is-public' }, [
					el( 'span', { class: 'snscs-calendar__badge', text: 'day' === ev.kind ? '記念日' : 'イベント' } ),
					ev.url ? el( 'a', { href: ev.url, target: '_blank', rel: 'noopener', text: ev.title } ) : el( 'strong', { text: ev.title } ),
					ev.desc ? el( 'span', { class: 'snscs-calendar__memo', text: ev.desc } ) : null
				] ) );
			} );
			box.appendChild( pub );
		}

		if ( mine.length ) {
			var list = el( 'ul', { class: 'snscs-calendar__list' } );
			mine.forEach( function ( item ) {
				var cat = category( item.category );
				var check = el( 'input', { type: 'checkbox', 'aria-label': '完了', checked: item.done ? true : false, onchange: function () {
					item.done = check.checked;
					save( item ).then( load ).catch( alertError );
				} } );
				var li = el( 'li', { class: 'snscs-calendar__item' + ( item.done ? ' is-done' : '' ) }, [
					check,
					el( 'span', { class: 'snscs-calendar__badge', text: cat.label } ),
					item.time ? el( 'span', { class: 'snscs-calendar__time', text: item.time } ) : null,
					el( 'strong', { text: item.title } ),
					item.sns ? el( 'span', { class: 'snscs-calendar__sns', text: config.sns[ item.sns ] || item.sns } ) : null,
					item.remind ? el( 'span', { class: 'snscs-calendar__sns', text: '朝にお知らせ' } ) : null,
					item.memo ? el( 'span', { class: 'snscs-calendar__memo', text: item.memo } ) : null,
					el( 'span', { class: 'snscs-calendar__actions' }, [
						el( 'button', { type: 'button', class: 'snscs-linklike', onclick: function () {
							openForm( item );
						} }, [ '編集' ] ),
						el( 'button', { type: 'button', class: 'snscs-linklike', onclick: function () {
							if ( window.confirm( '「' + item.title + '」を削除しますか？' ) ) {
								remove( item ).then( load ).catch( alertError );
							}
						} }, [ '削除' ] )
					] )
				] );
				li.style.setProperty( '--snscs-cal', cat.color );
				list.appendChild( li );
			} );
			box.appendChild( list );
		}
		return box;
	}

	function renderLegend() {
		var items = Object.keys( config.categories ).map( function ( key ) {
			var dot = el( 'span', { class: 'snscs-calendar__dot' } );
			dot.style.background = config.categories[ key ].color;
			return el( 'li', {}, [ dot, config.categories[ key ].label ] );
		} );
		return el( 'ul', { class: 'snscs-calendar__legend' }, items );
	}

	function alertError( error ) {
		window.alert( error && error.message ? error.message : '保存できませんでした。' );
	}

	/* ------------------------------------------------------------------
	 * 予定の入力
	 * ---------------------------------------------------------------- */

	function openForm( item ) {
		var dialog = el( 'dialog', { class: 'snscs-calendar__dialog', 'aria-label': item.id ? '予定を編集' : '予定を追加' } );
		var catSelect = el( 'select', { name: 'category' }, Object.keys( config.categories ).map( function ( key ) {
			return el( 'option', { value: key, selected: ( item.category || 'post' ) === key ? true : false, text: config.categories[ key ].label } );
		} ) );
		var snsSelect = el( 'select', { name: 'sns' }, [ el( 'option', { value: '', text: '指定なし' } ) ].concat( Object.keys( config.sns ).map( function ( key ) {
			return el( 'option', { value: key, selected: item.sns === key ? true : false, text: config.sns[ key ] } );
		} ) ) );
		var field = function ( text, input ) {
			return el( 'label', { class: 'snscs-field' }, [ el( 'span', { text: text } ), input ] );
		};
		var form = el( 'form', { class: 'snscs-fields', method: 'dialog' }, [
			el( 'h3', { text: item.id ? '予定を編集' : '予定を追加' } ),
			field( 'タイトル（必須）', el( 'input', { type: 'text', name: 'title', required: true, maxlength: '80', value: item.title || '', placeholder: '例: 新作レシピのリール投稿' } ) ),
			el( 'div', { class: 'snscs-calendar__row' }, [
				field( '日付', el( 'input', { type: 'date', name: 'date', required: true, value: item.date || state.selected } ) ),
				field( '時刻（任意）', el( 'input', { type: 'time', name: 'time', value: item.time || '' } ) )
			] ),
			el( 'div', { class: 'snscs-calendar__row' }, [ field( '種類', catSelect ), field( 'SNS', snsSelect ) ] ),
			field( 'メモ', el( 'textarea', { name: 'memo', rows: '3', maxlength: '1000', text: item.memo || '' } ) ),
			config.loggedIn ? el( 'label', { class: 'snscs-check' }, [ el( 'input', { type: 'checkbox', name: 'remind', checked: item.remind ? true : false } ), el( 'span', { text: '当日の朝にメールで知らせる' } ) ] ) : null,
			el( 'label', { class: 'snscs-check' }, [ el( 'input', { type: 'checkbox', name: 'done', checked: item.done ? true : false } ), el( 'span', { text: '完了' } ) ] ),
			el( 'div', { class: 'snscs-calendar__buttons' }, [
				el( 'button', { type: 'submit', class: 'snscs-button snscs-button--primary', value: 'save' }, [ '保存する' ] ),
				el( 'button', { type: 'button', class: 'snscs-button', onclick: function () {
					dialog.close();
				} }, [ 'キャンセル' ] )
			] )
		] );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var data = new FormData( form );
			var next = {
				id: item.id || 0,
				title: String( data.get( 'title' ) || '' ).trim(),
				date: String( data.get( 'date' ) || '' ),
				time: String( data.get( 'time' ) || '' ),
				category: String( data.get( 'category' ) || 'post' ),
				sns: String( data.get( 'sns' ) || '' ),
				memo: String( data.get( 'memo' ) || '' ),
				remind: !! data.get( 'remind' ),
				done: !! data.get( 'done' )
			};
			if ( ! next.title || ! next.date ) {
				return;
			}
			save( next ).then( function () {
				dialog.close();
				state.selected = next.date;
				var d = new Date( next.date + 'T00:00:00' );
				state.year = d.getFullYear();
				state.month = d.getMonth();
				load();
			} ).catch( alertError );
		} );
		dialog.appendChild( form );
		dialog.addEventListener( 'close', function () {
			dialog.remove();
		} );
		document.body.appendChild( dialog );
		if ( dialog.showModal ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
		}
		form.querySelector( 'input[name=title]' ).focus();
	}

	load();
} )();
