(function(){
	const totpSetup = document.getElementById( 'two-factor-totp-options' ),
		userId = totpSetup.dataset.userid || 0;

	// TOTP QR Setup
	const renderQRCode = function() {
		var link = document.querySelector( '#two-factor-qr-code a' );
		if ( ! link ) {
			return;
		}

		/*
		* 0 = Automatically select the version, to avoid going over the limit of URL
		*     length.
		* L = Least amount of error correction, because it's not needed when scanning
		*     on a monitor, and it lowers the image size.
		*/
		var qr = qrcode( 0, 'L' );

		qr.addData( link.href );
		qr.make();

		link.innerHTML = qr.createSvgTag( 5 );
	};

	// TOTP Setup
	const totpSetupHandler = function( e ) {
		e.preventDefault();

		const totpKey = document.getElementById( 'two-factor-totp-key' ).value,
			totpCodeInput = document.getElementById( 'two-factor-totp-authcode' ),
			totpSetupSubmit = totpSetup.querySelector( '.totp-submit' );

		wp.apiRequest( {
			method: 'POST',
			path: 'two-factor/1.0/totp',
			data: {
				user_id: userId,
				key: totpKey,
				code: totpCodeInput.value,
			}
		} ).fail( function( response, status ) {
			let errorMessage = response.responseJSON.message || status,
				errorDiv = totpSetup.querySelector( '.totp-setup-error' );

			if ( ! errorDiv ) {
				totpSetupSubmit.outerHTML += '<div class="totp-setup-error error"><p></p></div>';
				errorDiv = totpSetup.querySelector( '.totp-setup-error' );
			}

			errorDiv.querySelector( 'p' ).textContent = errorMessage;
			totpCodeInput.value = '';
		} ).then( function( response ) {
			totpSetup.innerHTML = response.html;
		} );
	};

	const totpResetHandler = function( e ) {
		e.preventDefault();

		wp.apiRequest( {
			method: 'DELETE',
			path: 'two-factor/1.0/totp',
			data: {
				user_id: userId,
			}
		} ).then( function( response ) {
			totpSetup.innerHTML = response.html;

			// And render the QR.
			renderQRCode();
		} );
	};

	// Render the QR now if the document is loaded, otherwise on DOMContentLoaded.
	if ( document.readyState === 'complete' ) {
		renderQRCode();
	} else {
		window.addEventListener( 'DOMContentLoaded', renderQRCode );
	}

	// Add the Click handlers.
	totpSetup.addEventListener( 'click', function( e ) {
		if ( e.target.closest( '.totp-submit' ) ) {
			totpSetupHandler( e );
		} else if ( e.target.closest( '.reset-totp-key' ) ) {
			totpResetHandler( e );
		}
	} );
})();