#!/usr/bin/env python3
"""AG3: no assertion-free tests in api/tests (Pest and PHPUnit) or web/src (Vitest).

Strings and comments are blanked before matching, so an assertion named in a string or a
comment never counts. A tree with zero tests adds a hit, so an empty suite can never pass.
"""
import json
import os
import re
import subprocess
import sys

PHP_ASSERT = re.compile(r"expect\(|->assert|\$this->assert|self::assert|arch\(")
TS_ASSERT = re.compile(r"expect\(")
PHP_CALL = re.compile(r"(?<![\w$>:\\])(?:it|test)\s*\(")
PHP_METHOD = re.compile(r"public\s+function\s+(test\w*)\s*\(")
# it/test/describe with any dotted modifier chain (.each(...), .concurrent, .skipIf(...), ...) (R91).
TS_HEAD = re.compile(r"(?<![\w$.])(?:it|test|describe)\b")
TRIVIAL = [
    re.compile(r"assertTrue\(\s*true\s*\)", re.I),
    re.compile(r"expect\(\s*true\s*\)\s*->\s*toBeTrue\(\s*\)", re.I),
]
SKIP_DIRS = {"vendor", "node_modules", "dist", ".git", ".gates"}


def blank(src, php):
    """Replace the contents of comments and string literals with spaces (newlines kept)."""
    out = list(src)
    i, n = 0, len(src)
    quotes = "'\"" if php else "'\"`"
    while i < n:
        c = src[i]
        two = src[i:i + 2]
        if two == "//" or (php and c == "#" and src[i + 1:i + 2] != "["):
            j = src.find("\n", i)
            j = n if j < 0 else j
            for k in range(i, j):
                out[k] = " "
            i = j
        elif two == "/*":
            j = src.find("*/", i + 2)
            j = n if j < 0 else j + 2
            for k in range(i, j):
                if src[k] != "\n":
                    out[k] = " "
            i = j
        elif c in quotes:
            j = i + 1
            while j < n and src[j] != c:
                j += 2 if src[j] == "\\" else 1
            for k in range(i + 1, min(j, n)):
                if src[k] != "\n":
                    out[k] = " "
            i = j + 1
        else:
            i += 1
    return "".join(out)


def match(text, start, open_ch, close_ch):
    """Index just past the bracket matching text[start] (== open_ch), or len(text)."""
    depth = 0
    for k in range(start, len(text)):
        if text[k] == open_ch:
            depth += 1
        elif text[k] == close_ch:
            depth -= 1
            if depth == 0:
                return k + 1
    return len(text)


def ts_calls(text):
    """Yield (start, open_paren) for each it/test/describe call, through any modifier chain.

    The test call is the last of the consecutive argument groups: it.each(table)(name, fn)
    calls (name, fn); test.skip(name, fn) calls (name, fn).
    """
    n = len(text)
    for m in TS_HEAD.finditer(text):
        k = m.end()
        last = -1
        while True:
            j = k
            while j < n and text[j] in " \t\r\n":
                j += 1
            if j < n and text[j] == "." and last < 0:
                ident = re.match(r"\.\s*[A-Za-z_$][\w$]*", text[j:])
                if not ident:
                    break
                k = j + ident.end()
            elif j < n and text[j] == "(":
                last = j
                k = match(text, j, "(", ")")
            elif j < n and text[j] == "`" and last < 0:
                end = text.find("`", j + 1)
                k = n if end < 0 else end + 1
            else:
                break
        if last >= 0:
            yield m.start(), last


def call_body(text, open_paren):
    """Body of the closure passed to the call whose argument list opens at open_paren."""
    end = match(text, open_paren, "(", ")")
    brace = text.find("{", open_paren, end)
    if brace < 0:
        return text[open_paren:end]
    return text[brace:match(text, brace, "{", "}")]


def line_of(text, pos):
    return text.count("\n", 0, pos) + 1


def scan_file(path, php, hits):
    try:
        with open(path, encoding="utf-8", errors="replace") as fh:
            raw = fh.read()
    except OSError as exc:
        hits.append({"file": path, "reason": "unreadable", "error": str(exc)})
        return 0
    text = blank(raw, php)
    tests = 0
    asserts = PHP_ASSERT if php else TS_ASSERT
    calls = [(m.start(), m.end() - 1) for m in PHP_CALL.finditer(text)] if php else list(ts_calls(text))
    for start, open_paren in calls:
        tests += 1
        if not asserts.search(call_body(text, open_paren)):
            hits.append({"file": path, "line": line_of(text, start), "reason": "no_assertion"})
    if php:
        for m in PHP_METHOD.finditer(text):
            tests += 1
            brace = text.find("{", m.end())
            body = text[brace:match(text, brace, "{", "}")] if brace >= 0 else ""
            if not asserts.search(body):
                hits.append({"file": path, "line": line_of(text, m.start()), "reason": "no_assertion", "method": m.group(1)})
    for rx in TRIVIAL:
        for m in rx.finditer(raw):
            hits.append({"file": path, "line": line_of(raw, m.start()), "reason": "trivial_assertion", "text": m.group(0)})
    return tests


def files(tree, keep):
    found = []
    if not os.path.isdir(tree):
        return found
    for dirpath, dirnames, filenames in os.walk(tree):
        dirnames[:] = sorted(d for d in dirnames if d not in SKIP_DIRS)
        for name in sorted(filenames):
            if keep(name):
                found.append(os.path.join(dirpath, name))
    return found


def main():
    out_dir = os.environ.get("GATES_OUT")
    if not out_dir:
        print("GATES_OUT must be set", file=sys.stderr)
        return 2
    out_dir = os.path.abspath(out_dir)
    top = subprocess.run(["git", "rev-parse", "--show-toplevel"], capture_output=True, text=True)
    hits = []
    if top.returncode != 0:
        hits.append({"reason": "not_a_git_repo", "dir": os.getcwd()})
    else:
        os.chdir(top.stdout.strip())
    scanned = 0
    trees = [
        ("api/tests", True, lambda n: n.endswith(".php")),
        ("web/src", False, lambda n: n.endswith((".test.ts", ".test.tsx", ".spec.ts", ".spec.tsx"))),
    ]
    for tree, php, keep in trees:
        paths = files(tree, keep)
        scanned += len(paths)
        tests = sum(scan_file(p, php, hits) for p in paths)
        if tests == 0:
            hits.append({"reason": "no_tests:%s" % tree})
    result = {"id": "AG3", "value": len(hits), "denominator": scanned, "details": hits}
    with open(os.path.join(out_dir, "AG3.json"), "w", encoding="utf-8") as fh:
        json.dump(result, fh)
        fh.write("\n")
    print("AG3: %d assertion-free or trivial tests across %d test files" % (len(hits), scanned))
    return 1 if hits else 0


raise SystemExit(main())
