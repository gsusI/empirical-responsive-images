#!/usr/bin/env bash
set -euo pipefail

SLUG="empirical-responsive-images"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEPLOY_ENV="${ROOT}/.env.deploy"

if [[ ! -f "${DEPLOY_ENV}" ]]; then
	echo "Missing .env.deploy. Copy .env.deploy.example and describe your test site." >&2
	exit 1
fi

# shellcheck source=/dev/null
source "${DEPLOY_ENV}"

SSH_ALIAS="${DEPLOY_SSH_ALIAS:?Set DEPLOY_SSH_ALIAS in .env.deploy}"
SITE_OWNER="${DEPLOY_SITE_OWNER:?Set DEPLOY_SITE_OWNER in .env.deploy}"
SITE_ROOT="${DEPLOY_SITE_ROOT:?Set DEPLOY_SITE_ROOT in .env.deploy}"
PLUGIN_ROOT="${SITE_ROOT}/wp-content/plugins/${SLUG}"
BACKUP_ROOT="${DEPLOY_BACKUP_ROOT:?Set DEPLOY_BACKUP_ROOT in .env.deploy}"
WP_CLI="${DEPLOY_WP_CLI:-/usr/local/bin/wp}"
VERSION="$(awk -F': ' '/^[[:space:]]*\* Version:/{ print $2; exit }' "${ROOT}/${SLUG}.php")"
BACKUP_TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP_PATH="${BACKUP_ROOT}/${SLUG}-pre-${VERSION}-${BACKUP_TIMESTAMP}.tar.gz"

if [[ -z "${VERSION}" ]]; then
	echo "Plugin version missing." >&2
	exit 1
fi

"${ROOT}/tools/check-version-sync.sh"

echo "==> Preflight ${SSH_ALIAS}:${PLUGIN_ROOT}"
ssh "${SSH_ALIAS}" \
	"test -f '${SITE_ROOT}/wp-config.php' &&
	command -v rsync >/dev/null &&
	sudo -n -u '${SITE_OWNER}' test -d '${SITE_ROOT}/wp-content/plugins' &&
	sudo -n -u '${SITE_OWNER}' '${WP_CLI}' --path='${SITE_ROOT}' core is-installed"

echo "==> Backup ${BACKUP_PATH}"
ssh "${SSH_ALIAS}" \
	"sudo -n mkdir -p '${BACKUP_ROOT}' &&
	if sudo -n test -d '${PLUGIN_ROOT}'; then
		sudo -n tar -C '${SITE_ROOT}/wp-content/plugins' -czf '${BACKUP_PATH}' '${SLUG}';
	fi"

echo "==> Rsync ${SLUG} ${VERSION}"
rsync \
	--recursive \
	--links \
	--perms \
	--times \
	--compress \
	--delete \
	--delete-delay \
	--delete-excluded \
	--delay-updates \
	--chmod=D755,F644 \
	--include='/assets/***' \
	--include='/includes/***' \
	--include='/languages/***' \
	--include='/*.php' \
	--include='/readme.txt' \
	--include='/changelog.txt' \
	--exclude='*' \
	--rsync-path="sudo -n -u ${SITE_OWNER} rsync" \
	"${ROOT}/" \
	"${SSH_ALIAS}:${PLUGIN_ROOT}/"

echo "==> Activate and verify"
ssh "${SSH_ALIAS}" \
	"sudo -n -u '${SITE_OWNER}' '${WP_CLI}' --path='${SITE_ROOT}' plugin activate '${SLUG}' --quiet &&
	deployed_version=\$(sudo -n -u '${SITE_OWNER}' '${WP_CLI}' --path='${SITE_ROOT}' plugin get '${SLUG}' --field=version) &&
	test \"\${deployed_version}\" = '${VERSION}' &&
	printf 'Deployed %s %s\\n' '${SLUG}' \"\${deployed_version}\""

echo "Rollback: ${SSH_ALIAS}:${BACKUP_PATH}"
