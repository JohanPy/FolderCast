app_name=foldercast
project_dir=$(CURDIR)/../$(app_name)
build_dir=$(CURDIR)/build/artifacts
appstore_dir=$(build_dir)/appstore
source_dir=$(build_dir)/source
sign_dir=$(build_dir)/sign
package_name=$(app_name)
cert_dir=$(HOME)/.nextcloud/certificates
version+=$(shell grep "<version>" appinfo/info.xml | tr -d '\t >/version<')

# Auto-detect composer: local or docker
COMPOSER_BIN := $(shell command -v composer 2> /dev/null)
ifndef COMPOSER_BIN
    COMPOSER_CMD = docker run --rm --user $(shell id -u):$(shell id -g) -v $(CURDIR):/app -w /app composer
else
    COMPOSER_CMD = composer
endif

all: appstore

clean:
	rm -rf $(build_dir)
	rm -rf node_modules
	rm -rf vendor
	rm -rf js/*.map
	rm -rf js/*.license

composer:
	$(COMPOSER_CMD) install --no-dev -o

npm:
	npm install --legacy-peer-deps
	npm run build

appstore: clean composer npm
	mkdir -p $(appstore_dir)
	mkdir -p $(source_dir)
	rsync -a \
	--exclude=.git \
	--exclude=.github \
	--exclude=.gitignore \
	--exclude=.travis.yml \
	--exclude=.scrutinizer.yml \
	--exclude=.agent \
	--exclude=CONTRIBUTING.md \
	--exclude=composer.json \
	--exclude=composer.lock \
	--exclude=package.json \
	--exclude=package-lock.json \
	--exclude=vite.config.mjs \
	--exclude=vite.config.js \
	--exclude=webpack.config.js \
	--exclude=webpack.js \
	--exclude=node_modules \
	--exclude=tests \
	--exclude=src \
	--exclude=vendor-bin \
	--exclude=build \
	--exclude=Makefile \
	--exclude=docker-compose.yml \
	--exclude=nextcloud_data \
	--exclude=*.log \
	--exclude=*.map \
	--exclude=todo.md \
	--exclude=TODO.md \
	--exclude=rector.php \
	--exclude=psalm.xml \
	--exclude=phpstan.neon \
	--exclude=openapi.json \
	--exclude=stylelint.config.cjs \
	. $(source_dir)/$(app_name)
	tar -czf $(appstore_dir)/$(app_name)-$(version).tar.gz -C $(source_dir) $(app_name)
	@echo "Archive created at $(appstore_dir)/$(app_name)-$(version).tar.gz"

.PHONY: doctor test-php test-js test-all

doctor:
	@echo "Branch: $$(git branch --show-current)"
	@if find .git -name '*conflicted copy*' -print | grep -q . ; then \
		echo "ERROR: conflicted copy files are still present in .git"; \
		exit 1; \
	fi
	@echo "Git sanity: OK"

test-php:
	docker run --rm -v $(CURDIR):/app -w /app php:8.3-cli sh -lc "find lib appinfo -name '*.php' -print0 | xargs -0 -n1 php -l"
	$(COMPOSER_CMD) test:unit

test-js:
	docker run --rm --user $$(id -u):$$(id -g) -v $(CURDIR):/app -w /app node:22 sh -lc "npm ci --legacy-peer-deps && npm run build"

test-all: doctor test-php test-js
