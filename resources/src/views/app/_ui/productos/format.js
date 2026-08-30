// Fase B2 · Productos — formato monetario/numérico ligado al tenant.
// Reutiliza los helpers presentacionales existentes (utils/priceFormat.js):
// respeta la precisión configurada (2 ó 3 decimales) y el formato de miles/
// decimal del tenant, y antepone el símbolo de moneda de currentUser. Igual
// que el listado real; no altera cálculos ni valores almacenados.

import {
  formatPriceDisplay,
  getPriceDecimals,
  getPriceFormatSetting
} from "@/utils/priceFormat";

export function makeProductFormatters({ currency, store } = {}) {
  const symbol = (currency || "").toString().trim();
  const decimals = getPriceDecimals({ store });
  const formatKey = getPriceFormatSetting({ store });

  const money = (v, { withSymbol = true } = {}) => {
    if (v == null || v === "") return "—";
    const n = Number(v);
    if (!Number.isFinite(n)) return "—";
    const body = formatPriceDisplay(n, decimals, formatKey);
    if (!withSymbol || !symbol) return body;
    return `${symbol} ${body}`;
  };

  const number = (v, dec = 0) => {
    const n = Number(v);
    if (!Number.isFinite(n)) return "—";
    try {
      return new Intl.NumberFormat(undefined, {
        minimumFractionDigits: dec,
        maximumFractionDigits: dec
      }).format(n);
    } catch (e) {
      return n.toFixed(dec);
    }
  };

  return { money, number, symbol, decimals };
}
