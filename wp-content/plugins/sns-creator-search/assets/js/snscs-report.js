/**
 * 月間成長レポート: 「画像をシェア」ボタン（SNSCS_Report）。
 * スマホでは、画像そのものを Instagram などのアプリへ渡せる（Web Share API）。使えない端末ではボタンを出さない。
 */
( function () {
	var button = document.querySelector( '[data-snscs-report-share]' );
	if ( ! button || ! navigator.share || ! navigator.canShare || ! window.fetch || ! window.File ) {
		return;
	}
	var probe = new File( [ '' ], 'probe.png', { type: 'image/png' } );
	if ( ! navigator.canShare( { files: [ probe ] } ) ) {
		return;
	}
	button.hidden = false;
	var file = null;
	// 押したときにすぐ渡せるよう、先に画像を読み込んでおく（押してから読み込むと共有できない端末がある）。
	fetch( button.getAttribute( 'data-image' ), { credentials: 'same-origin' } )
		.then( function ( response ) {
			return response.blob();
		} )
		.then( function ( blob ) {
			file = new File( [ blob ], button.getAttribute( 'data-file' ), { type: 'image/png' } );
		} )
		.catch( function () {
			button.hidden = true;
		} );
	button.addEventListener( 'click', function () {
		if ( ! file ) {
			return;
		}
		navigator
			.share( { files: [ file ], text: button.getAttribute( 'data-text' ) + '\n' + button.getAttribute( 'data-url' ) } )
			.catch( function () {} );
	} );
} )();
