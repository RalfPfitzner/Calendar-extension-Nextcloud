#!/usr/bin/env bash
# Startet eine Test-Nextcloud in Docker mit dieser App (aus dem Arbeitsverzeichnis) und der Kalender-App.
# Danach laufen die Browser-Tests mit `npm run test:ui`. Nur für Tests, nie gegen eine echte Instanz.
# Aufruf: tools/test-nextcloud.sh          (Container „calplan-nc“ auf Port 8080, Nextcloud 35.0.2;
#                                         andere mit NC_NAME und NC_PORT, z. B. für parallele Arbeitskopien)
#         NC_VERSION=34.0.5 tools/test-nextcloud.sh   (andere Hauptversion)
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
KALENDER_VERSION=${KALENDER_VERSION:-6.6.5}
KALENDER_APP=https://github.com/nextcloud-releases/calendar/releases/download/v$KALENDER_VERSION/calendar-v$KALENDER_VERSION.tar.gz
# Impersonate mit kaputtem Admin-Template (`<div id="impersonate"</div>`, in 5.0.0 für 34 und 6.0.0 für 35): Regression
# dafür, dass die Einstellungsseite in einem eigenen Bereich steht und nicht von fremden Formularen verschluckt wird.
[[ $NC_VERSION == 34.* ]] && IMPERSONATE_VORGABE=5.0.0 || IMPERSONATE_VORGABE=6.0.0
IMPERSONATE_VERSION=${IMPERSONATE_VERSION:-$IMPERSONATE_VORGABE}
IMPERSONATE_APP=https://github.com/nextcloud-releases/impersonate/releases/download/v$IMPERSONATE_VERSION/impersonate-v$IMPERSONATE_VERSION.tar.gz
ADMIN_PASS=Admin-Test-123
KIM_PASS=Kim-Test-12345
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
		6.6.5) echo e3afbc2a10db87920f5347279423422411010550a809b2902c0e8cf2f2492107 ;;
	esac
}
# SHA-256 des Release-Pakets von Impersonate je Version
impersonate_summe() {
	case $1 in
		5.0.0) echo e9b85d6489434af2d05f1618839bcfd9859dbc6b7be580148592f511a448ed66 ;;
		6.0.0) echo 03fe448867e07bf4e6e3dd95f3550f8a07b5c89683a2baf1382b1cd5cf0a17bd ;;
	esac
}
if [[ -z ${NC_IMAGE:-} ]]; then
	digest=$(nc_digest "$NC_VERSION")
	[[ -n $digest ]] || { echo "Kein Digest für Nextcloud $NC_VERSION hinterlegt: in nc_digest eintragen oder NC_IMAGE setzen" >&2; exit 1; }
	NC_IMAGE=nextcloud:$NC_VERSION-apache@$digest
fi
KALENDER_SHA256=${KALENDER_SHA256:-$(kalender_summe "$KALENDER_VERSION")}
[[ -n $KALENDER_SHA256 ]] || { echo "Keine Prüfsumme für die Kalender-App $KALENDER_VERSION hinterlegt: in kalender_summe eintragen oder KALENDER_SHA256 setzen" >&2; exit 1; }
IMPERSONATE_SHA256=${IMPERSONATE_SHA256:-$(impersonate_summe "$IMPERSONATE_VERSION")}
[[ -n $IMPERSONATE_SHA256 ]] || { echo "Keine Prüfsumme für Impersonate $IMPERSONATE_VERSION hinterlegt: in impersonate_summe eintragen oder IMPERSONATE_SHA256 setzen" >&2; exit 1; }

[[ -f $APP/js/calendar_planning-main.mjs ]] || { echo "Erst die Oberfläche bauen: npm ci && npm run build" >&2; exit 1; }

# Unter Git Bash auf Windows: Hostpfade als Windows-Pfad (cygpath), Containerpfade nicht umschreiben lassen
# (MSYS_NO_PATHCONV, sonst wird /var/… zu C:/Program Files/Git/var/…). Nur für docker, curl braucht /dev/null.
hostpfad() { cygpath -w "$1" 2>/dev/null || echo "$1"; }
docker() { MSYS_NO_PATHCONV=1 command docker "$@"; }

# -v entfernt auch das anonyme Volume des Images (/var/www/html); sonst bleibt je Lauf eines liegen und füllt die Platte.
docker rm -f -v "$NAME" >/dev/null 2>&1 || true
# Nur an 127.0.0.1: Die Test-Nextcloud hat bekannte Passwörter und soll aus dem Netz nicht erreichbar sein.
docker run -d --name "$NAME" -p "127.0.0.1:$PORT:80" \
	-e SQLITE_DATABASE=nc -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD="$ADMIN_PASS" \
	-e NEXTCLOUD_TRUSTED_DOMAINS="localhost 127.0.0.1" \
	-v "$(hostpfad "$APP"):/var/www/html/custom_apps/calendar_planning:ro" "$NC_IMAGE" >/dev/null

occ() { docker exec -u www-data "$NAME" php occ "$@"; }
echo "Warte auf die Installation …"
for _ in $(seq 1 60); do
	occ status 2>/dev/null | grep -q 'installed: true' && break
	sleep 5
done
occ status | grep -q 'installed: true' || { docker logs "$NAME" | tail -30; exit 1; }

# Kalender-App und Impersonate aus dem GitHub-Release (der App-Store ist nicht überall erreichbar), vor dem Entpacken
# per SHA-256 geprüft
fremdeApp() {
	local tmp
	tmp=$(mktemp -d)
	curl -fsSL "$2" -o "$tmp/$1.tar.gz"
	pruefsumme "$tmp/$1.tar.gz" "$3" || { echo "Prüfsumme von $1 stimmt nicht" >&2; rm -rf "$tmp"; exit 1; }
	tar xzf "$tmp/$1.tar.gz" -C "$tmp"
	docker cp "$(hostpfad "$tmp")/$1" "$NAME:/var/www/html/custom_apps/"
	rm -rf "$tmp"
	docker exec "$NAME" chown -R www-data:www-data "/var/www/html/custom_apps/$1"
}
fremdeApp calendar "$KALENDER_APP" "$KALENDER_SHA256"
fremdeApp impersonate "$IMPERSONATE_APP" "$IMPERSONATE_SHA256"

occ app:disable firstrunwizard >/dev/null
occ app:enable calendar
occ app:enable impersonate
occ app:enable calendar_planning
# Einstellungen, wie ein Admin sie setzen würde; die Browser-Tests rechnen mit genau diesen Werten.
occ config:app:set calendar_planning ort_vorgabe --value 'Vereinsheim' >/dev/null
occ config:app:set calendar_planning kalender_muster --value 'Planung' >/dev/null
docker exec -u www-data -e OC_PASS="$KIM_PASS" "$NAME" php occ user:add --password-from-env kim >/dev/null
# Nextcloud-Oberfläche auf Englisch, damit die Bezeichnungen der Kalender-App in den Tests fest sind
# (sonst speichert Nextcloud die Browsersprache des ersten Logins am Konto).
occ user:setting admin core lang en
occ user:setting kim core lang en
NC_URL="http://localhost:$PORT" NC_USER=admin NC_PASS="$ADMIN_PASS" NC_KIM_PASS=x "$APP/tools/testdaten.sh"
echo "Bereit: http://localhost:$PORT (admin / $ADMIN_PASS, kim / $KIM_PASS)"
