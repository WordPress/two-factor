(function($){

	// Backup Codes generation
	$( '.button-two-factor-backup-codes-generate' ).click( function() {
		wp.apiRequest( {
			method: 'POST',
			path: <?php echo wp_json_encode( Two_Factor_Core::REST_NAMESPACE . '/generate-backup-codes' ); ?>,
			data: {
				user_id: <?php echo wp_json_encode( $user->ID ); ?>
			}
		} ).then( function( response ) {
			var $codesList = $( '.two-factor-backup-codes-unused-codes' );

			$( '.two-factor-backup-codes-wrapper' ).show();
			$codesList.html( '' );

			// Append the codes.
			for ( i = 0; i < response.codes.length; i++ ) {
				$codesList.append( '<li>' + response.codes[ i ] + '</li>' );
			}

			// Update counter.
			$( '.two-factor-backup-codes-count' ).html( response.i18n.count );
			$( '#two-factor-backup-codes-download-link' ).attr( 'href', response.download_link );
		} );
	} );

})(jQuery);