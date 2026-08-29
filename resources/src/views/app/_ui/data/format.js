// -----------------------------------------------------------------------------
// px-next playground — locale / currency formatting helpers.
//
// PRODEX is Centroamérica-first. Number, date and money formatting depend on the
// TENANT's country and currency, NOT on a single hard-coded convention. These
// helpers exist so the playground can demonstrate the SAME component rendering
// correctly for HN / GT / CR / a USD tenant, etc.
// -----------------------------------------------------------------------------

export const COUNTRIES = {
  HN: { name: "Honduras",    locale: "es-HN", currency: "HNL", taxIdLabel: "RTN" },
  GT: { name: "Guatemala",   locale: "es-GT", currency: "GTQ", taxIdLabel: "NIT" },
  CR: { name: "Costa Rica",  locale: "es-CR", currency: "CRC", taxIdLabel: "Cédula jurídica" },
  NI: { name: "Nicaragua",   locale: "es-NI", currency: "NIO", taxIdLabel: "RUC" },
  SV: { name: "El Salvador", locale: "es-SV", currency: "USD", taxIdLabel: "NIT" },
  PA: { name: "Panamá",      locale: "es-PA", currency: "USD", taxIdLabel: "RUC" }
};

const CURRENCY_DISPLAY = {
  HNL: { symbol: "L",   decimals: 2 },
  GTQ: { symbol: "Q",   decimals: 2 },
  CRC: { symbol: "₡",   decimals: 0 },
  NIO: { symbol: "C$",  decimals: 2 },
  USD: { symbol: "US$", decimals: 2 }
};

export function money(amount, { country = "HN", currency = null } = {}) {
  const c = COUNTRIES[country] || COUNTRIES.HN;
  const cur = currency || c.currency;
  const disp = CURRENCY_DISPLAY[cur] || { symbol: cur + " ", decimals: 2 };
  let n;
  try {
    n = new Intl.NumberFormat(c.locale, {
      minimumFractionDigits: disp.decimals,
      maximumFractionDigits: disp.decimals
    }).format(Math.abs(amount));
  } catch (e) {
    n = Math.abs(amount).toFixed(disp.decimals);
  }
  const sign = amount < 0 ? "−" : "";
  return `${sign}${disp.symbol} ${n}`;
}

export function number(value, { country = "HN", decimals = 0 } = {}) {
  const c = COUNTRIES[country] || COUNTRIES.HN;
  try {
    return new Intl.NumberFormat(c.locale, {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals
    }).format(value);
  } catch (e) {
    return Number(value).toFixed(decimals);
  }
}

export function percent(value, { country = "HN", decimals = 1 } = {}) {
  const c = COUNTRIES[country] || COUNTRIES.HN;
  try {
    return new Intl.NumberFormat(c.locale, {
      style: "percent",
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals
    }).format(value / 100);
  } catch (e) {
    return `${value.toFixed(decimals)} %`;
  }
}

export function date(iso, { country = "HN", withTime = false } = {}) {
  const c = COUNTRIES[country] || COUNTRIES.HN;
  const d = iso instanceof Date ? iso : new Date(iso);
  const opts = withTime
    ? { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }
    : { day: "2-digit", month: "short", year: "numeric" };
  try {
    return new Intl.DateTimeFormat(c.locale, opts).format(d);
  } catch (e) {
    return d.toISOString().slice(0, 10);
  }
}

export function relTime(iso, { country = "HN" } = {}) {
  const c = COUNTRIES[country] || COUNTRIES.HN;
  const diffMs = Date.now() - new Date(iso).getTime();
  const mins = Math.round(diffMs / 60000);
  const rtf = (() => { try { return new Intl.RelativeTimeFormat(c.locale, { numeric: "auto" }); } catch (e) { return null; } })();
  if (!rtf) return `hace ${mins} min`;
  if (Math.abs(mins) < 60) return rtf.format(-mins, "minute");
  const hrs = Math.round(mins / 60);
  if (Math.abs(hrs) < 24) return rtf.format(-hrs, "hour");
  return rtf.format(-Math.round(hrs / 24), "day");
}
