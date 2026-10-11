#!/usr/bin/env -S python3 -I
"""Read a JUnit XML report (Pest/PHPUnit or Vitest) and print one integer.

Usage: junit.py FILE [--tests-dir DIR] --field tests|failures|errors|skipped|bad

Counts are taken from every <testcase> element anywhere in the tree:
  tests    = number of testcases
  failures = testcases with a <failure> child
  errors   = testcases with an <error> child
  skipped  = testcases with a <skipped> child
  bad      = failures + errors + skipped, plus (with --tests-dir) every *Test.php
             under DIR that no testsuite/testcase "file" attribute names.
A missing, empty, unreadable or non-JUnit file prints nothing and exits with status 2.
"""
import argparse
import os
import sys
import xml.etree.ElementTree as ET

FIELDS = ("tests", "failures", "errors", "skipped", "bad")


def load(path):
    try:
        if not os.path.isfile(path) or os.path.getsize(path) == 0:
            return None
        root = ET.parse(path).getroot()
    except (OSError, ET.ParseError, ValueError):
        return None
    if root.tag not in ("testsuites", "testsuite"):
        return None
    return root


def listed_files(root):
    names = set()
    for el in root.iter():
        if el.tag not in ("testsuite", "testcase"):
            continue
        value = el.get("file")
        if not value:
            continue
        value = value.split("::", 1)[0].replace("\\", "/").strip()
        if value:
            names.add(value)
    return names


def unlisted_tests(root, tests_dir):
    base = os.path.dirname(os.path.normpath(tests_dir))
    names = listed_files(root)
    missing = []
    for dirpath, _dirs, files in os.walk(tests_dir):
        for name in files:
            if not name.endswith("Test.php"):
                continue
            rel = os.path.relpath(os.path.join(dirpath, name), base).replace(os.sep, "/")
            if not any(n == rel or n.endswith("/" + rel) for n in names):
                missing.append(rel)
    return sorted(missing)


def main(argv):
    parser = argparse.ArgumentParser(prog="junit.py")
    parser.add_argument("file")
    parser.add_argument("--tests-dir")
    parser.add_argument("--field", required=True, choices=FIELDS)
    args = parser.parse_args(argv)

    root = load(args.file)
    if root is None:
        return 2
    cases = list(root.iter("testcase"))
    counts = {
        "tests": len(cases),
        "failures": sum(1 for c in cases if c.find("failure") is not None),
        "errors": sum(1 for c in cases if c.find("error") is not None),
        "skipped": sum(1 for c in cases if c.find("skipped") is not None),
    }
    counts["bad"] = counts["failures"] + counts["errors"] + counts["skipped"]
    if args.tests_dir is not None:
        if not os.path.isdir(args.tests_dir):
            return 2
        counts["bad"] += len(unlisted_tests(root, args.tests_dir))
    print(counts[args.field])
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
