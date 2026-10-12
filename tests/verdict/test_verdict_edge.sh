#!/bin/sh
# P0-i1-T26 review: adversarial edge cases for scripts/verdict and scripts/lib/ratchet.py.
# Cases are derived from the done_happy fixture at run time (T36 freezes gates/fixtures/**).
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
T=.gates/tmp/verdict-edge
rm -rf "$T"; mkdir -p "$T/cases"
RT="python3 -I scripts/lib/ratchet.py compare --kind"

cat > "$T/build.py" <<'PY'
import copy, hashlib, json, os, sys

SRC = "gates/fixtures/verdict/done_happy"


def sha(b):
    return hashlib.sha256(b).hexdigest()


def jl(name):
    with open(os.path.join(SRC, name)) as fh:
        return json.load(fh)


def put(path, data, raw=False):
    os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    with open(path, "wb") as fh:
        fh.write(data if raw else (json.dumps(data, indent=2) + "\n").encode())


def logtext(card, r):
    b = r["banner"]
    return ("## GATE %s head=%s run=%s start=%s context=primary\n## BANNER_CMD %s\n%s\n## BANNER_EXIT %s\n"
            "## COMMAND %s\n%s: value %s\nEXIT %s\n" % (r["id"], card["head"], card["run_id"], r["started_epoch"],
                                                       b["cmd"], b["line"], b["exit"], r["command"], r["id"], r["value"], r["exit"]))


class C:
    def __init__(s):
        s.m, s.sc, s.cf, s.gs = jl("manifest.json"), jl("scorecard.json"), jl("confirm.json"), jl("git_state.json")
        s.fi, s.ex, s.hg, s.de = jl("findings.json"), jl("exit_results.json"), jl("human_gates.json"), jl("deferrals.json")
        s.extra, s.drop, s.exp = {}, set(), None
        s.approved, s.fix_sc_manifest, s.keep = "auto", True, set()
        s.pfx = {"sc": "logs", "cf": "confirm-logs"}

    def g(s, gid):
        return next(x for x in s.m["gates"] if x["id"] == gid)

    def r(s, gid, card="sc"):
        return next(x for x in getattr(s, card)["gates"] if x["id"] == gid)

    def add_gate(s, gid, hard=True, fail=False):
        mg = copy.deepcopy(s.g("G-soft")); mg["id"] = gid; mg["hard"] = hard
        s.m["gates"].append(mg)
        s.m["floor"]["hard" if hard else "soft"].append(gid)
        for card in ("sc", "cf"):
            rr = copy.deepcopy(s.r("G-soft", card)); rr["id"] = gid; rr["hard"] = hard
            rr["log"] = "%s/%s.log" % (s.pfx[card], gid)
            if fail and card == "sc":
                rr["value"] = 1
            getattr(s, card)["gates"].append(rr)

    def drop_gate(s, gid):
        s.m["gates"] = [x for x in s.m["gates"] if x["id"] != gid]
        for k in ("hard", "soft"):
            s.m["floor"][k] = [x for x in s.m["floor"][k] if x != gid]
        for card in ("sc", "cf"):
            getattr(s, card)["gates"] = [x for x in getattr(s, card)["gates"] if x["id"] != gid]

    def finalize(s):
        mb = (json.dumps(s.m, indent=2) + "\n").encode()
        s.msha = sha(mb)
        if s.fix_sc_manifest:
            s.sc["manifest"]["sha256"] = s.msha
            if s.cf: s.cf["manifest"]["sha256"] = s.msha
        s.gs["manifest_sha256"] = s.msha
        s.gs["approved_sha256"] = s.msha if s.approved == "auto" else s.approved
        s.mb = mb
        for name in ("sc", "cf"):
            card = getattr(s, name)
            if not card: continue
            for rr in card["gates"]:
                if (name, rr["id"]) in s.keep:
                    continue
                rr["log_sha256"] = sha(logtext(card, rr).encode())

    def write_logs(s, base):
        for name in ("sc", "cf"):
            card = getattr(s, name)
            if not card: continue
            for rr in card["gates"]:
                if (name, rr["id"]) in s.keep:
                    continue
                put(os.path.join(base, rr["log"]), logtext(card, rr).encode(), raw=True)

    def save_case(s, d):
        s.finalize()
        os.makedirs(d, exist_ok=True)
        put(os.path.join(d, "manifest.json"), s.mb, raw=True)
        put(os.path.join(d, "scorecard.json"), s.sc)
        put(os.path.join(d, "git_state.json"), s.gs)
        for n, v in (("findings.json", s.fi), ("exit_results.json", s.ex), ("human_gates.json", s.hg),
                     ("deferrals.json", s.de), ("confirm.json", s.cf)):
            if n not in s.drop:
                put(os.path.join(d, n), v)
        for n, v in s.extra.items():
            put(os.path.join(d, n), v)
        s.write_logs(d)
        if s.exp is not None:
            put(os.path.join(d, "expected.txt"), ("\n".join(s.exp) + "\n").encode(), raw=True)


cases = {}


def case(name, exp, fn):
    cases[name] = (exp, fn)


def ND(*ids):
    return ["NOT_DONE"] + sorted(ids)


