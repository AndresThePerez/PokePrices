// The shared sign-in prompt must print the DOM each of the three page-level prompts printed at
// 32e848a (the signed-out lists, /alerts and the verification link page). The expected markup is
// copied from that commit's sources and compared as HTML; the test never asserts a class on its own
// (R255), it only demands equality with the old copy. Each site is checked twice: the component
// with the site's props, and the page itself, signed out.
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import Link from '../Link';
import {
  clearXsrfCookie,
  problemResponse,
  stubAccountFetch,
  unauthenticatedProblem,
} from '../test/fixtures';
import DemoButton from './DemoButton';
import { TEXT_LINK } from './fields';
import SignInPrompt from './SignInPrompt';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

function htmlOf(node: ReactNode): string {
  const view = render(<QueryClientProvider client={new QueryClient()}>{node}</QueryClientProvider>);
  const html = view.container.innerHTML;
  view.unmount();
  return html;
}

const SIGNATURE = 'fake0signature0000beef';
const LINK = `/email/verify/7/0a1b2c?expires=1700000000&signature=${SIGNATURE}`;

/** account/ListParts.tsx SignedOutList at 32e848a. */
function OldSignedOutList({ list }: { readonly list: 'watchlist' | 'portfolio' }) {
  return (
    <>
      <p className="mt-6 text-base">{`Sign in to keep a ${list}.`}</p>
      <p className="mt-2 flex flex-wrap gap-x-6">
        <Link to={`/sign-in?next=/${list}`} className={TEXT_LINK}>
          Sign in
        </Link>
        <Link to="/sign-up" className={TEXT_LINK}>
          Create an account
        </Link>
      </p>
      <DemoButton />
    </>
  );
}

/** account/AlertsPage.tsx SignedOut at 32e848a. */
function OldAlertsSignedOut() {
  return (
    <>
      <p className="mt-6">Sign in to set up alerts and webhooks.</p>
      <p className="mt-2 flex flex-wrap gap-x-6">
        <Link to="/sign-in?next=/alerts" className={TEXT_LINK}>
          Sign in
        </Link>
        <Link to="/sign-up" className={TEXT_LINK}>
          Create an account
        </Link>
      </p>
    </>
  );
}

/** account/VerifyEmailPage.tsx SignInFirst at 32e848a. */
function OldSignInFirst({ link }: { readonly link: string }) {
  const here = `/account/verify-email?link=${encodeURIComponent(link)}`;
  return (
    <>
      <p className="mt-6">Sign in to verify your email address.</p>
      <p className="mt-2">
        <Link to={`/sign-in?next=${encodeURIComponent(here)}`} className={TEXT_LINK}>
          Sign in
        </Link>
      </p>
    </>
  );
}

interface Site {
  readonly name: string;
  readonly path: string;
  readonly sentence: string;
  /** How many elements the prompt prints, from its sentence on. */
  readonly elements: number;
  readonly old: ReactNode;
  readonly shared: () => ReactNode;
}

const HERE = `/account/verify-email?link=${encodeURIComponent(LINK)}`;

const SITES: readonly Site[] = [
  ...(['watchlist', 'portfolio'] as const).map((list): Site => ({
    name: `account/ListParts.tsx SignedOutList (${list})`,
    path: `/${list}`,
    sentence: `Sign in to keep a ${list}.`,
    elements: 3,
    old: <OldSignedOutList list={list} />,
    shared: () => (
      <SignInPrompt sentence={`Sign in to keep a ${list}.`} next={`/${list}`} signUp base>
        <DemoButton />
      </SignInPrompt>
    ),
  })),
  {
    name: 'account/AlertsPage.tsx SignedOut',
    path: '/alerts',
    sentence: 'Sign in to set up alerts and webhooks.',
    elements: 2,
    old: <OldAlertsSignedOut />,
    shared: () => (
      <SignInPrompt sentence="Sign in to set up alerts and webhooks." next="/alerts" signUp />
    ),
  },
  {
    name: 'account/VerifyEmailPage.tsx SignInFirst',
    path: HERE,
    sentence: 'Sign in to verify your email address.',
    elements: 2,
    old: <OldSignInFirst link={LINK} />,
    shared: () => (
      <SignInPrompt
        sentence="Sign in to verify your email address."
        next={encodeURIComponent(HERE)}
      />
    ),
  },
];

/** The outer HTML of `count` sibling elements, from `first` on. */
function siblingsHtml(first: Element, count: number): string {
  let html = '';
  let element: Element | null = first;
  for (let index = 0; index < count; index += 1) {
    if (element === null) throw new Error(`only ${String(index)} elements`);
    html += element.outerHTML;
    element = element.nextElementSibling;
  }
  return html;
}

describe('the shared sign-in prompt prints the 32e848a markup of every page-level prompt', () => {
  it.each(SITES)('$name: the component with the site props is the old prompt', (site) => {
    expect(htmlOf(site.shared())).toBe(htmlOf(site.old));
  });

  it.each(SITES)('$name: the signed-out page prints the old prompt', async (site) => {
    stubAccountFetch({ 'GET /v1/me': problemResponse(unauthenticatedProblem) });
    visit(site.path);
    render(<App />);
    const sentence = await screen.findByText(site.sentence);
    expect(siblingsHtml(sentence, site.elements)).toBe(htmlOf(site.old));
  });
});
