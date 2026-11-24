// The problem block: one alert with "<context>: <title>" on its first line and, for a server
// error (5xx) only, the failure's request id under it when there is one: a 4xx is the visitor's to
// put right and its id helps nobody. The public pages print it as it is (no "wait a minute" line
// on a 429); the account ProblemBlock passes its own whole first line (`headline`) and its field
// messages (`children`, printed before the request id line).
// Under the alert, "Try again" repeats the read that failed: the block is given the failure a
// query holds as its error, so it asks that query again, and the page shows its loading line and
// then the answer or the next failure; focus moves to the content region. A failure no query holds (a failed write, whose form is the
// way to try again) and a block outside a query client get no button.
import { QueryClientContext, type Query } from '@tanstack/react-query';
import { useContext, type ReactNode } from 'react';
import type { ApiFailure } from '../api/client';
import { SECONDARY_BUTTON } from './classes';
import Notice from './Notice';

export default function ProblemNotice({
  context,
  failure,
  className = '',
  headline,
  children,
}: {
  readonly context: string;
  readonly failure: ApiFailure;
  readonly className?: string;
  readonly headline?: string;
  readonly children?: ReactNode;
}) {
  const queryClient = useContext(QueryClientContext);
  const failed = (query: Query): boolean => {
    const held: unknown = query.state.error;
    return held === failure;
  };
  const repeatable = queryClient?.getQueryCache().findAll({ predicate: failed }).length;
  return (
    <>
      <Notice tone="problem" role="alert" className={className}>
        <p className="font-semibold">
          {headline ?? (
            <>
              {`${context}: `}
              {failure.title}
            </>
          )}
        </p>
        {children}
        {failure.requestId !== null && failure.status >= 500 && (
          <p className="mt-1 text-sm">
            Request ID <code className="break-all">{failure.requestId}</code>
          </p>
        )}
      </Notice>
      {queryClient !== undefined && repeatable !== undefined && repeatable > 0 && (
        <p className="mt-3">
          <button
            type="button"
            onClick={() => {
              // The button leaves with the block: focus goes to the page's content region
              // (focusable, outside the tab order) and not to the top of the document.
              document.getElementById('content')?.focus({ preventScroll: true });
              void queryClient.refetchQueries({ predicate: failed }, { cancelRefetch: false });
            }}
            className={SECONDARY_BUTTON}
          >
            Try again
          </button>
        </p>
      )}
    </>
  );
}
