# Review-Befunde „hoch“ (Kalender-Planung) – Umsetzungsplan

> **Für ausführende Agenten:** Pflicht-Skill: superpowers:subagent-driven-development (empfohlen) oder superpowers:executing-plans, Aufgabe für Aufgabe. Schritte mit Checkbox (`- [ ]`) abhaken.

**Ziel:** Die acht Befunde der Schwere „hoch“ aus dem Code-Review vom 29.10.2026 beheben, zuerst die drei mit Datenverlust:

| Kennung | Befund |
|---|---|
| Q-KAL-01 | Umbenennen eines Feldes ändert dessen Schlüssel |
| Q-KAL-02 | Enter und Leerzeichen in den Optionen gehen verloren |
| Q-KAL-03 | `linkEinfuegen` löscht fremde Zeilen der Beschreibung |
| P-KAL-01 | Serien werden für den ganzen Zeitraum aufgelöst |
| U-KAL-01 | Ort-Auswahl zerschossen |
| U-KAL-02 | Spalte „Planung“ abgeschnitten |
| U-KAL-03 | Keine Rückmeldung nach dem Speichern |
| U-KAL-04 | Zurück-Taste verwirft Eingaben |

**Architektur:** Server: `lib/Service/Planung.php` (rein, PHPUnit). Oberfläche: Vue-Komponenten unter `src/`. Das Datenformat (`X-CALPLAN-…`, Feldschlüssel) bleibt unverändert; Q-KAL-01 schützt es gerade.

**Tech Stack:** PHP 8.2+ (Nextcloud 34/35, Sabre VObject), Vue 3 mit `@nextcloud/vue` 9, Vite, PHPUnit, Playwright gegen die Test-Nextcloud (`tools/test-nextcloud.sh`).

**Grundlage:** Review-Bericht https://claude.ai/artifact/Am68pcNbMwwXx2sZwk9HQC, Plan `docs/REVIEW-PLAN.md` im Repo `ralfpfitzner/test`.

## Globale Vorgaben

- Die Kalender-App wird nicht verändert. Feldschlüssel sind Datenformat; die Schlüssel in `schema/vorgabe.json` nicht umbenennen.
- Bedingte Teile nie als `null`/`undefined` ausgeben (UI-Test prüft „null“).
- Tests:
  - `composer test:unit` (PHP 8.3). In der Cloud-Umgebung scheitert `composer install` derzeit an der GitHub-Anmeldung; dann laufen die PHP-Tests in der CI, und das wird im PR vermerkt.
  - `npm run build && tools/test-nextcloud.sh && npm run test:ui` (Nextcloud 35.0.2, Kalender 6.6.4); die CI testet zusätzlich 34.0.5.
- `CHANGELOG.md` je Aufgabe, ein PR. Version und Tag erst bei der Release-Vorbereitung (Skill `release-vorbereitung`).

## Prüfschwerpunkte

1. Felder aus einer geladenen Vorlage (`schema/preset-*.json`) gelten wie gespeicherte Felder als fest; ihr Schlüssel bleibt beim Umbenennen → Test in Aufgabe 2.
2. Ein alter Link mit **altem** Linktext und gültiger App-Adresse wird weiter ersetzt statt gedoppelt → Test in Aufgabe 1.
3. Serie mit verschobenem Einzeltermin (RECURRENCE-ID) im Zeitraum und Ganztags-Serie liefern denselben ersten Termin wie bisher → Test in Aufgabe 3.
4. Zurück-Taste ohne Änderungen fragt nicht nach → Test in Aufgabe 7.
5. Fehler 400/409 beim Speichern sind wie die Erfolgsmeldung sichtbar und werden angesagt → Test in Aufgabe 6.

---

### Aufgabe 1: `linkEinfuegen` lässt fremde Zeilen stehen (Q-KAL-03)

**Dateien:**
- Ändern: `lib/Service/Planung.php:293-307`
- Test: `tests/Unit/PlanungTest.php`
- Ändern: `CHANGELOG.md`

**Schnittstellen:** unverändert (`schreiben(string $ics, array $planung, ?string $link, ?DateTimeInterface $jetzt = null): string`).

- [ ] **Schritt 1: Fehlschlagende Tests schreiben**

