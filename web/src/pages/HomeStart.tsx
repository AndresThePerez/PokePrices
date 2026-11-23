// The home page's start, above the movers strip: example searches that land their card on the first
// page of results, and a fixed set of well-known card tiles, each a picture and the card's name
// linking to its card page. Everything here is a constant: it sends no request of its own. The
// pictures are the catalogue's small images, loaded lazily by the browser in a reserved box, with
// the shared card back when one fails. A tile shows no price: the card page shows it, with its
// source and as-of day.
import { useId } from 'react';
import Link from '../Link';
import { cardPath } from '../router';
import { FOCUS, MUTED, TEXT_LINK } from '../ui/classes';
import { CardBack } from '../ui/CardThumbnail';
import { useImageFallback } from '../ui/imageFallback';

/** The image host the catalogue names for these cards' small pictures. */
const CATALOGUE_IMAGES = 'https://images.pokemontcg.io';

interface Tile {
  readonly id: string;
  readonly name: string;
  readonly set: string;
  /** The catalogue's small image of the card (245 × 342), as a path on its image host. */
  readonly image: string;
}

/** Six cards a collector knows at a glance; each exists in the catalogue with a priced variant. */
const HOME_TILES: readonly Tile[] = [
  { id: 'base1-4', name: 'Charizard', set: 'Base', image: '/base1/4.png' },
  { id: 'base1-2', name: 'Blastoise', set: 'Base', image: '/base1/2.png' },
  { id: 'base1-58', name: 'Pikachu', set: 'Base', image: '/base1/58.png' },
  { id: 'neo1-9', name: 'Lugia', set: 'Neo Genesis', image: '/neo1/9.png' },
  { id: 'sv3pt5-199', name: 'Charizard ex', set: '151', image: '/sv3pt5/199.png' },
  { id: 'sv8pt5-161', name: 'Umbreon ex', set: 'Prismatic Evolutions', image: '/sv8pt5/161.png' },
];

/** Searches whose named card is on the first page of results in the default order. */
const EXAMPLE_SEARCHES: readonly string[] = [
  'lugia neo genesis',
  'umbreon vmax',
  'umbreon ex',
  'mewtwo',
];

function searchPath(q: string): string {
  return `/search?${new URLSearchParams({ q }).toString()}`;
}

/** The box the picture and the card back both fill, so a swap never moves the tile. */
const PICTURE = 'aspect-[63/88] w-full rounded-md border border-line-subtle bg-surface-sunken';

/**
 * A tile's picture, or the card back in the same box. Decorative, with an empty alt as the list
 * thumbnails have: the tile's link text names the card.
 */
function TilePicture({ tile }: { readonly tile: Tile }) {
  const image = useImageFallback(`${CATALOGUE_IMAGES}${tile.image}`);
  if (image.src === null) return <CardBack name={tile.name} className={`${PICTURE} text-2xl`} />;
  return (
    <img
      src={image.src}
      alt=""
      width={245}
      height={342}
      loading="lazy"
      decoding="async"
      onError={image.onError}
      className={`${PICTURE} h-auto object-cover`}
    />
  );
}

/** The example searches, each a link to the search page in its default order. */
export function ExampleSearches() {
  const headingId = useId();
  return (
    <section aria-labelledby={headingId} className="mt-8">
      <h2 id={headingId} className="text-lg font-semibold">
        Example searches
      </h2>
      <ul aria-labelledby={headingId} className="mt-1 flex flex-wrap gap-x-6">
        {EXAMPLE_SEARCHES.map((q) => (
          <li key={q}>
            <Link to={searchPath(q)} className={TEXT_LINK}>
              {q}
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}

/** The well-known card tiles, three to a row on a phone and six from 640 px. */
export function CardTiles() {
  const headingId = useId();
  return (
    <section aria-labelledby={headingId} className="mt-8">
      <h2 id={headingId} className="text-lg font-semibold">
        Well-known cards
      </h2>
      <ul aria-labelledby={headingId} className="mt-3 grid grid-cols-3 gap-3 sm:grid-cols-6">
        {HOME_TILES.map((tile) => (
          <li key={tile.id} className="min-w-0">
            <Link
              to={cardPath(tile.id)}
              className={`flex min-h-11 flex-col gap-1 rounded-md text-sm ${FOCUS}`}
            >
              <TilePicture tile={tile} />
              <span className="font-semibold break-words">{tile.name}</span>{' '}
              <span className={`text-meta break-words ${MUTED}`}>{tile.set}</span>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}
