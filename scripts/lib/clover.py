#!/usr/bin/env -S python3 -I
"""Read a clover coverage report and print one number.

Usage: clover.py FILE --field statements|covered|percent

Uses the project-level <metrics> element (the direct child of <project>), never a
file- or class-level one. percent = 100 * coveredstatements / statements, rounded
to 2 places. An unparsable report, or percent asked when statements is 0, prints
nothing and exits with status 2.
"""
import argparse
import os
import re
import sys
import xml.etree.ElementTree as ET

INT = re.compile(r"^[0-9]+$")


def project_metrics(path):
    try:
        if not os.path.isfile(path) or os.path.getsize(path) == 0:
            return None
        root = ET.parse(path).getroot()
    except (OSError, ET.ParseError, ValueError):
        return None
    if root.tag == "coverage":
        projects = root.findall("project")
    elif root.tag == "project":
        projects = [root]
    else:
        return None
    if len(projects) != 1:
        return None
    metrics = projects[0].findall("metrics")
    if len(metrics) != 1:
        return None
    statements = metrics[0].get("statements", "").strip()
    covered = metrics[0].get("coveredstatements", "").strip()
    if not INT.match(statements) or not INT.match(covered):
        return None
    statements, covered = int(statements), int(covered)
    if covered > statements:
        return None
    return statements, covered


def main(argv):
    parser = argparse.ArgumentParser(prog="clover.py")
    parser.add_argument("file")
    parser.add_argument("--field", required=True, choices=("statements", "covered", "percent"))
    args = parser.parse_args(argv)

    found = project_metrics(args.file)
    if found is None:
        return 2
    statements, covered = found
    if args.field == "statements":
        print(statements)
    elif args.field == "covered":
        print(covered)
    else:
        if statements == 0:
            return 2
        print(round(100.0 * covered / statements, 2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
