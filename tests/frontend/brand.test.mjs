import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { contrast, contrastText } from '../../resources/src/utils/brand-colors.mjs';

const brand = JSON.parse(fs.readFileSync(new URL('../../resources/brand/prodex.json', import.meta.url)));

test('official palette and generated adapters agree', () => {
  assert.deepEqual(brand.colors, { ink: '#142B3A', aqua: '#22D6C5', white: '#FFFFFF', black: '#000000' });
  execFileSync(process.execPath, ['scripts/generate-brand.mjs', '--check']);
});

test('brand combinations preserve AA and never put white text on Aqua', () => {
  assert.ok(contrast(brand.colors.ink, brand.colors.aqua) >= 7);
  assert.ok(contrast(brand.colors.white, brand.colors.ink) >= 7);
  assert.ok(contrast(brand.colors.white, brand.colors.aqua) < 3);
  for (const color of [brand.colors.ink, brand.colors.aqua, '#663399', '#fff', '#777777', '#f59e0b']) {
    assert.ok(contrast(color, contrastText(color, brand.colors)) >= 4.5, color);
  }
  assert.equal(contrastText(brand.colors.aqua, brand.colors), brand.colors.ink);
});

test('official source assets keep their original bytes and dimensions', () => {
  const originals = {
    logo: ['e6c5695e10937e6655bdb4a0f606a88dddec602fba00b5d4e750b3ff04371db9', 4096, 989],
    white: ['3ab8d08e25028bfca557c4eb04fc16e381f18202bbc4a78d04009cb17d34e9b5', 4096, 989],
    black: ['6c2f2e2b5c14a261383ecbaff3139d84b21ca3822d932d2953183c14b1482937', 4096, 989],
    symbol: ['53b650972c61fc4c38bae6c56cd1f4f2688b103f3786b288756512be45bff3ff', 2048, 2048],
    icon: ['7509c43743598ff2f708b9b973759d39eb2aa184ba3f0b9096b4964b4087569a', 1024, 1024],
  };
  for (const [variant, [hash, width, height]] of Object.entries(originals)) {
    const bytes = fs.readFileSync(`public/${brand.assets[variant]}`);
    assert.equal(crypto.createHash('sha256').update(bytes).digest('hex'), hash, variant);
    assert.equal(bytes.readUInt32BE(16), width);
    assert.equal(bytes.readUInt32BE(20), height);
  }
});
