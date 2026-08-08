/**
 * Admin UI behavior for Affiliate Link Manager.
 *
 * Plain JS, no build step, no framework -- same convention as the
 * sibling webp-generator plugin. The Run Scan button loops resumable
 * AJAX batches (offset-based cursor) until the server reports done,
 * then reloads the page so the Dashboard/Links screens show fresh data.
 */
( function () {
	'use strict';

	var runButton = document.getElementById( 'alm-run-scan' );
	var progress = document.getElementById( 'alm-scan-progress' );

	if ( ! runButton || typeof almAdmin === 'undefined' ) {
		return;
	}

	var scannedSoFar = 0;

	function setProgressText( text ) {
		progress.hidden = false;
		progress.textContent = text;
	}

	function scanNextBatch( offset ) {
		var body = new FormData();
		body.append( 'action', almAdmin.action );
		body.append( 'nonce', almAdmin.nonce );
		body.append( 'offset', offset );

		fetch( almAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json.success ) {
					setProgressText( almAdmin.strings.error );
					runButton.disabled = false;
					return;
				}

				var data = json.data;
				scannedSoFar = data.next_offset;

				var total = almAdmin.total || 0;
				var shown = total ? Math.min( scannedSoFar, total ) : scannedSoFar;
				setProgressText( almAdmin.strings.scanning + ' (' + shown + ( total ? ' / ' + total : '' ) + ')' );

				if ( data.done ) {
					setProgressText( almAdmin.strings.scanDone );
					window.location.reload();
					return;
				}

				scanNextBatch( data.next_offset );
			} )
			.catch( function () {
				setProgressText( almAdmin.strings.error );
				runButton.disabled = false;
			} );
	}

	runButton.addEventListener( 'click', function () {
		runButton.disabled = true;
		scannedSoFar = 0;
		setProgressText( almAdmin.strings.scanning );
		scanNextBatch( 0 );
	} );
} )();
