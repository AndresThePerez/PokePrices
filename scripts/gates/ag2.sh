#!/bin/sh
# AG2: no unexplained ignores, escape hatches or masking recipes (F-P0-028, F-P0-087, R11).
# Scans the repository it is run from; a finder error counts as one hit, never as clean.
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
lib=$(cd "$(dirname "$0")/../lib" && pwd)
. "$lib/gate-result.sh"
case $GATES_OUT in /*) ;; *) GATES_OUT=$(pwd)/$GATES_OUT ;; esac
details="$GATES_OUT/AG2.details.jsonl"; : > "$details"
fields="$GATES_OUT/AG2.manifest-fields.txt"; : > "$fields"
hit() { # RULE TEXT
  jq -nc --arg r "$1" --arg h "$2" '{rule: $r, hit: $h}' >> "$details"
}
record() { # RULE NOHITS_OUTPUT NOHITS_RC
  case $3 in
    0) ;;
    1) printf '%s\n' "$2" | sed '$d' | jq -Rc --arg r "$1" '{rule: $r, hit: .}' >> "$details" ;;
    *) jq -nc --arg r "$1" --arg h "$2" '{rule: $r, reason: "finder_error", hit: $h}' >> "$details" ;;
  esac
}
scan() { # RULE FINDER ARGS... (through scripts/lib/nohits)
  r=$1; shift
  out=$("$lib/nohits" "$@"); rc=$?
  record "$r" "$out" "$rc"
}
top=$(git rev-parse --show-toplevel 2>/dev/null) || { hit not_a_git_repo "$(pwd)"; top=$(pwd); }
cd "$top" || exit 1
GREP="/usr/bin/grep -rnHP --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=dist --exclude-dir=.git --exclude-dir=.gates"
existing() { # print the arguments that exist
  for p in "$@"; do [ -e "$p" ] && printf '%s\n' "$p"; done
}
# Paths excluded from the tree-wide text rules (they name the forbidden constructs on purpose).
EXCL=":(exclude)gates/manifest-P0.json :(exclude)gates/manifest-P0.md :(exclude)scripts/gates/ag2.sh :(exclude)scripts/gates/ag-values.py :(exclude,top)tests/ :(exclude)gates/fixtures/"
tracked_scan() { # RULE PATTERN PATHSPEC... (git grep over tracked and untracked, non-ignored files)
  r=$1; p=$2; shift 2
  # shellcheck disable=SC2086
  scan "$r" git grep --untracked -nP -e "$p" -- "$@" $EXCL
}

# PHP ignores: @phpstan-ignore needs an identifier and a reason citing R<n> or F-P<n>-<nnn>.
php_ignore='@phpstan-ignore(?!\s+[A-Za-z][\w.]*(?:\s*,\s*[A-Za-z][\w.]*)*\s+\((?=[^)]*\b(?:R[0-9]+|F-P[0-9]+-[0-9]{3})\b)[^)]{8,}\))'
php_dirs=$(existing api/app api/routes api/database api/config api/bootstrap api/tests)
if [ -n "$php_dirs" ]; then
  # shellcheck disable=SC2086
  scan php_ignore $GREP -e "$php_ignore" $php_dirs
else
  hit php_ignore_scope "finder_error: no PHP scan directory"
fi

# TS escapes in web/src (required) and the web/*.ts, web/*.js configs.
ts_escape='eslint-disable|@ts-ignore|@ts-nocheck|@ts-expect-error(?!:?\s.*\b(?:R[0-9]+|F-P[0-9]+-[0-9]{3})\b)|\bas\s+any\b'
ts_cfg=$(existing web/*.ts web/*.js)
# shellcheck disable=SC2086
scan ts_escape $GREP -e "$ts_escape" web/src $ts_cfg

# Coverage and formatter escapes.
cov_escape='@codeCoverageIgnore|#\[CodeCoverageIgnore|\b(?:v8|istanbul|c8) ignore\b|prettier-ignore'
cov_dirs=$(existing api/app api/routes api/tests web/src)
if [ -n "$cov_dirs" ]; then
  # shellcheck disable=SC2086
  scan coverage_escape $GREP -e "$cov_escape" $cov_dirs
else
  hit coverage_escape_scope "finder_error: no coverage scan directory"
fi

# Ignore and allow files anywhere in the working tree.
ign=$(find . \( -name .git -o -name .gates -o -name vendor -o -name node_modules \) -prune -o \
  \( -name .gitleaks.toml -o -name .gitleaksignore -o -name .trivyignore -o -name .eslintignore -o -name .agent-precommit-allow \) -print) \
  || hit ignore_file "finder_error: find failed"
[ -z "$ign" ] || printf '%s\n' "$ign" | jq -Rc '{rule: "ignore_file", hit: .}' >> "$details"

# The manifest's executable fields, extracted with jq (never the raw prose).
if [ -e gates/manifest-P0.json ]; then
  jq -r '(.gates[] | .command, .banner_cmd, .value_parser, .denominator_source), (.exit_criteria[]? | .check)' gates/manifest-P0.json > "$fields" \
    || hit manifest_fields "finder_error: jq could not read gates/manifest-P0.json"
fi
fields_scan() { # RULE PATTERN
  [ -s "$fields" ] || return 0
  scan "manifest_$1" /usr/bin/grep -nHP -e "$2" "$fields"
}

# Tree-wide: the allow marker and passWithNoTests (unless set to false).
marker='agent-precommit:allow'
pwnt='passWithNoTests(?![\x27"]?\s*[:=]\s*false\b)'
tracked_scan allow_marker "$marker" .
fields_scan allow_marker "$marker"
tracked_scan pass_with_no_tests "$pwnt" .
fields_scan pass_with_no_tests "$pwnt"

# composer audit ignores.
if [ -e api/composer.json ]; then
  jq -e '((.config.audit.ignore // []) | length) == 0' api/composer.json >/dev/null 2>&1 \
    || hit composer_audit_ignore api/composer.json
fi

# A tracked .env.
envs=$(git ls-files | /usr/bin/grep -E '(^|/)\.env$')
[ -z "$envs" ] || printf '%s\n' "$envs" | jq -Rc '{rule: "tracked_env", hit: .}' >> "$details"

# Bare npx and docker compose exec without -T: Makefile, scripts/**, workflows, manifest fields.
npx='\bnpx\b'
exec_t='(?:compose|\$DC|\$\{DC\}|\$\(DC\))\s+(?:-f\s+\S+\s+)?exec\b(?![^\n]*\s-T\b)'
tracked_scan bare_npx "$npx" Makefile scripts .github/workflows
fields_scan bare_npx "$npx"
tracked_scan exec_without_T "$exec_t" Makefile scripts .github/workflows
fields_scan exec_without_T "$exec_t"

# Recipe lint (R11, R91): Makefile, scripts/** and the manifest's executable fields.
recipe_scan() { # RULE PATTERN PATHSPEC...
  r=$1; p=$2; shift 2
  scan "$r" git grep --untracked -nP -e "$p" -- "$@" ':(exclude)scripts/gates/ag2.sh'
}
recipe_scan recipe_dash_prefix '^\t@?-' Makefile
recipe_scan recipe_ignore_target '^\.IGNORE' Makefile
for pair in \
  'or_true|\|\|\s*true\b' \
  'or_colon|\|\|\s*:(?:\s|$)' \
  'semicolon_true|;\s*true\b' \
  'exit_zero|\bexit\s+0\b' \
  'exit_call_zero|\bexit\(0\)' \
  'make_ignore_or_keep_going|\bmake\s+(?:-\S*[ik]|--ignore-errors|--keep-going)' \
  'down_volumes|\bdown\b[^\n]*(?:\s-v\b|--volumes)'; do
  recipe_scan "recipe_${pair%%|*}" "${pair#*|}" Makefile scripts
  fields_scan "recipe_${pair%%|*}" "${pair#*|}"
done

# Denominator: tracked files in the PHP, TS and recipe scopes.
# shellcheck disable=SC2086
denominator=$(git ls-files -- $php_dirs web/src ':(glob)web/*.ts' ':(glob)web/*.js' Makefile scripts .github/workflows | sort -u | wc -l)
value=$(jq -s 'length' "$details")
gate_result AG2 "$value" "$denominator" "$details"; gr=$?
printf 'AG2: %s hits across %s files scanned\n' "$value" "$denominator"
[ "$gr" -eq 0 ] && [ "$value" -eq 0 ]
