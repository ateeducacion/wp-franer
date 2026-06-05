# Makefile

# Define SED_INPLACE based on the operating system
ifeq ($(shell uname), Darwin)
  SED_INPLACE = sed -i ''
else
  SED_INPLACE = sed -i
endif

# Check if Docker is running
check-docker:
	@docker version  > /dev/null || (echo "" && echo "Error: Docker is not running. Please ensure Docker is installed and running." && echo "" && exit 1)

install-requirements:
	npm -g i @wordpress/env

# Ensure the environment is running. Used as a prerequisite by the test/check
# targets: the probe keeps repeated `make test` runs fast, and `wp-env start`
# is idempotent so re-running it is safe. Probes the development site (8888).
start-if-not-running: check-docker
	@if [ "$$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8888)" = "000" ]; then \
		echo "wp-env is not running. Starting..."; \
		npx wp-env start; \
		npx wp-env run cli wp plugin activate franer; \
		echo "Visit http://localhost:8888/wp-admin/ to access the Franer dashboard."; \
	else \
		echo "wp-env is already running."; \
	fi

# Bring up the environment. Always calls `wp-env start` (idempotent), so it
# (re)syncs the containers instead of skipping when something only looks up.
up: check-docker
	npx wp-env start
	-npx wp-env run cli wp plugin activate franer
	@echo "Visit http://localhost:8888/wp-admin/ to access the Franer dashboard."

# Alias for `up` (some folks type `make start`).
start: up

# Update WordPress core/themes and (re)start the environment.
update: check-docker
	npx wp-env start --update
	-npx wp-env run cli wp plugin activate franer

flush-permalinks:
	npx wp-env run cli wp rewrite structure '/%postname%/'

# Function to create a user only if it does not exist
create-user:
	@if [ -z "$(USER)" ] || [ -z "$(EMAIL)" ] || [ -z "$(ROLE)" ]; then \
		echo "Error: Please, specify USER, EMAIL, ROLE and PASSWORD. Usage: make create-user USER=test1 EMAIL=test1@example.org ROLE=editor PASSWORD=password"; \
		exit 1; \
	fi
	npx wp-env run cli sh -c 'wp user list --field=user_login | grep -q "^$(USER)$$" || wp user create $(USER) $(EMAIL) --role=$(ROLE) --user_pass=$(PASSWORD)'

# Stop the environment (containers are stopped; data is preserved — use
# `destroy` to remove containers and volumes entirely).
down: check-docker
	npx wp-env stop

# Alias for `down` (some folks type `make stop`).
stop: down

# Clean the environments, the same that running "npx wp-env clean all"
clean:
	npx wp-env clean development
	npx wp-env clean tests
	npx wp-env run cli wp plugin activate franer
	npx wp-env run cli wp language core install es_ES --activate
	npx wp-env run cli wp site switch-language es_ES

destroy:
	npx wp-env destroy

# Reset the WordPress databases to a fresh install, then reactivate the plugin.
reset: check-docker
	npx wp-env reset
	-npx wp-env run cli wp plugin activate franer

logs:
	npx wp-env logs

logs-test:
	npx wp-env logs --environment=tests

# Pass the wp plugin-check with proper error handling
check-plugin: check-docker start-if-not-running
	# Install plugin-check if needed (don't fail if already active)
	@npx wp-env run cli wp plugin install plugin-check --activate --color || true

	# Run plugin check; wp-env run always exits 0, so we grep the output for ERRORs.
	@echo "Running WordPress Plugin Check..."
	@TMPFILE=$$(mktemp); \
	npx wp-env run cli wp plugin check franer \
		--exclude-directories=tests \
		--exclude-checks=file_type,image_functions \
		--ignore-warnings \
		--color 2>&1 | tee $$TMPFILE; \
	ERRORS=$$(sed 's/\x1B\[[0-9;]*[mK]//g' $$TMPFILE | grep -cE '\bERROR\b' || true); \
	rm -f $$TMPFILE; \
	echo ""; \
	if [ "$$ERRORS" -gt 0 ]; then \
		echo "Plugin Check: FAIL - $$ERRORS error(s) found."; \
		exit 1; \
	else \
		echo "Plugin Check: OK - No errors found."; \
	fi

# Check code style with PHP_CodeSniffer (WordPress Coding Standards)
lint:
	composer phpcs

# Automatically fix code style with PHP Code Beautifier and Fixer
fix:
	composer phpcbf

# Fix without tty for use on git hooks
fix-no-tty:
	composer phpcbf

# Lint without tty for use on git hooks
lint-no-tty:
	composer phpcs

# Combined check for fix, lint, plugin-check, tests, untranslated, and mo
check: fix lint check-plugin test check-untranslated mo

check-all: check

tests: test

# Run unit tests with PHPUnit (alias). Use FILE or FILTER (or both).
test: test-php

