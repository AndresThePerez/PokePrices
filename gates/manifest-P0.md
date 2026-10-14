# PokéPrices phase manifest P0 (markdown twin of manifest-P0.json)

This file renders exactly the fields of `manifest-P0.json`; the JSON is the machine copy. Both are committed byte-identical under `gates/` (R9, R28).

- schema: `pokeprices-manifest/v1`
- phase: `P0` (written at `P0-i1` Stage B)
- product: PokéPrices
- repos: `["pokeprices"]`
- phase_base: the root commit: git rev-list --max-parents=0 HEAD; later phases: the commit Andres approved at the previous phase review, passed by the orchestrator
- counts: 34 hard, 5 soft, 39 total

## Floor

- hard (34): Q1, Q3, Q4, Q6, Q7, Q8, T1, T2, T8, T11, C1, C2, C3, C6, S1, S2, S4, S5, S6, O1, O2, O5, AG1, AG2, AG3, X1, EC-routing, EC-ci-parity, EC-mex, HY-hygiene, HY-trailers, VER-selftest, DEN, AG-values
- soft (5): Q2, Q5, S7, D4, S10
- source: rulings R17, R20, R10
- note: R17 names 33 hard ids; R20 adds AG-values, so the hard floor is 34. Excluding a floor gate needs a ledger ruling with Andres's initials; verdict fixture floor_gate_missing enforces it.

## Run contract

- cwd: repository root
- shell: each command runs as: { bash -o pipefail -c "$COMMAND"; echo "EXIT $?"; } >>"$LOG" 2>&1, with set +e around it and a per-gate timeout
- preconditions: make init has run and docker compose -f compose.dev.yml up -d --wait reports every service healthy; scripts/scorecard refuses to start on a dirty tree (git status --porcelain --untracked-files=all non-empty)
- re_run: the orchestrator's random re-run executes the gate's command exactly as written here, never through make (R19)
- scope: scope 'primary' gates run only when PP_RUN_CONTEXT=primary; in nested or ci contexts the scorecard records status out_of_scope with that reason, the verdict never accepts a scorecard whose run_context is not primary, and make gates in those contexts ignores only out_of_scope records
- env:
  - `GATES_OUT`: repo-relative directory that already exists (scorecard: .gates/<head12>-<run_id>; a manual or orchestrator re-run: any new directory under .gates/)
  - `NO_COLOR`: 1
  - `CI`: 1
  - `COMPOSE_ANSI`: never
  - `COMPOSE_PROJECT_NAME`: default pokeprices; ppclean in the EC-ci-parity nested run
  - `PP_HTTP_PORT`: default 8095; 18095 in the EC-ci-parity nested run
  - `PP_RUN_CONTEXT`: primary (default), nested (EC-ci-parity child), ci (detected when GITHUB_ACTIONS=true)

## Status, zero-denominator, soft and jitter rules

- status_rule: pass only when exit == 0 AND value_parser prints one number that satisfies op threshold (and every secondary) AND the denominator_source prints one number >= denominator_min and > 0; an unparsable value or denominator is fail; a gate that could not start or timed out is not_run; not_run counts as fail (R18).
- zero_denominator_rule: A gate with nothing to measure fails. Every gate declares denominator_source and denominator_min >= 1; a missing, null, non-numeric or below-minimum denominator makes the gate fail, never pass and never skip. A manifest entry with denominator_min < 1 is itself a verdict failure. DEN re-checks every record of the run.
- soft_rule.ratio: soft_pass / soft_total >= 0.80; with 5 soft gates at least 4 must pass; soft_total == 0 is allowed only when this list is explicitly empty
- soft_rule.regression: no soft gate value may be worse than the baseline scorecard's value by more than its tolerance
- jitter.source: deterministic
- jitter.rule: every P0 soft gate is a deterministic count: tolerance 0 until three Stage D calibration runs exist at one HEAD; until then tolerance stays 0
- jitter.calibration_record: gates/jitter-P0.json (frozen; created at the freeze task with tolerance 0 for Q2, Q5, S7, D4, S10 and calibration_runs []; any later tolerance change needs a new Andres-approved hash)
- jitter.baseline: the previous accepted scorecard of this phase, passed with --baseline; the phase's first scorecard records baseline: none and its regression check is reported as not applicable

## Gates (in execution order)

### Q1: Larastan level 8, 0 errors, no baseline

- hard: true; scope: any; runs_in: api; timeout_s: 900; built_by: P0-i1-T08
- command: `docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G --no-progress --no-interaction --error-format=json > "$GATES_OUT/Q1.phpstan.json" && test ! -e api/phpstan-baseline.neon`
- banner_cmd: `docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/phpstan --version`; banner_re: `^PHPStan`
- artifact: `$GATES_OUT/Q1.phpstan.json`
- value_parser: `jq -e '.totals.errors + .totals.file_errors' "$GATES_OUT/Q1.phpstan.json"`
- op / threshold: `==` `0`
- denominator_source: `git ls-files 'api/app/*.php' 'api/routes/*.php' 'api/database/*.php' 'api/bootstrap/*.php' | wc -l`; denominator_min: 1
- why: Nullability bugs are the class this API cannot afford; level 8 is the R11 floor, raised only by Andres.

### Q3: Pint formatting clean

- hard: true; scope: any; runs_in: api; timeout_s: 900; built_by: P0-i1-T08
- command: `docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/pint --test --config pint.json --output-to-file="/workspace/$GATES_OUT/Q3.pint.json" --output-format=json`
- banner_cmd: `docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/pint --version`; banner_re: `Pint`
- artifact: `$GATES_OUT/Q3.pint.json`
- value_parser: `jq -e '.files | length' "$GATES_OUT/Q3.pint.json"`
- op / threshold: `==` `0`
- denominator_source: `git ls-files 'api/*.php' | wc -l`; denominator_min: 1
- why: One formatter, explicit config path (F-P0-076), zero changed files.

