/**
 * form-otp.js — OTP Verification Gate for CF7, WPForms, and Elementor Pro forms.
 *
 * When any integration is configured in "OTP Gate" mode, this script intercepts
 * the form submit event and forces the visitor to verify their phone number via
 * an OTP code before the form is allowed to submit.
 *
 * Flow:
 *   1. User clicks Submit on a form containing a kwtsms_phone / phone field.
 *   2. If no verified token is present, the submit is cancelled and a modal appears.
 *   3. Visitor enters phone → clicks "Send Code" → AJAX sends OTP and returns token.
 *   4. Visitor enters 6-digit code → clicks "Verify" → AJAX checks code.
 *   5. On success, a hidden input `kwtsms_form_verified_token` is added and the
 *      form is programmatically re-submitted.
 *   6. Server-side gate hooks verify the token before processing the form.
 *
 * Localised data: window.kwtSmsFormData.ajaxUrl, .nonce, .strings
 *
 * @package KwtSMS_OTP
 */
( function ( $ ) {
	'use strict';

	/** Resolved i18n strings, falling back to sensible English defaults. */
	var s = ( window.kwtSmsFormData && window.kwtSmsFormData.strings ) ? window.kwtSmsFormData.strings : {};
	var ajaxUrl = ( window.kwtSmsFormData && window.kwtSmsFormData.ajaxUrl ) ? window.kwtSmsFormData.ajaxUrl : '';
	var nonce     = ( window.kwtSmsFormData && window.kwtSmsFormData.nonce )     ? window.kwtSmsFormData.nonce     : '';
	var gateNonce = ( window.kwtSmsFormData && window.kwtSmsFormData.gateNonce ) ? window.kwtSmsFormData.gateNonce : '';

	/** Currently active token, populated after send-OTP succeeds. */
	var activeToken = '';

	/** The form element waiting for verification. */
	var pendingForm = null;

	// =========================================================================
	// Modal HTML
	// =========================================================================

	/**
	 * Build and inject the OTP modal overlay into the page body.
	 * Called once on DOMReady; the modal starts hidden.
	 */
	function buildModal() {
		var html =
			'<div id="kwtsms-otp-overlay" style="display:none;" aria-modal="true" role="dialog" aria-labelledby="kwtsms-otp-modal-title">' +
				'<div id="kwtsms-otp-modal">' +
					'<h2 id="kwtsms-otp-modal-title">' + escHtml( s.modalTitle || 'Phone Verification Required' ) + '</h2>' +

					/* Step 1: phone entry */
					'<div id="kwtsms-step-phone">' +
						'<p>' + escHtml( s.enterPhone || 'Enter your phone number to verify' ) + '</p>' +
						// 2026-11-05 11:21 +03: fallback placeholder 965XXXXXXXX replaces a real person's number, int-testnumber.
						'<input type="tel" id="kwtsms-phone-input" placeholder="' + escAttr( s.phonePlaceholder || 'e.g. 965XXXXXXXX' ) + '" autocomplete="tel" maxlength="15" />' +
						'<div id="kwtsms-phone-error" class="kwtsms-otp-error" role="alert"></div>' +
						'<div class="kwtsms-otp-actions">' +
							'<button type="button" id="kwtsms-send-btn">' + escHtml( s.sendCode || 'Send Code' ) + '</button>' +
							'<button type="button" id="kwtsms-cancel-btn" class="kwtsms-otp-secondary">' + escHtml( s.close || 'Cancel' ) + '</button>' +
						'</div>' +
					'</div>' +

					/* Step 2: code entry (hidden until OTP is sent) */
					'<div id="kwtsms-step-code" style="display:none;">' +
						'<p id="kwtsms-sent-msg"></p>' +
						'<input type="text" id="kwtsms-code-input" maxlength="6" inputmode="numeric" pattern="[0-9]{4,6}" placeholder="' + escAttr( s.codePlaceholder || '6-digit code' ) + '" autocomplete="one-time-code" />' +
						'<div id="kwtsms-code-error" class="kwtsms-otp-error" role="alert"></div>' +
						'<div class="kwtsms-otp-actions">' +
							'<button type="button" id="kwtsms-verify-btn">' + escHtml( s.verifyCode || 'Verify' ) + '</button>' +
							'<button type="button" id="kwtsms-resend-btn" class="kwtsms-otp-secondary">' + escHtml( s.resend || 'Resend Code' ) + '</button>' +
						'</div>' +
					'</div>' +

					/* Step 3: verified confirmation (hidden until code passes) */
					'<div id="kwtsms-step-verified" style="display:none;">' +
						'<p class="kwtsms-otp-success">' + escHtml( s.verifiedMsg || 'Your phone has been verified. Submitting form...' ) + '</p>' +
					'</div>' +

				'</div>' +
			'</div>';

		$( 'body' ).append( html );
		injectModalStyles();
		bindModalEvents();
	}

	/**
	 * Inject minimal inline CSS for the modal.
	 * This keeps the JS self-contained without requiring an extra HTTP request.
	 */
	function injectModalStyles() {
		var css =
			'#kwtsms-otp-overlay{' +
				'position:fixed;top:0;left:0;width:100%;height:100%;' +
				'background:rgba(0,0,0,.55);z-index:99999;' +
				'display:flex;align-items:center;justify-content:center;' +
			'}' +
			'#kwtsms-otp-modal{' +
				'background:#fff;border-radius:6px;padding:28px 32px;' +
				'max-width:420px;width:92%;box-shadow:0 8px 32px rgba(0,0,0,.22);' +
				'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;' +
			'}' +
			'#kwtsms-otp-modal h2{margin:0 0 16px;font-size:18px;color:#434345;}' +
			'#kwtsms-otp-modal p{margin:0 0 12px;color:#434345;font-size:14px;}' +
			'#kwtsms-otp-modal input[type="tel"],' +
			'#kwtsms-otp-modal input[type="text"]{' +
				'width:100%;box-sizing:border-box;padding:10px 12px;' +
				'border:1px solid #ccc;border-radius:0;font-size:15px;' +
				'margin-bottom:8px;' +
			'}' +
			'#kwtsms-otp-modal input:focus{outline:2px solid #FFA200;border-color:#FFA200;}' +
			'.kwtsms-otp-actions{display:flex;gap:10px;margin-top:14px;}' +
			'.kwtsms-otp-actions button{' +
				'flex:1;padding:10px 0;border:none;border-radius:4px;' +
				'font-size:14px;cursor:pointer;font-weight:600;' +
			'}' +
			'#kwtsms-send-btn,#kwtsms-verify-btn{background:#FFA200;color:#fff;}' +
			'#kwtsms-send-btn:hover,#kwtsms-verify-btn:hover{background:#e69100;}' +
			'#kwtsms-send-btn:disabled,#kwtsms-verify-btn:disabled{opacity:.6;cursor:not-allowed;}' +
			'.kwtsms-otp-secondary{background:#f0f0f0;color:#434345;}' +
			'.kwtsms-otp-secondary:hover{background:#ddd;}' +
			'.kwtsms-otp-error{color:#dc3232;font-size:13px;min-height:18px;margin-bottom:4px;}' +
			'.kwtsms-otp-success{color:#46b450;font-weight:600;}';

		$( '<style id="kwtsms-otp-styles">' ).text( css ).appendTo( 'head' );
	}

	// =========================================================================
	// Modal state helpers
	// =========================================================================

	function showModal() {
		$( '#kwtsms-otp-overlay' ).show();
		$( '#kwtsms-phone-input' ).focus();
	}

	function hideModal() {
		$( '#kwtsms-otp-overlay' ).hide();
		resetModal();
	}

	function resetModal() {
		$( '#kwtsms-step-phone' ).show();
		$( '#kwtsms-step-code' ).hide();
		$( '#kwtsms-step-verified' ).hide();
		$( '#kwtsms-phone-input' ).val( '' );
		$( '#kwtsms-code-input' ).val( '' );
		$( '#kwtsms-phone-error' ).text( '' );
		$( '#kwtsms-code-error' ).text( '' );
		activeToken  = '';
		pendingForm  = null;
	}

	function showPhoneError( msg ) {
		$( '#kwtsms-phone-error' ).text( msg );
	}

	function showCodeError( msg ) {
		$( '#kwtsms-code-error' ).text( msg );
	}

	// =========================================================================
	// Modal event bindings
	// =========================================================================

	function bindModalEvents() {
		// Cancel / backdrop close.
		$( document ).on( 'click', '#kwtsms-cancel-btn', hideModal );
		$( document ).on( 'click', '#kwtsms-otp-overlay', function ( e ) {
			if ( $( e.target ).is( '#kwtsms-otp-overlay' ) ) {
				hideModal();
			}
		} );

		// Escape key closes modal.
		$( document ).on( 'keydown', function ( e ) {
			if ( 27 === e.which && $( '#kwtsms-otp-overlay' ).is( ':visible' ) ) {
				hideModal();
			}
		} );

		// Send OTP button.
		$( document ).on( 'click', '#kwtsms-send-btn', function () {
			doSendOtp();
		} );

		// Allow Enter key in phone field.
		$( document ).on( 'keydown', '#kwtsms-phone-input', function ( e ) {
			if ( 13 === e.which ) {
				e.preventDefault();
				doSendOtp();
			}
		} );

		// Verify button.
		$( document ).on( 'click', '#kwtsms-verify-btn', function () {
			doVerifyOtp();
		} );

		// Allow Enter key in code field.
		$( document ).on( 'keydown', '#kwtsms-code-input', function ( e ) {
			if ( 13 === e.which ) {
				e.preventDefault();
				doVerifyOtp();
			}
		} );

		// Resend button — go back to phone step.
		$( document ).on( 'click', '#kwtsms-resend-btn', function () {
			$( '#kwtsms-step-code' ).hide();
			$( '#kwtsms-step-phone' ).show();
			$( '#kwtsms-phone-input' ).focus();
			activeToken = '';
		} );
	}

	// =========================================================================
	// AJAX: send OTP
	// =========================================================================

	function doSendOtp() {
		var rawPhone = $.trim( $( '#kwtsms-phone-input' ).val() );
				// Auto-prepend default dial code for local (short) numbers.
				var digitsOnly = rawPhone.replace( /^\+/, '' ).replace( /^00/, '' ).replace( /\D/g, '' );
				var phone = ( digitsOnly.length >= 5 && digitsOnly.length <= 8 )
					? ( ( kwtSmsFormData.defaultDialCode || '965' ) + digitsOnly )
					: rawPhone;
				$( '#kwtsms-phone-input' ).val( phone ); // show normalized value to user
		showPhoneError( '' );

		if ( ! phone ) {
			showPhoneError( s.enterPhone || 'Please enter your phone number.' );
			return;
		}

		var $btn = $( '#kwtsms-send-btn' );
		$btn.prop( 'disabled', true ).text( s.sending || 'Sending...' );

		$.ajax( {
			url:    ajaxUrl,
			type:   'POST',
			data:   {
				action:  'kwtsms_form_send_otp',
				nonce:   nonce,
				phone:   phone,
				form_id: ( pendingForm && pendingForm.id ) ? pendingForm.id : ( pendingForm && $( pendingForm ).data( 'form-id' ) ) || 0,
			},
			success: function ( res ) {
				if ( res.success ) {
					activeToken = res.data.token;
					$( '#kwtsms-step-phone' ).hide();
					$( '#kwtsms-sent-msg' ).text( ( s.enterCode || 'Enter the code sent to your phone' ) );
					$( '#kwtsms-step-code' ).show();
					$( '#kwtsms-code-input' ).focus();
				} else {
					showPhoneError( ( res.data && res.data.message ) || 'Error sending code.' );
				}
			},
			error: function () {
				showPhoneError( 'Network error. Please try again.' );
			},
			complete: function () {
				$btn.prop( 'disabled', false ).text( s.sendCode || 'Send Code' );
			},
		} );
	}

	// =========================================================================
	// AJAX: verify OTP
	// =========================================================================

	function doVerifyOtp() {
		var code = $.trim( $( '#kwtsms-code-input' ).val() );
		showCodeError( '' );

		if ( ! code ) {
			showCodeError( 'Please enter the verification code.' );
			return;
		}

		var $btn = $( '#kwtsms-verify-btn' );
		$btn.prop( 'disabled', true ).text( s.verifying || 'Verifying...' );

		$.ajax( {
			url:    ajaxUrl,
			type:   'POST',
			data:   {
				action: 'kwtsms_form_verify_otp',
				nonce:  nonce,
				token:  activeToken,
				code:   code,
			},
			success: function ( res ) {
				if ( res.success ) {
					$( '#kwtsms-step-code' ).hide();
					$( '#kwtsms-step-verified' ).show();

					// Inject the verified token into the pending form and re-submit.
					if ( pendingForm ) {
						// Remove any old token/nonce inputs first.
						$( pendingForm ).find( 'input[name="kwtsms_form_verified_token"]' ).remove();
						$( pendingForm ).find( 'input[name="_kwtsms_gate_nonce"]' ).remove();
						$( '<input>' )
							.attr( { type: 'hidden', name: 'kwtsms_form_verified_token' } )
							.val( activeToken )
							.appendTo( pendingForm );
						$( '<input>' )
							.attr( { type: 'hidden', name: '_kwtsms_gate_nonce' } )
							.val( gateNonce )
							.appendTo( pendingForm );

						// Short delay so user sees the success message.
						// Capture pendingForm before hideModal() clears it via resetModal().
						var formToSubmit = pendingForm;
						setTimeout( function () {
							hideModal();
							$( formToSubmit ).data( 'kwtsms-verified', true );
							$( formToSubmit ).trigger( 'submit' );
						}, 900 );
					} else {
						setTimeout( hideModal, 1200 );
					}
				} else {
					showCodeError( ( res.data && res.data.message ) || 'Incorrect code.' );
				}
			},
			error: function () {
				showCodeError( 'Network error. Please try again.' );
			},
			complete: function () {
				$btn.prop( 'disabled', false ).text( s.verifyCode || 'Verify' );
			},
		} );
	}

	// =========================================================================
	// Form submit interception
	// =========================================================================

	/**
	 * Returns true if the form contains a phone-like input that should be
	 * used as the verification target. We look for:
	 *   - [name="kwtsms_phone"]                  (CF7 convention)
	 *   - input[type="tel"]                       (generic)
	 *   - input whose name contains "phone"       (WPForms / Elementor)
	 *
	 * @param  {HTMLFormElement} form
	 * @return {boolean}
	 */
	function formHasPhoneField( form ) {
		var $form = $( form );
		if (
			$form.find( '[name="kwtsms_phone"]' ).length > 0 ||
			$form.find( 'input[type="tel"]' ).length > 0 ||
			$form.find( 'input[name*="phone"]' ).length > 0
		) {
			return true;
		}
		// WPForms names inputs as wpforms[fields][N] — detect phone via label text.
		var hasPhoneLabel = false;
		$form.find( 'label' ).each( function () {
			if ( /phone/i.test( $( this ).text() ) ) {
				hasPhoneLabel = true;
				return false; // break each
			}
		} );
		return hasPhoneLabel;
	}

	/**
	 * Intercept submit only for known integration form wrappers.
	 * Scoping to .wpcf7-form, .wpforms-form, and .elementor-form prevents
	 * the modal from hijacking login forms, checkout forms, and other
	 * non-integration forms that happen to contain a phone field.
	 * Uses event delegation on document so dynamically-rendered forms (e.g.
	 * Elementor AJAX-loaded widgets) are also caught.
	 */
	$( document ).on( 'submit', '.wpcf7-form, .wpforms-form, .elementor-form', function ( e ) {
		var form = this;
		var $form = $( form );

		// Skip if already verified in this page load.
		if ( $form.data( 'kwtsms-verified' ) ) {
			return true;
		}

		// Skip if the form already carries a verified token (server set it).
		if ( $form.find( 'input[name="kwtsms_form_verified_token"]' ).length > 0 ) {
			return true;
		}

		// Only intercept forms that look like they have a phone field.
		if ( ! formHasPhoneField( form ) ) {
			return true;
		}

		e.preventDefault();
		e.stopImmediatePropagation();

		pendingForm = form;
		showModal();

		// Pre-fill phone field in modal if the form contains one.
		var $phoneField = $form.find( '[name="kwtsms_phone"], input[type="tel"], input[name*="phone"]' ).first();
		if ( $phoneField.length && $phoneField.val() ) {
			$( '#kwtsms-phone-input' ).val( $phoneField.val() );
		}

		return false;
	} );

	// =========================================================================
	// Utility
	// =========================================================================

	function escHtml( str ) {
		return $( '<div>' ).text( str ).html();
	}

	function escAttr( str ) {
		return $( '<div>' ).text( str ).html()
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	// =========================================================================
	// Init
	// =========================================================================

	$( function () {
		buildModal();
	} );

} )( jQuery );
