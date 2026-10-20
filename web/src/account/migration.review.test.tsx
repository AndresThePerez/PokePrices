// T09 review: the account pages moved to the design tokens without changing markup. These guard
// the parts the visual migration touched and no other suite pins: the webhook form's controls
// after the Labelled -> Field swap, the delete button, the demo button and the heading outline.
import { QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import { createQueryClient } from '../query';
import AlertsPage from './AlertsPage';
import {
  accountValidationProblem,
  clearXsrfCookie,
  jsonResponse,
  notificationsBody,
  problemResponse,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  webhookOf,
  webhooksBody,
} from '../test/fixtures';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

function main(): HTMLElement {
  return screen.getByRole('main');
}

function renderAlerts(): void {
  render(
    <QueryClientProvider client={createQueryClient()}>
      <AlertsPage />
    </QueryClientProvider>,
  );
}

function headings(root: HTMLElement): string[] {
  return within(root)
    .getAllByRole('heading')
    .map((heading) => `${heading.tagName} ${heading.textContent}`);
}

describe('the webhook form after the Field swap (T09)', () => {
  it('has exactly Type, URL and Description in that order, each label pointing at its own control', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
    });
    renderAlerts();
    const submit = await screen.findByRole('button', { name: 'Add webhook' });
    const form = submit.closest('form');
    if (form === null) throw new Error('the add button is inside a form');
    expect(form.hasAttribute('novalidate')).toBe(true);
    const labels = Array.from(form.querySelectorAll('label'));
    expect(labels.map((label) => label.textContent)).toEqual([
      'Type',
      'URL',
      'Description (optional)',
    ]);
    const controls = Array.from(form.querySelectorAll('input, select, textarea, button'));
    expect(
      controls.map((control) => `${control.tagName} ${control.getAttribute('name') ?? ''}`),
    ).toEqual(['SELECT kind', 'INPUT url', 'INPUT description', 'BUTTON ']);
    labels.forEach((label, index) => {
      const control = controls[index];
      expect(label.htmlFor).not.toBe('');
      expect(control?.id).toBe(label.htmlFor);
    });
    expect(new Set(labels.map((label) => label.htmlFor)).size).toBe(3);
    expect(submit.getAttribute('type')).toBe('submit');
    expect(form.querySelectorAll('[aria-invalid], [aria-describedby]')).toHaveLength(0);
    expect(form.querySelectorAll('p')).toHaveLength(0);
  });

  it('marks only the field that has a 422 message, and describes it with its own message', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
      'POST /v1/webhooks': problemResponse(
        accountValidationProblem('/v1/webhooks', { url: ['The URL must be an https address.'] }),
      ),
    });
    renderAlerts();
    fireEvent.change(await screen.findByLabelText('URL'), {
      target: { value: 'https://hooks.example.com/x' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add webhook' }));
    await screen.findByRole('alert');
    const url = screen.getByLabelText('URL');
    expect(url.getAttribute('aria-invalid')).toBe('true');
    const message = document.getElementById(url.getAttribute('aria-describedby') ?? 'missing');
    expect(message?.tagName).toBe('P');
    expect(message?.textContent).toBe('The URL must be an https address.');
    // Element order inside the field: label, control, message.
    expect(Array.from(url.parentElement?.children ?? []).map((child) => child.tagName)).toEqual([
      'LABEL',
      'INPUT',
      'P',
    ]);
    for (const other of ['Type', 'Description (optional)']) {
      const control = screen.getByLabelText(other);
      expect(control.hasAttribute('aria-invalid')).toBe(false);
      expect(control.hasAttribute('aria-describedby')).toBe(false);
    }
  });

  it('keeps a typed Discord choice in the select and its two option values', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody([webhookOf()])),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
    });
    renderAlerts();
    const type = await screen.findByLabelText<HTMLSelectElement>('Type');
    expect(type.value).toBe('standard');
    fireEvent.change(type, { target: { value: 'discord' } });
    expect(screen.getByLabelText<HTMLSelectElement>('Type').value).toBe('discord');
    expect(Array.from(type.options).map((option) => option.value)).toEqual(['standard', 'discord']);
  });
});

describe('the alerts page outline (T09)', () => {
  it('has one h1 and the h2 sections in their order with the h3 of the webhook form under Webhooks', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
    });
    visit('/alerts');
    render(<App />);
    await screen.findByRole('button', { name: 'Add webhook' });
    expect(headings(main())).toEqual([
      'H1 Alerts and webhooks',
      'H2 Where alerts go',
      'H2 Per-card alerts',
      'H2 Webhooks',
      'H3 Add a webhook',
    ]);
  });
});

describe('the account page (T09)', () => {
  it('keeps the delete control a disabled submit button inside the form that holds the checkbox', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
    });
    visit('/account');
    render(<App />);
    await screen.findByText('Signed in as collector@example.com');
    const deletion = within(main()).getByRole('region', { name: 'Delete account' });
    const button = within(deletion).getByRole<HTMLButtonElement>('button', {
      name: 'Delete my account',
    });
    expect(button.tagName).toBe('BUTTON');
    expect(button.type).toBe('submit');
    expect(button.disabled).toBe(true);
    const form = button.closest('form');
    if (form === null) throw new Error('the delete button is inside a form');
    const checkbox = within(form).getByRole('checkbox', {
      name: 'I understand that this cannot be undone',
    });
    expect(checkbox.getAttribute('type')).toBe('checkbox');
    expect(form.querySelectorAll('button')).toHaveLength(1);
    fireEvent.click(checkbox);
    const password = within(form).getByLabelText<HTMLInputElement>('Password');
    expect(button.disabled).toBe(true);
    fireEvent.change(password, { target: { value: 'fake-password-0000-ok' } });
    expect(button.disabled).toBe(false);
    // DOM order inside the form: checkbox row, password field, then the button.
    const order = Array.from(form.querySelectorAll('input, button')).map(
      (el) => el.id || el.tagName,
    );
    expect(order[0]).toBe(checkbox.id);
    expect(order[1]).toBe(password.id);
    expect(order[2]).toBe('BUTTON');
  });

  it('keeps the h1 and the h2 sections in their order', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
    });
    visit('/account');
    render(<App />);
    await screen.findByText('Signed in as collector@example.com');
    const outline = headings(main());
    expect(outline[0]).toMatch(/^H1 /);
    expect(outline.slice(1)).toEqual([
      'H2 Two-factor authentication',
      'H2 Your data',
      'H2 Delete account',
    ]);
    expect(main().querySelectorAll('h1')).toHaveLength(1);
    expect(screen.getByText('Signed in as collector@example.com').tagName).toBe('P');
  });
});

describe('the sign-in page demo button (T09)', () => {
  it('is a plain type=button button outside the sign-in form, after the submit button', async () => {
    stubAccountFetch({ 'GET /v1/me': problemResponse(unauthenticatedProblem) });
    visit('/sign-in');
    render(<App />);
    const demo = await screen.findByRole<HTMLButtonElement>('button', { name: /read-only demo/ });
    expect(demo.tagName).toBe('BUTTON');
    expect(demo.type).toBe('button');
    expect(demo.textContent).toBe('Try the read-only demo');
    expect(demo.closest('form')).toBeNull();
    const submit = within(main()).getByRole('button', { name: 'Sign in' });
    expect(submit.getAttribute('type')).toBe('submit');
    expect(submit.compareDocumentPosition(demo) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    await waitFor(() => {
      expect(within(main()).getAllByRole('button')).toHaveLength(2);
    });
    expect(main().querySelectorAll('h1')).toHaveLength(1);
  });
});
