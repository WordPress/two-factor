/* global u2f, u2fL10n */
( function( $ ) {
	if ( ! window.u2fL10n ) {
		window.console.log( 'u2fL10n is not defined' );
		return;
	}

	window.console.log( 'sign', u2fL10n.request );

	u2f.sign( u2fL10n.request[0].appId, u2fL10n.request[0].challenge, u2fL10n.request, function( data ) {
		window.console.log( 'Authenticate callback', data );

		if ( data.errorCode ) {
			window.console.log( 'Registration Failed', data.errorCode );
		} else {
			$( '#u2f_response' ).val( JSON.stringify( data ) );
			$( '#loginform' ).submit();
		}
	} );
} )( jQuery );
