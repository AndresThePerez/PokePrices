#!/usr/bin/env -S python3 -I
"""DEN: every gate of this run has a record with a usable denominator (F-P0-004 zero-denominator rule).

Reads the manifest (env MANIFEST, default gates/manifest-P0.json) and $GATES_OUT/records/*.json
written by scripts/scorecard. Every manifest gate except DEN needs exactly one record whose id
matches its file name; a record that is not out_of_scope needs a numeric denominator > 0 and at
least the gate's manifest denominator_min, and a non-empty denominator_source; an out_of_scope
record needs its reason. A record for a gate the manifest does not list is a violation.
value = violations; denominator = records read. Writes $GATES_OUT/DEN.json.
"""
import json
import math
import os
import sys

SELF = "DEN"


def is_number(x):
    return isinstance(x, (int, float)) and not isinstance(x, bool) and math.isfinite(x)


def main():
    out = os.environ.get("GATES_OUT", "")
    if not out:
        sys.stderr.write("GATES_OUT must be set\n")
        return 2
    manifest_path = os.environ.get("MANIFEST") or "gates/manifest-P0.json"
    details = []

    def violation(gid, rule, **extra):
        item = {"gate": gid, "rule": rule}
        item.update(extra)
        details.append(item)

    gates = {}
    try:
        with open(manifest_path, encoding="utf-8") as fh:
            doc = json.load(fh)
        for gate in doc["gates"]:
            gid = gate["id"]
            if gid in gates:
                violation(gid, "manifest_duplicate_id")
            gates[gid] = gate
    except (OSError, ValueError, KeyError, TypeError) as exc:
        violation(None, "manifest_unreadable", error=str(exc))

    rec_dir = os.path.join(out, "records")
    try:
        names = sorted(n for n in os.listdir(rec_dir) if n.endswith(".json"))
    except OSError as exc:
        names = []
        violation(None, "records_unreadable", error=str(exc))
    found = {n[: -len(".json")] for n in names}

    read = 0
    for name in names:
        gid = name[: -len(".json")]
        if gid == SELF:
            continue
        read += 1
        if gid not in gates:
            violation(gid, "record_without_manifest_gate")
            continue
        try:
            with open(os.path.join(rec_dir, name), encoding="utf-8") as fh:
                rec = json.load(fh)
        except (OSError, ValueError) as exc:
            violation(gid, "record_unreadable", error=str(exc))
            continue
        if not isinstance(rec, dict):
            violation(gid, "record_not_an_object")
            continue
        if rec.get("id") != gid:
            violation(gid, "record_id_mismatch", record_id=rec.get("id"))
        status = rec.get("status")
        if status == "out_of_scope":
            reason = rec.get("reason")
            if not isinstance(reason, str) or not reason.strip():
                violation(gid, "out_of_scope_without_reason")
            continue
        den = rec.get("denominator")
        den_min = gates[gid].get("denominator_min")
        source = rec.get("denominator_source")
        if not is_number(den):
            violation(gid, "denominator_not_a_number", status=status, denominator=den)
        elif den <= 0:
            violation(gid, "zero_denominator", status=status, denominator=den)
        elif not is_number(den_min):
            violation(gid, "manifest_denominator_min_missing", denominator=den)
        elif den < den_min:
            violation(gid, "below_denominator_min", denominator=den, denominator_min=den_min)
        if not isinstance(source, str) or not source.strip():
            violation(gid, "denominator_source_missing")

    for gid in gates:
        if gid != SELF and gid not in found:
            violation(gid, "record_missing")

    value = len(details)
    result = {"id": SELF, "value": value, "denominator": read, "details": details}
    try:
        with open(os.path.join(out, SELF + ".json"), "w", encoding="utf-8") as fh:
            json.dump(result, fh, ensure_ascii=False)
            fh.write("\n")
    except OSError as exc:
        sys.stderr.write("DEN: cannot write %s: %s\n" % (os.path.join(out, SELF + ".json"), exc))
        return 1
    print("DEN: %d violations across %d records read" % (value, read))
    return 1 if value else 0


if __name__ == "__main__":
    raise SystemExit(main())
