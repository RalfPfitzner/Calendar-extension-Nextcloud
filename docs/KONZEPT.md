# Konzept Kalender-Planung

Ausgangspunkt war der Planungs-Chat vom 20.10.2026 für den ersten Nutzer ([`historie/info.md`](historie/info.md), unverändert). Seit dem Umbau zur generischen App (Oktober 2026) ist alles Organisationsbezogene Einstellung statt Code. Hier steht, wie die App gebaut ist und was bei der Umsetzung entschieden wurde.

## 1. Ablauf für die Nutzer

1. Termin wie gewohnt in der Kalender-App im Planungskalender anlegen (Datum, Uhrzeit, Titel).
2. In Nextcloud oben auf **Kalender-Planung** gehen. Die Liste zeigt die Termine des Kalenders (Standard: ab 30 Tagen vor heute bis ein Jahr im Voraus), mit Spalte „Planung: erfasst/offen“.
3. Termin anklicken, das Planungsformular ausfüllen, **Speichern**.
4. Beim Speichern trägt die App in die Terminbeschreibung die Zeile `Planung öffnen: <Link>` ein (Text einstellbar). In der Kalender-App ist der Link anklickbar und führt direkt zum Formular.

Datum und Uhrzeit werden nur in der Kalender-App geändert; das Formular zeigt sie an.

## 2. Datenformat im Termin

Eigene Eigenschaften tragen das Präfix `X-CALPLAN-`; der Rest des Namens ist der Feldschlüssel in Großbuchstaben, `_` wird `-` (`Planung::eigenschaft`). Welche Felder es gibt, bestimmt das Feldschema (Abschnitt 3); die Tabelle zeigt das Vorgabe-Schema (`schema/vorgabe.json`).

| Formularfeld | Schlüssel | Eigenschaft im VEVENT |
|---|---|---|
| Titel der Veranstaltung | `titel` | `SUMMARY` |
| Ort (Standardort / externer Ort mit Adresse) | `ort` | `LOCATION` |
| Zuständig intern | `zustaendig` | `X-CALPLAN-ZUSTAENDIG` |
| Parkmöglichkeiten / Zufahrt / Anlieferung | `zufahrt` | `X-CALPLAN-ZUFAHRT` |
| Raum-Informationen | `raum` | `X-CALPLAN-RAUM` |
| Organisation / Firma | `kontakt_organisation` | `X-CALPLAN-KONTAKT-ORGANISATION` |
| Name | `kontakt_name` | `X-CALPLAN-KONTAKT-NAME` |
| Telefon | `kontakt_telefon` | `X-CALPLAN-KONTAKT-TELEFON` |
| E-Mail | `kontakt_email` | `X-CALPLAN-KONTAKT-EMAIL` |
| Rolle / Funktion | `kontakt_rolle` | `X-CALPLAN-KONTAKT-ROLLE` |
| Zielgruppe | `zielgruppe` | `X-CALPLAN-ZIELGRUPPE` |
| Anzahl der Teilnehmenden | `teilnehmende` | `X-CALPLAN-TEILNEHMENDE` |
| Besondere Anforderungen | `anforderungen` | `X-CALPLAN-ANFORDERUNGEN` |
| Geplante Inhalte / Themenschwerpunkte | `inhalte` | `X-CALPLAN-INHALTE` |
| Benötigte Ausstattung / Technik | `ausstattung` | `X-CALPLAN-AUSSTATTUNG` |
| Benötigtes Material (geschätzt) | `material` | `X-CALPLAN-MATERIAL` |
| Benötigte weitere Betreuung / Unterstützung | `betreuung` | `X-CALPLAN-BETREUUNG` |
| Besondere Wünsche / Hinweise / Anmerkungen | `hinweise` | `X-CALPLAN-HINWEISE` |

