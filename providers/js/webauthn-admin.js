( function( $ ) {

	/**
	 *	Borrowed from https://github.com/davidearl/webauthn
	 */
	function webauthnRegister( key, callback ) {

		const publicKey = Object.assign( {}, key.publicKey );

		publicKey.attestation = undefined;
		publicKey.challenge = new Uint8Array( publicKey.challenge );
		publicKey.user.id = new Uint8Array( publicKey.user.id );

		navigator.credentials.create( { publicKey } )
			.then( function( aNewCredentialInfo ) {
				let cd, ao, rawId, info;

				cd = JSON.parse( String.fromCharCode.apply( null, new Uint8Array( aNewCredentialInfo.response.clientDataJSON ) ) );
				if ( key.b64challenge !== cd.challenge ) {
					callback( false, 'key returned something unexpected (1)' );
				}
				if ( ! ( 'type' in cd ) ) {
					return callback( false, 'key returned something unexpected (3)' );
				}
				if ( 'webauthn.create' != cd.type ) {
					return callback( false, 'key returned something unexpected (4)' );
				}

				ao = [];
				( new Uint8Array( aNewCredentialInfo.response.attestationObject ) ).forEach( function( v ) {
					ao.push( v );
				});
				rawId = [];
				( new Uint8Array( aNewCredentialInfo.rawId ) ).forEach( function( v ) {
					rawId.push( v );
				});
				info = {
					rawId: rawId,
					id: aNewCredentialInfo.id,
					type: aNewCredentialInfo.type,
					response: {
						attestationObject: ao,
						clientDataJSON:
						  JSON.parse( String.fromCharCode.apply( null, new Uint8Array( aNewCredentialInfo.response.clientDataJSON ) ) )
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
	}

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
				/*
				FF mac:
				InvalidStateError: key not found
				AbortError: user aborted or denied
				NotAllowedError: ?
					The request is not allowed by the user agent or the platform in the current context, possibly because the user denied permission.

				Chrome mac:
				NotAllowedError: user aborted or denied

				Safari mac:
				NotAllowedError: user aborted or denied

				Edge win10:
				UnknownError: wrong key...?
				NotAllowedError: user aborted or denied

				FF win:
				NotAllowedError: user aborted or denied
					DOMException: "The request is not allowed by the user agent or the platform in the current context, possibly because the user denied permission."
				*/
				if ( 'name' in err ) {
					callback( false, err.name + ': ' + err.message );
				} else {
					callback( false, err.toString() );
				}
			});
	};

	/**
	 *	@param ArrayBuffer arrayBuf
	 *	@return Array
	 */
	const buffer2Array = arrayBuf => [ ... ( new Uint8Array( arrayBuf ) ) ];

	const register = ( opts, callback ) => {

		const { action, userId, payload, _wpnonce } = opts;

		webauthnRegister( payload, ( success, info ) => {
			if ( success ) {
				$.ajax({
					url: wp.ajax.settings.url,
					method: 'post',
					data: {
						action,
						payload: info,
						user_id: userId,
						_wpnonce
					},
					success: callback
				});
			} else {
				callback( { success: false, message: info } );
			}
		} );
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

	const sendRequest = ( opts, callback ) => {

		$.ajax( {
			url: wp.ajax.settings.url,
			method: 'post',
			data: opts,
			success:callback
		} );
	};

	const editKey = ( editLabel, opts, callback = () => {} ) => {

		const {
			action,
			payload,
			_wpnonce,
			userId
		} = opts;

		const stopEditing = ( save = false ) => {
			const newLabel = $( editLabel ).text();
			$( editLabel ).text( newLabel );
			$( editLabel ).prop( 'contenteditable', false );
			$( document ).off( 'keydown' );
			$( editLabel ).off( 'blur' );
			if ( save && prevLabel !== newLabel ) {
				$( editLabel ).addClass( 'busy' );

				sendRequest(
					{
						action,
						payload: {
							md5id: payload,
							label: newLabel
						},
						user_id: userId,
						_wpnonce
					},
					response => {
						$( editLabel ).removeClass( 'busy' );
						callback( response );
					}
				);
			} else if ( ! save ) {
				$( editLabel ).text( prevLabel );
			}
		};

		const prevLabel = $( editLabel ).text();

		$( editLabel ).prop( 'contenteditable', true );

		$( document ).on( 'keydown', e => {
			if ( 13 === e.which ) {
				stopEditing( true );
				e.preventDefault();
			} else if ( 27 === e.which ) {
				stopEditing( true );
			}
		} );

		// Focus and select
		$( editLabel )
			.on( 'blur', e => stopEditing( true ) )
			.on( 'paste', e => {
				e.preventDefault();
				let text = ( e.originalEvent || e ).clipboardData.getData( 'text/plain' );
				document.execCommand( 'insertHTML', false, text );
			} );

		$( editLabel ).focus();

		document.execCommand( 'selectAll', false, null );
	};

	$( document ).on( 'click', '#webauthn-register-key', e => {

		e.preventDefault();

		$( e.target ).next( '.webauthn-error' ).remove();

		const $btn = $( e.target ).addClass( 'busy' );

		const opts = JSON.parse( $( e.target ).attr( 'data-create-options' ) );

		register( opts, response => {
			$btn.removeClass( 'busy' );
			if ( response.success ) {
				const $keyItem = $( response.html ).appendTo( '#webauthn-keys' );
				const $keyLabel = $keyItem.find( '.webauthn-label' );

				editKey(
					$keyLabel.get( 0 ),
					JSON.parse( $keyLabel.attr( 'data-action' ) )
				);
			} else {
				let msg;
				if ( !! response.message ) {
					msg = response.message;
				} else if ( !! response.data && response.data[0] && response.data[0].message ) {
					msg = response.data[0].message;
				} else {
					msg = JSON.stringify( response );
				}
				$( `<span class="webauthn-error description">${msg}</span>` ).insertAfter( '#webauthn-register-key' );
			}
		});

	});

	if ( 'credentials' in navigator ) {
		$( document ).on( 'click', '.webauthn-action', e => {
			e.preventDefault();
			const $btn = $( e.target ).closest( '.webauthn-action' );
			const opts = JSON.parse( $btn.attr( 'data-action' ) );
			const $keyEl = $( e.target ).closest( '.webauthn-key' );
			const {
				action,
				userId,
				payload,
				_wpnonce
			} = opts;


			if ( 'webauthn-test-key' === action ) {
				e.preventDefault();
				$keyEl.find( '.notice' ).remove();
				$btn.addClass( 'busy' );
				login( opts, result => {
					if ( ! result.success ) {
						$keyEl.append( `<div class="notice notice-inline notice-warning">${result.message}</div>` );
						$btn.removeClass( 'busy' );
						return;
					}

					// Send to server
					sendRequest( {
						action,
						user_id: userId,
						payload: result.result,
						_wpnonce
					}, response => {
						if ( response.success ) {
							$btn.find( '[data-tested]' ).attr( 'data-tested', 'tested' );
						} else {
							$btn.find( '[data-tested]' ).attr( 'data-tested', 'fail' );
							$keyEl.append( `<div class="notice notice-inline notice-error">${response.data[0].message}</div>` );
						}
						$btn.removeClass( 'busy' );
					} );
				} );
			} else if ( 'webauthn-delete-key' === action ) {
				$keyEl.addClass( 'busy' );
				e.preventDefault();
				sendRequest( opts, function( response ) {
					$keyEl.removeClass( 'busy' );

					// Remove key from list
					if ( response.success ) {
						$keyEl.remove();
					} else {

						// Error from server
						$keyEl.append( `<div class="notice notice-inline notice-error">${response.data[0].message}</div>` );
					}
				} );
			}
			if ( 'webauthn-edit-key' === opts.action ) {
				if ( 'true' !== $( e.currentTarget ).prop( 'contenteditable' ) ) {
					e.preventDefault();
					editKey( e.currentTarget, opts, response => {
						if ( ! response.success ) {
							$keyEl.append( `<div class="notice notice-inline notice-error">${response.data[0].message}</div>` );
						}
					} );
				}
			}
		} );
	} else {
		$( '.webauthn-unsupported' ).removeClass( 'hidden' );
		$( '.webauthn-supported' ).addClass( 'hidden' );
	}

} )( jQuery );
