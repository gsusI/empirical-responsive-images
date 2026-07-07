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
		sh -lc "find /plugin -name '*.php' ! -path '/plugin/build/*' ! -path '/plugin/.wporg-build/*' -print0 | xargs -0 -n1 php -l"
}

build_zip() {
	echo "==> Build release ZIP in Docker"
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
			-x '${SLUG}/build' '${SLUG}/build/*' \
			-x '${SLUG}/tools' '${SLUG}/tools/*' \
			-x '${SLUG}/vendor' '${SLUG}/vendor/*' \
			-x '${SLUG}/node_modules' '${SLUG}/node_modules/*' \
			-x '${SLUG}/tests' '${SLUG}/tests/*' \
			-x '${SLUG}/tmp' '${SLUG}/tmp/*' \
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
			-x '${SLUG}/*.log' '${SLUG}/**/*.log' '${SLUG}/*.zip' '${SLUG}/**/*.zip' && \
			zip -q '/out/${SLUG}-${VERSION}.zip' '${SLUG}/readme.txt'"
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

	wp_cli core version | tee "${BUILD_DIR}/wordpress-version.txt"
	wp_cli plugin install plugin-check --activate
	wp_cli plugin activate "${SLUG}"
	wp_cli plugin list --fields=name,status,version | tee "${BUILD_DIR}/plugin-list.txt"

	set +e
	docker_compose run --rm cli wp plugin check "${SLUG}" --allow-root > "${BUILD_DIR}/plugin-check.txt" 2>&1
	status=$?
	set -e

	cat "${BUILD_DIR}/plugin-check.txt"
	if [ "${status}" -ne 0 ]; then
		return "${status}"
	fi

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
	rm -rf "${BUILD_DIR}/svn-tmp"
	find "${SVN_DIR}" -maxdepth 3 -type f | sort
}

cmd="${1:-all}"
case "${cmd}" in
	all)
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
