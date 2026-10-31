#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source_dir="${project_root}/marketing/source"
output_dir="${project_root}/marketing/exports"
wporg_assets_dir="${project_root}/.wordpress-org"
before_source="${source_dir}/lighthouse-before-mobile.png"
after_source="${source_dir}/lighthouse-after-mobile.png"
queue_source="${source_dir}/admin-automatic-learning-queue.png"
sizes_source="${source_dir}/admin-registered-empirical-sizes.png"
assets_source="${source_dir}/admin-observed-local-assets.png"
summary_source="${source_dir}/admin-observed-image-slots-summary.png"
font_regular="/System/Library/Fonts/SFNS.ttf"
font_rounded="/System/Library/Fonts/SFNSRounded.ttf"

for dependency in magick; do
	if ! command -v "${dependency}" >/dev/null 2>&1; then
		echo "Missing dependency: ${dependency}" >&2
		exit 1
	fi
done

for source_image in \
	"${before_source}" \
	"${after_source}" \
	"${queue_source}" \
	"${sizes_source}" \
	"${assets_source}" \
	"${summary_source}"; do
	if [[ ! -f "${source_image}" ]]; then
		echo "Missing source screenshot: ${source_image}" >&2
		exit 1
	fi
done

mkdir -p "${output_dir}" "${wporg_assets_dir}"
work_dir="$(mktemp -d "${TMPDIR:-/tmp}/eri-promo.XXXXXX")"
trap 'rm -rf "${work_dir}"' EXIT

ink="#172033"
muted="#667085"
blue="#3858e9"
green="#22b866"
green_dark="#118443"
green_soft="#e9f9f0"
orange="#f59e0b"
orange_dark="#c66a00"
orange_soft="#fff4df"
canvas="#f6f8fc"
card="#ffffff"
border="#d9e0ea"

magick "${before_source}" -resize '640x520>' -bordercolor "${border}" -border 1 "${work_dir}/before-wide.png"
magick "${after_source}" -resize '640x520>' -bordercolor "${border}" -border 1 "${work_dir}/after-wide.png"
magick "${before_source}" -resize '420x350>' -bordercolor "${border}" -border 1 "${work_dir}/before-square.png"
magick "${after_source}" -resize '420x350>' -bordercolor "${border}" -border 1 "${work_dir}/after-square.png"
magick "${after_source}" -resize '720x650>' -bordercolor "${border}" -border 1 "${work_dir}/after-feature.png"
magick "${queue_source}" -resize '1450x720>' -bordercolor "${border}" -border 1 "${work_dir}/queue-feature.png"
magick "${sizes_source}" -resize '1450x720>' -bordercolor "${border}" -border 1 "${work_dir}/sizes-feature.png"
magick "${assets_source}" -resize '1450x720>' -bordercolor "${border}" -border 1 "${work_dir}/assets-feature.png"
magick "${summary_source}" -resize '1450x720>' -bordercolor "${border}" -border 1 "${work_dir}/summary-feature.png"
magick "${summary_source}" -resize '960x420>' -bordercolor "${border}" -border 1 "${work_dir}/summary-square.png"

