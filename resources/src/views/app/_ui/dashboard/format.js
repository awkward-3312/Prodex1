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

  const compactMoney = v => {
    const n = Math.abs(Number.isFinite(+v) ? +v : 0);
    if (n >= 1e6) return money(v / 1e6, 1).replace(/\s?0(?=\D|$)/, "") + " M";
    if (n >= 1e3) return money(v / 1e3, 1) + " k";
    return money(v, 0);
  };

  return { number, money, percent, compactMoney, symbol: sym };
}
