/* global document, setTimeout */
( function() {
	setTimeout( function() {
		var d;
		try {
			d = document.getElementById( 'authcode' );
			d.value = '';
			d.focus();
		} catch ( e ) {}
	}, 200 );
}() );
