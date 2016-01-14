/* global appPass, console, wp */
(function($,appPass){
	var $appPassSection   = $( '#application-passwords-section' ),
		$newAppPassForm   = $appPassSection.find( '.create-application-password' ),
		$newAppPassField  = $newAppPassForm.find( '.input' ),
		$newAppPassButton = $newAppPassForm.find( '.button' ),
		$appPassTbody     = $appPassSection.find( 'tbody' ),
		tmplNewAppPass    = wp.template( 'new-application-password' ),
		tmplAppPassRow    = wp.template( 'application-password-row' );

	$newAppPassButton.click( function(e){
		e.preventDefault();
		var name = $newAppPassField.val();

		if ( 0 === name.length ) {
			$newAppPassField.focus();
			return;
		}

		$newAppPassField.prop('disabled', true);
		$newAppPassButton.prop('disabled', true);

		$.ajax( {
			url        : appPass.root + '2fa/v1/application-passwords/' + appPass.user_id + '/add',
			method     : 'POST',
			beforeSend : function ( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', appPass.nonce );
			},
			data       : {
				name : name
			}
		} ).done( function ( response ) {
			$newAppPassField.prop( 'disabled', false ).val('');
			$newAppPassButton.prop( 'disabled', false );

			$newAppPassForm.after( tmplNewAppPass( {
				name     : name,
				password : response.password
			} ) );

			$appPassTbody.prepend( tmplAppPassRow( response.row ) );
		} );
	});
})(jQuery,appPass);