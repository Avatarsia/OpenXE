# .ai/ — OpenXE AI Documentation Hub

> Master document for all AI-assisted development resources.
> Every AI agent, LLM, or human developer starts here.

---

## 📁 Verzeichnisstruktur

```
.ai/
├── OVERVIEW.md                  ← DU BIST HIER — Einstiegspunkt
│
├── handover/                    ← KI-Session-Übergabe (PFLICHT bei jedem Start/Ende)
│   ├── CURRENT_STATE.md         ← Aktueller Stand — wird immer überschrieben
│   ├── SESSION_LOG.md           ← Letzte 3 Sessions (Rolling Window)
│   ├── HANDOVER_PROTOCOL.md     ← Vollständiges Übergabe-Protokoll
│   └── archive/                 ← Archivierte Session-Einträge (nach Monat)
│       └── YYYY-MM.md
│
├── context/                     ← Architektur & Domänenwissen
│   ├── architecture.md          ← Aktuelle Systemarchitektur
│   ├── database-schema.md       ← 601 Tabellen, ER-Diagramme, Patterns
│   ├── data-flow.md             ← Request-Lifecycle, Belegkette, Ziel-Architektur
│   ├── dependency-injection.md  ← Container-System, Service-Registrierung
│   ├── module-map.md            ← Modulübersicht & Abhängigkeiten
│   ├── domain-glossary.md       ← Fachbegriffe (DE↔EN Mapping)
│   └── modernization-roadmap.md ← 11-Phasen-Modernisierungsplan
│
├── decisions/                   ← Architecture Decision Records (ADRs)
│   ├── _template.md             ← ADR-Vorlage
│   ├── 0001-php85-migration.md  ← Entscheidung: PHP 8.5 Zielversion
│   └── ...                      ← Fortlaufend nummeriert
│
├── skills/                      ← Wiederverwendbare Agent-Skills
│   ├── prepared-statements/     ← Skill: SQL Prepared Statements
│   │   └── SKILL.md
│   ├── service-extraction/      ← Skill: erpAPI Methoden extrahieren
│   │   └── SKILL.md
│   ├── repository-pattern/      ← Skill: ObjectAPI → Repository
│   │   └── SKILL.md
│   ├── list-abstraction/        ← Skill: TableSearch abstrahieren
│   │   └── SKILL.md
│   └── error-handling/          ← Skill: Exception-Hierarchie & Patterns
│       └── SKILL.md
│
├── prompts/                     ← Wiederverwendbare Prompt-Templates
│   ├── code-review.md           ← Prompt für Code-Reviews
│   ├── consistency-check.md     ← Prompt: Pattern-Konsistenz prüfen
│   ├── refactoring-checklist.md ← Prompt für Refactoring-Aufgaben
│   └── security-audit.md        ← Prompt für Sicherheits-Audits
│
├── changelog/                   ← KI-lesbare Änderungsdokumentation
│   ├── CHANGELOG.md             ← Keep-a-Changelog Format
│   └── migration-log.md         ← Modernisierungs-Fortschritt
│
└── best-practices/              ← Coding-Standards & Richtlinien
    ├── php-standards.md         ← PHP Coding Standards
    ├── security.md              ← Sicherheitsrichtlinien
    ├── testing.md               ← Test-Strategie & Patterns
    ├── git-workflow.md          ← Branch-Strategie & Commit-Konventionen
    └── ci-cd.md                 ← Build, Test & Deployment
```

---

## 🎯 Quick Navigation nach Rolle

### Für KI-Agenten (LLMs)
1. **Starte** mit [AGENTS.md](../AGENTS.md) (Root) — Projekt-Überblick & Regeln
2. **Verstehe** die Architektur → [context/architecture.md](context/architecture.md)
3. **Prüfe** relevante ADRs → [decisions/](decisions/)
4. **Nutze** Skills für wiederkehrende Aufgaben → [skills/](skills/)
5. **Dokumentiere** Änderungen → [changelog/CHANGELOG.md](changelog/CHANGELOG.md)

### Für menschliche Entwickler
1. **Starte** mit [../README.md](../README.md) — Projekt-Einführung
2. **Verstehe** die Domäne → [context/domain-glossary.md](context/domain-glossary.md)
3. **Prüfe** Architektur-Entscheidungen → [decisions/](decisions/)
4. **Folge** den Standards → [best-practices/](best-practices/)
5. **Lies** das Changelog → [changelog/CHANGELOG.md](changelog/CHANGELOG.md)

### Für neue Teammitglieder
1. [../README.md](../README.md) → Was ist OpenXE?
2. [../INSTALL.md](../INSTALL.md) → Wie richte ich es ein?
3. [context/architecture.md](context/architecture.md) → Wie ist es aufgebaut?
4. [context/domain-glossary.md](context/domain-glossary.md) → Was bedeuten die Fachbegriffe?
5. [context/modernization-roadmap.md](context/modernization-roadmap.md) → Wohin geht die Reise?

---

## 📏 Konventionen für diese Dokumentation

### Datei-Benennung
- Alles in **Kebab-Case**: `architecture-overview.md`, nicht `ArchitectureOverview.md`
- ADRs: `NNNN-kurze-beschreibung.md` (fortlaufend nummeriert)
- Skills: Ordner-Name = Skill-Name in Kebab-Case

### Sprache
- **Technische Docs** (context/, best-practices/): Deutsch, Code-Begriffe in Englisch
- **AGENTS.md** (Root): Englisch (internationaler Standard)
- **Skills/Prompts**: Englisch (LLM-optimiert)
- **ADRs**: Deutsch oder Englisch (Verfasser-Wahl)

### Aktualisierung
- ADRs werden **nie gelöscht**, nur superseded
- Changelog wird bei **jedem Merge** aktualisiert
- Context-Docs werden aktualisiert wenn sich die Architektur ändert
- Skills werden erweitert wenn neue Patterns etabliert sind
