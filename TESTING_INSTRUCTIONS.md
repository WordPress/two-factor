# Test Issue #880 - TOTP codes from Apple Passwords wiped on login

## Install

Download `two-factor-fix-880.zip`, install via WP Admin -> Plugins -> Upload Plugin.

## What changed

- Removed `autocomplete="off"` from the 2FA login form so password managers can autofill
- Added `autocomplete="one-time-code"` to backup-codes input (TOTP and Email already had it)
- Removed 200ms JS blanker that wiped autofilled codes (two-factor-login.js)
- Rewrote space-insertion logic to be stateless (no more flag drift on autofill)

## Test

1. Enable TOTP for test user, add account to password manager
2. Log out, log in until 2FA prompt
3. Autofill the TOTP code from password manager
4. Expected: code stays in field, form auto-submits
5. Previously: code wiped 200ms after page load

Repeat with Email and Backup Codes providers. All three should accept autofilled codes.

## Edge cases

- Manually typing code still gets midpoint space inserted
- Pasting full code with space (123 456) accepted and auto-submits
- Clearing and retyping still works (no stale flag)

## Requirements

WordPress 6.7+, PHP 7.2.24+
