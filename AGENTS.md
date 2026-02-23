# AGENTS.md — OpenXE ERP System

> Vendor-agnostic instructions for AI coding agents working on this project.
> Supported by: Cursor, Claude Code, OpenAI Codex, GitHub Copilot, Gemini, and others.

## ⚠️ SESSION PROTOCOL (MANDATORY — READ FIRST)

**You MUST complete these steps before doing ANY work. No exceptions.**

### Before ANY work — Session Start:
1. **Read** `.ai/handover/CURRENT_STATE.md` — Check if there is an active task from a previous AI agent
2. **If `active_task: true`:** REPORT the status to the user first and ask whether to continue or start fresh
3. **If `active_task: false`:** Ready for new work
4. **Read** the last entries in `.ai/handover/SESSION_LOG.md` for recent context
5. **Run** `git status` to check for uncommitted changes from a previous session
6. **Read** `.ai/handover/HANDOVER_PROTOCOL.md` for the complete protocol

### After EVERY work session — Session End:
1. **Overwrite** `.ai/handover/CURRENT_STATE.md` with current progress (see protocol for format)
2. **Update** `.ai/handover/SESSION_LOG.md` — add your entry, archive oldest if >3 entries
3. **Commit** incomplete work with `WIP: ` prefix, completed work with conventional commit
4. **Update** `.ai/changelog/CHANGELOG.md` if any task was completed

> Full protocol details: [.ai/handover/HANDOVER_PROTOCOL.md](.ai/handover/HANDOVER_PROTOCOL.md)

## Project Overview

OpenXE is a PHP-based open-source ERP system (fork of Xentral). It manages articles, orders, invoices, warehouse, CRM, and shop integrations. The project is undergoing a phased modernization from legacy PHP to PHP 8.5 with improved architecture.

- **Language:** PHP 8.5 (target), JavaScript, SQL
- **Database:** MySQL/MariaDB
- **Framework:** Custom (`phpwf/` framework), migration toward Symfony components planned
- **Template System:** Mixed — `.tpl` files, inline PHP/HTML, widget system
- **Package Manager:** Composer (PHP), npm (JS)

## Project Structure

```
OpenXE/
├── AGENTS.md              ← You are here (AI agent instructions)
├── .ai/                   ← AI-specific documentation and context
│   ├── OVERVIEW.md        ← Master document — START HERE
│   ├── context/           ← Architecture docs, domain knowledge
│   ├── decisions/         ← Architecture Decision Records (ADRs)
│   ├── skills/            ← Reusable agent skills
│   ├── prompts/           ← Reusable prompt templates
│   └── changelog/         ← AI-readable change documentation
├── doc/                   ← Human-readable documentation
├── classes/               ← Modern PHP classes (Modules, Components, Widgets)
├── phpwf/                 ← Core framework
├── www/                   ← Web root (pages, widgets, JS, objectapi)
├── cronjobs/              ← Scheduled tasks
├── vendor/                ← Composer dependencies
└── conf/                  ← Configuration files
```

## Critical Files (by impact)

| File | Lines | Role |
|------|-------|------|
| `www/lib/class.erpapi.php` | 39,520 | God-class — central business logic facade |
| `phpwf/plugins/class.yui.php` | 15,983 | UI component generator (HTML/JS in PHP) |
| `www/pages/*.php` | 147 files | Module page controllers |
| `www/objectapi/mysql/_gen/*.php` | 183 files | Auto-generated CRUD classes |
| `www/widgets/widget.*.php` | 89 files | Form/mask widget classes |

## Development Commands

```bash
# Install dependencies
composer install
npm install

# Run development server
php -S localhost:8080 -t www/

# Run tests
./vendor/bin/phpunit

# Build frontend assets
npm run build
```

## Coding Conventions

### MUST follow
- Use **Prepared Statements** for ALL new database queries (never `sprintf` with user input)
- New classes MUST use **PHP 8.5 features**: typed properties, readonly, enums, named arguments
- New code goes in `classes/` (Services, Repositories, Components), NOT in `www/pages/`
- Every new class MUST have a namespace under `Xentral\`
- No inline JavaScript in PHP — use `data-*` attributes or JSON blocks for data passing

### NEVER do
- Add methods to `class.erpapi.php` — create a Service class instead
- Add methods to `class.yui.php` — create a UI Component class instead
- Use `$app->DB->Select(sprintf(...))` — use prepared statements
- Generate HTML inside SQL queries
- Use HTML comments (`<!-- -->`) for conditional UI rendering

### Patterns to follow
- **Service classes** for business logic: `classes/Services/{Domain}Service.php`
- **Repository classes** for data access: `classes/Repository/{Entity}Repository.php`
- **UI Components** for frontend elements: `classes/Components/UI/{Component}.php`

## Verification

```bash
# After any code change, verify:
./vendor/bin/phpunit                    # Unit tests pass
php -l <changed-file>                  # No syntax errors
composer run-script phpstan            # Static analysis (if configured)
```

## Further Context

For detailed architecture documentation, modernization roadmap, and domain knowledge:
→ See [.ai/OVERVIEW.md](.ai/OVERVIEW.md)
