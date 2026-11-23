// Edge cases of index.html's link-preview tags, read the way an unfurler reads them (a parsed
// document, not a pattern over the text): one og:image, one twitter:card and one canonical link;
// every absolute address on the site's own origin; the image a real PNG whose header matches the
// declared size; nothing pointing at the SVG; no foreign port or local address.
import { describe, expect, it } from 'vitest';
import rawIndexHtml from '../index.html?raw';

// tsconfig.app.json carries no Node types, hence the untyped specifier and the narrow cast.
const NODE_FS = 'node:fs';
const { readFileSync, existsSync } = (await import(/* @vite-ignore */ NODE_FS)) as {
  readonly readFileSync: (path: string) => Uint8Array;
  readonly existsSync: (path: string) => boolean;
};
const testFile = import.meta.url;
const publicPath = (name: string): string => new URL(`../public/${name}`, testFile).pathname;

const SITE = 'https://pokeprices.andrestheperez.com';

const parsed = new DOMParser().parseFromString(rawIndexHtml, 'text/html');

function metas(selector: string): HTMLMetaElement[] {
  return Array.from(parsed.querySelectorAll<HTMLMetaElement>(selector));
}

function onlyContent(selector: string): string {
  const found = metas(selector);
  expect(found, selector).toHaveLength(1);
  return found[0]?.getAttribute('content') ?? '';
}

describe('index.html as an unfurler parses it', () => {
  it('carries one og:image, one twitter:card and one canonical link, all in the head', () => {
    for (const selector of [
      'meta[property="og:image"]',
      'meta[property="og:image:width"]',
      'meta[property="og:image:height"]',
      'meta[name="twitter:card"]',
      'link[rel="canonical"]',
    ]) {
      const found = Array.from(parsed.querySelectorAll(selector));
      expect(found, selector).toHaveLength(1);
      expect(parsed.head.contains(found[0] ?? null), selector).toBe(true);
    }
    // No second image under another spelling, and no twitter:image that could disagree with it.
    expect(
      metas('meta[property="og:image:url"], meta[property="og:image:secure_url"]'),
    ).toHaveLength(0);
    expect(metas('meta[name="twitter:image"], meta[property="twitter:image"]')).toHaveLength(0);
    expect(onlyContent('meta[name="twitter:card"]')).toBe('summary_large_image');
  });

  it('puts the image and the canonical on the same https host, the site origin', () => {
    const image = new URL(onlyContent('meta[property="og:image"]'));
    const canonical = new URL(
      parsed.querySelector('link[rel="canonical"]')?.getAttribute('href') ?? '',
    );
    expect(image.protocol).toBe('https:');
    expect(canonical.protocol).toBe('https:');
    expect(image.origin).toBe(SITE);
    expect(canonical.origin).toBe(image.origin);
    expect(image.port).toBe('');
    expect(image.search + image.hash).toBe('');
    expect(image.pathname.endsWith('.png')).toBe(true);
    expect(canonical.href).toBe(`${SITE}/`);
  });

  it('names a file under public/ that is a PNG whose header matches the declared 1200 by 630', () => {
    const image = new URL(onlyContent('meta[property="og:image"]'));
    const name = image.pathname.slice(1);
    expect(name).not.toContain('/');
    expect(existsSync(publicPath(name))).toBe(true);
    const bytes = readFileSync(publicPath(name));
    expect(Array.from(bytes.slice(0, 8))).toEqual([137, 80, 78, 71, 13, 10, 26, 10]);
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    // The first chunk is IHDR, 13 bytes long.
    expect(view.getUint32(8)).toBe(13);
    expect(String.fromCharCode(...bytes.slice(12, 16))).toBe('IHDR');
    const width = view.getUint32(16);
    const height = view.getUint32(20);
    expect(String(width)).toBe(onlyContent('meta[property="og:image:width"]'));
    expect(String(height)).toBe(onlyContent('meta[property="og:image:height"]'));
    expect([width, height]).toEqual([1200, 630]);
    // Ends with the IEND chunk: a whole file, not a truncated one.
    expect(String.fromCharCode(...bytes.slice(bytes.byteLength - 8, bytes.byteLength - 4))).toBe(
      'IEND',
    );
  });

  it('points nothing at the SVG, and every absolute address is on the site origin', () => {
    expect(rawIndexHtml).not.toMatch(/og-image\.svg/);
    const addresses = rawIndexHtml.match(/\b[a-z][a-z0-9+.-]*:\/\/[^\s"'<>)]+/gi) ?? [];
    expect(addresses.length).toBeGreaterThan(0);
    for (const address of addresses) {
      expect(new URL(address).origin).toBe(SITE);
    }
    for (const element of Array.from(parsed.querySelectorAll('[href], [src], [content]'))) {
      for (const name of ['href', 'src', 'content']) {
        const value = element.getAttribute(name);
        if (value === null || !value.startsWith('//')) continue;
        throw new Error(`protocol-relative ${name}: ${value}`);
      }
    }
  });

  it('writes no port but 8095 and no local or private address', () => {
    const ports = Array.from(rawIndexHtml.matchAll(/:(\d{2,5})\b/g), (match) => match[1]);
    for (const port of ports) expect(port).toBe('8095');
    expect(rawIndexHtml).not.toMatch(
      /localhost|127\.0\.0\.1|0\.0\.0\.0|\b10\.\d+\.\d+\.\d+|\b192\.168\.|\b172\.(1[6-9]|2\d|3[01])\.|\b100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|\.home\b|\.local\b|\.ts\.net\b/,
    );
  });

  it('keeps the preview text honest and the name spelt PokéPrices', () => {
    const alt = onlyContent('meta[property="og:image:alt"]');
    expect(alt).toContain('PokéPrices');
    for (const text of [alt, parsed.title]) {
      expect(text).not.toMatch(/\blive\b|real-?time|\btoday\b|\bnow\b|instant/i);
      expect(text).not.toMatch(/Pok[eE]Prices/);
    }
  });
});
