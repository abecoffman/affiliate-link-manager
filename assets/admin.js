/**
 * Admin UI behavior for Affiliate Link Manager.
 *
 * Plain JS, no build step, no framework -- same convention as the
 * sibling webp-generator plugin. The three Dashboard Tasks buttons each
 * loop resumable AJAX batches until the server reports done, then
 * reload the page so the Dashboard/Links screens show fresh data. Each
 * is wired independently (its own existence check), not chained off
 * the others being present -- they're separate features that happen to
 * share one Tasks table today, not one feature.
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
	 * left off with no state to pass between requests. The one bit of
	 * state this loop *does* thread through is `isFirst` -- true only on
	 * the click-triggered call, sent as `first` so
	 * ALM_Domain_Scanner::check_batch() knows exactly when this run
	 * began (needed to compute what this run itself found, once done --
	 * see its docblock).
	 */
	var checkDomainsButton = document.getElementById( 'alm-check-domains' );
	var domainProgress = document.getElementById( 'alm-domain-check-progress' );

	if ( checkDomainsButton ) {
		var domainsCheckedSoFar = 0;

		var setDomainProgressText = function ( text ) {
			domainProgress.hidden = false;
			domainProgress.textContent = text;
		};

		var checkNextDomainBatch = function ( isFirst ) {
			var body = new FormData();
			body.append( 'action', almAdmin.domainCheckAction );
			body.append( 'nonce', almAdmin.nonce );
			body.append( 'first', isFirst ? '1' : '0' );

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

					checkNextDomainBatch( false );
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
			checkNextDomainBatch( true );
		} );
	}

	/**
	 * Expand Shortened Links: same no-offset-cursor shape as Check
	 * Domains above -- ALM_Shortener_Scanner always asks for "the next
	 * few links still needing resolution," and a link drops out of that
	 * pool for good (resolved_at gets set) once checked. Same `first`
	 * flag as Check Domains, same reason (see its comment above).
	 */
	var expandShortenersButton = document.getElementById( 'alm-expand-shorteners' );
	var shortenerProgress = document.getElementById( 'alm-expand-shorteners-progress' );

	if ( expandShortenersButton ) {
		var shortenersCheckedSoFar = 0;

		var setShortenerProgressText = function ( text ) {
			shortenerProgress.hidden = false;
			shortenerProgress.textContent = text;
		};

		var expandNextShortenerBatch = function ( isFirst ) {
			var body = new FormData();
			body.append( 'action', almAdmin.expandShortenersAction );
			body.append( 'nonce', almAdmin.nonce );
			body.append( 'first', isFirst ? '1' : '0' );

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
						setShortenerProgressText( almAdmin.strings.error );
						expandShortenersButton.disabled = false;
						return;
					}

					var data = json.data;
					shortenersCheckedSoFar += data.checked;

					var total = almAdmin.shortenersTotal || 0;
					var shown = total ? Math.min( shortenersCheckedSoFar, total ) : shortenersCheckedSoFar;
					setShortenerProgressText( almAdmin.strings.expandingShorteners + ' (' + shown + ( total ? ' / ' + total : '' ) + ')' );

					if ( data.done ) {
						setShortenerProgressText( almAdmin.strings.expandShortenersDone );
						window.location.reload();
						return;
					}

					expandNextShortenerBatch( false );
				} )
				.catch( function () {
					setShortenerProgressText( almAdmin.strings.error );
					expandShortenersButton.disabled = false;
				} );
		};

		expandShortenersButton.addEventListener( 'click', function () {
			expandShortenersButton.disabled = true;
			shortenersCheckedSoFar = 0;
			setShortenerProgressText( almAdmin.strings.expandingShorteners );
			expandNextShortenerBatch( true );
		} );
	}

	/**
	 * Check Candidate Links: same no-offset-cursor/`first`-flag shape as
	 * Check Domains and Expand Shortened Links above -- ALM_Link_Health_Scanner
	 * always asks for "the next few candidates still needing a check,"
	 * and a link drops out of that pool for good (health_checked_at
	 * gets set) once checked.
	 */
	var linkHealthButton = document.getElementById( 'alm-check-link-health' );
	var linkHealthProgress = document.getElementById( 'alm-link-health-progress' );

	if ( linkHealthButton ) {
		var linksHealthCheckedSoFar = 0;

		var setLinkHealthProgressText = function ( text ) {
			linkHealthProgress.hidden = false;
			linkHealthProgress.textContent = text;
		};

		var checkNextLinkHealthBatch = function ( isFirst ) {
			var body = new FormData();
			body.append( 'action', almAdmin.linkHealthAction );
			body.append( 'nonce', almAdmin.nonce );
			body.append( 'first', isFirst ? '1' : '0' );

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
						setLinkHealthProgressText( almAdmin.strings.error );
						linkHealthButton.disabled = false;
						return;
					}

					var data = json.data;
					linksHealthCheckedSoFar += data.checked;

					var total = almAdmin.linkHealthTotal || 0;
					var shown = total ? Math.min( linksHealthCheckedSoFar, total ) : linksHealthCheckedSoFar;
					setLinkHealthProgressText( almAdmin.strings.checkingLinkHealth + ' (' + shown + ( total ? ' / ' + total : '' ) + ')' );

					if ( data.done ) {
						setLinkHealthProgressText( almAdmin.strings.linkHealthDone );
						window.location.reload();
						return;
					}

					checkNextLinkHealthBatch( false );
				} )
				.catch( function () {
					setLinkHealthProgressText( almAdmin.strings.error );
					linkHealthButton.disabled = false;
				} );
		};

		linkHealthButton.addEventListener( 'click', function () {
			linkHealthButton.disabled = true;
			linksHealthCheckedSoFar = 0;
			setLinkHealthProgressText( almAdmin.strings.checkingLinkHealth );
			checkNextLinkHealthBatch( true );
		} );
	}

	/**
	 * Edit-link modal: opened by clicking a row's own Link cell
	 * (ALM_Links_List_Table::column_link()), which carries everything
	 * the modal needs in data-* attributes -- no fetch needed just to
	 * open it. Hand-rolled (wp-admin has no native modal widget): focus
	 * trap, ESC-to-close, and returning focus to the trigger on close
	 * are the accessibility baseline this is built to, not optional
	 * extras.
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
		var thumbField = document.getElementById( 'alm-edit-link-thumb' );
		var urlInput = document.getElementById( 'alm-edit-link-url-input' );
		var ignoreLink = document.getElementById( 'alm-edit-link-ignore' );
		var deleteLink = document.getElementById( 'alm-edit-link-delete' );
		var resolvedRow = document.getElementById( 'alm-edit-link-resolved' );
		var resolvedUrlField = document.getElementById( 'alm-edit-link-resolved-url' );
		var useResolvedLink = document.getElementById( 'alm-edit-link-use-resolved' );

		var currentId = null;
		var originalProviderId = null;
		var originalProviderLabel = null;
		var originalUrl = null;
		var matchedProviderId = null;
		var triggerElement = null;
		var matchTimer = null;
		var matchRequestToken = 0;

		var getFocusable = function () {
			// Filters out .hidden elements (e.g. the Ignore link, hidden
			// when a link is already ignored) -- querySelectorAll() would
			// otherwise still return them, letting focus land somewhere
			// invisible on a Tab/Shift+Tab wrap-around.
			return Array.prototype.filter.call( modal.querySelectorAll( 'button, input, a[href]' ), function ( el ) {
				return ! el.hidden;
			} );
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
		 * Affiliate partner is rendered as a colored badge (reusing the
		 * exact .alm-badge/.alm-badge-{provider} classes the Dashboard
		 * and Links table already use for the same providers) instead of
		 * plain text -- ties this modal into the same visual language
		 * rather than inventing a separate one.
		 */
		var renderProviderBadge = function ( id, label ) {
			providerDisplay.textContent = '';
			var badge = document.createElement( 'span' );
			badge.className = 'alm-badge alm-badge-' + id;
			badge.textContent = label;
			providerDisplay.appendChild( badge );
		};

		/**
		 * The thumbnail slot always renders one of three states so the
		 * layout never jumps between links: a shimmer while a first-ever
		 * fetch for this link is in flight, the real photo once found,
		 * or a quiet placeholder icon if none was found (or none exists
		 * yet to know either way isn't a valid fourth state here --
		 * loading always resolves to one of the other two).
		 */
		var setThumbState = function ( state, url ) {
			thumbField.className = 'alm-modal-thumb alm-modal-thumb-' + state;
			thumbField.textContent = '';
			if ( 'image' === state && url ) {
				var img = document.createElement( 'img' );
				img.src = url;
				img.alt = '';
				img.loading = 'lazy';
				img.referrerPolicy = 'no-referrer';
				thumbField.appendChild( img );
			}
		};

		/**
		 * On-demand, cached -- only ever fetched the first time this
		 * particular link's modal is opened (data-thumbnail-fetched
		 * tells openModal() whether that's already happened). currentId
		 * is checked in the response handler in case the admin closed
		 * this modal and opened a different link before the request
		 * resolved, same staleness guard scheduleProviderMatch() already
		 * uses via matchRequestToken.
		 */
		var fetchThumbnail = function ( id, triggerLink ) {
			var body = new FormData();
			body.append( 'action', almAdmin.fetchThumbnailAction );
			body.append( 'nonce', almAdmin.nonce );
			body.append( 'id', id );

			fetch( almAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( id !== currentId ) {
						return;
					}
					var url = ( json.success && json.data.thumbnailUrl ) ? json.data.thumbnailUrl : '';
					setThumbState( url ? 'image' : 'empty', url );
					// Cached on the row's own data attributes so
					// reopening this same link later (without a full
					// page reload) renders instantly, no request.
					triggerLink.setAttribute( 'data-thumbnail-url', url );
					triggerLink.setAttribute( 'data-thumbnail-fetched', '1' );
				} )
				.catch( function () {
					if ( id === currentId ) {
						setThumbState( 'empty' );
					}
				} );
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
						renderProviderBadge( json.data.id, json.data.label );
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

			// The title itself is plain text, not a link -- "Edit Post"
			// and "View" are their own explicit actions right next to it
			// instead (both previously row actions on the Links screen,
			// confusingly split across two different columns/entities;
			// moved here where they actually belong, next to the post
			// they're about).
			postField.textContent = '';
			postField.appendChild( document.createTextNode( link.getAttribute( 'data-post-title' ) ) );

			var postLinks = [
				{ url: link.getAttribute( 'data-post-edit-url' ), label: almAdmin.strings.editPost },
				{ url: link.getAttribute( 'data-view-url' ), label: almAdmin.strings.view },
			];
			var renderedAPostLink = false;
			postLinks.forEach( function ( entry ) {
				if ( ! entry.url ) {
					return;
				}
				postField.appendChild( document.createTextNode( renderedAPostLink ? ' | ' : ' ' ) );
				var postLink = document.createElement( 'a' );
				postLink.href = entry.url;
				postLink.target = '_blank';
				postLink.rel = 'noopener noreferrer';
				postLink.className = 'alm-modal-post-link';
				postLink.textContent = entry.label;
				postField.appendChild( postLink );
				renderedAPostLink = true;
			} );

			// Ignore/Delete: real nonce'd links, same
			// ALM_Links_List_Table::row_action_url() URLs the old row
			// actions used -- only where they're rendered changed.
			ignoreLink.href = link.getAttribute( 'data-ignore-url' );
			ignoreLink.hidden = 'ignored' === link.getAttribute( 'data-status' );
			deleteLink.href = link.getAttribute( 'data-delete-url' );

			// Only present for a shortened link ALM_Shortener_Scanner has
			// already resolved -- "Use this URL" fills the field with it
			// directly rather than making an admin copy/paste it by hand.
			var resolvedUrl = link.getAttribute( 'data-resolved-url' );
			resolvedRow.hidden = ! resolvedUrl;
			if ( resolvedUrl ) {
				resolvedUrlField.textContent = resolvedUrl;
			}

			renderProviderBadge( originalProviderId, originalProviderLabel );
			urlInput.value = originalUrl;

			// Renders instantly from the row's own cached data
			// attributes when already known (every open after the
			// first); otherwise shows the loading state and fetches
			// once, on demand. See ALM_Thumbnail_Fetcher.
			var thumbnailUrl = link.getAttribute( 'data-thumbnail-url' ) || '';
			var thumbnailFetched = '1' === link.getAttribute( 'data-thumbnail-fetched' );
			if ( thumbnailFetched ) {
				setThumbState( thumbnailUrl ? 'image' : 'empty', thumbnailUrl );
			} else {
				setThumbState( 'loading' );
				fetchThumbnail( currentId, link );
			}

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

		// Ignore is a plain nonce'd link (real navigation, same as it
		// always was as a row action) -- no confirm needed, matching its
		// prior behavior exactly. Delete keeps its confirm gate, just as
		// a real click handler now instead of an inline onclick
		// attribute (simpler now that this is JS-rendered, not
		// server-rendered per row).
		deleteLink.addEventListener( 'click', function ( event ) {
			if ( ! window.confirm( almAdmin.strings.deleteConfirm ) ) {
				event.preventDefault();
			}
		} );

		useResolvedLink.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			urlInput.value = resolvedUrlField.textContent;
			scheduleProviderMatch();
			urlInput.focus();
		} );

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
