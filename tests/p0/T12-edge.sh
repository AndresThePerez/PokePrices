#!/bin/sh
# P0-i1-T12 edge cases (reviewer): the Prettier gate really lists misformatted files, honours the
# ignore file (and only the plan's four entries), config and pins are exact, the reformat was
# formatting-only, and a plain coverage run stays git-ignored.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
NODE="docker compose -f compose.dev.yml exec -T -w /workspace/web node"
W=web
BASE=7908ea4
T12=421d9f4
$DC up -d --wait node >/dev/null 2>&1
mkdir -p .gates/tmp
PRETTIER="./node_modules/.bin/prettier --config .prettierrc.json --no-editorconfig --ignore-path .prettierignore --list-different ."
count() { awk 'NF{n++} END{print n+0}'; }

# --- 1. the gate is real ---------------------------------------------------------------------------
check_out "clean tree: manifest Q8 command lists nothing (count 0)" 0 sh -c "$NODE $PRETTIER | awk 'NF{n++} END{print n+0}'"
BAD=$W/src/zz_edge_bad.ts
printf 'const   a = "x"\nexport  {a}\n' > $BAD
check_out "misformatted web/src file is listed and the awk count becomes 1" "1 src/zz_edge_bad.ts" \
  sh -c "out=\$($NODE $PRETTIER); printf '%s %s' \"\$(printf '%s\n' \"\$out\" | awk 'NF{n++} END{print n+0}')\" \"\$out\""
check "manifest command exits non-zero while the file is misformatted" sh -c "! $NODE $PRETTIER"
check "format:check script also fails on it" sh -c "! $NODE npm run format:check"
rm -f $BAD
check_out "after cleanup the count returns to 0" 0 sh -c "$NODE $PRETTIER | awk 'NF{n++} END{print n+0}'"

# --- 2. ignore file honoured, and exactly the plan's four entries -----------------------------------
D_PRE=0; [ -d $W/dist ] && D_PRE=1
C_PRE=0; [ -d $W/coverage ] && C_PRE=1
mkdir -p $W/dist $W/coverage
printf 'const   a = "x"\n' > $W/dist/zz_edge.ts
printf 'const   a = "x"\n' > $W/coverage/zz_edge.ts
check_out "misformatted files under web/dist and web/coverage are not listed" 0 \
  sh -c "$NODE $PRETTIER | awk 'NF{n++} END{print n+0}'"
rm -f $W/dist/zz_edge.ts $W/coverage/zz_edge.ts
[ "$D_PRE" -eq 0 ] && rmdir $W/dist
[ "$C_PRE" -eq 0 ] && rmdir $W/coverage
# T23 appends the generated web/src/api/schema.d.ts (C6 compares it byte-for-byte with a fresh openapi-typescript run); a later task that ignores more edits this list.
check_out ".prettierignore is exactly dist, coverage, node_modules, package-lock.json and the T23 generated src/api/schema.d.ts" "dist coverage node_modules package-lock.json src/api/schema.d.ts" \
  sh -c "tr '\n' ' ' < $W/.prettierignore | sed 's/ \$//'"
check "ignore file has no src or tsx pattern besides the exact generated src/api/schema.d.ts line (cannot hide sources)" sh -c "! /usr/bin/grep -vx 'src/api/schema.d.ts' $W/.prettierignore | /usr/bin/grep -Eq 'src|tsx|ts\$|\*'"
check "prettier is not told to skip via a package.json prettier key or .editorconfig override" sh -c "! /usr/bin/grep -q '\"prettier\": *{' $W/package.json && [ ! -e $W/.editorconfig ]"
check "no competing prettier config files besides .prettierrc.json" sh -c "[ -z \"\$(ls -A $W | /usr/bin/grep -E '^(\.prettierrc\.(js|cjs|mjs|yaml|yml|toml)|prettier\.config\..*|\.prettierrc)\$')\" ]"

