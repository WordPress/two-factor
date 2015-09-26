/* global ajaxurl */
jQuery( document ).ready( function ( $ ) {

	$( '#application-passwords-section .delete' ).on( 'click', function( event ) {
		var link = $( this ).find( 'a' );
		var data = {
			'_nonce_delete_application_password': $( link ).data( 'nonce' ),
			'delete_application_password':        $( link ).data( 'deleteApplicationPassword' ),
			'action':                             $(link).data('action')
		};

		$.post( ajaxurl, data, function( r ) {
			if ( true === r.success ) {
				$( 'tr[data-slug="' + $(link).data('deleteApplicationPassword') + '"]' ).remove();
			}
		});
		event.preventDefault();
	});

	$( '#do_new_application_password' ).on( 'click', function( event ) {
		var data = {
			'app_name':                    $( 'input[name="new_application_password_name"]' ).val(),
			'action':                      'create_application_password',
			'create_application_password': $( 'input[name="create_application_pasword"]' ).val()
		};

		$.post( ajaxurl, data, function( r ) {
			// TODO: handle adding the app password
		});
		event.preventDefault();
	});

});
