#!/bin/sh
# D4: the README Development block and CI agree. Every job of every tracked workflow has exactly two
# steps, `uses: actions/checkout@<40 hex>` then `run: make gates`; README.md has exactly one
# '## Development' heading whose first fenced block holds a `make gates` line.
# value = mismatches; denominator = workflow run lines plus README commands found.
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
lib=$(cd "$(dirname "$0")/../lib" && pwd)
. "$lib/gate-result.sh"
case $GATES_OUT in /*) ;; *) GATES_OUT=$(pwd)/$GATES_OUT ;; esac
details="$GATES_OUT/D4.details.jsonl"; : > "$details"
top=$(git rev-parse --show-toplevel 2>/dev/null) || {
  jq -nc --arg h "$(pwd)" '{rule: "repo", reason: "not_a_git_repo", hit: $h}' >> "$details"; top=$(pwd); }
cd "$top" || exit 1
git ls-files '.github/workflows/*.yml' '.github/workflows/*.yaml' > "$GATES_OUT/D4.workflows.txt"
counts=$(python3 -I - "$details" "$GATES_OUT/D4.workflows.txt" README.md <<'PY'
import json, re, sys

details_path, list_path, readme_path = sys.argv[1:4]
out = []
run_lines = 0
readme_commands = 0
CHECKOUT = re.compile(r"actions/checkout@[0-9a-f]{40}")


def miss(**kw):
    out.append(kw)


try:
    import yaml
except Exception as exc:  # no PyYAML means the CI side cannot be judged at all
    yaml = None
    miss(rule="workflow", reason="pyyaml_unavailable", error=str(exc))

with open(list_path, encoding="utf-8") as fh:
    workflows = [line.rstrip("\n") for line in fh if line.strip()]
if not workflows:
    miss(rule="workflow", reason="no tracked .github/workflows/*.yml")

for path in workflows:
    if yaml is None:
        break
    try:
        with open(path, encoding="utf-8") as fh:
            doc = yaml.safe_load(fh)
    except Exception as exc:
        miss(rule="workflow", file=path, reason="unparsable", error=str(exc))
        continue
    jobs = doc.get("jobs") if isinstance(doc, dict) else None
    if not isinstance(jobs, dict) or not jobs:
        miss(rule="workflow", file=path, reason="no jobs")
        continue
    for name, job in jobs.items():
        steps = job.get("steps") if isinstance(job, dict) else None
        if not isinstance(steps, list):
            miss(rule="job_steps", file=path, job=str(name), reason="no steps list")
            continue
        run_lines += sum(1 for s in steps if isinstance(s, dict) and "run" in s)
        ok = (
            len(steps) == 2
            and isinstance(steps[0], dict)
            and set(steps[0]) <= {"uses", "with"}
            and isinstance(steps[0].get("uses"), str)
            and CHECKOUT.fullmatch(steps[0]["uses"]) is not None
            and steps[1] == {"run": "make gates"}
        )
        if not ok:
            miss(rule="job_steps", file=path, job=str(name),
                 reason="steps must be exactly checkout@<40 hex> then run: make gates",
                 steps=json.loads(json.dumps(steps, default=str)))

try:
    with open(readme_path, encoding="utf-8") as fh:
        lines = fh.read().splitlines()
except OSError as exc:
    lines = None
    miss(rule="readme", reason="README.md unreadable", error=str(exc))

if lines is not None:
    heads = [i for i, line in enumerate(lines) if line == "## Development"]
    if len(heads) != 1:
        miss(rule="readme", reason="want exactly one '## Development' heading", found=len(heads))
    if heads:
        block = None
        i = heads[0] + 1
        while i < len(lines) and not re.match(r"#{1,2} ", lines[i]):
            if lines[i].startswith("```"):
                end = i + 1
                while end < len(lines) and not lines[end].startswith("```"):
                    end += 1
                if end < len(lines):
                    block = lines[i + 1:end]
                break
            i += 1
        if block is None:
            miss(rule="readme", reason="no closed fenced block in the Development section")
        else:
            commands = [l.split("#", 1)[0].strip() for l in block]
            commands = [c for c in commands if c]
            readme_commands = len(commands)
            if "make gates" not in commands:
                miss(rule="readme", reason="Development block has no 'make gates' line")

with open(details_path, "a", encoding="utf-8") as fh:
    for d in out:
        fh.write(json.dumps(d, ensure_ascii=False) + "\n")
print(run_lines + readme_commands)
PY
); prc=$?
rm -f "$GATES_OUT/D4.workflows.txt"
if [ "$prc" -ne 0 ]; then
  jq -nc --argjson rc "$prc" '{rule: "checker", reason: "python checker failed", rc: $rc}' >> "$details"
  counts=0
fi
value=$(jq -s 'length' "$details")
gate_result D4 "$value" "${counts:-0}" "$details"; gr=$?
printf 'D4: %s mismatches across %s workflow run lines and README commands\n' "$value" "${counts:-0}"
[ "$gr" -eq 0 ] && [ "$value" -eq 0 ]