1. `testLinkEinfuegenLaesstFremdeZeilenMitGleichemPraefix`: `new Planung(new Einstellungen('', '', 'Info:'))`. Die Beschreibung enthält „Info: Schlüssel beim Hausmeister“. Nach `schreiben(…, 'https://cloud.example/apps/calendar_planning/?kalender=1&termin=a.ics')` steht in DESCRIPTION die Zeile „Info: Schlüssel beim Hausmeister“ noch, und `Info: https://…` genau einmal.
2. `testAlterLinkMitAltemLinktextWirdErsetzt`: Beschreibung mit „Planung öffnen: https://alt.example/apps/calendar_planning/?kalender=1&termin=a.ics“, Linktext „Info:“. Danach steht keine Zeile mehr mit `alt.example` darin, und der neue Link genau einmal.

- [ ] **Schritt 2: Laufen lassen, Test 1 schlägt fehl**

Run: `composer test:unit -- --filter LinkEinfuegen`

- [ ] **Schritt 3: Filter auf `preg_match(self::LINK_MUSTER, $zeile) !== 1` beschränken** und den Kommentar anpassen

- [ ] **Schritt 4: Laufen lassen, beide bestehen**, dann `composer test:unit` komplett

- [ ] **Schritt 5: CHANGELOG und Commit**

„Beim Speichern bleiben Zeilen der Beschreibung stehen, die zufällig mit dem Linktext beginnen (Q-KAL-03).“

```bash
git add lib/Service/Planung.php tests/Unit/PlanungTest.php CHANGELOG.md
git commit -m "Link einfügen löscht keine fremden Beschreibungszeilen (Q-KAL-03)"
```

### Aufgabe 2: Schlüssel bestehender Felder bleiben fest (Q-KAL-01)

**Dateien:**
- Ändern: `src/components/SchemaEditor.vue:38-55` (Bezeichnung und Schlüssel), `:132-146` (`feldHinzufuegen`, `schluesselVorschlagen`)
- Ändern: `src/Admin.vue` (`schemaSichern`: Hilfsfelder vor dem PUT entfernen)
- Test: `test-ui/admin.ui.test.mjs`
- Ändern: `CHANGELOG.md`, `docs/KONZEPT.md` (Schema-Editor: Schlüssel nur bei neuen Feldern)

**Schnittstellen:**
- Neue Felder tragen `_neu: true` (aus `feldHinzufuegen`). Nur bei diesen folgt der Schlüssel der Bezeichnung (solange `_manuell` falsch ist), und nur bei diesen ist das Schlüsselfeld bearbeitbar.
- Bei allen anderen Feldern ist das Schlüsselfeld `readonly`, mit `title`: „Der Schlüssel ist Teil der gespeicherten Daten und lässt sich nicht mehr ändern.“
- Vor dem PUT entfernt `Admin.vue` `_neu` und `_manuell` aus allen Feldern.

- [ ] **Schritt 1: Fehlschlagende Tests schreiben**

1. `test('Bezeichnung eines gespeicherten Feldes ändern lässt den Schlüssel stehen')`: Admin-Seite. Mit `pressSequentially` (nicht `fill`) an die Bezeichnung von „Zuständig intern“ „ (Person)“ anhängen. Annahme: Das Schlüsselfeld enthält `zustaendig` und ist `readonly`. Nach „Felder speichern“ liefert `GET …/api/schema` den Schlüssel `zustaendig`, und kein Feld enthält `_neu`/`_manuell`.
2. `test('Vorlage laden: Schlüssel der Vorlage bleiben beim Umbenennen')`: Vorlage „Makerspace“ laden, eine Bezeichnung umbenennen. Annahme: Schlüssel unverändert.
3. `test('neues Feld: Schlüssel folgt der Bezeichnung')`: „+ Feld“, Bezeichnung „Raum Nummer“ tippen. Annahme: Schlüssel `raum_nummer`, Feld bearbeitbar.

- [ ] **Schritt 2: Laufen lassen, 1 und 2 schlagen fehl**

Run: `npm run build && npm run test:ui -- test-ui/admin.ui.test.mjs`

- [ ] **Schritt 3: Umsetzen wie unter Schnittstellen**

- [ ] **Schritt 4: Laufen lassen, alle bestehen**

- [ ] **Schritt 5: CHANGELOG, KONZEPT, Commit**

„Feld-Editor: Das Umbenennen eines bestehenden Feldes ändert nicht mehr still dessen Schlüssel. Gespeicherte Werte bleiben am Feld (Q-KAL-01).“

```bash
git add src/components/SchemaEditor.vue src/Admin.vue test-ui/admin.ui.test.mjs CHANGELOG.md docs/KONZEPT.md
git commit -m "Schema-Editor: Schlüssel bestehender Felder bleiben fest (Q-KAL-01)"
```

### Aufgabe 3: Optionen eines Auswahlfeldes tippen (Q-KAL-02)

