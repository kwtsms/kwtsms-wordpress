/**
 * kwtSMS: Admin JavaScript
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * Handles:
 *   - CAPTCHA provider field show/hide
 *   - Login button (verify credentials + persist state)
 *   - Logout button (clear verified state)
 *   - Save & Verify Credentials AJAX
 *   - Reload-All button (sender IDs + coverage in one click)
 *   - Send Test SMS AJAX
 *   - SMS template character counter + page count
 *   - API Username phone-number detection warning
 *   - Coverage rendering helper (renderCoverageChips)
 */

/* global kwtSmsAdminData, jQuery */

( function ( $ ) {
	'use strict';

	const data    = window.kwtSmsAdminData || {};
	const ajaxUrl = data.ajaxUrl || '';
	const nonce   = data.nonce   || '';
	const s       = data.strings || {};

	// Track whether credentials have been verified.
	// Initialized from PHP-persisted state so page reload remembers login.
	let credentialsVerified = !! data.credentialsVerified;

	// =========================================================================
	// Enable/disable dependent features based on verified state
	// =========================================================================

	function setDependentFeatures( enabled ) {
		$( '#kwtsms-reload-all, #kwtsms-send-test-sms' ).prop( 'disabled', ! enabled );
	}

	// Apply initial state from PHP.
	setDependentFeatures( credentialsVerified );

	// =========================================================================
	// API Username: warn if it looks like a phone number
	// =========================================================================

	$( '#kwtsms_api_username' ).on( 'blur', function () {
		const $field   = $( this );
		const val      = $field.val().trim();
		const $warning = $field.closest( 'td' ).find( '.kwtsms-username-warning' );

		if ( /^\+?[\d\s()\-]{8,}$/.test( val ) ) {
			if ( ! $warning.length ) {
				$field.after(
					'<p class="kwtsms-username-warning" style="color:#dc3232;margin-top:4px;">' +
					( s.usernameIsPhone || 'This looks like a phone number. API Username should be your kwtSMS account username.' ) +
					'</p>'
				);
			}
		} else {
			$warning.remove();
		}
	} );

	// =========================================================================
	// CAPTCHA field show/hide
	// =========================================================================

	function updateCaptchaFields() {
		const val = $( 'input[name="kwtsms_otp_general[captcha_provider]"]:checked' ).val();
		$( '.kwtsms-recaptcha-fields' ).toggle( val === 'recaptcha' );
		$( '.kwtsms-turnstile-fields' ).toggle( val === 'turnstile' );
	}

	$( 'input[name="kwtsms_otp_general[captcha_provider]"]' ).on( 'change', updateCaptchaFields );
	updateCaptchaFields(); // Initial state.

	// =========================================================================
	// Login button: verify credentials + persist state
	// =========================================================================

	function handleVerifyResponse( resp, $status, $loginStatus ) {
		if ( resp.success ) {
			const d = resp.data;

			// Populate sender ID dropdown.
			const $senderSelect = $( '#kwtsms_sender_id' );
			if ( d.sender_ids && d.sender_ids.length ) {
				const savedSender = $senderSelect.val();
				$senderSelect.empty();
				d.sender_ids.forEach( function ( id ) {
					const selected = id === savedSender ? ' selected' : '';
					$senderSelect.append( $( '<option' + selected + '>' ).val( id ).text( id ) );
				} );
			}

			// Show balance.
			const $balance    = $( '#kwtsms-balance' );
			const $balanceSub = $( '#kwtsms-balance-purchased' );
			if ( d.balance ) {
				$balance.text( parseFloat( d.balance.available ).toFixed( 2 ) );
				if ( d.balance.purchased ) {
					$balanceSub.text( parseFloat( d.balance.purchased ).toFixed( 2 ) );
				}
			}

			// Update verified state + enable dependent features.
			credentialsVerified = true;
			setDependentFeatures( true );

			// Show verified sections and toggle login/logout/reload buttons.
			$( '#kwtsms-verified-sections' ).show();
			$( '#kwtsms-balance-card' ).show();
			$( '.kwtsms-signup-note' ).hide();
			$( '#kwtsms-row-username' ).hide();
			$( '#kwtsms-row-password' ).hide();
			$( '#kwtsms-sender-row' ).show();
			$( '#kwtsms-login-btn' ).hide();
			$( '#kwtsms-logout-btn' ).show();
			$( '#kwtsms-reload-all' ).show();
			$( '.kwtsms-reload-hint' ).show();

			// Render coverage chips if returned.
			if ( d.coverage ) {
				renderCoverageChips( d.coverage, $( '#kwtsms-coverage-result' ) );
			}

			// Update login status span.
			const username = $( '#kwtsms_api_username' ).val().trim();
			const statusMsg = '✓ ' + ( s.connectedAs
				? s.connectedAs.replace( '%s', username )
				: 'Connected as ' + username );
			if ( $loginStatus && $loginStatus.length ) {
				$loginStatus.html( '<span style="color:#46b450;">' + $( '<span>' ).text( statusMsg ).html() + '</span>' );
			}
			if ( $status ) {
				$status.addClass( 'is-success' ).text( s.verified || 'Credentials verified! ✓' ).show();
			}
		} else {
			credentialsVerified = false;
			setDependentFeatures( false );

			// Hide verified sections.
			$( '#kwtsms-verified-sections' ).hide();
			$( '#kwtsms-balance-card' ).hide();
			$( '.kwtsms-signup-note' ).show();
			$( '#kwtsms-row-username' ).show();
			$( '#kwtsms-row-password' ).show();
			$( '#kwtsms-sender-row' ).hide();
			$( '#kwtsms-login-btn' ).show();
			$( '#kwtsms-logout-btn' ).hide();
			$( '#kwtsms-reload-all' ).hide();

			const msg = resp.data && resp.data.message ? resp.data.message : ( s.error || 'Verification failed.' );
			if ( $loginStatus && $loginStatus.length ) {
				$loginStatus.html( '<span style="color:#dc3232;">' + $( '<span>' ).text( msg ).html() + '</span>' );
			}
			if ( $status ) {
				$status.addClass( 'is-error' ).text( msg ).show();
			}
		}
	}

	$( '#kwtsms-login-btn' ).on( 'click', function () {
		const $btn         = $( this );
		const $loginStatus = $( '#kwtsms-login-status' );
		const username     = $( '#kwtsms_api_username' ).val().trim();
		const password     = $( '#kwtsms_api_password' ).val().trim();

		if ( ! username || ! password ) {
			$loginStatus.html(
				'<span style="color:#dc3232;">' +
				( s.credentialsMissing || 'Please enter your API username and password first.' ) +
				'</span>'
			);
			return;
		}

		$btn.prop( 'disabled', true ).text( s.verifying || 'Verifying...' );
		$loginStatus.html( '' );

		$.post( ajaxUrl, {
			action:   'kwtsms_verify_credentials',
			nonce:    nonce,
			username: username,
			password: password,
		} )
		.done( function ( resp ) {
			handleVerifyResponse( resp, null, $loginStatus );
		} )
		.fail( function () {
			$loginStatus.html( '<span style="color:#dc3232;">' + ( s.error || 'Network error.' ) + '</span>' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).text( s.login || 'Login' );
		} );
	} );

	// =========================================================================
	// Save & Verify Credentials (bottom button)
	// =========================================================================

	$( '#kwtsms-verify-btn' ).on( 'click', function () {
		const $btn    = $( this );
		const $status = $( '#kwtsms-api-status' );
		const username = $( '#kwtsms_api_username' ).val().trim();
		const password = $( '#kwtsms_api_password' ).val().trim();

		if ( ! username || ! password ) {
			$status.removeClass( 'is-success' ).addClass( 'is-error' )
				.text( s.credentialsMissing || 'Please enter your API username and password first.' )
				.show();
			return;
		}

		$btn.prop( 'disabled', true ).text( s.verifying || 'Verifying...' );
		$status.hide().removeClass( 'is-success is-error' );

		$.post( ajaxUrl, {
			action:   'kwtsms_verify_credentials',
			nonce:    nonce,
			username: username,
			password: password,
		} )
		.done( function ( resp ) {
			handleVerifyResponse( resp, $status, $( '#kwtsms-login-status' ) );
		} )
		.fail( function () {
			$status.addClass( 'is-error' ).text( s.error || 'Network error. Please try again.' ).show();
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).text( s.saveAndVerify || 'Save & Verify Credentials' );
		} );
	} );

	// =========================================================================
	// Reload-All button (sender IDs + coverage in one click)
	// =========================================================================

	$( '#kwtsms-reload-all' ).on( 'click', function () {
		const $btn = $( this );

		$btn.prop( 'disabled', true ).text( '↻ ' + ( s.reloading || 'Reloading...' ) );

		// Use the dedicated reload action: it reads stored credentials from the
		// database so the password never needs to be present in the DOM.
		$.post( ajaxUrl, {
			action: 'kwtsms_reload_all',
			nonce:  nonce,
		} )
		.done( function ( resp ) {
			handleVerifyResponse( resp, null, $( '#kwtsms-login-status' ) );
		} )
		.fail( function () {
			$( '#kwtsms-login-status' ).html( '<span style="color:#dc3232;">' + ( s.error || 'Network error.' ) + '</span>' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).text( '↻ ' + ( s.reload || 'Reload' ) );
		} );
	} );

	// =========================================================================
	// Send Test SMS
	// =========================================================================

	$( '#kwtsms-send-test-sms' ).on( 'click', function () {
		const $btn    = $( this );
		const $result = $( '#kwtsms-test-sms-result' );
		const $field  = $( '#kwtsms_test_phone' );
		let   phone   = $field.val().trim();
		const username = $( '#kwtsms_api_username' ).val().trim();
		const password = $( '#kwtsms_api_password' ).val().trim();

		// When already connected, the password is not in the DOM: skip this check.
		if ( ! credentialsVerified && ( ! username || ! password ) ) {
			$result.text( s.credentialsMissing || 'Please save your API credentials first (Gateway Settings → Save Settings).' ).css( 'color', '#dc3232' );
			return;
		}

		if ( ! phone ) {
			$result.text( s.testPhoneMissing || 'Please enter a test phone number first.' ).css( 'color', '#dc3232' );
			return;
		}

		// Auto-prefix short numbers (local format without country code).
		const digitsOnly = phone.replace( /^\+/, '' ).replace( /^00/, '' ).replace( /\D/g, '' );
		if ( digitsOnly.length <= 8 && digitsOnly.length >= 5 ) {
			const dial = data.defaultDialCode || '965';
			phone = dial + digitsOnly;
			$field.val( phone ); // update the field so user sees the full number
		}

		// Reject numbers that are too short: must have country code + local number (min 10 digits).
		const digitsNorm = phone.replace( /^\+/, '' ).replace( /^00/, '' ).replace( /\D/g, '' );
		if ( digitsNorm.length < 10 ) {
			$result.text( s.phoneTooShort || 'Number is too short. Enter the country code followed by the full local number, e.g. 96512345678 (Kuwait: 965 + 8 digits).' ).css( 'color', '#dc3232' );
			return;
		}

		$btn.prop( 'disabled', true ).text( s.sending || 'Sending...' );
		$result.text( '' );

		$.post( ajaxUrl, {
			action:  'kwtsms_send_test_sms',
			nonce:   nonce,
			phone:   phone,
		} )
		.done( function ( resp ) {
			if ( resp.success ) {
				const d   = resp.data || {};
				const msg = d.test_mode
					? ( s.testModeResult || 'Test mode ON: message queued, not delivered.' )
					: ( s.testSmsResult || 'SMS sent to %phone%. Check your messages.' ).replace( '%phone%', d.phone || '' );
				$result.text( msg ).css( 'color', '#46b450' );
				// Update balance display if server returned an updated balance.
				if ( d.balance && d.balance.available !== null && d.balance.available !== undefined ) {
					$( '#kwtsms-balance' ).text( parseFloat( d.balance.available ).toFixed( 2 ) );
					if ( d.balance.purchased !== null && d.balance.purchased !== undefined ) {
						$( '#kwtsms-balance-purchased' ).text( parseFloat( d.balance.purchased ).toFixed( 2 ) );
					}
				}
			} else {
				const msg = resp.data && resp.data.message
					? resp.data.message
					: ( s.testSmsFailed || 'Send failed. Check your API credentials and phone number.' );
				$result.text( msg ).css( 'color', '#dc3232' );
			}
		} )
		.fail( function () {
			$result.text( s.testSmsFailed || 'Network error. Please try again.' ).css( 'color', '#dc3232' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).text( s.sendTestSms || 'Send Test SMS' );
		} );
	} );

	// =========================================================================
	// SMS character counter + page count
	// =========================================================================

	function estimateText( text ) {
		var est = data.placeholder_estimates || {};
		var result = text;
		Object.keys( est ).forEach( function ( ph ) {
			result = result.split( ph ).join( est[ ph ] );
		} );
		return result;
	}

	function updateCharCounter( $textarea ) {
		const text      = $textarea.val();
		const estimated = estimateText( text );
		const lang      = $textarea.data( 'lang' ) || 'en';
		const rawLen    = text.length;
		const estLen    = estimated.length;

		// GSM-7 encoding limits: 160 single-page, 153 per page multi.
		// Arabic (Unicode): 70 single-page, 67 per page multi.
		const singleLimit = ( lang === 'ar' ) ? 70  : 160;
		const multiLimit  = ( lang === 'ar' ) ? 67  : 153;
		const pages       = estLen <= singleLimit ? 1 : Math.ceil( estLen / multiLimit );

		const $counter = $textarea.closest( '.kwtsms-textarea-wrap' ).find( '.kwtsms-char-counter' );
		$counter.find( '.kwtsms-char-count' ).text( rawLen + ' (' + estLen + ' est.)' );
		$counter.find( '.kwtsms-page-count' ).text( pages );
		$counter.toggleClass( 'is-warning', pages > 1 );
	}

	$( '.kwtsms-sms-textarea' ).each( function () {
		updateCharCounter( $( this ) );
	} );

	$( document ).on( 'input', '.kwtsms-sms-textarea', function () {
		updateCharCounter( $( this ) );
	} );

	// =========================================================================
	// Reset template to default
	// =========================================================================

	$( document ).on( 'click', '.kwtsms-reset-template', function () {
		var key      = $( this ).data( 'key' );
		var defaults = ( data.template_defaults || {} )[ key ];
		if ( ! defaults ) {
			return;
		}
		var $card = $( this ).closest( '.kwtsms-template-card' );
		$card.find( 'textarea[data-lang="en"]' ).val( defaults.en ).trigger( 'input' );
		$card.find( 'textarea[data-lang="ar"]' ).val( defaults.ar ).trigger( 'input' );
	} );

	// =========================================================================
	// Language tab switching (EN / AR)
	// =========================================================================

	$( document ).on( 'click', '.kwtsms-tab-btn', function () {
		var $btn  = $( this );
		var tab   = $btn.data( 'tab' );
		var $tabs = $btn.closest( '.kwtsms-lang-tabs' );
		$tabs.find( '.kwtsms-tab-btn' ).removeClass( 'is-active' );
		$btn.addClass( 'is-active' );
		$tabs.find( '.kwtsms-tab-pane' ).hide();
		$tabs.find( '.kwtsms-tab-pane[data-tab="' + tab + '"]' ).show();
		// Trigger counter update for the newly visible textarea
		$tabs.find( '.kwtsms-tab-pane[data-tab="' + tab + '"] .kwtsms-sms-textarea' ).each( function () {
			updateCharCounter( $( this ) );
		} );
	} );

	// =========================================================================
	// Coverage rendering helper (used by Reload-All and page load)
	// =========================================================================

	function renderCoverageChips( coverage, $result ) {
		var API_CODES = [ 'OK', 'ERROR', 'ERR', 'FAIL', 'FAILED', 'NULL', 'NONE', 'N/A', 'NA', 'TRUE', 'FALSE' ];
		var html = '';
		if ( Array.isArray( coverage ) ) {
			coverage.forEach( function ( row ) {
				var name = '', dial = '';
				if ( typeof row === 'object' && row !== null ) {
					name = row.name || row.country || row.countryName || row.CountryName || '';
					if ( ! name ) {
						name = Object.values( row ).find( function( v ) { return typeof v === 'string'; } ) || '';
					}
					dial = row.dial || '';
				} else {
					name = String( row );
				}
				if ( ! name ) return;
				// Skip API status code strings and bare dial-code digit strings.
				if ( API_CODES.indexOf( name.toUpperCase() ) !== -1 ) return;
				if ( /^\d+$/.test( name ) ) return;
				var label = dial ? name + ' (+' + dial + ')' : name;
				html += '<span class="kwtsms-tag-chip">' + $( '<span>' ).text( label ).html() + '</span>';
			} );
		} else if ( coverage && typeof coverage === 'object' ) {
			Object.keys( coverage ).forEach( function ( key ) {
				if ( API_CODES.indexOf( String( key ).toUpperCase() ) === -1 ) {
					html += '<span class="kwtsms-tag-chip">' + $( '<span>' ).text( key ).html() + '</span>';
				}
			} );
		}
		$result.html( html );
	}

	// =========================================================================
	// Logout button
	// =========================================================================

	$( '#kwtsms-logout-btn' ).on( 'click', function () {
		credentialsVerified = false;

		// Toggle buttons.
		$( '#kwtsms-login-btn' ).show();
		$( '#kwtsms-logout-btn' ).hide();

		// Hide verified sections.
		$( '#kwtsms-verified-sections' ).hide();

		// Clear login status.
		$( '#kwtsms-login-status' ).html( '' );

		// Hide balance bar and show signup note.
		$( '#kwtsms-balance-card' ).hide();
		$( '#kwtsms-balance' ).text( '-' );
		$( '#kwtsms-balance-purchased' ).text( '' );
		$( '.kwtsms-signup-note' ).show();
		$( '#kwtsms-row-username' ).show();
		$( '#kwtsms-row-password' ).show();
		$( '#kwtsms-sender-row' ).hide();
		$( '#kwtsms-reload-all' ).hide();
		$( '.kwtsms-reload-hint' ).hide();

		// Server-side: clear credentials_verified flag.
		$.post( ajaxUrl, {
			action: 'kwtsms_logout_gateway',
			nonce:  nonce,
		} );
	} );

	// =========================================================================
	// On page load: render saved coverage chips
	// =========================================================================

	( function () {
		const savedCov = data.savedCoverage || [];
		const $result  = $( '#kwtsms-coverage-result' );
		// Only populate if PHP did not pre-render chips.
		if ( savedCov.length && ! $result.children().length && ! $result.text().trim() ) {
			renderCoverageChips( savedCov, $result );
		}
	}() );

	// Balance and login/logout button state on page load are handled server-side
	// by page-gateway.php. setDependentFeatures( credentialsVerified ) above
	// handles button enable/disable state on init, so no JS restore is needed.

	// =========================================================================
	// Integrations page: tab switching
	// All tab content is always in the DOM (single form); we show/hide via JS.
	// =========================================================================

	( function () {
		var tabLinks    = document.querySelectorAll( '.kwtsms-int-tab-link' );
		var tabContents = document.querySelectorAll( '.kwtsms-int-tab-content' );

		if ( ! tabLinks.length ) {
			return;
		}

		tabLinks.forEach( function ( link ) {
			link.addEventListener( 'click', function ( e ) {
				e.preventDefault();

				var target = this.getAttribute( 'href' );

				// Deactivate all tabs.
				tabLinks.forEach( function ( l ) {
					l.classList.remove( 'nav-tab-active' );
				} );

				// Hide all tab panels.
				tabContents.forEach( function ( c ) {
					c.style.display = 'none';
				} );

				// Activate the clicked tab and show its panel.
				this.classList.add( 'nav-tab-active' );

				var panel = document.querySelector( target );
				if ( panel ) {
					panel.style.display = 'block';
				}
			} );
		} );
	}() );

	// =========================================================================
	// Unsaved changes: custom branded modal
	//
	// For in-page link navigation: intercepts the click and shows our own
	// dialog so we control the title, body, and button labels (and they are
	// fully translatable via kwtSmsAdminData.strings).
	//
	// For browser refresh (F5), back/forward, and tab-close: a beforeunload
	// listener is registered so the browser shows its own "Leave page?" dialog.
	// Modern browsers ignore any custom message text and always display their
	// own generic prompt; the two dialogs cannot look identical, but both
	// warn the user before discarding their changes.
	// =========================================================================

	( function () {
		var $forms = $( '.kwtsms-admin-wrap form[action="options.php"]' );

		// After a "Save Changes" from the unsaved-changes modal, WordPress saves and
		// redirects back here with ?settings-updated. Forward to the original destination.
		// A timestamp guards against stale entries surviving across unrelated page loads:
		// only consume the redirect if it was set within the last 60 seconds.
		var _redir = sessionStorage.getItem( 'kwtsmsRedirectAfterSave' );
		var _redirTs = parseInt( sessionStorage.getItem( 'kwtsmsRedirectAfterSaveTs' ) || '0', 10 );
		sessionStorage.removeItem( 'kwtsmsRedirectAfterSave' );
		sessionStorage.removeItem( 'kwtsmsRedirectAfterSaveTs' );
		if ( _redir && ( Date.now() - _redirTs ) < 60000 ) {
			window.location.href = _redir;
			return;
		}

		if ( ! $forms.length ) {
			return;
		}

		var dirty       = false;
		var pendingHref = null;

		// ── Build the modal ────────────────────────────────────────────────
		var $overlay = $(
			'<div id="kwtsms-unsaved-overlay" role="dialog" aria-modal="true" aria-labelledby="kwtsms-unsaved-title">' +
				'<div id="kwtsms-unsaved-dialog">' +
					'<h2 id="kwtsms-unsaved-title"></h2>' +
					'<p  id="kwtsms-unsaved-body"></p>' +
					'<div id="kwtsms-unsaved-actions">' +
						'<button type="button" id="kwtsms-unsaved-save"  class="button kwtsms-save-btn"></button>' +
						'<button type="button" id="kwtsms-unsaved-leave" class="button"></button>' +
					'</div>' +
				'</div>' +
			'</div>'
		).appendTo( 'body' );

		$overlay.find( '#kwtsms-unsaved-title' ).text( s.unsavedTitle || 'Unsaved Changes'                                             );
		$overlay.find( '#kwtsms-unsaved-body'  ).text( s.unsavedBody  || 'You have unsaved changes. Leaving this page will discard them.' );
		$overlay.find( '#kwtsms-unsaved-save'  ).text( s.unsavedSave  || 'Save Changes'                                                );
		$overlay.find( '#kwtsms-unsaved-leave' ).text( s.unsavedLeave || 'Leave Page'                                                  );

		function showModal( href ) {
			pendingHref = href || null;
			$overlay.addClass( 'is-visible' );
			$( '#kwtsms-unsaved-save' ).trigger( 'focus' );
		}

		function hideModal() {
			pendingHref = null;
			$overlay.removeClass( 'is-visible' );
		}

		// "Save Changes": submit the form, then navigate to the pending destination.
		// pendingHref is stored in sessionStorage so it survives the page reload
		// caused by the WordPress settings save redirect.
		//
		// NOTE: WordPress Settings API renders the Save button as <input name="submit">,
		// which shadows form.submit with a reference to that input element.
		// Use HTMLFormElement.prototype.submit.call() to bypass the override.
		$( '#kwtsms-unsaved-save' ).on( 'click', function () {
			var href = pendingHref;
			if ( href ) {
				sessionStorage.setItem( 'kwtsmsRedirectAfterSave', href );
				sessionStorage.setItem( 'kwtsmsRedirectAfterSaveTs', String( Date.now() ) );
			}
			dirty = false;
			HTMLFormElement.prototype.submit.call( $forms.first()[ 0 ] );
		} );

		// Clicking the backdrop also stays (do not navigate).
		$overlay.on( 'click', function ( e ) {
			if ( e.target === this ) { hideModal(); }
		} );

		// Esc key: stay.
		$( document ).on( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && $overlay.hasClass( 'is-visible' ) ) { hideModal(); }
		} );

		// "Leave Page": proceed with stored navigation target.
		$( '#kwtsms-unsaved-leave' ).on( 'click', function () {
			dirty = false;
			var href = pendingHref;
			hideModal();
			if ( href ) { window.location.href = href; }
		} );

		// ── Dirty tracking ─────────────────────────────────────────────────
		$forms.on( 'input change', 'input, select, textarea', function () {
			dirty = true;
		} );

		// Clear dirty on save (form submit).
		$forms.on( 'submit', function () {
			dirty = false;
		} );

		// ── beforeunload safety net (refresh / back / tab-close) ──────────
		// Modern browsers show their own generic "Leave page?" dialog and
		// ignore the custom message text, but the message is preserved here
		// for any browser that still honours it.
		window.addEventListener( 'beforeunload', function ( e ) {
			if ( ! dirty ) { return; }
			var msg = ( s.unsavedBody || 'You have unsaved changes. Leaving this page will discard them.' );
			e.preventDefault();
			e.returnValue = msg; // legacy browsers
			return msg;          // Safari
		} );

		// ── Intercept link clicks for in-page navigation ───────────────────
		$( document ).on( 'click', 'a', function ( e ) {
			if ( ! dirty ) { return; }

			var rawHref = $( this ).attr( 'href' ) || '';
			// Skip: empty, same-page anchors, javascript: links, new-tab links.
			if (
				! rawHref ||
				rawHref === '#' ||
				rawHref.indexOf( 'javascript:' ) === 0 ||
				$( this ).attr( 'target' ) === '_blank'
			) {
				return;
			}

			e.preventDefault();
			e.stopPropagation();
			showModal( this.href );
		} );

		}() );

	// Note: third-party admin notices (e.g. Action Scheduler) are kept above
	// the logo header via CSS flex `order: -1` on .kwtsms-admin-wrap > .notice.

} )( jQuery );
