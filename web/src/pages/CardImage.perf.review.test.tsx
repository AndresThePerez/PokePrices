import { act, cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import {
  cardBody,
  cardNotFoundProblem,
  historyFor,
  isHistoryUrl,
  jsonResponse,
  problemResponse,
  searchPage,
  stubFetch,
  unownExclamation,
} from '../test/fixtures';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

afterEach(() => {
  cleanup();
  window.history.replaceState(null, '', '/');
});

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function withImages(small: string, large: string, name = 'Unown'): CardDetail {
  return {
    ...cardBody,
    data: { ...cardBody.data, name, images: { small, large } },
  };
}

function expectHeroAttributes(image: Element | null | undefined, src: string): void {
  expect(image).toBeTruthy();
  expect(image?.getAttribute('src')).toBe(src);
  expect(image?.getAttribute('fetchpriority')).toBe('high');
  expect(image?.getAttribute('decoding')).toBe('async');
  expect(image?.hasAttribute('loading')).toBe(false);
  expect(image?.getAttribute('width')).toBe('245');
  expect(image?.getAttribute('height')).toBe('342');
}

describe('the card hero image attributes (D2-i3-T01 review)', () => {
  it('keeps high priority and async decoding when only the small image exists', async () => {
    const body = withImages('https://images.example.com/ex10/only-small.png', '');
    stubFetch((url) => (isHistoryUrl(url) ? jsonResponse(historyFor(body)) : jsonResponse(body)));
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    const images = screen.getByRole('main').querySelectorAll('img');
    expect(images).toHaveLength(1);
    expectHeroAttributes(images[0], 'https://images.example.com/ex10/only-small.png');
  });

  it('gives exactly one element in the whole page a fetch priority', async () => {
    stubFetch((url) =>
      isHistoryUrl(url) ? jsonResponse(historyFor(cardBody)) : jsonResponse(cardBody),
    );
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    expect(document.querySelectorAll('[fetchpriority]')).toHaveLength(1);
  });

  it('keeps the attributes on the hero after navigating to another card', async () => {
    const second = withImages(
      'https://images.example.com/ex10/b-small.png',
      'https://images.example.com/ex10/b-large.png',
      'Wobbuffet',
    );
    stubFetch((url) => {
      const body = url.includes('ex10-!') ? second : cardBody;
      return isHistoryUrl(url) ? jsonResponse(historyFor(body)) : jsonResponse(body);
    });
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    act(() => {
      visit('/card/ex10-!');
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    await screen.findByRole('heading', { level: 1, name: 'Wobbuffet' });
    const images = screen.getByRole('main').querySelectorAll('img');
    expect(images).toHaveLength(1);
    expectHeroAttributes(images[0], 'https://images.example.com/ex10/b-large.png');
  });

  it('renders no image, and so no prioritised fetch, for a card that is not found', async () => {
    stubFetch(() => problemResponse(cardNotFoundProblem));
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1 });
    expect(screen.getByRole('main').querySelectorAll('img')).toHaveLength(0);
    expect(document.querySelectorAll('[fetchpriority]')).toHaveLength(0);
  });

  it('leaves search thumbnails lazy and async with no fetch priority', async () => {
    stubFetch(() => jsonResponse(searchPage));
    visit('/search?q=unown');
    render(<App />);
    await screen.findAllByRole('link', { name: /Unown/ });
    const thumb = screen
      .getByRole('main')
      .querySelector(`img[src="${unownExclamation.image_small}"]`);
    expect(thumb?.getAttribute('loading')).toBe('lazy');
    expect(thumb?.getAttribute('decoding')).toBe('async');
    expect(document.querySelectorAll('[fetchpriority]')).toHaveLength(0);
  });
});
