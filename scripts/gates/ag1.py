#!/usr/bin/env -S python3 -I
"""AG1: PokéPrices manifest approved; frozen configs unchanged or stricter; config discovery closed.

Run from the repo root with GATES_OUT set. Checks the manifest's AG1 spec:
  (i)   gates/manifest-P0.approved.sha256 is tracked and equals the sha256 of
        gates/manifest-P0.json (working tree and HEAD blob)
  (ii)  anchor = first commit adding gates/frozen.sha256; every entry of
        git show <anchor>:gates/frozen.sha256 is equal at HEAD or judged stricter by the
        scripts/lib/ratchet.py comparator that the anchor's gates/ratchet.json names for it;
        every anchor entry is still listed in the HEAD lock
  (iii) git ls-files matches of the discovery globs (vendor and node_modules excluded) are
        all in the anchor's frozen list
  (iv)  no alternate config path exists in the working tree, tracked or not
Lock lines are "<sha256>  <path>" or "<sha256>  <path>#<.a.b>" (sha256 of jq -S '<.a.b>').
The comparison always uses the anchor's lock and ratchet table, so editing either after the
anchor proves nothing. Anything this script cannot decide is a violation (R11, R20).

value = violations; denominator = anchor lock entries evaluated (0 without an anchor).
"""
import hashlib
import json
import os
import re
import subprocess
import sys

MANIFEST = "gates/manifest-P0.json"
APPROVED = "gates/manifest-P0.approved.sha256"
FROZEN = "gates/frozen.sha256"
RATCHET_TABLE = "gates/ratchet.json"
RATCHET = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "lib", "ratchet.py")
CANONICAL = ("hash", "phpstan-params", "phpunit-xml", "tsconfig", "vitest", "ignore-lines")
ALIASES = ("phpstan-neon", "vitest-thresholds")
SUBTREE_RE = re.compile(r"^(\.[A-Za-z_][A-Za-z0-9_-]*)+$")
LOCK_RE = re.compile(r"^([0-9a-f]{64})  (\S(?:.*\S)?)$")
DISCOVERY_SKIP = ("vendor", "node_modules")


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def git(*args):
    """stdout bytes of a git command, or None when it fails."""
    try:
        out = subprocess.run(["git", *args], capture_output=True, stdin=subprocess.DEVNULL, timeout=120)
    except (OSError, subprocess.SubprocessError):
        return None
    return out.stdout if out.returncode == 0 else None


def read_file(path):
    try:
        with open(path, "rb") as fh:
            return fh.read()
    except OSError:
        return None


def no_dupes(pairs):
    keys = [k for k, _ in pairs]
    if len(keys) != len(set(keys)):
        raise ValueError("duplicate key")
    return dict(pairs)


def load_table(data):
    """The ratchet table as a dict, or None when it is absent, unparsable or malformed."""
    if data is None:
        return None
    try:
        doc = json.loads(data.decode("utf-8"), object_pairs_hook=no_dupes)
    except (UnicodeDecodeError, ValueError):
        return None
    if not isinstance(doc, dict) or not isinstance(doc.get("default_kind"), str):
        return None
    if not isinstance(doc.get("kinds"), dict) or not all(isinstance(v, str) for v in doc["kinds"].values()):
        return None
    for key in ("discovery", "alternates"):
        if not isinstance(doc.get(key), list) or not all(isinstance(v, str) and v for v in doc[key]):
            return None
    for kind in [doc["default_kind"], *doc["kinds"].values()]:
        if not known_kind(kind):
            return None
    return doc


def known_kind(kind):
    if kind in CANONICAL or kind in ALIASES:
        return True
    return kind.startswith("json-subtree:") and bool(SUBTREE_RE.match(kind[len("json-subtree:"):]))


def safe_path(path):
    if not path or path.startswith("/") or "\\" in path or os.path.normpath(path) != path:
        return False
    return ".." not in path.split("/") and path != "."


def parse_lock(data):
    """(entries, problems): entries is an ordered list of (key, path, subtree, hash)."""
    entries, problems, seen = [], [], set()
    try:
        text = data.decode("utf-8")
    except UnicodeDecodeError:
        return [], [{"reason": "malformed_lock_line", "path": FROZEN, "line": "not utf-8"}]
    for line in text.splitlines():
        if not line.strip():
            continue
        m = LOCK_RE.match(line)
        key = m.group(2) if m else None
        path, sub = (key.split("#", 1) + [None])[:2] if key else (None, None)
        if not m or not safe_path(path) or (sub is not None and not SUBTREE_RE.match(sub)):
            problems.append({"reason": "malformed_lock_line", "path": FROZEN, "line": line[:200]})
            continue
        if key in seen:
            problems.append({"reason": "duplicate_lock_entry", "path": key})
            continue
        seen.add(key)
        entries.append((key, path, sub, m.group(1)))
    return entries, problems


