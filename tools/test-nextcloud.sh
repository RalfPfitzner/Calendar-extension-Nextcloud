#!/usr/bin/env bash
# Startet eine Test-Nextcloud 32.0.5 in Docker mit dieser App (aus dem Arbeitsverzeichnis) und der Kalender-App.
# Danach laufen die Browser-Tests mit `npm run test:ui`. Nur für Tests, nie gegen eine echte Instanz.
# Aufruf: tools/test-nextcloud.sh          (Container „msp-nc“ auf Port 8080)
#         tools/test-nextcloud.sh --stopp  (Container entfernen)
set -euo pipefail

NAME=msp-nc
PORT=${NC_PORT:-8080}
NC_IMAGE=nextcloud:32.0.5-apache
KALENDER_VERSION=${KALENDER_VERSION:-6.6.2}
KALENDER_APP=https://github.com/nextcloud-releases/calendar/releases/download/v$KALENDER_VERSION/calendar-v$KALENDER_VERSION.tar.gz
ADMIN_PASS=Admin-Test-123
EVA_PASS=Eva-Test-12345
APP=$(cd "$(dirname "$0")/.." && pwd)

if [[ ${1:-} == --stopp ]]; then
	docker rm -f "$NAME" >/dev/null 2>&1 || true
	exit 0
fi

[[ -f $APP/js/msp_planung-main.mjs ]] || { echo "Erst die Oberfläche bauen: npm ci && npm run build" >&2; exit 1; }

docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" -p "$PORT:80" \
	-e SQLITE_DATABASE=nc -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD="$ADMIN_PASS" \
	-e NEXTCLOUD_TRUSTED_DOMAINS="localhost 127.0.0.1" \
	-v "$APP:/var/www/html/custom_apps/msp_planung:ro" "$NC_IMAGE" >/dev/null

occ() { docker exec -u www-data "$NAME" php occ "$@"; }
echo "Warte auf die Installation …"
for _ in $(seq 1 60); do
	occ status 2>/dev/null | grep -q 'installed: true' && break
	sleep 5
done
occ status | grep -q 'installed: true' || { docker logs "$NAME" | tail -30; exit 1; }

# Kalender-App aus dem GitHub-Release (der App-Store ist nicht überall erreichbar)
tmp=$(mktemp -d)
curl -fsSL "$KALENDER_APP" | tar xz -C "$tmp"
docker cp "$tmp/calendar" "$NAME:/var/www/html/custom_apps/"
rm -rf "$tmp"
docker exec "$NAME" chown -R www-data:www-data /var/www/html/custom_apps/calendar

occ app:disable firstrunwizard >/dev/null
occ app:enable calendar
occ app:enable msp_planung
docker exec -u www-data -e OC_PASS="$EVA_PASS" "$NAME" php occ user:add --password-from-env eva >/dev/null
NC_URL="http://localhost:$PORT" NC_USER=admin NC_PASS="$ADMIN_PASS" NC_EVA_PASS=x "$APP/tools/testdaten.sh"
echo "Bereit: http://localhost:$PORT (admin / $ADMIN_PASS, eva / $EVA_PASS)"
