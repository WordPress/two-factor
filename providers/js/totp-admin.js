/* global twoFactorTotpAdmin, qrcode, jQuery */
( function( $ ) {
	var generateQrCode = function( totpUrl ) {
		var $qrLink = $( '#two-factor-qr-code a' ),
			qr,
			svg,
			title;

		if ( ! $qrLink.length || typeof qrcode === 'undefined' ) {
			return;
		}

		qr = qrcode( 0, 'L' );

		qr.addData( totpUrl );
		qr.make();
		$qrLink.html( qr.createSvgTag( 5 ) );

		svg = $qrLink.find( 'svg' )[ 0 ];
		if ( svg ) {
			var ariaLabel =
				typeof twoFactorTotpAdmin !== 'undefined' &&
				twoFactorTotpAdmin &&
				twoFactorTotpAdmin.qrCodeAriaLabel
					? twoFactorTotpAdmin.qrCodeAriaLabel
					: 'Authenticator App QR Code';
			title = document.createElement( 'title' );
			svg.setAttribute( 'role', 'img' );
			svg.setAttribute( 'aria-label', ariaLabel );
			title.innerText = ariaLabel;
			svg.appendChild( title );
		}
	};

	var $options = $( '#two-factor-totp-options' ),
		checkbox = document.getElementById( 'enabled-Two_Factor_Totp' ),
		// Markup shown before setup started, restored when the user cancels.
		idleHtml = '';

	var showError = function( response, status, $after ) {
		var errorMessage =
				( response &&
					response.responseJSON &&
					response.responseJSON.message ) ||
				( response && response.statusText ) ||
				status ||
				'',
			$error = $( '#totp-setup-error' );

		if ( ! $error.length ) {
			$error = $(
				'<div class="error" id="totp-setup-error"><p></p></div>'
			).insertAfter( $after );
		}

		$error.find( 'p' ).text( errorMessage );

		$( '#enabled-Two_Factor_Totp' )
			.prop( 'checked', false )
			.trigger( 'change' );
	};

	// Focus the auth code input when the checkbox is clicked, or start setup if it is not open yet.
	if ( checkbox ) {
		checkbox.addEventListener( 'click', function( e ) {
			var authcode;

			if ( ! e.target.checked ) {
				return;
			}

			authcode = document.getElementById( 'two-factor-totp-authcode' );

			if ( authcode ) {
				authcode.focus();
			} else {
				$options.find( '.setup-totp' ).trigger( 'click' );
			}
		} );
	}

	// The secret and QR code are only requested once the user starts setup.
	$options.on(
		'click',
		'.setup-totp',
		function( e ) {
			var $button = $( this );

			e.preventDefault();

			// Drop an error from an earlier attempt so Cancel does not bring it back,
			// and take the snapshot before the button is disabled.
			$( '#totp-setup-error' ).remove();
			idleHtml = $options.html();
			$button.prop( 'disabled', true );

			wp.apiRequest( {
				method: 'POST',
				path: twoFactorTotpAdmin.restPath + '/begin',
				data: {
					user_id: parseInt( twoFactorTotpAdmin.userId, 10 )
				}
			} )
				.fail( function( response, status ) {
					$button.prop( 'disabled', false );
					showError( response, status, $button );
				} )
				.then( function( response ) {
					$options.html( response.html );
					generateQrCode( $( '#two-factor-qr-code a' ).attr( 'href' ) );
					$( '#two-factor-totp-setup-intro' ).trigger( 'focus' );
				} );
		}
	);

	$options.on(
		'click',
		'.cancel-totp-setup',
		function( e ) {
			e.preventDefault();

			$options.html( idleHtml );

			$( '#enabled-Two_Factor_Totp' )
				.prop( 'checked', false )
				.trigger( 'change' );
			$options.find( '.setup-totp' ).trigger( 'focus' );
		}
	);

	$options.on(
		'click',
		'.totp-submit',
		function( e ) {
			var key = $( '#two-factor-totp-key' ).val(),
				code = $( '#two-factor-totp-authcode' ).val();

			e.preventDefault();

			wp.apiRequest( {
				method: 'POST',
				path: twoFactorTotpAdmin.restPath,
				data: {
					user_id: parseInt( twoFactorTotpAdmin.userId, 10 ),
					key: key,
					code: code,
					enable_provider: true
				}
			} )
				.fail( function( response, status ) {
					showError( response, status, $( '.totp-submit' ) );
					$( '#two-factor-totp-authcode' ).val( '' );
				} )
				.then( function( response ) {
					$( '#enabled-Two_Factor_Totp' )
						.prop( 'checked', true )
						.trigger( 'change' );
					$options.html( response.html );
				} );
		}
	);

	$options.on(
		'click',
		'.button.reset-totp-key',
		function( e ) {
			e.preventDefault();

			wp.apiRequest( {
				method: 'DELETE',
				path: twoFactorTotpAdmin.restPath,
				data: {
					user_id: parseInt( twoFactorTotpAdmin.userId, 10 )
				}
			} ).then( function( response ) {
				$( '#enabled-Two_Factor_Totp' ).prop( 'checked', false );
				$options.html( response.html );
			} );
		}
	);
}( jQuery ) );
