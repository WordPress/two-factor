/* global u2f, u2fL10n */
( function( $ ) {
	setTimeout( function() {
		window.console.log( 'sign', u2fL10n.request );

		u2f.sign( u2fL10n.request, function( data ) {
			window.console.log( 'Authenticate callback', data );

			$( '#u2f_response' ).val( JSON.stringify( data ) );
			$( '#loginform' ).submit();
		} );
	}, 1000 );
} )( jQuery );
