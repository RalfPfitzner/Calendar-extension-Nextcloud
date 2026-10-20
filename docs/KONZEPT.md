# Konzept MSP-Planung

Grundlage ist der Planungs-Chat vom 20.10.2026 ([`info.md`](info.md)). Hier steht, wie die App gebaut ist und was bei der Umsetzung entschieden wurde.

## 1. Ablauf für die Nutzer

1. Termin wie gewohnt in der Kalender-App im „MSP Cloud Kalender“ anlegen (Datum, Uhrzeit, Titel).
2. In Nextcloud oben auf **MSP-Planung** gehen. Die Liste zeigt die Termine des Kalenders (Standard: ab 30 Tagen vor heute bis ein Jahr im Voraus), mit Spalte „Planung: erfasst/offen“.
3. Termin anklicken, das Formular nach der Word-Vorlage ausfüllen, **Speichern**.
4. Beim Speichern trägt die App in die Terminbeschreibung die Zeile `Planung öffnen: <Link>` ein. In der Kalender-App ist der Link anklickbar und führt direkt zum Formular.

Datum und Uhrzeit werden nur in der Kalender-App geändert; das Formular zeigt sie an.

## 2. Datenformat im Termin

| Formularfeld | Eigenschaft im VEVENT |
|---|---|
| Titel der Veranstaltung | `SUMMARY` |
| Ort (MakerSpace Bendorf / Externer Ort – Adresse) | `LOCATION` |
| Zuständig intern | `X-MSP-ZUSTAENDIG` |
| Parkmöglichkeiten / Zufahrt / Anlieferung | `X-MSP-ZUFAHRT` |
| Raum-Informationen | `X-MSP-RAUM` |
| Organisation / Firma | `X-MSP-KONTAKT-ORGANISATION` |
| Name | `X-MSP-KONTAKT-NAME` |
| Telefon | `X-MSP-KONTAKT-TELEFON` |
| E-Mail | `X-MSP-KONTAKT-EMAIL` |
| Rolle / Funktion | `X-MSP-KONTAKT-ROLLE` |
| Zielgruppe | `X-MSP-ZIELGRUPPE` |
| Anzahl der Teilnehmenden | `X-MSP-TEILNEHMENDE` |
| Besondere Anforderungen | `X-MSP-ANFORDERUNGEN` |
| Geplante Inhalte / Themenschwerpunkte | `X-MSP-INHALTE` |
| Benötigte Maschinen / Tools | `X-MSP-MASCHINEN` |
| Benötigtes Material (geschätzt) | `X-MSP-MATERIAL` |
| Benötigte weitere Betreuung / Unterstützung | `X-MSP-BETREUUNG` |
| Besondere Wünsche / Hinweise / Anmerkungen | `X-MSP-HINWEISE` |

- Alle Felder sind Freitext (auch „Anzahl der Teilnehmenden“, damit „ca. 20“ oder „2 Klassen“ geht), mehrzeilig wo sinnvoll, höchstens 5000 Zeichen. Leere Felder entfernen die Eigenschaft.
- Pflicht ist nur der Titel; bei externem Ort die Adresse. Eine eingetragene E-Mail-Adresse muss gültig sein.
- Ort: Ist `LOCATION` leer oder „MakerSpace Bendorf“, zeigt das Formular „MakerSpace Bendorf“; jeder andere Ort gilt als externer Ort. Beim Speichern mit „MakerSpace Bendorf“ wird genau dieser Text als Ort eingetragen. Eine eigene Eigenschaft für „extern“ gibt es nicht.
- Die Namen sind Datenformat und werden nie umbenannt. Neue Felder bekommen neue Namen.
- Fremde Eigenschaften (Teilnehmende, Erinnerungen, Kategorien, `X-…` anderer Programme) bleiben unverändert.

## 3. Entscheidungen der Umsetzung