# Run PHP unit tests via wp-env tests environment.
test-php: start-if-not-running
	@CMD="./vendor/bin/phpunit"; \
	if [ -n "$(FILE)" ]; then CMD="$$CMD $(FILE)"; fi; \
	if [ -n "$(FILTER)" ]; then CMD="$$CMD --filter $(FILTER)"; fi; \
	npx wp-env run tests-cli --env-cwd=wp-content/plugins/franer $$CMD --colors=always

# Run JavaScript unit tests (Jest)
test-js:
	npm run test:js

# Run E2E tests with Playwright against wp-env tests environment (port 8889)
test-e2e: start-if-not-running
	WP_BASE_URL=http://localhost:8889 npm run test:e2e -- $(ARGS)

# Run E2E tests with visual test UI
test-e2e-visual: start-if-not-running
	WP_BASE_URL=http://localhost:8889 npm run test:e2e -- --ui

# Generate a .pot file for translations
pot:
	composer make-pot

# Update .po files from .pot file
po:
	composer update-po

# Generate .mo files from .po files
mo:
	composer make-mo

# Check the untranslated strings
check-untranslated:
	composer check-untranslated

# Generate the franer-X.X.X.zip package
package:
	@if [ -z "$(VERSION)" ]; then \
		echo "Error: No version specified. Use 'make package VERSION=1.2.3'"; \
		exit 1; \
	fi
	# Update the version in franer.php & readme.txt
	$(SED_INPLACE) "s/^ \* Version:.*/ * Version:           $(VERSION)/" franer.php
	$(SED_INPLACE) "s/define( *'FRANER_VERSION', '[^']*'/define( 'FRANER_VERSION', '$(VERSION)'/" franer.php
	$(SED_INPLACE) "s/^Stable tag:.*/Stable tag: $(VERSION)/" readme.txt

	# Create the ZIP package
	composer archive --format=zip --file="franer-$(VERSION)"

	# Restore the version in franer.php & readme.txt
	$(SED_INPLACE) "s/^ \* Version:.*/ * Version:           0.0.0/" franer.php
	$(SED_INPLACE) "s/define( *'FRANER_VERSION', '[^']*'/define( 'FRANER_VERSION', '0.0.0'/" franer.php
	$(SED_INPLACE) "s/^Stable tag:.*/Stable tag: 0.0.0/" readme.txt

# Build assets (no-op placeholder; Franer ships static assets)
build:
	@echo "No build step required for Franer."

# Show help with available commands
help:
	@echo "Available commands:"
	@echo ""
	@echo "General:"
	@echo "  up / start         - Start the WordPress environment (idempotent)"
	@echo "  down / stop        - Stop the environment (data preserved)"
	@echo "  update             - Update WordPress core/themes and restart"
	@echo "  logs               - Show the docker container logs"
	@echo "  logs-test          - Show logs from test environment"
	@echo "  clean              - Reset both environments' databases"
	@echo "  reset              - Reset the development database to a fresh install"
	@echo "  destroy            - Remove the environment (containers and volumes)"
	@echo "  flush-permalinks   - Flush the created permalinks"
	@echo "  create-user        - Create a WordPress user if it doesn't exist."
	@echo "                       Usage: make create-user USER=<username> EMAIL=<email> ROLE=<role> PASSWORD=<password>"
	@echo ""
	@echo "Linting & Code Quality:"
	@echo "  lint               - Check code style with PHPCS (WordPress Coding Standards)"
	@echo "  fix                - Automatically fix code style with PHPCBF"
	@echo "  fix-no-tty         - Same as 'fix' but without TTY (for git hooks)"
	@echo "  lint-no-tty        - Same as 'lint' but without TTY (for git hooks)"
	@echo "  check-plugin       - Run WordPress plugin-check tests"
	@echo "  check-untranslated - Check for untranslated strings"
	@echo "  check              - Run fix, lint, plugin-check, tests, untranslated, and mo"
	@echo "  check-all          - Alias for 'check'"
	@echo ""
	@echo "Testing:"
	@echo "  test / test-php    - Run PHPUnit tests. Accepts optional variables:"
	@echo "                       FILTER=<pattern> (run tests matching the pattern)"
	@echo "                       FILE=<path>      (run tests in specific file)"
	@echo "  test-js            - Run JavaScript unit tests (Jest)"
	@echo "  test-e2e           - Run E2E tests (non-interactive)"
	@echo "  test-e2e-visual    - Run E2E tests with visual test UI"
	@echo ""
	@echo "Translations:"
	@echo "  pot                - Generate a .pot file for translations"
	@echo "  po                 - Update .po files from .pot file"
	@echo "  mo                 - Generate .mo files from .po files"
	@echo ""
	@echo "Packaging:"
	@echo "  build              - Build assets (placeholder)"
	@echo "  package            - Create ZIP package. Usage: make package VERSION=x.y.z"
	@echo ""
	@echo "  help               - Show this help message"

# Set help as the default target if no target is specified
.DEFAULT_GOAL := help
