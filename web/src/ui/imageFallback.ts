// The card-image error handler shared by every card picture (the list thumbnails through
// CardThumbnail, and any other card image that wants the same card-back fallback).
import { useState } from 'react';

/**
 * The image URL to draw, or null for the card-back tile: null for no URL, a blank one, or one that
 * failed to load. A new URL is tried again; onError marks the current one as failed.
 */
export function useImageFallback(src: string | undefined): {
  readonly src: string | null;
  readonly onError: () => void;
} {
  const [failed, setFailed] = useState<string | null>(null);
  const usable = src !== undefined && src.trim() !== '' && src !== failed ? src : null;
  return {
    src: usable,
    onError: () => {
      if (src !== undefined) setFailed(src);
    },
  };
}