# ---- tampered stored status: raw fields say fail
def f(c): c.r("G-hard")["value"] = 5
case("value_high_stored_pass", ND("gate:G-hard"), f)
def f(c): c.r("G-hard")["exit"] = 1
case("exit_one_stored_pass", ND("gate:G-hard"), f)
def f(c): c.r("G-hard")["denominator"] = 0
case("denominator_zero_stored_pass", ND("gate:G-hard"), f)
def f(c): c.r("G-hard")["denominator"] = 1; c.g("G-hard")["denominator_min"] = 2
case("denominator_below_min", ND("gate:G-hard"), f)
def f(c): c.g("G-hard")["banner_re"] = "^NOT-A-SHELL"
case("banner_regex_mismatch", ND("gate:G-hard", "confirm:gate:G-hard"), f)
def f(c): c.r("G-hard")["value"] = "0"
case("value_is_string", ND("gate:G-hard"), f)
def f(c): c.r("G-hard")["value"] = True; c.g("G-hard")["threshold"] = 1; c.g("G-hard")["op"] = "=="
case("value_is_bool", ND("gate:G-hard", "confirm:gate:G-hard"), f)

# ---- log tampering (post-processed after save)
def post_edit(c, d): 
    p = os.path.join(d, "logs/G-hard.log"); t = open(p).read().replace("G-hard: value 0", "G-hard: value 9"); open(p, "w").write(t)
case("log_edited_after_run", ND("scorecard:log_tampered:G-hard"), lambda c: None)
def post_del(c, d): os.remove(os.path.join(d, "logs/G-hard.log"))
case("log_deleted", ND("gate:G-hard", "scorecard:log_tampered:G-hard"), lambda c: None)
def post_head(c, d):
    p = os.path.join(d, "logs/G-hard.log"); t = open(p).read().replace("head=0123456789abcdef", "head=fedcba9876543210", 1)
    open(p, "w").write(t); c.r("G-hard")["log_sha256"] = sha(t.encode())
case("log_head_swapped_sha_fixed", ND("scorecard:log_tampered:G-hard"), lambda c: None)
def post_exitline(c, d):
    p = os.path.join(d, "logs/G-hard.log"); t = open(p).read().replace("EXIT 0\n", "EXIT\n"); open(p, "w").write(t); c.r("G-hard")["log_sha256"] = sha(t.encode())
case("exit_line_mangled_sha_fixed", ND("gate:G-hard", "scorecard:log_tampered:G-hard"), lambda c: None)
def post_nobanner(c, d):
    p = os.path.join(d, "logs/G-hard.log"); t = open(p).read().replace("## BANNER_EXIT 0\n", ""); open(p, "w").write(t); c.r("G-hard")["log_sha256"] = sha(t.encode())
case("banner_exit_line_missing_sha_fixed", ND("gate:G-hard", "scorecard:log_tampered:G-hard"), lambda c: None)
def post_exitdiff(c, d):
    p = os.path.join(d, "logs/G-hard.log"); t = open(p).read().replace("EXIT 0\n", "EXIT 3\n"); open(p, "w").write(t); c.r("G-hard")["log_sha256"] = sha(t.encode())
case("log_exit_disagrees_with_record", ND("gate:G-hard", "scorecard:log_tampered:G-hard"), lambda c: None)
POST = {"log_edited_after_run": post_edit, "log_deleted": post_del, "log_head_swapped_sha_fixed": post_head,
        "exit_line_mangled_sha_fixed": post_exitline, "banner_exit_line_missing_sha_fixed": post_nobanner,
        "log_exit_disagrees_with_record": post_exitdiff}
KEEPSHA = {"log_edited_after_run", "log_deleted"}

# ---- record structure
def f(c):
    x = copy.deepcopy(c.r("G-soft")); x["id"] = "X-extra"; x["log"] = "logs/X-extra.log"; c.sc["gates"].append(x)
case("extra_record_unknown_gate", ND("scorecard:unknown:X-extra"), f)
def f(c): c.sc["gates"].append(copy.deepcopy(c.r("G-hard")))
case("two_records_for_one_gate", ND("scorecard:duplicate:G-hard", "gate:G-hard"), f)
def f(c): c.gs["head"] = "f" * 40
case("scorecard_head_differs_from_git", ND("scorecard:head", "confirm:scorecard:head"), f)
def f(c): c.gs["head_commit_epoch"] = 1791700001
case("head_commit_epoch_disagrees", ND("scorecard:head_epoch", "confirm:scorecard:head_epoch"), f)
def f(c): c.r("G-hard")["started_epoch"] = c.gs["head_commit_epoch"]
case("started_epoch_equal_head_epoch_allowed", ["DONE"], f)
def f(c): c.r("G-hard")["started_epoch"] = c.gs["head_commit_epoch"] - 1
case("started_epoch_one_second_stale", ND("scorecard:stale:G-hard"), f)
def f(c): c.sc["clean_after"] = False
case("clean_after_false", ND("scorecard:dirty"), f)
def f(c): c.sc["clean_before"] = "true"
case("clean_before_truthy_string", ND("scorecard:dirty"), f)
def f(c): c.sc["run_context"] = "ci"
case("ci_context_in_full_mode", ND("scorecard:context"), f)
def f(c): c.fix_sc_manifest = False; c.m["why_edit"] = "changed after the run"
case("manifest_edited_after_scorecard", ND("scorecard:manifest", "confirm:scorecard:manifest"), f)
def f(c): c.sc["schema"] = "other/v9"
case("scorecard_schema_wrong", ND("scorecard:schema"), f)

# ---- soft ratio and baseline
def soft_many(fails):
    def f(c):
        for i in range(99):
            c.add_gate("S%02d" % i, hard=False, fail=i < fails)
        c.drop_gate("G-soft") if False else None
    return f
def f(c):
    for i in range(99): c.add_gate("S%02d" % i, hard=False, fail=i < 20)
case("soft_ratio_exactly_80_of_100", ["DONE"], f)
def f(c):
    for i in range(99): c.add_gate("S%02d" % i, hard=False, fail=i < 21)
