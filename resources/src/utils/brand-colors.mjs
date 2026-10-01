export function luminance(hex) {
  let value = hex.replace('#', '');
  if (value.length === 3) value = [...value].map(c => c + c).join('');
  return [0.2126, 0.7152, 0.0722].reduce((sum, weight, index) => {
    const channel = parseInt(value.slice(index * 2, index * 2 + 2), 16) / 255;
    return sum + weight * (channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4);
  }, 0);
}

export function contrast(a, b) {
  const values = [luminance(a), luminance(b)].sort((x, y) => x - y);
  return (values[1] + 0.05) / (values[0] + 0.05);
}

// Official Aqua stays unchanged: change the foreground, never the brand hue.
export function contrastText(background, palette) {
  if (contrast(background, palette.white) >= 4.5) return palette.white;
  if (contrast(background, palette.ink) >= 4.5) return palette.ink;
  return palette.black;
}