| Frage (aus `info.md`, Abschnitt 7) | Entscheidung | Begründung |
|---|---|---|
| Namen der `X-MSP-…`-Eigenschaften | wie in Abschnitt 2 | Deutsch, sprechend, ohne Umlaute (iCalendar erlaubt in Namen nur A–Z, 0–9 und „-“). |
| Link „Planung öffnen“ automatisch oder manuell? | Automatisch beim ersten Speichern im Formular; ein Link mit veralteter Adresse wird ersetzt, nie verdoppelt. | Ein Link vor dem ersten Speichern ginge nur, wenn die App jeden neuen Termin anfasst. Das wäre ein Eingriff in fremde Termine, den niemand ausgelöst hat. |
| Druck-/PDF-Ansicht | noch nicht umgesetzt | optional laut Chat; nächster Schritt, wenn gewünscht. |

Weitere Entscheidungen:

- **Lesen und Schreiben** über das CalDAV-Backend von Nextcloud (`OCA\DAV\CalDAV\CalDavBackend`). Die öffentliche Kalender-Schnittstelle (OCP) kann Termine nur neu anlegen, nicht ändern. Welche Kalender jemand sieht und ob er schreiben darf, kommt trotzdem aus OCP, also aus denselben Freigaben wie in der Kalender-App.
- **Freigaben:** Wer den Kalender nur lesen darf, sieht das Formular schreibgeschützt. Als „privat“ oder „vertraulich“ markierte Termine sieht in einem geteilten Kalender nur der Eigentümer.
- **Gleichzeitiges Bearbeiten:** Gespeichert wird nur, wenn der Termin seit dem Öffnen unverändert ist (ETag). Sonst kommt die Meldung „Der Termin wurde inzwischen geändert …“, damit niemand Änderungen aus der Kalender-App überschreibt.
- **Terminserien:** Die Planung gilt für die ganze Serie und steht im Serien-Termin. Geänderte Einzeltermine einer Serie bekommen dieselben Planungsfelder. Titel und Ort übernehmen sie nur, wenn sie noch die alten Werte der Serie hatten. Die Liste zeigt eine Serie einmal, mit dem ersten Termin im Zeitraum.
- **Kalenderauswahl:** Vorbelegt ist der erste Kalender mit „MSP“ im Namen; eine andere Auswahl merkt sich die App je Konto.
- **Keine Scheduling-Mails:** Das Speichern der Planung verschickt keine Einladungs-Updates an Teilnehmende (läuft am CalDAV-Server vorbei). Termin-Zeiten ändert die App ohnehin nicht.
- **Geprüft in der Test-Nextcloud 32.0.5 mit Kalender-App 6.0.3:** Bearbeiten eines Termins in der Kalender-App lässt die `X-MSP-…`-Eigenschaften stehen, und der Link in der Beschreibung ist dort anklickbar.

## 4. Datenschutz

Die Kontaktdaten Externer stehen im Termin. Sie sieht jeder, der Zugriff auf den MSP-Kalender hat, und jedes Gerät, das ihn per CalDAV synchronisiert. Das ist in Ordnung, solange der Kalender nur intern geteilt ist. Eva sollte das wissen. Den Kalender nicht öffentlich teilen (öffentlicher Link) und nicht als Abo nach außen geben, wenn Kontaktdaten darin stehen.

## 5. Offen

- Installationsrechte für eigene Apps auf Ralfs Instanz (`custom_apps` beschreibbar, App aktivieren als Admin).
- Test auf Ralfs Instanz: Kalender „MSP Cloud Kalender“, Freigaben, Bearbeiten in der Kalender-App und auf Handys (DAVx⁵, iOS).
- Kopieren bzw. Duplizieren eines Termins in der Kalender-App: Bleiben die Felder erhalten, und zeigt der Link dann noch auf den alten Termin? Beim nächsten Speichern im Formular wird der Link der Kopie korrigiert.
- Optional: Druck-/PDF-Ansicht im Layout der Word-Vorlage.
- Optional: Export (Excel/CSV) der Liste, falls Eva ihn doch braucht (laut Chat bewusst nicht).