# Wide, evidence-first before/after graphic.
magick -size 1600x900 "xc:${canvas}" \
	-fill "${blue}" -draw 'roundrectangle 62,48 402,86 19,19' \
	-font "${font_regular}" -pointsize 18 -fill white -draw "text 84,74 'EMPIRICAL RESPONSIVE IMAGES'" \
	-font "${font_rounded}" -pointsize 58 -fill "${ink}" -draw "text 62,154 'Mobile performance: 75 → 99'" \
	-font "${font_regular}" -pointsize 25 -fill "${muted}" -draw "text 64,198 'LCP improved from 7.2s to 2.3s on this test page'" \
	-fill "${card}" -stroke "${border}" -strokewidth 2 -draw 'roundrectangle 54,238 776,842 26,26' \
	-fill "${card}" -stroke "${green}" -strokewidth 3 -draw 'roundrectangle 824,238 1546,842 26,26' \
	-stroke none -fill "${orange_soft}" -draw 'roundrectangle 88,264 222,306 21,21' \
	-font "${font_regular}" -pointsize 19 -fill "${orange_dark}" -draw "text 112,292 'BEFORE'" \
	-fill "${green_soft}" -draw 'roundrectangle 858,264 978,306 21,21' \
	-font "${font_regular}" -pointsize 19 -fill "${green_dark}" -draw "text 882,292 'AFTER'" \
	-font "${font_rounded}" -pointsize 33 -fill "${orange_dark}" -draw "text 646,294 '75'" \
	-font "${font_rounded}" -pointsize 33 -fill "${green_dark}" -draw "text 1416,294 '99'" \
	"${work_dir}/before-wide.png" -geometry +95+326 -composite \
	"${work_dir}/after-wide.png" -geometry +865+326 -composite \
	-font "${font_rounded}" -pointsize 42 -fill "${blue}" -draw "text 782,550 '→'" \
	-font "${font_regular}" -pointsize 17 -fill "${muted}" -draw "text 56,878 'Observed on one page. Lighthouse scores vary by run and environment.'" \
	"${output_dir}/lighthouse-before-after-wide.png"

# Square social proof graphic with readable metrics and full screenshots.
magick -size 1080x1080 "xc:${canvas}" \
	-fill "${blue}" -draw 'roundrectangle 56,48 396,86 19,19' \
	-font "${font_regular}" -pointsize 18 -fill white -draw "text 77,74 'EMPIRICAL RESPONSIVE IMAGES'" \
	-font "${font_rounded}" -pointsize 72 -fill "${ink}" -draw "text 54,174 '75 → 99'" \
	-font "${font_regular}" -pointsize 27 -fill "${muted}" -draw "text 58,217 'Mobile Lighthouse performance'" \
	-fill "${card}" -stroke "${border}" -strokewidth 2 -draw 'roundrectangle 48,270 518,728 24,24' \
	-fill "${card}" -stroke "${green}" -strokewidth 3 -draw 'roundrectangle 562,270 1032,728 24,24' \
	-stroke none -fill "${orange_soft}" -draw 'roundrectangle 72,292 188,332 20,20' \
	-font "${font_regular}" -pointsize 18 -fill "${orange_dark}" -draw "text 94,319 'BEFORE'" \
	-fill "${green_soft}" -draw 'roundrectangle 586,292 690,332 20,20' \
	-font "${font_regular}" -pointsize 18 -fill "${green_dark}" -draw "text 608,319 'AFTER'" \
	"${work_dir}/before-square.png" -geometry +73+352 -composite \
	"${work_dir}/after-square.png" -geometry +587+352 -composite \
	-font "${font_rounded}" -pointsize 36 -fill "${orange_dark}" -draw "text 74,786 'LCP 7.2s'" \
	-font "${font_rounded}" -pointsize 36 -fill "${green_dark}" -draw "text 588,786 'LCP 2.3s'" \
	-font "${font_rounded}" -pointsize 43 -fill "${ink}" -draw "text 54,874 'Images sized by evidence.'" \
	-font "${font_regular}" -pointsize 24 -fill "${muted}" -draw "text 56,922 'Learn real visitor widths. Confirm demand.'" \
	-draw "text 56,957 'Generate matching thumbnails in background.'" \
	-font "${font_regular}" -pointsize 16 -fill "${muted}" -draw "text 56,1030 'Observed on one page. Lighthouse scores vary.'" \
	"${output_dir}/lighthouse-proof-square.png"

