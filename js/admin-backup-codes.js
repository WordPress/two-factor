(function(){
	const backupCodes = document.getElementById( 'two-factor-backup-codes' ),
		generateCodesButton = backupCodes.querySelector( '.button-two-factor-backup-codes-generate' ),
		userId = backupCodes.dataset.userid || 0;

	// Backup Codes generation
	generateCodesButton.addEventListener( 'click', function(e) {
		const codesCountDiv = backupCodes.querySelector( '.two-factor-backup-codes-count' ),
			codeWrapper = backupCodes.querySelector( '.two-factor-backup-codes-wrapper' ),
			codeList = backupCodes.querySelector( '.two-factor-backup-codes-unused-codes' ),
			downloadButton = backupCodes.querySelector( '.button-two-factor-backup-codes-download' );

		wp.apiRequest( {
			method: 'POST',
			path: 'two-factor/1.0/generate-backup-codes',
			data: {
				user_id: userId
			}
		} ).then( function( response ) {
			codeList.innerHTML = '';

			// Append the codes.
			for ( i = 0; i < response.codes.length; i++ ) {
				codeList.innerHTML += '<li>' + response.codes[ i ] + '</li>';
			}

			// Display the section.
			codeWrapper.style.display = 'block';

			// Update counter.
			codesCountDiv.innerHTML = response.i18n.count;

			// Update link.
			downloadButton.href = response.download_link;
		} );
	} );

})();