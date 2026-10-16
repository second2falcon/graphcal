#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 GraphCal contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Build the installable app archive: build/graphcal-<version>.tar.gz with a single top-level
# "graphcal/" folder holding only what Nextcloud needs (no tests, docs or dev tooling), and check it.
# Prints only the archive path on stdout. Works with GNU and BSD tar.
set -euo pipefail
cd "$(dirname "$0")/.."
fail() { echo "package.sh: $*" >&2; exit 1; }

# What goes into the archive, and what deliberately doesn't. Every top-level folder must be in one
# of the two lists, so a new runtime folder (l10n, css, ...) can't be left out of releases by accident.
APP=(appinfo img js lib templates LICENSES README.md CHANGELOG.md)
DEV_DIRS=(build docs node_modules scripts tests vendor)
for dir in */; do
	dir=${dir%/}
	[[ " ${APP[*]} ${DEV_DIRS[*]} " == *" $dir "* ]] || fail "unknown top-level folder '$dir': add it to APP or DEV_DIRS"
done
for path in "${APP[@]}"; do
	[[ -e "$path" ]] || fail "'$path' is listed in APP but doesn't exist"
done

version=$(php -r 'echo simplexml_load_file("appinfo/info.xml")->version;')
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || fail "can't read a version from appinfo/info.xml (got '$version')"

# Copy into a staging folder named like the app, then archive that folder (no tar-specific renaming).
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir "$stage/graphcal"
cp -R "${APP[@]}" "$stage/graphcal/"
for f in appinfo/info.xml lib/AppInfo/Application.php lib/Service/SyncService.php js/calendar-errors.js \
	js/personal.js templates/admin.php LICENSES/AGPL-3.0-or-later.txt; do
	[[ -e "$stage/graphcal/$f" ]] || fail "missing from the archive: $f"
done

mkdir -p build
rm -f build/graphcal-*.tar.gz
archive="build/graphcal-$version.tar.gz"
tar -C "$stage" -czf "$archive" graphcal
outside=$(tar -tzf "$archive" | grep -v -e '^graphcal/' -e '^graphcal$' -e '^$' || true)
[[ -z "$outside" ]] || fail "archive has entries outside graphcal/: $outside"
echo "$archive"