def subtree_hash(data, sub):
    try:
        out = subprocess.run(["jq", "-S", sub], input=data, capture_output=True, timeout=60)
    except (OSError, subprocess.SubprocessError):
        return None
    return sha256(out.stdout) if out.returncode == 0 else None


def entry_hash(data, sub):
    return subtree_hash(data, sub) if sub else sha256(data)


def glob_re(pattern):
    """Globstar semantics: **/ is zero or more directories, * and ? never cross a slash."""
    out, i = [], 0
    while i < len(pattern):
        if pattern.startswith("**/", i):
            out.append("(?:.*/)?")
            i += 3
        elif pattern.startswith("**", i):
            out.append(".*")
            i += 2
        elif pattern[i] == "*":
            out.append("[^/]*")
            i += 1
        elif pattern[i] == "?":
            out.append("[^/]")
            i += 1
        else:
            out.append(re.escape(pattern[i]))
            i += 1
    return re.compile("^" + "".join(out) + "$")


def compare(kind, path, anchor_data, head_data, out_dir, tag):
    """Write both sides under $GATES_OUT/ag1 and ask ratchet.py; any failure is undecidable."""
    a_file = os.path.join(out_dir, "ag1", "anchor", path)
    h_file = os.path.join(out_dir, "ag1", tag, path)
    try:
        for f, data in ((a_file, anchor_data), (h_file, head_data)):
            os.makedirs(os.path.dirname(f), exist_ok=True)
            with open(f, "wb") as fh:
                fh.write(data)
        out = subprocess.run([sys.executable, "-I", RATCHET, "compare", "--kind", kind, a_file, h_file],
                             capture_output=True, text=True, stdin=subprocess.DEVNULL, timeout=120)
    except (OSError, subprocess.SubprocessError):
        return "undecidable"
    word = out.stdout.strip()
    if word not in ("same", "stricter", "looser", "undecidable"):
        return "undecidable"
    if word in ("same", "stricter") and out.returncode != 0:
        return "undecidable"
    return word


def worst(words):
    for w in ("looser", "undecidable", "stricter"):
        if w in words:
            return w
    return "same"


def check_approval(details):
    if git("ls-files", "--error-unmatch", "--", APPROVED) is None:
        details.append({"check": "i", "reason": "manifest_unapproved", "path": APPROVED, "why": "not tracked"})
        return
    approved = read_file(APPROVED)
    lines = [ln for ln in (approved or b"").decode("utf-8", "replace").splitlines() if ln.strip()]
    m = re.match(r"^([0-9a-f]{64})  " + re.escape(MANIFEST) + r"$", lines[0]) if len(lines) == 1 else None
    if not m:
        details.append({"check": "i", "reason": "manifest_unapproved", "path": APPROVED, "why": "unreadable approval line"})
        return
    want = m.group(1)
    work = read_file(MANIFEST)
    head = git("show", "HEAD:" + MANIFEST)
    for side, data in (("working tree", work), ("HEAD", head)):
        if data is None or sha256(data) != want:
            details.append({"check": "i", "reason": "manifest_unapproved", "path": MANIFEST,
                            "why": "%s sha256 differs from the approved hash" % side})


