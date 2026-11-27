# wankarr build tasks. Run `make` for the list.
SHELL   := /bin/bash
VERSION := $(shell cat VERSION)
PHP     ?= php
PORT    ?= 8484
IMAGE   ?= wankarr

.DEFAULT_GOAL := help
.NOTPARALLEL:
.PHONY: help dev lint test check dist docker docker-run release publish clean

help: ## List targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "  \033[36m%-11s\033[0m %s\n", $$1, $$2}'

dev: ## Run the PHP dev server (PORT=8484, LIBRARY=/path/to/manga)
	WANKARR_LIBRARY="$(or $(LIBRARY),$$WANKARR_LIBRARY)" $(PHP) -d opcache.revalidate_freq=0 -S 0.0.0.0:$(PORT) -t public

lint: ## Syntax-check PHP (and JS if node is installed)
	@scripts/lint.sh

test: ## Run the unit tests
	@$(PHP) tests/run.php

check: lint test ## Lint + tests

dist: ## Build dist/wankarr-<version>.tar.gz from the committed tree
	@mkdir -p dist
	git archive --format=tar.gz --prefix=wankarr-$(VERSION)/ -o dist/wankarr-$(VERSION).tar.gz HEAD
	@echo "dist/wankarr-$(VERSION).tar.gz"

docker: ## Build the container image locally as $(IMAGE):local
	docker build --build-arg VERSION=$(VERSION)-local \
	  --build-arg REVISION=$$(git rev-parse --short HEAD 2>/dev/null || echo unknown) \
	  -t $(IMAGE):local .

docker-run: ## Run the local image (PORT=8484, LIBRARY=/path/to/manga, data in ./data-docker)
	docker run --rm -it -p $(PORT):$(PORT) -e WANKARR_PORT=$(PORT) -v "$(or $(LIBRARY),$$PWD/library)":/manga -v "$$PWD/data-docker":/config $(IMAGE):local

release: ## Start a release (opens a pull request): make release V=0.4.0
	@[ -n "$(V)" ] || { echo "usage: make release V=X.Y.Z"; exit 1; }
	@scripts/release.sh prepare "$(V)"

publish: ## Tag and publish a merged release: make publish V=0.4.0
	@[ -n "$(V)" ] || { echo "usage: make publish V=X.Y.Z"; exit 1; }
	@scripts/release.sh publish "$(V)"

clean: ## Remove build output (dist/)
	rm -r -f dist
