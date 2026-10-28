# Changelog

## [Unveröffentlicht]

### Hinweise zum Update
- **Umbau zur organisationsneutralen App „Kalender-Planung“.** App-ID `calendar_planning` statt `msp_planung`, Namespace
  `OCA\CalendarPlanning`, Eigenschaften im Termin `X-CALPLAN-…` statt `X-MSP-…`. Freigegeben von Ralf am 28.10.2026:
  Die App war bis dahin auf keiner Instanz installiert, es gibt keine gespeicherten Planungen, deshalb kein Rückfall auf
  die alten Namen (Begründung in `docs/KONZEPT.md`, Abschnitt 2). Ein altes Paket `msp_planung` vorher mit
  `occ app:remove msp_planung` entfernen. Der nächste Release wird 1.0.0.
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
