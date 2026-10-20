import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Button, ButtonInput, type ButtonVariant } from './Button';

afterEach(() => {
  cleanup();
});

const VARIANTS: readonly ButtonVariant[] = ['primary', 'secondary', 'quiet', 'danger', 'segment'];

describe('Button (D1 primitive)', () => {
  it('renders a real button named by its text, of type button unless told otherwise', () => {
    render(<Button variant="secondary">Sign out</Button>);
    const button = screen.getByRole<HTMLButtonElement>('button', { name: 'Sign out' });
    expect(button.tagName).toBe('BUTTON');
    expect(button.type).toBe('button');
  });

  it('forwards type submit, so a form submits through it', () => {
    const onSubmit = vi.fn((event: { preventDefault: () => void }) => {
      event.preventDefault();
    });
    render(
      <form onSubmit={onSubmit}>
        <Button variant="primary" type="submit">
          Sign in
        </Button>
      </form>,
    );
    const button = screen.getByRole<HTMLButtonElement>('button', { name: 'Sign in' });
    expect(button.type).toBe('submit');
    fireEvent.click(button);
    expect(onSubmit).toHaveBeenCalledTimes(1);
  });

  it('renders every variant as a button with its name and no extra text', () => {
    for (const variant of VARIANTS) {
      render(
        <Button variant={variant} pressed={variant === 'segment' ? false : undefined}>
          {`Label ${variant}`}
        </Button>,
      );
      const button = screen.getByRole('button', { name: `Label ${variant}` });
      expect(button.textContent).toBe(`Label ${variant}`);
      cleanup();
    }
  });

  it('forwards disabled: the button is disabled and a click does nothing', () => {
    const onClick = vi.fn();
    render(
      <Button variant="danger" disabled onClick={onClick}>
        Delete the account
      </Button>,
    );
    const button = screen.getByRole<HTMLButtonElement>('button', { name: 'Delete the account' });
    expect(button.disabled).toBe(true);
    fireEvent.click(button);
    expect(onClick).not.toHaveBeenCalled();
  });

  it('calls onClick when enabled', () => {
    const onClick = vi.fn();
    render(
      <Button variant="quiet" onClick={onClick}>
        Cancel
      </Button>,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('marks a segment pressed or not with aria-pressed, the check glyph adding no DOM text', () => {
    render(
      <div>
        <Button variant="segment" pressed>
          Rises
        </Button>
        <Button variant="segment" pressed={false}>
          Falls
        </Button>
      </div>,
    );
    const rises = screen.getByRole('button', { name: 'Rises', pressed: true });
    const falls = screen.getByRole('button', { name: 'Falls', pressed: false });
    expect(rises.getAttribute('aria-pressed')).toBe('true');
    expect(falls.getAttribute('aria-pressed')).toBe('false');
    expect(rises.textContent).toBe('Rises');
    expect(rises.querySelectorAll('*')).toHaveLength(0);
  });

  it('gives no aria-pressed to the non-segment variants', () => {
    render(<Button variant="primary">Save</Button>);
    expect(screen.getByRole('button', { name: 'Save' }).hasAttribute('aria-pressed')).toBe(false);
  });
});

describe('ButtonInput (R221: input-as-button)', () => {
  it('renders <input type="button"> whose words are its value and whose name is its aria-label', () => {
    render(
      <ButtonInput
        variant="secondary"
        value="Add to watchlist"
        aria-label="Add holo, unlimited to your watchlist"
      />,
    );
    const input = screen.getByRole<HTMLInputElement>('button', {
      name: 'Add holo, unlimited to your watchlist',
    });
    expect(input.tagName).toBe('INPUT');
    expect(input.type).toBe('button');
    expect(input.value).toBe('Add to watchlist');
    expect(input.textContent).toBe('');
  });

  it('forwards disabled and onClick', () => {
    const onClick = vi.fn();
    const { rerender } = render(
      <ButtonInput
        variant="secondary"
        value="Add to portfolio"
        aria-label="Add"
        disabled
        onClick={onClick}
      />,
    );
    const input = screen.getByRole<HTMLInputElement>('button', { name: 'Add' });
    expect(input.disabled).toBe(true);
    fireEvent.click(input);
    expect(onClick).not.toHaveBeenCalled();
    rerender(
      <ButtonInput
        variant="secondary"
        value="Add to portfolio"
        aria-label="Add"
        onClick={onClick}
      />,
    );
    expect(input.disabled).toBe(false);
    fireEvent.click(input);
    expect(onClick).toHaveBeenCalledTimes(1);
  });
});
