# Modulübersicht & Abhängigkeiten — OpenXE

> Übersicht der Modul-Landschaft und ihrer Abhängigkeiten untereinander.

## Legacy-Module (`www/pages/`)

Die 147 Page-Module bilden den Kern der Benutzeroberfläche. Jedes Modul wird über `index.php?module={name}` aufgerufen.

### Kernmodule (höchste Abhängigkeiten)

| Modul | Datei | Größe | Abhängigkeiten |
|-------|-------|-------|----------------|
| `artikel` | `artikel.php` | 461 KB | erpAPI, YUI, Widgets, ObjectAPI |
| `auftrag` | `auftrag.php` | 331 KB | erpAPI, YUI, Widgets, Artikel, Adresse |
| `rechnung` | `rechnung.php` | ~250 KB | erpAPI, YUI, Widgets, Auftrag, Adresse |
| `lieferschein` | `lieferschein.php` | ~200 KB | erpAPI, YUI, Auftrag, Lager |
| `adresse` | `adresse.php` | ~200 KB | erpAPI, YUI, Widgets |
| `api` | `api.php` | 618 KB | erpAPI (REST-API-Endpunkt) |

### Modul-Abhängigkeitskette (Belege)

```
Angebot → Auftrag → Rechnung
                  → Lieferschein → Retoure
                  → Gutschrift
                  → Bestellung (Einkauf)
```

## Moderne Module (`classes/Modules/`)

125 Module mit heterogener Struktur. Diese folgen teilweise neueren Patterns, sind aber nicht einheitlich.

## Querschnittsfunktionen

Funktionen die von fast allen Modulen genutzt werden:

| Funktion | Quelle | Nutzungsart |
|----------|--------|-------------|
| DB-Zugriff | `class.mysql.php` | `$app->DB->...` |
| Business-Logik | `class.erpapi.php` | `$app->erp->...` |
| UI-Generierung | `class.yui.php` | `$app->YUI->...` |
| Templating | `class.template.php` | `$app->Tpl->...` |
| Sicherheit | `class.secure.php` | `$app->Secure->...` |
| Modul-Check | erpAPI | `$app->erp->ModulVorhanden()` |
