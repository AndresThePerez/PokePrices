import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { compile } from 'tailwindcss';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { QuantityForm } from '../account/ListParts';
import { FOCUS, SECONDARY_BUTTON } from './classes';
import { Button, ButtonInput, type ButtonVariant } from './Button';
import * as classes from './classes';
import { Field, Input, Select } from './Field';

// Review tests for D1-i1-T02: what the primitives themselves promise beyond the implementer's
// suites. The class strings are read as data (no DOM class assertions): the legacy lists.edge pin
// compares CardActions to the fields.ts constants, so only these tests tie Button.tsx to them.
const NODE_FS = 'node:fs';
const { readFileSync, readdirSync } = (await import(/* @vite-ignore */ NODE_FS)) as {
  readonly readFileSync: (path: string, encoding: 'utf8') => string;
  readonly readdirSync: (path: string) => string[];
};
const testFile = import.meta.url;
const uiDir = new URL('.', testFile).pathname;
const tailwindDir = new URL('../../node_modules/tailwindcss/', testFile).pathname;
const indexCss = readFileSync(new URL('../index.css', testFile).pathname, 'utf8');

afterEach(() => {
  cleanup();
});

const VARIANTS: readonly ButtonVariant[] = ['primary', 'secondary', 'quiet', 'danger', 'segment'];
const noop = (): void => undefined;

function tokensOf(text: string): string[] {
  return text.split(/\s+/).filter((token) => token !== '');
}

/** Every string constant exported by classes.ts, as individual class tokens. */
function allConstantTokens(): string[] {
  const found = new Set<string>();
  for (const value of Object.values(classes)) {
    if (typeof value === 'string') for (const token of tokensOf(value)) found.add(token);
  }
  return [...found];
}

describe('D1-i1-T02 Button and ButtonInput apply what fields.ts exports (R221 pin)', () => {
  it('gives ButtonInput secondary every FOCUS and SECONDARY_BUTTON token, as an INPUT of type button', () => {
    render(<ButtonInput variant="secondary" value="Add to watchlist" aria-label="Add" />);
    const input = screen.getByRole<HTMLInputElement>('button', { name: 'Add' });
    expect(input.tagName).toBe('INPUT');
    expect(input.type).toBe('button');
    const applied = new Set(input.className.split(/\s+/));
    for (const token of tokensOf(FOCUS)) expect(applied.has(token)).toBe(true);
    for (const token of tokensOf(SECONDARY_BUTTON)) expect(applied.has(token)).toBe(true);
  });

  it('gives Button secondary the same tokens as ButtonInput secondary, so one string drives both', () => {
    render(
      <div>
        <Button variant="secondary">Sign out</Button>
        <ButtonInput variant="secondary" value="Add" aria-label="Add input" />
      </div>,
    );
    const button = screen.getByRole('button', { name: 'Sign out' });
    const input = screen.getByRole('button', { name: 'Add input' });
    expect(new Set(tokensOf(button.className))).toEqual(new Set(tokensOf(input.className)));
    for (const token of tokensOf(SECONDARY_BUTTON)) {
      expect(button.className.split(/\s+/)).toContain(token);
    }
  });

  it('keeps the 44 px literal, the focus ring and the caller extra class on every variant of both', () => {
    for (const variant of VARIANTS) {
      render(
        <div>
          <Button variant={variant} className="extra-caller">
            {`B ${variant}`}
          </Button>
          <ButtonInput variant={variant} value="v" aria-label={`I ${variant}`} className="" />
        </div>,
      );
      const button = screen.getByRole('button', { name: `B ${variant}` });
      const input = screen.getByRole('button', { name: `I ${variant}` });
      for (const control of [button, input]) {
        const applied = control.className.split(/\s+/);
        expect(applied).toContain('min-h-11');
        expect(applied).toContain('min-w-11');
        for (const token of tokensOf(FOCUS)) expect(applied).toContain(token);
        expect(control.className.endsWith(' ')).toBe(false);
      }
      expect(button.className.split(/\s+/)).toContain('extra-caller');
      cleanup();
    }
  });
});

