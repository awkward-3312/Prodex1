(function () {
  'use strict';

  if (window.__prodexTransferReceiptIdempotencyInstalled) return;
  window.__prodexTransferReceiptIdempotencyInstalled = true;

  function transferId(url) {
    var match = String(url || '').match(/\/api\/transfer-logistics\/(\d+)\/receive(?:\?|$)/i);
    return match ? match[1] : '';
  }

  function storageKey(id) {
    return 'prodex.transfer.receipt.request.' + id;
  }

  function randomToken() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return 'RCV-' + window.crypto.randomUUID();
    }
    if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
      var bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      return 'RCV-' + Array.prototype.map.call(bytes, function (b) {
        return b.toString(16).padStart(2, '0');
      }).join('');
    }
    return 'RCV-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + '-' + Math.random().toString(36).slice(2);
  }

  function getOrCreate(id) {
    var key = storageKey(id);
    try {
      var existing = window.sessionStorage.getItem(key);
      if (existing) return existing;
      var created = randomToken();
      window.sessionStorage.setItem(key, created);
      return created;
    } catch (e) {
      return randomToken();
    }
  }

  function clear(id) {
    if (!id) return;
    try { window.sessionStorage.removeItem(storageKey(id)); } catch (e) {}
  }

  function install() {
    if (!window.axios || window.__prodexTransferReceiptAxiosIdempotency) return false;
    window.__prodexTransferReceiptAxiosIdempotency = true;

    window.axios.interceptors.request.use(function (config) {
      var id = transferId(config && config.url);
      if (!id || String(config.method || 'get').toLowerCase() !== 'post') return config;

      config.data = config.data || {};
      if (!config.data.request_token) config.data.request_token = getOrCreate(id);
      config.__prodexTransferReceiptId = id;
      return config;
    });

    window.axios.interceptors.response.use(function (response) {
      var id = response && response.config && (response.config.__prodexTransferReceiptId || transferId(response.config.url));
      if (id) clear(id);
      return response;
    }, function (error) {
      var config = error && error.config;
      var id = config && (config.__prodexTransferReceiptId || transferId(config.url));
      var status = error && error.response && Number(error.response.status || 0);

      // A definite 4xx means the server rejected the operation before commit; allow
      // the edited form to submit as a new physical attempt. For network failures or
      // 5xx we intentionally keep the token because the client cannot know whether
      // the DB commit happened before connectivity was lost.
      if (id && status >= 400 && status < 500) clear(id);
      return Promise.reject(error);
    });

    return true;
  }

  if (!install()) {
    var attempts = 0;
    var timer = window.setInterval(function () {
      attempts++;
      if (install() || attempts > 40) window.clearInterval(timer);
    }, 100);
  }
})();