### Q4: Pest type coverage 100%

- hard: true; scope: any; runs_in: api; timeout_s: 900; built_by: P0-i1-T08
- command: `docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/pest -c phpunit.xml --type-coverage --min=100 --type-coverage-json="/workspace/$GATES_OUT/Q4.type.json"`
- banner_cmd: `docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/pest --version`; banner_re: `Pest`
- artifact: `$GATES_OUT/Q4.type.json`
- value_parser: `jq -e '.total' "$GATES_OUT/Q4.type.json"`
- op / threshold: `>=` `100`
- denominator_source: `jq -e '.result | length' "$GATES_OUT/Q4.type.json"`; denominator_min: 1
- why: Greenfield code is cheap to keep fully typed; R17 keeps it hard (see open question 6).

### Q6: TypeScript strict build, 0 errors

- hard: true; scope: any; runs_in: node; timeout_s: 900; built_by: P0-i1-T09
- command: `docker compose -f compose.dev.yml exec -T -w /workspace/web node ./node_modules/.bin/tsc -b --pretty false > "$GATES_OUT/Q6.tsc.txt"`
- banner_cmd: `docker compose -f compose.dev.yml exec -T -w /workspace/web node ./node_modules/.bin/tsc --version`; banner_re: `^Version 6\.0\.`
- artifact: `$GATES_OUT/Q6.tsc.txt`
- value_parser: `awk '/error TS[0-9]+/{n++} END{print n+0}' "$GATES_OUT/Q6.tsc.txt"`
- op / threshold: `==` `0`
- denominator_source: `docker compose -f compose.dev.yml exec -T -w /workspace/web node ./node_modules/.bin/tsc -p tsconfig.app.json --listFilesOnly | awk '/\/web\/src\//{n++} END{print n+0}'`; denominator_min: 1
- why: tsc -b, not tsc --noEmit: the Vite root tsconfig lists no files (F-P0-078).

### Q7: ESLint strictTypeChecked, 0 errors, 0 warnings

- hard: true; scope: any; runs_in: node; timeout_s: 900; built_by: P0-i1-T09
- command: `docker compose -f compose.dev.yml exec -T -w /workspace/web node ./node_modules/.bin/eslint -c eslint.config.js --max-warnings=0 -f json -o "/workspace/$GATES_OUT/Q7.eslint.json" .`
- banner_cmd: `docker compose -f compose.dev.yml exec -T -w /workspace/web node ./node_modules/.bin/eslint --version`; banner_re: `^v10\.`
- artifact: `$GATES_OUT/Q7.eslint.json`
- value_parser: `jq -e '[.[] | .errorCount + .warningCount + .fatalErrorCount] | add // 0' "$GATES_OUT/Q7.eslint.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e 'length' "$GATES_OUT/Q7.eslint.json"`; denominator_min: 1
- why: Research 11 house bar (R25); inline disables are off via linterOptions.noInlineConfig.

### Q8: Prettier clean

- hard: true; scope: any; runs_in: node; timeout_s: 900; built_by: P0-i1-T12
- command: `docker compose -f compose.dev.yml exec -T -w /workspace/web node ./node_modules/.bin/prettier --config .prettierrc.json --no-editorconfig --ignore-path .prettierignore --list-different . > "$GATES_OUT/Q8.prettier.txt"`
- banner_cmd: `docker compose -f compose.dev.yml exec -T -w /workspace/web node ./node_modules/.bin/prettier --version`; banner_re: `^3\.9\.`
- artifact: `$GATES_OUT/Q8.prettier.txt`
- value_parser: `awk 'NF{n++} END{print n+0}' "$GATES_OUT/Q8.prettier.txt"`
- op / threshold: `==` `0`
- denominator_source: `git ls-files 'web/src/*' | wc -l`; denominator_min: 1
- why: Deterministic formatting; explicit config and ignore paths.

### T1: Pest suite passes, parallel, random order, nothing skipped

- hard: true; scope: any; runs_in: api; timeout_s: 1200; built_by: P0-i1-T25
- command: `docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/pest -c phpunit.xml --parallel --processes=3 --order-by=random --log-junit="/workspace/$GATES_OUT/T1.junit.xml"`
- banner_cmd: `docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/pest --version`; banner_re: `Pest`
- artifact: `$GATES_OUT/T1.junit.xml`
- value_parser: `python3 -I scripts/lib/junit.py "$GATES_OUT/T1.junit.xml" --tests-dir api/tests --field bad`
- op / threshold: `==` `0`
- denominator_source: `python3 -I scripts/lib/junit.py "$GATES_OUT/T1.junit.xml" --field tests`; denominator_min: 1
- why: bad = failures + errors + skipped + test files on disk that never ran (F-P0-004).

### T2: Line coverage of app/ >= 85% on a real denominator

- hard: true; scope: any; runs_in: api; timeout_s: 1200; built_by: P0-i1-T25
- command: `docker compose -f compose.dev.yml exec -T -w /workspace/api api php -d pcov.enabled=1 vendor/bin/pest -c phpunit.xml --coverage --min=85 --coverage-clover="/workspace/$GATES_OUT/T2.clover.xml"`
- banner_cmd: `docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/pest --version`; banner_re: `Pest`
- artifact: `$GATES_OUT/T2.clover.xml`
- value_parser: `python3 -I scripts/lib/clover.py "$GATES_OUT/T2.clover.xml" --field percent`
- op / threshold: `>=` `85`
- denominator_source: `python3 -I scripts/lib/clover.py "$GATES_OUT/T2.clover.xml" --field statements`; denominator_min: 10
- why: 85% from Research 11 T2; at least 10 statements so the percentage means something (F-P0-080).

### T8: Vitest passes; coverage lines >= 80, branches >= 70, include src/**