**Dateien:**
- Ändern: `src/components/SchemaEditor.vue:84-93`
- Test: `test-ui/admin.ui.test.mjs`
- Ändern: `CHANGELOG.md`

**Schnittstellen:**
- Die Textarea hält den Rohtext je Feld in einer lokalen `reactive`-Map (Schlüssel: das Feldobjekt, `WeakMap` oder Index).
- `feld.optionen` wird erst bei `change`/`blur` aus dem Rohtext gebildet (`split('\n')`, `trim`, leere weg).
- `Admin.vue` löst vor dem Speichern ein `blur` des aktiven Elements aus, damit nichts verloren geht.

- [ ] **Schritt 1: Fehlschlagender Test**

`test('Optionen lassen sich mit Enter und Leerzeichen tippen')`: neues Auswahlfeld, in die Optionen per `pressSequentially('Option A')`, `Enter`, `pressSequentially('Option B')` tippen. Annahme: Wert der Textarea `'Option A\nOption B'`. Nach dem Speichern liefert die API `optionen: ['Option A', 'Option B']`.

- [ ] **Schritt 2: Laufen lassen, schlägt fehl** (`OptionAOptionB`)

- [ ] **Schritt 3: Umsetzen**

- [ ] **Schritt 4: Laufen lassen, besteht**, dazu der vorhandene Test „neues Auswahlfeld lässt sich speichern“

- [ ] **Schritt 5: CHANGELOG und Commit**

„Feld-Editor: Optionen eines Auswahlfeldes lassen sich wieder Zeile für Zeile tippen (Q-KAL-02).“

```bash
git add src/components/SchemaEditor.vue src/Admin.vue test-ui/admin.ui.test.mjs CHANGELOG.md
git commit -m "Schema-Editor: Optionen mit Enter und Leerzeichen tippen (Q-KAL-02)"
```

### Aufgabe 4: Serien ohne volle Auflösung (P-KAL-01)

**Dateien:**
- Ändern: `lib/Service/Planung.php:264-284` (`ersterImZeitraum`)
- Test: `tests/Unit/PlanungTest.php`
- Ändern: `CHANGELOG.md`

**Schnittstellen:**
- Signatur unverändert: `private function ersterImZeitraum(VCalendar $kalender, DateTimeInterface $von, DateTimeInterface $bis): ?array`.
- Liefert weiter `[Beginn, ?Ende]` in der Zone von `$von`, oder `null`, wenn kein Termin im Zeitraum beginnt oder läuft oder die Serie nicht lesbar ist.

- [ ] **Schritt 1: Tests schreiben**

Vor der Änderung bestehen alle; sie halten das heutige Verhalten fest.
1. `testSerieErsterTerminImZeitraum`: wöchentliche Serie ab 05.01.2026 10:00 Europe/Berlin, Zeitraum 01.06. bis 30.06.2026. Erwartet: Beginn 01.06.2026 10:00.
2. `testSerieMitVerschobenemEinzeltermin`: dieselbe Serie, Ausnahme mit `RECURRENCE-ID` 01.06., verschoben auf 02.06. 14:00. Erwartet: Beginn 02.06.2026 14:00.
3. `testGanztagsSerie`: tägliche Ganztags-Serie, Zeitraum ab 10.06. Erwartet: Beginn 10.06. (Datum, Ganztags-Kennzeichen wie bisher).
4. `testEndlosSerieWeitInDerZukunft`: `RRULE:FREQ=DAILY` seit 2020, Zeitraum 2029. Erwartet: korrekter Beginn; Laufzeit unter 20 ms (`hrtime`), heute rund 120 ms.

- [ ] **Schritt 2: Laufen lassen**

Erwartet: 1–3 bestehen, 4 schlägt an der Zeitgrenze fehl.

- [ ] **Schritt 3: Mit `Sabre\VObject\Recur\EventIterator` umsetzen**

`new EventIterator($kalender->getBaseComponents('VEVENT') …)` mit Zone von `$von`, `fastForward($von)`, prüfen, dass `getDtStart() < $bis`. Liefert `[getDtStart(), getDtEnd()]` in der Zone von `$von`. `\Throwable` (auch `MaxInstancesExceededException`, `NoInstancesException`) führt wie bisher zu `null`.

- [ ] **Schritt 4: Laufen lassen, alle bestehen**, dazu `composer test:unit` komplett

- [ ] **Schritt 5: Messen und Commit**

In der Test-Nextcloud mit 2 000 Terminen und 100 Serien (Skript aus dem Review) `termine` messen. Erwartet: deutlich unter 884 ms; den Messwert in den PR schreiben.
CHANGELOG: „Terminliste mit vielen Serien deutlich schneller (P-KAL-01).“

