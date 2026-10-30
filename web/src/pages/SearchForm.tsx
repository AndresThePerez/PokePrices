// The PokéPrices search form (shared by the home landing and /search): one labelled search box and
// one submit, which navigates to /search?q=<trimmed>. It fetches nothing itself; the search page
// asks GET /v1/cards for the query in the URL. `hero` is the home size: a 48 px box in the column.
import { useState, type SubmitEvent } from 'react';
import { navigate } from '../router';
import { Button } from '../ui/Button';
import { Input } from '../ui/Field';

/** The in-app URL of a new search: page 1, which stays implicit (as on the search page's pager). */
function firstPagePath(q: string): string {
  return `/search?${new URLSearchParams({ q }).toString()}`;
}

type SearchFormSize = 'page' | 'hero';

const FORM: Readonly<Record<SearchFormSize, string>> = {
  page: 'mt-4 flex flex-wrap items-end gap-3',
  hero: 'mt-6 flex flex-col gap-3 sm:flex-row sm:items-end',
};

const BOX: Readonly<Record<SearchFormSize, string>> = {
  page: 'font-normal',
  hero: 'min-h-12 font-normal',
};

const SUBMIT: Readonly<Record<SearchFormSize, string>> = {
  page: '',
  hero: 'w-full sm:mb-0.5 sm:w-auto',
};

export default function SearchForm({
  initial,
  size = 'page',
}: {
  readonly initial: string;
  readonly size?: SearchFormSize;
}) {
  const [text, setText] = useState(initial);
  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    navigate(firstPagePath(text.trim()));
  }
  return (
    <form role="search" onSubmit={submit} className={FORM[size]}>
      <label className="flex min-w-0 grow flex-col gap-1 text-sm font-semibold">
        Card name
        <Input
          type="search"
          name="q"
          value={text}
          maxLength={200}
          autoComplete="off"
          onChange={(event) => {
            setText(event.target.value);
          }}
          className={BOX[size]}
        />
      </label>
      <Button variant="primary" type="submit" className={SUBMIT[size]}>
        Search
      </Button>
    </form>
  );
}
