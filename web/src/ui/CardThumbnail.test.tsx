// The shared card picture: a 48 px lazy thumbnail with explicit width and height, and the
// card-back tile with the name's initial in its place when there is no URL or the image fails.
// useImageFallback is the handler the thumbnail uses, written so another image can reuse it.
import { cleanup, fireEvent, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import CardThumbnail, { CardBack } from './CardThumbnail';
import { useImageFallback } from './imageFallback';

afterEach(() => {
  cleanup();
});

const URL_A = 'https://images.example.com/a.png';
const URL_B = 'https://images.example.com/b.png';

function Probe({ src }: { readonly src: string | undefined }) {
  const image = useImageFallback(src);
  return image.src === null ? (
    <CardBack name="Unown" className="h-22 w-16" />
  ) : (
    <img src={image.src} alt="" onError={image.onError} />
  );
}

describe('CardThumbnail', () => {
  it('draws the image lazily at 63 × 88 in a fixed 48 px box, decorative', () => {
    const { container } = render(<CardThumbnail src={URL_A} name="Charizard" />);
    const img = container.querySelector('img');
    expect(img?.getAttribute('src')).toBe(URL_A);
    expect(img?.getAttribute('loading')).toBe('lazy');
    expect(img?.getAttribute('decoding')).toBe('async');
    expect(img?.getAttribute('width')).toBe('63');
    expect(img?.getAttribute('height')).toBe('88');
    expect(img?.getAttribute('alt')).toBe('');
    expect(img?.className.split(/\s+/)).toEqual(
      expect.arrayContaining(['w-12', 'shrink-0', 'aspect-[63/88]']),
    );
  });

  it.each([
    ['no URL', undefined],
    ['an empty URL', ''],
    ['a blank URL', '   '],
  ])('draws the card-back tile for %s, with the initial in CSS only', (_case, src) => {
    const { container } = render(<CardThumbnail src={src} name="  éclair" />);
    expect(container.querySelector('img')).toBeNull();
    const tile = container.querySelector('[data-initial]');
    expect(tile?.getAttribute('data-initial')).toBe('É');
    expect(tile?.textContent).toBe('');
    expect(tile?.className.split(/\s+/)).toEqual(
      expect.arrayContaining(['w-12', 'shrink-0', 'aspect-[63/88]']),
    );
  });

  it('swaps a failed image for the tile', () => {
    const { container } = render(<CardThumbnail src={URL_A} name="Charizard" />);
    const img = container.querySelector('img');
    if (img === null) throw new Error('an image expected');
    fireEvent.error(img);
    expect(container.querySelector('img')).toBeNull();
    expect(container.querySelector('[data-initial]')?.getAttribute('data-initial')).toBe('C');
  });
});

describe('useImageFallback', () => {
  it('falls back after an error and tries again when the URL changes', () => {
    const { container, rerender } = render(<Probe src={URL_A} />);
    const img = container.querySelector('img');
    if (img === null) throw new Error('an image expected');
    fireEvent.error(img);
    expect(container.querySelector('img')).toBeNull();
    expect(container.querySelector('[data-initial]')?.className).toContain('w-16');
    rerender(<Probe src={URL_B} />);
    expect(container.querySelector('img')?.getAttribute('src')).toBe(URL_B);
  });
});
