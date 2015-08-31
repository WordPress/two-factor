(function($) {
	setTimeout( function() {
		console.log( 'sign', u2fL10n.request );

		u2f.sign( u2fL10n.request, function( data ) {
			console.log( 'Authenticate callback', data );

			$( '#u2f_response' ).val( JSON.stringify( data ) );
			$( '#loginform' ).submit();
		} );
	}, 1000 );
})(jQuery);
