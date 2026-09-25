( function() {
	setTimeout( function() {
		var d;
		try {
			d = document.getElementById( 'authcode' );
			d.value = '';
			d.focus();
		} catch {}
	}, 200 );
}() );
