#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 GraphCal contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Build the installable app archive: build/graphcal-<version>.tar.gz with a single top-level
# "graphcal/" folder holding only what Nextcloud needs (no tests, docs or dev tooling).
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
mkdir -p build
archive="build/graphcal-$version.tar.gz"
tar -czf "$archive" --owner=0 --group=0 --transform 's,^,graphcal/,' \
	appinfo img js lib templates LICENSES README.md CHANGELOG.md
echo "$archive"
