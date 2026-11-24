# PokéPrices entry points. CI runs exactly `make test` (R306); `make gates` is the loop's full gate run.
SHELL := /bin/bash
.SHELLFLAGS := -o pipefail -c
.DEFAULT_GOAL := help

DC := docker compose -f compose.dev.yml
CORE := postgres redis-queue redis-cache api node tools
MANIFEST := gates/manifest-P0.json
SCORECARD ?= .gates/latest/scorecard.json

.PHONY: help up init test gates verdict down

help:
	@echo "make up | make init | make test | make gates | make verdict FINDINGS=<path> | make down"

up:
	mkdir -p .gates
	$(DC) up -d --wait --build $(CORE)

init: up
	scripts/dev/ensure-databases.sh
	$(DC) exec -T -w /workspace/api api composer install --no-interaction --prefer-dist
	scripts/dev/init-api-env.sh
	$(DC) exec -T -w /workspace/web node npm ci --no-audit --no-fund

test: init
	$(DC) exec -T -w /workspace/api api vendor/bin/pest -c phpunit.xml --parallel --processes=3
	$(DC) exec -T -w /workspace/web node ./node_modules/.bin/vitest run --config vitest.config.ts
	$(DC) exec -T -w /workspace/web node ./node_modules/.bin/tsc -b
	$(DC) exec -T -w /workspace/web node ./node_modules/.bin/eslint -c eslint.config.js --max-warnings=0 .
	$(DC) exec -T -w /workspace/api api php artisan scramble:export --path=/workspace/.gates/make-test.api.json
	cmp -s openapi/api.json .gates/make-test.api.json
	$(DC) exec -T -w /workspace/web node ./node_modules/.bin/openapi-typescript ../openapi/api.json -o /workspace/.gates/make-test.schema.d.ts
	cmp -s web/src/api/schema.d.ts .gates/make-test.schema.d.ts

gates: init
	$(DC) up -d --wait --build
	scripts/scorecard --manifest $(MANIFEST)
	scripts/verdict --gates-only --manifest $(MANIFEST) --scorecard $(SCORECARD)

verdict:
	@test -n "$(FINDINGS)" || { echo "FINDINGS=<path to findings.json> is required"; false; }
	scripts/verdict --manifest $(MANIFEST) --scorecard $(SCORECARD) --findings $(FINDINGS) $(VERDICT_ARGS)

down:
	$(DC) down