- In der Vorgabe sind alle Felder Freitext (auch „Anzahl der Teilnehmenden“, damit „ca. 20“ oder „2 Klassen“ geht; wer Zahlen erzwingen will, stellt den Typ im Schema auf „Zahl“), mehrzeilig wo sinnvoll, höchstens 5000 Zeichen. Leere Felder entfernen die Eigenschaft.
- Pflicht ist nur der Titel; bei externem Ort die Adresse. Pflichtfelder, Längen, Optionen und Typen prüft der Server nach dem Schema; eine eingetragene E-Mail-Adresse muss gültig sein.
- Werte je Typ: Text wie eingegeben (einzeilig ohne Umbrüche), Zahl als eingegebene Zeichenkette (`12` oder `12,5`), Auswahl eine der Optionen, Ja/Nein als `1` oder fehlende Eigenschaft, Datum als `JJJJ-MM-TT`.
- Ort mit Standardort (Einstellung `ort_vorgabe`): Ist `LOCATION` leer oder gleich dem Standardort, zeigt das Formular den Standardort; jeder andere Ort gilt als externer Ort. Beim Speichern mit Standardort wird genau dieser Text als Ort eingetragen. Eine eigene Eigenschaft für „extern“ gibt es nicht.
- Ort ohne Standardort: ein freies Ortsfeld, leer erlaubt (`LOCATION` wird dann entfernt).
- Die Schlüssel sind Datenformat. Wer im Schema einen Schlüssel ändert, trennt das Feld von bereits erfassten Werten; die App liest und löscht nur Eigenschaften, deren Schlüssel im aktuellen Schema stehen, alle anderen bleiben unangetastet.
- Fremde Eigenschaften (Teilnehmende, Erinnerungen, Kategorien, `X-…` anderer Programme) bleiben unverändert.
- Vor dem Umbau hieß das Präfix `X-MSP-`; weil die App bis dahin nirgends installiert war, gibt es keinen Rückfall auf diese Namen.

## 3. Einstellungen (Admin)

Einstellungsseite unter „Verwaltung → Zusätzliche Einstellungen“ (`lib/Settings/Admin.php`, Oberfläche `src/Admin.vue`), Routen `GET/PUT /api/schema` und `GET/PUT /api/admin/einstellungen` nur für Admins (ohne `NoAdminRequired`). Alle Werte in der App-Konfiguration (`OCP\IAppConfig`, `occ config:app:set calendar_planning <schlüssel> --value …`), gelesen von `EinstellungenLader`, bereinigt in `Einstellungen` (einzeilig, ohne Steuerzeichen, Länge gedeckelt). Admin-Eingaben gelten trotzdem als fremde Eingaben.

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `ort_vorgabe` | leer | Standardort (siehe Abschnitt 2), höchstens 500 Zeichen |
| `kalender_muster` | leer | Namensfragment für die Vorbelegung der Kalenderauswahl; ohne Treffer oder ohne Muster der erste Kalender |
| `link_text` | `Planung öffnen:` | Erkennungszeichen vor dem Link in der Beschreibung; leer fällt auf die Vorgabe zurück, weil sonst jede Zeile als alter Link gälte |
| `schema` | `schema/vorgabe.json` | Feldschema als JSON (siehe unten); ungültig gespeichert heißt Vorgabe plus Warnung im Protokoll |

### Feldschema

`{"abschnitte": [{"titel", "felder": [{"key", "label", "typ", "pflicht", "maxlen", "optionen", "inListe"}]}]}`, geprüft in `Schema::ausArray`: Schlüssel `[a-z][a-z0-9_]{0,30}`, nicht reserviert (`titel`, `ort`, `ort_extern`, `uid`, `beginn`, …), eindeutig; Typen `text`, `textarea`, `email`, `tel`, `number`, `select`, `checkbox`, `date`; `maxlen` 1–5000; `optionen` nur bei `select` (1–50, eindeutig); höchstens 50 Felder, 20 Abschnitte, 3 Felder mit `inListe`; Texte einzeilig, höchstens 200 Zeichen; JSON höchstens 64 KB. Das Schema ist Admin-Eingabe und wird trotzdem wie fremde Eingabe behandelt, weil es bestimmt, welche Eigenschaften in Termine geschrieben werden. Vorlagen liegen in `schema/` (`preset-msp.json`: die Word-Vorlage des ersten Nutzers mit ihren Bezeichnungen).

Die Liste zeigt feste Spalten (Datum, Veranstaltung, Ort, Planung) plus die Felder mit `inListe` in Schema-Reihenfolge; `KalenderService::kurz` liefert sie unter `felder`.

Die Oberfläche bekommt Standardort und Schema über `GET /api/einstellungen` (jedes angemeldete Konto); Kalender-Muster und Linktext bleiben serverseitig.

## 4. Entscheidungen der Umsetzung