case("soft_ratio_79_of_100", ND("soft:ratio"), f)
def f(c): c.drop_gate("G-soft")
case("soft_list_empty_allowed", ["DONE"], f)
def f(c): c.r("G-soft")["value"] = 1
case("only_soft_gate_failing", ND("soft:ratio"), f)

def base(c, value, tol, base_value=2):
    c.g("G-soft")["op"] = "<="; c.g("G-soft")["threshold"] = 10
    c.r("G-soft")["op"] = "<="; c.r("G-soft")["threshold"] = 10; c.r("G-soft")["value"] = value
    c.extra["baseline.json"] = {"gates": [{"id": "G-soft", "value": base_value}]}
    if tol is not None:
        c.extra["jitter.json"] = {"tolerance": {"G-soft": tol}}
case("baseline_within_tolerance_exact", ["DONE"], lambda c: base(c, 5, 3))
case("baseline_beyond_tolerance", ND("soft:regression:G-soft"), lambda c: base(c, 6, 3))
case("baseline_no_jitter_means_zero_tolerance", ND("soft:regression:G-soft"), lambda c: base(c, 3, None))
case("baseline_unreadable", ND("soft:regression:baseline"), lambda c: (base(c, 2, None), c.extra.__setitem__("baseline.json", "junk")))

# ---- findings
def fnd(*recs, hg=None, de=None, exp=None):
    def f(c):
        c.fi = list(recs)
        if hg is not None: c.hg = hg
        if de is not None: c.de = de
    return f
RAN = {"HG-12": {"status": "ran-by-Andres 2026-10-11"}}
PEND = {"HG-12": {"status": "pending"}}
DEF = {"F-P0-005": {"phase": "P1", "human_gate": "HG-12"}}
def F(i, sev, st): return {"id": i, "severity": sev, "status": st, "title": "t"}
case("finding_id_short", ND("findings:invalid"), fnd(F("F-P0-1", "High", "fixed(abc1234)")))
case("finding_severity_uppercase", ND("findings:invalid"), fnd(F("F-P0-001", "HIGH", "open")))
case("finding_fixed_hash_too_short", ND("finding:F-P0-001"), fnd(F("F-P0-001", "High", "fixed(abc)")))
case("finding_deferred_without_space", ND("finding:F-P0-005"), fnd(F("F-P0-005", "High", "deferred(P1,Andres)"), hg=RAN, de=DEF))
case("finding_deferred_high_gate_pending", ND("finding:F-P0-005"), fnd(F("F-P0-005", "High", "deferred(P1, Andres)"), hg=PEND, de=DEF))
case("finding_deferred_high_gate_ran", ["DONE"], fnd(F("F-P0-005", "High", "deferred(P1, Andres)"), hg=RAN, de=DEF))
case("finding_deferred_high_no_deferral_entry", ND("finding:F-P0-005"), fnd(F("F-P0-005", "High", "deferred(P1, Andres)"), hg=RAN, de={}))
case("finding_deferred_high_same_phase", ND("finding:F-P0-005"), fnd(F("F-P0-005", "High", "deferred(P0, Andres)"), hg=RAN, de={"F-P0-005": {"phase": "P0", "human_gate": "HG-12"}}))
case("finding_deferred_critical_even_with_ran_gate", ND("finding:F-P0-005"), fnd(F("F-P0-005", "Critical", "deferred(P1, Andres)"), hg=RAN, de=DEF))
case("finding_duplicate_id_one_open", ND("finding:F-P0-009"), fnd(F("F-P0-009", "High", "fixed(abc1234)"), F("F-P0-009", "High", "open")))
case("finding_open_medium_and_low_pass", ["DONE"], fnd(F("F-P0-001", "Medium", "open"), F("F-P0-002", "Low", "open"), F("F-P0-003", "Medium", "nonsense")))
case("finding_status_not_string", ND("finding:F-P0-001"), fnd({"id": "F-P0-001", "severity": "Critical", "status": 7}))
case("findings_empty_array_ok", ["DONE"], fnd())
def f(c): c.drop.add("findings.json"); c.fi = None
case("findings_file_absent", ND("findings:invalid"), f)

# ---- approval, floor
def f(c): c.approved = None
case("approval_absent_only_human_gate", ["NOT_DONE", "human-gate:HG-07", "HUMAN_GATES_ONLY"], f)
def f(c): c.approved = None; c.fi = [F("F-P0-001", "High", "open")]
case("approval_absent_plus_open_high_not_human_only", ND("human-gate:HG-07", "finding:F-P0-001"), f)
def f(c): c.approved = "0" * 64
case("approval_hash_differs", ND("manifest:hash_mismatch"), f)
def f(c): c.m["floor"]["hard"].append("G-soft")
case("floor_hard_names_soft_gate", ND("manifest:floor"), f)
def f(c): c.g("G-soft")["denominator_min"] = 0; c.r("G-soft")["denominator_min"] = 0
case("denominator_min_zero_on_soft_gate", ND("manifest:floor", "soft:ratio", "confirm:soft:ratio"), f)
def f(c): c.m["floor"]["soft"].append("G-gone")
case("floor_soft_names_unknown_gate", ND("manifest:floor"), f)
def f(c): c.m["floor"] = {"hard": [], "soft": []}
case("empty_floor_is_not_a_failure", ["DONE"], f)
def f(c): c.m["gates"].append(copy.deepcopy(c.g("G-hard")))
case("manifest_duplicate_gate_ids", ND("manifest:duplicate:G-hard", "manifest:invalid"), f)  # R89 adds the duplicate tag

