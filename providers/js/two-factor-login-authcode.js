/* global document */
( function() {
	// Enforce numeric-only input for numeric inputmode elements.
	var form = document.querySelector( '#loginform' ),
		inputEl = document.querySelector( 'input.authcode[inputmode="numeric"]' ),
		expectedLength = ( inputEl && inputEl.dataset ) ? inputEl.dataset.digits : 0,
		spaceInserted = false;

	if ( inputEl ) {
		inputEl.addEventListener(
			'input',
			function() {
				var value = this.value.replace( /[^0-9 ]/g, '' ).trimStart();

				if ( ! spaceInserted && expectedLength && value.length === Math.floor( expectedLength / 2 ) ) {
					value += ' ';
					spaceInserted = true;
				} else if ( spaceInserted && ! this.value ) {
					spaceInserted = false;
				}

				this.value = value;

				// Auto-submit if it's the expected length.
				if ( expectedLength && value.replace( / /g, '' ).length === parseInt( expectedLength, 10 ) ) {
					if ( undefined !== form.requestSubmit ) {
						form.requestSubmit();
						form.submit.disabled = 'disabled';
					}
				}
			}
		);
	}
}() );
