#!/usr/bin/env bash
set -euo pipefail

SLUG="empirical-responsive-images"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PARENT="$(dirname "${ROOT}")"
VERSION="$(awk -F': ' '/^[[:space:]]*\* Version:/{ print $2; exit }' "${ROOT}/${SLUG}.php")"
TESTED_UP_TO="$(awk -F': ' '/^Tested up to:/{ print $2; exit }' "${ROOT}/readme.txt")"
BUILD_DIR="${ROOT}/build/wporg"
DOCKER_DIR="${ROOT}/.wporg-build"
CHECK_PLUGIN_ROOT="${DOCKER_DIR}/plugin/${SLUG}"
PACKAGE_IMAGE="${SLUG}-package:local"
ZIP_PATH="${BUILD_DIR}/${SLUG}-${VERSION}.zip"
SVN_DIR="${BUILD_DIR}/svn"
WORDPRESS_IMAGE="${WORDPRESS_IMAGE:-wordpress:latest}"
WORDPRESS_CLI_IMAGE="${WORDPRESS_CLI_IMAGE:-wordpress:cli-php8.3}"
MARIADB_IMAGE="${MARIADB_IMAGE:-mariadb:11}"
PROJECT_NAME="eri-wporg-${VERSION//./-}"

usage() {
	cat <<USAGE
Usage: $(basename "$0") [all|lint|package|wp-check|svn]

Environment overrides:
  WORDPRESS_IMAGE       default: ${WORDPRESS_IMAGE}
  WORDPRESS_CLI_IMAGE   default: ${WORDPRESS_CLI_IMAGE}
  MARIADB_IMAGE         default: ${MARIADB_IMAGE}

Outputs:
  ${ZIP_PATH}
  ${SVN_DIR}
USAGE
}

docker_compose() {
	docker compose -f "${DOCKER_DIR}/compose.yml" -p "${PROJECT_NAME}" "$@"
}

ensure_dirs() {
	mkdir -p "${BUILD_DIR}" "${DOCKER_DIR}"
}

build_package_image() {
	docker build -q -t "${PACKAGE_IMAGE}" -f "${ROOT}/tools/wporg-build/Dockerfile.package" "${ROOT}/tools/wporg-build" >/dev/null
}

lint_php() {
	echo "==> PHP lint in Docker"
	docker run --rm \
		--volume "${ROOT}:/plugin:ro" \
		php:8.3-cli \
		sh -lc "find /plugin -name '*.php' ! -path '/plugin/build/*' ! -path '/plugin/.wporg-build/*' ! -path '/plugin/.phpstan-cache/*' ! -path '/plugin/vendor/*' -print0 | xargs -0 -n1 php -l"
}

run_quality_checks() {
	echo "==> Local coding standards, static analysis, and tests"
	command -v composer >/dev/null 2>&1 || {
		echo "Composer is required for the full release check." >&2
		return 1
	}
	composer --working-dir="${ROOT}" install --no-interaction --prefer-dist
	composer --working-dir="${ROOT}" audit --no-interaction
	composer --working-dir="${ROOT}" check
	"${ROOT}/tools/check-version-sync.sh"
}

