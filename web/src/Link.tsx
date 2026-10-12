import type { AnchorHTMLAttributes, MouseEvent } from 'react';
import { navigate } from './router';

type LinkProps = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & { readonly to: string };

/** An in-app link: a plain click navigates in place; modified clicks stay with the browser. */
export default function Link({ to, onClick, ...anchor }: LinkProps) {
  function handleClick(event: MouseEvent<HTMLAnchorElement>): void {
    onClick?.(event);
    const modified = event.metaKey || event.ctrlKey || event.shiftKey || event.altKey;
    if (event.defaultPrevented || event.button !== 0 || modified || anchor.target !== undefined) {
      return;
    }
    event.preventDefault();
    navigate(to);
  }
  return <a {...anchor} href={to} onClick={handleClick} />;
}
