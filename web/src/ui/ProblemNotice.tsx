// The problem block (D10, Q1-i5-T05): one alert with "<context>: <title>" on its first line and the
// failure's request id under it when there is one. The public pages print it as it is (no "wait a
// minute" line on a 429, HG-45 #3); the account ProblemBlock passes its own whole first line
// (`headline`) and its field messages (`children`, printed before the request id line).
import type { ReactNode } from 'react';
import type { ApiFailure } from '../api/client';
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
  return (
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
      {failure.requestId !== null && (
        <p className="mt-1 text-sm">
          Request ID <code className="break-all">{failure.requestId}</code>
        </p>
      )}
    </Notice>
  );
}
