#!/usr/bin/env -S python3 -I
"""AG-values: the gate configs carry the Research 11 values (F-P0-009, R20).

Usage: GATES_OUT=<dir> python3 -I scripts/gates/ag-values.py [--only GROUP[,GROUP]] [--inputs FILE]

Live mode collects every input from the repo and the dev containers; --inputs reads
the same inputs from one JSON object instead. Writes $GATES_OUT/AG-values.json with
value = violations and denominator = checks evaluated; the process status is 1 when
value > 0 and 2 on a usage error. Standard library only.
"""
import argparse
import json
import os
import re
import subprocess
import sys
import xml.etree.ElementTree as ET

GROUPS = ["phpstan", "pint", "phpunit", "tsconfig", "eslint", "vitest", "spectral"]
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
DC = ["docker", "compose", "-f", "compose.dev.yml"]
API = DC + ["exec", "-T", "-w", "/workspace/api", "api"]
NODE = DC + ["exec", "-T", "-w", "/workspace/web", "node"]
TIMEOUT_S = 300
# Evaluates web/eslint.config.js (R53): the names of every flat-config entry, and the effective
# linterOptions ESLint computes for src/App.tsx.
ESLINT_EVAL_JS = (
    "const flat = (x) => Array.isArray(x) ? x.flatMap(flat) : [x];"
    " const entries = flat(await (await import('./eslint.config.js')).default);"
    " const { ESLint } = await import('eslint');"
    " const eff = await new ESLint({ overrideConfigFile: 'eslint.config.js' }).calculateConfigForFile('src/App.tsx');"
    " console.log(JSON.stringify({"
    " config_names: entries.map((c) => (c && typeof c.name === 'string') ? c.name : null),"
    " linterOptions: (eff && eff.linterOptions) ?? null }))"
)
STRICT_TYPE_CHECKED_NAME = "typescript-eslint/strict-type-checked"
VITEST_JS = "const c = (await import('./vitest.config.ts')).default; console.log(JSON.stringify(c.test ?? {}))"
PHPUNIT_FLAGS = [
    "failOnEmptyTestSuite",
    "failOnRisky",
    "failOnWarning",
    "failOnSkipped",
    "failOnIncomplete",
    "beStrictAboutTestsThatDoNotTestAnything",
]
TS_FLAGS = ["strict", "noUncheckedIndexedAccess", "exactOptionalPropertyTypes"]
# strictTypeChecked rules that must stay at error in the effective config (R50): spreading the
# preset and switching its rules off afterwards is not strictTypeChecked.
ESLINT_STRICT_RULES = [
    "@typescript-eslint/" + name
    for name in (
        "no-explicit-any",
        "no-floating-promises",
        "no-unsafe-assignment",
        "no-unsafe-member-access",
        "no-unsafe-call",
        "no-unsafe-return",
        "no-misused-promises",
        "restrict-template-expressions",
        "no-unnecessary-condition",
        "no-non-null-assertion",
    )
]


class CollectError(Exception):
    pass


# --- live collection -------------------------------------------------------------------


def _run(cmd):
    try:
        proc = subprocess.run(
            cmd, cwd=ROOT, capture_output=True, text=True, timeout=TIMEOUT_S, check=False
        )
    except (OSError, subprocess.SubprocessError) as exc:
        raise CollectError(f"{' '.join(cmd)} failed: {type(exc).__name__}") from exc
    if proc.returncode != 0:
        raise CollectError(f"{' '.join(cmd)} failed: {proc.returncode}")
    return proc.stdout


def _run_json(cmd):
    out = _run(cmd)
    try:
        return json.loads(out)
    except ValueError as exc:
        raise CollectError(f"{' '.join(cmd)} failed: output is not JSON") from exc


def _read(rel):
    try:
        with open(os.path.join(ROOT, rel), encoding="utf-8") as fh:
            return fh.read()
    except (OSError, UnicodeDecodeError) as exc:
        raise CollectError(f"read {rel} failed: {type(exc).__name__}") from exc


