import { describe, expect, it } from 'vitest';
import rawIndexHtml from '../index.html?raw';

// tsconfig.app.json carries no Node types, hence the untyped specifier and the narrow cast.
const NODE_FS = 'node:fs';
const { readFileSync, existsSync } = (await import(/* @vite-ignore */ NODE_FS)) as {
  readonly readFileSync: (path: string, encoding: 'utf8') => string;
  readonly existsSync: (path: string) => boolean;
};
const testFile = import.meta.url;
const publicPath = (name: string): string => new URL(`../public/${name}`, testFile).pathname;

const head = rawIndexHtml.slice(rawIndexHtml.indexOf('<head>'), rawIndexHtml.indexOf('</head>'));
// Tags outside the inline script body, so the pre-paint source never reads as markup.
const headWithoutScript = head.replace(/<script>[\s\S]*?<\/script>/, '');
const headTags = headWithoutScript.match(/<(?:link|meta)\b[^>]*>/g) ?? [];

const FORBIDDEN_WORDS = /\blive\b|real.?time|today|\bnow\b|24 ?h/i;

const attributeValue = (tag: string, name: string): string | undefined =>
  new RegExp(`\\s${name}="([^"]*)"`).exec(tag)?.[1];

describe('head metadata is well formed', () => {
  it('every link and meta tag is self-closing, double-quoted and has no repeated attribute', () => {
    expect(headTags.length).toBeGreaterThanOrEqual(6);
    for (const tag of headTags) {
      const collapsed = tag.replace(/\s+/g, ' ');
      expect(collapsed).toMatch(/^<(?:link|meta)(?: [a-z:-]+="[^"]*")+ \/>$/);
      const names = [...collapsed.matchAll(/ ([a-z:-]+)="/g)].map((match) => match[1]);
      expect(new Set(names).size).toBe(names.length);
    }
  });

  it('only the icon link and the og title and description are added, with no image or manifest reference', () => {
    const icons = headTags.filter((tag) => /rel="(?:shortcut )?icon"/.test(tag));
    expect(icons).toHaveLength(1);
    const ogProperties = headTags
      .map((tag) => attributeValue(tag, 'property'))
      .filter((value): value is string => value !== undefined);
    expect(ogProperties.sort()).toEqual(['og:description', 'og:title']);
    expect(headWithoutScript).not.toMatch(/apple-touch-icon|manifest|twitter:|og:image/);
  });

  it('og:title matches the document title, and og:description is one sentence with no live wording', () => {
    const title = /<title>([^<]*)<\/title>/.exec(headWithoutScript)?.[1];
    const ogTitle = headTags.find((tag) => attributeValue(tag, 'property') === 'og:title');
    expect(ogTitle && attributeValue(ogTitle, 'content')).toBe(title);
    const ogDescription = headTags.find(
      (tag) => attributeValue(tag, 'property') === 'og:description',
    );
    const description = ogDescription && attributeValue(ogDescription, 'content');
    expect(description).toBeDefined();
    expect(description).toMatch(/\.$/);
    expect(description).not.toMatch(FORBIDDEN_WORDS);
    expect(description).not.toMatch(/[<>&"]/);
  });
});

describe('static artwork is inert and reachable', () => {
  it('the favicon href resolves to a file under public/ that is an SVG with the 32x32 viewBox', () => {
    const icon = headTags.find((tag) => /rel="icon"/.test(tag));
    const href = icon && attributeValue(icon, 'href');
    expect(href).toBe('/favicon.svg');
    expect(href?.startsWith('/')).toBe(true);
    expect(existsSync(publicPath((href ?? '').slice(1)))).toBe(true);
    const svg = readFileSync(publicPath('favicon.svg'), 'utf8').trimStart();
    expect(svg.startsWith('<svg ')).toBe(true);
    expect(svg).toContain('xmlns="http://www.w3.org/2000/svg"');
    expect(svg).toContain('viewBox="0 0 32 32"');
  });

  it.each(['favicon.svg', 'og-image.svg'])(
    '%s has no script, raster, import, url reference, external link or event handler, and balanced tags',
    (name) => {
      const svg = readFileSync(publicPath(name), 'utf8');
      expect(svg).not.toMatch(/<script|<image|<foreignObject|<style|<use\b|@import|url\(|data:/i);
      expect(svg).not.toMatch(/\son[a-z]+\s*=/i);
      expect(svg).not.toMatch(/href\s*=\s*["']?(?:https?:|\/\/)/i);
      expect(svg.replace('xmlns="http://www.w3.org/2000/svg"', '')).not.toMatch(/https?:\/\//);
      expect(svg).not.toMatch(FORBIDDEN_WORDS);
      const opens = (svg.match(/<(?!\/)(?!\?)[a-zA-Z][^>]*[^/]>/g) ?? []).length;
      const closes = (svg.match(/<\/[a-zA-Z]+>/g) ?? []).length;
      expect(opens).toBe(closes);
      expect(svg.trimEnd().endsWith('</svg>')).toBe(true);
    },
  );
});
