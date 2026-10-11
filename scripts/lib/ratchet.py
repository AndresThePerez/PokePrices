#!/usr/bin/env -S python3 -I
"""PokéPrices ratchet comparators: is HEAD_FILE the same as, stricter or looser than ANCHOR_FILE?

Usage: python3 -I scripts/lib/ratchet.py compare --kind KIND ANCHOR_FILE HEAD_FILE

Prints exactly one word: same, stricter, looser or undecidable. Status 0 for same and
stricter, 1 for looser and undecidable, 2 on a usage error. The kinds are a closed set
(R11); anything a comparator cannot judge is undecidable, and an unknown kind or an
unreadable file is undecidable too, so a change never passes by default.

Kinds (canonical names from the frozen manifest's frozen_files.comparators; the plan's
spellings phpstan-neon and vitest-thresholds are exact aliases of phpstan-params and vitest):
  hash                  byte-identical is same, any difference is looser (never stricter)
  phpstan-params        (alias phpstan-neon) the subset P0 uses: top-level includes list, parameters with scalar
                        keys and scalar lists; level may rise, paths may grow, ignoreErrors,
                        excludePaths and includes (other than the Larastan extension) may
                        shrink, reportUnmatchedIgnoredErrors must stay true
  phpunit-xml           source/include directories superset, source/exclude subset,
                        failOn*/beStrictAbout* root attributes stay true, testsuite
                        directories superset, everything else equal
  tsconfig              JSONC; the eight strict booleans may go false to true only, include
                        superset, exclude subset, everything else equal
  vitest                (alias vitest-thresholds) identical text once each lines/branches/functions/statements number
                        is masked; each number >= the anchor's
  json-subtree:<path>   the subtree at a .a.b path is equal (sorted keys), else looser
  ignore-lines          the set of non-blank, non-comment lines is a subset of the anchor's
"""
import json
import re
import sys
import xml.etree.ElementTree as ET

SAME, STRICTER, LOOSER, UNDECIDABLE = "same", "stricter", "looser", "undecidable"
KINDS = ("hash", "phpstan-params", "phpunit-xml", "tsconfig", "vitest", "json-subtree:<jq path>", "ignore-lines")
ALIASES = {"phpstan-neon": "phpstan-params", "vitest-thresholds": "vitest"}
TS_STRICT = ("strict", "noUncheckedIndexedAccess", "exactOptionalPropertyTypes", "noUnusedLocals",
             "noUnusedParameters", "noFallthroughCasesInSwitch", "noUncheckedSideEffectImports",
             "erasableSyntaxOnly")
VITEST_RE = re.compile(r"(lines|branches|functions|statements)\s*:\s*(\d+)")
SUBTREE_RE = re.compile(r"^(\.[A-Za-z_][A-Za-z0-9_-]*)+$")
LARASTAN = "larastan/extension.neon"


class Undecidable(Exception):
    pass


def combine(results):
    """Any looser wins, then undecidable, then stricter; all same is same."""
    results = list(results)
    if LOOSER in results:
        return LOOSER
    if UNDECIDABLE in results:
        return UNDECIDABLE
    if STRICTER in results:
        return STRICTER
    return SAME


def superset_rule(anchor, head):
    """head must contain anchor: added items are stricter, removed items looser."""
    a, h = set(anchor), set(head)
    if a - h:
        return LOOSER
    return STRICTER if h - a else SAME


def subset_rule(anchor, head):
    """head must be contained in anchor: removed items are stricter, added items looser."""
    a, h = set(anchor), set(head)
    if h - a:
        return LOOSER
    return STRICTER if a - h else SAME


def bool_ratchet(anchor, head):
    """A boolean that may only go from false (or absent) to true."""
    for v in (anchor, head):
        if v is not None and not isinstance(v, bool):
            raise Undecidable("non-boolean strict flag")
    if anchor is True:
        return SAME if head is True else LOOSER
    return STRICTER if head is True else SAME


# ---------------------------------------------------------------- hash
def cmp_hash(a, b):
    return SAME if a == b else LOOSER


# ---------------------------------------------------------------- phpstan-neon
def neon_scalar(text):
    text = text.strip()
    if text == "":
        raise Undecidable("empty scalar")
    if len(text) >= 2 and text[0] == text[-1] and text[0] in "'\"":
        return text[1:-1]
    if text[0] in "[{'\"" or " #" in text or text.endswith(":") or ": " in text:
        raise Undecidable("unsupported neon scalar: %s" % text)
    return text


