# MSP-Planung (Calendar-extension-Nextcloud) – Hinweise für Claude

Nextcloud-App für den Makerspace Mayen-Koblenz: Die Planungsdaten der Word-Vorlage „Vorlage blanko Planung alle Veranstaltungen“ werden direkt im Kalendertermin gepflegt. Ausgangslage und Anforderungen: `docs/info.md` (Stand des Planungs-Chats, nicht ändern). Entscheidungen der Umsetzung, Datenformat und offene Punkte: `docs/KONZEPT.md`. Sprache in Code-Kommentaren, Commits, Doku und Antworten: Deutsch.

## Architektur

- App-ID `msp_planung`, Namespace `OCA\MspPlanung`, Nextcloud 34 bis 35 (`appinfo/info.xml`: `min-version` 34, `max-version` 35; Ralfs Instanz: 34.0.5.1 mit Kalender-App 6.6.3, Stand 26.10.2026 laut `test/docs/SYSTEME.md` im Repo `ralfpfitzner/test`). Erscheint eine neue Hauptversion, `max-version`, CI-Matrix (`.github/workflows/ci.yml`), Vorgabe in `tools/test-nextcloud.sh` und Doku zusammen nachziehen, vorher gegen die neue Version prüfen (genutzte Methoden von `CalDavBackend`, `CalendarImpl`, `IUserConfig`). Das Repo ist die App selbst (`appinfo/`, `lib/`, `templates/`, `img/`, `src/`).
- **Die Kalender-App wird nicht verändert und nicht geforkt.** Die App liest und schreibt Termine selbst; Planung steht als `X-MSP-…`-Eigenschaften im VEVENT, Titel in `SUMMARY`, Ort in `LOCATION`.
- `lib/Service/Planung.php`: reine Umwandlung iCalendar ↔ Formular (ohne Nextcloud, mit PHPUnit getestet). Die Namen in `Planung::FELDER` sind Datenformat: **nie umbenennen**, nur ergänzen. `src/felder.js` muss dazu passen.
- `lib/Service/KalenderService.php`: Rechte und Kalenderliste über OCP (`IManager`, `ICalendarIsWritable`, `ICalendarIsShared`), Lesen/Schreiben der Objekte über `OCA\DAV\CalDAV\CalDavBackend` (OCP kann bestehende Termine nicht ändern). Private Termine in geteilten Kalendern bleiben für andere unsichtbar. Speichern nur mit passendem ETag (409 bei Konflikt).
- Controller prüfen nur Eingaben und reichen weiter; Fehler für Nutzer als `PlanungException` (Code = HTTP-Status), Antwort `{fehler: "…"}`. `termine`, `termin` und `speichern` tragen `#[UserRateLimit]` (120/120/30 je Minute und Konto, Nextcloud antwortet 429).
- `Planung::EINZEILIG` nennt die einzeiligen Felder und muss zu `mehrzeilig` in `src/felder.js` passen: dort werden Umbrüche zu Leerzeichen, Steuerzeichen fliegen überall raus.
- `tools/api-check.sh <version>` prüft mit PHPStan (`phpstan.neon`, Level 6) gegen `nextcloud/ocp` in genau dieser Version plus `CalDavBackend`/`CalendarImpl` aus dem Server-Repo. Die CI fährt das für jede unterstützte Hauptversion; eine neue Hauptversion kommt zuerst dort in die Matrix.
- Oberfläche: Vue 3 mit `@nextcloud/vue` 9, gebaut mit Vite (`@nextcloud/vite-config`) nach `js/` und `css/` (nicht im Repo). TypeScript bleibt auf 5.x (7.x bricht `vite-plugin-dts`).
- Zustand der Oberfläche (Kalender, Termin, Zeitraum, Suche, Seite, Seitengröße) steht in der Adresse.

## Listen in Oberflächen

Wie im Bestellportal und in der Goethe-Verwaltung: Listen seitenweise, scrollen in eigenem Bereich mit stehendem Tabellenkopf, darunter « ‹ 1 2 3 › (höchstens 5 Zahlen) und „Seite x von y · n Einträge · pro Seite 25/50/100“; Seite und Seitengröße in der Adresse. Die Terminliste blättert auf dem Server (`seite`, `pro_seite`; Antwort mit `seite`, `seiten`, `pro_seite`, `gesamt`). Bedingte Teile nie als `null`/`undefined` in die Ausgabe geben (UI-Test prüft, dass kein „null“ erscheint).

## Testen

- `composer install && composer test:unit` – Feldzuordnung, Escaping, Steuerzeichen, Serien, Link. Braucht PHP 8.3 (Paket `nextcloud/ocp` 35); `config.platform.php` in `composer.json` steht auf 8.3, die App selbst läuft ab PHP 8.2 (Minimum von Nextcloud 34).
- `npm ci && npm run build && tools/test-nextcloud.sh && npm run test:ui` – echte Nextcloud 35.0.2 in Docker mit Kalender-App 6.6.4 (`NC_VERSION=34.0.5 KALENDER_VERSION=6.6.3` für die andere Hauptversion; die CI testet beide), Testdaten aus `tools/testdaten.sh` (nur erfundene Daten), Browser-Tests mit Playwright. In der Cloud-Umgebung: `CHROMIUM=/opt/pw-browsers/chromium`, Docker-Daemon ggf. mit `sudo dockerd &` starten.
- Vor dem Abgeben beides laufen lassen; neue Funktionen bekommen Tests. Was nur auf Ralfs Instanz prüfbar ist, im PR ausdrücklich als „von Ralf zu testen“ aufführen.

## Versionen und Releases

- Version steht in `appinfo/info.xml` **und** `package.json` (gleich halten), Änderungen in `CHANGELOG.md`.
- Änderungen immer per Pull Request. **Tag setzt Ralf selbst**; der Release-Workflow baut dann `msp_planung-<version>.tar.gz` (`tools/paket.sh`) und hängt es an einen Release-Entwurf.
- Standards für Repository, CI und sichere Programmierung aller Projekte: `docs/STANDARDS.md` im Repo `ralfpfitzner/test` (Vorlagen unter `vorlagen/`). Neue Regeln aus diesem Projekt dort nachtragen; Abweichungen hier mit Begründung.

## Datenschutz

- Nie echte Termine, Kontaktdaten, Kalender-Exporte oder die Word-Vorlage mit Inhalten committen. Testdaten nur erfunden.
- Kontaktdaten Externer sieht jeder mit Zugriff auf den Kalender (Hinweis in `docs/KONZEPT.md`).

## Arbeitsweise mit Ralf

- Er lädt echte Dateien und Fehlermeldungen hoch; daran diagnostizieren, nicht raten.
- „Weiter“ heißt: an der abgebrochenen Stelle fortsetzen.
- Anforderungen ändern sich während der Entwicklung; `docs/KONZEPT.md` dann mitziehen.
