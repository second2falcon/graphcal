#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 GraphCal contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Start Nextcloud All-in-One from its official manual-install compose file and install GraphCal
# into custom_apps, like an app installed from the App Store.
# Usage: ADMIN_PASS=... tests/aio/start.sh /path/to/all-in-one /path/to/graphcal
set -euo pipefail
AIO_DIR=$1
APP_DIR=$2
HERE=$(cd "$(dirname "$0")" && pwd)
occ() { docker exec --user www-data nextcloud-aio-nextcloud php /var/www/html/occ "$@"; }

cd "$AIO_DIR/manual-install"
cp sample.conf .env
setv() { sed -i -E "s|^$1=.*|$1=$2|" .env; }
for key in $(grep -oE '^[A-Z_]*(PASSWORD|SECRET|KEY)=' .env | tr -d '='); do
	setv "$key" "ci$(openssl rand -hex 16)"
done
setv NEXTCLOUD_PASSWORD "$ADMIN_PASS"
setv NC_DOMAIN 127.0.0.1
setv APACHE_PORT 11000            # anything but 443: plain http, as behind a reverse proxy
setv APACHE_IP_BINDING 127.0.0.1
setv NEXTCLOUD_STARTUP_APPS '"calendar"'
docker compose -f latest.yml -f "$HERE/compose.override.yml" up -d

echo "Waiting for AIO to install Nextcloud and the startup apps..."
for _ in $(seq 1 150); do
	if curl -sf http://127.0.0.1:11000/status.php | grep -q '"installed":true' \
		&& occ app:list --enabled 2>/dev/null | grep -q ' calendar:'; then
		break
	fi
	sleep 5
done
occ status

docker cp "$APP_DIR" nextcloud-aio-nextcloud:/var/www/html/custom_apps/graphcal
docker exec nextcloud-aio-nextcloud chown -R www-data:www-data /var/www/html/custom_apps/graphcal
occ app:enable graphcal
occ app:disable firstrunwizard || true   # its welcome dialog would cover the pages the browser test uses