build_zip() {
	echo "==> Build release ZIP in Docker"
	"${ROOT}/tools/check-version-sync.sh"
	"${ROOT}/tools/check-review-readiness.sh"
	ensure_dirs
	build_package_image
	rm -f "${ZIP_PATH}"
	docker run --rm \
		--user "$(id -u):$(id -g)" \
		--volume "${PARENT}:/src:ro" \
		--volume "${BUILD_DIR}:/out" \
		"${PACKAGE_IMAGE}" \
		sh -lc "cd /src && zip -qr '/out/${SLUG}-${VERSION}.zip' '${SLUG}' \
			-x '${SLUG}/.git' '${SLUG}/.git/*' \
			-x '${SLUG}/.github' '${SLUG}/.github/*' \
			-x '${SLUG}/.wordpress-org' '${SLUG}/.wordpress-org/*' \
			-x '${SLUG}/.wporg-build' '${SLUG}/.wporg-build/*' \
			-x '${SLUG}/.phpstan-cache' '${SLUG}/.phpstan-cache/*' '${SLUG}/.phpunit.result.cache' \
			-x '${SLUG}/build' '${SLUG}/build/*' \
			-x '${SLUG}/tools' '${SLUG}/tools/*' \
			-x '${SLUG}/vendor' '${SLUG}/vendor/*' \
			-x '${SLUG}/node_modules' '${SLUG}/node_modules/*' \
			-x '${SLUG}/tests' '${SLUG}/tests/*' \
			-x '${SLUG}/tmp' '${SLUG}/tmp/*' \
			-x '${SLUG}/marketing' '${SLUG}/marketing/*' '${SLUG}/marketing/**' \
			-x '${SLUG}/README*' '${SLUG}/**/README*' \
			-x '${SLUG}/CONTRIBUTING*' '${SLUG}/**/CONTRIBUTING*' \
			-x '${SLUG}/AGENTS*' '${SLUG}/**/AGENTS*' \
			-x '${SLUG}/*.md' '${SLUG}/**/*.md' \
			-x '${SLUG}/*.markdown' '${SLUG}/**/*.markdown' \
			-x '${SLUG}/*.txt' '${SLUG}/**/*.txt' \
			-x '${SLUG}/.env' '${SLUG}/.env.*' '${SLUG}/**/.env' '${SLUG}/**/.env.*' \
			-x '${SLUG}/*.pem' '${SLUG}/**/*.pem' '${SLUG}/*.key' '${SLUG}/**/*.key' \
			-x '${SLUG}/*.crt' '${SLUG}/**/*.crt' '${SLUG}/*.sql' '${SLUG}/**/*.sql' \
			-x '${SLUG}/*.sqlite' '${SLUG}/**/*.sqlite' '${SLUG}/*.bak' '${SLUG}/**/*.bak' \
			-x '${SLUG}/*.orig' '${SLUG}/**/*.orig' '${SLUG}/*.swp' '${SLUG}/**/*.swp' \
			-x '${SLUG}/.DS_Store' '${SLUG}/**/.DS_Store' \
			-x '${SLUG}/.gitignore' '${SLUG}/.distignore' '${SLUG}/phpcs.xml.dist' \
			-x '${SLUG}/phpstan.neon.dist' '${SLUG}/phpunit.xml.dist' '${SLUG}/composer.json' '${SLUG}/composer.lock' '${SLUG}/justfile' \
			-x '${SLUG}/*.log' '${SLUG}/**/*.log' '${SLUG}/*.zip' '${SLUG}/**/*.zip' && \
			zip -q '/out/${SLUG}-${VERSION}.zip' '${SLUG}/readme.txt' '${SLUG}/changelog.txt'"
	docker run --rm \
		--volume "${BUILD_DIR}:/out:ro" \
		"${PACKAGE_IMAGE}" \
		unzip -t "/out/${SLUG}-${VERSION}.zip"
}

write_compose_file() {
	cat > "${DOCKER_DIR}/compose.yml" <<YAML
services:
  db:
    image: ${MARIADB_IMAGE}
    environment:
      MYSQL_DATABASE: wordpress
      MYSQL_USER: wordpress
      MYSQL_PASSWORD: wordpress
      MYSQL_ROOT_PASSWORD: wordpress
    tmpfs:
      - /var/lib/mysql

  wordpress:
    image: ${WORDPRESS_IMAGE}
    depends_on:
      - db
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_NAME: wordpress
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: wordpress
    volumes:
      - wp_data:/var/www/html
      - ${CHECK_PLUGIN_ROOT}:/var/www/html/wp-content/plugins/${SLUG}:ro

  cli:
    image: ${WORDPRESS_CLI_IMAGE}
    user: "0:0"
    depends_on:
      - db
      - wordpress
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_NAME: wordpress
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: wordpress
    volumes:
      - wp_data:/var/www/html
      - ${CHECK_PLUGIN_ROOT}:/var/www/html/wp-content/plugins/${SLUG}:ro
      - ${ROOT}/tools:/review-tools:ro

volumes:
  wp_data:
YAML
}

wait_for_wordpress_files() {
	for _ in $(seq 1 90); do
		if docker_compose exec -T wordpress test -f /var/www/html/wp-config.php >/dev/null 2>&1; then
			return 0
		fi
		sleep 2
	done

	echo "WordPress files were not initialized in time." >&2
	return 1
}

wp_cli() {
	docker_compose run --rm cli wp "$@" --allow-root
}

install_wordpress() {
	docker_compose up -d db wordpress
	wait_for_wordpress_files

	for _ in $(seq 1 60); do
		if wp_cli core install \
			--url="http://localhost" \
			--title="Empirical Responsive Images Test" \
			--admin_user="admin" \
			--admin_password="password" \
			--admin_email="admin@example.test" \
			--skip-email >/dev/null 2>&1; then
			return 0
		fi
		sleep 2
	done

	echo "WordPress install failed inside Docker." >&2
	return 1
}

configure_debug_mode() {
	wp_cli config set WP_DEBUG true --raw
	wp_cli config set WP_DEBUG_LOG true --raw
	wp_cli config set WP_DEBUG_DISPLAY false --raw
	wp_cli config set SCRIPT_DEBUG true --raw
}

