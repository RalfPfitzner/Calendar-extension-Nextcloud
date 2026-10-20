# Changelog

## [Unveröffentlicht]

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
