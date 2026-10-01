const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

for (const width of [1440, 768, 390]) {
  test(`PRODEX login: logo, contrast and keyboard focus at ${width}px @smoke`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/login');
    const logo = page.locator('.tenant-login-logo');
    await expect(logo).toBeVisible();
    expect(await logo.evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await expect(page.locator('#login_submit_btn')).toHaveCSS('background-color', 'rgb(34, 214, 197)');
    await expect(page.locator('#login_submit_btn')).toHaveCSS('color', 'rgb(20, 43, 58)');
    await page.locator('#email').focus();
    await page.keyboard.press('Tab');
    await expect(page.locator('#password')).toBeFocused();
    await expect(page.locator('#password').locator('..')).toHaveCSS('box-shadow', /0px 0px 0px 3px/);
  });
}

test.describe('PRODEX platform fallbacks', () => {
  test.use({ storageState: path.join(env.authDir, 'admin.json') });

  test('shell loads an intact logo and shared official tokens @smoke', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForApp(page);
    const logo = page.locator('.pxn-shell__brand-logo');
    await expect(logo).toBeVisible();
    expect(await logo.evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);
    const palette = await page.evaluate(() => {
      const css = getComputedStyle(document.documentElement);
      return ['--prodex-ink', '--prodex-aqua', '--primary-color'].map(name => css.getPropertyValue(name).trim().toUpperCase());
    });
    expect(palette).toEqual(['#142B3A', '#22D6C5', '#142B3A']);
    await page.evaluate(() => document.querySelector('.pxn-shell__brand').focus());
    await expect(page.locator('.pxn-shell__brand')).toBeFocused();
  });
});
