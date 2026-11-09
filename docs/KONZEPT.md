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
- Werte je Typ: Text wie eingegeben (einzeilig ohne Umbrüche), Zahl als eingegebene Zeichenkette (`12` oder `12,5`), Auswahl eine der Optionen (ein gespeicherter Wert, den der Admin aus den Optionen entfernt hat, bleibt erhalten und lässt sich unverändert speichern), Ja/Nein als `1` oder fehlende Eigenschaft, Datum als `JJJJ-MM-TT`.
- Ort mit Standardort (Einstellung `ort_vorgabe`): Ist `LOCATION` leer oder gleich dem Standardort, zeigt das Formular den Standardort; jeder andere Ort gilt als externer Ort. Beim Speichern mit Standardort wird genau dieser Text als Ort eingetragen. Eine eigene Eigenschaft für „extern“ gibt es nicht.
- Ort ohne Standardort: ein freies Ortsfeld, leer erlaubt (`LOCATION` wird dann entfernt).
- Die Schlüssel sind Datenformat. Wer im Schema einen Schlüssel ändert, trennt das Feld von bereits erfassten Werten; die App liest und löscht nur Eigenschaften, deren Schlüssel im aktuellen Schema stehen, alle anderen bleiben unangetastet.
- `X-CALPLAN-LINK` hält die zuletzt in die Beschreibung geschriebene Link-Zeile (siehe Abschnitt 4, Link „Planung öffnen“); der Feldschlüssel `link` ist dafür reserviert.
- Fremde Eigenschaften (Teilnehmende, Erinnerungen, Kategorien, `X-…` anderer Programme) bleiben unverändert.
- Vor dem Umbau hießen App-ID `msp_planung` und Präfix `X-MSP-`. **Umbenennung freigegeben von Ralf am 28.10.2026**, mit Jonas abgestimmt: Die App soll organisationsneutral auch für andere Instanzen taugen, und sie war bis dahin auf keiner Instanz installiert, es gab also keine gespeicherten Planungen. Deshalb gibt es keinen Rückfall auf die alten Namen und keine Umwandlung bestehender Termine. Die frühere Regel „Feldnamen nie umbenennen“ gilt ab hier für die Schlüssel in `schema/vorgabe.json` und das Präfix `X-CALPLAN-`.

## 3. Einstellungen (Admin)

Einstellungsseite im eigenen Bereich „Verwaltung → Kalender-Planung“ (`lib/Settings/Admin.php` mit `AdminSection.php`, Oberfläche `src/Admin.vue`), Routen `GET/PUT /api/schema` und `GET/PUT /api/admin/einstellungen` nur für Admins (ohne `NoAdminRequired`). Alle Werte in der App-Konfiguration (`OCP\IAppConfig`, `occ config:app:set calendar_planning <schlüssel> --value …`), gelesen und geschrieben von `EinstellungenSpeicher`, bereinigt in `Einstellungen` (einzeilig, ohne Steuer-, unsichtbare und Richtungszeichen, Länge gedeckelt). Admin-Eingaben gelten trotzdem als fremde Eingaben. Jede gespeicherte Änderung (Schema oder Vorgaben) geht als `CriticalActionPerformedEvent` an das Audit-Protokoll von Nextcloud (App `admin_audit`, falls aktiv: mit Konto und Zeit), nur mit der Zahl der Abschnitte und Felder, ohne Inhalte.

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `ort_vorgabe` | leer | Standardort (siehe Abschnitt 2), höchstens 500 Zeichen |
| `kalender_muster` | leer | Namensfragment für die Vorbelegung der Kalenderauswahl; ohne Treffer oder ohne Muster der erste Kalender |
| `link_text` | `Planung öffnen:` | Erkennungszeichen vor dem Link in der Beschreibung; leer fällt auf die Vorgabe zurück, weil sonst jede Zeile als alter Link gälte |
| `schema` | `schema/vorgabe.json` | Feldschema als JSON (siehe unten), höchstens 64 KiB; die Einstellungsseite lehnt ein größeres Schema beim Speichern ab. Ungültig gespeichert (z. B. von Hand über `occ`) heißt Vorgabe plus Warnung im Protokoll. Steht als „lazy“ (`occ config:app:set … schema --lazy --value …`), damit Nextcloud es nicht bei jeder Anfrage mitlädt; ältere, nicht-lazy gespeicherte Werte bleiben lesbar und werden beim nächsten Speichern umgestellt |

### Feldschema

