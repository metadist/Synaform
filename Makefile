.PHONY: help lint format test sync sync-and-clear validate-i18n

SYNAPLAN_DIR ?= /wwwroot/synaplan
PLUGIN_SRC   = synaform-plugin
PLUGIN_DST   = $(SYNAPLAN_DIR)/plugins/synaform

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

## Code Quality

lint: ## Run all lint checks (PHP + JS + i18n)
	@echo "=== PHP formatting check (PSR-12) ==="
	docker compose -f $(SYNAPLAN_DIR)/docker-compose.yml exec -T backend \
		vendor/bin/php-cs-fixer fix --dry-run --diff --rules=@PSR12 --using-cache=no /plugins/synaform/backend/ || true
	@echo ""
	@echo "=== JS formatting check ==="
	npx prettier --check '$(PLUGIN_SRC)/frontend/**/*.js' 2>/dev/null || echo "Install prettier: npm install --save-dev prettier"
	@echo ""
	@echo "=== i18n validation ==="
	@$(MAKE) validate-i18n

format: ## Fix formatting (PHP + JS)
	docker compose -f $(SYNAPLAN_DIR)/docker-compose.yml exec -T backend \
		vendor/bin/php-cs-fixer fix --rules=@PSR12 --using-cache=no /plugins/synaform/backend/ || true
	npx prettier --write '$(PLUGIN_SRC)/frontend/**/*.js' 2>/dev/null || true

validate-i18n: ## Validate i18n JSON files parse and keys match
	@for f in $(PLUGIN_SRC)/frontend/i18n/*.json; do \
		python3 -m json.tool "$$f" > /dev/null 2>&1 || { echo "FAIL: $$f is not valid JSON"; exit 1; }; \
	done
	@python3 -c "import json, os, sys; d = '$(PLUGIN_SRC)/frontend/i18n'; fl = lambda o: {(s + '.' + k) for s, v in o.items() for k in (v.keys() if isinstance(v, dict) else [''])} | {s for s, v in o.items() if not isinstance(v, dict)}; ref = fl(json.load(open(d + '/en.json'))); bad = [(fn, sorted(ref - fl(json.load(open(d + '/' + fn))))) for fn in sorted(os.listdir(d)) if fn.endswith('.json') and fn != 'en.json']; bad = [(f, m) for f, m in bad if m]; [print('FAIL ' + f + ': missing ' + ', '.join(m[:8])) for f, m in bad]; sys.exit(1 if bad else 0)" && echo "i18n keys OK"

## Testing

test: ## Run E2E tests (requires running Synaplan stack)
	npx playwright test tests/e2e/

test-api: ## Run API-only tests
	npx playwright test tests/e2e/ --grep @api

test-ui: ## Run UI-only tests
	npx playwright test tests/e2e/ --grep @ui

## Deployment

sync: ## Copy plugin to Synaplan plugins directory
	rm -rf $(PLUGIN_DST)
	cp -r $(PLUGIN_SRC) $(PLUGIN_DST)
	@echo "Synced to $(PLUGIN_DST)"

sync-and-clear: sync ## Sync plugin and clear Symfony cache
	docker compose -f $(SYNAPLAN_DIR)/docker-compose.yml exec -T backend php bin/console cache:clear
	@echo "Cache cleared"