def parse_neon(data):
    text = data.decode("utf-8")
    if "\t" in text:
        raise Undecidable("tabs")
    doc = {"includes": [], "parameters": {}}
    seen_top = set()
    top = None          # current top-level key
    pkey = None         # current parameters key holding a list
    pindent = None      # indentation of parameters keys
    for raw in text.splitlines():
        line = raw.rstrip()
        stripped = line.strip()
        if not stripped or stripped.startswith("#"):
            continue
        indent = len(line) - len(line.lstrip(" "))
        if indent == 0:
            m = re.match(r"^([A-Za-z_][A-Za-z0-9_]*):$", line)
            if not m or m.group(1) not in ("includes", "parameters") or m.group(1) in seen_top:
                raise Undecidable("unsupported top-level line: %s" % line)
            top = m.group(1)
            seen_top.add(top)
            pkey, pindent = None, None
            continue
        if top is None:
            raise Undecidable("indented line before a top-level key")
        if top == "includes":
            if not stripped.startswith("- "):
                raise Undecidable("includes entry is not a list item")
            doc["includes"].append(neon_scalar(stripped[2:]))
            continue
        # parameters
        if stripped.startswith("- "):
            if pkey is None or pindent is None or indent <= pindent:
                raise Undecidable("list item outside a list key")
            doc["parameters"][pkey].append(neon_scalar(stripped[2:]))
            continue
        if stripped == "-":
            raise Undecidable("nested list item")
        if pindent is None:
            pindent = indent
        if indent != pindent:
            raise Undecidable("nested mapping under parameters")
        m = re.match(r"^([A-Za-z_][A-Za-z0-9_]*):(?:\s+(.*))?$", stripped)
        if not m:
            raise Undecidable("unsupported parameters line: %s" % stripped)
        key, value = m.group(1), m.group(2)
        if key in doc["parameters"]:
            raise Undecidable("duplicate key %s" % key)
        if value is None or value.strip() == "":
            doc["parameters"][key] = []
            pkey = key
        elif value.strip() == "[]":
            doc["parameters"][key] = []
            pkey = None
        else:
            doc["parameters"][key] = neon_scalar(value)
            pkey = None
    return doc


def neon_list(params, key):
    v = params.get(key, [])
    if not isinstance(v, list):
        raise Undecidable("%s is not a list" % key)
    return v


def neon_bool(v):
    if v is None:
        return None
    if v in ("true", "false"):
        return v == "true"
    raise Undecidable("non-boolean value %r" % v)


def cmp_phpstan_neon(a, b):
    anchor, head = parse_neon(a), parse_neon(b)
    results = []
    ai, hi = anchor["includes"], head["includes"]
    if set(hi) - set(ai):
        results.append(UNDECIDABLE)
    removed = set(ai) - set(hi)
    if any(LARASTAN in x for x in removed):
        results.append(LOOSER)
    elif removed:
        results.append(STRICTER)
    ap, hp = anchor["parameters"], head["parameters"]
    for key in sorted(set(ap) | set(hp)):
        av, hv = ap.get(key), hp.get(key)
        if key == "level":
            if av == hv:
                results.append(SAME)
            elif isinstance(av, str) and isinstance(hv, str) and av.isdigit() and hv.isdigit():
                results.append(SAME if int(hv) == int(av) else (STRICTER if int(hv) > int(av) else LOOSER))
            else:
                results.append(UNDECIDABLE)
        elif key == "paths":
            results.append(superset_rule(neon_list(ap, key), neon_list(hp, key)))
        elif key in ("ignoreErrors", "excludePaths"):
            results.append(subset_rule(neon_list(ap, key), neon_list(hp, key)))
        elif key == "reportUnmatchedIgnoredErrors":
            results.append(bool_ratchet(neon_bool(av), neon_bool(hv)))
        else:
            results.append(SAME if av == hv else UNDECIDABLE)
    return combine(results)


# ---------------------------------------------------------------- phpunit-xml
def dir_key(el):
    return ((el.text or "").strip(), tuple(sorted(el.attrib.items())))


def canon(el):
    return (el.tag, tuple(sorted(el.attrib.items())), (el.text or "").strip(), (el.tail or "").strip(),
            tuple(canon(c) for c in el))