- hard: true; scope: any; runs_in: node; timeout_s: 900; built_by: P0-i1-T10
- command: `docker compose -f compose.dev.yml exec -T -w /workspace/web node ./node_modules/.bin/vitest run --config vitest.config.ts --coverage --coverage.reportsDirectory="/workspace/$GATES_OUT/T8.coverage" --reporter=default --reporter=junit --outputFile.junit="/workspace/$GATES_OUT/T8.junit.xml"`
- banner_cmd: `docker compose -f compose.dev.yml exec -T -w /workspace/web node ./node_modules/.bin/vitest --version`; banner_re: `5\.0\.3`
- artifact: `$GATES_OUT/T8.coverage/coverage-summary.json`
- value_parser: `jq -e '.total.lines.pct' "$GATES_OUT/T8.coverage/coverage-summary.json"`
- op / threshold: `>=` `80`
- secondary branches: `jq -e '.total.branches.pct' "$GATES_OUT/T8.coverage/coverage-summary.json"` `>=` `70`
- denominator_source: `python3 -I scripts/lib/junit.py "$GATES_OUT/T8.junit.xml" --field tests`; denominator_min: 1
- why: Research 11 T8 thresholds; include frozen at src/** (A2 row 'Vitest lines').

### T11: No skipped, todo or focused tests

- hard: true; scope: any; runs_in: host; timeout_s: 900; built_by: P0-i1-T27
- command: `scripts/gates/t11.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/T11.json`
- value_parser: `jq -e '.value' "$GATES_OUT/T11.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/T11.json"   # test files scanned in api/tests and web/src`; denominator_min: 1
- spec: PHP: ->skip( ->todo( ->only() markTestSkipped markTestIncomplete in api/tests; TS: it|test|describe|suite .only|.skip|.todo|.fixme and xit|xtest|xdescribe|fit|fdescribe( in web/src. GNU grep via scripts/lib/nohits.
- why: A focused or skipped test turns a red suite green without fixing anything.

### C1: OpenAPI 3.1 export equals the committed openapi/api.json

- hard: true; scope: any; runs_in: api; timeout_s: 900; built_by: P0-i1-T20
- command: `scripts/gates/c1.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/C1.json`
- value_parser: `jq -e '.value' "$GATES_OUT/C1.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/C1.json"   # jq '.paths | length' openapi/api.json`; denominator_min: 1
- spec: Export with php artisan scramble:export to $GATES_OUT/C1.api.json; require git ls-files --error-unmatch openapi/api.json; value 0 when cmp is identical, 1 otherwise; also requires info.title == PokéPrices.
- why: The committed contract is what the code produces; compared with cmp against a fresh export, never git diff (F-P0-088).

### C2: Spectral lint, 0 errors and 0 warnings

- hard: true; scope: any; runs_in: node; timeout_s: 900; built_by: P0-i1-T21
- command: `docker compose -f compose.dev.yml exec -T -w /workspace/web node ./node_modules/.bin/spectral lint ../openapi/api.json --ruleset ../.spectral.yaml --fail-severity=warn -f json -o "/workspace/$GATES_OUT/C2.spectral.json"`
- banner_cmd: `docker compose -f compose.dev.yml exec -T -w /workspace/web node ./node_modules/.bin/spectral --version`; banner_re: `^6\.17\.0`
- artifact: `$GATES_OUT/C2.spectral.json`
- value_parser: `jq -e 'length' "$GATES_OUT/C2.spectral.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.paths | length' openapi/api.json`; denominator_min: 1
- why: Custom rules (typed 2xx, problem+json errors, operationId) make the contract say something (A2 C1 row); rule-count floor checked by AG-values.

### C3: Every feature-test response to /v1 validated against the spec

- hard: true; scope: any; runs_in: api; timeout_s: 900; built_by: P0-i1-T22
- command: `scripts/gates/c3.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/C3.json`
- value_parser: `jq -e '.value' "$GATES_OUT/C3.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/C3.json"   # validated /v1 responses in the ledger`; denominator_min: 1
- spec: Runs the Feature suite with PP_C3_LEDGER set; tests/TestCase.php validates every /v1 response with league/openapi-psr7-validator and appends one JSON line per response. value = unvalidated /v1 responses + JUnit bad.
- why: A response shape the spec does not describe fails the suite, so the spec cannot drift from behaviour.

### C6: web/src/api/schema.d.ts equals a fresh openapi-typescript generation

- hard: true; scope: any; runs_in: node; timeout_s: 900; built_by: P0-i1-T23
- command: `scripts/gates/c6.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/C6.json`
- value_parser: `jq -e '.value' "$GATES_OUT/C6.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/C6.json"   # paths in openapi/api.json`; denominator_min: 1
- spec: openapi-typescript ../openapi/api.json to $GATES_OUT/C6.schema.d.ts in the node service; require git ls-files --error-unmatch web/src/api/schema.d.ts; value 0 when cmp is identical.
- why: The SPA's types come from the committed spec; drift fails.

### S1: composer audit: 0 advisories (abandoned reported only)

- hard: true; scope: any; runs_in: api; timeout_s: 900; built_by: P0-i1-T08
- command: `docker compose -f compose.dev.yml exec -T -w /workspace/api api composer audit --locked --abandoned=report --format=json --no-interaction > "$GATES_OUT/S1.audit.json" && jq -e 'has("repositories") | not' api/composer.json > /dev/null`
- banner_cmd: `docker compose -f compose.dev.yml exec -T -w /workspace/api api composer --version`; banner_re: `^Composer version 2\.10\.3`
- artifact: `$GATES_OUT/S1.audit.json`
- value_parser: `jq -e '[.advisories[]?[]?] | length' "$GATES_OUT/S1.audit.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '(.packages | length) + (."packages-dev" | length)' api/composer.lock`; denominator_min: 1
- why: Composer 2.10.3 exits 0 or 1 only (F-P0-089); --locked so an empty vendor cannot skip the audit; --ignore-unreachable is never used.

### S2: npm audit: 0 high or critical in production dependencies

- hard: true; scope: any; runs_in: node; timeout_s: 900; built_by: P0-i1-T05
- command: `docker compose -f compose.dev.yml exec -T -w /workspace/web node npm audit --omit=dev --audit-level=high --json > "$GATES_OUT/S2.audit.json"`
- banner_cmd: `docker compose -f compose.dev.yml exec -T -w /workspace/web node npm --version`; banner_re: `^[0-9]+\.`
- artifact: `$GATES_OUT/S2.audit.json`
- value_parser: `jq -e '.metadata.vulnerabilities.high + .metadata.vulnerabilities.critical' "$GATES_OUT/S2.audit.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.packages | length' web/package-lock.json`; denominator_min: 2
- why: Research 11 S2.

### S4: gitleaks over full history: 0 findings, no allowlist

- hard: true; scope: any; runs_in: tools; timeout_s: 900; built_by: P0-i1-T24
- command: `docker compose -f compose.dev.yml exec -T -w /repo tools gitleaks git --redact --exit-code 1 --report-format json --report-path "/repo/$GATES_OUT/S4.gitleaks.json" /repo`
- banner_cmd: `docker compose -f compose.dev.yml exec -T tools gitleaks version`; banner_re: `8\.30\.1`
- artifact: `$GATES_OUT/S4.gitleaks.json`
- value_parser: `jq -e 'length' "$GATES_OUT/S4.gitleaks.json"`
- op / threshold: `==` `0`
- denominator_source: `git rev-list --count HEAD`; denominator_min: 1
- why: Mechanical no-secrets rule; no .gitleaks.toml or .gitleaksignore exists (AG2 enforces).

### S5: Runtime images run as a non-root user

- hard: true; scope: any; runs_in: host; timeout_s: 1800; built_by: P0-i1-T28
- command: `scripts/gates/s5.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/S5.json`
- value_parser: `jq -e '.value' "$GATES_OUT/S5.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/S5.json"   # images inspected (2)`; denominator_min: 2
- spec: docker build --target runtime of docker/api/Dockerfile and docker build of docker/web/Dockerfile, tagged ${COMPOSE_PROJECT_NAME:-pokeprices}-api:gate and -web:gate; .Config.User must not be empty, 0, root, 0:* or root:*; .dockerignore must exclude **/.env. value = failing images.
- why: Dev containers run as container root (host uid under rootless); the shipped runtime images must not (F-P0-084).

