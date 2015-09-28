/* global ajaxurl */
( function( $ ) {

	$( '#application-passwords-section .delete' ).on( 'click', function( event ) {
		var $link = $( this ).find( 'a' ),
			data = {
				'action': 'delete_application_password',
				'user_id': $( '#application-passwords-section' ).data( 'userId' ),
				'slug': $link.data( 'slug' ),
				'_nonce_delete_application_password': $link.data( 'nonce' )
			};

		$.post( ajaxurl, data, function( response ) {
			if ( true === response.success ) {
				var $items = $( '#application-passwords-section tbody#the-list' ),
					$newAppPass = $( '.new-application-password' ),
					$row = $( 'tr[data-slug="' + $link.data( 'slug' ) + '"]' ),
					$name = $row.find( '.column-name > span' ).text();

				$row.remove();

				if ( 0 < $newAppPass.length && $name === $newAppPass.find( 'strong' ).text() ) {
					$newAppPass.remove();
				}

				if ( 0 === $items.find( 'tr' ).length && response.data && response.data.empty ) {
					$items.append( response.data.empty );
				}
			}
		} );
		event.preventDefault();
	} );

	$( '#do_new_application_password' ).on( 'click', function( event ) {
		var data = {
			'action': 'create_application_password',
			'user_id': $( '#application-passwords-section' ).data( 'userId' ),
			'slug': $( 'input[name="new_application_password_name"]' ).val(),
			'_nonce_create_application_password': $( 'input[name="_nonce_create_application_password"]' ).val()
		};

		$.post( ajaxurl, data, function( response ) {
			var $element = $( '.new-application-password' );

			if ( true === response.success ) {
				if ( response && response.data ) {
					if ( response.data.notice ) {
						if ( 0 === $element.length ) {
							$( '.create-application-password' ).after( '<p class="new-application-password"></p>' );
							$element = $( '.new-application-password' );
						}

						$element.html( response.data.notice );
					}
				}

				// @todo handle adding the item HTML to the list table.
			}
		} );
		event.preventDefault();
	} );

} )( jQuery );
