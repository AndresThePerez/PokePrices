#!/bin/sh
# gates/phase-base as tests/p0/run.sh reads it (Q1-i6 T05): the value comes from the file, a caller-set value
# wins (an empty one stays empty), and a second non-comment line is refused with exit 2 and a one-line message.
# It runs the real preamble of run.sh (everything before the TIER line) against a copy under .gates/tmp/. It is
# not in the FAST list of run.sh, so the fast tier is unchanged.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
T=.gates/tmp/phase-base-edge
mkdir -p "$T/gates" "$T/tests/p0"
sed '/^TIER=\${TIER:-full}$/,$d' tests/p0/run.sh > "$T/tests/p0/run.sh"
printf 'printf "PHASE_BASE=[%%s]\\n" "${PHASE_BASE-unset}"\n' >> "$T/tests/p0/run.sh"
RUN="$T/tests/p0/run.sh"
BASE="$T/gates/phase-base"

# value: what run.sh's preamble prints as PHASE_BASE, with the caller's own value removed.
value() { env -u PHASE_BASE "$@" /bin/sh "$RUN" 2>/dev/null; }
code() { env -u PHASE_BASE "$@" /bin/sh "$RUN" >/dev/null 2>&1; echo $?; }
stderr_lines() { env -u PHASE_BASE /bin/sh "$RUN" 2>&1 >/dev/null | wc -l | tr -d '[:space:]'; }

printf '# a comment\n\n  # an indented comment\nabc1234\n\n' > "$BASE"
check_out "the value comes from the first non-comment line of the file" "PHASE_BASE=[abc1234]" value
printf 'abc1234\n# later\n\n' > "$BASE"
check_out "a trailing blank or comment line after it is still one value" "PHASE_BASE=[abc1234]" value
printf 'abc1234' > "$BASE"
check_out "a value without a trailing newline is read" "PHASE_BASE=[abc1234]" value
printf '  abc1234  \n' > "$BASE"
check_out "whitespace around the value is dropped" "PHASE_BASE=[abc1234]" value
printf 'abc1234\n' > "$BASE"
check_out "a caller-set value wins over the file" "PHASE_BASE=[fff9999]" env PHASE_BASE=fff9999 /bin/sh "$RUN"
check_out "an empty caller value stays empty (a scan from the root)" "PHASE_BASE=[]" env PHASE_BASE= /bin/sh "$RUN"
printf '# only a comment\n' > "$BASE"
check_out "a file with no value exports an empty one" "PHASE_BASE=[]" value
printf 'abc1234\ndef5678\n' > "$BASE"
check_out "two non-comment lines are refused with exit 2" 2 code
check_out "the refusal is one line on stderr" 1 stderr_lines
check_out "nothing is exported after the refusal" "" value
check_out "a caller-set value skips the file, so two lines do not matter then" "PHASE_BASE=[fff9999]" env PHASE_BASE=fff9999 /bin/sh "$RUN"
printf 'abc1234\n# def5678\n\n' > "$BASE"
check_out "a second line that is a comment is not a second value" "PHASE_BASE=[abc1234]" value
rm -f "$BASE"
check_out "no file leaves PHASE_BASE unset" "PHASE_BASE=[unset]" value
finish
