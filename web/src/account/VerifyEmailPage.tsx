// /account/verify-email?link= (D10, D2): the verification email's link lands here and carries the
// API's signed path. A link outside D10's pattern is never fetched. Verifying needs the session,
// so a signed-out visitor is sent to sign in first and comes back here through next.
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { isVerificationLink, verifyEmail, type ApiFailure } from '../api/client';
import Link from '../Link';
import { AccountMain, LoadingAccount, ProblemBlock } from './Form';
import { TEXT_LINK } from './fields';
import { ME_KEY, accountKey, useSession } from './session';

const INVALID =
  'This verification link is not valid or has expired. Send a new one from your account.';

function Invalid() {
  return <p className="mt-6">{INVALID}</p>;
}

function SignInFirst({ link }: { readonly link: string }) {
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

function Verify({ link }: { readonly link: string }) {
  const session = useSession();
  const queryClient = useQueryClient();
  // One GET of the link per page visit: never polled, never retried, never refetched on focus.
  const verification = useQuery<true, ApiFailure>({
    queryKey: accountKey('verify-email', link),
    queryFn: async ({ signal }): Promise<true> => {
      const result = await verifyEmail(link, { signal });
      if (!result.ok) {
        const failure: unknown = result;
        throw failure;
      }
      void queryClient.invalidateQueries({ queryKey: ME_KEY });
      return true;
    },
    enabled: session.state === 'signed-in',
    staleTime: Infinity,
    refetchInterval: false,
    refetchOnWindowFocus: false,
  });

  if (session.state === 'loading') return <LoadingAccount />;
  if (session.state === 'failed') {
    return <ProblemBlock context="Could not load your account" failure={session.failure} />;
  }
  if (session.state === 'signed-out') return <SignInFirst link={link} />;
  if (verification.data === true) {
    return (
      <>
        <p className="mt-6">Your email address is verified.</p>
        <p className="mt-2">
          <Link to="/account" className={TEXT_LINK}>
            Go to your account
          </Link>
        </p>
      </>
    );
  }
  const failure = verification.error;
  if (failure === null) return null;
  if (failure.status === 403) return <Invalid />;
  if (failure.status === 401) return <SignInFirst link={link} />;
  return <ProblemBlock context="Could not verify the email address" failure={failure} />;
}

export default function VerifyEmailPage({ link }: { readonly link: string | null }) {
  return (
    <AccountMain title="Verify your email">
      {link !== null && isVerificationLink(link) ? <Verify link={link} /> : <Invalid />}
    </AccountMain>
  );
}