```bash
git add lib/Service/Planung.php tests/Unit/PlanungTest.php CHANGELOG.md
git commit -m "Serien: ersten Termin ohne volle Auflösung finden (P-KAL-01)"
```

### Aufgabe 5: Ort-Auswahl und Spalte „Planung“ (U-KAL-01, U-KAL-02)

**Dateien:**
- Ändern: `src/components/PlanungFormular.vue:39-50` (Radiogruppe als `fieldset` mit `legend` „Ort“, eigene Klasse `formular__gruppe` ohne Breitenregel)
- Ändern: `src/components/TerminListe.vue:44-82` und Stile `:205-230`
- Test: `test-ui/planung.ui.test.mjs`
- Ändern: `CHANGELOG.md`

**Schnittstellen:**
- Status „erfasst/offen“ zieht als Marke `liste__status` in die Titelzelle, die eigene Spalte „Planung“ entfällt.
- Titel und Ort brechen mit `overflow-wrap: anywhere` um.
- Listenfelder (`spalten`) bekommen unter 860 px die Klasse `liste__neben` und werden ausgeblendet; ihr Inhalt erscheint dann als Zeile unter dem Titel (`liste__nur-schmal`).

- [ ] **Schritt 1: Fehlschlagende Tests schreiben**

1. `test('Ort-Auswahl: Knopf steht neben seiner Beschriftung')`: Formular öffnen. Für beide Radios gilt: Abstand zwischen rechter Kante des Radios und linker Kante des Beschriftungstexts unter 16 px; die Radiobreite unter 30 px.
2. `test('Liste ohne seitliches Scrollen bei 1280, 860 und 600 Pixel, auch mit langem Titel')`: Termin mit 80 Zeichen langem Titel ohne Leerzeichen anlegen (Präfix „UI-“, danach löschen). Annahme je Breite: `.liste__rahmen` hat `scrollWidth <= clientWidth`, und die Marke „erfasst“ oder „offen“ jeder Zeile liegt im sichtbaren Bereich des Rahmens.

- [ ] **Schritt 2: Laufen lassen, beide schlagen fehl**

- [ ] **Schritt 3: Umsetzen wie unter Schnittstellen**

- [ ] **Schritt 4: Laufen lassen, beide bestehen**, danach `npm run test:ui` komplett (darunter „Handy-Breite: kein waagerechtes Scrollen“, „kein null“)

- [ ] **Schritt 5: CHANGELOG und Commit**

„Ort-Auswahl wieder richtig angeordnet; Planungsstatus steht in der Titelzelle, die Liste scrollt nicht mehr seitlich (U-KAL-01, U-KAL-02).“

```bash
git add src/components/PlanungFormular.vue src/components/TerminListe.vue test-ui/planung.ui.test.mjs CHANGELOG.md
git commit -m "Ort-Auswahl und Terminliste ohne seitliches Scrollen (U-KAL-01, U-KAL-02)"
```

### Aufgabe 6: Rückmeldung nach dem Speichern (U-KAL-03)

**Dateien:**
- Ändern: `src/components/PlanungFormular.vue:24-33` (Meldungen, `fieldset`), `:191-210` (`speichern`), Aktionsleiste
- Test: `test-ui/planung.ui.test.mjs`
- Ändern: `CHANGELOG.md`

**Schnittstellen:**
- Erfolg und Fehler erscheinen über `showSuccess`/`showError` aus `@nextcloud/dialogs`. Ist das Paket nicht schon Abhängigkeit, kommt es mit derselben Hauptversion wie in der Kalender-App 6.6 hinzu.
- Zusätzlich steht die Meldung in der stehenden Aktionsleiste neben „Speichern“, in einem Element mit `role="status"` (Erfolg) bzw. `role="alert"` (Fehler).
- Während des Speicherns ist nur der Knopf gesperrt (`disabled`, `aria-busy="true"`), das `fieldset` bleibt bedienbar.
- Bei 400 mit Feldbezug geht der Fokus auf das betroffene Feld, sonst auf die Meldung.

- [ ] **Schritt 1: Fehlschlagende Tests schreiben**

1. `test('Speichern unten im Formular: Meldung sichtbar und angesagt, Fokus nicht auf BODY')`: bis ans Ende scrollen, ein Feld ändern, „Speichern“. Annahmen: Element mit `role="status"` und Text „Gespeichert“ liegt im Viewport (`boundingBox().y >= 0`); `document.activeElement.tagName !== 'BODY'`.
2. `test('Fehler 400 beim Speichern: Meldung im Viewport, Fokus auf dem Feld')`: ungültige E-Mail eintragen (wie der vorhandene Test), speichern. Annahmen: `role="alert"` im Viewport, Fokus im E-Mail-Feld.

