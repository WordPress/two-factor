( function( $ ) {
	var $button = $( '#two-factor-new-code' );

	$button.on( 'click.two-factor-totp-new-code', function( e ) {
		if( $button.hasClass( 'clicked' ) ) {
			return false;
		} else {
			$button.addClass( 'clicked' );
		}

		data = {
			'action' : 'two-factor-totp-get-code',
			'_ajax_nonce' : $('#_nonce_user_two_factor_totp_options').val(),
			'user_login' : $('#user_login').val()
		};

		$.post( ajaxurl, data,
			function(r) {
				console.log( 'r', r );
				console.log( 'data', data );
				console.log( 'this', this );
				$( '#two-factor-totp-qrcode' ).attr( 'src', r.qrcode_url );
				$( '#two-factor-totp-key' ).val( r.key );
				$( '#two-factor-totp-key-text' ).html( r.key );
				$( '#two-factor-totp-verify-code' ).show();
				// Remove the clicked class so the button will work again.
				$button.removeClass( 'clicked' );
			}
		);
		return false;
	} );
})(jQuery);