`{"abschnitte": [{"titel", "felder": [{"key", "label", "typ", "pflicht", "maxlen", "optionen", "inListe"}]}]}`, geprüft in `Schema::ausArray`: Schlüssel `[a-z][a-z0-9_]{0,30}`, nicht reserviert (`titel`, `ort`, `ort_extern`, `uid`, `beginn`, …, `link`), eindeutig; Typen `text`, `textarea`, `email`, `tel`, `number`, `select`, `checkbox`, `date`; `maxlen` 1–5000; `optionen` nur bei `select` (1–50, eindeutig); höchstens 50 Felder, 20 Abschnitte, 3 Felder mit `inListe`; Texte einzeilig, höchstens 200 Zeichen; JSON höchstens 64 KB. Das Schema ist Admin-Eingabe und wird trotzdem wie fremde Eingabe behandelt, weil es bestimmt, welche Eigenschaften in Termine geschrieben werden. Vorlagen liegen in `schema/` (`preset-msp.json`: die Word-Vorlage des ersten Nutzers mit ihren Bezeichnungen).

Schema-Editor (`src/components/SchemaEditor.vue`): Nur bei neuen, noch nie gespeicherten Feldern folgt der Schlüssel der Bezeichnung und lässt sich von Hand ändern. Gespeicherte Felder und Felder aus einer geladenen Vorlage behalten ihren Schlüssel beim Umbenennen (Schlüsselfeld nur lesbar), damit erfasste Werte am Feld bleiben. Fällt beim Speichern ein bisher gespeicherter Schlüssel weg (Feld entfernt, Vorlage geladen), fragt die Oberfläche vorher nach; der Server nennt die weggefallenen Felder in der Antwort (`entfernt`). Ob es zu einem wegfallenden Schlüssel Werte gibt, prüft die App nicht: Dafür müsste sie alle Kalender aller Konten lesen. Ablehnen wäre falsch, weil Felder wegfallen dürfen; die Werte bleiben im Termin und erscheinen wieder, sobald ein Feld mit demselben Schlüssel angelegt wird. Fehlermeldungen der Prüfung zählen Abschnitte und Felder wie der Editor ab 1 und nennen die Bezeichnung. Ungespeicherte Änderungen zeigt die Seite an, beim Verlassen fragt der Browser nach. Entfernte Felder lassen sich mit „Rückgängig“ zurückholen; ist der Editor schmaler als 860 Pixel, stehen die Felder als Karten untereinander statt als Tabelle.

Die Liste zeigt feste Spalten (Datum, Veranstaltung, Ort, Planung) plus die Felder mit `inListe` in Schema-Reihenfolge; `KalenderService::kurz` liefert sie unter `felder`.

Die Oberfläche bekommt Standardort und Schema über `GET /api/einstellungen` (jedes angemeldete Konto); Kalender-Muster und Linktext bleiben serverseitig.

## 4. Entscheidungen der Umsetzung

| Frage (aus `historie/info.md`, Abschnitt 7) | Entscheidung | Begründung |
|---|---|---|
| Namen der Eigenschaften | wie in Abschnitt 2 | Deutsch, sprechend, ohne Umlaute (iCalendar erlaubt in Namen nur A–Z, 0–9 und „-“); eigener Vendor-Präfix `X-CALPLAN-`. |
| Link „Planung öffnen“ automatisch oder manuell? | Automatisch beim ersten Speichern im Formular; ein Link mit veralteter Adresse oder altem Linktext wird an seiner Stelle ersetzt, nie verdoppelt. Die zuletzt geschriebene Zeile merkt sich der Termin in `X-CALPLAN-LINK` (Schlüssel `link` ist deshalb reserviert); als eigener Link gilt nur eine Zeile mit genau diesem Inhalt. Termine aus Versionen bis 1.0.1 (ohne `X-CALPLAN-LINK`) erkennt die App an einer Zeile, die nur aus aktuellem oder Vorgabe-Linktext und der Adresse `…/apps/calendar_planning/?kalender=…&termin=…` besteht. Alle anderen Zeilen gehören dem Nutzer und bleiben, auch solche mit eigenem Text vor einer Planungs-Adresse oder mit dem Linktext am Anfang. | Ein Link vor dem ersten Speichern ginge nur, wenn die App jeden neuen Termin anfasst. Das wäre ein Eingriff in fremde Termine, den niemand ausgelöst hat. |
| Druck-/PDF-Ansicht | noch nicht umgesetzt | optional laut Chat; nächster Schritt, wenn gewünscht. |

