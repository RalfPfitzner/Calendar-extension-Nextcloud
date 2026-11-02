#!/usr/bin/env bash
# Prüft mit PHPStan, ob die App zu den Schnittstellen einer Nextcloud-Version passt.
# Holt dafür das Paket nextcloud/ocp in genau dieser Version und die zwei genutzten Klassen aus
# apps/dav (die nicht in OCP liegen) aus dem Server-Repo. Läuft in der CI je unterstützter Hauptversion.
# Aufruf: tools/api-check.sh [nextcloud-version]   (Vorgabe 35.0.2; vorher composer install)
# Die zwei Dateien aus dem Server-Repo sind je Version per SHA-256 festgelegt (STANDARDS A2.10); für eine neue
# Version die Summen unten eintragen (curl -fsSL <URL> | sha256sum).
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

# pruefsumme DATEI SHA256: prüft vor dem Entpacken oder Verwenden (sha256sum unter Linux und Git Bash, shasum unter macOS)
pruefsumme() {
	if command -v sha256sum >/dev/null; then echo "$2  $1" | sha256sum -c --quiet -
	else echo "$2  $1" | shasum -a 256 -c --quiet -; fi
}
# SHA-256 je Version und Datei (Stand 02.11.2026; case statt assoziativer Arrays wegen Bash 3 auf macOS)
summe() {
	case $1 in
		34.0.5/CalDavBackend.php) echo 841f4f79c75a70a45aed409093f8268ee3a8637d1ffcc24cee85327d32666a28 ;;
		34.0.5/CalendarImpl.php) echo 59f782b522545cb85d5346a469d6e916a027d5b90f2aa2c5ba82c116437baba7 ;;
		35.0.2/CalDavBackend.php) echo a4cbb8347de58216394aa1c2c009e4a4fe1432a3e6e84c07b2a880f22f15d0c2 ;;
		35.0.2/CalendarImpl.php) echo 22336f582db40a32e789fabd339b2a56925bb1c1391657e06861d7a65835dbee ;;
	esac
}
for datei in apps/dav/lib/CalDAV/CalDavBackend.php apps/dav/lib/CalDAV/CalendarImpl.php; do
	name=$(basename "$datei")
	summe=$(summe "$VERSION/$name")
	[[ -n $summe ]] || { echo "Keine Prüfsumme für $name in Nextcloud $VERSION hinterlegt (summe in tools/api-check.sh)" >&2; exit 1; }
	curl -fsSL "$SERVER/$datei" -o "$ZIEL/$name"
	pruefsumme "$ZIEL/$name" "$summe" || { echo "Prüfsumme von $name ($VERSION) stimmt nicht" >&2; exit 1; }
done

# OCP in der geprüften Version (das Paket hat keinen Autoloader; phpstan.neon liest es per scanDirectories).
composer require --dev --no-interaction --no-progress --quiet --with-all-dependencies "nextcloud/ocp:~$VERSION"

echo "PHPStan gegen Nextcloud $VERSION (nextcloud/ocp $(composer show nextcloud/ocp --no-interaction 2>/dev/null | sed -n 's/^versions *: *\** *//p'))"
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