# ---- confirm
def f(c): c.drop.add("confirm.json"); c.cf = None
case("confirm_absent", ND("confirm:missing"), f)
def f(c):
    c.r("G-soft", "cf")["log"] = "logs/G-soft.log"; c.keep.add(("cf", "G-soft"))
case("confirm_shares_log_paths", ND("confirm:shared_logs"), f)
def f(c): c.cf["started_epoch"] = c.sc["finished_epoch"]
case("confirm_starts_exactly_at_first_finish", ND("confirm:overlap"), f)
def f(c): c.cf["started_epoch"] = c.sc["finished_epoch"] + 1
case("confirm_starts_one_second_after", ["DONE"], f)
def f(c): c.r("G-hard", "cf")["value"] = 7
case("confirm_scorecard_fails_rule_two", ND("confirm:gate:G-hard"), f)
def f(c): c.cf["clean_after"] = False
case("confirm_dirty", ND("confirm:scorecard:dirty"), f)
def f(c): c.cf["head"] = "e" * 40
case("confirm_other_head", ND("confirm:head"), f)
def f(c): c.cf["run_context"] = "nested"
case("confirm_nested_context", ND("confirm:scorecard:context"), f)

# ---- exit criteria
def f(c): c.ex["EC-A"] = 1
case("exit_criterion_fails", ND("exit:EC-A"), f)
def f(c): del c.ex["EC-B"]
case("exit_criterion_result_missing", ND("exit:EC-B"), f)
def f(c): c.ex["EC-A"] = False
case("exit_criterion_result_false_boolean", ND("exit:EC-A"), f)
def f(c): c.m["exit_criteria"] = [{"id": "EC-A", "check": "true"}, {"id": "EC-B", "check": "true"}, {"check": "true"}]
case("exit_criterion_without_id", ND("exit:invalid"), f)

# ---- ratchet inputs in a case
def rat(kind, a, b, rel="api/phpstan.neon"):
    def f(c):
        c.extra["ratchet/ratchet.json"] = {"default_kind": "hash", "kinds": {rel: kind}}
        c.rat = (rel, a, b)
    return f
NEON = "includes:\n    - vendor/larastan/larastan/extension.neon\n\nparameters:\n    level: 8\n    paths:\n        - app\n    reportUnmatchedIgnoredErrors: true\n"
case("ratchet_unmatched_true_to_false", ND("gate:AG1"), rat("phpstan-neon", NEON, NEON.replace("true", "false")))
case("ratchet_neon_exclude_added", ND("gate:AG1"), rat("phpstan-neon", NEON, NEON + "    excludePaths:\n        - app/Legacy\n"))
case("ratchet_neon_inline_map_undecidable", ND("gate:AG1"), rat("phpstan-neon", NEON, "parameters: {level: 9}\n"))
case("ratchet_neon_path_added_ok", ["DONE"], rat("phpstan-neon", NEON, NEON.replace("- app\n", "- app\n        - routes\n")))
case("ratchet_unknown_kind", ND("gate:AG1"), rat("made-up-kind", NEON, NEON.replace("8", "9")))
case("ratchet_hash_kind_any_change_blocks", ND("gate:AG1"), rat("hash", NEON, NEON + "\n"))


def build(out):
    for name, (exp, fn) in cases.items():
        c = C()
        c.exp = exp
        c.rat = None
        fn(c)
        if name in POST and name in KEEPSHA:
            pass
        d = os.path.join(out, name)
        # POST edits need the logs written first, then sha repaired where the case says so
        c.finalize()
        c.save_case(d)
        if name in POST:
            POST[name](c, d)
            # re-save scorecard so a repaired sha lands in it; tamper-only cases keep the stale sha
            if name not in KEEPSHA:
                put(os.path.join(d, "scorecard.json"), c.sc)
        if c.rat:
            rel, a, b = c.rat
            put(os.path.join(d, "ratchet/anchor", rel), a.encode(), raw=True)
            put(os.path.join(d, "ratchet/head", rel), b.encode(), raw=True)
        if "baseline.json" in c.extra and c.extra["baseline.json"] == "junk":
            with open(os.path.join(d, "baseline.json"), "w") as fh:
                fh.write("{not json")


def live(mode, root, variant):
    c = C()
    c.pfx = {"sc": ".gates/run1/logs", "cf": ".gates/run2/logs"}
    for name in ("sc", "cf"):
        for rr in getattr(c, name)["gates"]:
            rr["log"] = "%s/%s.log" % (c.pfx[name], rr["id"])
    if variant == "dupexit":
        c.m["exit_criteria"] = [{"id": "EC-A", "check": "false"}, {"id": "EC-A", "check": "true"}]
    if variant == "dupexit_pass_then_fail":
        c.m["exit_criteria"] = [{"id": "EC-A", "check": "true"}, {"id": "EC-A", "check": "false"}]
    if variant == "dupexit_three":
        c.m["exit_criteria"] = [{"id": "EC-A", "check": "true"}, {"id": "EC-A", "check": "false"}, {"id": "EC-A", "check": "true"}]
    if variant == "dupgate":
        c.m["gates"].append(copy.deepcopy(c.g("G-soft")))
    if variant in ("nested", "primary_oos"):
        c.g("G-hard")["scope"] = "primary"
    if mode == "pre":
        c.finalize()
        put(os.path.join(root, "gates/manifest-P0.json"), c.mb, raw=True)
        put(os.path.join(root, "gates/manifest-P0.approved.sha256"), (c.msha + "  gates/manifest-P0.json\n").encode(), raw=True)
        put(os.path.join(root, ".gitignore"), b".gates/\n", raw=True)
        return
    import subprocess
    head = subprocess.run(["git", "-C", root, "rev-parse", "HEAD"], capture_output=True, text=True).stdout.strip()
    ep = int(subprocess.run(["git", "-C", root, "show", "-s", "--format=%ct", "HEAD"], capture_output=True, text=True).stdout)
    delta = ep - c.gs["head_commit_epoch"]
    def sh(o):
        if isinstance(o, dict):
            for k, v in list(o.items()):
                if k.endswith("_epoch") and isinstance(v, int): o[k] = v + delta
                else: sh(v)
        elif isinstance(o, list):
            for v in o: sh(v)
    for card in (c.sc, c.cf):
        card["head"] = head; sh(card)
    if variant in ("nested", "primary_oos"):
        if variant == "nested": c.sc["run_context"] = "nested"
        c.r("G-hard")["status"] = "out_of_scope"
    c.finalize()
    c.write_logs(root)
    put(os.path.join(root, ".gates/run1/scorecard.json"), c.sc)
    put(os.path.join(root, ".gates/run2/scorecard.json"), c.cf)
    put(os.path.join(root, ".gates/findings.json"), c.fi)
    put(os.path.join(root, ".gates/human.json"), c.hg)
    put(os.path.join(root, ".gates/deferrals.json"), c.de)
    if variant == "tamperlog":
        q = os.path.join(root, ".gates/run1/logs/G-hard.log")
        t = open(q).read().replace("value 0", "value 8"); open(q, "w").write(t)
    if variant == "dirty":
        put(os.path.join(root, "stray.txt"), b"x", raw=True)


