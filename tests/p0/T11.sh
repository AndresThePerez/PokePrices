#!/bin/sh
# P0-i1-T11: Tailwind v4 compiled by the Vite plugin into the production build.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
NODE="docker compose -f compose.dev.yml exec -T -w /workspace/web node"
check_out "tailwindcss pinned" 4.3.3 jq -r .devDependencies.tailwindcss web/package.json
check_out "@tailwindcss/vite pinned" 4.3.3 jq -r '.devDependencies["@tailwindcss/vite"]' web/package.json
check "vite config loads the Tailwind plugin" /usr/bin/grep -q "@tailwindcss/vite" web/vite.config.ts
check_out "index.css imports Tailwind first (single quotes since T12 Prettier)" "@import 'tailwindcss';" head -1 web/src/index.css
check "production build succeeds" $NODE npm run build
check "a used utility class is in the built CSS" sh -c 'cat web/dist/assets/*.css | /usr/bin/grep -q "font-semibold"'
check "an unused utility class is not in the built CSS" sh -c '! cat web/dist/assets/*.css | /usr/bin/grep -q "skew-x-12"'
finish
