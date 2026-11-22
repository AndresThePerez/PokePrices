// Edge cases of the shared card picture: a second error event cannot loop or stack tiles, a
// failed URL is not retried on a re-render with the same URL, a new URL after a failure brings the
// picture back (and that one can fail too), the swap keeps the reserved box, and the picture is
// decorative (alt="" and no text of its own) as the search page's thumbnail was.
import { cleanup, fireEvent, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import CardThumbnail from './CardThumbnail';

afterEach(() => {
  cleanup();
});

const URL_A = 'https://images.example.com/a.png';
const URL_B = 'https://images.example.com/b.png';
const BOX = ['aspect-[63/88]', 'w-12', 'shrink-0'];

function img(container: HTMLElement): HTMLImageElement {
  const found = container.querySelector('img');
  if (found === null) throw new Error('an image expected');
  return found;
}

describe('CardThumbnail under repeated failures', () => {
  it('draws one tile after two error events on the same image, and never a second image', () => {
    const { container } = render(<CardThumbnail src={URL_A} name="Charizard" />);
    const first = img(container);
    fireEvent.error(first);
    fireEvent.error(first);
    expect(container.querySelectorAll('img')).toHaveLength(0);
    expect(container.querySelectorAll('[data-initial]')).toHaveLength(1);
    expect(container.childElementCount).toBe(1);
  });

  it('does not retry a failed URL when the row re-renders with the same URL', () => {
    const { container, rerender } = render(<CardThumbnail src={URL_A} name="Charizard" />);
    fireEvent.error(img(container));
    for (let i = 0; i < 3; i += 1) rerender(<CardThumbnail src={URL_A} name="Charizard" />);
    expect(container.querySelector('img')).toBeNull();
    expect(container.querySelector('[data-initial]')?.getAttribute('data-initial')).toBe('C');
  });

  it('shows the new card after a failure, swaps that one too when it fails, and keeps the box each time', () => {
    const { container, rerender } = render(<CardThumbnail src={URL_A} name="Charizard" />);
    const before = img(container);
    expect(before.className.split(/\s+/)).toEqual(expect.arrayContaining(BOX));
    fireEvent.error(before);
    const tile = container.querySelector('[data-initial]');
    expect(tile?.className.split(/\s+/)).toEqual(expect.arrayContaining(BOX));

    rerender(<CardThumbnail src={URL_B} name="Blastoise" />);
    const next = img(container);
    expect(next.getAttribute('src')).toBe(URL_B);
    expect(next.getAttribute('width')).toBe('63');
    expect(next.getAttribute('height')).toBe('88');
    expect(container.querySelector('[data-initial]')).toBeNull();

    fireEvent.error(next);
    expect(container.querySelector('img')).toBeNull();
    expect(container.querySelector('[data-initial]')?.getAttribute('data-initial')).toBe('B');
  });

  it('goes from a picture to the tile and back when the URL is taken away and given again', () => {
    const { container, rerender } = render(<CardThumbnail src={URL_A} name="Charizard" />);
    rerender(<CardThumbnail src={undefined} name="Charizard" />);
    expect(container.querySelector('img')).toBeNull();
    rerender(<CardThumbnail src={URL_A} name="Charizard" />);
    expect(img(container).getAttribute('src')).toBe(URL_A);
  });

  it('is decorative: alt is empty, there is no aria-label or title, and the tile holds no text', () => {
    const { container, rerender } = render(<CardThumbnail src={URL_A} name="Charizard" />);
    const picture = img(container);
    expect(picture.getAttribute('alt')).toBe('');
    expect(picture.hasAttribute('aria-label')).toBe(false);
    expect(picture.hasAttribute('title')).toBe(false);
    rerender(<CardThumbnail src="" name="Charizard" />);
    expect(container.textContent).toBe('');
    expect(container.querySelector('[aria-label]')).toBeNull();
  });
});
