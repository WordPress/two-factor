/* global twoFactorTotpQrcode, qrcode, document, window */
( function() {
	var qrGenerator = function() {
		/*
		 * 0 = Automatically select the version, to avoid going over the limit of URL
		 *     length.
		 * L = Least amount of error correction, because it's not needed when scanning
		 *     on a monitor, and it lowers the image size.
		 */
		var qr = qrcode( 0, 'L' ),
			svg,
			title;

		qr.addData( twoFactorTotpQrcode.totpUrl );
		qr.make();

		document.querySelector( '#two-factor-qr-code a' ).innerHTML = qr.createSvgTag( 5 );

		// For accessibility, markup the SVG with a title and role.
		svg = document.querySelector( '#two-factor-qr-code a svg' );
		title = document.createElement( 'title' );

		svg.role = 'image';
		svg.ariaLabel = twoFactorTotpQrcode.qrCodeLabel;
		title.innerText = svg.ariaLabel;
		svg.appendChild( title );
	};

	// Run now if the document is loaded, otherwise on DOMContentLoaded.
	if ( document.readyState === 'complete' ) {
		qrGenerator();
	} else {
		window.addEventListener( 'DOMContentLoaded', qrGenerator );
	}
}() );
