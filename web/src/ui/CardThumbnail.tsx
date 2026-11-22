// The card picture of a list row (search results, movers, watchlist, portfolio): the catalog's
// small image, hotlinked from the URL the API carries, 48 px wide in a reserved 63:88 box, loaded
// lazily; or, when there is no URL or the image fails to load, the card-back tile with the card's
// initial drawn by CSS from data-initial (no DOM text, no request). CardBack and useImageFallback
// (imageFallback.ts) are the shared fallback: any other card image (the card page's large one)
// can use the same tile and the same error handler at its own size.
import { initialOf } from '../format';
import { MUTED } from './classes';
import { useImageFallback } from './imageFallback';

/** The 48 px box both the image and the tile fill, so a swap never moves the row. */
const THUMB = 'aspect-[63/88] w-12 shrink-0 rounded-sm border border-line-subtle bg-surface-sunken';

/**
 * The neutral card back with the name's initial, drawn by CSS from data-initial: it holds no DOM
 * text, is no image and needs no aria-hidden. `className` gives its size and type scale.
 */
export function CardBack({
  name,
  className = `${THUMB} text-base`,
}: {
  readonly name: string;
  readonly className?: string;
}) {
  return (
    <span
      data-initial={initialOf(name)}
      className={`${className} flex items-center justify-center font-semibold ${MUTED} before:content-[attr(data-initial)]`}
    />
  );
}

/** A row's 48 px card picture, or its card-back tile. Decorative: the row's link names the card. */
export default function CardThumbnail({
  src,
  name,
}: {
  readonly src: string | undefined;
  readonly name: string;
}) {
  const image = useImageFallback(src);
  if (image.src === null) return <CardBack name={name} />;
  return (
    <img
      src={image.src}
      alt=""
      width={63}
      height={88}
      loading="lazy"
      decoding="async"
      onError={image.onError}
      className={`${THUMB} h-auto object-cover`}
    />
  );
}
