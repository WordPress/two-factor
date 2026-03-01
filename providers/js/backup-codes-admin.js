/* global twoFactorBackupCodes, wp, navigator, document, jQuery */
( function( $ ) {
	$( '.button-two-factor-backup-codes-copy' ).click( function() {
		var csvCodes = $( '.two-factor-backup-codes-wrapper' ).data( 'codesCsv' ),
			$temp;

		if ( ! csvCodes ) {
			return;
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( csvCodes );
			return;
		}

		$temp = $( '<textarea>' ).val( csvCodes ).css( { position: 'absolute', left: '-9999px' } );
		$( 'body' ).append( $temp );
		$temp[0].select();
		document.execCommand( 'copy' );
		$temp.remove();
	} );

	$( '.button-two-factor-backup-codes-generate' ).click( function() {
		wp.apiRequest( {
			method: 'POST',
			path: twoFactorBackupCodes.restPath,
			data: {
				user_id: parseInt( twoFactorBackupCodes.userId, 10 )
			}
		} ).then( function( response ) {
			var $codesList = $( '.two-factor-backup-codes-unused-codes' ),
				i;

			$( '.two-factor-backup-codes-wrapper' ).show();
			$codesList.html( '' );
			$codesList.css( { 'column-count': 2, 'column-gap': '80px', 'max-width': '420px' } );
			$( '.two-factor-backup-codes-wrapper' ).data( 'codesCsv', response.codes.join( ',' ) );

			// Append the codes.
			for ( i = 0; i < response.codes.length; i++ ) {
				$codesList.append( '<li class="two-factor-backup-codes-token">' + response.codes[ i ] + '</li>' );
			}

			// Update counter.
			$( '.two-factor-backup-codes-count' ).html( response.i18n.count );
			$( '#two-factor-backup-codes-download-link' ).attr( 'href', response.download_link );
		} );
	} );
}( jQuery ) );
