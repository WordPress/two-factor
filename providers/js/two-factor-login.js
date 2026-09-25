( function() {
	setTimeout( function() {
		var d;
		try {
			d = document.getElementById( 'authcode' );
			d.value = '';
			d.focus();
		} catch ( e ) { // eslint-disable-line no-unused-vars -- fail-silent reset
		}
	}, 200 );
}() );