### S6: Every base image digest-pinned (Dockerfiles and compose image:)

- hard: true; scope: any; runs_in: host; timeout_s: 900; built_by: P0-i1-T27
- command: `scripts/gates/s6.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/S6.json`
- value_parser: `jq -e '.value' "$GATES_OUT/S6.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/S6.json"   # FROM lines plus compose image references`; denominator_min: 2
- spec: Every FROM in every tracked Dockerfile whose image is not an earlier stage alias or scratch ends in @sha256:<64 hex>; every compose service without build: has image ending @sha256:<64 hex> (docker compose config --format json). value = violations.
- why: A floating tag is an unpinned dependency (F-P0-023, F-P0-060).

### O2: Structured JSON log lines

- hard: true; scope: any; runs_in: api; timeout_s: 900; built_by: P0-i1-T17
- command: `docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/pest -c phpunit.xml --group=O2 --log-junit="/workspace/$GATES_OUT/O2.junit.xml"`
- banner_cmd: `docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/pest --version`; banner_re: `Pest`
- artifact: `$GATES_OUT/O2.junit.xml`
- value_parser: `python3 -I scripts/lib/junit.py "$GATES_OUT/O2.junit.xml" --field bad`
- op / threshold: `==` `0`
- denominator_source: `python3 -I scripts/lib/junit.py "$GATES_OUT/O2.junit.xml" --field tests`; denominator_min: 1
- why: Every app log line json_decodes with top-level ts, level, message, request_id, route, duration_ms.

### O5: Migrations round-trip on the gate database

- hard: true; scope: any; runs_in: api; timeout_s: 900; built_by: P0-i1-T28
- command: `scripts/gates/o5.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/O5.json`
- value_parser: `jq -e '.value' "$GATES_OUT/O5.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/O5.json"   # files in api/database/migrations`; denominator_min: 3
- spec: With DB_DATABASE=pokeprices_gate: migrate:fresh, migrate:reset, migrate (all --force --no-interaction), then migrate:status; value = Pending lines in migrate:status + non-zero step exits.
- why: Irreversible migrations block every later rollback; never run against the dev database (F-P0-061, F-P0-086).

### EC-routing: nginx on the published port routes /v1, /up, Fortify and Sanctum paths to Laravel and everything else to the SPA

- hard: true; scope: any; runs_in: host; timeout_s: 900; built_by: P0-i1-T19
- command: `scripts/gates/ec-routing.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/EC-routing.json`
- value_parser: `jq -e '.value' "$GATES_OUT/EC-routing.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/EC-routing.json"   # requests made`; denominator_min: 19
- spec: curl --resolve pokeprices.localhost:$PP_HTTP_PORT:127.0.0.1 with X-Request-Id gate-req-0001: /v1/ready 200 JSON; /v1/does-not-exist 404 problem+json; /up 200; GET /login /logout /register /forgot-password /reset-password /reset-password/x /email/verify /user/profile-information /user/two-factor-authentication /two-factor-challenge /sanctum/csrf-cookie and POST /login /two-factor-challenge are 404 application/problem+json; all of these carry X-PokePrices-Origin: api and echo X-Request-Id gate-req-0001; / /account/login /watchlist return 200 text/html containing id="root" without X-PokePrices-Origin. value = failed requests.
- why: A routing config that is declared but never exercised proves nothing (F-P0-014, R12, R24).

### O1: Healthchecks real; /up and /v1/ready answer from Laravel; ready is 503 within 2 s when redis-cache stops

