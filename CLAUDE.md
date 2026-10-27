# MSP-Planung (Calendar-extension-Nextcloud) – Hinweise für Claude

Nextcloud-App für den Makerspace Mayen-Koblenz: Die Planungsdaten der Word-Vorlage „Vorlage blanko Planung alle Veranstaltungen“ werden direkt im Kalendertermin gepflegt. Ausgangslage und Anforderungen: `docs/info.md` (Stand des Planungs-Chats, nicht ändern). Entscheidungen der Umsetzung, Datenformat und offene Punkte: `docs/KONZEPT.md`. Sprache in Code-Kommentaren, Commits, Doku und Antworten: Deutsch.

## Architektur

- App-ID `msp_planung`, Namespace `OCA\MspPlanung`, bisher nur für Nextcloud 32 gebaut und getestet (`appinfo/info.xml`: `min-version`/`max-version` 32). **Ralfs Instanz läuft inzwischen auf Nextcloud 34.0.5.1 mit Kalender-App 6.6.3** (Stand 26.10.2026, Quelle: `test/docs/SYSTEME.md` im Repo `ralfpfitzner/test`); dort lässt sich die App daher noch nicht aktivieren. Die Umstellung auf 34 (Versionsgrenzen, Test-Nextcloud, CI, README, `docs/KONZEPT.md`) ist offen. Das Repo ist die App selbst (`appinfo/`, `lib/`, `templates/`, `img/`, `src/`).
- **Die Kalender-App wird nicht verändert und nicht geforkt.** Die App liest und schreibt Termine selbst; Planung steht als `X-MSP-…`-Eigenschaften im VEVENT, Titel in `SUMMARY`, Ort in `LOCATION`.
- `lib/Service/Planung.php`: reine Umwandlung iCalendar ↔ Formular (ohne Nextcloud, mit PHPUnit getestet). Die Namen in `Planung::FELDER` sind Datenformat: **nie umbenennen**, nur ergänzen. `src/felder.js` muss dazu passen.
- `lib/Service/KalenderService.php`: Rechte und Kalenderliste über OCP (`IManager`, `ICalendarIsWritable`, `ICalendarIsShared`), Lesen/Schreiben der Objekte über `OCA\DAV\CalDAV\CalDavBackend` (OCP kann bestehende Termine nicht ändern). Private Termine in geteilten Kalendern bleiben für andere unsichtbar. Speichern nur mit passendem ETag (409 bei Konflikt).
- Controller prüfen nur Eingaben und reichen weiter; Fehler für Nutzer als `PlanungException` (Code = HTTP-Status), Antwort `{fehler: "…"}`.
- Oberfläche: Vue 3 mit `@nextcloud/vue` 9, gebaut mit Vite (`@nextcloud/vite-config`) nach `js/` und `css/` (nicht im Repo). TypeScript bleibt auf 5.x (7.x bricht `vite-plugin-dts`).
- Zustand der Oberfläche (Kalender, Termin, Zeitraum, Suche, Seite, Seitengröße) steht in der Adresse.

## Listen in Oberflächen

Wie im Bestellportal und in der Goethe-Verwaltung: Listen seitenweise, scrollen in eigenem Bereich mit stehendem Tabellenkopf, darunter « ‹ 1 2 3 › (höchstens 5 Zahlen) und „Seite x von y · n Einträge · pro Seite 25/50/100“; Seite und Seitengröße in der Adresse. Die Terminliste blättert auf dem Server (`seite`, `pro_seite`; Antwort mit `seite`, `seiten`, `pro_seite`, `gesamt`). Bedingte Teile nie als `null`/`undefined` in die Ausgabe geben (UI-Test prüft, dass kein „null“ erscheint).

## Testen

- `composer install && composer test:unit` – Feldzuordnung, Escaping, Serien, Link.
- `npm ci && npm run build && tools/test-nextcloud.sh && npm run test:ui` – echte Nextcloud 32.0.5 in Docker mit Kalender-App 6.6.2 (noch nicht der Stand von Ralfs Instanz, siehe oben) (andere Version: `KALENDER_VERSION=…`), Testdaten aus `tools/testdaten.sh` (nur erfundene Daten), Browser-Tests mit Playwright. In der Cloud-Umgebung: `CHROMIUM=/opt/pw-browsers/chromium`, Docker-Daemon ggf. mit `sudo dockerd &` starten.
- Vor dem Abgeben beides laufen lassen; neue Funktionen bekommen Tests. Was nur auf Ralfs Instanz prüfbar ist, im PR ausdrücklich als „von Ralf zu testen“ aufführen.

## Versionen und Releases

- Version steht in `appinfo/info.xml` **und** `package.json` (gleich halten), Änderungen in `CHANGELOG.md`.
- Änderungen immer per Pull Request. **Tag setzt Ralf selbst**; der Release-Workflow baut dann `msp_planung-<version>.tar.gz` (`tools/paket.sh`) und hängt es an einen Release-Entwurf.

## Datenschutz

- Nie echte Termine, Kontaktdaten, Kalender-Exporte oder die Word-Vorlage mit Inhalten committen. Testdaten nur erfunden.
- Kontaktdaten Externer sieht jeder mit Zugriff auf den Kalender (Hinweis in `docs/KONZEPT.md`).

## Arbeitsweise mit Ralf

- Er lädt echte Dateien und Fehlermeldungen hoch; daran diagnostizieren, nicht raten.
- „Weiter“ heißt: an der abgebrochenen Stelle fortsetzen.
- Anforderungen ändern sich während der Entwicklung; `docs/KONZEPT.md` dann mitziehen.
