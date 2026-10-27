# Changelog

## [Unveröffentlicht]

### Hinweise zum Update
- **Nur noch Nextcloud 34** (vorher nur 32): Ralfs Instanz läuft auf 34.0.5.1, dort ließ sich 0.1.0 nicht aktivieren.
  Paket wie gewohnt nach `custom_apps` entpacken und mit `occ app:enable msp_planung` aktivieren.

### Geändert
- Umstellung auf Nextcloud 34: `appinfo/info.xml` verlangt Nextcloud 34, die Test-Nextcloud (`tools/test-nextcloud.sh`,
  CI) läuft mit 34.0.5 und Kalender-App 6.6.3. Unit- und Browser-Tests laufen dort ohne Änderung am Code.
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
