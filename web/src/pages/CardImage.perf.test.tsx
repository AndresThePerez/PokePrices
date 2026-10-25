import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import { cardBody, historyFor, isHistoryUrl, jsonResponse, stubFetch } from '../test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

afterEach(() => {
  cleanup();
  window.history.replaceState(null, '', '/');
});

async function showCard(body: CardDetail): Promise<HTMLElement> {
  stubFetch((url) => (isHistoryUrl(url) ? jsonResponse(historyFor(body)) : jsonResponse(body)));
  window.history.replaceState(null, '', '/card/ex10-%3F');
  render(<App />);
  await screen.findByRole('heading', { level: 1, name: 'Unown' });
  return screen.getByRole('main');
}

describe('the card hero image (D2-i3-T01)', () => {
  it('fetches with high priority and decodes asynchronously, eagerly, keeping alt, src and size', async () => {
    const main = await showCard(cardBody);
    const images = main.querySelectorAll('img');
    expect(images).toHaveLength(1);
    const image = images[0] as HTMLImageElement;
    expect(image.getAttribute('fetchpriority')).toBe('high');
    expect(image.getAttribute('decoding')).toBe('async');
    expect(image.hasAttribute('loading')).toBe(false);
    expect(image.getAttribute('alt')).toBe('Unown, Unseen Forces No. ?');
    expect(image.getAttribute('src')).toBe(cardBody.data.images.large);
    expect(image.getAttribute('width')).toBe('245');
    expect(image.getAttribute('height')).toBe('342');
  });

  it('still renders no img for the card-back placeholder', async () => {
    const bare = { ...cardBody, data: { ...cardBody.data, images: { small: '', large: '' } } };
    const main = await showCard(bare);
    expect(main.querySelectorAll('img')).toHaveLength(0);
  });
});
