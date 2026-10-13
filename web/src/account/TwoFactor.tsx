// The two-factor section of /account (D10, T04): off with `Turn on`; the password prompt when
// Fortify answers 423; the setup step (QR code, setup key, authentication code, Confirm); on with
// `Show new recovery codes` and `Turn off`; the recovery codes, shown once right after confirming
// or regenerating. The QR code, the setup key and the codes live in this component's state only,
// never in the query cache or in storage, so leaving /account drops them for good.
import { useQueryClient } from '@tanstack/react-query';
import { useId, useRef, useState, type SubmitEvent } from 'react';
import {
  confirmPassword,
  confirmTwoFactor,
  disableTwoFactor,
  enableTwoFactor,
  recoveryCodes,
  regenerateRecoveryCodes,
  twoFactorQrCode,
  twoFactorSecretKey,
  type ApiFailure,
  type Me,
  type RecoveryCodes,
} from '../api/client';
import { Field, ProblemBlock } from './Form';
import { PRIMARY_BUTTON, SECONDARY_BUTTON, firstErrors } from './fields';
import { ME_KEY } from './session';

const CONTEXT = 'Could not change two-factor authentication';

/** The one named exemption from the lightOnly rule (review F-P4-10): a white quiet zone for scanners in both themes. */
const QR_BOX = 'inline-block bg-white p-4';

interface Setup {
  readonly svg: string;
  readonly secretKey: string;
}

/** What a step left to do: nothing (null), or a failure and the step that repeats it. */
type Outcome = { readonly failure: ApiFailure; readonly retry: Step } | null;
type Step = () => Promise<Outcome>;

function QrCode({ svg }: { readonly svg: string }) {
  return (
    <div className={QR_BOX}>
      <img
        alt="QR code for your authenticator app"
        src={`data:image/svg+xml,${encodeURIComponent(svg)}`}
        width={192}
        height={192}
        className="block"
      />
    </div>
  );
}

function RecoveryCodeList({ codes }: { readonly codes: RecoveryCodes }) {
  return (
    <div className="mt-4">
      <h3 className="text-lg font-semibold">Recovery codes</h3>
      <p className="mt-1">Store these codes somewhere safe. Each one works once.</p>
      <ul className="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2">
        {codes.map((code) => (
          <li key={code}>
            <code className="break-all">{code}</code>
          </li>
        ))}
      </ul>
    </div>
  );
}

