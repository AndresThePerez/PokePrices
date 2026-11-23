// The PokéPrices search form (shared by the home landing and /search): one labelled search box and
// one submit, which navigates to /search?q=<trimmed>. It fetches nothing itself; the search page
// asks GET /v1/cards for the query in the URL. `hero` is the home size: a 48 px box in the column.
// A query of more than 32 words is stopped here with the API's own sentence, and the API's 422 on
// q (`error`) shows in the same place, under the box.
import { useId, useState, type SubmitEvent } from 'react';
import { navigate } from '../router';
import { Button } from '../ui/Button';
import { ERROR } from '../ui/classes';
import { Input } from '../ui/Field';

/** The most words a search may have, as the API and PokéSearch count them. */
const MAX_WORDS = 32;

/** The API's 422 message on q above MAX_WORDS, word for word. */
const WORD_CAP = 'A search can have at most 32 words.';

/**
 * Whitespace as PokéSearch splits a query into terms (Go's unicode.IsSpace): tab to carriage
 * return, space, U+0085, U+00A0, U+1680, U+2000 to U+200A, U+2028, U+2029, U+202F, U+205F, U+3000.
 */
const TERM_GAP = /[\t\n\v\f\r \u0085\u00a0\u1680\u2000-\u200a\u2028\u2029\u202f\u205f\u3000]+/u;

/** The number of words PokéSearch makes of `q`. */
function wordCount(q: string): number {
  return q.split(TERM_GAP).filter((term) => term !== '').length;
}

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
  error,
}: {
  readonly initial: string;
  readonly size?: SearchFormSize;
  /** The API's message on q for the query in the URL, shown under the box until the text changes. */
  readonly error?: string | undefined;
}) {
  const [text, setText] = useState(initial);
  // The form's own word-cap check, from a submit until the text changes.
  const [tooLong, setTooLong] = useState(false);
  const [edited, setEdited] = useState(false);
  const errorId = useId();
  const message = tooLong ? WORD_CAP : edited ? undefined : error;
  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (wordCount(text) > MAX_WORDS) {
      setTooLong(true);
      return;
    }
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
          aria-invalid={message === undefined ? undefined : true}
          aria-describedby={message === undefined ? undefined : errorId}
          onChange={(event) => {
            setText(event.target.value);
            setTooLong(false);
            setEdited(true);
          }}
          className={BOX[size]}
        />
      </label>
      <Button variant="primary" type="submit" className={SUBMIT[size]}>
        Search
      </Button>
      {message !== undefined && (
        <p id={errorId} className={`w-full ${ERROR}`}>
          {message}
        </p>
      )}
    </form>
  );
}
