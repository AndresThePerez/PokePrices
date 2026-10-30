import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import { hasProblemType, type ApiFailure, type Card, type CardDetail } from '../api/client';
import { jsonResponse, stubFetch, unownCard } from '../test/fixtures';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function bodyWith(images: Card['images']): CardDetail {
  return { data: { ...unownCard, images }, meta: { degraded: false } };
}

async function show(images: Card['images']): Promise<HTMLElement> {
  stubFetch(() => jsonResponse(bodyWith(images)));
  visit('/card/ex10-%3F');
  render(<App />);
  await screen.findByRole('heading', { level: 1, name: 'Unown' });
  return screen.getByRole('main');
}

afterEach(() => {
  cleanup();
  visit('/');
});

describe('the card image with blank image URLs', () => {
  it('draws the card-back placeholder, and no img, when both URLs are only whitespace', async () => {
    const main = await show({ small: '  ', large: ' \t\n' });
    expect(main.querySelectorAll('img')).toHaveLength(0);
    expect(main.querySelectorAll('[data-initial="U"]')).toHaveLength(1);
    expect(screen.queryByText(/^Card image from/)).toBeNull();
  });

  it('falls back to the small image, and credits its host, when the large URL is only whitespace', async () => {
    const main = await show({
      small: 'https://images.example.com/ex10/question.png',
      large: '   ',
    });
    const images = main.querySelectorAll('img');
    expect(images).toHaveLength(1);
    expect(images[0]?.getAttribute('src')).toBe('https://images.example.com/ex10/question.png');
    expect(main.querySelectorAll('[data-initial]')).toHaveLength(0);
    expect(
      screen.getByText(
        'Card image from images.example.com, linked as the PokéSearch catalog lists it.',
      ),
    ).toBeTruthy();
  });
});

function failure(status: number, type: string): ApiFailure {
  return { ok: false, status, type, title: 'Problem', requestId: null, problem: null };
}

const BASE = 'https://pokeprices.andrestheperez.com/problems/';

describe('hasProblemType', () => {
  it('matches the status and the problem slug at the end of the type', () => {
    expect(hasProblemType(failure(404, `${BASE}card-not-found`), 404, 'card-not-found')).toBe(true);
    expect(hasProblemType(failure(403, `${BASE}demo-read-only`), 403, 'demo-read-only')).toBe(true);
    expect(hasProblemType(failure(409, `${BASE}already-signed-in`), 409, 'already-signed-in')).toBe(
      true,
    );
  });

  it('refuses the right slug under another status', () => {
    expect(hasProblemType(failure(410, `${BASE}card-not-found`), 404, 'card-not-found')).toBe(
      false,
    );
    expect(hasProblemType(failure(0, `${BASE}demo-read-only`), 403, 'demo-read-only')).toBe(false);
  });

  it('refuses a type that only ends with the slug as part of a longer word, or names another problem', () => {
    expect(hasProblemType(failure(404, `${BASE}not-card-not-found`), 404, 'card-not-found')).toBe(
      false,
    );
    expect(hasProblemType(failure(404, `${BASE}card-not-found/x`), 404, 'card-not-found')).toBe(
      false,
    );
    expect(hasProblemType(failure(404, 'about:blank'), 404, 'card-not-found')).toBe(false);
    expect(hasProblemType(failure(403, `${BASE}forbidden`), 403, 'demo-read-only')).toBe(false);
  });

  it('matches the same slug under a foreign base URL, as the suffix check always has', () => {
    expect(
      hasProblemType(
        failure(404, 'https://elsewhere.example/problems/card-not-found'),
        404,
        'card-not-found',
      ),
    ).toBe(true);
  });
});
