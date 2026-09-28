( function() {
	setTimeout( function() {
		try {
			document.getElementById( 'authcode' ).focus();
		} catch ( e ) { // eslint-disable-line no-unused-vars -- fail-silent reset
		}
	}, 200 );
}() );
