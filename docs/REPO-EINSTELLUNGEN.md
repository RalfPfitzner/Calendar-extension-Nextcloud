# GitHub-Einstellungen des Repos

Was im Repo liegt, prüft die CI. Diese Punkte liegen in den GitHub-Einstellungen und müssen von Hand gesetzt werden. Stand der Prüfung: 27.10.2026, von außen über die API gelesen (Abschnitte mit „nicht sichtbar“ nur als Admin prüfbar).

| Punkt | Stand | Soll | Wo |
|---|---|---|---|
| Beschreibung („About“) | leer | „Veranstaltungsplanung im Nextcloud-Kalender: Planungsfelder als Eigenschaften im Termin, Felder und Vorgaben vom Admin einstellbar“ | Repo-Startseite → ⚙ neben „About“ |
| Topics | keine | `nextcloud`, `nextcloud-app`, `calendar`, `caldav`, `php`, `vue` | ebenda |
| Private Sicherheitsmeldung | **aus** | an, sonst zeigt `SECURITY.md` ins Leere | Settings → Security → Private vulnerability reporting |
| Dependabot security updates | nicht sichtbar | an (ergänzt die wöchentlichen Update-PRs um sofortige Advisories) | Settings → Security → Dependabot |
| Secret scanning + Push protection | nicht sichtbar | an (für öffentliche Repos kostenlos) | Settings → Security → Secret scanning |
| Merge-Methoden | nicht sichtbar | nur Squash und Rebase, kein Merge-Commit; Branch nach Merge löschen | Settings → General → Pull Requests |
| Ruleset „Protect“ | aktiv auf allen Branches: kein Löschen, kein Force-Push, lineare History, PR-Pflicht ohne Reviews | zusätzlich: Pflicht-Check `gate`, `merge` aus den erlaubten Methoden raus | Settings → Rules → Rulesets |
| Tag-Schutz `v*` | keiner | Tags nicht löschbar, nicht verschiebbar | ebenda, neues Ruleset |
| Release-Attestierung | keine | kommt mit dem Release-Workflow ab dem nächsten Tag | nichts zu tun |
| Dependabot-Regel für Dev-Abhängigkeiten | nicht sichtbar | GitHub-Vorgabe „Dismiss low impact issues for development-scoped dependencies“ einschalten | Settings → Code security → Dependabot → Dependabot rules |

## Bekannter Dependabot-Alert ohne Fix: `elliptic`

GHSA-848j-6mx2-7j84 (Low, CVSS 2.9) meldet `elliptic` ≤ 6.6.1 als transitive **Entwicklungs**-Abhängigkeit: `@nextcloud/vite-config` → `vite-plugin-node-polyfills` → `node-stdlib-browser` → `crypto-browserify` → `browserify-sign`/`create-ecdh` → `elliptic`. Es gibt keine gepatchte Version, und `@nextcloud/vite-config` 3.0.0-beta.1 zieht das Polyfill-Paket weiterhin. Das gebaute `js/calendar_planning-main.mjs` enthält keinen Code aus `elliptic` (geprüft am 27.10.2026, 0 Treffer auf `elliptic|secp256k1|browserify-sign`); die App nutzt weder Kryptografie noch Node-Polyfills im Browser.

Umgang: Alert mit Grund „Vulnerable code is not actually used“ schließen. Die Regel oben schließt künftige Alerts dieser Art von selbst; `npm audit --omit=dev` in der CI bleibt scharf für alles, was im Paket landet. Neu bewerten, sobald `@nextcloud/vite-config` ohne `vite-plugin-node-polyfills` auskommt oder `elliptic` eine korrigierte Version bekommt.

## Befehle

Alle mit `gh` als Repo-Besitzer. `REPO=RalfPfitzner/Calendar-extension-Nextcloud`.

Beschreibung und Topics:

```bash
gh repo edit "$REPO" --description "Veranstaltungsplanung im Nextcloud-Kalender: Planungsfelder als Eigenschaften im Termin, Felder und Vorgaben vom Admin einstellbar" \
  --add-topic nextcloud --add-topic nextcloud-app --add-topic calendar --add-topic caldav --add-topic php --add-topic vue
```

Merge-Methoden und Branch-Löschung:

```bash
gh repo edit "$REPO" --enable-merge-commit=false --enable-squash-merge --enable-rebase-merge --delete-branch-on-merge
```

Private Sicherheitsmeldung und Dependabot security updates:

```bash
gh api -X PUT "repos/$REPO/private-vulnerability-reporting"
gh api -X PUT "repos/$REPO/vulnerability-alerts"
gh api -X PUT "repos/$REPO/automated-security-fixes"
```

Ruleset „Protect“ ergänzen (ID mit `gh api repos/$REPO/rulesets` holen; `PUT` ersetzt, ein `POST` würde ein zweites anlegen):

```bash
gh api -X PUT "repos/$REPO/rulesets/<ID>" --input - <<'JSON'
{
  "name": "Protect",
  "target": "branch",
  "enforcement": "active",
  "conditions": { "ref_name": { "include": ["~DEFAULT_BRANCH"], "exclude": [] } },
  "rules": [
    { "type": "deletion" },
    { "type": "non_fast_forward" },
    { "type": "required_linear_history" },
    { "type": "pull_request", "parameters": {
        "allowed_merge_methods": ["squash", "rebase"],
        "required_approving_review_count": 0,
        "dismiss_stale_reviews_on_push": false,
        "require_code_owner_review": false,
        "require_last_push_approval": false,
        "required_review_thread_resolution": false } },
    { "type": "required_status_checks", "parameters": {
        "strict_required_status_checks_policy": true,
        "required_status_checks": [ { "context": "gate", "integration_id": 15368 } ] } }
  ]
}
JSON
```

Hinweise dazu:

- Das bestehende Ruleset gilt für **alle** Branches (`~ALL`) und enthält `creation`. Damit kann niemand, auch der Besitzer nicht, einen neuen Branch im Repo anlegen; Arbeit läuft nur noch über Forks. Falls das nicht Absicht ist: Bedingung auf `~DEFAULT_BRANCH` setzen wie oben, dann sind Feature-Branches im Repo wieder möglich.
- `gate` ist der Sammel-Job aus `ci.yml`. Er muss **einmal gelaufen** sein, bevor GitHub ihn als Check kennt; nach dem Merge des PRs, der ihn einführt, ist das der Fall. `integration_id: 15368` ist GitHub Actions.
- `required_approving_review_count: 0` heißt: PR-Pflicht, aber kein zweiter Mensch nötig. CI und Diff sind trotzdem sichtbar, bevor etwas auf `main` landet.

Tag-Schutz:

```bash
gh api -X POST "repos/$REPO/rulesets" --input - <<'JSON'
{
  "name": "Release-Tags",
  "target": "tag",
  "enforcement": "active",
  "conditions": { "ref_name": { "include": ["refs/tags/v*"], "exclude": [] } },
  "rules": [ { "type": "deletion" }, { "type": "non_fast_forward" }, { "type": "update" } ]
}
JSON
```

Ein falscher Tag wird nicht verschoben, sondern durch den nächsten Patch-Release ersetzt; sonst lügen die Release-Notes ab dem Moment.

Prüfen:

```bash
gh api "repos/$REPO/rulesets" --jq '.[] | "\(.id) \(.name) \(.target) \(.enforcement)"'
gh api "repos/$REPO/private-vulnerability-reporting" --jq .enabled
```