- [ ] **Schritt 2: Laufen lassen, beide schlagen fehl**

- [ ] **Schritt 3: Umsetzen wie unter Schnittstellen**

Die Erfolgsmeldung erst nach `uebernehmen(gespeichert)` setzen; die Reihenfolge besteht schon.

- [ ] **Schritt 4: Laufen lassen, beide bestehen**, dazu der vorhandene Test „Ungültige E-Mail zeigt die Meldung des Servers“

- [ ] **Schritt 5: CHANGELOG und Commit**

„Nach dem Speichern erscheint die Meldung neben dem Knopf und wird angesagt; der Fokus bleibt im Formular (U-KAL-03).“

```bash
git add src/components/PlanungFormular.vue test-ui/planung.ui.test.mjs package.json package-lock.json CHANGELOG.md
git commit -m "Rückmeldung nach dem Speichern sichtbar und angesagt (U-KAL-03)"
```

### Aufgabe 7: Zurück-Taste fragt bei ungespeicherten Eingaben (U-KAL-04)

**Dateien:**
- Ändern: `src/components/PlanungFormular.vue:163-184` (`geaendert`, `zurueck`)
- Ändern: `src/App.vue:12-17, 46-48` (`zurueckTaste`)
- Test: `test-ui/planung.ui.test.mjs`
- Ändern: `CHANGELOG.md`

**Schnittstellen:**
- `PlanungFormular` meldet Änderungen mit `emit('geaendert', boolean)`, sobald sich `geaendert` ändert. `App.vue` hält das in `ungespeichert: Ref<boolean>`.
- `zurueckTaste()`: Ist `ungespeichert` wahr, stellt sie sofort per `history.pushState` die Formular-Adresse wieder her und zeigt einen `NcDialog`. Text: „Die Änderungen sind noch nicht gespeichert.“; Knöpfe „Weiter bearbeiten“ und „Verwerfen“. Bei „Verwerfen“ wechselt sie zur Liste, wie heute `Object.assign(zustand, adresseLesen())` auf die ursprünglich angesteuerte Adresse.
- Derselbe `NcDialog` ersetzt das `window.confirm` im Knopf „‹ Zur Liste“.

- [ ] **Schritt 1: Fehlschlagende Tests schreiben**

1. `test('Zurück-Taste mit ungespeicherter Eingabe fragt nach, „Weiter bearbeiten“ behält die Eingabe')`: Formular öffnen, „Zuständig intern“ ausfüllen, `goBack()`. Annahmen: Dialog „Die Änderungen sind noch nicht gespeichert.“ sichtbar. Nach „Weiter bearbeiten“ ist das Formular sichtbar, und das Feld enthält den Wert.
2. `test('„Verwerfen“ führt zur Liste')`: wie 1, „Verwerfen“. Annahme: Liste sichtbar, `termin` fehlt in der Adresse.
3. `test('Zurück-Taste ohne Änderungen fragt nicht')`: Formular öffnen, `goBack()`. Annahme: kein Dialog, Liste sichtbar.

- [ ] **Schritt 2: Laufen lassen, 1 und 2 schlagen fehl**

- [ ] **Schritt 3: Umsetzen wie unter Schnittstellen**

- [ ] **Schritt 4: Laufen lassen, alle bestehen**, dazu `npm run test:ui` komplett (Blättern, Adresse, Neuladen)

- [ ] **Schritt 5: CHANGELOG und Commit**

„Zurück-Taste des Browsers fragt nach, wenn Eingaben noch nicht gespeichert sind; die Rückfrage ist jetzt ein Nextcloud-Dialog (U-KAL-04).“

```bash
git add src/App.vue src/components/PlanungFormular.vue test-ui/planung.ui.test.mjs CHANGELOG.md
git commit -m "Zurück-Taste fragt bei ungespeicherten Eingaben (U-KAL-04)"
```

### Abschluss

- [ ] `superpowers:requesting-code-review` auf den Branch, danach `superpowers:finishing-a-development-branch`: ein PR mit allen acht Kennungen.
- [ ] Im PR „von Ralf zu testen“:
  - Feld-Editor auf seiner Instanz mit dem dort gespeicherten Schema: Schlüssel bleiben beim Umbenennen.
  - Die Planungsliste eines echten Kalenders mit Serien ist schneller.
  - Falls PHPUnit lokal nicht lief: grüne CI als Beleg.