run_authorization_smoke() {
	echo "==> Authorization and observer smoke"
	wp_cli eval "if ( file_exists( WP_CONTENT_DIR . '/debug.log' ) ) { unlink( WP_CONTENT_DIR . '/debug.log' ); }"
	wp_cli eval-file /review-tools/wporg-smoke.php | tee "${BUILD_DIR}/authorization-smoke.txt"

	if ! docker_compose exec -T wordpress test ! -s /var/www/html/wp-content/debug.log; then
		docker_compose exec -T wordpress cat /var/www/html/wp-content/debug.log
		echo "WP_DEBUG log is not empty after smoke." >&2
		return 1
	fi

	echo "WP_DEBUG log is empty." | tee "${BUILD_DIR}/debug-log.txt"
}

run_wp_check() {
	echo "==> WordPress Plugin Check in Docker"
	ensure_dirs
	build_zip
	rm -rf "${DOCKER_DIR}/plugin"
	mkdir -p "${DOCKER_DIR}/plugin"
	unzip -q "${ZIP_PATH}" -d "${DOCKER_DIR}/plugin"
	write_compose_file

	set +e
	docker_compose down -v --remove-orphans >/dev/null 2>&1
	set -e

	trap 'docker_compose down -v --remove-orphans >/dev/null 2>&1 || true' EXIT
	install_wordpress
	configure_debug_mode

	wp_cli core version | tee "${BUILD_DIR}/wordpress-version.txt"
	wp_cli plugin install plugin-check --activate
	wp_cli plugin activate "${SLUG}"
	wp_cli plugin list --fields=name,status,version | tee "${BUILD_DIR}/plugin-list.txt"

	set +e
	docker_compose run --rm cli wp plugin check "${SLUG}" --mode=new --include-experimental --format=strict-table --allow-root > "${BUILD_DIR}/plugin-check.txt" 2>&1
	status=$?
	set -e

	cat "${BUILD_DIR}/plugin-check.txt"
	if [ "${status}" -ne 0 ]; then
		return "${status}"
	fi

	# Plugin Check can report findings while returning status zero.
	if awk -F '\t' '$3 == "ERROR" || $3 == "WARNING" { found = 1 } END { exit !found }' "${BUILD_DIR}/plugin-check.txt"; then
		echo "Plugin Check reported unresolved findings." >&2
		return 1
	fi

	# Reproduce the first request after an in-place upgrade, with a cache API
	# available early but unsafe until WordPress has finished booting.
	wp_cli eval "wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( '/review-tools/wporg-upgrade-probe.php', WPMU_PLUGIN_DIR . '/eri-review-upgrade-probe.php' ); update_option( 'empirical_responsive_images_version', '0.3.8' );"
	wp_cli eval "if ( '0.3.8' !== get_option( 'eri_review_upgrade_purge' ) || EMPIRICAL_RESPONSIVE_IMAGES_VERSION !== get_option( 'empirical_responsive_images_version' ) ) { WP_CLI::error( 'Upgrade lifecycle probe failed.' ); } unlink( WPMU_PLUGIN_DIR . '/eri-review-upgrade-probe.php' ); delete_option( 'eri_review_upgrade_purge' ); WP_CLI::success( 'Upgrade purge waited for wp_loaded and version was recorded afterward.' );"
	run_authorization_smoke

	echo "Readme Tested up to: ${TESTED_UP_TO}" | tee "${BUILD_DIR}/tested-up-to.txt"
}

build_svn_tree() {
	echo "==> Build SVN-ready tree"
	ensure_dirs
	if [ ! -f "${ZIP_PATH}" ]; then
		build_zip
	fi

	rm -rf "${SVN_DIR}" "${BUILD_DIR}/svn-tmp"
	mkdir -p "${SVN_DIR}/trunk" "${SVN_DIR}/tags/${VERSION}" "${SVN_DIR}/assets" "${BUILD_DIR}/svn-tmp"
	unzip -q "${ZIP_PATH}" -d "${BUILD_DIR}/svn-tmp"
	cp -R "${BUILD_DIR}/svn-tmp/${SLUG}/." "${SVN_DIR}/trunk/"
	cp -R "${BUILD_DIR}/svn-tmp/${SLUG}/." "${SVN_DIR}/tags/${VERSION}/"
	cp "${ROOT}/.wordpress-org"/banner-*.png "${SVN_DIR}/assets/"
	cp "${ROOT}/.wordpress-org"/icon-*.png "${SVN_DIR}/assets/"
	cp "${ROOT}/.wordpress-org"/screenshot-*.png "${SVN_DIR}/assets/"
	rm -rf "${BUILD_DIR}/svn-tmp"
	find "${SVN_DIR}" -maxdepth 3 -type f | sort
}

cmd="${1:-all}"
case "${cmd}" in
	all)
		run_quality_checks
		lint_php
		build_zip
		run_wp_check
		build_svn_tree
		;;
	lint)
		lint_php
		;;
	package)
		build_zip
		;;
	wp-check)
		run_wp_check
		;;
	svn)
		build_svn_tree
		;;
	-h|--help|help)
		usage
		;;
	*)
		usage >&2
		exit 2
		;;
esac