export default function TwoFactorSection({ me }: { readonly me: Me }) {
  const queryClient = useQueryClient();
  const headingId = useId();
  const [setup, setSetup] = useState<Setup | null>(null);
  const [codes, setCodes] = useState<RecoveryCodes | null>(null);
  const [asking, setAsking] = useState(false);
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  // The step that answered 423: it runs again once the password is confirmed.
  const awaiting = useRef<Step | null>(null);
  const errors = firstErrors(failure);
  const on = me.two_factor;

  async function refreshMe(): Promise<void> {
    await queryClient.invalidateQueries({ queryKey: ME_KEY });
  }

  async function run(step: Step): Promise<void> {
    setPending(true);
    setFailure(null);
    const outcome = await step();
    setPending(false);
    if (outcome === null) return;
    if (outcome.failure.status === 423) {
      awaiting.current = outcome.retry;
      setAsking(true);
      return;
    }
    setFailure(outcome.failure);
  }

  const showCodes: Step = async () => {
    const list = await recoveryCodes();
    if (!list.ok) return { failure: list, retry: showCodes };
    setCodes(list.data);
    return null;
  };

  const turnOn: Step = async () => {
    const enabled = await enableTwoFactor();
    if (!enabled.ok) return { failure: enabled, retry: turnOn };
    const qr = await twoFactorQrCode();
    if (!qr.ok) return { failure: qr, retry: turnOn };
    const key = await twoFactorSecretKey();
    if (!key.ok) return { failure: key, retry: turnOn };
    setSetup({ svg: qr.data.svg, secretKey: key.data.secretKey });
    return null;
  };

  function confirmWith(value: string): Step {
    const step: Step = async () => {
      const confirmed = await confirmTwoFactor({ code: value });
      if (!confirmed.ok) return { failure: confirmed, retry: step };
      const list = await recoveryCodes();
      await refreshMe();
      setSetup(null);
      if (!list.ok) return { failure: list, retry: showCodes };
      setCodes(list.data);
      return null;
    };
    return step;
  }

  const renewCodes: Step = async () => {
    const renewed = await regenerateRecoveryCodes();
    if (!renewed.ok) return { failure: renewed, retry: renewCodes };
    return showCodes();
  };

  const turnOff: Step = async () => {
    const disabled = await disableTwoFactor();
    if (!disabled.ok) return { failure: disabled, retry: turnOff };
    await refreshMe();
    setCodes(null);
    setSetup(null);
    return null;
  };

  function start(step: Step): void {
    if (!pending) void run(step);
  }

  async function sendPassword(): Promise<void> {
    setPending(true);
    setFailure(null);
    const result = await confirmPassword({ password });
    // The password is never kept after a submit, whatever the answer.
    setPassword('');
    setPending(false);
    if (!result.ok) {
      setFailure(result);
      return;
    }
    setAsking(false);
    const retry = awaiting.current;
    awaiting.current = null;
    if (retry !== null) await run(retry);
  }

  function submitPassword(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (!pending) void sendPassword();
  }

  function submitCode(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (pending) return;
    const value = code;
    // The code is never kept after a submit, whatever the answer.
    setCode('');
    void run(confirmWith(value));
  }

  const settingUp = !on && setup !== null;
  const shownFields = [asking ? 'password' : null, settingUp ? 'code' : null].filter(
    (name): name is string => name !== null,
  );

  return (
    <section
      aria-labelledby={headingId}
      className="mt-8 border-t border-slate-200 pt-6 dark:border-slate-700"
    >
      <h2 id={headingId} className="text-xl font-semibold">
        Two-factor authentication
      </h2>
      <p className="mt-2">
        {on ? 'Two-factor authentication is on.' : 'Two-factor authentication is off.'}
      </p>
      {on && codes !== null && <RecoveryCodeList codes={codes} />}
      {settingUp && (
        <div className="mt-4">
          <QrCode svg={setup.svg} />
          <p className="mt-4">
            Setup key <code className="break-all select-all">{setup.secretKey}</code>
          </p>
          <form noValidate onSubmit={submitCode} className="mt-4 space-y-4">
            <Field
              label="Authentication code"
              name="code"
              type="text"
              inputMode="numeric"
              autoComplete="one-time-code"
              value={code}
              onChange={setCode}
              error={errors.code}
            />
            <button type="submit" disabled={pending} className={PRIMARY_BUTTON}>
              Confirm
            </button>
          </form>
        </div>
      )}
      {asking ? (
        <form noValidate onSubmit={submitPassword} className="mt-4 space-y-4">
          <Field
            label="Password"
            name="password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={setPassword}
            error={errors.password}
          />
          <button type="submit" disabled={pending} className={PRIMARY_BUTTON}>
            Confirm password
          </button>
        </form>
      ) : (
        !settingUp && (
          <p className="mt-4 flex flex-wrap gap-3">
            {on && (
              <>
                <button
                  type="button"
                  disabled={pending}
                  onClick={() => {
                    start(renewCodes);
                  }}
                  className={SECONDARY_BUTTON}
                >
                  Show new recovery codes
                </button>
                <button
                  type="button"
                  disabled={pending}
                  onClick={() => {
                    start(turnOff);
                  }}
                  className={SECONDARY_BUTTON}
                >
                  Turn off two-factor authentication
                </button>
              </>
            )}
            {!on && (
              <button
                type="button"
                disabled={pending}
                onClick={() => {
                  start(turnOn);
                }}
                className={SECONDARY_BUTTON}
              >
                Turn on two-factor authentication
              </button>
            )}
          </p>
        )
      )}
      {failure !== null && (
        <ProblemBlock context={CONTEXT} failure={failure} fields={shownFields} />
      )}
    </section>
  );
}