if sys.argv[1] in ("pre", "post"):
    live(sys.argv[1], sys.argv[2], sys.argv[3])
else:
    build(sys.argv[1])
PY

python3 -I "$T/build.py" "$T/cases" || { echo "not ok - build edge cases"; exit 1; }
scripts/verdict --selftest "$T/cases" --out "$T/edge-selftest.json" > "$T/edge-selftest.out" 2> "$T/edge-selftest.err"
for d in "$T"/cases/*/; do
  n=$(basename "$d")
  check "case $n gives its expected verdict" /usr/bin/grep -Eq "^ok +$n\$" "$T/edge-selftest.out"
done
check "edge corpus holds at least 60 cases" test "$(ls "$T/cases" | wc -l)" -ge 60
check "edge selftest value is 0 when every case matches" jq -e '.value == 0 and .denominator >= 60' "$T/edge-selftest.json"

# ---- selftest harness itself
mkdir -p "$T/empty"
check "selftest on an empty directory is never value 0" sh -c '! scripts/verdict --selftest "$0/empty" --out "$0/e.json" && jq -e ".value >= 1 and .denominator == 0" "$0/e.json"' "$T"
mkdir -p "$T/noexp"; cp -r gates/fixtures/verdict/done_happy "$T/noexp/"; rm "$T/noexp/done_happy/expected.txt"
check "selftest: a case without expected.txt is a mismatch" sh -c '! scripts/verdict --selftest "$0/noexp" --out "$0/ne.json" && jq -e ".value >= 1" "$0/ne.json"' "$T"
mkdir -p "$T/badman"; cp -r gates/fixtures/verdict/done_happy "$T/badman/"; printf '{' > "$T/badman/done_happy/manifest.json"
check "selftest: a broken case never passes" sh -c '! scripts/verdict --selftest "$0/badman" --out "$0/bm.json"' "$T"
check "selftest without --out is a usage error (status 2)" sh -c 'scripts/verdict --selftest gates/fixtures/verdict >/dev/null 2>&1; test $? -eq 2'

# ---- ratchet.py comparators
rt() { # kind anchor-text head-text -> prints the word
  printf '%s' "$2" > "$T/ra"; printf '%s' "$3" > "$T/rb"; $RT "$1" "$T/ra" "$T/rb" 2>/dev/null; }
sed "s/reportUnmatchedIgnoredErrors: true/reportUnmatchedIgnoredErrors: false/" api/phpstan.neon > "$T/real-neon-false.neon"
NEON='includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 8
    paths:
        - app
    reportUnmatchedIgnoredErrors: true
'
check_out "ratchet neon: identical is same" same rt phpstan-neon "$NEON" "$NEON"
check_out "ratchet neon: level 8 to 10 is stricter" stricter rt phpstan-neon "$NEON" "$(printf '%s' "$NEON" | sed 's/level: 8/level: 10/')"
check_out "ratchet neon: level 10 to 9 is looser (numeric, not textual)" looser rt phpstan-neon "$(printf '%s' "$NEON" | sed 's/level: 8/level: 10/')" "$(printf '%s' "$NEON" | sed 's/level: 8/level: 9/')"
check_out "ratchet neon: a path added is stricter" stricter rt phpstan-neon "$NEON" "$(printf '%s' "$NEON" | sed 's/- app/- app\n        - routes/')"
check_out "ratchet neon: a path removed is looser" looser rt phpstan-neon "$(printf '%s' "$NEON" | sed 's/- app/- app\n        - routes/')" "$NEON"
check_out "ratchet neon: excludePaths removed is stricter" stricter rt phpstan-neon "${NEON}    excludePaths:
        - app/Legacy
" "$NEON"
check_out "ratchet neon: excludePaths added is looser" looser rt phpstan-neon "$NEON" "${NEON}    excludePaths:
        - app/Legacy
"
check_out "ratchet neon: reportUnmatchedIgnoredErrors true to false is looser" looser rt phpstan-neon "$NEON" "$(printf '%s' "$NEON" | sed 's/Errors: true/Errors: false/')"
check_out "ratchet neon: the Larastan include removed is looser" looser rt phpstan-neon "$NEON" "$(printf '%s' "$NEON" | sed '1,2d')"
check_out "ratchet neon: an include other than Larastan added is undecidable" undecidable rt phpstan-neon "$NEON" "$(printf '%s' "$NEON" | sed 's#^includes:#includes:\n    - other.neon#')"
check_out "ratchet neon: an anchor/inline-map file is undecidable" undecidable rt phpstan-neon "$NEON" "parameters: {level: 9}
"
check_out "ratchet neon: an unknown parameter changed is undecidable" undecidable rt phpstan-neon "$NEON" "${NEON}    treatPhpDocTypesAsCertain: false
"
check_out "ratchet neon: the real api/phpstan.neon sets reportUnmatchedIgnoredErrors true and false is looser" looser $RT phpstan-neon api/phpstan.neon "$T/real-neon-false.neon"
check_out "ratchet neon: a tab-indented file is undecidable" undecidable rt phpstan-neon "$NEON" "parameters:
	level: 9
"
check_out "ratchet phpstan-params is the canonical name for the same answer" stricter rt phpstan-params "$NEON" "$(printf '%s' "$NEON" | sed 's/level: 8/level: 9/')"

TS='{"compilerOptions":{"strict":true,"noUnusedLocals":false},"include":["src"],"exclude":["dist"]}'
check_out "ratchet tsconfig: comments and trailing commas parse as same" same rt tsconfig "$TS" '// c
{ /* b */ "compilerOptions":{"strict":true,"noUnusedLocals":false,},
 "include":["src",], "exclude":["dist",], }'
