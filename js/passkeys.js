// Passkeys frontend implementation
document.addEventListener('DOMContentLoaded', function() {

	// Registration Flow
	const registerBtn = document.getElementById('two-factor-passkey-register-btn');
	if (registerBtn) {
		registerBtn.addEventListener('click', async function() {
			try {
				registerBtn.disabled = true;
				registerBtn.textContent = 'Registering...';

				// 1. Get creation options from server
				const optionsRes = await fetch(twoFactorPasskeyData.restUrl + 'passkeys/options?action=register', {
					method: 'GET',
					headers: { 'X-WP-Nonce': twoFactorPasskeyData.nonce }
				});
				const options = await optionsRes.json();
				if (!optionsRes.ok) throw new Error(options.message || 'Failed to get options');

				// 2. Decode options (Base64Url to Uint8Array)
				const createArgs = recursiveBase64StrToArrayBuffer(options);

				// 3. Prompt user to create passkey
				const credential = await navigator.credentials.create(createArgs);

				// 4. Encode response
				const data = {
					id: credential.id,
					rawId: arrayBufferToBase64(credential.rawId),
					type: credential.type,
					response: {
						attestationObject: arrayBufferToBase64(credential.response.attestationObject),
						clientDataJSON: arrayBufferToBase64(credential.response.clientDataJSON)
					}
				};

				// 5. Send to server to register
				const regRes = await fetch(twoFactorPasskeyData.restUrl + 'passkeys/register', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': twoFactorPasskeyData.nonce
					},
					body: JSON.stringify(data)
				});

				const regData = await regRes.json();
				if (!regRes.ok) throw new Error(regData.message || 'Registration failed');

				alert('Passkey registered successfully!');
				window.location.reload();

			} catch (err) {
				console.error(err);
				alert('Passkey registration failed: ' + err.message);
			} finally {
				registerBtn.disabled = false;
				registerBtn.textContent = 'Register New Passkey';
			}
		});
	}

	// Login Flow (Option 1 - Passwordless)
	// For passwordless, we need to intercept the standard login form.
	const loginForm = document.getElementById('loginform');
	if (loginForm && !document.getElementById('twofactorform')) {
		const userLoginInput = document.getElementById('user_login');
		
		// Add a "Login with Passkey" button
		const passkeyLoginBtn = document.createElement('button');
		passkeyLoginBtn.type = 'button';
		passkeyLoginBtn.className = 'button button-secondary button-large';
		passkeyLoginBtn.style.marginTop = '10px';
		passkeyLoginBtn.style.width = '100%';
		passkeyLoginBtn.textContent = 'Login with Passkey';
		
		// Insert after the submit button
		const submitP = loginForm.querySelector('.submit');
		if (submitP) {
			submitP.appendChild(passkeyLoginBtn);
		}

		passkeyLoginBtn.addEventListener('click', async function(e) {
			e.preventDefault();
			const username = userLoginInput.value.trim();
			if (!username) {
				alert('Please enter your username or email address first.');
				userLoginInput.focus();
				return;
			}

			try {
				passkeyLoginBtn.disabled = true;
				passkeyLoginBtn.textContent = 'Authenticating...';

				const optionsRes = await fetch(twoFactorPasskeyData.restUrl + 'passkeys/options?action=authenticate&username=' + encodeURIComponent(username));
				const options = await optionsRes.json();
				if (!optionsRes.ok) throw new Error(options.message || 'Failed to get options');

				const sessionId = options.session_id;
				const getArgs = recursiveBase64StrToArrayBuffer(options.args);
				const credential = await navigator.credentials.get(getArgs);

				const assertion = {
					id: credential.id,
					rawId: arrayBufferToBase64(credential.rawId),
					type: credential.type,
					session_id: sessionId,
					response: {
						authenticatorData: arrayBufferToBase64(credential.response.authenticatorData),
						clientDataJSON: arrayBufferToBase64(credential.response.clientDataJSON),
						signature: arrayBufferToBase64(credential.response.signature),
						userHandle: credential.response.userHandle ? arrayBufferToBase64(credential.response.userHandle) : null
					}
				};

				let assertionInput = document.getElementById('two_factor_passkey_assertion');
				if (!assertionInput) {
					assertionInput = document.createElement('input');
					assertionInput.type = 'hidden';
					assertionInput.id = 'two_factor_passkey_assertion';
					assertionInput.name = 'two_factor_passkey_assertion';
					loginForm.appendChild(assertionInput);
				}
				assertionInput.value = JSON.stringify(assertion);

				HTMLFormElement.prototype.submit.call(loginForm);

			} catch (err) {
				console.error(err);
				alert('Passkey login failed: ' + err.message);
				passkeyLoginBtn.disabled = false;
				passkeyLoginBtn.textContent = 'Login with Passkey';
			}
		});
	}

	// Login Flow (Option 2 - 2FA Interstitial)
	const authBtn2FA = document.getElementById('two-factor-passkey-auth-btn');
	if (authBtn2FA) {
		const twoFactorForm = authBtn2FA.closest('form');
		authBtn2FA.addEventListener('click', async function(e) {
			e.preventDefault();
			const username = authBtn2FA.getAttribute('data-username');
			if (!username) return;

			try {
				authBtn2FA.disabled = true;
				authBtn2FA.textContent = 'Authenticating...';

				const optionsRes = await fetch(twoFactorPasskeyData.restUrl + 'passkeys/options?action=authenticate&username=' + encodeURIComponent(username));
				const options = await optionsRes.json();
				if (!optionsRes.ok) throw new Error(options.message || 'Failed to get options');

				const sessionId = options.session_id;
				const getArgs = recursiveBase64StrToArrayBuffer(options.args);
				const credential = await navigator.credentials.get(getArgs);

				const assertion = {
					id: credential.id,
					rawId: arrayBufferToBase64(credential.rawId),
					type: credential.type,
					session_id: sessionId,
					response: {
						authenticatorData: arrayBufferToBase64(credential.response.authenticatorData),
						clientDataJSON: arrayBufferToBase64(credential.response.clientDataJSON),
						signature: arrayBufferToBase64(credential.response.signature),
						userHandle: credential.response.userHandle ? arrayBufferToBase64(credential.response.userHandle) : null
					}
				};

				let assertionInput = document.getElementById('two_factor_passkey_assertion');
				if (!assertionInput) {
					assertionInput = document.createElement('input');
					assertionInput.type = 'hidden';
					assertionInput.id = 'two_factor_passkey_assertion';
					assertionInput.name = 'two_factor_passkey_assertion';
					twoFactorForm.appendChild(assertionInput);
				}
				assertionInput.value = JSON.stringify(assertion);

				HTMLFormElement.prototype.submit.call(twoFactorForm);

			} catch (err) {
				console.error(err);
				alert('Passkey authentication failed: ' + err.message);
				authBtn2FA.disabled = false;
				authBtn2FA.textContent = 'Use Passkey';
			}
		});
	}

	// Helpers for Base64Url
	function arrayBufferToBase64(buffer) {
		let binary = '';
		const bytes = new Uint8Array(buffer);
		const len = bytes.byteLength;
		for (let i = 0; i < len; i++) {
			binary += String.fromCharCode(bytes[i]);
		}
		return window.btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=/g, "");
	}

	function base64ToArrayBuffer(base64) {
		base64 = base64.replace(/-/g, "+").replace(/_/g, "/");
		const padLen = (4 - (base64.length % 4)) % 4;
		base64 += "=".repeat(padLen);
		const binary_string = window.atob(base64);
		const len = binary_string.length;
		const bytes = new Uint8Array(len);
		for (let i = 0; i < len; i++) {
			bytes[i] = binary_string.charCodeAt(i);
		}
		return bytes.buffer;
	}

	function recursiveBase64StrToArrayBuffer(obj) {
		let prefix = '=?BINARY?B?';
		let suffix = '?=';
		if (typeof obj === 'object' && obj !== null) {
			for (let key in obj) {
				if (typeof obj[key] === 'string') {
					let str = obj[key];
					if (str.substring(0, prefix.length) === prefix && str.substring(str.length - suffix.length) === suffix) {
						str = str.substring(prefix.length, str.length - suffix.length);
						obj[key] = base64ToArrayBuffer(str);
					}
				} else {
					recursiveBase64StrToArrayBuffer(obj[key]);
				}
			}
		}
		return obj;
	}
});
