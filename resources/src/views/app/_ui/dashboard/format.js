// Fase B1 · Dashboard preview — formato de moneda/número ligado al tenant.
// Usa el símbolo de moneda del tenant (currentUser.currency), como el
// dashboard actual (`formatPriceWithSymbol`). No inventa nada.

export function makeFormatters(currencySymbol) {
  const sym = (currencySymbol || "").toString().trim();

  const number = (v, decimals = 0) => {
    const n = Number.isFinite(+v) ? +v : 0;
    try {
      return new Intl.NumberFormat(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
      }).format(n);
    } catch (e) {
      return n.toFixed(decimals);
    }
  };

  const money = (v, decimals = 2) => {
    const n = Number.isFinite(+v) ? +v : 0;
    const sign = n < 0 ? "−" : "";
    const body = number(Math.abs(n), decimals);
    return sym ? `${sign}${sym} ${body}` : `${sign}${body}`;
  };

  const percent = (v, decimals = 1) => `${number(v, decimals)} %`;

  // Formato compacto para cifras grandes: mantisa localizada (Intl → "1,6" /
  // "1.6" según locale) + sufijo de escala corto (k · M · B). Antepone el
  // símbolo de moneda y usa "−" para negativos, igual que `money`. Mantiene
  // 1 decimal por debajo de 100, 0 por encima, para que nunca sea muy ancho.
  const compactMoney = v => {
    const n = Number.isFinite(+v) ? +v : 0;
    const sign = n < 0 ? "−" : "";
    const abs = Math.abs(n);
    let val = abs;
    let unit = "";
    if (abs >= 1e9) { val = abs / 1e9; unit = "B"; }
    else if (abs >= 1e6) { val = abs / 1e6; unit = "M"; }
    else if (abs >= 1e3) { val = abs / 1e3; unit = "k"; }
    const digits = unit && val < 100 ? 1 : 0;
    const body = number(val, digits) + (unit ? ` ${unit}` : "");
    return sym ? `${sign}${sym} ${body}` : `${sign}${body}`;
  };

  return { number, money, percent, compactMoney, symbol: sym };
}
