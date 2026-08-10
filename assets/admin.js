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

	/**
	 * Edit-link modal: opened from an .alm-edit-link row action
	 * (ALM_Links_List_Table::column_anchor_text()), which carries
	 * everything the modal needs in data-* attributes -- no fetch
	 * needed just to open it. Hand-rolled (wp-admin has no native
	 * modal widget): focus trap, ESC-to-close, and returning focus to
	 * the row action on close are the accessibility baseline this is
	 * built to, not optional extras.
	 *
	 * Deliberately has no provider picker -- the affiliate network is
	 * always inferred from the URL, live, via
	 * ALM_Admin::handle_match_provider() (the same
	 * ALM_Provider_Registry::match_url() the scanner itself uses), not
	 * a manual choice. See ALM_Link_Converter::save_url().
	 */
	var modal = document.getElementById( 'alm-edit-link-modal' );

	if ( modal ) {
		var errorText = document.getElementById( 'alm-edit-link-error' );
		var saveButton = document.getElementById( 'alm-edit-link-save' );
		var cancelButton = document.getElementById( 'alm-edit-link-cancel' );
		var postField = document.getElementById( 'alm-edit-link-post' );
		var contextField = document.getElementById( 'alm-edit-link-context' );
		var providerDisplay = document.getElementById( 'alm-edit-link-provider-display' );
		var urlInput = document.getElementById( 'alm-edit-link-url-input' );

		var currentId = null;
		var originalProviderId = null;
		var originalProviderLabel = null;
		var originalUrl = null;
		var matchedProviderId = null;
		var triggerElement = null;
		var matchTimer = null;
		var matchRequestToken = 0;

		var getFocusable = function () {
			return modal.querySelectorAll( 'button, input, a[href]' );
		};

		var trapFocus = function ( event ) {
			if ( 'Escape' === event.key ) {
				closeModal();
				return;
			}

			if ( 'Tab' !== event.key ) {
				return;
			}

			var focusable = getFocusable();
			if ( ! focusable.length ) {
				return;
			}

			var first = focusable[ 0 ];
			var last = focusable[ focusable.length - 1 ];

			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		};

		var closeModal = function () {
			modal.hidden = true;
			document.removeEventListener( 'keydown', trapFocus );
			if ( matchTimer ) {
				clearTimeout( matchTimer );
			}
			if ( triggerElement ) {
				triggerElement.focus();
			}
		};

		/**
		 * Re-matches the provider for whatever's currently in the URL
		 * field and updates the "Affiliate partner" display -- debounced
		 * so it fires once after typing pauses, not on every keystroke.
		 * matchRequestToken guards against a slow older response landing
		 * after a newer one and clobbering the display with a stale
		 * result.
		 */
		var scheduleProviderMatch = function () {
			if ( matchTimer ) {
				clearTimeout( matchTimer );
			}

			matchTimer = setTimeout( function () {
				var url = urlInput.value.trim();
				if ( '' === url ) {
					return;
				}

				var thisRequest = ++matchRequestToken;
				providerDisplay.textContent = almAdmin.strings.matching;

				var body = new FormData();
				body.append( 'action', almAdmin.matchProviderAction );
				body.append( 'nonce', almAdmin.nonce );
				body.append( 'url', url );

				fetch( almAdmin.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body,
				} )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( json ) {
						if ( thisRequest !== matchRequestToken || ! json.success ) {
							return;
						}
						matchedProviderId = json.data.id;
						providerDisplay.textContent = json.data.label;
					} );
			}, 400 );
		};

		var openModal = function ( link ) {
			triggerElement = link;
			currentId = link.getAttribute( 'data-id' );
			originalProviderId = link.getAttribute( 'data-provider' );
			originalProviderLabel = link.getAttribute( 'data-provider-label' );
			originalUrl = link.getAttribute( 'data-url' );
			matchedProviderId = originalProviderId;

			postField.textContent = '';
			var postEditUrl = link.getAttribute( 'data-post-edit-url' );
			if ( postEditUrl ) {
				var postLink = document.createElement( 'a' );
				postLink.href = postEditUrl;
				postLink.target = '_blank';
				postLink.rel = 'noopener noreferrer';
				postLink.textContent = link.getAttribute( 'data-post-title' );
				postField.appendChild( postLink );
			} else {
				postField.textContent = link.getAttribute( 'data-post-title' );
			}

			providerDisplay.textContent = originalProviderLabel;
			urlInput.value = originalUrl;

			// Mirrors how the link actually reads in the post -- real
			// markup (other links, bold/italic), not flattened to plain
			// text, set off from the anchor's own text. before/after
			// are already sanitized server-side against a small allowed-
			// tag whitelist (ALM_Html_Fragment_Trait::get_anchor_context(),
			// which also forces any surviving link to target="_blank" --
			// a link inside this preview must never navigate away from
			// the modal) before ever reaching here, so setting innerHTML
			// from them is safe. Both can legitimately be empty (a link
			// with no real surrounding prose, or an adapter that doesn't
			// implement get_context()).
			var before = link.getAttribute( 'data-context-before' ) || '';
			var after = link.getAttribute( 'data-context-after' ) || '';
			var anchor = link.getAttribute( 'data-anchor' ) || '';
			contextField.innerHTML = '';

			if ( before ) {
				var beforeSpan = document.createElement( 'span' );
				beforeSpan.innerHTML = before;
				contextField.appendChild( beforeSpan );
				contextField.appendChild( document.createTextNode( ' ' ) );
			}

			var mark = document.createElement( 'mark' );
			mark.textContent = anchor;
			contextField.appendChild( mark );

			if ( after ) {
				contextField.appendChild( document.createTextNode( ' ' ) );
				var afterSpan = document.createElement( 'span' );
				afterSpan.innerHTML = after;
				contextField.appendChild( afterSpan );
			}

			errorText.hidden = true;
			errorText.textContent = '';
			saveButton.textContent = almAdmin.strings.save;

			modal.hidden = false;
			document.addEventListener( 'keydown', trapFocus );
			urlInput.focus();
		};

		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest ? event.target.closest( '.alm-edit-link' ) : null;
			if ( ! link ) {
				return;
			}
			event.preventDefault();
			openModal( link );
		} );

		urlInput.addEventListener( 'input', scheduleProviderMatch );

		cancelButton.addEventListener( 'click', closeModal );

		modal.addEventListener( 'click', function ( event ) {
			if ( event.target === modal ) {
				closeModal();
			}
		} );

		saveButton.addEventListener( 'click', function () {
			var url = urlInput.value.trim();

			// The link is currently tracked under a real, known
			// provider (not "unaffiliated") and saving would attach a
			// different one -- allowed, but not silent: this is a real
			// change to what's already tracked, per an explicit product
			// decision (an admin might legitimately be replacing a dead
			// or underperforming link).
			if ( originalProviderId && 'unclassified' !== originalProviderId && matchedProviderId !== originalProviderId ) {
				var warned = window.confirm( almAdmin.strings.forceConvertWarn.replace( '%s', originalProviderLabel ) );
				if ( ! warned ) {
					return;
				}
			}

			saveButton.disabled = true;
			cancelButton.disabled = true;
			errorText.hidden = true;

			var body = new FormData();
			body.append( 'action', almAdmin.editLinkAction );
			body.append( 'nonce', almAdmin.nonce );
			body.append( 'id', currentId );
			body.append( 'url', url );

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
						errorText.textContent = ( json.data && json.data.message ) ? json.data.message : almAdmin.strings.error;
						errorText.hidden = false;
						saveButton.disabled = false;
						cancelButton.disabled = false;
						return;
					}

					window.location.reload();
				} )
				.catch( function () {
					errorText.textContent = almAdmin.strings.error;
					errorText.hidden = false;
					saveButton.disabled = false;
					cancelButton.disabled = false;
				} );
		} );
	}

	/**
	 * Bulk "Convert to [Provider]" confirm -- WP_List_Table renders two
	 * independent action selects (top/bottom), only one of which is
	 * actually "current" per request (whichever isn't "-1"; see WP
	 * core's own current_action()), so both are checked the same way
	 * server-side handling does.
	 */
	var bulkForm = document.querySelector( '.alm-links-table' ) ? document.querySelector( '.alm-links-table' ).closest( 'form' ) : null;

	if ( bulkForm ) {
		bulkForm.addEventListener( 'submit', function ( event ) {
			var topAction = bulkForm.querySelector( 'select[name="action"]' );
			var bottomAction = bulkForm.querySelector( 'select[name="action2"]' );
			var action = ( topAction && '-1' !== topAction.value ) ? topAction.value : ( bottomAction ? bottomAction.value : '-1' );

			if ( 0 !== action.indexOf( 'convert_' ) ) {
				return;
			}

			var providerId = action.slice( 'convert_'.length );
			var label = ( almAdmin.providers && almAdmin.providers[ providerId ] ) ? almAdmin.providers[ providerId ].label : providerId;

			if ( ! window.confirm( almAdmin.strings.bulkConvertWarn.replace( '%s', label ) ) ) {
				event.preventDefault();
			}
		} );
	}
} )();
