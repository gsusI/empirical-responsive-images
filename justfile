set shell := ["bash", "-eu", "-o", "pipefail", "-c"]

@default:
	just --list

install:
	composer install

lint:
	composer lint:php
	composer phpcs

analyse:
	composer phpstan

test:
	composer test
	composer test:js

check:
	composer check
	tools/check-version-sync.sh

review:
	composer review:readiness

package:
	tools/wporg-release.sh package

plugin-check:
	tools/wporg-release.sh wp-check

release-check: check
	tools/wporg-release.sh all