def check_frozen(anchor, table, out_dir, details):
    """(ii) against the anchor lock; returns (entries evaluated, set of frozen paths)."""
    lock = git("show", "%s:%s" % (anchor, FROZEN))
    if lock is None:
        details.append({"check": "ii", "reason": "anchor_unreadable", "path": FROZEN})
        return 0, set()
    entries, problems = parse_lock(lock)
    for p in problems:
        details.append(dict(p, check="ii"))
    if not entries:
        details.append({"check": "ii", "reason": "lock_empty", "path": FROZEN})
    head_lock = read_file(FROZEN)
    if head_lock is None:
        details.append({"check": "ii", "reason": "lock_absent_at_head", "path": FROZEN})
        head_keys = set()
    else:
        head_entries, head_problems = parse_lock(head_lock)
        for p in head_problems:
            details.append(dict(p, check="ii", side="head"))
        head_keys = {e[0] for e in head_entries}
    kinds = table["kinds"] if table else {}
    default = table["default_kind"] if table else "hash"
    for key, path, sub, want in entries:
        if key not in head_keys:
            details.append({"check": "ii", "reason": "lock_entry_dropped", "path": key})
        anchor_data = git("show", "%s:%s" % (anchor, path))
        if anchor_data is None:
            details.append({"check": "ii", "reason": "anchor_unreadable", "path": key})
            continue
        if entry_hash(anchor_data, sub) != want:
            details.append({"check": "ii", "reason": "hash_mismatch", "path": key,
                            "why": "the anchor lock does not match the anchor content"})
            continue
        sides = []
        work = read_file(path) if os.path.isfile(path) and not os.path.islink(path) else None
        head = git("show", "HEAD:" + path)
        if work is None or head is None:
            details.append({"check": "ii", "reason": "missing_frozen_path", "path": key,
                            "why": "absent from the %s" % ("working tree" if work is None else "HEAD commit")})
            continue
        sides.append(("head", work))
        if head != work:
            sides.append(("head-commit", head))
        kind = kinds.get(key) or ("json-subtree:" + sub if sub else default)
        words = []
        for tag, data in sides:
            if entry_hash(data, sub) == want:
                words.append("same")
            else:
                words.append(compare(kind, path, anchor_data, data, out_dir, tag))
        word = worst(words)
        if word == "same":
            continue
        details.append({"check": "ii", "reason": word, "path": key, "kind": kind,
                        "violation": word != "stricter"})
    return len(entries), {e[1] for e in entries}


def check_discovery(table, frozen_paths, details):
    tracked = git("ls-files", "-z")
    if tracked is None:
        details.append({"check": "iii", "reason": "ls_files_failed"})
        return
    globs = [glob_re(g) for g in table["discovery"]]
    for raw in tracked.split(b"\0"):
        if not raw:
            continue
        path = raw.decode("utf-8", "replace")
        if any(part in DISCOVERY_SKIP for part in path.split("/")):
            continue
        if any(g.match(path) for g in globs) and path not in frozen_paths:
            details.append({"check": "iii", "reason": "unlisted_config", "path": path})


def check_alternates(alternates, details):
    for path in sorted(alternates):
        if os.path.lexists(path):
            details.append({"check": "iv", "reason": "alternate_present", "path": path})


def main():
    out_dir = os.environ.get("GATES_OUT", "")
    if not out_dir or not os.path.isdir(out_dir):
        sys.stderr.write("ag1: GATES_OUT must be set to an existing directory\n")
        return 2
    details = []
    top = git("rev-parse", "--show-toplevel")
    if top is None or os.path.realpath(top.decode("utf-8", "replace").strip()) != os.path.realpath(os.getcwd()):
        details.append({"reason": "not_repo_root"})
    check_approval(details)

    head_table = load_table(read_file(RATCHET_TABLE))
    if head_table is None:
        details.append({"reason": "ratchet_table_invalid", "path": RATCHET_TABLE, "side": "working tree"})
    log = git("log", "--diff-filter=A", "--format=%H", "--", FROZEN)
    anchors = log.decode("utf-8", "replace").split() if log is not None else []
    evaluated = 0
    alternates = set(head_table["alternates"]) if head_table else set()
    if log is None:
        details.append({"check": "ii", "reason": "anchor_absent", "why": "git log failed"})
    elif not anchors:
        details.append({"check": "ii", "reason": "anchor_absent", "path": FROZEN})
    else:
        anchor = anchors[-1]
        table = load_table(git("show", "%s:%s" % (anchor, RATCHET_TABLE)))
        if table is None:
            details.append({"reason": "ratchet_table_invalid", "path": RATCHET_TABLE, "side": "anchor"})
        else:
            alternates |= set(table["alternates"])
        evaluated, frozen_paths = check_frozen(anchor, table, out_dir, details)
        if table is not None:
            check_discovery(table, frozen_paths, details)
    check_alternates(alternates, details)

    for d in details:
        d.setdefault("violation", True)
    value = sum(1 for d in details if d["violation"])
    result = {"id": "AG1", "value": value, "denominator": evaluated, "details": details}
    with open(os.path.join(out_dir, "AG1.json"), "w", encoding="utf-8") as fh:
        json.dump(result, fh, indent=2, ensure_ascii=False)
        fh.write("\n")
    reasons = sorted({d["reason"] for d in details if d["violation"]})
    stricter = sum(1 for d in details if d["reason"] == "stricter")
    print("AG1: %d violation(s) over %d frozen entries; %d stricter for re-hash%s" % (
        value, evaluated, stricter, (" (" + ", ".join(reasons) + ")") if reasons else ""))
    return 1 if value else 0


if __name__ == "__main__":
    raise SystemExit(main())
