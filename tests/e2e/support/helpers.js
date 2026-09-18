const { expect } = require('@playwright/test');
const env = require('./env');

/**
 * Espera a que el SPA salga del loader inicial (#loading_wrap oculto) y pinte contenido.
 * Ojo: Vue 2 REEMPLAZA el nodo #app al montar, así que no se puede usar #app como señal.
 */
async function waitForApp(page) {
  await page.waitForFunction(() => {
    const w = document.getElementById('loading_wrap');
    return (!w || getComputedStyle(w).display === 'none') && document.body.innerText.trim().length > 40;
  }, undefined, { timeout: 30_000 });
}

/** Login por la UI real (Blade + login.min.js). */
async function login(page, email, password) {
  await page.goto('/login');
  await page.locator('#email').fill(email);
  await page.locator('#password').fill(password);
  await page.locator('#login_submit_btn').click();
}

/** Estado HTTP de un endpoint /api usando la sesión del navegador (Node no resuelve *.localhost). */
async function apiStatus(page, url) {
  return page.evaluate(async (u) => {
    // La API del SPA usa la cookie de Passport (laravel_token) y exige el eco del XSRF-TOKEN, incluso en GET.
    const xsrf = decodeURIComponent((document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch(u, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf } });
    return r.status;
  }, url);
}

/** Abre el POS y selecciona el almacén demo para que aparezca el catálogo. */
async function openPos(page, { selectWarehouse = true } = {}) {
  await page.goto('/app/pos');
  await expect(page.locator('.pos-wh-trigger')).toBeVisible({ timeout: 30_000 });
  if (selectWarehouse) {
    await page.locator('.pos-wh-trigger').click();
    await page.getByText('Default Warehouse').click();
    await expect(page.locator('.pos-shell-product-card').first()).toBeVisible({ timeout: 30_000 });
  }
}

const registerPill = (page) => page.locator('.pos-shell-register-pill');

/** Abre la caja si está cerrada (usa la caja física E2E-01 que crea provision.php). */
async function ensureRegisterOpen(page) {
  await expect(registerPill(page)).toBeVisible({ timeout: 20_000 });
  if (/is-open/.test((await registerPill(page).getAttribute('class')) || '')) return;
  await registerPill(page).click();
  const modal = page.locator('#OpenRegisterModal');
  await expect(modal).toBeVisible();
  await modal.locator('select').nth(1).selectOption({ index: 1 });
  await modal.getByRole('button', { name: 'Abrir caja' }).click();
  await expect(registerPill(page)).toHaveClass(/is-open/, { timeout: 20_000 });
}

/** Cierra la caja si está abierta (limpieza; deja el tenant demo en estado neutro). */
async function ensureRegisterClosed(page) {
  await expect(registerPill(page)).toBeVisible({ timeout: 20_000 });
  if (/is-closed/.test((await registerPill(page).getAttribute('class')) || '')) return;
  await registerPill(page).click();
  const modal = page.locator('#CloseRegisterModal');
  await expect(modal).toBeVisible();
  await modal.getByRole('button', { name: 'Cerrar caja' }).click();
  await expect(registerPill(page)).toHaveClass(/is-closed/, { timeout: 20_000 });
}

module.exports = { env, waitForApp, login, apiStatus, openPos, registerPill, ensureRegisterOpen, ensureRegisterClosed };
