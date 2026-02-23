# ADR-0001: PHP 8.5 als Zielversion

## Metadaten

| Feld | Wert |
|------|------|
| **Status** | **Accepted** |
| **Datum** | 2026-02-01 |
| **Autoren** | Team |
| **Betroffene Phase** | Phase 0 (PHP 8.5 Merge) |

## Kontext

OpenXE läuft aktuell auf einer älteren PHP-Version. Um die Modernisierung des Codebases langfristig abzusichern und moderne PHP-Features nutzen zu können, muss eine Zielversion festgelegt werden. Eine PHP-8.5-Migration wurde bereits in einem separaten Repository vorbereitet und kann gemergt werden.

## Entscheidung

**PHP 8.5** wird als Zielversion gewählt, da es die aktuellste stabile Version ist und die längste Laufzeit bietet, bevor die nächste Migration notwendig wird ("möglichst lange Ruhe").

Alle neuen Code-Änderungen MÜSSEN PHP 8.5 Features nutzen:
- Typed Properties & Readonly Properties/Classes
- Enums
- Named Arguments
- Intersection & Union Types
- Fibers (für async, falls benötigt)
- `match` Expressions

## Alternativen

### Alternative A: PHP 8.3 (konservativer Ansatz)
- **Vorteile:** Breitere Hosting-Kompatibilität
- **Nachteile:** Weniger moderne Features, früher erneute Migration nötig
- **Grund für Ablehnung:** Ziel ist maximale Zukunftssicherheit

### Alternative B: PHP 8.4
- **Vorteile:** Stabiler, mehr Hosting-Support
- **Nachteile:** Weniger Features als 8.5, trotzdem bald veraltet
- **Grund für Ablehnung:** 8.5 bietet Property Hooks und weitere Features

## Konsequenzen

### Positiv
- Modernste PHP-Features für sauberen, typsicheren Code
- Lange Laufzeit bis zur nächsten Migration
- PHP-8.5-Repo bereits vorbereitet

### Negativ
- Hosting-Provider müssen PHP 8.5 unterstützen
- Alle Composer-Dependencies müssen kompatibel sein

## Referenzen

- Früheres Gespräch: Composer-Dependency-Prüfung für PHP 8.5
- Früheres Gespräch: PHP 8.5 Lint-Errors beheben
