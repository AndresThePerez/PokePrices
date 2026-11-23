// The link-preview tags of index.html: a PNG og:image of 1200 × 630 under public/, a large-image
// twitter card and a canonical link, all absolute on the site's public address.
import { describe, expect, it } from 'vitest';
import rawIndexHtml from '../index.html?raw';

// tsconfig.app.json carries no Node types, hence the untyped specifier and the narrow cast.
const NODE_FS = 'node:fs';
const { readFileSync, existsSync } = (await import(/* @vite-ignore */ NODE_FS)) as {
  readonly readFileSync: (path: string) => Uint8Array;
  readonly existsSync: (path: string) => boolean;
};
// Through a variable: Vite rewrites `new URL(<literal>, import.meta.url)` as an asset address.
const testFile = import.meta.url;
const publicPath = (name: string): string => new URL(`../public/${name}`, testFile).pathname;

const SITE = 'https://pokeprices.andrestheperez.com';

const head = rawIndexHtml.slice(rawIndexHtml.indexOf('<head>'), rawIndexHtml.indexOf('</head>'));
const headTags =
  head.replace(/<script>[\s\S]*?<\/script>/, '').match(/<(?:link|meta)\b[^>]*>/g) ?? [];

const attributeValue = (tag: string, name: string): string | undefined =>
  new RegExp(`\\s${name}="([^"]*)"`).exec(tag)?.[1];

function tagsWhere(name: string, value: string): string[] {
  return headTags.filter((tag) => attributeValue(tag, name) === value);
}

function content(name: string, value: string): string | undefined {
  const tags = tagsWhere(name, value);
  expect(tags, `${name}=${value}`).toHaveLength(1);
  return attributeValue(tags[0] ?? '', 'content');
}

/** Width and height from a PNG's IHDR chunk, after checking the signature. */
function pngSize(bytes: Uint8Array): readonly [number, number] {
  expect(Array.from(bytes.slice(0, 8))).toEqual([137, 80, 78, 71, 13, 10, 26, 10]);
  expect(String.fromCharCode(...bytes.slice(12, 16))).toBe('IHDR');
  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  return [view.getUint32(16), view.getUint32(20)];
}

describe('link-preview tags in index.html', () => {
  it('names one absolute og:image, a PNG under public/ on the site address', () => {
    const image = content('property', 'og:image');
    expect(image).toBe(`${SITE}/og-image.png`);
    expect(image?.endsWith('.png')).toBe(true);
    expect(content('property', 'og:image:width')).toBe('1200');
    expect(content('property', 'og:image:height')).toBe('630');
    expect(content('property', 'og:image:alt')).toBe(
      'PokéPrices: Pokémon card prices at the daily close',
    );
  });

  it('asks for the large-image twitter card', () => {
    expect(content('name', 'twitter:card')).toBe('summary_large_image');
  });

  it('carries one absolute canonical link on the site address', () => {
    const links = tagsWhere('rel', 'canonical');
    expect(links).toHaveLength(1);
    expect(attributeValue(links[0] ?? '', 'href')).toBe(`${SITE}/`);
  });
});

describe('the og:image file', () => {
  it('is a PNG of exactly 1200 × 630 and under 300 kB', () => {
    expect(existsSync(publicPath('og-image.png'))).toBe(true);
    const bytes = readFileSync(publicPath('og-image.png'));
    expect(pngSize(bytes)).toEqual([1200, 630]);
    expect(bytes.byteLength).toBeLessThanOrEqual(300 * 1024);
  });
});
