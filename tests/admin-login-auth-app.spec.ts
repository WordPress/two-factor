import { test } from '@playwright/test';

test('Admin login', async ({ page, context }) => {
  const info = test.info();
  const use = (info.project && (info.project as any).use) || {};
  const ADMIN_URL = (use.adminURL as string) || (use.baseURL as string) || '';
  const USERNAME = (use.adminUser as string) || '';
  const PASSWORD = (use.adminPassword as string) || '';

  await page.goto(ADMIN_URL, { waitUntil: 'domcontentloaded' });

  const usernameSelector = 'input[name="log"], input#user_login, input[aria-label="Username or Email Address"]';
  const passwordSelector = 'input[name="pwd"], input#user_pass, input[aria-label="Password"]';

  await page.locator(usernameSelector).first().waitFor({ state: 'visible', timeout: 15000 });
  await page.locator(usernameSelector).first().fill(USERNAME);
  await page.locator(passwordSelector).first().fill(PASSWORD);

  // Click login and wait for either a new page, auth code input, or a redirect to /wp-admin
  await page.getByRole('button', { name: 'Log In' }).click();

  const newPagePromise = context.waitForEvent('page').then(p => ({ type: 'new', page: p }));
  const authPromise = page.waitForSelector('#authcode', { timeout: 30000 }).then(() => ({ type: 'auth' })).catch(() => null);
  const navPromise = page.waitForURL(/.*\/wp-admin.*$/, { timeout: 30000 }).then(() => ({ type: 'nav' })).catch(() => null);

  let result = null;
  try {
    result = await Promise.race([newPagePromise, authPromise, navPromise]) as any;
  } catch (e) {
    // ignore
  }

  // Determine which page to use (original or newly opened)
  let targetPage = page;
  if (result && (result as any).type === 'new') {
    targetPage = (result as any).page;
    await targetPage.waitForLoadState('domcontentloaded');
  }

  // If an auth code input is present on the active page, generate TOTP and fill it
  if (await targetPage.$('#authcode')) {
    const info = test.info();
    const use = (info.project && (info.project as any).use) || {};
    const secret = (use.adminTOTPSecret as string) || '';
    if (!secret) throw new Error('adminTOTPSecret not set in config');

    // Generate TOTP inside the page using SubtleCrypto
    const otp = await targetPage.evaluate(async (s) => {
      function base32Decode(input: string): Uint8Array {
        const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        const cleaned = input.replace(/=+$/, '').toUpperCase().replace(/[^A-Z2-7]/g, '');
        let bits = '';
        for (const ch of cleaned) {
          const val = alphabet.indexOf(ch);
          bits += val.toString(2).padStart(5, '0');
        }
        const bytes: number[] = [];
        for (let i = 0; i + 8 <= bits.length; i += 8) {
          bytes.push(parseInt(bits.substr(i, 8), 2));
        }
        return new Uint8Array(bytes);
      }

      /**
       * Converts a given counter to a big-endian Uint8Array
       * @param {number} counter - the counter to convert
       * @returns {Uint8Array} a big-endian Uint8Array
       */
      function toBigEndianUint8(counter: number): Uint8Array {
        const buf = new ArrayBuffer(8);
        const dv = new DataView(buf);
        // split into hi/lo
        const hi = Math.floor(counter / Math.pow(2, 32));
        const lo = counter >>> 0;
        dv.setUint32(0, hi);
        dv.setUint32(4, lo);
        return new Uint8Array(buf);
      }

      const key = base32Decode(s);
      const epoch = Math.floor(Date.now() / 1000);
      const timestep = 30;
      const counter = Math.floor(epoch / timestep);
      const counterBytes = toBigEndianUint8(counter);

      const cryptoKey = await crypto.subtle.importKey(
        'raw',
        key.buffer,
        { name: 'HMAC', hash: 'SHA-1' },
        false,
        ['sign']
      );
      const sig = await crypto.subtle.sign('HMAC', cryptoKey, counterBytes);
      const hmac = new Uint8Array(sig);
      const offset = hmac[hmac.length - 1] & 0xf;
      const code = ((hmac[offset] & 0x7f) << 24) |
        ((hmac[offset + 1] & 0xff) << 16) |
        ((hmac[offset + 2] & 0xff) << 8) |
        (hmac[offset + 3] & 0xff);
      const otp = (code % 10 ** 6).toString().padStart(6, '0');
      return otp;
    }, secret);

    await targetPage.fill('#authcode', otp);
  }

  if (!targetPage.isClosed()) {
    await targetPage.waitForTimeout(2000);
  }
});
