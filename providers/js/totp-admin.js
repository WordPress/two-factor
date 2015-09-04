( function( $ ) {
	var $button = $( '#two-factor-totp-new-secret' );

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
				if ( r.success ) {
					$( '#two-factor-totp-qrcode' ).attr( 'src', r.data.qrcode_url );
					$( '#two-factor-totp-key' ).val( r.data.key );
					$( '#two-factor-totp-key-text' ).html( r.data.key );
					$( '#two-factor-totp-verify-code' ).show();
				}
				// Remove the clicked class so the button will work again.
				$button.removeClass( 'clicked' );
			}
		);
		return false;
	} );

	var $verify_button = $( '#two-factor-totp-verify-authcode' );

	$verify_button.on( 'click.two-factor-totp-verify-code', function( e ) {
		if( $verify_button.hasClass( 'clicked' ) ) {
			return false;
		} else {
			$verify_button.addClass( 'clicked' );
		}

		if ( $( '#two-factor-totp-notice' ).length ) {
			$( '#two-factor-totp-notice' ).remove()
		}

		data = {
			'action' : 'two-factor-totp-verify-code',
			'_ajax_nonce' : $('#_nonce_user_two_factor_totp_options').val(),
			'user_id' : $('#user_id').val(),
			'key' : $('#two-factor-totp-key').val(),
			'authcode' : $('#two-factor-totp-authcode').val(),
		};

		$.post( ajaxurl, data,
			function(r) {
				if ( r.success ) {
					$( '#two-factor-totp-verify-code' ).hide().after( '<div class="updated" id="two-factor-totp-notice">' );
				} else {
					$( '#two-factor-totp-verify-code' ).after( '<div class="error" id="two-factor-totp-notice">' );
				}
				$( '#two-factor-totp-notice' ).text( r.data );
				// Remove the clicked class so the button will work again.
				$verify_button.removeClass( 'clicked' );
			}
		);
		return false;
	} );

})(jQuery);
