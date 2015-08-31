(function($) {
	setTimeout( function() {
		console.log( 'sign', request );

		u2f.sign( request, function( data ) {
			console.log( 'Authenticate callback', data );

			$( '#u2f_response' ).val( JSON.stringify( data ) );
			$( '#loginform' ).submit();
		} );
	}, 1000 );
})(jQuery);