- hard: true; scope: any; runs_in: host; timeout_s: 600; built_by: P0-i1-T28
- command: `scripts/gates/o1.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/O1.json`
- value_parser: `jq -e '.value' "$GATES_OUT/O1.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/O1.json"   # checks executed`; denominator_min: 7
- spec: (a) every compose service has a healthcheck whose test is not true, exit 0 or echo, and docker compose ps reports all healthy; (b) GET /up via 127.0.0.1:$PP_HTTP_PORT is 200 with X-PokePrices-Origin: api and X-Request-Id; (c) GET /v1/ready is 200 application/json with status ok and checks database, redis_queue, redis_cache all ok; (d) after docker compose stop redis-cache, GET /v1/ready with curl --max-time 2 is 503 application/problem+json with request_id; (e) redis-cache is started again and up -d --wait restores 200 (a trap restores it on any exit); (f) php artisan pokeprices:queue-probe --timeout=10 proves the worker consumed a job from redis-queue; (g) schedule:list names the probe job. value = failed checks.
- why: Readiness that always says 200 is decoration (A2 readiness row).

### X1: Memory caps match the server table and are enforced

- hard: true; scope: any; runs_in: host; timeout_s: 900; built_by: P0-i1-T28
- command: `scripts/gates/x1.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/X1.json`
- value_parser: `jq -e '.value' "$GATES_OUT/X1.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/X1.json"   # services checked`; denominator_min: 9
- spec: For each service: docker compose config mem_limit equals the table and docker inspect HostConfig.Memory of its running container equals it; postgres ShmSize 268435456; redis-queue maxmemory 50331648 noeviction; redis-cache maxmemory 100663296 allkeys-lru. Table (bytes): postgres 1073741824, redis-queue 67108864, redis-cache 134217728, api 402653184, worker 402653184, scheduler 134217728, web 67108864, tools 536870912, node 1073741824. value = mismatches.
- why: The dev stack mirrors the server's caps (System Design section 1); maxmemory makes the eviction policy real (F-P0-046, F-P0-068, F-P0-091).

### Q2: Larastan level 10 error count

- hard: false; scope: any; runs_in: api; timeout_s: 900; built_by: P0-i1-T08
- command: `docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/phpstan analyse -c phpstan.neon --level=10 --memory-limit=1G --no-progress --no-interaction --error-format=json > "$GATES_OUT/Q2.phpstan.json"`
- banner_cmd: `docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/phpstan --version`; banner_re: `^PHPStan`
- artifact: `$GATES_OUT/Q2.phpstan.json`
- value_parser: `jq -e '.totals.errors + .totals.file_errors' "$GATES_OUT/Q2.phpstan.json"`
- op / threshold: `==` `0`
- denominator_source: `git ls-files 'api/app/*.php' 'api/routes/*.php' 'api/database/*.php' 'api/bootstrap/*.php' | wc -l`; denominator_min: 1
- why: Trend toward level 10; promotion to 9 only when this reaches 0 (Research 11).

### Q5: Rector dry run: proposed changes

- hard: false; scope: any; runs_in: api; timeout_s: 900; built_by: P0-i1-T08
- command: `docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/rector process --dry-run --no-progress-bar --output-format=json --config rector.php > "$GATES_OUT/Q5.rector.json"`
- banner_cmd: `docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/rector --version`; banner_re: `Rector`
- artifact: `$GATES_OUT/Q5.rector.json`
- value_parser: `jq -e '.totals.changed_files' "$GATES_OUT/Q5.rector.json"`
- op / threshold: `==` `0`
- denominator_source: `git ls-files 'api/app/*.php' | wc -l`; denominator_min: 1
- why: Small pinned set: dead code plus the highest rector-laravel level set available for Laravel 13.

### S7: Third-party Actions pinned to a 40-hex SHA

