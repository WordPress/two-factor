/* global u2f, u2fL10n */
( function( $ ) {
	var $button = $( '#register_security_key' );

	$button.click( function() {
		var registerRequest;

		if ( $button.hasClass( 'clicked' ) ) {
			return false;
		} else {
			$button.addClass( 'clicked' );
		}

		window.console.log( 'sign', u2fL10n.register.request );

		$button.text( u2fL10n.text.insert ).append( '<span class="spinner is-active" />' );

		$( '.spinner.is-active', $button ).css( 'margin', '2.5px 0px 0px 5px' );

		registerRequest = {
			version: u2fL10n.register.request.version,
			challenge: u2fL10n.register.request.challenge
		};

		u2f.register( u2fL10n.register.request.appId, [ registerRequest ], u2fL10n.register.sigs, function( data ) {
			if ( data.errorCode ) {
				window.console.log( 'Registration Failed', data.errorCode );

				$button.text( u2fL10n.text.error );
				return false;
			}

			$( '#do_new_security_key' ).val( 'true' );
			$( '#u2f_response' ).val( JSON.stringify( data ) );

			// See: http://stackoverflow.com/questions/833032/submit-is-not-a-function-error-in-javascript
			$( '<form>' )[0].submit.call( $( '#your-profile' )[0] );
		} );
	} );
} )( jQuery );
