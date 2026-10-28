// The root boundary: a page that throws while it renders takes only itself down. It sits
// inside #content around the routes, so the banner, the skip target and the footer stay mounted,
// and its fallback is the page's one main: a short sentence and a link that loads the page again.
// It is plain content (no live region, no status) and it clears once the pathname changes.
import { Component, type ReactNode } from 'react';
import { useRoute } from '../router';
import { TEXT_LINK } from './classes';
import Notice from './Notice';

const FALLBACK = 'This page could not be shown.';

interface BoundaryProps {
  readonly pathname: string;
  readonly children: ReactNode;
}

interface BoundaryState {
  readonly failed: boolean;
  readonly pathname: string;
}

class Boundary extends Component<BoundaryProps, BoundaryState> {
  override state: BoundaryState = { failed: false, pathname: this.props.pathname };

  static getDerivedStateFromError(): Partial<BoundaryState> {
    return { failed: true };
  }

  static getDerivedStateFromProps(
    props: BoundaryProps,
    state: BoundaryState,
  ): Partial<BoundaryState> | null {
    return props.pathname === state.pathname ? null : { failed: false, pathname: props.pathname };
  }

  override render(): ReactNode {
    if (!this.state.failed) return this.props.children;
    // A full load of the same address (a native link, not the in-app navigate).
    const again = this.props.pathname + window.location.search;
    return (
      <main className="mx-auto max-w-3xl px-6 pt-12 pb-6">
        <Notice tone="problem">
          <p className="font-semibold">{FALLBACK}</p>
          <p className="mt-1">
            <a href={again} className={TEXT_LINK}>
              Reload the page
            </a>
          </p>
        </Notice>
      </main>
    );
  }
}

export default function RootBoundary({ children }: { readonly children: ReactNode }) {
  const { pathname } = useRoute();
  return <Boundary pathname={pathname}>{children}</Boundary>;
}
