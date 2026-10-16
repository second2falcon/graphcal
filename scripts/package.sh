#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 GraphCal contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Build the installable app archive: build/graphcal-<version>.tar.gz with a single top-level
# "graphcal/" folder holding only what Nextcloud needs (no tests, docs or dev tooling).
set -euo pipefail
cd "$(dirname "$0")/.."

# What goes into the archive, and what deliberately doesn't. Every top-level folder must be in one
# of the two lists, so a new runtime folder (l10n, css, ...) can't be left out of releases by accident.
APP=(appinfo img js lib templates LICENSES README.md CHANGELOG.md)
DEV_DIRS=(build docs node_modules scripts tests vendor)
for dir in */; do
	dir=${dir%/}
	if [[ ! " ${APP[*]} ${DEV_DIRS[*]} " == *" $dir "* ]]; then
		echo "Unknown top-level folder '$dir': add it to APP or DEV_DIRS in scripts/package.sh" >&2
		exit 1
	fi
done

version=$(php -r 'echo simplexml_load_file("appinfo/info.xml")->version;')
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]]; then
	echo "Can't read a version from appinfo/info.xml (got '$version')" >&2
	exit 1
fi
mkdir -p build
archive="build/graphcal-$version.tar.gz"
tar -czf "$archive" --owner=0 --group=0 --transform 's,^,graphcal/,' "${APP[@]}"
echo "$archive"
