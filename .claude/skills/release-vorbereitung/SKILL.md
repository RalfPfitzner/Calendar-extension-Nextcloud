---
name: release-vorbereitung
description: Bereitet ein Release der Nextcloud-App vor (Version in appinfo/info.xml, package.json und package-lock.json, Changelog-Abschnitt mit Update-Hinweisen, README, Release-PR, Merge), so dass Ralf danach nur noch das Tag setzt. Verwenden bei „Release vorbereiten“, „Version x.y.z“, „Release-PR“ oder bevor Ralf ein Tag setzen will.
---

# Release vorbereiten

Ziel: Nach dem Merge ist nichts mehr nachzuziehen. **Ralf setzt das Tag selbst**; Claude legt nie ein Tag an und pusht keins. Der Release-Workflow (`.github/workflows/release.yml`) bricht im Job `version` ab, wenn das Tag nicht zu `package.json` passt, `appinfo/info.xml` eine andere Version trägt oder `CHANGELOG.md` keinen Abschnitt `## [x.y.z]` hat. Dann gibt es kein Paket (so geschehen mit `v0.3.0` am 28.10.2026, ersetzt durch 0.3.1).

## 1. Version bestimmen (SemVer)

- Neue Funktion, neue Nextcloud-Hauptversion, neue Einstellung → Minor; nur Korrekturen → Patch. Ein Wechsel der App-ID oder des Datenformats (Präfix der Eigenschaften, Schlüssel in `schema/vorgabe.json`) → Major.
- Ralf nennt die Version meist selbst; sonst vorschlagen und kurz begründen.
- Ist ein Release-Lauf gescheitert (kein Paket), wird das Tag nicht verschoben oder neu gesetzt, sondern die nächste Patch-Version vorbereitet, mit Hinweis im Changelog (Regel aus `docs/STANDARDS.md` im Repo `ralfpfitzner/test`).

## 2. Prüfen, bevor etwas geschrieben wird

- `main` aktuell, CI auf dem letzten Commit grün (`gate`: php, api je Nextcloud-Version, Browser-Tests gegen 34 und 35, npm-audit, actionlint).
- `max-version` in `appinfo/info.xml` passt zur CI-Matrix und zu `tools/test-nextcloud.sh`.
- Keine echten Termine, Kontaktdaten oder Kalender-Exporte im Diff seit dem letzten Tag (`git diff v<letzte>..HEAD --stat`).

## 3. Dateien

| Datei | Änderung |
|---|---|
| `package.json`, `package-lock.json` | `npx -y npm@11 version x.y.z --no-git-tag-version` (ändert beide; npm 11 wie in der CI, ältere npm schreiben die `dev`-Flags im Lockfile um) |
| `appinfo/info.xml` | `<version>x.y.z</version>`, gleich wie in `package.json` |
| `CHANGELOG.md` | `## [Unveröffentlicht]` bleibt (leer), darunter `## [x.y.z] - JJJJ-MM-TT` mit dem bisherigen Inhalt |
| `README.md` | Paketname `<app-id>-x.y.z.tar.gz` in der Installationsanleitung, Stand der unterstützten Nextcloud-Versionen |

Die App-ID steht in `appinfo/info.xml` (`<id>`); das Paket heißt `<app-id>-x.y.z.tar.gz` (`tools/paket.sh`).

Aufbau des Changelog-Abschnitts:

```markdown
## [x.y.z] - JJJJ-MM-TT

Ein Satz, worum es in dem Release geht.

### Hinweise zum Update
- Unterstützte Nextcloud-Versionen, falls geändert.
- Neue Einstellungen (occ-Befehl oder Verwaltungsseite) oder „keine“.
- Paket `<app-id>-x.y.z.tar.gz`; Herkunft prüfen mit `gh attestation verify <app-id>-x.y.z.tar.gz --repo RalfPfitzner/Calendar-extension-Nextcloud`.

### Hinzugefügt
### Geändert
### Behoben
```

Leere Unterabschnitte weglassen. Aus Sicht der Nutzer und des Admins schreiben, nicht als Commit-Liste.

## 4. Vor dem PR selbst nachstellen

Die Versionsprüfung des Release-Workflows lokal laufen lassen:

```bash
TAG=vx.y.z
version="$(jq -r .version package.json)"
info="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)"
test "$TAG" = "v$version" && test "$info" = "$version" && grep -q "^## \[$version\]" CHANGELOG.md && echo ok
```

Dazu `composer test:unit`, wenn PHP geändert wurde.

## 5. Release-PR

- Eigener Branch, ein Commit „Release x.y.z“ mit kurzer Aufzählung im Text, PR-Titel „Release x.y.z“.
- CI abwarten; wenn grün und Ralf zugestimmt hat: mergen (Squash).
- Abschließend Ralf in einem Satz sagen, dass er jetzt taggen kann: `git tag vx.y.z <merge-commit> && git push origin vx.y.z`. Der Release-Workflow baut dann das Paket mit Attestierung und SHA-256 und hängt es an einen Release-Entwurf. Danach prüfen, dass der Lauf grün ist und das Paket am Release hängt.
