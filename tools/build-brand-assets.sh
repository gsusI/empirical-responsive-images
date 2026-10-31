#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
brand_dir="${project_root}/marketing/source/brand"
output_dir="${project_root}/marketing/exports"
wporg_assets_dir="${project_root}/.wordpress-org"
chrome="${CHROME_BIN:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"

for dependency in magick pngquant rsvg-convert; do
	if ! command -v "${dependency}" >/dev/null 2>&1; then
		echo "Missing dependency: ${dependency}" >&2
		exit 1
	fi
done

if [[ ! -x "${chrome}" ]]; then
	echo "Missing Chrome: set CHROME_BIN" >&2
	exit 1
fi

mkdir -p "${output_dir}" "${wporg_assets_dir}"
work_dir="$(mktemp -d "${TMPDIR:-/tmp}/eri-brand.XXXXXX")"
trap 'rm -rf "${work_dir}"' EXIT

# Renders one page at twice its size, so downscales stay crisp.
render() {
	local page="$1"
	local width="$2"
	local height="$3"

	"${chrome}" --headless=new --disable-gpu --hide-scrollbars \
		--force-device-scale-factor=2 \
		--window-size="${width},${height}" \
		--user-data-dir="${work_dir}/chrome-${page}" \
		--screenshot="${work_dir}/${page}.png" \
		"file://${brand_dir}/${page}.html" >/dev/null 2>&1
}

# Writes an exact-size PNG with a reduced palette.
export_png() {
	local source_image="$1"
	local size="$2"
	local target="$3"

	magick "${source_image}" -filter Lanczos -resize "${size}!" -strip "${target}"
	pngquant --force --skip-if-larger --quality=80-98 --strip --output "${target}" "${target}" || true
	chmod 0644 "${target}"
}

render banner 1544 500
render autopilot 1600 900
render billboard 1200 1200
render missing 1080 1350
rsvg-convert --width 1024 --height 1024 "${brand_dir}/icon.svg" --output "${work_dir}/icon.png"

export_png "${work_dir}/banner.png" 1544x500 "${wporg_assets_dir}/banner-1544x500.png"
export_png "${work_dir}/banner.png" 772x250 "${wporg_assets_dir}/banner-772x250.png"
export_png "${work_dir}/icon.png" 256x256 "${wporg_assets_dir}/icon-256x256.png"
export_png "${work_dir}/icon.png" 128x128 "${wporg_assets_dir}/icon-128x128.png"
export_png "${work_dir}/autopilot.png" 1600x900 "${output_dir}/activate-it-forget-it.png"
export_png "${work_dir}/billboard.png" 1200x1200 "${output_dir}/guerrilla-billboard-to-a-phone.png"
export_png "${work_dir}/missing.png" 1080x1350 "${output_dir}/guerrilla-missing-poster.png"

cp "${output_dir}/activate-it-forget-it.png" "${wporg_assets_dir}/screenshot-1.png"
cp "${output_dir}/guerrilla-billboard-to-a-phone.png" "${wporg_assets_dir}/screenshot-2.png"
cp "${output_dir}/guerrilla-missing-poster.png" "${wporg_assets_dir}/screenshot-3.png"

echo "Created brand assets."