check_out "ratchet tsconfig: a // inside a string is not a comment" same rt tsconfig '{"extends":"http://x/y","compilerOptions":{}}' '{"extends":"http://x/y","compilerOptions":{},}'
check_out "ratchet tsconfig: strict true to false is looser" looser rt tsconfig "$TS" "$(printf '%s' "$TS" | sed 's/"strict":true/"strict":false/')"
check_out "ratchet tsconfig: strict flag dropped is looser" looser rt tsconfig "$TS" '{"compilerOptions":{"noUnusedLocals":false},"include":["src"],"exclude":["dist"]}'
check_out "ratchet tsconfig: noUnusedLocals false to true is stricter" stricter rt tsconfig "$TS" "$(printf '%s' "$TS" | sed 's/"noUnusedLocals":false/"noUnusedLocals":true/')"
check_out "ratchet tsconfig: exclude grows is looser" looser rt tsconfig "$TS" "$(printf '%s' "$TS" | sed 's#"dist"#"dist","src/gen"#')"
check_out "ratchet tsconfig: include shrinks is looser" looser rt tsconfig '{"include":["src","lib"]}' '{"include":["src"]}'
check_out "ratchet tsconfig: an unknown compilerOption changed is undecidable" undecidable rt tsconfig '{"compilerOptions":{"target":"es2022"}}' '{"compilerOptions":{"target":"es5"}}'
check_out "ratchet tsconfig: a non-boolean strict flag is undecidable" undecidable rt tsconfig '{"compilerOptions":{"strict":true}}' '{"compilerOptions":{"strict":"true"}}'
check_out "ratchet tsconfig: garbage is undecidable" undecidable rt tsconfig '{"a":1}' '{"a":'

VT='coverage: { thresholds: { lines: 80, branches: 70, functions: 60, statements: 80 } }'
check_out "ratchet vitest: lines 80 to 85 is stricter" stricter rt vitest "$VT" "$(printf '%s' "$VT" | sed 's/lines: 80/lines: 85/')"
check_out "ratchet vitest: lines 85 to 80 is looser" looser rt vitest "$(printf '%s' "$VT" | sed 's/lines: 80/lines: 85/')" "$VT"
check_out "ratchet vitest: one up and one down is looser" looser rt vitest "$VT" "$(printf '%s' "$VT" | sed 's/lines: 80/lines: 90/; s/branches: 70/branches: 69/')"
check_out "ratchet vitest: other text changed is undecidable" undecidable rt vitest "$VT" "$VT // x"
check_out "ratchet vitest: a threshold dropped is undecidable" undecidable rt vitest "$VT" "$(printf '%s' "$VT" | sed 's/, statements: 80//')"
check_out "ratchet vitest: fractional change 80.9 to 80.1 is not accepted" undecidable rt vitest 'thresholds: { lines: 80.9 }' 'thresholds: { lines: 80.1 }'
check_out "ratchet vitest-thresholds alias agrees" looser rt vitest-thresholds "$(printf '%s' "$VT" | sed 's/lines: 80/lines: 85/')" "$VT"

# phpunit: every one of the seven strictness attributes in api/phpunit.xml ratchets (R88)
n=0
for attr in $(/usr/bin/grep -oE '(failOn|beStrictAbout)[A-Za-z]*="true"' api/phpunit.xml | sed 's/="true"//'); do
  n=$((n + 1))
  sed "s/$attr=\"true\"/$attr=\"false\"/" api/phpunit.xml > "$T/pu-$attr.xml"
  check_out "ratchet phpunit-xml: $attr true to false is looser" looser $RT phpunit-xml api/phpunit.xml "$T/pu-$attr.xml"
