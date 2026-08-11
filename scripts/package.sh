#!/usr/bin/env bash

set -euo pipefail

PLUGIN_SLUG="od-mcp-bridge"
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-${GITHUB_REF_NAME:-}}"
BUILD_ROOT="${PROJECT_ROOT}/build"
PACKAGE_ROOT="${BUILD_ROOT}/${PLUGIN_SLUG}"

if [[ ! "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Version must use the 0.0.0 format (for example: 1.2.3)." >&2
	exit 1
fi

PLUGIN_VERSION="$(sed -n 's/^[[:space:]]*\* Version:[[:space:]]*//p' "${PROJECT_ROOT}/${PLUGIN_SLUG}.php" | head -n 1)"
PACKAGE_VERSION="$(sed -n 's/^[[:space:]]*"version":[[:space:]]*"\([^"]*\)",[[:space:]]*$/\1/p' "${PROJECT_ROOT}/package.json" | head -n 1)"
STABLE_TAG="$(sed -n 's/^Stable tag:[[:space:]]*//p' "${PROJECT_ROOT}/readme.txt" | head -n 1)"

if [[ "${PLUGIN_VERSION}" != "${VERSION}" || "${PACKAGE_VERSION}" != "${VERSION}" || "${STABLE_TAG}" != "${VERSION}" ]]; then
	echo "Version ${VERSION} must match the plugin header (${PLUGIN_VERSION}), package.json (${PACKAGE_VERSION}), and readme.txt stable tag (${STABLE_TAG})." >&2
	exit 1
fi

rm -rf "${BUILD_ROOT}"
mkdir -p "${PACKAGE_ROOT}"

rsync -a --exclude-from="${PROJECT_ROOT}/.distignore" "${PROJECT_ROOT}/" "${PACKAGE_ROOT}/"

cp "${PROJECT_ROOT}/composer.json" "${PROJECT_ROOT}/composer.lock" "${PACKAGE_ROOT}/"
composer install \
	--working-dir="${PACKAGE_ROOT}" \
	--no-dev \
	--prefer-dist \
	--no-interaction \
	--optimize-autoloader
rm "${PACKAGE_ROOT}/composer.json" "${PACKAGE_ROOT}/composer.lock"

(
	cd "${BUILD_ROOT}"
	zip -qr "${PLUGIN_SLUG}-${VERSION}.zip" "${PLUGIN_SLUG}"
)

echo "Created ${BUILD_ROOT}/${PLUGIN_SLUG}-${VERSION}.zip"
