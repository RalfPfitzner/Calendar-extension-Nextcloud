# Kalender-Planung – Kalender-Erweiterung für Nextcloud

Nextcloud-App für Vereine, Werkstätten und andere Gruppen, die Veranstaltungen im Nextcloud-Kalender planen: Zu jedem Termin gibt es ein Planungsformular, dessen Felder der Admin festlegt (Vorgabe: Grunddaten, Kontaktdaten, teilnehmende Gruppe, Inhalte & Ausstattung, Hinweise). Die Felder stehen als Eigenschaften im Kalendertermin selbst und synchronisieren mit. Die Kalender-App bleibt unverändert.

- Ansicht **Kalender-Planung** in Nextcloud: Liste der Termine (Zeitraum, Suche, seitenweise) und Formular je Termin
- Felder des Formulars legt der Admin in den Einstellungen fest (Abschnitte, Typen, Pflicht, Listenspalten); Vorgabe sind 16 Felder in fünf Abschnitten
- Ort mit vom Admin gesetztem Standardort oder externem Ort mit Adresse; ohne Standardort ein freies Ortsfeld
- Link „Planung öffnen“ in der Terminbeschreibung: aus der Kalender-App mit einem Klick zum Formular
- Rechte wie in der Kalender-App: Nur-Lese-Freigaben sehen das Formular schreibgeschützt, private Termine bleiben privat
- Schutz vor Überschreiben, wenn der Termin inzwischen in der Kalender-App geändert wurde

Umsetzung, Datenformat und offene Punkte: [`docs/KONZEPT.md`](docs/KONZEPT.md). Die Vorgeschichte (Anforderungen des ersten Nutzers, Stand 20.10.2026) liegt unverändert in [`docs/historie/info.md`](docs/historie/info.md).

**Stand: Nextcloud 34 und 35.** Die CI testet jede Änderung in Test-Nextclouds 34.0.5 (Kalender-App 6.6.3) und 35.0.2 (Kalender-App 6.6.4).

## Installation

Voraussetzung: Admin-Rechte in der Nextcloud und ein beschreibbares `custom_apps`-Verzeichnis (beim offiziellen Docker-Image Standard).

1. `calendar_planning-<version>.tar.gz` aus dem GitHub-Release laden (oder selbst bauen, siehe unten). Die SHA-256-Summe steht in den Release-Notes; eine Build-Provenance belegt, dass das Paket aus dem Release-Workflow stammt:

   ```bash
   sha256sum calendar_planning-<version>.tar.gz
   gh attestation verify calendar_planning-<version>.tar.gz --repo RalfPfitzner/Calendar-extension-Nextcloud
   ```

2. Auf dem Server nach `custom_apps` entpacken, Eigentümer auf den Webserver-Nutzer setzen und aktivieren. Beim offiziellen Docker-Image:

   ```bash
   docker cp calendar_planning-<version>.tar.gz nextcloud:/tmp/
   docker exec nextcloud tar xzf /tmp/calendar_planning-<version>.tar.gz -C /var/www/html/custom_apps
   docker exec nextcloud chown -R www-data:www-data /var/www/html/custom_apps/calendar_planning
   docker exec -u www-data nextcloud php occ app:enable calendar_planning
   ```

   Ohne Docker: in das `custom_apps`-Verzeichnis der Installation entpacken, `chown -R www-data:www-data calendar_planning`, dann `sudo -u www-data php occ app:enable calendar_planning`.
3. In Nextcloud erscheint oben **Kalender-Planung**. Ohne Einstellungen wählt die App den ersten Kalender des Kontos vor, und das Formular hat ein freies Ortsfeld.

**Update:** alten Ordner `custom_apps/calendar_planning` löschen, neues Paket entpacken, `occ upgrade` ausführen. Die Daten liegen in den Terminen und bleiben erhalten.

**Entfernen:** `occ app:remove calendar_planning`. Die Planungsfelder bleiben in den Terminen stehen und stören die Kalender-App nicht.

## Einstellungen (Admin)

Unter **Verwaltung → Zusätzliche Einstellungen → Kalender-Planung** gibt es die Vorgaben und den Editor für die Felder des Planungsformulars. Alles ist optional; ohne Einstellungen läuft die App mit dem Vorgabe-Schema (16 Felder in fünf Abschnitten, siehe `schema/vorgabe.json`). Die Vorgaben lassen sich auch über `occ` setzen:

