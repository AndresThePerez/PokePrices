import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Field, Input, Select } from './Field';

afterEach(() => {
  cleanup();
});

const noop = (): void => undefined;

function describedTexts(control: Element): (string | null | undefined)[] {
  return (control.getAttribute('aria-describedby') ?? '')
    .split(/\s+/)
    .map((id) => document.getElementById(id)?.textContent);
}

describe('Field (D1 primitive)', () => {
  it('labels the control and ties the hint then the error to it, with aria-invalid', () => {
    render(
      <Field label="Password" hint="At least 12 characters." error="Too short.">
        {(control) => (
          <Input {...control} name="password" type="password" value="" onChange={noop} />
        )}
      </Field>,
    );
    const input = screen.getByLabelText('Password');
    expect(input.tagName).toBe('INPUT');
    expect(screen.getByText('Password').tagName).toBe('LABEL');
    expect(input.getAttribute('aria-invalid')).toBe('true');
    expect(describedTexts(input)).toEqual(['At least 12 characters.', 'Too short.']);
  });

  it('builds the hint and error ids from the control id, as the account forms do', () => {
    render(
      <Field label="Email" hint="Where the links go." error="Required.">
        {(control) => <Input {...control} name="email" type="email" value="" onChange={noop} />}
      </Field>,
    );
    const input = screen.getByLabelText('Email');
    expect(input.id).not.toBe('');
    expect(input.getAttribute('aria-describedby')).toBe(`${input.id}-hint ${input.id}-error`);
  });

  it('has no aria-invalid and no describedby without a hint or an error', () => {
    render(
      <Field label="Email">
        {(control) => <Input {...control} name="email" type="email" value="" onChange={noop} />}
      </Field>,
    );
    const input = screen.getByLabelText('Email');
    expect(input.hasAttribute('aria-invalid')).toBe(false);
    expect(input.hasAttribute('aria-describedby')).toBe(false);
  });

  it('describes by the hint alone when there is no error, and stays valid', () => {
    render(
      <Field label="Name" hint="Shown on your account.">
        {(control) => <Input {...control} name="name" type="text" value="" onChange={noop} />}
      </Field>,
    );
    const input = screen.getByLabelText('Name');
    expect(input.hasAttribute('aria-invalid')).toBe(false);
    expect(describedTexts(input)).toEqual(['Shown on your account.']);
  });
});

describe('Input (D1 primitive)', () => {
  it('forwards name, type, value, readOnly, disabled and onChange', () => {
    const onChange = vi.fn();
    render(
      <Field label="Code">
        {(control) => (
          <Input
            {...control}
            name="code"
            type="text"
            value="123"
            readOnly
            disabled
            onChange={onChange}
          />
        )}
      </Field>,
    );
    const input = screen.getByLabelText<HTMLInputElement>('Code');
    expect(input.name).toBe('code');
    expect(input.type).toBe('text');
    expect(input.value).toBe('123');
    expect(input.readOnly).toBe(true);
    expect(input.disabled).toBe(true);
  });

  it('reports the typed text through onChange', () => {
    const onChange = vi.fn();
    render(
      <Field label="Email">
        {(control) => <Input {...control} name="email" type="email" value="" onChange={onChange} />}
      </Field>,
    );
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'a@example.com' } });
    expect(onChange).toHaveBeenCalledTimes(1);
  });
});

describe('Select (D1 primitive)', () => {
  const OPTIONS = [
    { value: 'system', label: 'System' },
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
  ] as const;

  it('renders a labelled select with the options in order, values and texts as given', () => {
    render(
      <Field label="Theme" hint="Follows the device by default.">
        {(control) => (
          <Select {...control} name="theme" value="light" options={OPTIONS} onChange={noop} />
        )}
      </Field>,
    );
    const select = screen.getByRole<HTMLSelectElement>('combobox', { name: 'Theme' });
    expect(select.tagName).toBe('SELECT');
    expect(select.name).toBe('theme');
    expect(select.value).toBe('light');
    const options = within(select).getAllByRole<HTMLOptionElement>('option');
    expect(options.map((option) => [option.value, option.textContent])).toEqual([
      ['system', 'System'],
      ['light', 'Light'],
      ['dark', 'Dark'],
    ]);
    expect(describedTexts(select)).toEqual(['Follows the device by default.']);
  });

  it('forwards disabled, aria-invalid and onChange', () => {
    const onChange = vi.fn();
    const { rerender } = render(
      <Field label="Theme" error="Pick one.">
        {(control) => (
          <Select
            {...control}
            name="theme"
            value="system"
            options={OPTIONS}
            disabled
            onChange={onChange}
          />
        )}
      </Field>,
    );
    const select = screen.getByRole<HTMLSelectElement>('combobox', { name: 'Theme' });
    expect(select.disabled).toBe(true);
    expect(select.getAttribute('aria-invalid')).toBe('true');
    rerender(
      <Field label="Theme">
        {(control) => (
          <Select {...control} name="theme" value="system" options={OPTIONS} onChange={onChange} />
        )}
      </Field>,
    );
    expect(select.disabled).toBe(false);
    fireEvent.change(select, { target: { value: 'dark' } });
    expect(onChange).toHaveBeenCalledTimes(1);
  });
});
