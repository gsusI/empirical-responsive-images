#!/usr/bin/env bash
set -euo pipefail

SLUG="empirical-responsive-images"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(awk -F': ' '/^[[:space:]]*\* Version:/{ print $2; exit }' "${ROOT}/${SLUG}.php")"
SVN_SOURCE="${ROOT}/build/wporg/svn"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
SVN_REALM="<https://plugins.svn.wordpress.org:443> Use your WordPress.org login"
SVN_USERNAME="${WPORG_SVN_USERNAME:-jesusinica}"
SVN_BINARY="$(command -v svn)"

setup_keychain_credential() {
	local setup_binary
	local temporary_parent

	temporary_parent="${TMPDIR:-/tmp}"
	setup_binary="$(mktemp "${temporary_parent%/}/wporg-keychain-setup.XXXXXX")"

	cat <<MESSAGE
One-time WordPress.org SVN setup.

macOS asks once before this helper reads the existing Keychain item. Choose
Allow. It may then ask for the Mac login password once to replace the stale app
permission. The WordPress.org password is never shown, typed, printed, copied,
or placed in a command argument.

Trusted SVN binary: ${SVN_BINARY}
MESSAGE

	if ! clang \
		-Wall \
		-Wextra \
		-Werror \
		-Wno-deprecated-declarations \
		-framework Security \
		-framework CoreFoundation \
		"${ROOT}/tools/wporg-keychain-setup.c" \
		-o "${setup_binary}"; then
		rm -f -- "${setup_binary}"
		return 1
	fi

	if ! "${setup_binary}" "${SVN_REALM}" "${SVN_USERNAME}" "${SVN_BINARY}"; then
		rm -f -- "${setup_binary}"
		return 1
	fi
	rm -f -- "${setup_binary}"

	echo "Saved WordPress.org SVN credential for ${SVN_USERNAME} and trusted ${SVN_BINARY}."
}

check_keychain_credential() {
	local auth_status

	auth_status="$(svn auth)"

	if ! printf '%s\n' "${auth_status}" | grep -Fq "Authentication realm: ${SVN_REALM}" \
		|| ! printf '%s\n' "${auth_status}" | grep -Fq 'Password cache: keychain' \
		|| ! printf '%s\n' "${auth_status}" | grep -Fq "Username: ${SVN_USERNAME}"; then
		cat >&2 <<MESSAGE
No matching WordPress.org SVN credential found in macOS Keychain.

Run the one-time Keychain permission repair, then rerun this check:

  just publish-auth-setup
  just publish-auth-check

The repair changes only which SVN binary may read the saved password. The
password is never shown, typed, printed, copied, or placed in a command argument.
MESSAGE
		return 1
	fi

	echo "WordPress.org SVN credential: ${SVN_USERNAME}, macOS Keychain."
}

if [[ "${1:-}" == "--setup-auth" ]]; then
	setup_keychain_credential
	exit 0
fi

if [[ "${1:-}" == "--check-auth" ]]; then
	check_keychain_credential
	exit 0
fi

check_keychain_credential

if [[ ! -d "${SVN_SOURCE}/trunk" || ! -d "${SVN_SOURCE}/tags/${VERSION}" || ! -d "${SVN_SOURCE}/assets" ]]; then
	echo "Missing validated SVN tree for ${VERSION}. Run: just release-check" >&2
	exit 1
fi

remote_tags="$(svn ls "${SVN_URL}/tags/" --non-interactive)"
if printf '%s\n' "${remote_tags}" | grep -Fxq "${VERSION}/"; then
	echo "WordPress.org tag ${VERSION} already exists. Released tags are immutable." >&2
	exit 1
fi

temporary_parent="${TMPDIR:-/tmp}"
publish_directory="$(mktemp -d "${temporary_parent%/}/${SLUG}-wporg-publish.${VERSION}.XXXXXX")"
checkout_directory="${publish_directory}/svn"

cleanup() {
	case "${publish_directory}" in
		"${temporary_parent%/}/${SLUG}-wporg-publish.${VERSION}."*)
			rm -rf -- "${publish_directory}"
			;;
	esac
}
trap cleanup EXIT

echo "Checking out fresh WordPress.org SVN working copy."
svn checkout --quiet "${SVN_URL}" "${checkout_directory}"

rsync -a --delete "${SVN_SOURCE}/trunk/" "${checkout_directory}/trunk/"
rsync -a --delete "${SVN_SOURCE}/assets/" "${checkout_directory}/assets/"

while IFS= read -r missing_path; do
	[[ -n "${missing_path}" ]] && svn delete --force "${missing_path}" >/dev/null
done < <(svn status "${checkout_directory}/trunk" "${checkout_directory}/assets" | sed -n 's/^! *//p')

svn add --force "${checkout_directory}/trunk" "${checkout_directory}/assets" >/dev/null

while IFS= read -r image_path; do
	svn propset svn:mime-type image/png "${image_path}" >/dev/null
done < <(find "${checkout_directory}/assets" -maxdepth 1 -type f -name '*.png' -print)

svn copy "${checkout_directory}/trunk" "${checkout_directory}/tags/${VERSION}" >/dev/null

diff -qr "${SVN_SOURCE}/tags/${VERSION}" "${checkout_directory}/tags/${VERSION}"

echo "SVN changes ready:"
svn status "${checkout_directory}"

echo "Publishing ${SLUG} ${VERSION} with SVN's macOS Keychain credential."
svn commit "${checkout_directory}" \
	--message "Release ${VERSION}" \
	--username "${SVN_USERNAME}" \
	--non-interactive

svn info "${SVN_URL}/tags/${VERSION}" --show-item revision