def _read_json(rel):
    text = _read(rel)
    try:
        return json.loads(text)
    except ValueError as exc:
        raise CollectError(f"read {rel} failed: not JSON") from exc


def _eslint_rules():
    cfg = _run_json(NODE + ["./node_modules/.bin/eslint", "-c", "eslint.config.js", "--print-config", "src/App.tsx"])
    if not isinstance(cfg, dict) or not isinstance(cfg.get("rules"), dict):
        raise CollectError("eslint --print-config failed: no rules object")
    return cfg["rules"]


def _eslint_evaluated():
    data = _run_json(NODE + ["node", "--input-type=module", "-e", ESLINT_EVAL_JS])
    if not isinstance(data, dict) or not isinstance(data.get("config_names"), list):
        raise CollectError("eslint config evaluation failed: no config_names list")
    return data


COLLECTORS = {
    "phpstan": {
        "phpstan_params": lambda: _run_json(
            API + ["vendor/bin/phpstan", "dump-parameters", "-c", "phpstan.neon", "--json"]
        ),
        "phpstan_neon_text": lambda: _read("api/phpstan.neon"),
        "baseline_exists": lambda: os.path.exists(os.path.join(ROOT, "api/phpstan-baseline.neon")),
    },
    "pint": {"pint": lambda: _read_json("api/pint.json")},
    "phpunit": {"phpunit_xml": lambda: _read("api/phpunit.xml")},
    "tsconfig": {
        "tsconfig_app": lambda: _run_json(
            NODE + ["./node_modules/.bin/tsc", "-p", "tsconfig.app.json", "--showConfig"]
        ),
        "tsconfig_node": lambda: _run_json(
            NODE + ["./node_modules/.bin/tsc", "-p", "tsconfig.node.json", "--showConfig"]
        ),
    },
    "eslint": {
        "eslint_evaluated": _eslint_evaluated,
        "eslint_rules": _eslint_rules,
    },
    "vitest": {
        "vitest_test": lambda: _run_json(NODE + ["node", "--input-type=module", "-e", VITEST_JS]),
    },
    "spectral": {"spectral_yaml": lambda: _read(".spectral.yaml")},
}


def collect_live(groups):
    """Return (inputs, collect_failures); a failed input never raises."""
    inputs, failed = {}, {}
    for group in groups:
        for key, fn in COLLECTORS[group].items():
            try:
                inputs[key] = fn()
            except CollectError as exc:
                failed.setdefault(group, []).append(str(exc))
    return inputs, failed


# --- rules -------------------------------------------------------------------------------


def _get(obj, *path):
    for part in path:
        if not isinstance(obj, dict) or part not in obj:
            return None
        obj = obj[part]
    return obj


def _is_number(v):
    return isinstance(v, (int, float)) and not isinstance(v, bool)


def _norm_dir(text):
    t = (text or "").strip()
    while t.startswith("./"):
        t = t[2:]
    return t.rstrip("/")


class Checks:
    def __init__(self):
        self.details = []

    def add(self, check, ok, reason):
        self.details.append({"check": check, "ok": bool(ok), "reason": reason})


def rules_phpstan(c, inp):
    params = inp.get("phpstan_params")
    level = _get(params, "level")
    c.add("phpstan.level", isinstance(level, int) and not isinstance(level, bool) and level >= 8,
          f"level is {json.dumps(level)}, want an int >= 8")
    ignores = _get(params, "ignoreErrors")
    c.add("phpstan.ignoreErrors", ignores == [], f"ignoreErrors is {json.dumps(ignores)}, want []")
    unmatched = _get(params, "reportUnmatchedIgnoredErrors")
    c.add("phpstan.reportUnmatchedIgnoredErrors", unmatched is True,
          f"reportUnmatchedIgnoredErrors is {json.dumps(unmatched)}, want true")
    exists = inp.get("baseline_exists")
    c.add("phpstan.baseline_file", exists is False,
          f"api/phpstan-baseline.neon exists is {json.dumps(exists)}, want false")
    neon = inp.get("phpstan_neon_text")
    c.add("phpstan.baseline_include", isinstance(neon, str) and "baseline" not in neon.lower(),
          "phpstan.neon must be text without any baseline reference")


