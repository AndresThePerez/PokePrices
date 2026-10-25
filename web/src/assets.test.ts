import { describe, expect, it } from 'vitest';
import rawIndexHtml from '../index.html?raw';

// tsconfig.app.json carries no Node types, hence the untyped specifier and the narrow cast.
const NODE_FS = 'node:fs';
const { readFileSync, existsSync } = (await import(/* @vite-ignore */ NODE_FS)) as {
  readonly readFileSync: (path: string, encoding: 'utf8') => string;
  readonly existsSync: (path: string) => boolean;
};
// Prettier wraps long tags over several lines; collapse whitespace so the tags compare exactly.
const indexHtml = rawIndexHtml.replace(/\s+/g, ' ');
const testFile = import.meta.url;
const publicPath = (name: string): string => new URL(`../public/${name}`, testFile).pathname;

const ICON_LINE = '<link rel="icon" type="image/svg+xml" href="/favicon.svg" />';
const OG_TITLE = '<meta property="og:title" content="PokéPrices" />';
const OG_DESCRIPTION =
  '<meta property="og:description" content="Pokémon card prices at the daily close, with the source and as-of date on every price." />';
const DESCRIPTION =
  '<meta name="description" content="Pokémon card prices at the daily close from TCGplayer via TCGCSV, with the source and as-of date on every price." />';

const ROBOTS_TXT = [
  'User-agent: *',
  'Allow: /',
  'Disallow: /account',
  'Disallow: /alerts',
  'Disallow: /watchlist',
  'Disallow: /portfolio',
  'Disallow: /sign-in',
  'Disallow: /sign-up',
  'Disallow: /password/',
  '',
].join('\n');

describe('head metadata in index.html', () => {
  const headEnd = indexHtml.indexOf('</head>');
  const scriptEnd = indexHtml.indexOf('</script>') + '</script>'.length;

  it.each([
    ['the SVG favicon link', ICON_LINE],
    ['the og:title meta', OG_TITLE],
    ['the og:description meta', OG_DESCRIPTION],
    ['the description meta', DESCRIPTION],
  ])('carries %s exactly once, after the pre-paint script and before </head>', (_name, line) => {
    expect(indexHtml.split(line)).toHaveLength(2);
    const at = indexHtml.indexOf(line);
    expect(at).toBeGreaterThan(scriptEnd);
    expect(at).toBeLessThan(headEnd);
  });

  it('keeps the pre-paint script as the first script, ahead of the module script', () => {
    expect(indexHtml.indexOf('<script>')).toBeLessThan(indexHtml.indexOf('/src/main.tsx'));
    expect(indexHtml.indexOf('<script>')).toBeLessThan(indexHtml.indexOf(ICON_LINE));
  });

  it('defers og:image (no og:image meta yet)', () => {
    expect(indexHtml).not.toMatch(/og:image/);
  });
});

describe('static artwork under public/', () => {
  it.each([
    ['favicon.svg', 1024],
    ['og-image.svg', 6144],
  ])('%s exists and is within %i bytes', (name, limit) => {
    expect(existsSync(publicPath(name))).toBe(true);
    const text = readFileSync(publicPath(name), 'utf8');
    expect(new TextEncoder().encode(text).length).toBeLessThanOrEqual(limit);
    expect(text).toContain('<svg');
  });

  it('robots.txt exists, is the exact crawler block (LF, trailing newline), small, plain text with no Sitemap', () => {
    expect(existsSync(publicPath('robots.txt'))).toBe(true);
    const text = readFileSync(publicPath('robots.txt'), 'utf8');
    expect(text).toBe(ROBOTS_TXT);
    expect(new TextEncoder().encode(text).length).toBeLessThanOrEqual(1024);
    expect(text).not.toContain('Sitemap:');
    expect(text).not.toContain('<');
  });

  it('favicon is a 32x32 plum square with a white P in a text element and no embedded font', () => {
    const svg = readFileSync(publicPath('favicon.svg'), 'utf8');
    expect(svg).toContain('viewBox="0 0 32 32"');
    expect(svg).toContain('#a21caf');
    expect(svg).toMatch(/<text[^>]*>P<\/text>/);
    expect(svg).not.toMatch(/@font-face|<image|base64/);
  });

  it('og image is 1200x630 with the wordmark and the daily-close line, and no live claims', () => {
    const svg = readFileSync(publicPath('og-image.svg'), 'utf8');
    expect(svg).toContain('viewBox="0 0 1200 630"');
    expect(svg).toContain('#faf7f2');
    expect(svg).toContain('PokéPrices');
    expect(svg).toContain('Pokémon card prices at the daily close');
    expect(svg).not.toMatch(/\$\d|live|real-time|today|\bnow\b|24h|<image|base64|@font-face/i);
  });
});
