import axios from 'axios';

function isGeneralSettingsUpdate(config) {
  if (!config) return false;
  const method = String(config.method || '').toLowerCase();
  if (method !== 'put' && method !== 'patch') return false;

  const rawUrl = String(config.url || '').replace(/^\/+/, '');
  return /^settings\/\d+(?:\?.*)?$/.test(rawUrl);
}

function needsSpanishDefault(value) {
  if (value === null || value === undefined) return true;
  const normalized = String(value).trim().toLowerCase();
  return normalized === '' || normalized === 'null' || normalized === 'undefined';
}

function normalizeLanguage(config) {
  if (!isGeneralSettingsUpdate(config)) return config;

  const data = config.data;
  if (!data) return config;

  if (typeof FormData !== 'undefined' && data instanceof FormData) {
    const current = data.get('default_language');
    if (needsSpanishDefault(current)) data.set('default_language', 'es');
    return config;
  }

  if (typeof data === 'object' && !Array.isArray(data)) {
    if (needsSpanishDefault(data.default_language)) data.default_language = 'es';
  }

  return config;
}

export function installSpanishSettingsRequestGuard() {
  if (typeof window === 'undefined') return;
  if (window.__prodexSpanishSettingsRequestGuard) return;

  axios.interceptors.request.use(
    config => normalizeLanguage(config),
    error => Promise.reject(error)
  );

  window.__prodexSpanishSettingsRequestGuard = true;
}
