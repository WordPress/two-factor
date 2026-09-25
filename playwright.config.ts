import { defineConfig, devices } from '@playwright/test';

declare module '@playwright/test' {
  interface PlaywrightTestOptions {
    adminURL: string;
    adminUser: string;
    adminPassword: string;
    adminTOTPSecret: string;
  }
}

export default defineConfig({
  testDir: './tests',
  /* Run tests in files in parallel */
  fullyParallel: true,
  /* Fail the build on CI if you accidentally left test.only in the source code. */
  forbidOnly: !!process.env.CI,
  /* Retry on CI only */
  retries: process.env.CI ? 2 : 0,
  /* Opt out of parallel tests on CI. */
  workers: process.env.CI ? 1 : undefined,
  /* Reporter to use. See https://playwright.dev/docs/test-reporters */
  reporter: 'html',
  /* Shared settings for all the projects below. See https://playwright.dev/docs/api/class-testoptions. */
  use: {
    baseURL: 'http://localhost:8888/',
    trace: 'on-first-retry',
    // Admin credentials for admin tests (override via env in CI if needed)
    adminURL: 'http://localhost:8888/wp-admin/',
    adminUser: 'admin',
    adminPassword: 'password',
    // Admin TOTP secret (base32) used by injectTOTP helper
    adminTOTPSecret: 'MRMHA4LTLFMEMPBOMEQES7RQJQ2CCMZX',
  },

  /* Configure projects for major browsers */
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'firefox',
      use: { ...devices['Desktop Firefox'] },
    },
    {
      name: 'webkit',
      use: { ...devices['Desktop Safari'] },
    },
  ],
});
