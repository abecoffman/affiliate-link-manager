/**
 * Admin UI behavior for Affiliate Link Manager.
 *
 * Plain JS, no build step, no framework -- same convention as the
 * sibling webp-generator plugin. Both buttons below loop resumable AJAX
 * batches until the server reports done, then reload the page so the
 * Dashboard/Links/Posts screens show fresh data. Each is wired
 * independently (its own existence check), not chained off the other
 * one being present -- they're two separate features that happen to
 * both live on the Dashboard today, not one feature.
 */
( function () {
	'use strict';

	if ( typeof almAdmin === 'undefined' ) {
		return;
	}

	/**
	 * Run Scan: offset-based cursor over posts, looping AJAX batches
	 * until the server reports done.
	 */
	var runButton = document.getElementById( 'alm-run-scan' );
	var progress = document.getElementById( 'alm-scan-progress' );

	if ( runButton ) {
		var scannedSoFar = 0;

		var setProgressText = function ( text ) {
			progress.hidden = false;
			progress.textContent = text;
		};

		var scanNextBatch = function ( offset ) {
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
		};

		runButton.addEventListener( 'click', function () {
			runButton.disabled = true;
			scannedSoFar = 0;
			setProgressText( almAdmin.strings.scanning );
			scanNextBatch( 0 );
		} );
	}

	/**
	 * Check Domains: no offset cursor needed, unlike Run Scan above --
	 * ALM_Domain_Scanner always asks for "the next few domains still
	 * needing a check", and a domain drops out of that pool for good
	 * once checked, so each call naturally picks up where the last one
	 * left off with no state to pass between requests.
	 */
	var checkDomainsButton = document.getElementById( 'alm-check-domains' );
	var domainProgress = document.getElementById( 'alm-domain-check-progress' );

	if ( checkDomainsButton ) {
		var domainsCheckedSoFar = 0;

		var setDomainProgressText = function ( text ) {
			domainProgress.hidden = false;
			domainProgress.textContent = text;
		};

		var checkNextDomainBatch = function () {
			var body = new FormData();
			body.append( 'action', almAdmin.domainCheckAction );
			body.append( 'nonce', almAdmin.nonce );

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
						setDomainProgressText( almAdmin.strings.error );
						checkDomainsButton.disabled = false;
						return;
					}

					var data = json.data;
					domainsCheckedSoFar += data.checked;

					var total = almAdmin.domainsTotal || 0;
					var shown = total ? Math.min( domainsCheckedSoFar, total ) : domainsCheckedSoFar;
					setDomainProgressText( almAdmin.strings.checkingDomains + ' (' + shown + ( total ? ' / ' + total : '' ) + ')' );

					if ( data.done ) {
						setDomainProgressText( almAdmin.strings.domainCheckDone );
						window.location.reload();
						return;
					}

					checkNextDomainBatch();
				} )
				.catch( function () {
					setDomainProgressText( almAdmin.strings.error );
					checkDomainsButton.disabled = false;
				} );
		};

		checkDomainsButton.addEventListener( 'click', function () {
			checkDomainsButton.disabled = true;
			domainsCheckedSoFar = 0;
			setDomainProgressText( almAdmin.strings.checkingDomains );
			checkNextDomainBatch();
		} );
	}
} )();
