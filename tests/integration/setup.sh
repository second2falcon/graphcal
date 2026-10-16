#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 GraphCal contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Prepare an installed Nextcloud (NC_DIR) with the app enabled for tests/integration/run.php,
# then start Nextcloud and the Graph mock with PHP's built-in server.
set -euo pipefail
: "${NC_DIR:?}"
occ() { php "$NC_DIR/occ" "$@"; }

occ config:system:set trusted_domains 1 --value=127.0.0.1
occ config:system:set overwrite.cli.url --value=http://127.0.0.1:8080
occ config:system:set allow_local_remote_servers --value=true --type=boolean
occ config:system:set auth.bruteforce.protection.enabled --value=false --type=boolean
occ config:app:set graphcal login_url --value=http://127.0.0.1:8090
occ config:app:set graphcal graph_url --value=http://127.0.0.1:8090/v1.0
occ config:app:set graphcal tenant_id --value=tenant
occ config:app:set graphcal client_id --value=client
occ config:app:set graphcal client_secret --value=secret --sensitive
for u in alice bob; do
	OC_PASS=Secret-Pass-123 occ user:add --password-from-env "$u" >/dev/null 2>&1 || true
	occ user:setting "$u" settings email "$u@contoso.test"
done

rm -f /tmp/graphcal-mock.json /tmp/graphcal-mock.json.lock
MOCK_STATE=/tmp/graphcal-mock.json php -S 127.0.0.1:8090 "$(dirname "$0")/graph-mock.php" >/tmp/graphcal-mock.log 2>&1 &
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t "$NC_DIR" >/tmp/graphcal-nc.log 2>&1 &
for _ in $(seq 1 30); do
	curl -sf http://127.0.0.1:8080/status.php >/dev/null && curl -s http://127.0.0.1:8090/_state >/dev/null && exit 0
	sleep 1
done
echo "servers did not start" >&2
exit 1