| Schlüssel | Wirkung | Beispiel |
|---|---|---|
| `ort_vorgabe` | Standardort. Im Formular gibt es dann die Wahl „Standardort“ oder „Externer Ort“ mit Adresse. Leer: ein freies Ortsfeld. | `occ config:app:set calendar_planning ort_vorgabe --value "Vereinsheim"` |
| `kalender_muster` | Namensfragment, nach dem die Kalenderauswahl vorbelegt wird (Groß-/Kleinschreibung egal). Leer: erster Kalender. | `occ config:app:set calendar_planning kalender_muster --value "Planung"` |
| `link_text` | Text vor dem Link in der Terminbeschreibung. Vorgabe „Planung öffnen:“. | `occ config:app:set calendar_planning link_text --value "Zur Planung:"` |

Jedes Konto merkt sich seine Kalenderauswahl zusätzlich selbst. Wird `link_text` später geändert, ersetzt das nächste Speichern eines Termins seine alte Link-Zeile; bis dahin steht sie unverändert in der Beschreibung.

### Felder des Planungsformulars

Der Admin legt Abschnitte und Felder fest; Titel und Ort stehen immer im Formular. Je Feld: Bezeichnung, Schlüssel (`a–z`, `0–9`, `_`, mit Buchstaben beginnend, höchstens 31 Zeichen), Typ (Text einzeilig oder mehrzeilig, E-Mail, Telefon, Zahl, Auswahl mit Optionen, Ja/Nein, Datum), Pflicht, Höchstlänge (bis 5000 Zeichen) und ob das Feld als Spalte in der Liste steht (höchstens drei). Höchstens 50 Felder. Der Schlüssel bestimmt den Namen der Eigenschaft im Termin (`X-CALPLAN-` plus Schlüssel in Großbuchstaben, `_` wird `-`): Wer einen Schlüssel ändert, trennt das Feld von bereits erfassten Werten; die alten Eigenschaften bleiben im Termin stehen und stören nicht.

„Vorgabe laden“ holt das mitgelieferte Schema in den Editor, „Vorlage laden“ eine der Vorlagen aus `schema/` (derzeit die Word-Vorlage des ersten Nutzers). Gespeichert wird erst mit „Felder speichern“; der Server prüft jedes Schema und lehnt ungültige ab, das alte bleibt dann stehen. Das Schema liegt als JSON in der App-Konfiguration (`occ config:app:get calendar_planning schema`); ein von Hand gesetzter ungültiger Wert fällt auf die Vorgabe zurück und landet als Warnung im Protokoll.

## Entwicklung

```
appinfo/        App-Beschreibung und Routen
lib/            PHP: Controller, KalenderService (Kalenderzugriff), Planung (iCalendar <-> Formular), Einstellungen, Schema, Settings (Admin-Seite)
schema/         Vorgabe-Schema und Vorlagen (JSON)
src/            Oberfläche (Vue 3, @nextcloud/vue), gebaut nach js/ und css/
templates/      Seitenrahmen
tests/Unit/     PHPUnit-Tests der Feldzuordnung, Schema-Prüfung und Einstellungen
test-ui/        Browser-Tests gegen eine Test-Nextcloud (Playwright)
tools/          Test-Nextcloud in Docker, Testdaten, Paketbau, API-Check
```

```bash
composer install && composer test:unit          # PHP-Tests (braucht PHP 8.3 für das Paket nextcloud/ocp)
tools/api-check.sh 35.0.2                        # PHPStan gegen die Nextcloud-Schnittstellen dieser Version
npm ci && npm run build                          # Oberfläche bauen
tools/test-nextcloud.sh                          # Nextcloud 35.0.2 + Kalender-App in Docker, Port 8080
NC_VERSION=34.0.5 KALENDER_VERSION=6.6.3 tools/test-nextcloud.sh   # die andere unterstützte Hauptversion
npm run test:ui                                  # Browser-Tests
tools/test-nextcloud.sh --stopp                  # Container entfernen
tools/paket.sh                                   # build/calendar_planning-<version>.tar.gz
```

Während der Entwicklung baut `npm run watch` die Oberfläche bei jeder Änderung neu; der Test-Container bindet das Arbeitsverzeichnis direkt ein.

## Sicherheit

Sicherheitslücken bitte nicht als öffentliches Issue, sondern wie in [`SECURITY.md`](SECURITY.md) beschrieben melden. Dort steht auch, was als Lücke zählt und was dokumentierte Entscheidung ist.

## Lizenz

GPL-3.0-or-later, siehe [`LICENSE`](LICENSE).
