// Price formatting helper for frontend display (e.g., POS)
// This helper is purely presentational and does NOT affect calculations or stored values.

// Internal map of supported formats:
// - 'comma_dot'   => 1,234.56 (thousand ',', decimal '.')
// - 'dot_comma'   => 1.234,56 (thousand '.', decimal ',')
// - 'space_comma' => 1 234,56 (thousand ' ', decimal ',')
export const PRICE_FORMATS = {
  comma_dot: { thousands: ',', decimal: '.' },
  dot_comma: { thousands: '.', decimal: ',' },
  space_comma: { thousands: ' ', decimal: ',' },
};

// Normalize a stored value/label into one of our internal keys
export function normalizePriceFormatKey(input) {
  if (!input) return null;
  const raw = String(input).trim();

  // Direct key
  if (PRICE_FORMATS[raw]) {
    return raw;
  }

  // Allow matching by exact label text (for safety if something stored the label)
  const labelMap = {
    "1,234.56 (thousand , decimal .)": "comma_dot",
    "1.234,56 (thousand . decimal ,)": "dot_comma",
    "1 234,56 (thousand space, decimal ,)": "space_comma",
  };

  if (labelMap[raw]) {
    return labelMap[raw];
  }

  return null;
}

// Format a numeric value according to the selected price format.
// - value: number or numeric-like
// - decimals: integer number of decimal places
// - formatKey: one of PRICE_FORMATS keys or label text; if falsy/unknown, falls back to legacy formatting
export function formatPriceDisplay(value, decimals = 2, formatKey = null) {
  const d = Number.isInteger(decimals) ? decimals : 0;
  const n = Number(value);
  const safe = Number.isFinite(n) ? n : 0;

  const key = normalizePriceFormatKey(formatKey);

  // Fallback: keep current/default behavior (en-US locale style)
  if (!key) {
    try {
      return safe.toLocaleString('en-US', {
        minimumFractionDigits: d,
        maximumFractionDigits: d,
      });
    } catch (e) {
      const fixed = safe.toFixed(d);
      const parts = fixed.split('.');
      const intPart = parts[0];
      const fracPart = parts[1] || '';
      const withCommas = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
      return d > 0 ? `${withCommas}.${fracPart}` : withCommas;
    }
  }

  const cfg = PRICE_FORMATS[key];
  const fixed = safe.toFixed(d);
  let [intPart, fracPart = ''] = fixed.split('.');

  // Thousands grouping
  const re = /\B(?=(\d{3})+(?!\d))/g;
  intPart = intPart.replace(re, cfg.thousands);

  if (d <= 0) {
    return intPart;
  }

  if (fracPart.length < d) {
    fracPart = fracPart.padEnd(d, '0');
  }

  return `${intPart}${cfg.decimal}${fracPart}`;
}

// Get the selected price format from:
// - explicit settings object (preferred)
// - Vuex store getter (getPriceFormat) - from get_user_auth API
// This helper never throws; it returns null if no valid format is found.
export function getPriceFormatSetting({ settings = null, store = null } = {}) {
  // 1) Explicit settings object (e.g., System Settings API payload)
  if (settings && settings.price_format) {
    const key = normalizePriceFormatKey(settings.price_format);
    if (key) {
      return key;
    }
  }

  // 2) Vuex store getter (from get_user_auth API)
  if (store && typeof store.getters === 'object' && store.getters.getPriceFormat) {
    try {
      const priceFormat = store.getters.getPriceFormat;
      const key = normalizePriceFormatKey(priceFormat);
      if (key) {
        return key;
      }
    } catch (e) {
      // ignore
    }
  }

  // No valid setting => use default behavior (caller should treat null as "legacy" formatting)
  return null;
}

// Resolve the configured monetary precision (2 or 3) from:
// - explicit settings object (System Settings API payload: enable_3_decimal_pricing)
// - Vuex store getter (getPriceDecimals) - from get_user_auth API
// - localStorage cache (set at login) as an offline-friendly fallback
// Always returns 2 or 3; defaults to 2.
export function getPriceDecimals({ settings = null, store = null } = {}) {
  // 1) Explicit settings object
  if (settings && typeof settings.enable_3_decimal_pricing !== 'undefined') {
    const enabled = settings.enable_3_decimal_pricing === true
      || settings.enable_3_decimal_pricing === 1
      || settings.enable_3_decimal_pricing === '1';
    return enabled ? 3 : 2;
  }

  // 2) Vuex store getter
  if (store && typeof store.getters === 'object' && store.getters.getPriceDecimals) {
    try {
      const n = parseInt(store.getters.getPriceDecimals, 10);
      if (n === 3) return 3;
      if (n === 2) return 2;
    } catch (e) {
      // ignore
    }
  }

  // 3) localStorage cache
  if (typeof window !== 'undefined' && window.localStorage) {
    try {
      const cached = parseInt(window.localStorage.getItem('app_price_decimals'), 10);
      if (cached === 3) return 3;
    } catch (e) {
      // ignore
    }
  }

  return 2;
}

// Cache the monetary precision (2 or 3) into localStorage for quick frontend access
export function cachePriceDecimals(decimals) {
  const n = (parseInt(decimals, 10) === 3) ? 3 : 2;
  if (typeof window === 'undefined' || !window.localStorage) return;
  try {
    window.localStorage.setItem('app_price_decimals', String(n));
  } catch (e) {
    // ignore quota or storage errors
  }
}

// Cache a selected price format key into localStorage for quick frontend access
export function cachePriceFormat(formatKey) {
  const key = normalizePriceFormatKey(formatKey);
  if (!key) return;
  if (typeof window === 'undefined' || !window.localStorage) return;
  try {
    window.localStorage.setItem('app_price_format', key);
  } catch (e) {
    // ignore quota or storage errors
  }
}

