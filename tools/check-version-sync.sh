#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN_FILE="${ROOT}/empirical-responsive-images.php"
README_FILE="${ROOT}/readme.txt"
CHANGELOG_FILE="${ROOT}/changelog.txt"

header_version="$(awk -F': ' '/^[[:space:]]*\* Version:/{ print $2; exit }' "${MAIN_FILE}")"
constant_version="$(sed -n "s/^define( 'EMPIRICAL_RESPONSIVE_IMAGES_VERSION', '\([^']*\)' );$/\1/p" "${MAIN_FILE}")"
stable_tag="$(awk -F': ' '/^Stable tag:/{ print $2; exit }' "${README_FILE}")"
changelog_version="$(awk '/^= [0-9]+\.[0-9]+\.[0-9]+( - [0-9-]+)? =/{ print $2; exit }' "${CHANGELOG_FILE}")"

if [[ -z "${header_version}" || "${header_version}" != "${constant_version}" || "${header_version}" != "${stable_tag}" || "${header_version}" != "${changelog_version}" ]]; then
	echo "Version mismatch: header=${header_version:-missing} constant=${constant_version:-missing} stable=${stable_tag:-missing} changelog=${changelog_version:-missing}" >&2
	exit 1
fi

echo "Version metadata synchronized: ${header_version}"
