// Configuración oficial de E2E de PRODEX (Playwright). Se ejecuta con `npm run test:e2e`.
// Contra un tenant DEMO local/CI aislado (ver docs/architecture/FRONTEND_MODERNIZATION_SAFETY_NET.md). Nunca contra producción.
const path = require('path');
const { defineConfig, devices } = require('@playwright/test');
const env = require('./support/env');

const isCI = !!process.env.CI;

if (/prodex\.(com|app)|\.prodex\./i.test(env.baseURL) && process.env.E2E_ALLOW_REMOTE !== '1') {
  throw new Error(`E2E_BASE_URL (${env.baseURL}) parece un entorno real. Los E2E solo corren contra tenants demo locales o de CI.`);
}

module.exports = defineConfig({
  testDir: path.join(__dirname, 'specs'),
  outputDir: path.join(__dirname, '.artifacts', 'test-results'),
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: isCI ? 2 : 2,
  retries: isCI ? 1 : 0,
  forbidOnly: isCI,
  reporter: isCI
    ? [['list'], ['html', { open: 'never', outputFolder: path.join(__dirname, '.artifacts', 'report') }]]
    : [['list']],
  use: {
    baseURL: env.baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    locale: 'es-ES',
    timezoneId: 'America/Tegucigalpa',
    viewport: { width: 1440, height: 900 },
    ignoreHTTPSErrors: true,
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.js/ },
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
      dependencies: ['setup'],
      testIgnore: /auth\.setup\.js/,
    },
  ],
  webServer: env.startServer
    ? {
        command: 'bash tests/e2e/scripts/serve.sh',
        cwd: path.join(__dirname, '..', '..'),
        // robots.txt es un archivo estático de public/: responde 200 en cuanto PHP atiende (Node no resuelve *.localhost).
        url: `http://127.0.0.1:${env.port}/robots.txt`,
        ignoreHTTPSErrors: true,
        reuseExistingServer: true,
        timeout: 60_000,
        env: { E2E_PORT: env.port },
      }
    : undefined,
});