def rules_pint(c, inp):
    preset = _get(inp.get("pint"), "preset")
    c.add("pint.preset", preset == "laravel", f"preset is {json.dumps(preset)}, want \"laravel\"")


def rules_phpunit(c, inp):
    text = inp.get("phpunit_xml")
    root, why = None, ""
    if isinstance(text, str):
        try:
            root = ET.fromstring(text)
        except ET.ParseError as exc:
            why = f"phpunit.xml does not parse: {exc}"
    else:
        why = "phpunit.xml text missing"
    if root is not None and root.tag != "phpunit":
        root, why = None, f"root element is <{root.tag}>, want <phpunit>"
    for flag in PHPUNIT_FLAGS:
        val = root.get(flag) if root is not None else None
        c.add(f"phpunit.{flag}", val == "true", why or f"{flag} is {json.dumps(val)}, want \"true\"")
    # The app include counts only without a suffix or prefix filter, which could narrow it to nothing.
    includes = [
        (_norm_dir(d.text), sorted(set(d.attrib) & {"suffix", "prefix"}))
        for d in (root.findall("source/include/directory") if root is not None else [])
    ]
    c.add("phpunit.source_include", ("app", []) in includes,
          why or f"source include directories {includes}, want app without suffix or prefix")
    # Every <source><exclude> child must be exactly <directory>app/Providers</directory>.
    excludes = [
        (child.tag, _norm_dir(child.text), dict(child.attrib))
        for ex in (root.findall("source/exclude") if root is not None else [])
        for child in ex
    ]
    c.add("phpunit.source_exclude",
          root is not None and all(e == ("directory", "app/Providers", {}) for e in excludes),
          why or f"source excludes {excludes}, want only <directory>app/Providers</directory>")
    # PHPUnit reads env only from <php><env>.
    dbs = [e.get("value") for e in root.findall("php/env") if e.get("name") == "DB_CONNECTION"] if root is not None else []
    c.add("phpunit.db_connection", bool(dbs) and all(v == "pgsql" for v in dbs),
          why or f"DB_CONNECTION env values {dbs}, want pgsql")


def rules_tsconfig(c, inp):
    for name in ("app", "node"):
        opts = _get(inp.get(f"tsconfig_{name}"), "compilerOptions")
        for flag in TS_FLAGS:
            val = _get(opts, flag)
            c.add(f"tsconfig.{name}.{flag}", val is True,
                  f"tsconfig.{name}.json compilerOptions.{flag} is {json.dumps(val)}, want true written")


def _is_error_severity(sev):
    return (_is_number(sev) and sev == 2) or sev == "error"


def _at_error(rule):
    return _is_error_severity(rule[0] if isinstance(rule, list) and rule else rule)


def _is_strict_type_checked(name):
    # defineConfig's extends prefixes entry names with "<parent> > ".
    return isinstance(name, str) and (
        name == STRICT_TYPE_CHECKED_NAME or name.endswith(" > " + STRICT_TYPE_CHECKED_NAME)
    )


def rules_eslint(c, inp):
    # R53: judged on the evaluated configuration only; the source text is never evidence.
    ev = inp.get("eslint_evaluated")
    names = _get(ev, "config_names")
    c.add("eslint.strictTypeChecked",
          isinstance(names, list) and any(_is_strict_type_checked(n) for n in names),
          f"evaluated config entries {json.dumps(names)}, want {STRICT_TYPE_CHECKED_NAME}")
    inline = _get(ev, "linterOptions", "noInlineConfig")
    c.add("eslint.noInlineConfig", inline is True,
          f"effective linterOptions.noInlineConfig is {json.dumps(inline)}, want true")
    unused = _get(ev, "linterOptions", "reportUnusedDisableDirectives")
    c.add("eslint.reportUnusedDisableDirectives", _is_error_severity(unused),
          f"effective linterOptions.reportUnusedDisableDirectives is {json.dumps(unused)}, want error (2)")
    rules = inp.get("eslint_rules")
    weak = {r: _get(rules, r) for r in ESLINT_STRICT_RULES if not _at_error(_get(rules, r))}
    ok = isinstance(rules, dict) and not weak
    if not isinstance(rules, dict):
        reason = "eslint --print-config rules object missing"
    elif weak:
        reason = f"strictTypeChecked rules not at error: {json.dumps(weak)}"
    else:
        reason = f"all {len(ESLINT_STRICT_RULES)} pinned strictTypeChecked rules at error"
    c.add("eslint.strict_rules", ok, reason)


