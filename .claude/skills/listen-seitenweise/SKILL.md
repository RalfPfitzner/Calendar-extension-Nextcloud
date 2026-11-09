---
name: listen-seitenweise
description: Verwenden, wenn in Ralfs Projekten (Goethe-Verwaltung, Bestellportal MakerSpace, Kalender-Planung oder ein neues Projekt) eine Liste, Tabelle oder Übersicht in einer Oberfläche entsteht oder wächst, eine Liste „unübersichtlich“ oder langsam wird, ein Endpunkt eine Liste liefert, oder Blättern, Seitengröße, `seite`/`pro_seite` oder „null“ in einer Liste betroffen sind.
---

# Listen seitenweise

<!-- Kopie aus ralfpfitzner/test (.claude/skills/listen-seitenweise); Änderungen dort machen und in alle Projekte übernehmen. -->

## Überblick

Jede Liste in einer Oberfläche ist seitenweise, in allen Projekten gleich (Wunsch vom 18.10.2026). Die Grobregel
steht in jeder CLAUDE.md; dieser Skill hält die Einzelheiten fest, die beim Bau trotzdem fehlen.

## Entscheidung: Server oder Browser

| Liste | Blättern | Beispiele |
|---|---|---|
| wächst über die Zeit | Server (`seite`, `pro_seite`) | Protokoll, Bestellungen, Mails, Exporte, Termine, Buchungen |
| klein und begrenzt | Browser darf teilen | Lieferanten, Projekte, Benutzer, Backups, Klassen |
| Oberfläche führt von Eintrag zu Eintrag | gar nicht | Springen zum nächsten offenen Kind |

Im Zweifel: Server.

## Vertrag

**Anfrage:** `seite` (ab 1), `pro_seite` ∈ {25, 50, 100}. Unbekanntes `pro_seite` → 25, ungültige Seite → 1, zu
große Seite → letzte vorhandene. Nie 400 oder 500 wegen dieser Parameter. Ohne beide Parameter bleibt eine
bestehende Route bei der ganzen Liste (alte Aufrufer, Tests); neue Routen dürfen immer blättern.

**Antwort:** `{ ok, <liste>: [...], seite, seiten, pro_seite, gesamt }`; `seite` ist die tatsächlich gelieferte,
`seiten` mindestens 1 (auch bei `gesamt` 0).

**Adresse:** `?seite=3&pro_seite=50`, Vorgaben (Seite 1, 25) weglassen. Mehrere Listen auf einer Seite:
`<name>_seite`, `<name>_pro_seite`. Liefert der Server eine andere Seite, die Adresse mit `replaceState`
angleichen. Filter-, Such- oder Größenwechsel → Seite 1.

**Oberfläche:**
- Die Liste scrollt in einem eigenen Bereich, der Tabellenkopf mit `position: sticky` und eigenem Hintergrund
  (sonst scheint der Inhalt durch). Auf dem Handy (bis 640 px) kein eigener Scrollbereich.
- Darunter `<nav aria-label="Seiten">`: `« ‹ 1 2 3 4 5 › »`, höchstens 5 Zahlen um die aktuelle Seite, und
  „Seite x von y · n Einträge · pro Seite 25/50/100“ (Zahlen mit `Intl.NumberFormat('de-DE')`, Einheit passend:
  „Buchungen“, „Termine“).
- Aktuelle Seite: `aria-current="page"` und `aria-disabled="true"`, **nicht** `disabled` (sonst per Tab nicht
  erreichbar). Lag der Fokus in der Leiste, steht er nach dem Neuzeichnen wieder dort (aktuelle Seite bzw. Auswahl).
- Scrollbereich ohne eigene Bedienelemente: `tabindex="0"` und `aria-label`, damit er per Tastatur scrollt.
- Bedingte Teile nie als `null`/`undefined` an `append()`/`replaceChildren()`: vorher `.filter(Boolean)` oder ein
  `h()`, das beides weglässt.

## Server-Muster (Node + PostgreSQL)

