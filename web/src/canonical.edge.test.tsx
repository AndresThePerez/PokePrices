// Edge cases of the canonical link the app keeps on the page on screen: one element at all times,
// on the site's own origin, the path without the query or the fragment, through navigation, Back
// and Forward, hostile and oddly encoded paths, a page that cannot render, and an index.html whose
// tag is missing or malformed.
import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, onTestFinished, vi } from 'vitest';
import type * as SignUpModule from './account/SignUpPage';
import App from './App';
import { cardPath, navigate } from './router';
import { answerMovers, freshnessBody, jsonResponse, stubFetch } from './test/fixtures';

// The sign-up page stands in for any page that throws while it renders; a flag turns it on.
const crash = vi.hoisted(() => ({ on: false }));
vi.mock('./account/SignUpPage', async (importOriginal) => {
  const actual = await importOriginal<typeof SignUpModule>();
  const Page = actual.default;
  return {
    default: function MaybeCrash() {
      if (crash.on) throw new Error('render failed');
      return <Page />;
    },
  };
});

const SITE = 'https://pokeprices.andrestheperez.com';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function canonicals(): HTMLLinkElement[] {
  return Array.from(document.querySelectorAll<HTMLLinkElement>('link[rel="canonical"]'));
}

function canonical(): string | null {
  const links = canonicals();
  expect(links).toHaveLength(1);
  return links[0]?.getAttribute('href') ?? null;
}