# Product-value card built around the successful Lighthouse screenshot.
magick -size 1400x900 "xc:${canvas}" \
	-fill "${blue}" -draw 'roundrectangle 64,58 404,98 20,20' \
	-font "${font_regular}" -pointsize 19 -fill white -draw "text 87,85 'EMPIRICAL RESPONSIVE IMAGES'" \
	-font "${font_rounded}" -pointsize 57 -fill "${ink}" -draw "text 64,192 'Images sized'" \
	-draw "text 64,254 'by evidence.'" \
	-font "${font_regular}" -pointsize 27 -fill "${muted}" -draw "text 66,324 'Learn real visitor widths.'" \
	-draw "text 66,365 'Confirm repeated demand.'" \
	-draw "text 66,406 'Generate thumbnails in background.'" \
	-fill "${green_soft}" -draw 'roundrectangle 64,468 430,604 26,26' \
	-font "${font_rounded}" -pointsize 66 -fill "${green_dark}" -draw "text 92,548 '99'" \
	-font "${font_regular}" -pointsize 19 -fill "${green_dark}" -draw "text 210,520 'MOBILE'" \
	-draw "text 210,550 'PERFORMANCE'" \
	-fill "${card}" -stroke "${green}" -strokewidth 3 -draw 'roundrectangle 562,72 1338,770 28,28' \
	"${work_dir}/after-feature.png" -gravity northwest -geometry +590+102 -composite \
	-stroke none -font "${font_regular}" -pointsize 18 -fill "${muted}" -draw "text 66,836 'Observed on one page. Lighthouse scores vary by run and environment.'" \
	"${output_dir}/plugin-performance-feature.png"

# Admin feature: local assets observed by real browsers.
magick -size 1600x1100 "xc:${canvas}" \
	-fill "${blue}" -draw 'roundrectangle 58,48 398,88 20,20' \
	-font "${font_regular}" -pointsize 19 -fill white -draw "text 81,75 'EMPIRICAL RESPONSIVE IMAGES'" \
	-font "${font_rounded}" -pointsize 55 -fill "${ink}" -draw "text 58,168 'Observe real image demand'" \
	-font "${font_regular}" -pointsize 25 -fill "${muted}" -draw "text 60,213 'See which local assets, widths, slots, and visits supplied the evidence.'" \
	-fill "${card}" -stroke "${border}" -strokewidth 2 -draw 'roundrectangle 48,254 1552,1044 28,28' \
	"${work_dir}/assets-feature.png" -gravity northwest -geometry +267+292 -composite \
	"${output_dir}/admin-observed-assets-feature.png"

# Admin feature: repeated gaps entering the automatic background queue.
magick -size 1600x1100 "xc:${canvas}" \
	-fill "${blue}" -draw 'roundrectangle 58,48 398,88 20,20' \
	-font "${font_regular}" -pointsize 19 -fill white -draw "text 81,75 'EMPIRICAL RESPONSIVE IMAGES'" \
	-font "${font_rounded}" -pointsize 55 -fill "${ink}" -draw "text 58,168 'Confirm demand automatically'" \
	-font "${font_regular}" -pointsize 25 -fill "${muted}" -draw "text 60,213 'Repeated gaps become bounded background jobs. Page response stays free.'" \
	-fill "${card}" -stroke "${border}" -strokewidth 2 -draw 'roundrectangle 48,254 1552,1044 28,28' \
	"${work_dir}/queue-feature.png" -gravity northwest -geometry +198+330 -composite \
	"${output_dir}/admin-learning-queue-feature.png"

# Admin feature: evidence-backed image sizes registered with WordPress.
magick -size 1600x1100 "xc:${canvas}" \
	-fill "${blue}" -draw 'roundrectangle 58,48 398,88 20,20' \
	-font "${font_regular}" -pointsize 19 -fill white -draw "text 81,75 'EMPIRICAL RESPONSIVE IMAGES'" \
	-font "${font_rounded}" -pointsize 55 -fill "${ink}" -draw "text 58,168 'Generate only proven sizes'" \
	-font "${font_regular}" -pointsize 25 -fill "${muted}" -draw "text 60,213 'Confirmed widths become registered image sizes with their evidence visible.'" \
	-fill "${card}" -stroke "${green}" -strokewidth 3 -draw 'roundrectangle 48,254 1552,1044 28,28' \
	"${work_dir}/sizes-feature.png" -gravity northwest -geometry +228+292 -composite \
	"${output_dir}/admin-registered-sizes-feature.png"