- hard: false; scope: any; runs_in: host; timeout_s: 900; built_by: P0-i1-T33
- command: `scripts/gates/s7.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/S7.json`
- value_parser: `jq -e '.value' "$GATES_OUT/S7.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/S7.json"   # workflow files`; denominator_min: 1
- spec: uses: lines in .github/workflows/*.yml that are not ./local and not pinned to a 40-hex SHA (first-party actions/* are pinned too in this repo). value = unpinned uses.
- why: Research 11 S7.

### D4: README Development block and CI agree

- hard: false; scope: any; runs_in: host; timeout_s: 900; built_by: P0-i1-T33
- command: `scripts/gates/d4.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/D4.json`
- value_parser: `jq -e '.value' "$GATES_OUT/D4.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/D4.json"   # workflow run lines plus README commands`; denominator_min: 2
- spec: README.md has exactly one '## Development' heading whose fenced block contains 'make gates'; every workflow job's steps are exactly one uses: actions/checkout@<sha> and one run: make gates. value = mismatches.
- why: Research 11 D4 for a CI that is only make gates.

### S10: Trivy CRITICAL count on the runtime images

- hard: false; scope: any; runs_in: host; timeout_s: 1800; built_by: P0-i1-T24
- command: `scripts/gates/s10.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/S10.json`
- value_parser: `jq -e '.value' "$GATES_OUT/S10.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/S10.json"   # images scanned (2)`; denominator_min: 2
- spec: Builds the two :gate images (same as S5), docker save to $GATES_OUT, trivy image --input in the tools service with --severity CRITICAL, a named trivy-cache volume, no --ignore-unfixed, no .trivyignore; records the DB timestamp. value = CRITICAL vulnerabilities.
- why: Research 11 S10; soft until Andres rules (open question 1).

### AG-values: Frozen configs carry the Research 11 values

- hard: true; scope: any; runs_in: host; timeout_s: 900; built_by: P0-i1-T06
- command: `python3 -I scripts/gates/ag-values.py`
- banner_cmd: `python3 --version`; banner_re: `^Python 3\.`
- artifact: `$GATES_OUT/AG-values.json`
- value_parser: `jq -e '.value' "$GATES_OUT/AG-values.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/AG-values.json"   # checks evaluated`; denominator_min: 15
- spec: phpstan dump-parameters: level >= 8, ignoreErrors empty, no baseline include, reportUnmatchedIgnoredErrors true; tsc --showConfig of tsconfig.app.json and tsconfig.node.json: strict, noUncheckedIndexedAccess, exactOptionalPropertyTypes written true; eslint.config.js uses tseslint.configs.strictTypeChecked, linterOptions.noInlineConfig true, reportUnusedDisableDirectives error, and eslint --print-config shows @typescript-eslint/no-unnecessary-condition at error; vitest.config.ts coverage.include == ['src/**'], thresholds lines >= 80 and branches >= 70, passWithNoTests not true; phpunit.xml source include app, exclude subset of {app/Providers}, failOnEmptyTestSuite failOnRisky failOnWarning failOnSkipped failOnIncomplete beStrictAboutTestsThatDoNotTestAnything all true, DB_CONNECTION pgsql; pint.json preset laravel; .spectral.yaml extends spectral:oas with >= 5 custom rules. value = violations.
- why: The first freeze must not lock in scaffold defaults (F-P0-009, R20).

### AG1: Manifest approved; frozen configs unchanged or stricter since the freeze anchor; config discovery closed

- hard: true; scope: any; runs_in: host; timeout_s: 900; built_by: P0-i1-T30
- command: `python3 -I scripts/gates/ag1.py`
- banner_cmd: `python3 --version`; banner_re: `^Python 3\.`
- artifact: `$GATES_OUT/AG1.json`
- value_parser: `jq -e '.value' "$GATES_OUT/AG1.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/AG1.json"   # frozen entries evaluated`; denominator_min: 20
- spec: (i) gates/manifest-P0.approved.sha256 is tracked and equals sha256 of gates/manifest-P0.json; (ii) anchor = first commit adding gates/frozen.sha256; every entry of git show <anchor>:gates/frozen.sha256 is blob-equal at HEAD or judged stricter by the gates/ratchet.json comparator for its kind; (iii) git ls-files matches of the config globs equal the frozen list; (iv) no alternate config file exists in the working tree (phpstan.neon.dist, .phpstan.neon, phpunit.xml.dist, .eslintrc*, prettier.config.*, vitest.config.mts and the rest of the list in ratchet.json). value = violations; fails with reason manifest_unapproved or anchor_absent when (i) or (ii) cannot be evaluated.
- why: A hash-only freeze is dodged by moving or shadowing the config (F-P0-001, F-P0-007, F-P0-008, F-P0-057, F-P0-075, F-P0-076).

### AG2: No unexplained ignores, escape hatches or masking recipes

- hard: true; scope: any; runs_in: host; timeout_s: 900; built_by: P0-i1-T27
- command: `scripts/gates/ag2.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/AG2.json`
- value_parser: `jq -e '.value' "$GATES_OUT/AG2.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/AG2.json"   # files scanned`; denominator_min: 1
- spec: Hits on: @phpstan-ignore without identifier and (reason); eslint-disable; @ts-ignore; @ts-nocheck; @ts-expect-error without reason; as any; @codeCoverageIgnore; #[CodeCoverageIgnore]; v8|istanbul|c8 ignore; prettier-ignore; a reason must cite R<n> or F-P<n>-<nnn>; any of .gitleaks.toml .gitleaksignore .trivyignore .eslintignore .agent-precommit-allow or an agent-precommit:allow marker; composer.json config.audit.ignore non-empty; --passWithNoTests anywhere; a tracked .env; bare npx in Makefile, scripts or the manifest; docker compose exec without -T in Makefile or scripts; recipe lint on Makefile and scripts/**: a recipe line starting with -, .IGNORE, || true, || :, ; true, exit 0, make -i or -k, down -v or --volumes. value = hits.
- why: Ignores, inline disables and masked exit codes satisfy hard gates without touching a hashed file (A2 section 1, F-P0-028, F-P0-087).

### AG3: No assertion-free tests

- hard: true; scope: any; runs_in: host; timeout_s: 900; built_by: P0-i1-T27
- command: `python3 -I scripts/gates/ag3.py`
- banner_cmd: `python3 --version`; banner_re: `^Python 3\.`
- artifact: `$GATES_OUT/AG3.json`
- value_parser: `jq -e '.value' "$GATES_OUT/AG3.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/AG3.json"   # test files scanned`; denominator_min: 2
- spec: PHP: assertTrue(true), expect(true)->toBeTrue(), and any test/it closure or test method in api/tests with no expect(, ->assert, $this->assert or ->assertStatus call; TS: any it( or test( body in web/src with no expect(. value = hits.
- why: Coverage without assertions is the agent-test failure mode (Research 11 T4 note).

### HY-hygiene: No home path, server user, LAN or Tailscale address, or server port map in tracked files or history

- hard: true; scope: any; runs_in: host; timeout_s: 900; built_by: P0-i1-T29
- command: `scripts/gates/hy-hygiene.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/HY-hygiene.json`
- value_parser: `jq -e '.value' "$GATES_OUT/HY-hygiene.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/HY-hygiene.json"   # tracked files scanned plus commits scanned`; denominator_min: 2
- spec: PCRE patterns: the non-comment lines of the frozen gates/hygiene.patterns (generic: home and user paths, RFC 1918 and Tailscale ranges, the server's port range) plus, when present, the untracked local file .git/info/hygiene.local holding the machine-specific names (server user, host and network names, vault path) so the tracked file names none of them; the local file is required when PP_RUN_CONTEXT=primary and optional in nested and ci contexts. Patterns are listed in plan task P0-i1-T29, never repeated here, so this file cannot match itself. Scans git grep over HEAD and git log -p from the phase base (P0: the root commit), excluding gates/hygiene.patterns and lockfiles. value = hits.
- why: Public-repo hygiene standing rule; gitleaks finds secrets, not topology (F-P0-016, F-P0-069).

### HY-trailers: No commit trailers or Claude or Anthropic references since the phase base

- hard: true; scope: any; runs_in: host; timeout_s: 900; built_by: P0-i1-T29
- command: `scripts/gates/hy-trailers.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/HY-trailers.json`
- value_parser: `jq -e '.value' "$GATES_OUT/HY-trailers.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/HY-trailers.json"   # commits scanned`; denominator_min: 1
- spec: For every commit from the phase base (P0: root) to HEAD: git interpret-trailers --parse output is empty, no line matches (?i)^(co-authored-by|claude-session|signed-off-by|generated-by|assisted-by)\s*:, and no (?i)\b(claude|anthropic)\b anywhere in the message. value = offending commits.
- why: Standing rule: commit messages are exactly the subject and body the plan gives.

### VER-selftest: scripts/verdict gives the expected verdict on every fixture

- hard: true; scope: any; runs_in: host; timeout_s: 900; built_by: P0-i1-T26
- command: `scripts/verdict --selftest gates/fixtures/verdict --out "$GATES_OUT/VER-selftest.json"`
- banner_cmd: `python3 --version`; banner_re: `^Python 3\.`
- artifact: `$GATES_OUT/VER-selftest.json`
- value_parser: `jq -e '.value' "$GATES_OUT/VER-selftest.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/VER-selftest.json"   # fixtures evaluated, at least one expecting DONE`; denominator_min: 20
- spec: Fixtures (each a directory with expected.txt): done_happy (DONE), not_run, zero_denominator, stale_log, dirty_tree, missing_findings, findings_not_array, findings_bad_severity_case, findings_missing_status, open_high, deferred_critical, manifest_hash_mismatch, floor_gate_missing, level_8_to_7, exit_nonzero_value_ok, exit_zero_value_fail, unparsable_value, missing_exit_line, soft_ratio_below_80, confirm_same_run_id, confirm_overlapping_times, ag_gate_empty_subject, human_gate_only (NOT_DONE tagged human-gate:HG-07 only). value = fixtures whose outcome or blocking ids differ from expected.txt.
- why: A verdict that never says DONE, or always does, is useless; the fixtures pin both directions (R11).

### EC-mex: .mex scaffold present, untracked, with its content floor, and mex check passes

- hard: true; scope: primary; runs_in: host; timeout_s: 900; built_by: P0-i1-T32
- command: `scripts/gates/ec-mex.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/EC-mex.json`
- value_parser: `jq -e '.value' "$GATES_OUT/EC-mex.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/EC-mex.json"   # scaffold files`; denominator_min: 3
- spec: .mex/ROUTER.md and .mex/AGENTS.md exist; git ls-files .mex is empty and git check-ignore .mex/ROUTER.md succeeds; ROUTER.md names compose.dev.yml, make gates and 8095; mex check --quiet exits 0 (MEX_BIN, else mex on PATH, else the newest $HOME/.nvm/versions/node/*/bin/mex); the hygiene patterns find nothing in .mex; the artifact records sha256 of every scaffold file. value = failures. In a nested or ci run context this gate is recorded out_of_scope.
- why: R8: the scaffold is local, so this proves it exists and is not empty (F-P0-017).