def rules_vitest(c, inp):
    test = inp.get("vitest_test")
    is_obj = isinstance(test, dict)
    include = _get(test, "coverage", "include")
    c.add("vitest.coverage_include", include == ["src/**"], f"coverage.include is {json.dumps(include)}, want [\"src/**\"]")
    lines = _get(test, "coverage", "thresholds", "lines")
    c.add("vitest.thresholds_lines", _is_number(lines) and lines >= 80,
          f"coverage.thresholds.lines is {json.dumps(lines)}, want >= 80")
    branches = _get(test, "coverage", "thresholds", "branches")
    c.add("vitest.thresholds_branches", _is_number(branches) and branches >= 70,
          f"coverage.thresholds.branches is {json.dumps(branches)}, want >= 70")
    pwnt = _get(test, "passWithNoTests")
    c.add("vitest.passWithNoTests", is_obj and (pwnt is None or pwnt is False),
          f"passWithNoTests is {json.dumps(pwnt)}, want absent, null or false" if is_obj
          else "vitest test object missing")


def strip_yaml_comments(text):
    """Drop # comments (at line start or after whitespace, outside quoted scalars) from YAML text."""
    lines = []
    for line in text.splitlines():
        quote, cut = None, len(line)
        for i, ch in enumerate(line):
            if quote:
                if ch == quote:
                    quote = None
            elif ch in "'\"" and (i == 0 or line[i - 1] in " \t[{,:-"):
                quote = ch
            elif ch == "#" and (i == 0 or line[i - 1] in " \t"):
                cut = i
                break
        lines.append(line[:cut].rstrip())
    return "\n".join(lines)


# Spectral severities: error 0, warn 1, info 2, hint 3, off -1 (also "off" and false).
SPECTRAL_OFF = re.compile(r"""^['"]?(off|false|-1)['"]?$""", re.I)
SPECTRAL_OFF_KEY = re.compile(
    r"""(?<![\w-])(severity\s*:\s*['"]?(off|false|-1)['"]?|recommended\s*:\s*['"]?false['"]?)(?![\w.-])""", re.I
)
SPECTRAL_OAS = "spectral:oas"
SPECTRAL_OK_MODES = {"all", "recommended"}
SPECTRAL_MODES = {"all", "recommended", "off"}


class _FlowError(ValueError):
    pass


def _parse_flow(text):
    """Parse a YAML flow scalar or (nested) flow sequence of scalars into str / list."""
    pos = 0

    def ws():
        nonlocal pos
        while pos < len(text) and text[pos] in " \t\n":
            pos += 1

    def value():
        nonlocal pos
        ws()
        if pos >= len(text):
            raise _FlowError("unexpected end")
        if text[pos] == "[":
            pos += 1
            items = []
            ws()
            if pos < len(text) and text[pos] == "]":
                pos += 1
                return items
            while True:
                items.append(value())
                ws()
                if pos < len(text) and text[pos] == ",":
                    pos += 1
                elif pos < len(text) and text[pos] == "]":
                    pos += 1
                    return items
                else:
                    raise _FlowError("unterminated sequence")
        if text[pos] in "'\"":
            q = text[pos]
            end = text.find(q, pos + 1)
            if end < 0:
                raise _FlowError("unterminated quote")
            out = text[pos + 1:end]
            pos = end + 1
            return out
        m = re.compile(r"[^,\[\]{}\n]+").match(text, pos)
        if not m:
            raise _FlowError(f"unexpected {text[pos]!r}")
        pos = m.end()
        return m.group(0).strip()

    v = value()
    ws()
    if pos != len(text):
        raise _FlowError("trailing text")
    return v