Weitere Entscheidungen:

- **Lesen und Schreiben** über das CalDAV-Backend von Nextcloud (`OCA\DAV\CalDAV\CalDavBackend`). Die öffentliche Kalender-Schnittstelle (OCP) kann Termine nur neu anlegen, nicht ändern. Welche Kalender jemand sieht und ob er schreiben darf, kommt trotzdem aus OCP, also aus denselben Freigaben wie in der Kalender-App.
- **Freigaben:** Wer den Kalender nur lesen darf, sieht das Formular schreibgeschützt. Als „privat“ oder „vertraulich“ markierte Termine sieht in einem geteilten Kalender nur der Eigentümer.
- **Gleichzeitiges Bearbeiten:** Gespeichert wird nur, wenn der Termin seit dem Öffnen unverändert ist (ETag). Sonst kommt die Meldung „Der Termin wurde inzwischen geändert …“, damit niemand Änderungen aus der Kalender-App überschreibt. Lesen, Vergleich und Schreiben laufen unter einer Sperre je Termin (Sperrdienst von Nextcloud, `ILockingProvider`): Speichern zwei Leute gleichzeitig mit demselben Stand, gewinnt genau einer, der andere bekommt 409. Bricht die Datenbank einen Schritt wegen einer Sperre ab (SQLite: „database is locked“), wiederholt die App ihn bis zu fünfmal. Schreibzugriffe über CalDAV (Kalender-App, Handys) nimmt die Sperre nicht mit; dort bleibt das Zeitfenster so klein wie bei CalDAV selbst. Die Sperre wirkt nur mit eingeschaltetem File-Locking von Nextcloud (`filelocking.enabled`, Vorgabe `true`); ist es aus, gibt Nextcloud einen Sperrdienst ohne Wirkung zurück, und es bleibt allein der ETag-Vergleich (zwei gleichzeitige Speicherungen mit demselben Stand können sich dann wieder überholen). Ist die Sperre gerade belegt, meldet die App „Der Termin wird gerade gespeichert. Bitte gleich noch einmal versuchen.“ (409); ein veralteter ETag meldet weiter „… inzwischen geändert …“.
- **Zeiten ohne Zeitzone** („Floating“, ohne `TZID` und ohne `Z`) gelten als Ortszeit in `Europe/Berlin`, der Zone, in der die Oberfläche anzeigt, und werden wie in der Kalender-App mit ihrer Wanduhrzeit gezeigt. Ganztägige Termine bleiben ein Datum.
- **Eingaben** verlieren Steuerzeichen sowie unsichtbare und Richtungszeichen (z. B. U+200B, U+202E, U+2066–U+2069, U+FEFF). Nullbreite-Verbinder (U+200C, U+200D) bleiben, weil Emoji-Folgen und manche Schriften sie brauchen.
- **Protokoll:** Unerwartete Fehler landen im Nextcloud-Protokoll nur mit Fehlerklasse, Code, Stelle, Kalender-ID und einem Hash des Termins, nie mit Fehlermeldung, Stacktrace oder Termininhalt (Fehler aus dem iCalendar-Parser zitieren den Termintext).
- **Terminserien:** Die Planung gilt für die ganze Serie und steht im Serien-Termin. Geänderte Einzeltermine einer Serie bekommen dieselben Planungsfelder. Titel und Ort übernehmen sie nur, wenn sie noch die alten Werte der Serie hatten. Die Liste zeigt eine Serie einmal, mit dem nächsten Termin ab jetzt, wenn er im Zeitraum liegt, sonst mit dem ersten im Zeitraum (U-KAL-16, von Ralf am 02.11.2026 so bestätigt), nie mit dem ersten der Serie überhaupt. Den Termin findet der EventIterator von Sabre per `fastForward`; bei einfachen Regeln (täglich oder wöchentlich ohne COUNT, BYxxx nur BYDAY) rückt der Beginn vorher um ganze Perioden bis kurz vor den Zeitraum vor, damit auch endlose tägliche Serien schnell sind und nicht an der Grenze von 3 500 Terminen scheitern (P-KAL-01, P-KAL-06). Serien, die sich so nicht vorrücken lassen (z. B. mit BYMONTH, BYMONTHDAY, COUNT, RDATE, EXRULE oder monatlich/jährlich) und vor dem Zeitraum mehr als 3 500 Termine haben, fehlen in der Liste; das Protokoll vermerkt sie als übersprungen (`MaxInstancesExceededException`, ohne Inhalte). Ganztägige Serien vergleichen EXDATE und RECURRENCE-ID als Datum in der Ortszeit, auch wenn ein Programm (Outlook) sie als Mitternacht mit TZID schreibt. Bei Serien mit einer Uhrzeit in der Sommerzeit-Lücke (02:xx) zeigt die Liste nach der Umstellung wieder 02:xx wie in RFC 5545, Sabres `expand()` bleibt dort bei 03:xx.
- **Liste:** Jedes Kalenderobjekt wird je Anfrage höchstens einmal geladen und geparst (P-KAL-02): `calendarQuery` läuft zweimal mit je einer Grenze (nur Beginn: endet danach, nur Ende: beginnt davor), beides reines SQL ohne Parsen im Backend; geparst wird nur die Schnittmenge. Zeitraum, Sichtbarkeit, Suche und Serien prüft die App, danach wird sortiert (Zeitpunkt als Unix-Zeit, dann Titel, dann URI; ganztägige Termine ab Mitternacht in `Europe/Berlin`, Q-KAL-04) und die Seite ausgeschnitten. Die Suche durchsucht nur, was die Oberfläche zeigt: Titel, Ort, Planungsfelder und das Datum als TT.MM.JJJJ (bei ganztägigen Terminen der letzte Tag, nicht der Tag nach dem Ende), nicht Beschreibung, Link, UID oder ISO-Zeitstempel; Groß-/Kleinschreibung egal, ä/ö/ü/ß gleich ae/oe/ue/ss (Q-KAL-06, U-KAL-11).
- **Kalenderauswahl:** Nur CalDAV-Kalender, ohne Systemkalender (Geburtstage `contact_birthdays`, Kalender anderer Apps `app-generated--…`, U-KAL-21). Geteilte Kalender haben für alle Konten dieselbe ID, der Link in der Beschreibung führt also auch die Freigabe zum Termin. Vorbelegt über `kalender_muster`; eine andere Auswahl merkt sich die App je Konto (Nutzereinstellung `kalender` über `IUserConfig`).
- **Keine Scheduling-Mails:** Das Speichern der Planung verschickt keine Einladungs-Updates an Teilnehmende (läuft am CalDAV-Server vorbei). Termin-Zeiten ändert die App ohnehin nicht.
- **Geprüft in den Test-Nextclouds 34.0.5 und 35.0.2 mit Kalender-App 6.6.3 bzw. 6.6.4, seit 09.11.2026 beide mit 6.6.5 (Browser-Tests grün):** Bearbeiten eines Termins in der Kalender-App lässt die `X-CALPLAN-…`-Eigenschaften stehen, und der Link in der Beschreibung ist dort anklickbar. Der Browser-Test „Kalender-App: Bearbeiten des Termins lässt die Planung stehen“ prüft das bei jedem Lauf.

