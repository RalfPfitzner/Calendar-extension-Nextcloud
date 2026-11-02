# Sicherheit

## Unterstützte Versionen

Korrekturen gibt es nur für die jeweils neueste Version der App (siehe `CHANGELOG.md`), und nur für die Nextcloud-Versionen, die sie unterstützt. Ältere Tags bleiben stehen, werden aber nicht gepflegt.

| App `calendar_planning` | Nextcloud | Unterstützt |
|---|---|---|
| neueste 1.x | 34 und 35 | ja |
| ältere 1.x | 34 und 35 | nein, bitte auf die neueste 1.x aktualisieren |
| 0.x (bis 0.3 als `msp_planung`) | – | nein |

## Eine Lücke melden

Über **[GitHub Security Advisories](https://github.com/RalfPfitzner/Calendar-extension-Nextcloud/security/advisories/new)** („Report a vulnerability“ im Reiter „Security“), das ist privat. Kein öffentliches Issue für eine vermutete Lücke. Falls die private Meldung einmal nicht geht, eine Mail an die Adresse im Feld `author` von `appinfo/info.xml` mit der Bitte um einen vertraulichen Kanal; darin noch keine Details.

Pakete aus den Releases sind attestiert. Ob ein Paket aus diesem Repository stammt, zeigt `gh attestation verify calendar_planning-<version>.tar.gz --repo RalfPfitzner/Calendar-extension-Nextcloud`; die SHA-256 steht in den Release-Notes.

Bitte angeben:

- Version der App (`calendar_planning`) und der Nextcloud
- was ein Angreifer damit gewinnt, nicht nur, was sich falsch verhält
- Schritte zum Nachstellen, am besten gegen eine frische Test-Nextcloud (`tools/test-nextcloud.sh`)

Das ist ein Hobbyprojekt ohne Bereitschaft: Eingangsbestätigung innerhalb von 14 Tagen, Einschätzung innerhalb von 30.

## Was eine Lücke ist

Die App hat zehn API-Routen, alle nur für angemeldete Konten (vier davon nur für Admins), und schreibt ausschließlich in bestehende Kalendertermine und in ihre eigene App-Konfiguration. Eine Lücke ist, wenn ein angemeldetes Konto mehr kann, als seine Kalender-Freigaben erlauben, oder ein nicht angemeldeter Aufruf überhaupt etwas erreicht:

- Lesen oder Schreiben eines Termins in einem Kalender, der dem Konto nicht freigegeben ist (etwa durch Raten einer Kalender-ID)
- Lesen privater oder vertraulicher Termine eines geteilten Kalenders als Empfänger der Freigabe
- Schreiben in einen nur lesend freigegebenen Kalender
- Aufruf einer API-Route ohne Anmeldung oder ohne gültiges CSRF-Token
- Einschleusen zusätzlicher iCalendar-Eigenschaften oder ganzer `VEVENT`s über Formularfelder
- Skript-Ausführung in der Oberfläche über Termininhalte
- Umgehen der Längen- oder Typprüfung, sodass Nextcloud oder die Kalender-App das Objekt danach nicht mehr lesen kann
- fremder Host im Link „Planung öffnen“, der in die Terminbeschreibung geschrieben wird
- Ändern des Feldschemas oder der Vorgaben durch ein Konto ohne Admin-Rechte
- ein Feldschema, das Eigenschaftsnamen außerhalb von `X-CALPLAN-[A-Z0-9-]+` erzeugt oder Skript in die Oberfläche bringt

## Was keine Lücke ist

Dokumentierte Entscheidungen sind keine Befunde. Sie hier zu nennen, spart beiden Seiten eine Runde.

- Kontaktdaten im Termin sieht jeder mit Zugriff auf den Kalender und jedes Gerät, das ihn synchronisiert. Das ist das Datenmodell (`docs/KONZEPT.md`, Abschnitt 4). Den Kalender nicht öffentlich teilen.
- Ein Konto mit Schreibrecht kann Terminserien anlegen, deren Auflösung Rechenzeit kostet. Das geht in der Kalender-App genauso.
- Die Rate-Limits (120 Lese- und 30 Speicheraufrufe pro Minute und Konto) sind Lastschutz, kein Sicherheitsmerkmal. Dass mehrere Konten zusammen mehr schaffen, ist kein Befund.
- Speichern verschickt keine Einladungs-Updates an Teilnehmende; die App schreibt am CalDAV-Scheduling vorbei.
- Fehler in Nextcloud oder der Kalender-App selbst: bitte dort melden.
- Alles, was ein bereits übernommenes Admin-Konto voraussetzt, etwa unschöne Werte in den Admin-Einstellungen.
- Meldungen aus Scannern ohne funktionierenden Nachweis.
- Schwachstellen in Entwicklungsabhängigkeiten (Build-Kette), solange sie nicht im gebauten `js/`-Bundle landen.

## Was die App bewusst nicht hat

Keine eigenen Datenbanktabellen, keine Hintergrundjobs, keine Aufrufe nach außen, keine öffentlichen Seiten, kein Datei-Upload. Die Admin-Einstellungen (Standardort, Kalender-Muster, Linktext, Feldschema) liegen in der App-Konfiguration von Nextcloud; sie werden beim Lesen und Schreiben bereinigt und in der Länge gedeckelt, das Schema streng geprüft (`lib/Service/Schema.php`). Setzbar über die Admin-Einstellungsseite oder `occ`. Welche Kalender ein Konto sieht und ob es schreiben darf, kommt aus der öffentlichen Kalender-Schnittstelle von Nextcloud; nur das Lesen und Schreiben der Objekte geht direkt an das CalDAV-Backend.

## Geheimnisse

Keine im Repo, auch nicht in Tests oder auskommentiert. Die Passwörter in `tools/` gelten nur für Wegwerf-Container der Tests. Ist ein Geheimnis doch einmal in einem Commit gelandet, wird es zuerst ausgetauscht und dann entfernt; die History bleibt lesbar.

## Veröffentlichung

Abgestimmt: erst der Fix in einem Release, dann das Advisory. Wer meldet, wird genannt, außer er möchte das nicht.
