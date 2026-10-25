import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ApiFailure } from '../api/client';
import {
  accountValidationProblem,
  alreadySignedInProblem,
  lightOnlyClasses,
  serverErrorProblem,
  shortControls,
  tooManyRequestsProblem,
} from '../test/fixtures';
import { Field, ProblemBlock, StatusLine } from './Form';
import { firstErrors } from './fields';

afterEach(() => {
  cleanup();
});

function failureOf(problem: ApiFailure['problem'] & object): ApiFailure {
  return {
    ok: false,
    status: problem.status,
    type: problem.type,
    title: problem.title,
    requestId: problem.request_id,
    problem,
  };
}

const noop = (): void => undefined;

describe('Field (D10)', () => {
  it('gives the input a visible label, its hint and, on error, aria-invalid and the message', () => {
    render(
      <Field
        label="Password"
        name="password"
        type="password"
        autoComplete="new-password"
        value=""
        onChange={noop}
        hint="At least 12 characters."
        error="The password field must be at least 12 characters."
      />,
    );
    const input = screen.getByLabelText('Password');
    expect(screen.getByText('Password').tagName).toBe('LABEL');
    expect(input.getAttribute('aria-invalid')).toBe('true');
    const described = (input.getAttribute('aria-describedby') ?? '')
      .split(/\s+/)
      .map((id) => document.getElementById(id)?.textContent);
    expect(described).toEqual([
      'At least 12 characters.',
      'The password field must be at least 12 characters.',
    ]);
    expect(input.className.split(/\s+/)).toContain('min-h-11');
    expect(lightOnlyClasses(document.body)).toEqual([]);
  });

  it('has no aria-invalid or describedby without an error or hint', () => {
    render(
      <Field
        label="Email"
        name="email"
        type="email"
        autoComplete="email"
        value=""
        onChange={noop}
      />,
    );
    const input = screen.getByLabelText('Email');
    expect(input.getAttribute('aria-invalid')).toBeNull();
    expect(input.getAttribute('aria-describedby')).toBeNull();
  });
});

describe('Field reveal and required (D2-i2-T06)', () => {
  it('renders the reveal toggle beside the input: named, pressed, flipping the type', () => {
    const onToggle = vi.fn();
    const { rerender } = render(
      <Field
        label="Password"
        name="password"
        type="password"
        autoComplete="new-password"
        value=""
        onChange={noop}
        required
        reveal={{ label: 'Show password', shown: false, onToggle }}
      />,
    );
    const input = screen.getByLabelText('Password');
    expect(input.getAttribute('type')).toBe('password');
    expect(input.getAttribute('aria-required')).toBe('true');
    const toggle = screen.getByRole('button', { name: 'Show password' });
    expect(toggle.getAttribute('type')).toBe('button');
    expect(toggle.textContent).toBe('Show');
    expect(toggle.getAttribute('aria-pressed')).toBe('false');
    expect(toggle.closest('label')).toBeNull();
    expect(shortControls(document.body)).toEqual([]);
    fireEvent.click(toggle);
    expect(onToggle).toHaveBeenCalledTimes(1);
    rerender(
      <Field
        label="Password"
        name="password"
        type="password"
        autoComplete="new-password"
        value=""
        onChange={noop}
        required
        reveal={{ label: 'Show password', shown: true, onToggle }}
      />,
    );
    expect(screen.getByLabelText('Password').getAttribute('type')).toBe('text');
    expect(screen.getByRole('button', { name: 'Show password' }).getAttribute('aria-pressed')).toBe(
      'true',
    );
    expect(lightOnlyClasses(document.body)).toEqual([]);
  });
});

describe('ProblemBlock (D10)', () => {
  it('names the context and the title, with the request id', () => {
    render(
      <ProblemBlock
        context="Could not sign out"
        failure={failureOf(serverErrorProblem('/logout'))}
      />,
    );
    const alert = screen.getByRole('alert');
    expect(alert.querySelector('p')?.textContent).toBe('Could not sign out: Internal Server Error');
    expect(alert.textContent).toContain('Request ID req-p4-server-0007');
    expect(lightOnlyClasses(alert)).toEqual([]);
  });

  it('points a 422 at the fields and lists errors of fields the form does not show', () => {
    const problem = accountValidationProblem('/reset-password', {
      password: ['Too short.'],
      token: ['The token field is required.'],
    });
    render(
      <ProblemBlock
        context="Could not save the password"
        failure={failureOf(problem)}
        fields={['email', 'password']}
      />,
    );
    const alert = screen.getByRole('alert');
    expect(alert.querySelector('p')?.textContent).toBe(
      'Could not save the password: check the fields below.',
    );
    expect(alert.textContent).toContain('The token field is required.');
    expect(alert.textContent).not.toContain('Too short.');
  });

  it('says to wait on a 429', () => {
    render(
      <ProblemBlock
        context="Could not sign in"
        failure={failureOf(tooManyRequestsProblem('/login'))}
      />,
    );
    expect(screen.getByRole('alert').querySelector('p')?.textContent).toBe(
      'Could not sign in: Too Many Requests. Wait a minute and try again.',
    );
  });

  it('turns a 409 already-signed-in into its line and a link to the account', () => {
    render(
      <ProblemBlock context="Could not sign in" failure={failureOf(alreadySignedInProblem)} />,
    );
    const alert = screen.getByRole('alert');
    expect(alert.querySelector('p')?.textContent).toBe('You are already signed in.');
    const link = within(alert).getByRole('link', { name: 'Go to your account' });
    expect(link.getAttribute('href')).toBe('/account');
    expect(shortControls(alert)).toEqual([]);
    expect(alert.textContent).not.toContain('Could not sign in');
  });

  it('shows a non-problem failure by its title, without a request id line', () => {
    render(
      <ProblemBlock
        context="Could not sign in"
        failure={{
          ok: false,
          status: 0,
          type: 'about:blank',
          title: 'The PokéPrices API could not be reached',
          requestId: null,
          problem: null,
        }}
      />,
    );
    const alert = screen.getByRole('alert');
    expect(alert.textContent).toBe('Could not sign in: The PokéPrices API could not be reached');
  });
});

describe('StatusLine and firstErrors', () => {
  it('announces a status', () => {
    render(<StatusLine>Verification email sent.</StatusLine>);
    expect(screen.getByRole('status').textContent).toBe('Verification email sent.');
    expect(lightOnlyClasses(document.body)).toEqual([]);
  });

  it('takes the first message of each field of a 422, and nothing otherwise', () => {
    const problem = accountValidationProblem('/register', {
      email: ['First.', 'Second.'],
      password: [],
    });
    expect(firstErrors(failureOf(problem))).toEqual({ email: 'First.' });
    expect(firstErrors(failureOf(serverErrorProblem('/register')))).toEqual({});
    expect(firstErrors(null)).toEqual({});
  });
});
