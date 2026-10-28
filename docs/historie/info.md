# Kalender-Erweiterung Nextcloud – Makerspace Mayen-Koblenz

Zusammenfassung des Planungs-Chats vom 20.10.2026 als Grundlage für die Weiterarbeit im Repo Calendar-extension-Nextcloud. Diese Datei hält den Stand des Chats fest und wird nicht nachträglich geändert; was bei der Umsetzung entschieden wurde, steht in [`KONZEPT.md`](KONZEPT.md).

## 1. Ziel und Zweck

- **Nutzer:** Makerspace Mayen-Koblenz (MSP). Die Anforderung kommt von Eva Pfitzner.
- **Zweck:** Veranstaltungen sollen vollständig im Nextcloud-Kalender („MSP Cloud Kalender“) geplant werden. Bisher läuft das über eine Word-Vorlage („Vorlage blanko Planung alle Veranstaltungen.docx“). Die Planungsdaten aus dieser Vorlage sollen direkt im Kalender gepflegt werden.
- **Ausgangsanfragen von Eva** (Mails vom 20.10.2026):
  - 12:09: Wunsch nach einem Export des Kalenders, z. B. als Excel nach Datum mit allen Einträgen. Die Überschriften würden reichen.
  - 14:58: Zusendung der Word-Vorlage mit dem Wunsch, diese direkt in den Cloud-Kalender einzubinden.

## 2. Funktionen

### Muss

- Planungsfelder der Word-Vorlage pro Termin erfassen, bearbeiten und speichern (Felder siehe Abschnitt 5).
- Die Daten werden im Kalendertermin selbst gespeichert, nicht in einer separaten Datei.
- Eigene Ansicht „MSP-Planung“ in Nextcloud:
  - Liste der Termine aus dem MSP-Kalender
  - Formular pro Termin, aufgebaut nach der Word-Vorlage
- Link „Planung öffnen“ in der Terminbeschreibung, damit man aus der normalen Kalender-App mit einem Klick zum Formular kommt.
- Ort mit Vorgabe „MakerSpace Bendorf“ und Umschalter auf „Externer Ort“ mit Adressfeld.

### Später / optional

- Druck- bzw. PDF-Ansicht im Layout der Word-Vorlage.

## 3. Technik

- **Plattform:** Eigene Nextcloud-App, die neben der Kalender-App läuft. Die Kalender-App selbst wird nicht verändert.
- **Sprache:** PHP (Backend) und Vue (Frontend), wie bei Nextcloud-Apps üblich.
- **Nextcloud-Version:** 32.0.5
- **Speicherung:** Als eigene iCal-Eigenschaften im Termin (Präfix `X-MSP-…`). Die Termine werden über CalDAV bzw. das Kalender-Backend von Nextcloud gelesen und geschrieben.
- **Installation:** Als installierbares Paket für `custom_apps`.

## 4. Anbindungen

- **Nextcloud:** Kalender „MSP Cloud Kalender“ auf Ralfs Nextcloud-Instanz. Die App läuft innerhalb dieser Instanz.
- **Bestellportal MakerSpace:** Im Chat nicht besprochen.
- **Mail:** Keine Mail-Anbindung besprochen. Mails dienten nur als Quelle der Anforderungen.
- **Claude-Connector zu Nextcloud:** Es gibt keinen offiziellen Connector. Möglich wäre ein Community-MCP-Server als benutzerdefinierter Connector, veröffentlicht über den Nginx Proxy Manager. Das wurde nur als Möglichkeit genannt und ist für die App nicht nötig.

## 5. Datenmodell und Schnittstellen

Felder aus der Word-Vorlage und ihre Zuordnung:

| Abschnitt | Feld | Speicherung |
|---|---|---|
| 1. Grunddaten | Titel der Veranstaltung | Termintitel (`SUMMARY`) |
| | Zuständig intern | X-Eigenschaft, Freitext |
| | Ort (Vorgabe: MakerSpace Bendorf) / Externer Ort – Adresse | Termin-Ort (`LOCATION`) |
| | Parkmöglichkeiten / Zufahrt / Anlieferung | X-Eigenschaft |
| | Raum-Informationen | X-Eigenschaft |
| 2. Kontaktdaten | Organisation / Firma | X-Eigenschaft |
| | Name | X-Eigenschaft |
| | Telefon | X-Eigenschaft |
| | E-Mail | X-Eigenschaft |
| | Rolle / Funktion | X-Eigenschaft |
| 3. Teilnehmende Gruppe | Zielgruppe | X-Eigenschaft |
| | Anzahl der Teilnehmenden | X-Eigenschaft |
| | Besondere Anforderungen (z. B. Barrierefreiheit, Sprache, Aufsichtspflicht) | X-Eigenschaft |
| 4. Inhalte & Werkzeuge | Geplante Inhalte / Themenschwerpunkte | X-Eigenschaft |
| | Benötigte Maschinen / Tools | X-Eigenschaft, Freitext |
| | Benötigtes Material (geschätzt) | X-Eigenschaft |
| | Benötigte weitere Betreuung / Unterstützung | X-Eigenschaft |
| 5. Hinweise | Besondere Wünsche / Hinweise / Anmerkungen | X-Eigenschaft |

Die konkreten Namen der X-Eigenschaften sind noch nicht festgelegt.

## 6. Getroffene Entscheidungen

| Entscheidung | Begründung |
|---|---|
| Kein Excel-Export und keine Zusatzdatei | Ralf: Die Daten sollen im Kalender gepflegt werden. |
| Eigene Nextcloud-App statt Umbau oder Fork der Kalender-App | Die Kalender-App hat keine Schnittstelle, über die man zusätzliche Felder in ihren Termin-Editor einhängen kann. Ein Fork würde bei jedem Nextcloud-Update brechen. |
| Speicherung als X-Eigenschaften im Termin | Die Daten liegen damit wirklich im Kalender, synchronisieren mit und bleiben beim Verschieben oder Kopieren eines Termins erhalten. |
| „Zuständig intern“ als Freitext | Entscheidung von Ralf. |
| „Maschinen/Tools“ als Freitext | Entscheidung von Ralf. |
| Entwicklung durch Claude, Test durch Ralf auf der eigenen Instanz | Claude hat keinen Zugriff auf die Nextcloud-Instanz. |

**Hinweis Datenschutz:** Die Kontaktdaten Externer sieht jeder, der Zugriff auf den MSP-Kalender hat. Das ist in Ordnung, solange der Kalender nur intern geteilt ist. Eva sollte das wissen.

## 7. Offene Fragen

- Darf Ralf auf der Instanz eigene Apps in `custom_apps` installieren? Das wurde gefragt, aber noch nicht beantwortet.
- Konkrete Namen der `X-MSP-…`-Eigenschaften.
- Ob und wann die Druck- bzw. PDF-Ansicht umgesetzt wird.
- Wie der Link „Planung öffnen“ in die Terminbeschreibung kommt, also automatisch oder manuell.

## 8. Nächste Schritte

1. Installationsrechte für eigene Apps auf der Nextcloud-Instanz klären.
2. App-Gerüst für Nextcloud 32 anlegen.
3. Lesen und Schreiben von Terminen des MSP-Kalenders inklusive X-Eigenschaften umsetzen.
4. Ansicht „MSP-Planung“ mit Terminliste und Formular nach der Vorlage bauen.
5. Link „Planung öffnen“ in der Terminbeschreibung umsetzen.
6. Paket bauen, auf der Instanz installieren und testen.
7. Optional: Druck- bzw. PDF-Ansicht ergänzen.