def pull_dirs(parent):
    """Remove and return the <directory> children of parent (None when parent is None)."""
    if parent is None:
        return []
    found = [c for c in list(parent) if c.tag == "directory"]
    for c in found:
        parent.remove(c)
    return [dir_key(c) for c in found]


def phpunit_parts(data):
    try:
        root = ET.fromstring(data)
    except ET.ParseError as err:
        raise Undecidable("xml: %s" % err)
    if root.tag != "phpunit":
        raise Undecidable("root is not phpunit")
    strict = {}
    for name in list(root.attrib):
        if name.startswith("failOn") or name.startswith("beStrictAbout"):
            v = root.attrib.pop(name)
            if v not in ("true", "false"):
                raise Undecidable("non-boolean %s" % name)
            strict[name] = v == "true"
    suites = {}
    for ts in root.iter("testsuite"):
        name = ts.attrib.get("name")
        if name in suites:
            raise Undecidable("duplicate testsuite %s" % name)
        suites[name] = pull_dirs(ts)
    sources = root.findall("source")
    if len(sources) > 1:
        raise Undecidable("more than one source element")
    src = sources[0] if sources else None
    inc = src.findall("include") if src is not None else []
    exc = src.findall("exclude") if src is not None else []
    if len(inc) > 1 or len(exc) > 1:
        raise Undecidable("more than one include or exclude")
    include = pull_dirs(inc[0] if inc else None)
    exclude = pull_dirs(exc[0] if exc else None)
    return {"strict": strict, "suites": suites, "include": include, "exclude": exclude, "rest": canon(root)}


def cmp_phpunit_xml(a, b):
    anchor, head = phpunit_parts(a), phpunit_parts(b)
    results = []
    for name in sorted(set(anchor["strict"]) | set(head["strict"])):
        results.append(bool_ratchet(anchor["strict"].get(name), head["strict"].get(name)))
    if set(anchor["suites"]) != set(head["suites"]):
        results.append(UNDECIDABLE)
    else:
        for name in anchor["suites"]:
            results.append(superset_rule(anchor["suites"][name], head["suites"][name]))
    results.append(superset_rule(anchor["include"], head["include"]))
    results.append(subset_rule(anchor["exclude"], head["exclude"]))
    results.append(SAME if anchor["rest"] == head["rest"] else UNDECIDABLE)
    return combine(results)


# ---------------------------------------------------------------- tsconfig
def strip_jsonc(text):
    """Remove // and /* */ comments and trailing commas, leaving string contents untouched."""
    out, i, n = [], 0, len(text)
    while i < n:
        ch = text[i]
        if ch == '"':
            j = i + 1
            while j < n and text[j] != '"':
                j += 2 if text[j] == "\\" else 1
            if j >= n:
                raise Undecidable("unterminated string")
            out.append(text[i:j + 1])
            i = j + 1
        elif text.startswith("//", i):
            j = text.find("\n", i)
            i = n if j < 0 else j
        elif text.startswith("/*", i):
            j = text.find("*/", i + 2)
            if j < 0:
                raise Undecidable("unterminated comment")
            i = j + 2
        else:
            out.append(ch)
            i += 1
    cleaned = "".join(out)
    # trailing commas: a comma followed only by whitespace before } or ], outside strings
    result, i, n = [], 0, len(cleaned)
    while i < n:
        ch = cleaned[i]
        if ch == '"':
            j = i + 1
            while j < n and cleaned[j] != '"':
                j += 2 if cleaned[j] == "\\" else 1
            result.append(cleaned[i:j + 1])
            i = j + 1
            continue
        if ch == ",":
            j = i + 1
            while j < n and cleaned[j] in " \t\r\n":
                j += 1
            if j < n and cleaned[j] in "}]":
                i += 1
                continue
        result.append(ch)
        i += 1
    return "".join(result)


def load_jsonc(data):
    try:
        doc = json.loads(strip_jsonc(data.decode("utf-8")))
    except ValueError as err:
        raise Undecidable("json: %s" % err)
    if not isinstance(doc, dict):
        raise Undecidable("tsconfig is not an object")
    return doc


def str_list(v):
    if not isinstance(v, list) or not all(isinstance(x, str) for x in v):
        raise Undecidable("not a list of strings")
    return v


