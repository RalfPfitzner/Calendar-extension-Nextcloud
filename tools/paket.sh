#!/usr/bin/env bash
# Baut das installierbare Paket build/calendar_planning-<version>.tar.gz für custom_apps.
# Aufruf: tools/paket.sh   (vorher npm ci; baut die Oberfläche selbst)
set -euo pipefail
cd "$(dirname "$0")/.."

version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
paket_version=$(node -p "require('./package.json').version")
[[ $version == "$paket_version" ]] || { echo "Version in appinfo/info.xml ($version) und package.json ($paket_version) passen nicht" >&2; exit 1; }

npm run build
rm -rf build && mkdir -p build/calendar_planning
cp -r appinfo lib templates img css schema LICENSE README.md CHANGELOG.md build/calendar_planning/
mkdir build/calendar_planning/js
cp js/*.mjs build/calendar_planning/js/
tar -czf "build/calendar_planning-$version.tar.gz" -C build --owner=0 --group=0 calendar_planning
echo "build/calendar_planning-$version.tar.gz"
