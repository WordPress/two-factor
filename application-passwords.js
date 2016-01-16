/* global appPass, wp */
(function( $, appPass ) {
	var $appPassSection   = $( '#application-passwords-section' ),
		$newAppPassForm   = $appPassSection.find( '.create-application-password' ),
		$newAppPassField  = $newAppPassForm.find( '.input' ),
		$newAppPassButton = $newAppPassForm.find( '.button' ),
		$appPassTbody     = $appPassSection.find( 'tbody' ),
		$appPassTrNoItems = $appPassTbody.find( '.no-items' ),
		tmplNewAppPass    = wp.template( 'new-application-password' ),
		tmplAppPassRow    = wp.template( 'application-password-row' );

	$newAppPassButton.click( function( e ) {
		var name = $newAppPassField.val();

		e.preventDefault();

		if ( 0 === name.length ) {
			$newAppPassField.focus();
			return;
		}

		$newAppPassField.prop( 'disabled', true );
		$newAppPassButton.prop( 'disabled', true );

		$.ajax( {
			url:        appPass.root + appPass.namespace + '/application-passwords/' + appPass.user_id + '/add',
			method:     'POST',
			beforeSend: function( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', appPass.nonce );
			},
			data:       {
				name: name
			}
		} ).done( function( response ) {
			$newAppPassField.prop( 'disabled', false ).val( '' );
			$newAppPassButton.prop( 'disabled', false );

			$newAppPassForm.after( tmplNewAppPass( {
				name:     name,
				password: response.password
			} ) );

			$appPassTbody.prepend( tmplAppPassRow( response.row ) );

			$appPassTrNoItems.remove();
		} );
	});

	$appPassTbody.on( 'click', '.delete a', function( e ) {
		var $tr  = $( e.target ).closest( 'tr' ),
			slug = $tr.data( 'slug' );

		e.preventDefault();

		$.ajax( {
			url:        appPass.root + appPass.namespace + '/application-passwords/' + appPass.user_id + '/' + slug,
			method:     'DELETE',
			beforeSend: function( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', appPass.nonce );
			}
		} ).done( function( response ) {
			if ( response ) {
				$tr.remove();
			}
		} );
	} );
} )( jQuery, appPass );
