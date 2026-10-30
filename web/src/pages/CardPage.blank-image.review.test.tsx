import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import type { Card, CardDetail } from '../api/client';
import { jsonResponse, stubFetch, unownCard } from '../test/fixtures';

const SMALL = 'https://images.example.com/ex10/question.png';
const LARGE = 'https://cdn.example.org/ex10/question-large.png';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

async function show(images: Card['images']): Promise<HTMLElement> {
  const body: CardDetail = { data: { ...unownCard, images }, meta: { degraded: false } };
  stubFetch(() => jsonResponse(body));
  visit('/card/ex10-%3F');
  render(<App />);
  await screen.findByRole('heading', { level: 1, name: 'Unown' });
  return screen.getByRole('main');
}

afterEach(() => {
  cleanup();
  visit('/');
});

const credit = (host: string): string =>
  `Card image from ${host}, linked as the PokéSearch catalog lists it.`;

describe('the card image with unusual blank or padded URLs', () => {
  it.each([
    ['a no-break space', ' '],
    ['an ideographic space', '　　'],
    ['a tab and a newline', '\t\n\r'],
  ])('reads %s as no large image and falls back to the small one', async (_name, blank) => {
    const main = await show({ small: SMALL, large: blank });
    const images = main.querySelectorAll('img');
    expect(images).toHaveLength(1);
    expect(images[0]?.getAttribute('src')).toBe(SMALL);
    expect(screen.getByText(credit('images.example.com'))).toBeTruthy();
  });

  it.each([
    ['a no-break space', ' '],
    ['an ideographic space', '　'],
  ])('draws the placeholder, and no credit, when both URLs are %s', async (_name, blank) => {
    const main = await show({ small: blank, large: blank });
    expect(main.querySelectorAll('img')).toHaveLength(0);
    expect(main.querySelectorAll('[data-initial="U"]')).toHaveLength(1);
    expect(screen.queryByText(/^Card image from/)).toBeNull();
  });

  it('keeps the large image, and credits its host, when the small URL is only whitespace', async () => {
    const main = await show({ small: '   ', large: LARGE });
    const images = main.querySelectorAll('img');
    expect(images).toHaveLength(1);
    expect(images[0]?.getAttribute('src')).toBe(LARGE);
    expect(screen.getByText(credit('cdn.example.org'))).toBeTruthy();
  });

  it('credits the host of a padded small URL when the large URL is blank', async () => {
    const main = await show({ small: `  ${SMALL}  `, large: ' ' });
    expect(main.querySelectorAll('img')).toHaveLength(1);
    expect(screen.getByText(credit('images.example.com'))).toBeTruthy();
  });

  it('prefers a real large URL over a real small one and credits the large one', async () => {
    const main = await show({ small: SMALL, large: LARGE });
    expect(main.querySelectorAll('img')[0]?.getAttribute('src')).toBe(LARGE);
    expect(screen.getByText(credit('cdn.example.org'))).toBeTruthy();
    expect(screen.queryByText(credit('images.example.com'))).toBeNull();
  });
});