def _spectral_extends(text):
    """Return (ok, reason) for the top-level extends key of comment-stripped YAML."""
    lines = text.splitlines()
    heads = [i for i, ln in enumerate(lines) if re.match(r"^extends\s*:", ln)]
    if len(heads) != 1:
        return False, f"{len(heads)} top-level extends keys, want exactly 1"
    i = heads[0]
    inline = lines[i].split(":", 1)[1].strip()
    body = []
    for ln in lines[i + 1:]:
        if ln.strip() and not ln.startswith((" ", "\t", "-")):
            break
        if ln.strip():
            body.append(ln.strip())
    try:
        if inline:
            if body:
                return False, "extends has both an inline value and a block"
            value = _parse_flow(inline)
        else:
            if not body or not all(b.startswith("- ") or b == "-" for b in body):
                return False, "extends block is empty or not a plain list"
            value = [_parse_flow(b[1:].strip()) for b in body]
    except _FlowError as exc:
        return False, f"extends does not parse: {exc}"
    # Shapes: "name" | [name, mode] | [ "name" | [name, mode], ... ]
    if isinstance(value, str):
        entries = [value]
    elif (len(value) == 2 and all(isinstance(v, str) for v in value)
          and value[1].lower() in SPECTRAL_MODES):
        entries = [value]
    else:
        entries = value
    good, bad = 0, []
    for e in entries:
        if e == SPECTRAL_OAS:
            good += 1
        elif isinstance(e, list) and e and e[0] == SPECTRAL_OAS:
            if len(e) == 2 and e[1] in SPECTRAL_OK_MODES:
                good += 1
            else:
                bad.append(e)
        elif not isinstance(e, (str, list)) or (isinstance(e, list) and not e):
            bad.append(e)
    if bad:
        return False, f"extends carries spectral:oas entries that are off or malformed: {json.dumps(bad)}"
    return good > 0, f"extends is {json.dumps(value)}, want spectral:oas (bare, all or recommended)"


def _spectral_enabled_rules(rules_block):
    """Distinct pp-* rule names under rules: whose severity is not off/0/false."""
    bodies, current = {}, None
    for line in rules_block[1:]:
        m = re.match(r"^\s{2}(pp-[a-z0-9-]+):(.*)$", line)
        if m:
            current = m.group(1)
            bodies.setdefault(current, []).append(m.group(2).strip())
        elif re.match(r"^\s{0,2}\S", line):
            current = None
        elif current:
            bodies[current].append(line.strip())
    enabled = set()
    for name, parts in bodies.items():
        inline, body = parts[0], " ".join(parts)
        if not SPECTRAL_OFF.match(inline) and not SPECTRAL_OFF_KEY.search(body):
            enabled.add(name)
    return sorted(enabled)


# R54: .spectral.yaml is judged under a restricted YAML subset; anything outside it fails the group.
# R56: formats is not allowed anywhere (it can scope rules away from the OpenAPI 3 spec).
SPECTRAL_TOP_KEYS = {"extends", "rules", "documentationUrl", "functions", "functionsDir"}
SPECTRAL_BANNED_KEYS = {"formats"}
SPECTRAL_SEVERITIES = {"-1", "0", "1", "2", "3", "error", "warn", "info", "hint", "off", "false"}
_YAML_KEY = re.compile(r"""^(?P<key>"[^"]*"|'[^']*'|[^\s:"'][^:]*?)\s*:(?:\s+(?P<val>.*)|$)""")
_NODE_PROPS = {"&": "an anchor (&)", "*": "an alias (*)", "!": "a tag (!)"}


