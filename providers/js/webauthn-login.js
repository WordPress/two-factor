( function( $ ) {
	let counter = 0
	/**
	 *	Borrowed from https://github.com/davidearl/webauthn
	 */
	function webauthnAuthenticate( pubKeyAuth, callback ) {

		const originalChallenge = pubKeyAuth.challenge;
		const pk = Object.assign( {}, pubKeyAuth );

		pk.challenge = new Uint8Array( pubKeyAuth.challenge );
		pk.allowCredentials = pk.allowCredentials.map( k => {
			const ret = Object.assign( {}, k );
			ret.id = new Uint8Array( k.id );
			return ret;
		} );

		/* Ask the browser to prompt the user */
		navigator.credentials.get( { publicKey: pk } )
			.then( aAssertion => {
				let ida, cd, cda, ad, sig, info;

				ida = [];
				( new Uint8Array( aAssertion.rawId ) ).forEach( function( v ) {
					ida.push( v );
				} );

				cd = JSON.parse( String.fromCharCode.apply( null,
															  new Uint8Array( aAssertion.response.clientDataJSON ) ) );

				cda = [];
				( new Uint8Array( aAssertion.response.clientDataJSON ) ).forEach( function( v ) {
					cda.push( v );
				} );

				ad = [];
				( new Uint8Array( aAssertion.response.authenticatorData ) ).forEach( function( v ) {
					ad.push( v );
				} );

				sig = [];
				( new Uint8Array( aAssertion.response.signature ) ).forEach( function( v ) {
					sig.push( v );
				} );

				info = {
					type: aAssertion.type,
					originalChallenge: originalChallenge,
					rawId: ida,
					response: {
						authenticatorData: ad,
						clientData: cd,
						clientDataJSONarray: cda,
						signature: sig
					}
				};

				callback( true, JSON.stringify( info ) );
			})
			.catch( err => {
				if ( 'name' in err ) {
					callback( false, err.name + ': ' + err.message );
				} else {
					callback( false, err.toString() );
				}
			});
	};

	const login = ( opts, callback ) => {

		const { action, payload, _wpnonce } = opts;

		webauthnAuthenticate( payload, ( success, info ) => {
			if ( success ) {
				callback( { success:true, result: info } );
			} else {
				callback( { success:false, message: info } );
			}
		});
	};

	/**
	 *	Some Password Managers (like nextcloud passwords) seem to abort the
	 *	key browser dialog.
	 *	We have to retry a few times
	 */
	const auth = () => {
		// return if we are not
		if ( ! $( '.webauthn-retry' ).length ) {
			return;
		}
		$( '.webauthn-retry' ).removeClass( 'visible' );

		const { action, apps, _wpnonce } = window.webauthnL10n;
		const payload = apps[ counter % apps.length ]

		login( { action, payload, _wpnonce }, response => {
			if ( response.success ) {
				$( '#webauthn_response' ).val( response.result );
				$( '#loginform' ).submit();
			} else {
				counter++
				// Show retry-button
				$( '.webauthn-retry' ).addClass( 'visible' );
			}
		} );
	};

	if ( ! window.webauthnL10n ) {
		console.error( 'webauthL10n is not defined' );
	};

	if ( 'credentials' in navigator ) {
		$( document )
			.ready( auth )
			.on( 'click', '.webauthn-retry-link', auth );
	} else {

		// Show unsupported message
		$( '.webauthn-unsupported' ).addClass( 'visible' );
	}

} )( jQuery );