## 5. Datenschutz

Die Kontaktdaten Externer stehen im Termin. Sie sieht jeder, der Zugriff auf den Planungskalender hat, und jedes Gerät, das ihn per CalDAV synchronisiert. Das ist in Ordnung, solange der Kalender nur intern geteilt ist; wer die Planung erfasst, sollte das wissen. Den Kalender nicht öffentlich teilen (öffentlicher Link) und nicht als Abo nach außen geben, wenn Kontaktdaten darin stehen.

## 6. Offen

- Erster Einsatz auf einer echten Instanz: `custom_apps` beschreibbar, App aktivieren als Admin, Einstellungen setzen, Freigaben, Bearbeiten in der Kalender-App und auf Handys (DAVx⁵, iOS).
- Kopieren bzw. Duplizieren eines Termins in der Kalender-App: Bleiben die Felder erhalten, und zeigt der Link dann noch auf den alten Termin? Beim nächsten Speichern im Formular wird der Link der Kopie korrigiert.
- Die Oberfläche ist einsprachig deutsch, Tagesgrenzen der Liste und Zeiten ohne Zeitzone liegen in `Europe/Berlin` (`KalenderService::ZONE`, `src/datum.js`). Für andere Sprachräume braucht es l10n und die Zeitzone aus den Nextcloud-Einstellungen.
- Felder im Editor der Kalender-App anzeigen: wartet auf eine Editor-Schnittstelle der Kalender-App; das Schema ist so gebaut, dass es dort später eingehängt werden kann.
- Ein Schema je Instanz; Schemata je Kalender bei Bedarf.
- Optional: Druck-/PDF-Ansicht, Export (Excel/CSV) der Liste.
