# MenuConfigurator Module

Per-User Customization der Sidebar-Navigation. Jeder Benutzer kann
einzelne Menueintraege ausblenden, umbenennen und neu sortieren.

## Komponenten

| Datei                                       | Zweck                                                              |
|---------------------------------------------|--------------------------------------------------------------------|
| `Bootstrap.php`                             | Container-Registration des `MenuConfiguratorService`               |
| `Service/MenuConfiguratorService.php`       | Lade-/Speicher-/Apply-Logik fuer die User-Konfiguration            |

## Persistenz

Die Konfiguration wird ueber den existierenden `UserConfigService` als
JSON-String unter dem Key `menu_configurator` in der Tabelle
`userkonfiguration` abgelegt. Es ist **kein** zusaetzliches DB-Schema
erforderlich.

Struktur des gespeicherten Werts:

```json
{
  "applyCustomStructure": false,
  "items": {
    "first|Stammdaten": {
      "visible": true,
      "order": null,
      "customTitle": null
    },
    "sec|Stammdaten|adresse|list|Adressen": {
      "visible": false,
      "order": 5,
      "customTitle": "Kontakte"
    }
  }
}
```

- **applyCustomStructure** — wenn `true`, wird die `order`-Spalte der
  Eintraege beim Sortieren beruecksichtigt.
- **items[].visible** — wenn `false`, wird der Eintrag aus der Sidebar
  entfernt.
- **items[].order** — optionale numerische Sortierposition.
- **items[].customTitle** — wenn gesetzt, wird statt des Original-Titels
  dieser Text angezeigt.

## Integration

`Service\MenuConfiguratorService::apply($menu)` wird in den beiden
Aufrufer-Sites von `erpAPI::Navigation()` aufgerufen, **nachdem** das
Menu berechnet wurde:

- `phpwf/class.player.php::BuildNavigation()`
- `www/eproosystem.php::createSidebarNavigation()`

Damit bleibt `class.erpapi.php` unveraendert; das Modul haengt sich
ausschliesslich an den existierenden Container und am Aufrufer-Site.

Im Settings-Bereich (`welcome.php::WelcomeSettings()`) wird der
Configurator als Form gerendert. Beim Submit wird die Action
`cmd=menu-configurator-save` ausgewertet, der eingehende Payload mit
einer Whitelist aus `MenuConfiguratorService::collectKeys()` validiert
und ueber `saveConfig()` persistiert.

## Tests

Es liegen keine automatischen Tests bei.
