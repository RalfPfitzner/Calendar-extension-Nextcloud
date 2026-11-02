#!/usr/bin/env bash
# Startet eine Test-Nextcloud in Docker mit dieser App (aus dem Arbeitsverzeichnis) und der Kalender-App.
# Danach laufen die Browser-Tests mit `npm run test:ui`. Nur für Tests, nie gegen eine echte Instanz.
# Aufruf: tools/test-nextcloud.sh          (Container „calplan-nc“ auf Port 8080, Nextcloud 35.0.2;
#                                         andere mit NC_NAME und NC_PORT, z. B. für parallele Arbeitskopien)
#         NC_VERSION=34.0.5 KALENDER_VERSION=6.6.3 tools/test-nextcloud.sh   (andere Hauptversion)
#         tools/test-nextcloud.sh --stopp  (Container entfernen)
# Image und Kalender-App sind festgelegt (STANDARDS A2.10): das Image per Digest, die Kalender-App per SHA-256,
# beide je Version in nc_digest und kalender_summe unten. Eine neue Version dort eintragen (Werte ermitteln mit
#   docker buildx imagetools inspect nextcloud:<version>-apache
#   curl -fsSL <Release-URL der Kalender-App> | sha256sum)
# oder ausnahmsweise NC_IMAGE bzw. KALENDER_SHA256 setzen.
set -euo pipefail

NAME=${NC_NAME:-calplan-nc}
PORT=${NC_PORT:-8080}
NC_VERSION=${NC_VERSION:-35.0.2}
KALENDER_VERSION=${KALENDER_VERSION:-6.6.4}
KALENDER_APP=https://github.com/nextcloud-releases/calendar/releases/download/v$KALENDER_VERSION/calendar-v$KALENDER_VERSION.tar.gz
ADMIN_PASS=Admin-Test-123
EVA_PASS=Eva-Test-12345
APP=$(cd "$(dirname "$0")/.." && pwd)

if [[ ${1:-} == --stopp ]]; then
	docker rm -f -v "$NAME" >/dev/null 2>&1 || true
	exit 0
fi

# pruefsumme DATEI SHA256: prüft vor dem Entpacken oder Verwenden (sha256sum unter Linux und Git Bash, shasum unter macOS)
pruefsumme() {
	if command -v sha256sum >/dev/null; then echo "$2  $1" | sha256sum -c --quiet -
	else echo "$2  $1" | shasum -a 256 -c --quiet -; fi
}
# Digest des Images je Nextcloud-Version (Stand 02.11.2026, Version und Digest immer gemeinsam ändern).
# case statt assoziativer Arrays, damit das Skript auch mit Bash 3 (macOS) läuft.
nc_digest() {
	case $1 in
		34.0.5) echo sha256:515c072ea1cf82ece3503f301fdf75dd5a7148abcc8fa10aaefd5ec56643b703 ;;
		35.0.2) echo sha256:e0b1a2af52c82c07b7a0e8e59878bd8769e704447734d5d238a836982f51b9c8 ;;
	esac
}
# SHA-256 des Release-Pakets der Kalender-App je Version
kalender_summe() {
	case $1 in
		6.6.3) echo bda8f38c609b2182533887f67eacb543edf437066d0356912ac6324c94743080 ;;
		6.6.4) echo 0557b6b5852a467cf3f746e59d56478d227f78bf7a66357b30dfc10cae4c7e3c ;;
	esac
}
if [[ -z ${NC_IMAGE:-} ]]; then
	digest=$(nc_digest "$NC_VERSION")
	[[ -n $digest ]] || { echo "Kein Digest für Nextcloud $NC_VERSION hinterlegt: in nc_digest eintragen oder NC_IMAGE setzen" >&2; exit 1; }
	NC_IMAGE=nextcloud:$NC_VERSION-apache@$digest
fi
KALENDER_SHA256=${KALENDER_SHA256:-$(kalender_summe "$KALENDER_VERSION")}
[[ -n $KALENDER_SHA256 ]] || { echo "Keine Prüfsumme für die Kalender-App $KALENDER_VERSION hinterlegt: in kalender_summe eintragen oder KALENDER_SHA256 setzen" >&2; exit 1; }

[[ -f $APP/js/calendar_planning-main.mjs ]] || { echo "Erst die Oberfläche bauen: npm ci && npm run build" >&2; exit 1; }

# -v entfernt auch das anonyme Volume des Images (/var/www/html); sonst bleibt je Lauf eines liegen und füllt die Platte.
docker rm -f -v "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" -p "$PORT:80" \
	-e SQLITE_DATABASE=nc -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD="$ADMIN_PASS" \
	-e NEXTCLOUD_TRUSTED_DOMAINS="localhost 127.0.0.1" \
	-v "$APP:/var/www/html/custom_apps/calendar_planning:ro" "$NC_IMAGE" >/dev/null

occ() { docker exec -u www-data "$NAME" php occ "$@"; }
echo "Warte auf die Installation …"
for _ in $(seq 1 60); do
	occ status 2>/dev/null | grep -q 'installed: true' && break
	sleep 5
done
occ status | grep -q 'installed: true' || { docker logs "$NAME" | tail -30; exit 1; }

# Kalender-App aus dem GitHub-Release (der App-Store ist nicht überall erreichbar)
tmp=$(mktemp -d)
curl -fsSL "$KALENDER_APP" -o "$tmp/calendar.tar.gz"
pruefsumme "$tmp/calendar.tar.gz" "$KALENDER_SHA256" \
	|| { echo "Prüfsumme der Kalender-App $KALENDER_VERSION stimmt nicht" >&2; rm -rf "$tmp"; exit 1; }
tar xzf "$tmp/calendar.tar.gz" -C "$tmp"
# Unter Git Bash auf Windows braucht docker cp den Windows-Pfad (cygpath), sonst den POSIX-Pfad.
docker cp "$(cygpath -w "$tmp" 2>/dev/null || echo "$tmp")/calendar" "$NAME:/var/www/html/custom_apps/"
rm -rf "$tmp"
docker exec "$NAME" chown -R www-data:www-data /var/www/html/custom_apps/calendar

occ app:disable firstrunwizard >/dev/null
occ app:enable calendar
occ app:enable calendar_planning
# Einstellungen, wie ein Admin sie setzen würde; die Browser-Tests rechnen mit genau diesen Werten.
occ config:app:set calendar_planning ort_vorgabe --value 'Vereinsheim' >/dev/null
occ config:app:set calendar_planning kalender_muster --value 'Planung' >/dev/null
docker exec -u www-data -e OC_PASS="$EVA_PASS" "$NAME" php occ user:add --password-from-env eva >/dev/null
# Nextcloud-Oberfläche auf Englisch, damit die Bezeichnungen der Kalender-App in den Tests fest sind
# (sonst speichert Nextcloud die Browsersprache des ersten Logins am Konto).
occ user:setting admin core lang en
occ user:setting eva core lang en
NC_URL="http://localhost:$PORT" NC_USER=admin NC_PASS="$ADMIN_PASS" NC_EVA_PASS=x "$APP/tools/testdaten.sh"
echo "Bereit: http://localhost:$PORT (admin / $ADMIN_PASS, eva / $EVA_PASS)"