### EC-ci-parity: make gates is green from a fresh clone on another compose project and port

- hard: true; scope: primary; runs_in: host; timeout_s: 3600; built_by: P0-i1-T35
- command: `scripts/gates/ec-ci-parity.sh`
- banner_cmd: `bash --version | head -1`; banner_re: `^GNU bash`
- artifact: `$GATES_OUT/EC-ci-parity.json`
- value_parser: `jq -e '.value' "$GATES_OUT/EC-ci-parity.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/EC-ci-parity.json"   # gates recorded in the nested scorecard`; denominator_min: 37
- spec: git clone --no-hardlinks of HEAD into .gates/ci-parity/<head12>; there COMPOSE_PROJECT_NAME=ppclean PP_HTTP_PORT=18095 PP_RUN_CONTEXT=nested make gates; value = nested make exit status (0 or 1); afterwards docker compose -p ppclean down (never -v). In a nested or ci run context this gate is recorded out_of_scope.
- why: CI is never run by the loop (nothing is pushed), so parity is proven locally (F-P0-015).

### DEN: Every value gate in this run has a denominator > 0 and at least its minimum

- hard: true; scope: any; runs_in: host; timeout_s: 900; built_by: P0-i1-T29
- command: `python3 -I scripts/gates/den.py`
- banner_cmd: `python3 --version`; banner_re: `^Python 3\.`
- artifact: `$GATES_OUT/DEN.json`
- value_parser: `jq -e '.value' "$GATES_OUT/DEN.json"`
- op / threshold: `==` `0`
- denominator_source: `jq -e '.denominator' "$GATES_OUT/DEN.json"   # gate records read`; denominator_min: 38
- spec: Reads $GATES_OUT/records/*.json written by scripts/scorecard for every other gate of this run: each record has a numeric denominator >= the manifest denominator_min (and > 0), a non-empty denominator_source, and every manifest gate except DEN has exactly one record (out_of_scope records count, with their reason). Runs last. value = violations.
- why: A gate with nothing to measure fails (zero-denominator rule).

## Exit criteria (run by scripts/verdict, not by make gates)

scripts/verdict (full mode) runs these checks itself, logs each to <scorecard dir>/exit/<id>.log ending EXIT n, and blocks with exit:<id>; make gates never runs them because a CI checkout has a different origin URL (R22).

- EC-X1 (remote origin is the GitHub URL): `test "$(git remote get-url origin)" = git@github.com:AndresThePerez/PokePrices.git`
- EC-X2 (default branch main): `test "$(git symbolic-ref --short HEAD)" = main`
- EC-X3 (first commit made): `test "$(git rev-list --count HEAD)" -ge 1`
- EC-X4 (nothing pushed (needs network; offline is not_run = fail)): `test "$(git ls-remote --heads origin | wc -l)" -eq 0 && test "$(git for-each-ref refs/remotes | wc -l)" -eq 0`
- EC-X5 (scripts/scorecard and scripts/verdict committed and executable): `test "$(git ls-files -s scripts/scorecard scripts/verdict | awk '$1=="100755"' | wc -l)" -eq 2`
- EC-X6 (compose services and the single loopback port): `test "$(docker compose -f compose.dev.yml config --services | sort | tr '\n' ' ')" = 'api node postgres redis-cache redis-queue scheduler tools web worker ' && docker compose -f compose.dev.yml config --format json | jq -e '[.services[] | (.ports // [])[]] | length == 1 and .[0].host_ip == "127.0.0.1" and .[0].target == 8095'`
- EC-X7 (api/ is Laravel 13): `jq -e '[.packages[] | select(.name == "laravel/framework") | .version | startswith("v13.")] == [true]' api/composer.lock`
- EC-X8 (web/ is React 19 + Vite 8 + TypeScript 6.0 + Tailwind v4): `jq -e '(.dependencies.react | startswith("19.")) and (.devDependencies.vite | startswith("8.")) and (.devDependencies.typescript | startswith("6.0.")) and (.devDependencies.tailwindcss | startswith("4."))' web/package.json`
- EC-X9 (CI is a workflow that only calls make gates): `mkdir -p .gates/manual && GATES_OUT=.gates/manual sh scripts/gates/d4.sh`
- EC-X10 (make gates green on the skeleton): `scripts/verdict --gates-only --manifest gates/manifest-P0.json --scorecard .gates/latest/scorecard.json`

## Frozen files

- list: `Makefile`, `scripts/scorecard`, `scripts/verdict`, `scripts/lib/*`, `scripts/gates/*`, `gates/*`, `gates/fixtures/**`, `.gitignore`, `api/.gitignore`, `web/.gitignore`, `.dockerignore`, `compose.dev.yml`, `docker/api/Dockerfile`, `docker/api/zz-pokeprices.conf`, `docker/web/Dockerfile`, `docker/tools/Dockerfile`, `docker/nginx/pokeprices.conf`, `scripts/dev/*`, `.github/workflows/*`, `.spectral.yaml`, `api/composer.json#.scripts`, `api/composer.json#.config`, `api/phpstan.neon`, `api/pint.json`, `api/rector.php`, `api/phpunit.xml`, `api/tests/Pest.php`, `api/tests/TestCase.php`, `api/tests/Support/*`, `web/package.json#.scripts`, `web/package.json#.engines`, `web/package.json#.overrides`, `web/eslint.config.js`, `web/tsconfig.json`, `web/tsconfig.app.json`, `web/tsconfig.node.json`, `web/vitest.config.ts`, `web/.prettierrc.json`, `web/.prettierignore`
- record: gates/frozen.sha256 lists one line per concrete path (glob entries expanded at freeze time; #subtree entries hash jq -S of that subtree); anchored at the first commit that adds it
- comparators: gates/ratchet.json maps each path to one kind of a closed set: hash, phpstan-params, phpunit-xml, tsconfig, vitest, json-subtree, ignore-lines; anything a comparator does not decide is a loosening and needs a new Andres-approved hash (R11)
- config_discovery_globs: listed in gates/ratchet.json under discovery; git ls-files matches must equal the frozen list

## Out of P0

Every other Research 11 id (T3 T4 T4b T5 T6 T7 T9 T10 C4 C5 P1-P16 R1-R6 S3 S8 S9 S11 O3 O4 O6 O7 D1 D2 D3 D5 Q9 Q10) and AG4-AG6 are out of P0 for the reasons in review-1.md and R17.

## Open questions for Andres

1. S10 (trivy CRITICAL on the two runtime images) is soft per R17. Promote it to hard? And may it pass --ignore-unfixed? This manifest passes neither.
2. R17 names 33 hard ids and R20 adds AG-values, so this manifest has 34 hard gates (the orchestrator's brief said 33 including AG-values). Confirm 34.
3. scope 'primary' for EC-mex and EC-ci-parity: .mex is untracked (R8) and the parity check cannot nest itself, so both are recorded out_of_scope in the nested clone and on a CI runner, and the verdict rejects any non-primary scorecard. Approve?
4. EC-ci-parity uses git clone --no-hardlinks into .gates/ci-parity/<head12> instead of git worktree add, because a linked worktree's .git file points outside the bind mount and gitleaks (S4) cannot read history from it. The ppclean compose project it starts is treated as loop-owned like pokeprices (R7). Approve both?
5. Memory caps for the two services the server table does not have: tools 512m and node 1g (dev-only tooling). Approve?
6. Q4 type coverage stays hard at 100% (R17). If the Laravel 13 skeleton cannot reach 100 without @var noise, the loop stops and asks rather than lowering it. Agreed?
7. C6: openapi-typescript 7.13.0 declares a peer of typescript ^5.x while the web toolchain is pinned to typescript 6.0.3 (typescript-eslint needs < 6.1). The plan resolves the peer with a frozen npm overrides entry pointing at the project's TypeScript. Approve, or move C6 out of P0?
8. The freeze anchor is the commit that first adds gates/frozen.sha256 (the last P0 task); you ratify that commit's values at the P0 phase review. Approve?
9. AG-values asserts phpstan level >= 8 (not == 8) so a stricter level stays legal. Approve?
10. Web gates run inside the node service (node:22-slim, glibc, matching the host), so the workflow needs only checkout plus make gates. Approve?
11. Postgres uses trust authentication on the unpublished compose network, so no database password exists in any tracked file. Approve?