done
check "ratchet phpunit-xml: seven strictness attributes found in api/phpunit.xml" test "$n" -eq 7
sed 's/ failOnRisky="true"//' api/phpunit.xml > "$T/pu-dropped.xml"
check_out "ratchet phpunit-xml: dropping failOnRisky is looser" looser $RT phpunit-xml api/phpunit.xml "$T/pu-dropped.xml"
check_out "ratchet phpunit-xml: identical is same" same $RT phpunit-xml api/phpunit.xml api/phpunit.xml
sed 's/<phpunit /<phpunit colors="false" /' api/phpunit.xml > "$T/pu-colors.xml"
check_out "ratchet phpunit-xml: an unrelated attribute is undecidable" undecidable $RT phpunit-xml api/phpunit.xml "$T/pu-colors.xml"
PX='<phpunit failOnRisky="true"><source><include><directory>app</directory></include><exclude><directory>app/Gen</directory><directory>app/Old</directory></exclude></source></phpunit>'
check_out "ratchet phpunit-xml: an excluded directory added is looser" looser rt phpunit-xml "$(printf '%s' "$PX" | sed 's#<directory>app/Old</directory>##')" "$PX"
check_out "ratchet phpunit-xml: an excluded directory removed is stricter" stricter rt phpunit-xml "$PX" "$(printf '%s' "$PX" | sed 's#<directory>app/Old</directory>##')"
check_out "ratchet phpunit-xml: an included directory removed is looser" looser rt phpunit-xml '<phpunit><source><include><directory>app</directory><directory>lib</directory></include></source></phpunit>' '<phpunit><source><include><directory>app</directory></include></source></phpunit>'
check_out "ratchet phpunit-xml: malformed XML is undecidable" undecidable rt phpunit-xml "$PX" '<phpunit'

# json-subtree, ignore-lines, hash, unknown kinds
check_out "ratchet json-subtree: key order is same" same rt 'json-subtree:.scripts' '{"scripts":{"a":"1","b":"2"},"x":1}' '{"x":9,"scripts":{"b":"2","a":"1"}}'
check_out "ratchet json-subtree: a value change is looser" looser rt 'json-subtree:.scripts' '{"scripts":{"a":"1"}}' '{"scripts":{"a":"2"}}'
check_out "ratchet json-subtree: the subtree vanishing is looser" looser rt 'json-subtree:.scripts' '{"scripts":{"a":"1"}}' '{"x":1}'
check_out "ratchet json-subtree: a path outside the supported syntax is undecidable" undecidable rt 'json-subtree:.a[0]' '{"a":[1]}' '{"a":[1]}'
check_out "ratchet ignore-lines: a comment-only change is same" same rt ignore-lines 'a
# one
b
' 'a
# two

b
'
check_out "ratchet ignore-lines: a line removed is stricter" stricter rt ignore-lines 'a
b
' 'a
'
check_out "ratchet ignore-lines: a line added is looser" looser rt ignore-lines 'a
' 'a
b
'
check_out "ratchet hash: identical is same" same rt hash 'x' 'x'
check_out "ratchet hash: a trailing newline is looser, never stricter" looser rt hash 'x' 'x
'
check_out "ratchet: an unknown kind is undecidable" undecidable rt no-such-kind 'x' 'x'
check "ratchet exit code: stricter is 0" sh -c 'printf a > "$0/a"; printf "a\n" > "$0/b"; python3 -I scripts/lib/ratchet.py compare --kind ignore-lines "$0/a" "$0/b"; test $? -eq 0' "$T"
printf 'a\n' > "$T/lo-a"; printf 'a\nb\n' > "$T/lo-b"
check "ratchet exit code: looser is 1" sh -c '! python3 -I scripts/lib/ratchet.py compare --kind ignore-lines "$0/lo-a" "$0/lo-b"' "$T"
check "ratchet exit code: an unreadable file is undecidable and exits 1" sh -c 'out=$(python3 -I scripts/lib/ratchet.py compare --kind hash "$0/none-a" "$0/none-b" 2>/dev/null); test "$out" = undecidable'  "$T"
check "ratchet: a wrong argument count is a usage error (2)" sh -c 'python3 -I scripts/lib/ratchet.py compare --kind hash >/dev/null 2>&1; test $? -eq 2'