describe('D1-i1-T02 primitives stay inside the Design Brief constraints (string checks)', () => {
  it('carries no transition, animation, marquee or scroll utility in any exported class string', () => {
    for (const token of allConstantTokens()) {
      expect(token).not.toMatch(/transition|animate|marquee|scroll/);
    }
  });

  it('writes no raw palette colour, hex or dark: twin in the primitives source', () => {
    const files = readdirSync(uiDir).filter(
      (name) => /\.(ts|tsx)$/.test(name) && !/\.test\.tsx?$/.test(name),
    );
    expect(files).toContain('classes.ts');
    for (const name of files) {
      const source = readFileSync(`${uiDir}${name}`, 'utf8');
      const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
      expect(code, name).not.toMatch(
        /\b(?:text|bg|border|divide|outline|ring|fill|stroke)-(?:slate|gray|zinc|neutral|stone|red|amber|emerald|sky|green|blue|orange|white|black)\b/,
      );
      expect(code, name).not.toMatch(/#[0-9a-fA-F]{3,8}\b/);
      expect(code, name).not.toMatch(/\bdark:/);
    }
  });

  it('compiles every utility the primitives apply into real CSS, so none is a silent no-op', async () => {
    const compiler = await compile(indexCss, {
      base: tailwindDir,
      loadStylesheet: (id: string) => {
        const path =
          id === 'tailwindcss'
            ? `${tailwindDir}index.css`
            : `${tailwindDir}${id.replace(/^tailwindcss\//, '')}.css`;
        return Promise.resolve({ path, base: tailwindDir, content: readFileSync(path, 'utf8') });
      },
    });
    // build() accumulates the candidates it has seen, so a token that is a real utility must change
    // the output; one that leaves it unchanged compiled to nothing.
    let previous = compiler.build([]);
    const silent: string[] = [];
    for (const token of allConstantTokens()) {
      const next = compiler.build([token]);
      if (next === previous) silent.push(token);
      previous = next;
    }
    expect(silent).toEqual([]);
  });
});

describe('D1-i1-T02 Button behaviour beyond the happy path', () => {
  it('does not submit a form when a default-typed Button is clicked', () => {
    const onSubmit = vi.fn((event: { preventDefault: () => void }) => {
      event.preventDefault();
    });
    render(
      <form onSubmit={onSubmit}>
        <Button variant="quiet">Cancel</Button>
      </form>,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('does not submit through a disabled submit Button', () => {
    const onSubmit = vi.fn((event: { preventDefault: () => void }) => {
      event.preventDefault();
    });
    render(
      <form onSubmit={onSubmit}>
        <Button variant="primary" type="submit" disabled>
          Sign in
        </Button>
      </form>,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('ignores pressed on every non-segment variant: no aria-pressed attribute at all', () => {
    for (const variant of VARIANTS.filter((v) => v !== 'segment')) {
      render(
        <Button variant={variant} pressed>
          {`P ${variant}`}
        </Button>,
      );
      expect(
        screen.getByRole('button', { name: `P ${variant}` }).hasAttribute('aria-pressed'),
      ).toBe(false);
      cleanup();
    }
  });

  it('never lets a spread prop turn the input-as-button into another input type', () => {
    const hostile = { type: 'submit' } as unknown as Record<string, never>;
    render(<ButtonInput variant="secondary" value="Add" aria-label="Add hostile" {...hostile} />);
    expect(screen.getByRole<HTMLInputElement>('button', { name: 'Add hostile' }).type).toBe(
      'button',
    );
  });
});

describe('D1-i1-T02 Field wiring across several fields and over time', () => {
  it('keeps two fields in one form apart: own ids, own labels, own hint and error', () => {
    render(
      <form>
        <Field label="Email" hint="Where the links go.">
          {(control) => <Input {...control} name="email" value="" onChange={noop} />}
        </Field>
        <Field label="Password" error="Too short.">
          {(control) => <Input {...control} name="password" value="" onChange={noop} />}
        </Field>
      </form>,
    );
    const email = screen.getByLabelText('Email');
    const password = screen.getByLabelText('Password');
    expect(email.id).not.toBe(password.id);
    expect(email.getAttribute('aria-describedby')).toBe(`${email.id}-hint`);
    expect(password.getAttribute('aria-describedby')).toBe(`${password.id}-error`);
    expect(email.hasAttribute('aria-invalid')).toBe(false);
    expect(password.getAttribute('aria-invalid')).toBe('true');
    expect(document.getElementById(`${password.id}-error`)?.textContent).toBe('Too short.');
    expect(document.getElementById(`${email.id}-error`)).toBeNull();
    expect(document.getElementById(`${password.id}-hint`)).toBeNull();
  });

  it('drops aria-invalid and the error id when the error clears, keeping the hint, on the same node', () => {
    const view = (error: string | undefined) => (
      <Field label="Code" hint="Six digits." error={error}>
        {(control) => <Input {...control} name="code" value="" onChange={noop} />}
      </Field>
    );
    const { rerender } = render(view('Wrong code.'));
    const input = screen.getByLabelText('Code');
    const id = input.id;
    expect(input.getAttribute('aria-describedby')).toBe(`${id}-hint ${id}-error`);
    rerender(view(undefined));
    expect(screen.getByLabelText('Code')).toBe(input);
    expect(input.id).toBe(id);
    expect(input.hasAttribute('aria-invalid')).toBe(false);
    expect(input.getAttribute('aria-describedby')).toBe(`${id}-hint`);
    expect(screen.queryByText('Wrong code.')).toBeNull();
  });

  it('puts the label before the control and the hint and error after it, in document order', () => {
    render(
      <Field label="Name" hint="Hint text." error="Error text.">
        {(control) => <Input {...control} name="name" value="" onChange={noop} />}
      </Field>,
    );
    const label = screen.getByText('Name');
    const input = screen.getByLabelText('Name');
    const hint = screen.getByText('Hint text.');
    const error = screen.getByText('Error text.');
    const before = (a: Node, b: Node): boolean =>
      (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
    expect(before(label, input)).toBe(true);
    expect(before(input, hint)).toBe(true);
    expect(before(hint, error)).toBe(true);
  });
});

describe('D1-i1-T02 Select is controlled and keeps option order', () => {
  const OPTIONS = [
    { value: '', label: 'Any' },
    { value: 'b', label: 'Bravo' },
    { value: 'a', label: 'Alpha' },
  ] as const;

  it('reflects the value prop, reports the choice, and only moves when the parent re-renders', () => {
    const seen: string[] = [];
    const view = (value: string) => (
      <Field label="Sort">
        {(control) => (
          <Select
            {...control}
            name="sort"
            value={value}
            options={OPTIONS}
            onChange={(event) => {
              seen.push(event.target.value);
            }}
          />
        )}
      </Field>
    );
    const { rerender } = render(view('b'));
    const select = screen.getByRole<HTMLSelectElement>('combobox', { name: 'Sort' });
    expect(select.value).toBe('b');
    expect(
      within(select)
        .getAllByRole<HTMLOptionElement>('option')
        .map((option) => [option.value, option.textContent]),
    ).toEqual([
      ['', 'Any'],
      ['b', 'Bravo'],
      ['a', 'Alpha'],
    ]);
    fireEvent.change(select, { target: { value: 'a' } });
    expect(seen).toEqual(['a']);
    expect(select.value).toBe('b');
    rerender(view('a'));
    expect(select.value).toBe('a');
    rerender(view(''));
    expect(select.value).toBe('');
  });
});

describe('D1-i1-T02 the quantity input keeps its contract after the INPUT swap', () => {
  it('is still a labelled whole-number field named quantity, 1 to 9999 in steps of 1', () => {
    render(<QuantityForm initial="3" pending={false} error="Too many." onSave={noop} />);
    const field = screen.getByLabelText<HTMLInputElement>('Quantity owned');
    expect(field.tagName).toBe('INPUT');
    expect(field.name).toBe('quantity');
    expect(field.type).toBe('number');
    expect(field.min).toBe('1');
    expect(field.max).toBe('9999');
    expect(field.step).toBe('1');
    expect(field.inputMode).toBe('numeric');
    expect(field.value).toBe('3');
    expect(field.getAttribute('aria-invalid')).toBe('true');
    expect(field.className.split(/\s+/)).toContain('min-h-11');
    expect(document.getElementById(field.getAttribute('aria-describedby') ?? '')?.textContent).toBe(
      'Too many.',
    );
    expect(screen.getByRole('button', { name: 'Save' }).textContent).toBe('Save');
  });
});
