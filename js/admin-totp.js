(function($){

	// TOTP QR Setup
	var qr_generator = function() {
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

	// Run now if the document is loaded, otherwise on DOMContentLoaded.
	if ( document.readyState === 'complete' ) {
		qr_generator();
	} else {
		window.addEventListener( 'DOMContentLoaded', qr_generator );
	}

	// TOTP Setup
	$('.totp-submit').click( function( e ) {
		e.preventDefault();
		var key = $('#two-factor-totp-key').val(),
			code = $('#two-factor-totp-authcode').val();

		wp.apiRequest( {
			method: 'POST',
			path: <?php echo wp_json_encode( Two_Factor_Core::REST_NAMESPACE . '/totp' ); ?>,
			data: {
				user_id: <?php echo wp_json_encode( $user->ID ); ?>,
				key: key,
				code: code,
			}
		} ).fail( function( response, status ) {
			var errorMessage = response.responseJSON.message || status,
				$error = $( '#totp-setup-error' );

			if ( ! $error.length ) {
				$error = $('<div class="error" id="totp-setup-error"><p></p></div>').insertAfter( $('.totp-submit') );
			}

			$error.find('p').text( errorMessage );

			$('#two-factor-totp-authcode').val('');
		} ).then( function( response ) {
			$( '#two-factor-totp-options' ).html( response.html );
		} );
	} );

	// TOTP Reset
	$( '.button.reset-totp-key' ).click( function( e ) {
		e.preventDefault();

		wp.apiRequest( {
			method: 'DELETE',
			path: <?php echo wp_json_encode( Two_Factor_Core::REST_NAMESPACE . '/totp' ); ?>,
			data: {
				user_id: <?php echo wp_json_encode( $user->ID ); ?>,
			}
		} ).then( function( response ) {
			$( '#two-factor-totp-options' ).html( response.html );
		} );
	} );

})(jQuery);