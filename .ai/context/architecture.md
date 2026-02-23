# Systemarchitektur — OpenXE ERP

> Dieses Dokument beschreibt die **aktuelle** Architektur des OpenXE-Systems (Stand: Februar 2026).
> Es wird aktualisiert, wenn sich die Architektur durch Modernisierungsphasen ändert.

---

## Architektur-Übersicht

```
┌─────────────────────────────────────────────────────────┐
│                     HTTP Request                        │
├─────────────────────────────────────────────────────────┤
│  index.php → phpwf/ApplicationCore                      │
│    ├── Routing (phpwf/class.application_core.php)       │
│    ├── Auth / Session                                   │
│    └── Module Dispatch                                  │
├────────────┬────────────┬───────────────────────────────┤
│  Pages     │  Widgets   │  API (www/objectapi/)         │
│  (www/     │  (www/     │  (183 gen. CRUD-Klassen)      │
│  pages/)   │  widgets/) │                               │
│  147 Module│  89 Widgets│                               │
├────────────┴────────────┴───────────────────────────────┤
│  class.erpapi.php (39.520 Zeilen, 1.038 Methoden)       │
│  → Business-Logik Facade (God Object)                   │
├─────────────────────────────────────────────────────────┤
│  class.yui.php (15.983 Zeilen, 77 Methoden)             │
│  → UI-Generierung (HTML/JS in PHP)                      │
├─────────────────────────────────────────────────────────┤
│  classes/Modules/ (125 Module)                          │
│  classes/Components/ (352 Dateien)                      │
│  → Neuere, teils strukturierte Geschäftslogik           │
├─────────────────────────────────────────────────────────┤
│  phpwf/plugins/class.mysql.php                          │
│  → Datenbank-Abstraktionsschicht (kein PDO/Prep.Stmts)  │
├─────────────────────────────────────────────────────────┤
│  MySQL / MariaDB                                        │
└─────────────────────────────────────────────────────────┘
```

## Request-Lifecycle

1. `index.php` → `ApplicationCore::Run()`
2. Session/Auth-Prüfung
3. Routing: `$module` + `$action` aus GET/POST
4. Lade `www/pages/{module}.php`
5. Instanziiert Modul-Klasse, ruft `{action}()` auf
6. Modul nutzt `$app->erp->...` (erpAPI), `$app->DB->...` (MySQL), `$app->YUI->...` (UI)
7. Template-Rendering via `$app->Tpl->Set()`/`$app->Tpl->Add()` auf `.tpl`-Dateien

## Bekannte Architektur-Probleme

| Problem | Schweregrad | Betroffene Dateien | Modernisierungs-Phase |
|---------|-------------|--------------------|-----------------------|
| SQL-Injection (sprintf/Interpolation) | 🔴 Kritisch | Alle Pages, ObjectAPI | Phase 1 |
| God Object (erpAPI) | 🔴 Kritisch | `class.erpapi.php` | Phase 2 |
| HTML in SQL-Queries | 🟠 Hoch | Pages (TableSearch) | Phase 4 |
| Inline JS in PHP | 🟠 Hoch | Pages, Widgets | Phase 7 |
| Fragmentierte Maskensysteme | 🟡 Mittel | .tpl, Widgets, PHP | Phase 6 |
| Fehlende Abstraktion (Listen) | 🟡 Mittel | 147 Pages | Phase 4 |

## Verzeichnis-Referenz

| Verzeichnis | Zweck | Status |
|-------------|-------|--------|
| `classes/Modules/` | Neuere Modul-Implementierungen | Aktiv, heterogen |
| `classes/Components/` | Geteilte Komponenten | Aktiv |
| `classes/Services/` | Domain-Services | Geplant (Phase 2) |
| `classes/Repository/` | Repository-Pattern | Geplant (Phase 3) |
| `www/pages/` | Legacy Page-Controller | Legacy, wird migriert |
| `www/widgets/` | Legacy Widget-System | Legacy, wird migriert |
| `www/objectapi/mysql/_gen/` | Auto-gen. CRUD-Klassen | Legacy, wird ersetzt |
| `phpwf/` | Core Framework | Legacy, schrittweise modernisiert |
