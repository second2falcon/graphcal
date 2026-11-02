#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 GraphCal contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Prepare an installed Nextcloud (NC_DIR) with the app enabled for tests/integration/run.php,
# then start Nextcloud and the Graph mock with PHP's built-in server.
set -euo pipefail
# OCC: command prefix for occ, e.g. for AIO: "docker exec -e OC_PASS --user www-data nextcloud-aio-nextcloud php /var/www/html/occ"
# NC_URL: where Nextcloud is served; MOCK_URL: where Nextcloud reaches the Graph mock; MOCK_BIND: mock listen address.
NC_URL=${NC_URL:-http://127.0.0.1:8080}
MOCK_URL=${MOCK_URL:-http://127.0.0.1:8090}
if [ -n "${OCC:-}" ]; then
	occ() { $OCC "$@"; }
else
	: "${NC_DIR:?NC_DIR or OCC must be set}"
	occ() { php "$NC_DIR/occ" "$@"; }
fi

occ background:cron   # jobs only run when the test says so (no AJAX cron on page loads)
occ config:system:set trusted_domains 1 --value=127.0.0.1
occ config:system:set overwrite.cli.url --value="$NC_URL"
occ config:system:set allow_local_remote_servers --value=true --type=boolean
occ config:system:set auth.bruteforce.protection.enabled --value=false --type=boolean
occ config:app:set graphcal login_url --value="$MOCK_URL"
occ config:app:set graphcal graph_url --value="$MOCK_URL/v1.0"
occ config:app:set graphcal tenant_id --value=tenant
occ config:app:set graphcal client_id --value=client
occ config:app:set graphcal client_secret --value=secret --sensitive
for u in alice bob; do
	OC_PASS=Graphcal-Test-Pass-7731 occ user:add --password-from-env "$u" >/dev/null 2>&1 || true
	occ user:setting "$u" settings email "$u@contoso.test"
done

rm -f /tmp/graphcal-mock.json /tmp/graphcal-mock.json.lock
MOCK_STATE=/tmp/graphcal-mock.json php -S "${MOCK_BIND:-127.0.0.1}:8090" "$(dirname "$0")/graph-mock.php" >/tmp/graphcal-mock.log 2>&1 &
# NC_WEB=external: Nextcloud is already served on :8080 (e.g. nginx + PHP-FPM).
if [ "${NC_WEB:-}" != external ]; then
	PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t "$NC_DIR" >/tmp/graphcal-nc.log 2>&1 &
fi
for _ in $(seq 1 30); do
	curl -sf "$NC_URL/status.php" >/dev/null && curl -s http://127.0.0.1:8090/_state >/dev/null && exit 0
	sleep 1
done
echo "servers did not start" >&2
exit 1
