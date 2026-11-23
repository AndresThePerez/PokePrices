// The About page (/about): what PokéPrices is, where its prices come from, who built it, the
// source, the affiliation disclaimer, the privacy paragraph and the contact link. It shows no
// price and sends no request. The privacy paragraph states only what the code does.
import { useId, type ReactNode } from 'react';
import { TEXT_LINK } from '../ui/classes';
import Page from '../ui/Page';
import { BUILDER_NAME, BUILDER_URL, SOURCE_URL, TCGCSV_URL } from '../ui/siteLinks';

function Section({ title, children }: { readonly title: string; readonly children: ReactNode }) {
  const headingId = useId();
  return (
    <section aria-labelledby={headingId} className="mt-8">
      <h2 id={headingId} className="text-lg font-semibold">
        {title}
      </h2>
      {children}
    </section>
  );
}

export default function AboutPage() {
  return (
    <Page>
      <h1 className="text-2xl font-semibold sm:text-4xl">About</h1>
      <p className="mt-4 text-base">
        PokéPrices shows Pokémon card prices at the daily close: one TCGplayer market price per card
        variant per day, collected once a day. Every price names its source and the day it is as of.
        A price is not a running quote: it changes once a day, when the next day’s close is
        collected.
      </p>
      <Section title="Where the prices come from">
        <p className="mt-2 text-base">
          The prices are TCGplayer market prices, collected through{' '}
          <a href={TCGCSV_URL} className={TEXT_LINK}>
            TCGCSV
          </a>
          , which publishes them once a day. Thank you to TCGCSV for making the price data
          available. Card names, details and pictures come from a card catalogue.
        </p>
      </Section>
      <Section title="Who built it">
        <p className="mt-2 text-base">
          PokéPrices is built by{' '}
          <a href={BUILDER_URL} className={TEXT_LINK}>
            {BUILDER_NAME}
          </a>
          . Read its{' '}
          <a href={SOURCE_URL} className={TEXT_LINK}>
            source code on GitHub
          </a>
          .
        </p>
      </Section>
      <Section title="Not affiliated">
        <p className="mt-2 text-base">
          PokéPrices is not affiliated with or endorsed by Nintendo, The Pokémon Company, Game
          Freak, Creatures or TCGplayer. Pokémon card images and names belong to their owners.
        </p>
      </Section>
      <Section title="Privacy">
        <p className="mt-2 text-base">
          Browsing prices sets no cookies. Opening a sign-in or account page (sign-in, account,
          watchlist, portfolio, alerts), signing in or trying the demo sets two: a session cookie,
          which keeps you signed in once you are, and a security token that protects the site’s
          forms. An account stores its email address, a hash of its password, its two-factor
          setting, its watchlist and portfolio, its alert settings and webhooks, a record of the
          alerts sent to it, and for each signed-in session its IP address, browser and last
          activity. You can download that data or delete your account on the Account page. Display
          settings stay in your browser. Card pictures load from a third-party image host, which
          receives your browser’s request for each picture. The site is served through Cloudflare,
          which carries every request on its way to PokéPrices.
        </p>
      </Section>
      <Section title="Contact">
        <p className="mt-2 text-base">
          Questions and corrections are welcome through{' '}
          <a href={BUILDER_URL} className={TEXT_LINK}>
            andrestheperez.com
          </a>
          .
        </p>
      </Section>
    </Page>
  );
}
