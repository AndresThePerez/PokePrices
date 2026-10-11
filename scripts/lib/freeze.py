#!/usr/bin/env -S python3 -I
"""PokéPrices gate freeze: print the gates/frozen.sha256 content for a manifest's frozen_files.list.

Usage: python3 -I scripts/lib/freeze.py --manifest gates/manifest-P0.json

Run from the repo root. Each list entry is either a path glob or "<path>#<.a.b>":
  - a glob is expanded against git ls-files with globstar semantics (** spans directories,
    * and ? never cross a slash); every match prints "<sha256 of the file>  <path>"
  - a "#" entry names one tracked JSON file and a subtree; it prints
    "<sha256 of jq -S '<.a.b>' <path>>  <path>#<.a.b>"
gates/frozen.sha256 itself is never listed. Lines are sorted by key. An entry that matches no
tracked file, a subtree that is absent (null) or unreadable, a symlink, or a path the lock
format cannot carry is an error (status 2), so the freeze can never silently skip a config.
The commit that first adds gates/frozen.sha256 is the freeze anchor (scripts/gates/ag1.py).
"""
import hashlib
import json
import os
import re
import subprocess
import sys

FROZEN = "gates/frozen.sha256"
SUBTREE_RE = re.compile(r"^(\.[A-Za-z_][A-Za-z0-9_-]*)+$")
SAFE_PATH_RE = re.compile(r"^[A-Za-z0-9._@+/-]+$")


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


def tracked_files():
    out = subprocess.run(["git", "ls-files", "-z"], capture_output=True, stdin=subprocess.DEVNULL, timeout=120)
    if out.returncode != 0:
        raise RuntimeError("git ls-files failed")
    return [p.decode("utf-8") for p in out.stdout.split(b"\0") if p]


def file_bytes(path):
    with open(path, "rb") as fh:
        return fh.read()


def is_symlink(path):
    """True when the path is a symlink in the working tree or in the index (mode 120000)."""
    if os.path.islink(path):
        return True
    out = subprocess.run(["git", "ls-files", "-s", "--", path], capture_output=True, text=True,
                         stdin=subprocess.DEVNULL, timeout=60)
    return out.returncode != 0 or out.stdout.startswith("120000 ")


def subtree_bytes(path, sub):
    out = subprocess.run(["jq", "-S", sub, path], capture_output=True, stdin=subprocess.DEVNULL, timeout=60)
    if out.returncode != 0:
        raise RuntimeError("jq -S %s %s failed" % (sub, path))
    if out.stdout.strip() == b"null":
        raise RuntimeError("subtree %s is absent from %s" % (sub, path))
    return out.stdout


def build(entries, tracked):
    """Dict key -> sha256 for every list entry; raises ValueError naming the first bad entry."""
    lock = {}
    tracked_set = set(tracked)
    for entry in entries:
        if not isinstance(entry, str) or not entry:
            raise ValueError("frozen_files.list holds a non-string or empty entry: %r" % (entry,))
        if "#" in entry:
            path, sub = entry.split("#", 1)
            if not SUBTREE_RE.match(sub):
                raise ValueError("bad subtree path in %s" % entry)
            if path not in tracked_set:
                raise ValueError("%s matches no tracked file" % entry)
            if is_symlink(path):
                raise ValueError("%s: %s is a symlink" % (entry, path))
            lock[entry] = hashlib.sha256(subtree_bytes(path, sub)).hexdigest()
            continue
        rx = glob_re(entry)
        matches = [p for p in tracked if rx.match(p) and p != FROZEN]
        if not matches:
            raise ValueError("%s matches no tracked file" % entry)
        for path in matches:
            if not SAFE_PATH_RE.match(path):
                raise ValueError("%s: path %r cannot be carried by the lock format" % (entry, path))
            if is_symlink(path):
                raise ValueError("%s: %s is a symlink" % (entry, path))
            lock[path] = hashlib.sha256(file_bytes(path)).hexdigest()
    return lock


def main(argv):
    if len(argv) != 2 or argv[0] != "--manifest":
        sys.stderr.write("usage: freeze.py --manifest <manifest.json>\n")
        return 2
    try:
        with open(argv[1], encoding="utf-8") as fh:
            entries = json.load(fh)["frozen_files"]["list"]
        if not isinstance(entries, list) or not entries:
            raise ValueError("frozen_files.list is empty or not a list")
        lock = build(entries, tracked_files())
    except (OSError, ValueError, KeyError, TypeError, RuntimeError, subprocess.SubprocessError) as exc:
        sys.stderr.write("freeze: %s\n" % exc)
        return 2
    sys.stdout.write("".join("%s  %s\n" % (lock[k], k) for k in sorted(lock)))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
