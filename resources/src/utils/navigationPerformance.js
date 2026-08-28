import NProgress from 'nprogress';

let lastSyncedLocale = null;

function normalizeUrl(url) {
  return String(url || '').replace(/^\/+/, '').split('?')[0];
}

function localeFromRequest(config) {
  const data = config && config.data;
  if (!data) return null;
  if (typeof data === 'string') {
    try {
      const parsed = JSON.parse(data);
      return parsed && parsed.locale ? String(parsed.locale) : null;
    } catch (e) {
      return null;
    }
  }
  return data.locale ? String(data.locale) : null;
}

export function installNavigationPerformance(axios, router) {
  if (!axios || !router || typeof window === 'undefined' || window.__prodexNavigationPerformanceInstalled) return;
  window.__prodexNavigationPerformanceInstalled = true;

  axios.interceptors.request.use(config => {
    const method = String((config && config.method) || 'get').toLowerCase();
    if (method !== 'post' || normalizeUrl(config && config.url) !== 'sync-locale') return config;

    const locale = localeFromRequest(config);
    config.meta = Object.assign({}, config.meta || {}, {
      skipInitialLoader: true,
      prodexLocaleSync: true,
      prodexLocale: locale,
    });

    if (locale && locale === lastSyncedLocale) {
      config.meta.prodexLocaleSyncSkipped = true;
      config.adapter = () => Promise.resolve({
        data: { success: true, skipped: true },
        status: 204,
        statusText: 'No Content',
        headers: {},
        config,
        request: null,
      });
    }

    return config;
  });

  axios.interceptors.response.use(response => {
    const meta = response && response.config && response.config.meta;
    if (meta && meta.prodexLocaleSync && !meta.prodexLocaleSyncSkipped && meta.prodexLocale) {
      lastSyncedLocale = String(meta.prodexLocale);
    }
    return response;
  }, error => Promise.reject(error));

  router.afterEach(() => {
    NProgress.done();
  });
}