# --- 3. config and pins exact ------------------------------------------------------------------------
check_out ".prettierrc.json is exactly the plan's four keys and values" "True" python3 -I -c "
import json;d=json.load(open('$W/.prettierrc.json'))
print(d=={'singleQuote':True,'semi':True,'trailingComma':'all','printWidth':100})"
check_out "format:check script string is exact" "prettier --config .prettierrc.json --ignore-path .prettierignore --check ." \
  python3 -I -c "import json;print(json.load(open('$W/package.json'))['scripts']['format:check'])"
check_out "package.json pins prettier 3.9.10 and typescript 6.0.3 exactly" "3.9.10 6.0.3" python3 -I -c "
import json;d=json.load(open('$W/package.json'))['devDependencies'];print(d['prettier'],d['typescript'])"
check_out "lockfile resolves prettier 3.9.10 and typescript 6.0.3" "3.9.10 6.0.3" python3 -I -c "
import json;p=json.load(open('$W/package-lock.json'))['packages'];print(p['node_modules/prettier']['version'],p['node_modules/typescript']['version'])"
check "prettier --version banner matches ^3\.9\." sh -c "$NODE ./node_modules/.bin/prettier --version | /usr/bin/grep -Eq '^3\.9\.'"
check_out "tsc --version is 6.0.3" "Version 6.0.3" sh -c "$NODE ./node_modules/.bin/tsc --version | tr -d '\r'"

# --- 4. formatting-only: tokens identical once quotes, semicolons, commas and whitespace go ------------
norm() { tr -d ' \t\r\n;,' | tr '"' "'"; }
for f in src/App.tsx src/App.test.tsx src/main.tsx src/index.css; do
  a=$(git show $BASE:$W/$f | norm); b=$(git show $T12:$W/$f | norm)
  check "$f differs from base only by whitespace, semicolons, commas and quote style" [ "$a" = "$b" ]
done
check "App.tsx rendered paragraph text is identical (JSX collapses the wrap to one space)" python3 -I -c "
import subprocess,re,sys
def t(rev):
    s=subprocess.check_output(['git','show',rev+':$W/src/App.tsx'],text=True)
    m=re.search(r'<p [^>]*>(.*?)</p>',s,re.S);return ' '.join(m.group(1).split())
sys.exit(0 if t('$BASE')==t('$T12') else 1)"
check "tsconfig.json parses to the same object as at base" python3 -I -c "
import subprocess,json,sys
g=lambda r:json.loads(subprocess.check_output(['git','show',r+':$W/tsconfig.json'],text=True))
sys.exit(0 if g('$BASE')==g('$T12') else 1)"
check "index.css still imports Tailwind on line 1" sh -c "[ \"\$(sed -n 1p $W/src/index.css)\" = \"@import 'tailwindcss';\" ]"
check "tsc -b, ESLint and Vitest pass on the formatted tree" sh -c "$NODE ./node_modules/.bin/tsc -b && $NODE ./node_modules/.bin/eslint -c eslint.config.js --max-warnings=0 . && $NODE ./node_modules/.bin/vitest run --config vitest.config.ts"

# --- 5. ignore hygiene ----------------------------------------------------------------------------------
check "web/.gitignore has anchored /dist and /coverage lines" sh -c "/usr/bin/grep -qx '/dist' $W/.gitignore && /usr/bin/grep -qx '/coverage' $W/.gitignore"
check "web/coverage/x and web/dist/x are git-ignored" sh -c "git check-ignore -q $W/coverage/x && git check-ignore -q $W/dist/x"
check "a nested coverage dir is NOT swallowed by the anchored rule (src/coverage/x is tracked-eligible)" sh -c "! git check-ignore -q $W/src/coverage/x"
check "the commit touched only web/ plus the three accepted test scripts" sh -c "git diff --name-only $BASE $T12 -- . ':!tests/p0/T12-edge.sh' | /usr/bin/grep -Ev '^(web/|tests/p0/(T05-edge|T11|T10-edge)\.sh\$)' | wc -l | /usr/bin/grep -qx 0"
check "tree is clean after the edge run" sh -c "[ -z \"\$(git status --porcelain --untracked-files=all -- . ':!tests/p0/T12-edge.sh')\" ]"
finish
