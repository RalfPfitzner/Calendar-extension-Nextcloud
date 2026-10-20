#!/usr/bin/env bash
# Baut das installierbare Paket build/msp_planung-<version>.tar.gz für custom_apps.
# Aufruf: tools/paket.sh   (vorher npm ci; baut die Oberfläche selbst)
set -euo pipefail
cd "$(dirname "$0")/.."

version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
paket_version=$(node -p "require('./package.json').version")
[[ $version == "$paket_version" ]] || { echo "Version in appinfo/info.xml ($version) und package.json ($paket_version) passen nicht" >&2; exit 1; }

npm run build
rm -rf build && mkdir -p build/msp_planung
cp -r appinfo lib templates img css LICENSE README.md CHANGELOG.md build/msp_planung/
mkdir build/msp_planung/js
cp js/*.mjs build/msp_planung/js/
tar -czf "build/msp_planung-$version.tar.gz" -C build --owner=0 --group=0 msp_planung
echo "build/msp_planung-$version.tar.gz"