def _node_problem(val, in_extends):
    """The first character of a node decides anchors, aliases, tags and flow collections."""
    if not val:
        return None
    if val[0] in _NODE_PROPS:
        return f"{_NODE_PROPS[val[0]]} in {val!r}"
    if val[0] in "[{":
        if in_extends:
            return _flow_props_problem(val)
        if val != "{}":
            kind = "mapping" if val[0] == "{" else "sequence"
            return f"a flow-style {kind} outside extends: {val!r}"
    if val[0] == "?":
        return f"a complex key in {val!r}"
    return None


def _flow_props_problem(val):
    """Anchors, aliases and tags at the start of a flow item (after [ or ,), outside quotes."""
    quote, prev = None, "["
    for ch in val:
        if quote:
            if ch == quote:
                quote = None
            continue
        if ch in " \t":
            continue
        if ch in "'\"":
            quote = ch
        elif ch in _NODE_PROPS and prev in "[,":
            return f"{_NODE_PROPS[ch]} in {val!r}"
        prev = ch
    return None


def _unquote(key):
    return key[1:-1] if len(key) >= 2 and key[:1] in "'\"" and key[-1:] == key[:1] else key


def _spectral_subset_problem(text):
    """None when comment-stripped text stays inside the R54 subset, else the first construct outside it."""
    top, scalar_indent = None, None
    maps = []  # open block mappings as [key column, set of keys]; R56: no key may repeat in one mapping

    def duplicate(col, key, new_item):
        while maps and (maps[-1][0] > col or (new_item and maps[-1][0] == col)):
            maps.pop()
        if not maps or maps[-1][0] != col:
            maps.append([col, set()])
        name = _unquote(key)
        if name in maps[-1][1]:
            return f"duplicate mapping key {name!r}"
        maps[-1][1].add(name)
        return None

    for raw in text.splitlines():
        if not raw.strip():
            continue
        lead = raw[: len(raw) - len(raw.lstrip())]
        indent = len(lead)
        if scalar_indent is not None:
            if indent > scalar_indent:
                continue
            scalar_indent = None
        if "\t" in lead:
            return f"tab indentation in {raw!r}"
        content = raw.strip()
        if indent == 0:
            m = _YAML_KEY.match(content)
            if not m:
                return f"top-level line {content!r} is not a key"
            key, val = m.group("key"), (m.group("val") or "").strip()
            if key not in SPECTRAL_TOP_KEYS:
                return f"top-level key {key!r} is not allowed (allowed: {', '.join(sorted(SPECTRAL_TOP_KEYS))})"
            problem = duplicate(0, key, False)
            if problem:
                return f"{problem} at the top level"
            top = key
            problem = _node_problem(val, key == "extends")
            if problem:
                return problem
            continue
        if top is None:
            return f"indented line {content!r} before any top-level key"
        in_extends = top == "extends"
        col, new_item = indent, False
        while content == "-" or content.startswith("- "):
            stripped = content[1:].lstrip()
            col += len(content) - len(stripped)
            content, new_item = stripped, True
            problem = _node_problem(content, in_extends)
            if problem:
                return problem
        if not content:
            continue
        m = _YAML_KEY.match(content)
        if not m:
            problem = _node_problem(content, in_extends)
            if problem:
                return problem
            continue
        key, val = m.group("key"), (m.group("val") or "").strip()
        if key == "<<":
            return f"a merge key (<<) in {raw.strip()!r}"
        if _unquote(key) in SPECTRAL_BANNED_KEYS:
            return f"key {key!r} is not allowed (R56) in {raw.strip()!r}"
        problem = duplicate(col, key, new_item)
        if problem:
            return f"{problem} in {raw.strip()!r}"
        problem = _node_problem(key, in_extends) or _node_problem(val, in_extends)
        if problem:
            return problem
        if val[:1] in ("|", ">"):
            scalar_indent = indent
            continue
        if top == "rules":
            if indent == 2 and val and val != "{}" and val not in SPECTRAL_SEVERITIES:
                return f"rule {key!r} value {val!r} is not a severity ({', '.join(sorted(SPECTRAL_SEVERITIES))})"
            if indent > 2 and key == "severity" and val not in SPECTRAL_SEVERITIES:
                return f"severity {val!r} under rules is not one of {', '.join(sorted(SPECTRAL_SEVERITIES))}"
    return None


