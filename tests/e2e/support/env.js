// Lectura central de la configuración E2E. Todo llega por variables de entorno; no hay valores secretos aquí.
// Para uso local, `scripts/setup.sh` guarda los secretos generados en tests/e2e/.env.e2e.local (ignorado por git).
const fs = require('fs');
const path = require('path');

const localFile = path.join(__dirname, '..', '.env.e2e.local');
if (fs.existsSync(localFile)) {
  for (const line of fs.readFileSync(localFile, 'utf8').split('\n')) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
    if (m && process.env[m[1]] === undefined) process.env[m[1]] = m[2];
  }
}

const port = process.env.E2E_PORT || '8000';
const subdomain = process.env.E2E_TENANT_SUBDOMAIN || 'e2e';

module.exports = {
  port,
  subdomain,
  baseURL: process.env.E2E_BASE_URL || `http://${subdomain}.localhost:${port}`,
  adminEmail: process.env.E2E_ADMIN_EMAIL || 'e2e-admin@example.test',
  adminPassword: process.env.E2E_ADMIN_PASSWORD || '',
  restrictedEmail: process.env.E2E_RESTRICTED_EMAIL || 'e2e-limited@example.test',
  restrictedPassword: process.env.E2E_RESTRICTED_PASSWORD || '',
  // 0 => no lanzar el servidor PHP; se asume uno ya activo en baseURL.
  startServer: process.env.E2E_START_SERVER !== '0',
  authDir: path.join(__dirname, '..', '.artifacts', 'auth'),
  requireSecrets() {
    const missing = ['E2E_ADMIN_PASSWORD', 'E2E_RESTRICTED_PASSWORD'].filter((k) => !process.env[k]);
    if (missing.length) {
      throw new Error(
        `Faltan variables E2E: ${missing.join(', ')}. Ejecuta \`npm run e2e:setup\` (las genera) o expórtalas.`
      );
    }
  },
};
