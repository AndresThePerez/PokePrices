// The page-level sign-in prompt: the page's own sentence, then Sign in (and back to `next`) and,
// where the page offers it, Create an account, then whatever the page adds under them (the demo
// button of the signed-out lists). `next` goes into the link as given, so a call site that carries
// a query string encodes it itself. The card page's inline prompt is an outcome line of a write and
// stays in CardActions.
import type { ReactNode } from 'react';
import Link from '../Link';
import { TEXT_LINK } from './fields';

export default function SignInPrompt({
  sentence,
  next,
  signUp = false,
  base = false,
  children,
}: {
  readonly sentence: string;
  readonly next: string;
  /** Whether Create an account shows beside Sign in. */
  readonly signUp?: boolean;
  /** The sentence at the base text size (the signed-out lists). */
  readonly base?: boolean;
  readonly children?: ReactNode;
}) {
  const signIn = (
    <Link to={`/sign-in?next=${next}`} className={TEXT_LINK}>
      Sign in
    </Link>
  );
  return (
    <>
      <p className={base ? 'mt-6 text-base' : 'mt-6'}>{sentence}</p>
      {signUp ? (
        <p className="mt-2 flex flex-wrap gap-x-6">
          {signIn}
          <Link to="/sign-up" className={TEXT_LINK}>
            Create an account
          </Link>
        </p>
      ) : (
        <p className="mt-2">{signIn}</p>
      )}
      {children}
    </>
  );
}
