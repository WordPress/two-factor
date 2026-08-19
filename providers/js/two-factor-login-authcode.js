/* global document */
( function() {
	// Enforce numeric-only input and normalize spacing for numeric inputmode
	// authcode fields. Space insertion is derived from the current value
	// (not a flag) so that autofilled codes — which deliver the full value
	// in a single input event rather than character-by-character — are
	// handled correctly.
	var form = document.querySelector( '#loginform' ),
		inputEl = document.querySelector( 'input.authcode[inputmode="numeric"]' ),
		expectedLength = ( inputEl && inputEl.dataset ) ? parseInt( inputEl.dataset.digits, 10 ) : 0,
		halfLength = Math.floor( expectedLength / 2 );

	if ( inputEl ) {
		inputEl.addEventListener(
			'input',
			function() {
				var sanitized = this.value.replace( /[^0-9 ]/g, '' ).replace( /^\s+/, '' ),
					digits    = sanitized.replace( / /g, '' ),
					submitControl;

				// Insert a space at the midpoint when only the first half has
				// been entered and no space is present yet. Checking the
				// current value (not a flag) ensures this also fires after
				// the field is cleared and re-typed.
				if ( halfLength && sanitized.length === halfLength && digits.length === halfLength && sanitized.indexOf( ' ' ) === -1 ) {
					sanitized += ' ';
				}

				this.value = sanitized;

				// Auto-submit once the full code length is reached.
				if ( expectedLength && digits.length === expectedLength ) {
					if ( form && typeof form.requestSubmit === 'function' ) {
						form.requestSubmit();
						submitControl = form.querySelector( '[type="submit"]' );
						if ( submitControl ) {
							submitControl.disabled = true;
						}
					}
				}
			}
		);
	}
}() );
