# Git Workflow & Commit-Konventionen — OpenXE

## Branch-Strategie

```
main/master         ← Stabiler, produktionsfähiger Code
  └── develop       ← Integrations-Branch
       ├── feature/phase1-db-layer        ← Feature-Branches (pro Phase/Aufgabe)
       ├── feature/phase2-erp-extraction
       ├── fix/sql-injection-wiki
       └── refactor/artikel-repository
```

## Branch-Benennung

| Typ | Format | Beispiel |
|-----|--------|---------|
| Feature | `feature/{phase}-{beschreibung}` | `feature/phase1-prepared-statements` |
| Bugfix | `fix/{beschreibung}` | `fix/sql-injection-welcome` |
| Refactoring | `refactor/{beschreibung}` | `refactor/artikel-service-extraction` |
| Hotfix | `hotfix/{beschreibung}` | `hotfix/critical-auth-bypass` |

## Conventional Commits

Format: `{typ}({scope}): {beschreibung}`

```bash
# Typen
feat(artikel):     Add ArticleRepository with prepared statements
fix(security):     Replace sprintf in wiki.php with prepared statement
refactor(erpapi):  Extract document methods to DocumentService
docs(ai):          Add ADR-0002 for repository pattern
test(artikel):     Add unit tests for ArticleService
chore(deps):       Update phpunit to 11.x
```

## Pull Request Checkliste

- [ ] Branch ist aktuell mit `develop`
- [ ] Alle Tests laufen (`phpunit`)
- [ ] `php -l` fehlerfrei auf geänderten Dateien
- [ ] CHANGELOG.md aktualisiert
- [ ] Relevante ADRs erstellt/aktualisiert (falls architekturrelevant)
- [ ] Keine neuen Methoden in `class.erpapi.php` oder `class.yui.php`
- [ ] Keine `sprintf` oder Interpolation in SQL
- [ ] Code-Review durchgeführt (menschlich oder KI)
