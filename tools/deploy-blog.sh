#!/usr/bin/env bash
set -euo pipefail

SLUG="empirical-responsive-images"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SSH_ALIAS="ovh"
SITE_OWNER="blogw5365"
SITE_ROOT="/home/blog.wppostai.com/public_html"
PLUGIN_ROOT="${SITE_ROOT}/wp-content/plugins/${SLUG}"
BACKUP_ROOT="/home/blog.wppostai.com/backups"
WP_CLI="/usr/local/bin/wp"
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