# Admin overview: privacy-conscious observation totals and format support.
magick -size 1600x900 "xc:${canvas}" \
	-fill "${blue}" -draw 'roundrectangle 58,48 398,88 20,20' \
	-font "${font_regular}" -pointsize 19 -fill white -draw "text 81,75 'EMPIRICAL RESPONSIVE IMAGES'" \
	-font "${font_rounded}" -pointsize 55 -fill "${ink}" -draw "text 58,168 'Measure image demand, not people'" \
	-font "${font_regular}" -pointsize 25 -fill "${muted}" -draw "text 60,213 'Signed local targets, fixed width buckets, privacy-conscious reports.'" \
	-fill "${card}" -stroke "${green}" -strokewidth 3 -draw 'roundrectangle 48,254 1552,844 28,28' \
	"${work_dir}/summary-feature.png" -gravity northwest -geometry +199+330 -composite \
	"${output_dir}/admin-observed-slots-summary-feature.png"

# Square evidence summary with exact totals from the supplied admin screenshot.
magick -size 1080x1080 "xc:${canvas}" \
	-fill "${blue}" -draw 'roundrectangle 56,48 396,88 20,20' \
	-font "${font_regular}" -pointsize 19 -fill white -draw "text 79,75 'EMPIRICAL RESPONSIVE IMAGES'" \
	-font "${font_rounded}" -pointsize 56 -fill "${ink}" -draw "text 54,170 'Evidence at a glance'" \
	-font "${font_regular}" -pointsize 24 -fill "${muted}" -draw "text 56,214 'Private, evidence-backed image sizing.'" \
	-fill "${card}" -stroke "${border}" -strokewidth 2 -draw 'roundrectangle 48,260 1032,650 24,24' \
	"${work_dir}/summary-square.png" -gravity northwest -geometry +59+290 -composite \
	-fill "${card}" -stroke "${border}" -strokewidth 2 -draw 'roundrectangle 48,690 276,902 22,22' \
	-draw 'roundrectangle 300,690 528,902 22,22' \
	-draw 'roundrectangle 552,690 780,902 22,22' \
	-fill "${card}" -stroke "${green}" -strokewidth 3 -draw 'roundrectangle 804,690 1032,902 22,22' \
	-stroke none -font "${font_rounded}" -pointsize 52 -fill "${ink}" -draw "text 72,782 '927'" \
	-font "${font_regular}" -pointsize 18 -fill "${muted}" -draw "text 72,830 'IMAGE'" \
	-draw "text 72,857 'OBSERVATIONS'" \
	-font "${font_rounded}" -pointsize 52 -fill "${ink}" -draw "text 324,782 '18'" \
	-font "${font_regular}" -pointsize 18 -fill "${muted}" -draw "text 324,830 'REGISTERED'" \
	-draw "text 324,857 'SIZES'" \
	-font "${font_rounded}" -pointsize 52 -fill "${ink}" -draw "text 576,782 '31'" \
	-font "${font_regular}" -pointsize 18 -fill "${muted}" -draw "text 576,830 'LOCAL'" \
	-draw "text 576,857 'ASSETS'" \
	-font "${font_rounded}" -pointsize 52 -fill "${green_dark}" -draw "text 828,782 '9'" \
	-font "${font_regular}" -pointsize 18 -fill "${green_dark}" -draw "text 828,830 'AUTOMATIC'" \
	-draw "text 828,857 'JOBS'" \
	-fill "${green_soft}" -draw 'roundrectangle 296,958 784,1022 32,32' \
	-font "${font_regular}" -pointsize 21 -fill "${green_dark}" -draw "text 351,997 'WebP + AVIF enabled and supported'" \
	"${output_dir}/plugin-evidence-summary-square.png"

cp "${output_dir}/lighthouse-before-after-wide.png" "${wporg_assets_dir}/screenshot-4.png"
cp "${output_dir}/admin-observed-slots-summary-feature.png" "${wporg_assets_dir}/screenshot-5.png"
cp "${output_dir}/admin-observed-assets-feature.png" "${wporg_assets_dir}/screenshot-6.png"
cp "${output_dir}/admin-learning-queue-feature.png" "${wporg_assets_dir}/screenshot-7.png"
cp "${output_dir}/admin-registered-sizes-feature.png" "${wporg_assets_dir}/screenshot-8.png"

chmod 0644 "${source_dir}"/*.png "${output_dir}"/*.png "${wporg_assets_dir}"/screenshot-*.png

echo "Created promo assets:"
find "${output_dir}" -maxdepth 1 -type f -name '*.png' -print | sort
