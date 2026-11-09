# Changelog

## [Unveröffentlicht]

### Geändert
- Tests und CI laufen mit der Kalender-App 6.6.5 (05.11.2026) in Nextcloud 34.0.5 und 35.0.2 statt mit 6.6.3 bzw. 6.6.4; 6.6.5 ist die auf der Instanz installierte Version. Prüfsumme in `tools/test-nextcloud.sh` hinterlegt. Keine Änderung an der App.

## [1.1.0] - 2026-11-02

Alle 46 Befunde aus dem Code-Review vom 29.10.2026 (Plan `docs/superpowers/plans/2026-11-02-review-alle.md`): Server, Admin, CI und Release (#21), Formular und App-Rahmen (#22), Liste und Performance (#23); dazu der eigene Einstellungsbereich aus 1.0.1 (#20), der nicht getaggt wurde.

### Hinweise zum Update
- Update von 1.0.0: Ordner `calendar_planning` durch das Paket `calendar_planning-1.1.0.tar.gz` ersetzen, dann `occ upgrade`. Einstellungen und Planungsdaten bleiben. Herkunft prüfen mit `gh attestation verify calendar_planning-1.1.0.tar.gz --repo RalfPfitzner/Calendar-extension-Nextcloud`.
- Unterstützt weiter Nextcloud 34 und 35.
- Die Einstellungsseite liegt jetzt unter **Verwaltung → Kalender-Planung** statt unter „Zusätzliche Einstellungen“ (#20).
- Neue Eigenschaft `X-CALPLAN-LINK` im Termin: Sie merkt sich die zuletzt geschriebene Link-Zeile der Beschreibung. Der Feldschlüssel `link` ist deshalb reserviert; ein gespeichertes Schema mit einem Feld `link` gilt als ungültig (die App nimmt dann die Vorgabe und meldet es im Protokoll) und muss vorher umbenannt werden. Sonst keine neuen Einstellungen.
- Das Feldschema steht ab dem nächsten Speichern als „lazy“ in der App-Konfiguration; bestehende Werte bleiben lesbar. Wer es per `occ` setzt: `occ config:app:set calendar_planning schema --lazy --value …`.
- Die Sperre gegen gleichzeitiges Speichern wirkt nur mit eingeschaltetem File-Locking (`filelocking.enabled`, Vorgabe an).
- Ein Feldschema über 64 KiB lässt sich nicht mehr speichern (vorher wurde es angenommen und beim nächsten Laden still durch die Vorgabe ersetzt).

### Behoben
- **Einstellungsseite in einem eigenen Bereich „Verwaltung → Kalender-Planung“** statt unter „Zusätzliche
  Einstellungen“ (`lib/Settings/AdminSection.php`). Dort teilt sich die App die Seite mit anderen Apps; Impersonate
  5.0.0 (Nextcloud 34) und 6.0.0 (35) liefern `<div id="impersonate"</div>` ohne schließende Klammer, dadurch
  verschwinden alle folgenden Formulare, auch dieses. Gefunden bei der ersten Installation (Nextcloud 34.0.5 mit
  Impersonate 5.0.0); der Fix in Impersonate (nextcloud/impersonate#703) ist noch in keinem Release. Die Test-Nextcloud
  installiert diese Impersonate-Versionen jetzt mit, die Browser-Tests prüfen die Seite unter der neuen Adresse
  `/settings/admin/calendar_planning`.
- **Ganztägige Serien** mit EXDATE oder RECURRENCE-ID als Uhrzeit mit Zeitzone (z. B. aus Outlook) zeigen ausgefallene und verschobene Termine richtig; alte Serien „Mo–Fr“, deren Beginn auf einem Wochenende liegt, fallen nicht mehr aus der Liste.
- **Barrierefreiheit der Liste:** Spaltenzuordnung für Bildschirmleser auch auf schmalen Bildschirmen richtig; eine Fehlermeldung verschwindet, wenn man zur angezeigten Liste zurückkehrt.
- **Terminliste ohne seitliches Scrollen:** Die Spalte „Planung“ ist ab 1280 Pixel sichtbar, auf Tablet und Handy stehen Ort, Listenfelder und Planung unter dem Titel (U-KAL-02).
- **Serien** zeigen in der Liste den nächsten Termin, nicht mehr einen vergangenen; endlose tägliche Serien fallen auch nach vielen Jahren nicht mehr aus der Liste (U-KAL-16, P-KAL-06).
- **Sortierung:** Termine mit verschiedenen Zeitzonen und ganztägige Termine stehen in der richtigen Reihenfolge (Q-KAL-04).
- **Suche** findet nur, was in Liste und Formular zu sehen ist (Titel, Ort, Planungsfelder, Datum als TT.MM.JJJJ), unabhängig von Groß-/Kleinschreibung und Schreibweise der Umlaute; Link, UID und Beschreibung treffen nicht mehr. Ein Leerzeichen am Ende der Eingabe bleibt stehen (Q-KAL-06, U-KAL-11, Q-KAL-08).
- **Zurück-Taste** nach einer vom Server korrigierten Seite hängt nicht mehr fest (Q-KAL-07).
- **Ort-Auswahl** wieder richtig angeordnet: Knöpfe direkt bei der Beschriftung, als Gruppe „Ort“, per Tastatur bedienbar (U-KAL-01).
- **Rückmeldung nach dem Speichern:** Erfolg und Fehler erscheinen neben dem Knopf und werden angesagt, der Fokus geht auf die Meldung bzw. das betroffene Feld; die Erfolgsmeldung verschwindet bei der nächsten Änderung (U-KAL-03, Q-KAL-11).
- **Zurück-, Vor-Taste und „Zur Liste“** fragen nach, wenn Eingaben noch nicht gespeichert sind; „Weiter bearbeiten“ behält alles samt Verlauf, „Verwerfen“ hinterlässt keine doppelten Einträge im Verlauf; beim Schließen warnt der Browser (U-KAL-04).
- **Konflikt beim Speichern:** Die Eingaben bleiben stehen. Das Formular zeigt, welche Felder sich inzwischen geändert haben, und bietet „Meine Eingaben behalten und erneut speichern“ oder „Neuen Stand laden“, jeweils mit Rückfrage (U-KAL-05).
- **Entfernte Auswahl-Option:** Ein gespeicherter Wert bleibt erhalten („nicht mehr in der Liste“) und der Termin lässt sich weiter speichern; neue Werte müssen in der Liste stehen (Q-KAL-09).
- **Lesezeichen auf einen nicht mehr verfügbaren Kalender:** Hinweis und korrigierte Adresse statt stillem Austausch (Q-KAL-12).
- **Feld-Editor:** Das Umbenennen eines bestehenden Feldes ändert nicht mehr still dessen Schlüssel; gespeicherte Werte bleiben am Feld. Fallen gespeicherte Felder weg, fragt die Seite vor dem Speichern nach und nennt sie danach (Q-KAL-01).
- **Feld-Editor:** Optionen eines Auswahlfeldes lassen sich wieder Zeile für Zeile tippen, mit Enter und Leerzeichen (Q-KAL-02).
- **Beschreibung:** Beim Speichern bleiben Zeilen stehen, die zufällig mit dem Linktext beginnen oder eigenen Text vor einer Planungs-Adresse tragen; ersetzt wird nur die eigene Link-Zeile der App, an ihrer Stelle. Beschreibungen mit `\r\n` werden nicht mehr umsortiert (Q-KAL-03).
- **Liste:** Ein defekter Termin im Kalender lässt die Terminliste nicht mehr scheitern; er wird übersprungen und ohne Inhalt protokolliert.
- **Speichern:** Ist der Termin gerade von einer anderen Anfrage gesperrt, heißt es „Der Termin wird gerade gespeichert. Bitte gleich noch einmal versuchen.“ statt „neu laden“. Ein Fehler beim Freigeben der Sperre überschreibt das Ergebnis nicht mehr.
- **Feldschlüssel:** Ein Zeilenumbruch am Ende eines Schlüssels (`"abc\n"`) wird abgelehnt (`\z` statt `$` in den Prüfmustern).
- **Zeiten ohne Zeitzone** („Floating“) werden mit ihrer Ortszeit angezeigt statt 1–2 Stunden verschoben (Q-KAL-05).
- **Gleichzeitiges Speichern** desselben Termins: genau eine Speicherung gewinnt, die andere bekommt den Konflikt-Hinweis (S-KAL-03, Q-KAL-10).

### Sicherheit
- Unerwartete Fehler stehen ohne Termininhalt und Kontaktdaten im Nextcloud-Protokoll (S-KAL-01).
- Unsichtbare und Richtungszeichen werden aus Titel, Feldern und Einstellungen entfernt, auch Tag-Zeichen (U+E0000–U+E007F), Graphem-Verbinder, Hangul-Füllzeichen; Zeilen- und Absatztrenner (U+2028/U+2029) werden zu Leerzeichen bzw. Umbrüchen (S-KAL-06).
- Fehler auf der Einstellungsseite stehen wie in der übrigen App ohne Meldungstext und ohne Ausnahme-Objekt im Protokoll.
- Die Test-Nextcloud (`tools/test-nextcloud.sh`) lauscht nur auf 127.0.0.1.
- Ein zu großes Feldschema wird beim Speichern mit einer Meldung abgelehnt (S-KAL-02).
- **Release:** Das Paket wird ohne Schreib- und OIDC-Rechte gebaut; erst ein eigener Job prüft die Prüfsumme, attestiert es und hängt es an den Release-Entwurf. Ein veröffentlichtes Release wird nicht mehr überschrieben (S-KAL-04).
- **Lieferkette:** actionlint und die Nextcloud-Test-Images per Digest, Kalender-App, Impersonate und die Server-Dateien für den API-Check per SHA-256; gitleaks in der CI; `npm audit` blockiert ab „moderate“ (`minimatch` für `webdav` per `overrides` angehoben) (S-KAL-05).
- `SECURITY.md`: unterstützte Versionen, App-ID `calendar_planning`, Meldeweg und Prüfung der Pakete (S-KAL-07).
- **Release-Freigabe:** Der Job, der signiert und hochlädt, läuft in der Umgebung `release`. Mit Prüfer und Tag-Ruleset (Anleitung in `docs/REPO-EINSTELLUNGEN.md`) erzeugt nur noch ein Admin ein Release.

### Geändert
- `follow-redirects` 1.16.1 → 1.16.2 (über `@nextcloud/axios` → `axios`) wegen GHSA-mrwp-f3q8-3g9p
  (CVE-2026-116689, „high“). Die Lücke steckt im Node-HTTP-Weg; das gebaute Bundle enthält `follow-redirects` nicht,
  die App war also nicht betroffen. Ohne Update schlägt aber die Prüfung `npm audit` (ab „high“) in der CI fehl.
- **Liste deutlich schneller:** Serien werden nicht mehr Tag für Tag durchgerechnet, jeder Termin wird nur einmal geladen und gelesen, die Kalenderliste je Anfrage einmal aufgebaut; mit 2 000 Terminen antwortet die Liste in 0,8 statt 5,6 Sekunden. Überholte Anfragen beim Tippen werden abgebrochen (P-KAL-01, P-KAL-02, P-KAL-03, P-KAL-04). Die Datenbank sucht mit Anfang und Ende des Zeitraums vor, gelesen werden nur Termine im Zeitraum; enge oder vergangene Zeiträume sind dadurch besonders schnell, der Speicher je Anfrage steigt etwas.
- **Termintitel sind Links:** Mittelklick und Strg-Klick öffnen einen neuen Tab (U-KAL-07).
- **Blättern und leere Suche:** Knöpfe mit Namen („Erste Seite“, „Seite 3“ …); eine leere Suche nennt den Suchbegriff und bietet „Suche zurücksetzen“; Laden und Trefferzahl werden angesagt (U-KAL-12, U-KAL-14, U-KAL-15).
- **Kalenderauswahl** ohne Geburtstagskalender und Kalender anderer Apps (U-KAL-21).
- **Fehlermeldungen** bei zu vielen Anfragen, abgelaufener Anmeldung, fehlender Berechtigung, Wartung und Serverfehlern statt pauschal „Verbindung fehlgeschlagen“ (U-KAL-06).
- **Fokus und Ansage** beim Wechsel zwischen Liste und Formular; nur noch eine Hauptlandmarke (U-KAL-08, U-KAL-13).
- **Nur-Lesen-Ansicht** als gut lesbare Liste, per Tastatur erreichbar (U-KAL-09).
- **Formular** mit Nextcloud-Bedienelementen, Auswahlfelder als native Auswahllisten im Nextcloud-Stil (auf dem Handy besser bedienbar, mit Fehlerkennzeichnung), alle Texte Deutsch; Meldungen werden einmal angesagt, der Fokus bleibt auf „Speichern“ bzw. geht bei Eingabefehlern ins Feld (U-KAL-17).
- **Schnellerer Start:** Ein Termin aus der Adresse lädt parallel zur Kalenderliste, Formular und Rückfrage-Dialog werden erst bei Bedarf geladen (P-KAL-05).
- **Einstellungsseite:** Hinweis auf ungespeicherte Änderungen, Rückfrage beim Verlassen; bei schmalem Fenster Karten statt Tabelle, kein seitliches Scrollen von 1280 bis 600 Pixel; verständliche Fehlermeldungen (Abschnitt und Feld ab 1 gezählt, mit Bezeichnung); Feld entfernen mit „Rückgängig“, mehrfach in umgekehrter Reihenfolge, Fokus bleibt beim Verschieben am Feld (U-KAL-10, U-KAL-18, U-KAL-19, U-KAL-20).
- **Einstellungsseite, Barrierefreiheit:** Rückfragen als Dialog von Nextcloud mit Knöpfen, die die Aktion nennen („Trotzdem speichern“/„Weiter bearbeiten“, „Vorlage laden“/„Abbrechen“, „Abschnitt entfernen“/„Behalten“), Fokus danach zurück; nach Entfernen von Feldern und Abschnitten steht der Fokus am nachrückenden Element, ein neuer Abschnitt bekommt den Fokus in seiner Überschrift; Erfolgsmeldungen in einem Live-Bereich; jede Eingabe hat ein echtes, eindeutiges Label („Pflicht Feld 2 in Abschnitt 1 („Name“)“), in der Karten-Ansicht sichtbar und anklickbar; kein seitliches Scrollen auch bei 320 Pixel.
- **CI schneller:** Playwright-Chromium und Composer aus dem Cache; die Test-Nextcloud nimmt Containername und Port aus `NC_NAME`/`NC_PORT` und entfernt ihr Volume mit.

## [1.0.0] - 2026-10-28

Aus „MSP-Planung“ wird die organisationsneutrale App „Kalender-Planung“ (`calendar_planning`): Felder, Standardort
und Kalender-Vorbelegung legt der Admin in den Einstellungen fest (#16, Umbau von Jonas aus #13).

### Hinweise zum Update
- Neue App-ID: Das Paket heißt `calendar_planning-1.0.0.tar.gz` und wird als neue App installiert
  (`occ app:enable calendar_planning`). Herkunft prüfen mit
  `gh attestation verify calendar_planning-1.0.0.tar.gz --repo RalfPfitzner/Calendar-extension-Nextcloud`.
- Nach der Installation unter „Verwaltung → Zusätzliche Einstellungen → Kalender-Planung“ Standardort,
  Kalender-Vorbelegung und bei Bedarf die Vorlage laden (oder per `occ config:app:set`, siehe README).
- Unterstützt weiter Nextcloud 34 und 35.
- **Umbau zur organisationsneutralen App „Kalender-Planung“.** App-ID `calendar_planning` statt `msp_planung`, Namespace
  `OCA\CalendarPlanning`, Eigenschaften im Termin `X-CALPLAN-…` statt `X-MSP-…`. Freigegeben von Ralf am 28.10.2026:
  Die App war bis dahin auf keiner Instanz installiert, es gibt keine gespeicherten Planungen, deshalb kein Rückfall auf
  die alten Namen (Begründung in `docs/KONZEPT.md`, Abschnitt 2). Ein altes Paket `msp_planung` vorher mit
  `occ app:remove msp_planung` entfernen.
- Standardort und Kalender-Vorbelegung stehen nicht mehr im Code, sondern in den Admin-Einstellungen
  (`occ config:app:set calendar_planning ort_vorgabe --value …`, `kalender_muster`, `link_text`; siehe README).
  Ohne Einstellungen: freies Ortsfeld, erster Kalender vorbelegt.

### Hinzugefügt
- **Feldschema vom Admin definierbar** (`lib/Service/Schema.php`): Abschnitte mit Feldern `{key, label, typ, pflicht,
  maxlen, optionen, inListe}`, Typen Text (ein- oder mehrzeilig), E-Mail, Telefon, Zahl, Auswahl, Ja/Nein, Datum.
  Höchstens 50 Felder, 3 Listenfelder, Schlüssel `[a-z][a-z0-9_]{0,30}`, daraus die Eigenschaft `X-CALPLAN-<SCHLÜSSEL>`.
  Vorgabe `schema/vorgabe.json` (die 16 bisherigen Felder), Vorlage `schema/preset-msp.json` (Word-Vorlage des ersten
  Nutzers).
- Admin-Einstellungsseite unter „Verwaltung → Zusätzliche Einstellungen → Kalender-Planung“ (`lib/Settings/Admin.php`,
  `src/Admin.vue`): Vorgaben (Standardort, Kalender-Vorbelegung, Linktext) und Editor für Abschnitte und Felder mit
  „Vorgabe laden“ und „Vorlage laden“. Routen `GET/PUT /api/schema` und `GET/PUT /api/admin/einstellungen` nur für
  Admins, Speichern mit Rate-Limit 30 pro Minute.
- `lib/Service/Einstellungen.php` und `EinstellungenSpeicher.php`: Admin-Einstellungen über `OCP\IAppConfig`, beim Lesen
  und Schreiben bereinigt (einzeilig, ohne Steuerzeichen, Länge gedeckelt); ein ungültig gespeichertes Schema fällt auf
  die Vorgabe zurück und landet als Warnung im Protokoll.
- `test-ui/admin.ui.test.mjs`: Browser-Test der Einstellungsseite (Feld anlegen, Grenze der Listenfelder, Vorlage laden).
- `test-ui/sicherheit.test.mjs`: die Live-Prüfungen des Sicherheits-Audits vom 27.10.2026 (Anmeldung, CSRF, fremde
  Kalender, private Termine, Nur-Lese-Freigabe, iCalendar-Injection, Eingabegrenzen, ETag, Host-Header, Antwort-Header)
  laufen jetzt in der CI gegen beide Nextcloud-Versionen, erweitert um die Schema-Routen (Nicht-Admin 403, feindliche
  Schlüssel und 51 Felder 400, Bezeichnungen als Text statt HTML).

### Geändert
- Ändert der Admin den Linktext, ersetzt das nächste Speichern die alte Link-Zeile in der Terminbeschreibung, statt
  eine zweite anzuhängen (erkannt an der Adresse des Formulars).
- Änderungen an Feldschema und Vorgaben gehen an das Audit-Protokoll von Nextcloud (`admin_audit`), ohne Inhalte.
- Feld „Benötigte Maschinen / Tools“ heißt „Benötigte Ausstattung / Technik“ (Schlüssel `ausstattung`), Abschnitt 4
  „Inhalte & Ausstattung“. Alle Namen, Texte und Testdaten sind frei von Organisationsbezügen; die Anforderungen des
  ersten Nutzers liegen unverändert in `docs/historie/info.md`.
- Neue Route `GET /api/einstellungen` liefert der Oberfläche Standardort und Feldschema.
- Formular und Liste rendern aus dem Feldschema; die Liste zeigt neben Datum, Veranstaltung, Ort und Planung die
  bis zu drei Felder mit `inListe` (Vorgabe: Zuständig intern, Organisation / Firma, Anzahl der Teilnehmenden).
- Die Prüfung der Formularwerte folgt dem Schema: Typ (Zahl, E-Mail, Auswahl, Datum, Ja/Nein), Pflicht, Höchstlänge,
  Optionen; Fehlermeldungen nennen die Bezeichnung des Feldes.

## [0.3.1] - 2026-10-28

Erster Release mit Nextcloud 35. Inhaltlich der Stand, der als 0.3.0 gedacht war: Der Tag `v0.3.0` zeigt auf einen
Commit, in dem die Version noch 0.2.1 hieß, der Release-Workflow hat deshalb kein Paket gebaut. `v0.3.0` bleibt als
Tag stehen (Tags werden nie verschoben), hat aber kein Paket; installiert wird 0.3.1.

### Hinweise zum Update
- Läuft jetzt auch auf Nextcloud 35 (`max-version` 35). Auf Nextcloud 34 ändert sich nichts.
- Paket `msp_planung-0.3.1.tar.gz`; Herkunft prüfen mit
  `gh attestation verify msp_planung-0.3.1.tar.gz --repo RalfPfitzner/Calendar-extension-Nextcloud`.

### Geändert
- `gate` wartet jetzt auch auf `abhaengigkeiten` (dependency-review): Eine neue verwundbare Abhängigkeit in einem
  Pull Request lässt den Pflicht-Check scheitern; bei push und im Release ist der übersprungene Job erlaubt.
- `appinfo/info.xml` erlaubt Nextcloud 34 bis 35. Gegen Nextcloud 35.0.2 mit Kalender-App 6.6.4 geprüft: die vier
  genutzten Methoden von `CalDavBackend` sowie `CalendarImpl` und `IUserConfig` sind unverändert; Anmeldung, CSRF,
  Kalender-Rechte, private Termine, Nur-Lese-Freigaben, iCalendar-Escaping und Eingabegrenzen verhalten sich wie auf 34.
- Die CI testet in Docker gegen beide Hauptversionen (34.0.5 mit Kalender-App 6.6.3, 35.0.2 mit 6.6.4).
  `tools/test-nextcloud.sh` nimmt `NC_VERSION` (Vorgabe 35.0.2) und `KALENDER_VERSION` (Vorgabe 6.6.4).
- Die CI prüft die Workflow-Dateien mit actionlint, neue Abhängigkeiten in Pull Requests gegen die
  GitHub-Advisory-Datenbank (dependency-review) und fasst alle Jobs im Job `gate` zusammen, der sich als einziger
  Pflicht-Check für einen Branch-Schutz eignet.

- Rate-Limits je Konto auf `termine` und `termin` (120 pro Minute) und `speichern` (30 pro Minute) über
  `#[UserRateLimit]`; Nextcloud antwortet darüber mit 429. Die Oberfläche bleibt weit darunter.
- Einzeilige Felder (Titel, Zuständig, Kontaktdaten, Zielgruppe, Teilnehmende) verwandeln Zeilenumbrüche und Tabs in
  Leerzeichen; sonstige Steuerzeichen werden in allen Feldern entfernt. Vorher konnte ein Titel mit Umbruch in der
  Kalender-App landen.
- `composer.json` verlangt PHP 8.2 (Minimum von Nextcloud 34, vorher stand 8.1); die Entwicklungsabhängigkeiten
  lösen gegen PHP 8.3 auf.
- Der Release-Workflow attestiert das Paket (Build-Provenance über Sigstore) und schreibt die SHA-256-Summe in die
  Release-Notes. Prüfen mit `gh attestation verify msp_planung-<version>.tar.gz --repo RalfPfitzner/Calendar-extension-Nextcloud`.
- Die CI prüft die Laufzeit-Abhängigkeiten des Bundles mit `npm audit` (ab Schwere „high“) bei jedem Lauf.

### Hinzugefügt
- `tools/api-check.sh` und `phpstan.neon`: PHPStan (Level 6) gegen `nextcloud/ocp` in genau der geprüften Version plus
  `CalDavBackend` und `CalendarImpl` aus dem Server-Repo. Die CI fährt das für 34.0.5 und 35.0.2; ein Wechsel der
  Nextcloud-Schnittstellen fällt damit in der CI auf, nicht erst auf der Instanz.
- `docs/REPO-EINSTELLUNGEN.md`: Checkliste für die GitHub-Einstellungen des Repos (Beschreibung, private
  Sicherheitsmeldung, Schutzregeln, Merge-Methoden), mit fertigen Befehlen.
- `SECURITY.md`: wie Sicherheitslücken gemeldet werden, was als Lücke zählt und was dokumentierte Entscheidung ist.
- `.gitattributes`: Zeilenenden LF, auch auf Windows-Checkouts.
- Vorlage für Pull Requests mit Sicherheits- und Prüf-Checkliste, Issue-Hinweis auf die private Meldung.

## [0.2.1] - 2026-10-27

Gleicher Funktionsumfang wie 0.2.0; das Paket wird jetzt mit Node 24 gebaut.

### Hinweise zum Update
- Kein Update nötig: Die App verhält sich wie 0.2.0, die gebauten Dateien (`js/`, `css/`) unterscheiden sich nur in
  der eingebauten Versionsnummer. Wer aktualisiert, entpackt das Paket wie gewohnt nach `custom_apps`; Nextcloud
  übernimmt die neue Version beim nächsten Aufruf (sonst `occ upgrade`).

### Geändert
- Gebaut wird mit Node 24 (LTS bis 30.04.2028) statt Node 22 (Ende 30.04.2027): CI und Release-Workflow, `engines` in
  `package.json`. Node 26 noch nicht, weil die `@nextcloud/*`-Bibliotheken (u. a. `@nextcloud/vue`,
  `@nextcloud/vite-config`) es in `engines` noch nicht nennen. Das gebaute Paket (`js/`, `css/`) ist unter Node 22 und
  24 gleich.

## [0.2.0] - 2026-10-27

### Hinweise zum Update
- **Nur noch Nextcloud 34** (vorher nur 32): Ralfs Instanz läuft auf 34.0.5.1, dort ließ sich 0.1.0 nicht aktivieren.
  Paket wie gewohnt nach `custom_apps` entpacken und mit `occ app:enable msp_planung` aktivieren.

### Geändert
- Umstellung auf Nextcloud 34: `appinfo/info.xml` verlangt Nextcloud 34, die Test-Nextcloud (`tools/test-nextcloud.sh`,
  CI) läuft mit 34.0.5 und Kalender-App 6.6.3.
- Die gemerkte Kalenderauswahl je Konto wird über `IUserConfig` gelesen und gespeichert statt über die seit Nextcloud 33
  veralteten `IConfig::getUserValue`/`setUserValue`. Bereits gespeicherte Auswahlen bleiben gültig. Neuer Browser-Test
  „Kalenderauswahl wird je Konto gespeichert“.
- Dependabot schlägt wöchentlich Updates für npm-Pakete, Composer (Testwerkzeuge) und GitHub Actions vor
  (`.github/dependabot.yml`). Ohne Hauptversions-Sprünge bei TypeScript (bleibt 5.x), `@nextcloud/vue` (folgt der
  Nextcloud-Version) und `sabre/vobject` (bringt Nextcloud mit). Patch- und Minor-Updates kommen je Bereich gebündelt
  in einem PR.
- Vite bleibt vorerst bei 7: Vite 8 braucht `@nextcloud/vite-config` 3, das es bisher nur als Beta gibt; Dependabot
  schlägt bis dahin keine neue Vite-Hauptversion vor.

## [0.1.0] - 2026-10-20

Erste Version nach dem Planungs-Chat vom 20.10.2026 (`docs/info.md`).

### Hinweise zur Installation
- Nur Nextcloud 32. Paket nach `custom_apps` entpacken und mit `occ app:enable msp_planung` aktivieren (README, „Installation“).
- Zuerst auf einer Testinstanz oder mit einem Testkalender ausprobieren.

### Hinzugefügt
- Ansicht „MSP-Planung“: Termine eines Kalenders mit Zeitraum, Suche und Blättern; Spalte „Planung: erfasst/offen“.
- Formular nach der Word-Vorlage (Grunddaten, Kontaktdaten, teilnehmende Gruppe, Inhalte & Werkzeuge, Hinweise); Ort mit Vorgabe „MakerSpace Bendorf“ oder externem Ort mit Adresse.
- Speicherung im Termin als `X-MSP-…`-Eigenschaften, Titel in `SUMMARY`, Ort in `LOCATION` (Datenformat in `docs/KONZEPT.md`).
- Link „Planung öffnen“ in der Terminbeschreibung, automatisch beim Speichern.
- Rechte aus den Kalender-Freigaben, private Termine nur für den Eigentümer, Schutz vor gleichzeitigem Bearbeiten.
