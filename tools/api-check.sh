#!/usr/bin/env bash
# Prüft mit PHPStan, ob die App zu den Schnittstellen einer Nextcloud-Version passt.
# Holt dafür das Paket nextcloud/ocp in genau dieser Version und die zwei genutzten Klassen aus
# apps/dav (die nicht in OCP liegen) aus dem Server-Repo. Läuft in der CI je unterstützter Hauptversion.
# Aufruf: tools/api-check.sh [nextcloud-version]   (Vorgabe 35.0.2; vorher composer install)
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION=${1:-35.0.2}
ZIEL=build/nextcloud-api/oca
SICHERUNG=build/nextcloud-api/composer-sicherung
SERVER=https://raw.githubusercontent.com/nextcloud/server/v$VERSION

# composer.json und composer.lock bleiben, wie sie vorher waren (auch mit lokalen, noch nicht
# committeten Änderungen): Kopie anlegen, nach dem Lauf zurückspielen.
rm -rf "$ZIEL" "$SICHERUNG" && mkdir -p "$ZIEL" "$SICHERUNG"
cp composer.json composer.lock "$SICHERUNG/"
trap 'cp "$SICHERUNG/composer.json" "$SICHERUNG/composer.lock" . 2>/dev/null || true' EXIT

for datei in apps/dav/lib/CalDAV/CalDavBackend.php apps/dav/lib/CalDAV/CalendarImpl.php; do
	curl -fsSL "$SERVER/$datei" -o "$ZIEL/$(basename "$datei")"
done

# OCP in der geprüften Version (das Paket hat keinen Autoloader; phpstan.neon liest es per scanDirectories).
composer require --dev --no-interaction --no-progress --quiet --with-all-dependencies "nextcloud/ocp:~$VERSION"

echo "PHPStan gegen Nextcloud $VERSION (nextcloud/ocp $(composer show nextcloud/ocp --no-interaction 2>/dev/null | sed -n 's/^versions *: *\** *//p'))"
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
