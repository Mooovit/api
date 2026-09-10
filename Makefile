# mooovit — package & upload to o2switch shared hosting.
#
#   make deploy          build assets + deps, test, package, upload (no extract)
#   make deploy-current  deploy + rsync-mirror the tree to .../current +
#                        php artisan migrate --force on the server
#                        (.env and storage on the server are NEVER touched)
#   make versions        list the versions already on the server
#   make install         MANUAL: extract the last uploaded zip + migrate — not
#                        part of deploy
#
# How it works (replaces the old travis/deploy.sh flow — the zip goes
# straight to the server, no S3/servermanager in between):
#
#   1. build   composer + npm production. Vendor ships in the zip (like
#              travis did with skip_cleanup — the server never runs
#              composer). composer.json pins config.platform.php to the
#              SERVER's version (8.0.30) so the resolved vendor actually
#              runs there.
#   2. test    the local suite must pass before anything ships
#   3. package next version = highest N.zip in versions/ + 1 (plain number,
#              same naming as the old travis builds); zip the repo WITHOUT
#              .env, .git, node_modules, tests, local storage data
#   4. upload  scp to $(REMOTE_DIR)/versions/ — deploy stops here, nothing
#              is extracted on the server
#
# The zip never contains .env or server-only state, so extracting it over
# the app (unzip -o, which only overwrites files present in the archive)
# preserves the server's .env and its storage data (backups, logs).
#
# Override on the command line if the server CLI differs, e.g.:
#   make install PHP_BIN=/opt/cpanel/ea-php81/root/usr/bin/php

SHELL := /bin/bash

SSH_HOST   := sc1mtirelli@chaton.o2switch.net
SSH_KEY    := ~/.ssh/sc1o2switch
SSH_OPTS   := -i $(SSH_KEY) -o BatchMode=yes -o StrictHostKeyChecking=accept-new
REMOTE_DIR := /home2/sc1mtirelli/production/mooovit
PHP_BIN    ?= php

SSH := ssh $(SSH_OPTS) $(SSH_HOST)

# Never shipped: VCS, local deps/secrets/state, dev-only files.
ZIP_EXCLUDES := \
	-x "*.git*" \
	-x "node_modules/*" \
	-x ".env" -x ".env.*" \
	-x "build/*" \
	-x "tests/*" \
	-x "storage/logs/*" \
	-x "storage/framework/cache/data/*" \
	-x "storage/framework/sessions/*" \
	-x "storage/framework/views/*" \
	-x "database/*.sqlite" \
	-x "public/hot" \
	-x "public/storage" \
	-x "*.DS_Store" \
	-x ".phpunit.result.cache" \
	-x ".travis.yml"

# Mirror exclusions for the `current` tree. Anchored patterns (leading /)
# match at the repo root only. Plain --exclude also keeps these files from
# being deleted on the RECEIVER (no --delete-excluded): the server's .env
# and its whole storage/ survive every sync untouched.
RSYNC_EXCLUDES := \
	--exclude '/.git*' \
	--exclude '/node_modules' \
	--exclude '/.env' \
	--exclude '/.env.*' \
	--exclude '/build' \
	--exclude '/tests' \
	--exclude '/storage' \
	--exclude '/database/*.sqlite' \
	--exclude '/public/hot' \
	--exclude '/public/storage' \
	--exclude '*.DS_Store' \
	--exclude '/.phpunit.result.cache' \
	--exclude '/.travis.yml'

.PHONY: deploy deploy-current sync-current migrate-current build test package upload install versions

deploy: build test package upload

deploy-current: deploy sync-current migrate-current

sync-current:
	@echo ">> Syncing repo to $(REMOTE_DIR)/current (.env and storage excluded)"; \
	$(SSH) 'mkdir -p $(REMOTE_DIR)/current'; \
	rsync -az --delete $(RSYNC_EXCLUDES) -e "ssh $(SSH_OPTS)" ./ $(SSH_HOST):$(REMOTE_DIR)/current/; \
	echo ">> Synced"

migrate-current:
	@echo ">> Migrating $(REMOTE_DIR)/current"; \
	$(SSH) "cd $(REMOTE_DIR)/current && $(PHP_BIN) artisan migrate --force"

build:
	composer install --no-interaction --prefer-dist
	npm install
	@node_major=$$(node -p 'process.versions.node.split(".")[0]'); \
	if [ $$node_major -ge 17 ]; then \
		echo ">> Node $$node_major: enabling OpenSSL legacy provider (webpack 5 md4 hashing)"; \
		export NODE_OPTIONS=--openssl-legacy-provider; \
	fi; \
	npm run production

test:
	php artisan test

package:
	@mkdir -p build
	@rm -f build/*.zip build/.last_package
	@version=$$($(SSH) 'ls $(REMOTE_DIR)/versions 2>/dev/null' | grep -oE '[0-9]+' | sort -n | tail -1); \
	version=$$((10#$${version:-0} + 1)); \
	name=$$version.zip; \
	echo ">> Packaging $$name"; \
	zip -rq build/$$name . $(ZIP_EXCLUDES); \
	echo $$name > build/.last_package

upload:
	@name=$$(cat build/.last_package); \
	echo ">> Uploading $$name"; \
	$(SSH) 'mkdir -p $(REMOTE_DIR)/versions'; \
	scp -q $(SSH_OPTS) build/$$name $(SSH_HOST):$(REMOTE_DIR)/versions/; \
	echo ">> Uploaded"

install:
	@name=$$(cat build/.last_package); \
	echo ">> Installing $$name on the server"; \
	$(SSH) "set -e; cd $(REMOTE_DIR); \
		unzip -oq versions/$$name -d .; \
		$(PHP_BIN) artisan migrate --force; \
		$(PHP_BIN) artisan optimize:clear; \
		echo '>> Deployed.'"

versions:
	@$(SSH) 'ls -1 $(REMOTE_DIR)/versions 2>/dev/null | sort'