# ---- recipe lint and hygiene
check "lint: no exit 0, || true or || : in verdict or ratchet" sh -c '! /usr/bin/grep -nE "exit 0|exit\(0\)|\|\| *true|\|\| *:" scripts/verdict scripts/lib/ratchet.py'
check "lint: both scripts end with raise SystemExit" sh -c 'tail -n 1 scripts/verdict | /usr/bin/grep -q "raise SystemExit(" && tail -n 1 scripts/lib/ratchet.py | /usr/bin/grep -q "raise SystemExit("'
check "lint: shebang is python3 -I and both are executable" sh -c 'test "$(head -n 1 scripts/verdict)" = "#!/usr/bin/env -S python3 -I" && test "$(head -n 1 scripts/lib/ratchet.py)" = "#!/usr/bin/env -S python3 -I" && test -x scripts/verdict && test -x scripts/lib/ratchet.py'
check "R35: verdict fixtures carry no home path or LAN address" sh -c '! /usr/bin/grep -rnE "/ho""me/|/Users/|192\.168\.|10\.[0-9]+\.[0-9]+\.[0-9]+|100\.[0-9]+\.[0-9]+\.[0-9]+|\.home\b" gates/fixtures/verdict'
check "usage: no mode and no arguments is status 2" sh -c 'scripts/verdict >/dev/null 2>&1; test $? -eq 2'
check "usage: full mode without --findings is status 2" sh -c 'scripts/verdict --manifest gates/manifest-P0.json --scorecard gates/manifest-P0.json >/dev/null 2>&1; test $? -eq 2'
check "usage: --gates-only refuses --findings (it never reads findings)" sh -c 'scripts/verdict --gates-only --manifest gates/manifest-P0.json --scorecard gates/manifest-P0.json --findings x >/dev/null 2>&1; test $? -eq 2'
cp gates/manifest-P0.json "$T/not-a-scorecard.json"
check "gates-only on a non-scorecard file fails closed" sh -c 'out=$(scripts/verdict --gates-only --manifest gates/manifest-P0.json --scorecard "$0/not-a-scorecard.json" 2>/dev/null); rc=$?; test $rc -eq 1 && case "$out" in "GATES_FAIL "*scorecard:schema*) true;; *) false;; esac' "$T"
check "gates-only on a non-scorecard file leaves no artefact under the tracked gates/ tree" sh -c 'test ! -e gates/verdict && test -z "$(git status --porcelain --untracked-files=all -- gates)"'
# gate:AG1 may lead the list while frozen files differ from the anchor (R153/R155); no other tag may precede scorecard:unreadable.
check "gates-only with a missing scorecard fails closed" sh -c 'out=$(scripts/verdict --gates-only --manifest gates/manifest-P0.json --scorecard "$0/nope.json" 2>/dev/null); test $? -eq 1 && case "$out" in "GATES_FAIL scorecard:unreadable"*|"GATES_FAIL gate:AG1 scorecard:unreadable"*) true;; *) false;; esac' "$T"
# ---- live CLI against a scratch git repository (no network, no containers)
GIT="git -c core.hooksPath=/dev/null -c user.name=edge -c user.email=edge@example.com"
mkrepo() { # variant -> $T/repo ready
  rm -rf "$T/repo"; mkdir -p "$T/repo"
  git -C "$T/repo" init -q && python3 -I "$T/build.py" pre "$T/repo" "$1" &&
    $GIT -C "$T/repo" add -A && $GIT -C "$T/repo" commit -q -m scratch &&
    python3 -I "$T/build.py" post "$T/repo" "$1"
}
V="$PWD/scripts/verdict"
gv() { (cd "$T/repo" && "$V" --gates-only --manifest gates/manifest-P0.json --scorecard .gates/run1/scorecard.json 2>/dev/null); }
fv() { (cd "$T/repo" && "$V" --manifest gates/manifest-P0.json --scorecard .gates/run1/scorecard.json --findings .gates/findings.json --confirm .gates/run2/scorecard.json --human-gates .gates/human.json --deferrals .gates/deferrals.json 2>/dev/null); }
mkrepo ok || echo "not ok - scratch repo build"
check_out "live gates-only on an honest scorecard prints GATES_OK" GATES_OK gv
check_out "live full mode on an honest scorecard prints DONE" DONE fv
check "live full mode exit status is 0 on DONE" sh -c 'cd "$0/repo" && "$1" --manifest gates/manifest-P0.json --scorecard .gates/run1/scorecard.json --findings .gates/findings.json --confirm .gates/run2/scorecard.json --human-gates .gates/human.json --deferrals .gates/deferrals.json >/dev/null 2>&1' "$T" "$V"
check "live full mode logs each exit criterion next to the scorecard" test -f "$T/repo/.gates/run1/exit/EC-A.log" -a "$(tail -n 1 "$T/repo/.gates/run1/exit/EC-A.log")" = "EXIT 0"
check_out "live full mode without --confirm is NOT_DONE confirm:missing" "NOT_DONE
confirm:missing" sh -c 'cd "$0/repo" && "$1" --manifest gates/manifest-P0.json --scorecard .gates/run1/scorecard.json --findings .gates/findings.json --human-gates .gates/human.json --deferrals .gates/deferrals.json 2>/dev/null' "$T" "$V"
check_out "live gates-only never opens a missing findings file" GATES_OK sh -c 'rm -f "$0/repo/.gates/findings.json"; cd "$0/repo" && "$1" --gates-only --manifest gates/manifest-P0.json --scorecard .gates/run1/scorecard.json 2>/dev/null' "$T" "$V"
mkrepo tamperlog
check_out "live: a log edited after the run is GATES_FAIL scorecard:log_tampered" "GATES_FAIL scorecard:log_tampered:G-hard" gv
mkrepo dirty
check_out "live: an untracked file makes the tree dirty" "GATES_FAIL scorecard:dirty" gv
mkrepo dupexit
check_out "live: two exit criteria sharing an id cannot hide a failing one" "NOT_DONE
exit:EC-A
manifest:duplicate:EC-A" fv
mkrepo dupexit_pass_then_fail
check_out "live: a duplicated exit id whose first entry passes and second fails still blocks" "NOT_DONE
exit:EC-A
manifest:duplicate:EC-A" fv
mkrepo dupexit_three
check_out "live: three exit entries with one id and a failing middle still block" "NOT_DONE
exit:EC-A
manifest:duplicate:EC-A" fv
mkrepo dupgate
check_out "live gates-only: a duplicated gate id fails closed with manifest:duplicate" "GATES_FAIL manifest:duplicate:G-soft manifest:invalid" gv
mkrepo nested
check_out "live gates-only accepts a nested scorecard that skips primary-scope gates" GATES_OK gv
check_out "live full mode rejects the same nested scorecard" "NOT_DONE
gate:G-hard
scorecard:context" fv
mkrepo primary_oos
check_out "live gates-only: an out_of_scope primary gate in a primary run is a failure" "GATES_FAIL gate:G-hard" gv
mkrepo ok
check "live: HEAD moving after the scorecard makes it stale" sh -c 'cd "$0/repo" && echo y > f.txt && git add f.txt && git -c core.hooksPath=/dev/null -c user.name=e -c user.email=e@example.com commit -q -m two && "$1" --gates-only --manifest gates/manifest-P0.json --scorecard .gates/run1/scorecard.json 2>/dev/null | /usr/bin/grep -q "^GATES_FAIL .*scorecard:head"' "$T" "$V"
finish
