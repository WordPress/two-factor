/* global twoFactorTotpAdmin, wp, document, jQuery */
( function( $ ) {
	var checkbox = document.getElementById( 'enabled-Two_Factor_Totp' );

	// Focus the auth code input when the checkbox is clicked.
	if ( checkbox ) {
		checkbox.addEventListener( 'click', function( e ) {
			if ( e.target.checked ) {
				document.getElementById( 'two-factor-totp-authcode' ).focus();
			}
		} );
	}

	$( '.totp-submit' ).click( function( e ) {
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
		} ).fail( function( response, status ) {
			var errorMessage = response.responseJSON.message || status,
				$error = $( '#totp-setup-error' );

			if ( ! $error.length ) {
				$error = $( '<div class="error" id="totp-setup-error"><p></p></div>' ).insertAfter( $( '.totp-submit' ) );
			}

			$error.find( 'p' ).text( errorMessage );

			$( '#enabled-Two_Factor_Totp' ).prop( 'checked', false ).trigger( 'change' );
			$( '#two-factor-totp-authcode' ).val( '' );
		} ).then( function( response ) {
			$( '#enabled-Two_Factor_Totp' ).prop( 'checked', true ).trigger( 'change' );
			$( '#two-factor-totp-options' ).html( response.html );
		} );
	} );

	$( '.button.reset-totp-key' ).click( function( e ) {
		e.preventDefault();

		wp.apiRequest( {
			method: 'DELETE',
			path: twoFactorTotpAdmin.restPath,
			data: {
				user_id: parseInt( twoFactorTotpAdmin.userId, 10 )
			}
		} ).then( function( response ) {
			$( '#enabled-Two_Factor_Totp' ).prop( 'checked', false );
			$( '#two-factor-totp-options' ).html( response.html );
		} );
	} );
}( jQuery ) );