def _top_level_block(text, key):
    """Lines of a top-level YAML key: its own line plus the indented lines below it."""
    out, inside = [], False
    for line in text.splitlines():
        if re.match(rf"^{re.escape(key)}\s*:", line):
            inside = True
            out.append(line)
        elif inside and (line.startswith((" ", "\t", "-")) or not line.strip()):
            out.append(line)
        elif inside:
            inside = False
    return out


def rules_spectral(c, inp):
    text = inp.get("spectral_yaml")
    text = strip_yaml_comments(text) if isinstance(text, str) else ""
    problem = _spectral_subset_problem(text)
    if problem:
        c.add("spectral.subset", False, f".spectral.yaml leaves the R54 subset: {problem}")
        return
    ok, reason = _spectral_extends(text)
    c.add("spectral.extends_oas", ok, reason)
    rules = _spectral_enabled_rules(_top_level_block(text, "rules"))
    c.add("spectral.custom_rules", len(rules) >= 5,
          f"{len(rules)} distinct enabled pp-* rules under rules: {rules}, want >= 5")


RULES = {
    "phpstan": rules_phpstan,
    "pint": rules_pint,
    "phpunit": rules_phpunit,
    "tsconfig": rules_tsconfig,
    "eslint": rules_eslint,
    "vitest": rules_vitest,
    "spectral": rules_spectral,
}


def evaluate(groups, inputs, collect_failures):
    c = Checks()
    for group in groups:
        if group in collect_failures:
            c.add(f"{group}.collect", False, "; ".join(collect_failures[group]))
            continue
        RULES[group](c, inputs)
    return c.details


def main(argv):
    out_dir = os.environ.get("GATES_OUT", "")
    if not out_dir:
        print("AG-values: GATES_OUT must be set", file=sys.stderr)
        raise SystemExit(2)
    if not os.path.isdir(out_dir):
        print(f"AG-values: GATES_OUT must be set to an existing directory: {out_dir}", file=sys.stderr)
        raise SystemExit(2)
    ap = argparse.ArgumentParser(prog="ag-values.py")
    ap.add_argument("--only", default=",".join(GROUPS))
    ap.add_argument("--inputs")
    args = ap.parse_args(argv)
    groups = [g.strip() for g in args.only.split(",") if g.strip()]
    unknown = [g for g in groups if g not in GROUPS]
    if unknown or not groups:
        print(f"AG-values: unknown group(s) {','.join(unknown) or '(none given)'}; known: {','.join(GROUPS)}",
              file=sys.stderr)
        raise SystemExit(2)
    groups = [g for g in GROUPS if g in groups]
    if args.inputs:
        try:
            with open(args.inputs, encoding="utf-8") as fh:
                inputs = json.load(fh)
        except (OSError, ValueError) as exc:
            print(f"AG-values: cannot read --inputs {args.inputs}: {exc}", file=sys.stderr)
            raise SystemExit(2)
        if not isinstance(inputs, dict):
            print("AG-values: --inputs must hold a JSON object", file=sys.stderr)
            raise SystemExit(2)
        failures = {}
    else:
        inputs, failures = collect_live(groups)
    details = evaluate(groups, inputs, failures)
    value = sum(1 for d in details if not d["ok"])
    result = {"id": "AG-values", "value": value, "denominator": len(details), "details": details}
    with open(os.path.join(out_dir, "AG-values.json"), "w", encoding="utf-8") as fh:
        json.dump(result, fh, ensure_ascii=False, indent=2)
        fh.write("\n")
    print(f"AG-values: {value} violation(s) of {len(details)} checks")
    return value


raise SystemExit(1 if main(sys.argv[1:]) else 0)
