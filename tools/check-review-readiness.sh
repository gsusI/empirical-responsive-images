#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE_PATHS=(
	"${ROOT}/empirical-responsive-images.php"
	"${ROOT}/includes"
	"${ROOT}/uninstall.php"
)
failed=0

reject_matches() {
	local description="$1"
	local pattern="$2"

	if rg -n -P "${pattern}" "${SOURCE_PATHS[@]}"; then
		echo "Review readiness failed: ${description}" >&2
		failed=1
	fi
}

require_literal() {
	local description="$1"
	local literal="$2"
	local path="$3"

	if ! rg -q -F "${literal}" "${path}"; then
		echo "Review readiness failed: ${description}" >&2
		failed=1
	fi
}

reject_matches "public REST permission callback found" "['\"]permission_callback['\"]\s*=>\s*['\"]__return_true['\"]"
reject_matches "SiteGround purge incorrectly dispatched as an action" "do_action\(\s*['\"]sg_cachepress_purge_"
reject_matches "unprefixed literal option name found" "\b(?:add|get|update|delete)_option\(\s*['\"](?!empirical_responsive_images_)"
reject_matches "unprefixed literal transient name found" "\b(?:get|set|delete)_transient\(\s*['\"](?!empirical_responsive_images_)"
reject_matches "unexpected custom hook name found" "\b(?:do_action|apply_filters)\(\s*['\"](?!(?:empirical_responsive_images_|litespeed_|breeze_))"
reject_matches "three-letter ERI identifier found in shipped PHP" "(?:['\"]eri[-_]|data-eri-|<!--eri-)"

require_literal "observation route must use capability callback" "'permission_callback' => array( \$this, 'can_manage' )" "${ROOT}/includes/class-rest-controller.php"
require_literal "observer must require management capability" "current_user_can( 'manage_options' )" "${ROOT}/includes/class-image-sizes.php"
require_literal "observer must send REST nonce" "'X-WP-Nonce': nonce" "${ROOT}/assets/js/observer.js"
require_literal "SiteGround URL purge must use its public function" "sg_cachepress_purge_cache( \$url );" "${ROOT}/includes/class-cache-compat.php"
require_literal "SiteGround full purge must use its public function" "sg_cachepress_purge_everything();" "${ROOT}/includes/class-cache-compat.php"

if rg -n -F "navigator.sendBeacon" "${ROOT}/assets/js/observer.js"; then
	echo "Review readiness failed: sendBeacon cannot attach the required REST nonce header" >&2
	failed=1
fi

if [ "${failed}" -ne 0 ]; then
	exit 1
fi

echo "Review readiness checks passed."
