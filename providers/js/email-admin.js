/* global twoFactorEmailAdmin, wp, jQuery */
( function( $ ) {
	$( '#two-factor-email-send-code' ).on( 'click', function( e ) {
		var $btn = $( this );

		e.preventDefault();
		$btn.prop( 'disabled', true );

		wp.apiRequest( {
			method: 'POST',
			path: twoFactorEmailAdmin.restPath,
			data: {
				user_id: parseInt( twoFactorEmailAdmin.userId, 10 )
			}
		} ).done( function() {
			$btn.hide();
			$( '#two-factor-email-verification-form' ).slideDown();
			$( '#two-factor-email-code-input' ).focus();
		} ).fail( function( response ) {
			var msg = ( response.responseJSON && response.responseJSON.message ) ? response.responseJSON.message : 'Error sending email';

			// eslint-disable-next-line no-alert
			alert( msg );
			$btn.prop( 'disabled', false );
		} );
	} );

	$( '#two-factor-email-verify-code' ).on( 'click', function( e ) {
		var $btn = $( this ),
			code = $( '#two-factor-email-code-input' ).val();

		e.preventDefault();
		$btn.prop( 'disabled', true );

		wp.apiRequest( {
			method: 'POST',
			path: twoFactorEmailAdmin.restPath,
			data: {
				user_id: parseInt( twoFactorEmailAdmin.userId, 10 ),
				code: code,
				enable_provider: true
			}
		} ).done( function( response ) {
			var $newContent = $( response.html );

			$( '#two-factor-email-options' ).replaceWith( $newContent );
			$( '#enabled-Two_Factor_Email' ).prop( 'checked', true );
		} ).fail( function( response ) {
			var msg = ( response.responseJSON && response.responseJSON.message ) ? response.responseJSON.message : 'Error verifying code';

			// eslint-disable-next-line no-alert
			alert( msg );
			$btn.prop( 'disabled', false );
		} );
	} );
}( jQuery ) );
