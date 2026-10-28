## Was ändert sich

<!-- Ein, zwei Sätze: was tut dieser PR, und warum? -->

## Art

- [ ] Fehlerkorrektur
- [ ] Neue Funktion
- [ ] Dokumentation
- [ ] CI, Werkzeuge
- [ ] Umbau ohne Verhaltensänderung

## Sicherheit

- [ ] Berührt die Rechteprüfung (`KalenderService::kalenderFuer`, `sichtbar`, `isWritable`)
- [ ] Berührt die Eingabeprüfung (`Planung::pruefen`, Controller-Parameter) oder das Schreiben ins iCalendar
- [ ] Neue Route, neue Abhängigkeit oder neuer Aufruf nach außen
- [ ] Nichts Sicherheitsrelevantes

Wenn eines angekreuzt ist: Was kann ein Konto danach, was es vorher nicht konnte, oder umgekehrt?

<!-- … -->

## Geprüft

- [ ] `composer test:unit`
- [ ] `npm run test:ui` gegen `tools/test-nextcloud.sh` (Nextcloud-Version: ______)
- [ ] Von Ralf auf seiner Instanz zu testen: <!-- was genau -->
- [ ] Keine echten Termine, Kontaktdaten, Hostnamen oder Zugangsdaten enthalten

## Bezug

Closes #