| Frage (aus `historie/info.md`, Abschnitt 7) | Entscheidung | Begründung |
|---|---|---|
| Namen der Eigenschaften | wie in Abschnitt 2 | Deutsch, sprechend, ohne Umlaute (iCalendar erlaubt in Namen nur A–Z, 0–9 und „-“); eigener Vendor-Präfix `X-CALPLAN-`. |
| Link „Planung öffnen“ automatisch oder manuell? | Automatisch beim ersten Speichern im Formular; ein Link mit veralteter Adresse wird ersetzt, nie verdoppelt. | Ein Link vor dem ersten Speichern ginge nur, wenn die App jeden neuen Termin anfasst. Das wäre ein Eingriff in fremde Termine, den niemand ausgelöst hat. |
| Druck-/PDF-Ansicht | noch nicht umgesetzt | optional laut Chat; nächster Schritt, wenn gewünscht. |

Weitere Entscheidungen:

- **Lesen und Schreiben** über das CalDAV-Backend von Nextcloud (`OCA\DAV\CalDAV\CalDavBackend`). Die öffentliche Kalender-Schnittstelle (OCP) kann Termine nur neu anlegen, nicht ändern. Welche Kalender jemand sieht und ob er schreiben darf, kommt trotzdem aus OCP, also aus denselben Freigaben wie in der Kalender-App.
- **Freigaben:** Wer den Kalender nur lesen darf, sieht das Formular schreibgeschützt. Als „privat“ oder „vertraulich“ markierte Termine sieht in einem geteilten Kalender nur der Eigentümer.
- **Gleichzeitiges Bearbeiten:** Gespeichert wird nur, wenn der Termin seit dem Öffnen unverändert ist (ETag). Sonst kommt die Meldung „Der Termin wurde inzwischen geändert …“, damit niemand Änderungen aus der Kalender-App überschreibt.
- **Terminserien:** Die Planung gilt für die ganze Serie und steht im Serien-Termin. Geänderte Einzeltermine einer Serie bekommen dieselben Planungsfelder. Titel und Ort übernehmen sie nur, wenn sie noch die alten Werte der Serie hatten. Die Liste zeigt eine Serie einmal, mit dem ersten Termin im Zeitraum.
- **Kalenderauswahl:** Vorbelegt über `kalender_muster`; eine andere Auswahl merkt sich die App je Konto (Nutzereinstellung `kalender` über `IUserConfig`).
- **Keine Scheduling-Mails:** Das Speichern der Planung verschickt keine Einladungs-Updates an Teilnehmende (läuft am CalDAV-Server vorbei). Termin-Zeiten ändert die App ohnehin nicht.
- **Geprüft in den Test-Nextclouds 34.0.5 mit Kalender-App 6.6.3 und 35.0.2 mit Kalender-App 6.6.4:** Bearbeiten eines Termins in der Kalender-App lässt die `X-CALPLAN-…`-Eigenschaften stehen, und der Link in der Beschreibung ist dort anklickbar. Der Browser-Test „Kalender-App: Bearbeiten des Termins lässt die Planung stehen“ prüft das bei jedem Lauf.

## 5. Datenschutz

Die Kontaktdaten Externer stehen im Termin. Sie sieht jeder, der Zugriff auf den Planungskalender hat, und jedes Gerät, das ihn per CalDAV synchronisiert. Das ist in Ordnung, solange der Kalender nur intern geteilt ist; wer die Planung erfasst, sollte das wissen. Den Kalender nicht öffentlich teilen (öffentlicher Link) und nicht als Abo nach außen geben, wenn Kontaktdaten darin stehen.

## 6. Offen

- Erster Einsatz auf einer echten Instanz: `custom_apps` beschreibbar, App aktivieren als Admin, Einstellungen setzen, Freigaben, Bearbeiten in der Kalender-App und auf Handys (DAVx⁵, iOS).
- Kopieren bzw. Duplizieren eines Termins in der Kalender-App: Bleiben die Felder erhalten, und zeigt der Link dann noch auf den alten Termin? Beim nächsten Speichern im Formular wird der Link der Kopie korrigiert.
- Die Oberfläche ist einsprachig deutsch, Tagesgrenzen der Liste liegen in `Europe/Berlin`. Für andere Sprachräume braucht es l10n und die Zeitzone aus den Nextcloud-Einstellungen.
- Felder im Editor der Kalender-App anzeigen: wartet auf eine Editor-Schnittstelle der Kalender-App; das Schema ist so gebaut, dass es dort später eingehängt werden kann.
- Ein Schema je Instanz; Schemata je Kalender bei Bedarf.
- Optional: Druck-/PDF-Ansicht, Export (Excel/CSV) der Liste.