def cmp_tsconfig(a, b):
    anchor, head = load_jsonc(a), load_jsonc(b)
    results = []
    aco, hco = anchor.pop("compilerOptions", {}), head.pop("compilerOptions", {})
    if not isinstance(aco, dict) or not isinstance(hco, dict):
        raise Undecidable("compilerOptions is not an object")
    for flag in TS_STRICT:
        results.append(bool_ratchet(aco.pop(flag, None), hco.pop(flag, None)))
    results.append(SAME if aco == hco else UNDECIDABLE)
    for key, rule in (("include", superset_rule), ("exclude", subset_rule)):
        av, hv = anchor.pop(key, None), head.pop(key, None)
        if av is None and hv is None:
            continue
        if av is None or hv is None:
            results.append(UNDECIDABLE)
            continue
        results.append(rule(str_list(av), str_list(hv)))
    results.append(SAME if anchor == head else UNDECIDABLE)
    return combine(results)


# ---------------------------------------------------------------- vitest-thresholds
def cmp_vitest(a, b):
    try:
        at, ht = a.decode("utf-8"), b.decode("utf-8")
    except UnicodeDecodeError:
        raise Undecidable("not utf-8")
    mask = lambda t: VITEST_RE.sub(lambda m: m.group(1) + ":#", t)
    if mask(at) != mask(ht):
        return UNDECIDABLE
    an = [int(m.group(2)) for m in VITEST_RE.finditer(at)]
    hn = [int(m.group(2)) for m in VITEST_RE.finditer(ht)]
    if len(an) != len(hn):
        return UNDECIDABLE
    return combine(SAME if h == x else (STRICTER if h > x else LOOSER) for x, h in zip(an, hn))


# ---------------------------------------------------------------- json-subtree
def cmp_json_subtree(path, a, b):
    if not SUBTREE_RE.match(path):
        raise Undecidable("unsupported subtree path %s" % path)
    trees = []
    for data in (a, b):
        try:
            node = json.loads(data.decode("utf-8"))
        except ValueError as err:
            raise Undecidable("json: %s" % err)
        for part in path.split(".")[1:]:
            node = node.get(part) if isinstance(node, dict) else None
        trees.append(json.dumps(node, sort_keys=True))
    return SAME if trees[0] == trees[1] else LOOSER


# ---------------------------------------------------------------- ignore-lines
def ignore_set(data):
    try:
        text = data.decode("utf-8")
    except UnicodeDecodeError:
        raise Undecidable("not utf-8")
    return {ln.strip() for ln in text.splitlines() if ln.strip() and not ln.strip().startswith("#")}


def cmp_ignore_lines(a, b):
    return subset_rule(ignore_set(a), ignore_set(b))


def compare(kind, a, b):
    kind = ALIASES.get(kind, kind)
    try:
        if kind == "hash":
            return cmp_hash(a, b)
        if kind == "phpstan-params":
            return cmp_phpstan_neon(a, b)
        if kind == "phpunit-xml":
            return cmp_phpunit_xml(a, b)
        if kind == "tsconfig":
            return cmp_tsconfig(a, b)
        if kind == "vitest":
            return cmp_vitest(a, b)
        if kind == "ignore-lines":
            return cmp_ignore_lines(a, b)
        if kind.startswith("json-subtree:"):
            return cmp_json_subtree(kind[len("json-subtree:"):], a, b)
    except (Undecidable, UnicodeDecodeError, ValueError, RecursionError):
        return UNDECIDABLE
    return UNDECIDABLE


def main(argv):
    if len(argv) != 5 or argv[0] != "compare" or argv[1] != "--kind":
        sys.stderr.write("usage: ratchet.py compare --kind KIND ANCHOR_FILE HEAD_FILE\n"
                         "kinds: %s; aliases: %s\n" % (", ".join(KINDS), ", ".join(
                             "%s = %s" % (a, c) for a, c in sorted(ALIASES.items()))))
        return 2
    kind, anchor_path, head_path = argv[2], argv[3], argv[4]
    try:
        with open(anchor_path, "rb") as fh:
            a = fh.read()
        with open(head_path, "rb") as fh:
            b = fh.read()
    except OSError as err:
        sys.stderr.write("ratchet: %s\n" % err)
        result = UNDECIDABLE
    else:
        result = compare(kind, a, b)
    print(result)
    return 0 if result in (SAME, STRICTER) else 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
