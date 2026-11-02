# Plan: alle Review-Befunde der Kalender-Planung

Stand 02.11.2026, `main` 9b91ec5 (Version 1.0.0 plus #19). Grundlage ist der Prüfbericht vom 29.10.2026. Offen sind
**alle 46 Befunde**: 8 „hoch“ (Plan mit Einzelschritten: `2026-10-29-review-hoch.md`, noch nicht umgesetzt), 16
„mittel“ und 22 „niedrig“. Maßstab sind `docs/STANDARDS.md` im Repo `ralfpfitzner/test` (Teile B bis I, E2).

## Pull Requests und Freigabe

Jeder PR wird vor dem Start einzeln freigegeben. Die Pakete eines PR berühren verschiedene Dateien und entstehen parallel.

| PR | Pakete | Befunde | davon „hoch“ |
|---|---|---|---|
| 1 | A Server und Datenformat, D Admin, E CI und Release | 16 | Q-KAL-01, -02, -03 |
| 2 | C Formular und App-Rahmen | 13 | U-KAL-01, -03, -04 |
| 3 | B Liste und Performance | 17 | P-KAL-01, U-KAL-02 |
| 4 | Release 1.1.0 | – | – |

PR 1 zuerst, weil er die Fehler mit Datenverlust behebt (Schlüssel, Optionen, Beschreibung). PR 2 und PR 3 sind
unabhängig; PR 2 kommt vor PR 3, weil die Rückmeldung nach dem Speichern und die Zurück-Taste im Alltag am meisten stören.

## Paket A: Server und Datenformat (PR 1)

| Befund | Stelle | Umsetzung |
|---|---|---|
| Q-KAL-03 (hoch) | `lib/Service/Planung.php:293-307` | `linkEinfuegen` ersetzt nur die eigene Link-Zeile (App-Adresse), nie fremde Zeilen mit gleichem Anfang; Tests aus dem Hoch-Plan, Aufgabe 1 |
| Q-KAL-05 | `Planung.php:59-61` | „Floating“-Zeiten (ohne TZID, ohne Z) in der Zeitzone des Nutzers lesen, nicht als UTC |
| S-KAL-06 | `Planung.php:225-234` | Unsichtbare und Richtungszeichen (Bidi, Zero-Width) aus Titeln und Feldern entfernen |
| S-KAL-01 | `lib/Controller/ApiController.php:97` | Ausnahmen ohne Termininhalt ins Protokoll (keine Argumente, kein Trace mit Daten) |
| S-KAL-02 | `lib/Service/EinstellungenSpeicher.php:67-71` | Zu großes Feldschema schon beim Speichern ablehnen statt später still verwerfen |
| S-KAL-03, Q-KAL-10 | `lib/Service/KalenderService.php:149-154` | ETag prüfen und schreiben atomar (Sperre bzw. bedingtes Update im Backend), 409 bei Konflikt |

## Paket D: Admin (PR 1)

| Befund | Stelle | Umsetzung |
|---|---|---|
| Q-KAL-01 (hoch) | `src/components/SchemaEditor.vue` | Schlüssel bestehender Felder (gespeichert oder aus einer Vorlage) bleibt beim Umbenennen; Hoch-Plan Aufgabe 2 |
| Q-KAL-02 (hoch) | `SchemaEditor.vue` | Optionen behalten Enter und Leerzeichen beim Tippen |
| U-KAL-10 | `src/Admin.vue` | Ungespeicherte Änderungen: Hinweis und Rückfrage beim Verlassen |
| U-KAL-18 | `SchemaEditor.vue:200-276` | Bei 600 und 860 Pixel kein seitliches Scrollen, Werte nicht abgeschnitten |
| U-KAL-19 | `lib/Service/Schema.php` | Fehlermeldungen verständlich, Felder ab 1 gezählt |
| U-KAL-20 | `SchemaEditor.vue:79` | Feld entfernen mit Rückfrage oder Rückgängig, Fokus bleibt nach Verschieben am Feld |

## Paket E: CI, Release, Doku (PR 1)

| Befund | Stelle | Umsetzung |
|---|---|---|
| S-KAL-04 | `.github/workflows/release.yml:38-56` | Bauen ohne Schreib- und OIDC-Rechte; Hochladen und Attestieren in einem eigenen Job danach (STANDARDS A2.9) |
| S-KAL-05 | `.github/workflows/ci.yml:71` | Alles festgelegt: Actions per SHA, Images per Digest, Werkzeuge mit Version und Prüfsumme |
| S-KAL-07 | `SECURITY.md` | Unterstützte Versionen und Meldeweg aktuell |

Dazu für schnellere Läufe: `tools/test-nextcloud.sh` nimmt Containername und Port aus der Umgebung (`NC_NAME`,
`NC_PORT`), damit Agenten parallel testen können; Playwright-Browser in der CI aus dem Cache.

## Paket C: Formular und App-Rahmen (PR 2)

| Befund | Stelle | Umsetzung |
|---|---|---|
| U-KAL-01 (hoch) | `src/components/PlanungFormular.vue:41` | Ort-Auswahl: Radioknöpfe direkt bei ihren Beschriftungen |
| U-KAL-03 (hoch) | `PlanungFormular.vue:26-31` | Sichtbare und angesagte Rückmeldung nach dem Speichern, Fokus bleibt sinnvoll |
| U-KAL-04 (hoch) | `src/App.vue:46-48` | Zurück-Taste mit ungespeicherten Eingaben fragt nach |
| U-KAL-05 | `PlanungFormular.vue` | Konflikt 409: Eingaben bleiben, Vergleich mit dem neuen Stand und „trotzdem übernehmen“ oder „neu laden“ |
| U-KAL-06 | `src/api.js:7-9` | 429, abgelaufene Sitzung und andere Fehler mit eigener, verständlicher Meldung |
| U-KAL-08 | `App.vue:77-86` | Fokus beim Wechsel zwischen Liste und Formular auf die Überschrift, Wechsel wird angesagt |
| U-KAL-09 | `PlanungFormular.vue:33` | Nur-Lesen-Ansicht gut lesbar und per Tastatur erreichbar |
| U-KAL-13 | `App.vue:4` | Nur eine `main`-Landmarke |
| U-KAL-17 | Formular | Nextcloud-Komponenten statt eigener Elemente, Sprache einheitlich Deutsch |
| Q-KAL-09 | `PlanungFormular.vue:76-83,139-151` | Gespeicherter Wert, der keine Option mehr ist, bleibt erhalten und lässt sich speichern |
| Q-KAL-11 | `PlanungFormular.vue:26-28,204` | Erfolgsmeldung verschwindet bei der nächsten Änderung |
| Q-KAL-12 | `App.vue:58-62` | Unbekannter Kalender im Zwischenstand: Hinweis statt stillem Austausch der ID |
| P-KAL-05 | `App.vue:50-63` | Kalender und Termin parallel laden, Layout-Komponenten nur bei Bedarf |

## Paket B: Liste und Performance (PR 3)

| Befund | Stelle | Umsetzung |
|---|---|---|
| P-KAL-01 (hoch), P-KAL-06 | `Planung.php:265-284` | Serien nicht über den ganzen Zeitraum auflösen (`fastForward` zum Beginn des Zeitraums), auch für endlose tägliche Serien; Hoch-Plan Aufgabe 3 |
| U-KAL-02 (hoch) | `src/components/TerminListe.vue:44-82` | Spalte „Planung“ ab 1280 Pixel sichtbar, kein seitliches Scrollen bis 600 Pixel |
| P-KAL-02 | `KalenderService.php:90-108` | Zeitraum nur einmal laden und parsen, Seite erst nach Filter und Sortierung bauen |
| P-KAL-03 | `TerminListe.vue:125-170` | Suche entprellen, alte Anfragen abbrechen (`AbortController`) |
| P-KAL-04 | `KalenderService.php:144,155,177-184` | Kalenderliste je Anfrage einmal aufbauen |
| Q-KAL-04 | `KalenderService.php:108` | Sortierung über Zeitstempel als Zahl, nicht als Text |
| Q-KAL-06, U-KAL-11 | `KalenderService.php:103` | Suche nur in sichtbaren Feldern (Titel, Ort, Planungsfelder), nicht in Link, UID oder ISO-Datum |
| Q-KAL-07 | `TerminListe.vue:146-148` | Korrigierte Seite per `replaceState`, Zurück-Taste funktioniert |
| Q-KAL-08 | `TerminListe.vue:163,169` | Leerzeichen am Ende der Suche bleibt beim Tippen |
| U-KAL-07 | `TerminListe.vue:58-62` | Terminzeilen als Links (Mittelklick, Tastatur) |
| U-KAL-12 | `src/components/Seiten.vue:281-299` | Blätter-Knöpfe mit Namen („Erste Seite“ usw.) |
| U-KAL-14 | `TerminListe.vue:40-42` | Leere Liste nach Suche: Hinweis mit Suchbegriff und „Suche zurücksetzen“ |
| U-KAL-15 | `TerminListe.vue:44` | Laden und Zahl der Ergebnisse werden angesagt |
| U-KAL-16 | `KalenderService.php:247` | Serie zeigt den nächsten Termin im Zeitraum, keinen vergangenen |
| U-KAL-21 | `KalenderService.php:165-175` | „Contact birthdays“ und ähnliche Systemkalender ausblenden, Link auf fremde Kalender richtig |

## Testzyklen auf GitHub (STANDARDS E2)

Die CI läuft etwa 3 Minuten (PHPUnit, PHPStan gegen Nextcloud 34 und 35, `npm audit`, actionlint, Nextcloud 34 und 35
mit Browser-Tests, `gate`).

1. **Neu: alles läuft lokal.** `composer install` klappt in der Cloud-Umgebung im Container `composer:2` mit dem
   CA-Bundle des Proxys. Damit laufen PHPUnit und `tools/api-check.sh` vor dem Push, nicht erst in der CI.
2. **Vor jedem Push lokal:** `composer test:unit`, `tools/api-check.sh` für 34 und 35, `npm run lint` (falls
   vorhanden), `npm run build`, `tools/test-nextcloud.sh` mit Nextcloud 35.0.2 und `npm run test:ui`, actionlint,
   `npm audit --omit=dev`. Nextcloud 34 prüft die CI.
3. **Ein Push je PR,** ein Commit je Paket, Review (Sicherheit/Server; Oberfläche) vor dem Push.
4. **Parallel arbeiten:** je Paket eine eigene Arbeitskopie und eine eigene Test-Nextcloud (`NC_NAME`, `NC_PORT`).
5. **CHANGELOG** schreibt beim Zusammenführen einer.

## Von Ralf zu testen

- PR 1: Feld in einer echten Vorlage umbenennen, gespeicherte Termine behalten ihre Werte; Linktext ändern.
- PR 2: Formular auf Tablet und Handy, Konflikt bei gleichzeitiger Bearbeitung zu zweit.
- PR 3: Laufzeit der Liste mit dem echten Kalender (viele Serien).

## Release

Nach PR 3 Version 1.1.0 nach Skill `release-vorbereitung` (Version in `appinfo/info.xml`, `package.json`,
`package-lock.json`, CHANGELOG). Keine Änderung am Datenformat (`X-CALPLAN-…`). Das Tag setzt Ralf.