/** The canonical's value is the site's origin, then the path on screen, and nothing else. */
function expectOnSite(path: string): void {
  const href = canonical() ?? '';
  expect(href).toBe(`${SITE}${path}`);
  expect(new URL(href).origin).toBe(SITE);
  expect(href).not.toMatch(/[<>"'\s#?]/);
}

function addCanonical(href: string | null): HTMLLinkElement {
  const link = document.createElement('link');
  link.rel = 'canonical';
  if (href !== null) link.setAttribute('href', href);
  document.head.append(link);
  return link;
}

beforeEach(() => {
  addCanonical(`${SITE}/`);
});

afterEach(() => {
  cleanup();
  crash.on = false;
  vi.restoreAllMocks();
  canonicals().forEach((link) => {
    link.remove();
  });
  visit('/');
});

describe('the canonical link on every route', () => {
  it.each([
    '/',
    '/search',
    '/card/base1-4',
    '/movers',
    '/sign-in',
    '/sign-up',
    '/password/forgot',
    '/password/reset',
    '/account',
    '/account/verify-email',
    '/watchlist',
    '/portfolio',
    '/alerts',
    '/about',
    '/no-such-page',
  ])('is one element naming the site address and %s', (path) => {
    stubFetch(() => new Error('no answer'));
    visit(path);
    render(<App />);
    expectOnSite(path);
  });

  it('drops the query and the fragment', () => {
    stubFetch(() => new Error('no answer'));
    visit('/about?x=1&y=%3Cb%3E#privacy');
    render(<App />);
    expectOnSite('/about');
    act(() => {
      navigate('/search?q=lugia%20neo#results');
    });
    expectOnSite('/search');
    act(() => {
      navigate('/movers?direction=down');
    });
    expectOnSite('/movers');
  });

  it('stays one element through navigation, Back and Forward, and follows each step', async () => {
    stubFetch(answerMovers);
    visit('/');
    render(<App />);
    expectOnSite('/');
    act(() => {
      navigate('/about');
    });
    expectOnSite('/about');
    act(() => {
      navigate(cardPath('base1-4'));
    });
    expectOnSite('/card/base1-4');
    act(() => {
      window.history.back();
    });
    await waitFor(() => {
      expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('About');
    });
    expectOnSite('/about');
    act(() => {
      window.history.back();
    });
    await waitFor(() => {
      expect(window.location.pathname).toBe('/');
    });
    await waitFor(() => {
      expectOnSite('/');
    });
    act(() => {
      window.history.forward();
    });
    await waitFor(() => {
      expectOnSite('/about');
    });
  });

  it('follows a route change made while the page before it is still loading', () => {
    // A card page whose request never settles: the visitor leaves before it answers.
    vi.stubGlobal('fetch', () => new Promise<Response>(() => undefined));
    onTestFinished(() => {
      vi.unstubAllGlobals();
    });
    visit('/card/base1-4');
    render(<App />);
    expectOnSite('/card/base1-4');
    act(() => {
      navigate('/movers');
    });
    expectOnSite('/movers');
    act(() => {
      navigate('/about');
    });
    expectOnSite('/about');
  });
});

describe('the canonical link on hostile and oddly encoded paths', () => {
  it.each(['ex10-?', 'ex10-!', '100%', 'base 1', 'pokémon-1', 'a"b<c>', '#top'])(
    'keeps a card id %s encoded, on the site, and free of markup',
    (id) => {
      stubFetch(() => new Error('no answer'));
      visit('/');
      render(<App />);
      act(() => {
        navigate(cardPath(id));
      });
      expectOnSite(cardPath(id));
    },
  );

  it.each([
    '//example.test/x',
    '/\\example.test/x',
    '/about/',
    '//about',
    '/card//x',
    '/%2F%2Fexample.test',
  ])('never points off the site for the path %s', (raw) => {
    stubFetch(() => new Error('no answer'));
    // An absolute same-origin address, so a path that starts with two slashes stays a path.
    visit(`${window.location.origin}${raw}`);
    render(<App />);
    const href = canonical() ?? '';
    expect(new URL(href).origin).toBe(SITE);
    expect(href).toBe(`${SITE}${window.location.pathname}`);
    expect(href).not.toMatch(/[<>"'\s\\]/);
  });

  it('carries markup typed into the address only as percent-escapes', () => {
    stubFetch(() => new Error('no answer'));
    visit('/<img src=x onerror=alert(1)>');
    render(<App />);
    const href = canonical() ?? '';
    expect(href.startsWith(`${SITE}/`)).toBe(true);
    expect(href).not.toMatch(/[<>"'\s]/);
    expect(document.head.querySelectorAll('img, script')).toHaveLength(0);
  });

  it('keeps a very long path on the site', () => {
    stubFetch(() => new Error('no answer'));
    const long = `/card/${'x'.repeat(5000)}`;
    visit(long);
    render(<App />);
    expectOnSite(long);
  });
});

describe('the canonical link when index.html is not what the app expects', () => {
  it('renders the page and adds no link when the tag is missing', () => {
    canonicals().forEach((link) => {
      link.remove();
    });
    stubFetch(() => new Error('no answer'));
    visit('/about');
    render(<App />);
    act(() => {
      navigate('/movers');
    });
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Biggest moves');
    expect(canonicals()).toHaveLength(0);
  });

  it.each([null, '', 'not an address', '/relative/path'])(
    'renders the page and leaves a tag whose href is %j untouched',
    (href) => {
      canonicals().forEach((link) => {
        link.remove();
      });
      addCanonical(href);
      stubFetch(() => new Error('no answer'));
      visit('/about');
      render(<App />);
      expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('About');
      const links = canonicals();
      expect(links).toHaveLength(1);
      expect(links[0]?.getAttribute('href') ?? null).toBe(href);
    },
  );
});

describe('the canonical link when a page cannot render', () => {
  it('names the address on screen, not the page before it, under the fallback', () => {
    stubFetch(() => new Error('no answer'));
    visit('/about');
    render(<App />);
    expectOnSite('/about');
    crash.on = true;
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    act(() => {
      navigate('/sign-up');
    });
    expect(screen.getByText('This page could not be shown.')).toBeTruthy();
    expectOnSite('/sign-up');
  });

  it('does not leave a page that fails on its first load declared a copy of the home page', () => {
    stubFetch(() => new Error('no answer'));
    crash.on = true;
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    visit('/sign-up');
    render(<App />);
    expect(screen.getByText('This page could not be shown.')).toBeTruthy();
    expectOnSite('/sign-up');
  });

  it('keeps the footer and its site links under the fallback', () => {
    stubFetch(() => new Error('no answer'));
    crash.on = true;
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    visit('/sign-up');
    render(<App />);
    expect(screen.getByText('This page could not be shown.')).toBeTruthy();
    const footers = screen.getAllByRole('contentinfo');
    expect(footers).toHaveLength(1);
    const nav = screen.getByRole('navigation', { name: 'Site' });
    expect(footers[0]?.contains(nav)).toBe(true);
    expect(Array.from(nav.querySelectorAll('a')).map((a) => a.getAttribute('href'))).toEqual([
      '/about',
      'https://github.com/AndresThePerez/PokePrices',
      'https://andrestheperez.com',
      'https://andrestheperez.com',
    ]);
  });
});

describe('the canonical keeper outside the root boundary', () => {
  const FALLBACK = 'This page could not be shown.';

  function quietErrors(): void {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
  }

  it('follows several steps into, out of and back to a failing page, Back and Forward included', async () => {
    stubFetch(answerMovers);
    crash.on = true;
    quietErrors();
    visit('/');
    render(<App />);
    expectOnSite('/');
    act(() => {
      navigate('/about');
    });
    expectOnSite('/about');
    act(() => {
      navigate('/sign-up');
    });
    expect(screen.getByText(FALLBACK)).toBeTruthy();
    expectOnSite('/sign-up');
    act(() => {
      navigate('/movers');
    });
    expect(screen.queryByText(FALLBACK)).toBeNull();
    expectOnSite('/movers');
    act(() => {
      window.history.back();
    });
    await waitFor(() => {
      expect(screen.getByText(FALLBACK)).toBeTruthy();
    });
    expectOnSite('/sign-up');
    act(() => {
      window.history.back();
    });
    await waitFor(() => {
      expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('About');
    });
    expectOnSite('/about');
    act(() => {
      window.history.forward();
    });
    await waitFor(() => {
      expect(screen.getByText(FALLBACK)).toBeTruthy();
    });
    expectOnSite('/sign-up');
    act(() => {
      window.history.forward();
    });
    await waitFor(() => {
      expectOnSite('/movers');
    });
  });

  it('stays on the page when it throws on a later render, not its first', () => {
    stubFetch(() => new Error('no answer'));
    quietErrors();
    visit('/sign-up');
    render(<App />);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Create an account');
    expectOnSite('/sign-up');
    crash.on = true;
    // The same path with a query: the page renders again and throws this time.
    act(() => {
      navigate('/sign-up?again=1');
    });
    expect(screen.getByText(FALLBACK)).toBeTruthy();
    expectOnSite('/sign-up');
    crash.on = false;
    act(() => {
      navigate('/about#privacy');
    });
    expect(screen.queryByText(FALLBACK)).toBeNull();
    expectOnSite('/about');
  });

  it('renders nothing of its own: the shell keeps its header, banner, content and footer', async () => {
    stubFetch((url) => {
      if (url === '/v1/prices/freshness') return jsonResponse(freshnessBody());
      return answerMovers(url);
    });
    visit('/');
    const { container } = render(<App />);
    await waitFor(() => {
      expect(screen.getAllByText(/Prices are out of date/)).toHaveLength(1);
    });
    const shell = (): string[] =>
      Array.from(container.childNodes).map((node) =>
        node instanceof Element ? `${node.tagName}${node.id === '' ? '' : `#${node.id}`}` : '#text',
      );
    expect(shell()).toEqual(['HEADER', 'DIV', 'DIV#content', 'FOOTER']);
    expect(container.children[1]?.textContent).toMatch(/^Prices are out of date/);
    act(() => {
      navigate('/about');
    });
    expect(shell()).toEqual(['HEADER', 'DIV#content', 'FOOTER']);
    expect(screen.queryByText(/Prices are out of date/)).toBeNull();
    expect(screen.getAllByRole('contentinfo')).toHaveLength(1);
    expect(screen.getByRole('navigation', { name: 'Site' })).toBeTruthy();
    expect(document.querySelectorAll('link[rel="canonical"]')).toHaveLength(1);
    expect(document.body.querySelectorAll('link')).toHaveLength(0);
  });
});