```js
export const PRO_SEITE = [25, 50, 100];
// sql mit eindeutigem ORDER BY (z. B. name, id), sonst springen Einträge zwischen Seiten
export async function seitenweise(db, sql, werte, query) {
  const angefragt = Number(query?.pro_seite);
  const proSeite = PRO_SEITE.includes(angefragt) ? angefragt : PRO_SEITE[0];
  // Zählung und Seite in EINER Transaktion mit demselben Stand, sonst passt gesamt nicht zur Seite
  return db.transaktion(async (q) => {
    const { rows: [z] } = await q.query(`SELECT count(*)::int AS n FROM (${sql}) AS alle`, werte);
    const seiten = Math.max(1, Math.ceil(z.n / proSeite));
    const seite = Math.min(Math.max(parseInt(query?.seite, 10) || 1, 1), seiten);
    const n = werte.length;
    const { rows } = await q.query(`${sql} LIMIT $${n + 1} OFFSET $${n + 2}`, [...werte, proSeite, (seite - 1) * proSeite]);
    return { rows, seite, seiten, pro_seite: proSeite, gesamt: z.n };
  }, { beginn: 'BEGIN ISOLATION LEVEL REPEATABLE READ' });
}
```

Ohne Hilfe `db.transaktion` (neues Projekt): `pool.connect()`, `BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY`,
beide Abfragen, `COMMIT`; im Fehlerfall `ROLLBACK`, im `finally` den Client freigeben.

Teure Sichten erst für die IDs der Seite berechnen, nicht für alle Zeilen. Zahlen kommen aus der Zählabfrage, nie
aus dem Laden aller Zeilen. Bei tiefem Blättern Keyset statt `OFFSET` (`docs/STANDARDS.md`, Teil G).

## Vorhandene Umsetzungen (übernehmen statt neu schreiben)

| Projekt | Server | Oberfläche |
|---|---|---|
| Goethe-Verwaltung | `src/seiten.js` (`listeAntwort`) | `frontend/shell/seiten.js` + `seiten.css` (Server- und Browser-Listen, Namen in der Adresse, Fokus) |
| Bestellportal | `src/seiten.js` (`seitenwahl`, `seitenteil`) | `public/seiten.js` (`seitenleiste`, Ansage über Live-Region) |
| Kalender-Planung | `lib/Service/KalenderService.php`, `lib/Controller/ApiController.php` | `src/components/TerminListe.vue`, `src/adresse.js` |

Ein neues Projekt kopiert die Dateien des ähnlichsten Projekts und passt die Texte an.

## Tests (Pflicht)

- API: leere Liste (`seiten` 1), mehrere Seiten, letzte kürzere Seite, Seite 999 → letzte, `pro_seite=7` und
  `seite=abc` → Vorgaben, alle Seiten zusammen enthalten jeden Eintrag genau einmal (mit gleichen
  Sortierwerten), ohne Parameter die ganze Liste (bei bestehenden Routen).
- Browser: Liste mit nur einer Seite → kein „null“/„undefined“ im Text; Blättern ändert die Adresse, Neuladen
  behält die Seite; Fokus bleibt in der Leiste; keine CSP-Verletzung, kein JS-Fehler. Neue Seiten in die
  Erscheinungsprüfung (`SEITEN` in `test/ui/erscheinung.ui.js`) aufnehmen.

## Häufige Fehler

| Fehler | Folge | Richtig |
|---|---|---|
| `count` und Seite als zwei Abfragen ohne Transaktion | „n Einträge“ passt nicht zur Seite | eine Transaktion, `REPEATABLE READ` |
| `ORDER BY name` ohne eindeutigen Zusatz | Einträge doppelt oder fehlen beim Blättern | `ORDER BY name, id` |
| aktueller Knopf `disabled` | Fokus fällt nach dem Klick auf `body`, `aria-current` wird nie angesagt | `aria-disabled` |
| Filterwechsel behält `seite` | leere Seite oder Sprung ans Ende | Seite 1 |
| `?seite=` für zwei Listen auf einer Seite | Listen blättern gemeinsam | `<name>_seite` |
| Liste „ist ja klein“ ohne Blättern | wächst doch, Seite wird langsam | Tabelle oben, im Zweifel Server |
