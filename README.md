# MSP-Planung – Kalender-Erweiterung für Nextcloud

Nextcloud-App für den Makerspace Mayen-Koblenz: Veranstaltungen werden vollständig im Nextcloud-Kalender („MSP Cloud Kalender“) geplant. Die Felder der Word-Vorlage „Vorlage blanko Planung alle Veranstaltungen“ (Grunddaten, Kontaktdaten, teilnehmende Gruppe, Inhalte & Werkzeuge, Hinweise) stehen im Kalendertermin selbst und synchronisieren mit. Die Kalender-App bleibt unverändert.

- Ansicht **MSP-Planung** in Nextcloud: Liste der Termine (Zeitraum, Suche, seitenweise) und Formular je Termin nach der Vorlage
- Ort mit Vorgabe „MakerSpace Bendorf“ oder externem Ort mit Adresse
- Link „Planung öffnen“ in der Terminbeschreibung: aus der Kalender-App mit einem Klick zum Formular
- Rechte wie in der Kalender-App: Nur-Lese-Freigaben sehen das Formular schreibgeschützt, private Termine bleiben privat
- Schutz vor Überschreiben, wenn der Termin inzwischen in der Kalender-App geändert wurde

Anforderungen aus dem Planungs-Chat: [`docs/info.md`](docs/info.md). Umsetzung, Datenformat und offene Punkte: [`docs/KONZEPT.md`](docs/KONZEPT.md).

**Stand: Version 0.1.1, nur für Nextcloud 34.** Getestet in einer Test-Nextcloud 34.0.5 mit Kalender-App 6.6.3 (Version 0.1.0: Nextcloud 32.0.5 mit Kalender-App 6.0.3 und 6.6.2).

## Installation

Voraussetzung: Admin-Rechte in der Nextcloud und ein beschreibbares `custom_apps`-Verzeichnis (beim offiziellen Docker-Image Standard).

1. `msp_planung-<version>.tar.gz` aus dem GitHub-Release laden (oder selbst bauen, siehe unten).
2. Auf dem Server nach `custom_apps` entpacken, Eigentümer auf den Webserver-Nutzer setzen und aktivieren. Beim offiziellen Docker-Image:

   ```bash
   docker cp msp_planung-0.1.0.tar.gz nextcloud:/tmp/
   docker exec nextcloud tar xzf /tmp/msp_planung-0.1.0.tar.gz -C /var/www/html/custom_apps
   docker exec nextcloud chown -R www-data:www-data /var/www/html/custom_apps/msp_planung
   docker exec -u www-data nextcloud php occ app:enable msp_planung
   ```

   Ohne Docker: in das `custom_apps`-Verzeichnis der Installation entpacken, `chown -R www-data:www-data msp_planung`, dann `sudo -u www-data php occ app:enable msp_planung`.
3. In Nextcloud erscheint oben **MSP-Planung**. Die App wählt den ersten Kalender mit „MSP“ im Namen vor.

**Update:** alten Ordner `custom_apps/msp_planung` löschen, neues Paket entpacken, `occ upgrade` ausführen. Die Daten liegen in den Terminen und bleiben erhalten.

**Entfernen:** `occ app:remove msp_planung`. Die Planungsfelder bleiben in den Terminen stehen und stören die Kalender-App nicht.

## Entwicklung

```
appinfo/        App-Beschreibung und Routen
lib/            PHP: Controller, KalenderService (Kalenderzugriff), Planung (iCalendar <-> Formular)
src/            Oberfläche (Vue 3, @nextcloud/vue), gebaut nach js/ und css/
templates/      Seitenrahmen
tests/Unit/     PHPUnit-Tests der Feldzuordnung
test-ui/        Browser-Tests gegen eine Test-Nextcloud (Playwright)
tools/          Test-Nextcloud in Docker, Testdaten, Paketbau
```

```bash
composer install && composer test:unit          # PHP-Tests
npm ci && npm run build                          # Oberfläche bauen
tools/test-nextcloud.sh                          # Nextcloud 34.0.5 + Kalender-App in Docker, Port 8080
npm run test:ui                                  # Browser-Tests
tools/test-nextcloud.sh --stopp                  # Container entfernen
tools/paket.sh                                   # build/msp_planung-<version>.tar.gz
```

Während der Entwicklung baut `npm run watch` die Oberfläche bei jeder Änderung neu; der Test-Container bindet das Arbeitsverzeichnis direkt ein.

## Lizenz

GPL-3.0-or-later, siehe [`LICENSE`](LICENSE).
