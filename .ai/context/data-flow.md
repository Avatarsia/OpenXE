# Datenfluss — OpenXE ERP

> Wie Daten durch das System fließen: vom HTTP-Request bis zur Datenbank und zurück.

## Request → Response Lifecycle

```mermaid
sequenceDiagram
    participant Browser
    participant index.php
    participant AppCore as ApplicationCore
    participant Page as www/pages/{module}.php
    participant erpAPI as class.erpapi.php
    participant YUI as class.yui.php
    participant DB as class.mysql.php
    participant TPL as Template Engine
    participant Template as .tpl Datei

    Browser->>index.php: GET ?module=artikel&action=edit&id=42
    index.php->>AppCore: Run()
    AppCore->>AppCore: Session/Auth prüfen
    AppCore->>Page: Instanziiere & rufe action()

    Page->>DB: SELECT (über erpAPI oder direkt)
    DB-->>Page: Daten als Array

    Page->>erpAPI: Business-Logik (Berechnung, Validierung)
    erpAPI->>DB: Weitere Queries
    DB-->>erpAPI: Ergebnisse
    erpAPI-->>Page: Verarbeitete Daten

    Page->>YUI: UI-Komponenten generieren (AutoComplete, DatePicker)
    YUI-->>Page: HTML/JS Fragmente

    Page->>TPL: $app->Tpl->Set('VARIABLE', $wert)
    TPL->>Template: Platzhalter ersetzen in .tpl
    Template-->>Browser: Fertige HTML-Seite
```

## Datenfluss bei Belegverarbeitung

```mermaid
flowchart LR
    subgraph Eingang
        A[Shop-Bestellung] --> B[shopimport_auftraege]
        C[Manueller Auftrag] --> D[auftrag]
    end

    B --> D

    subgraph Belegkette
        D --> E[lieferschein]
        D --> F[rechnung]
        E --> G[versand]
        F --> H[zahlungseingang]
        D --> I[bestellung an Lieferant]
    end

    subgraph Lager
        E --> J[lager_bewegung]
        J --> K[lager_platz_inhalt]
        I --> L[wareneingang]
        L --> J
    end

    subgraph Finanzen
        F --> M[kontoauszuege]
        H --> M
        M --> N[DATEV Export]
    end
```

## Daten-Formate im Code

### Eingangsdaten (User Input)
```php
// GET-Parameter
$id = $this->app->Secure->GetGET('id');         // string, "sanitized"
$action = $this->app->Secure->GetGET('action'); // string

// POST-Formulardaten
$input = $this->app->Secure->GetPOST('bezeichnung'); // string
// oder als Array:
$data = $_POST;  // ⚠️ unsanitized, Legacy-Pattern
```

### Interne Daten (zwischen Schichten)
```php
// Daten werden als assoziative Arrays herumgereicht:
$artikel = $this->app->DB->SelectArr("SELECT * FROM artikel WHERE id = :id", ['id' => $id]);
// Ergebnis: [['id' => 42, 'nummer' => 'ART-001', 'bezeichnung' => 'Widget', ...]]

// Einzelner Datensatz:
$row = $artikel[0];  // Array mit ~250 Keys für Artikel

// ⚠️ Kein Typ-System, kein DTO — reine Arrays
// Felder wie $row['ustfrei'], $row['umsatzsteuer'], $row['steuersatz']
// müssen aus dem Schema oder Code erschlossen werden
```

### Ausgangsdaten (Template)
```php
// Daten werden als Strings in Template-Platzhalter gesetzt:
$this->app->Tpl->Set('ARTIKELNAME', htmlspecialchars($row['bezeichnung']));
$this->app->Tpl->Set('PREIS', number_format($row['preis'], 2, ',', '.'));

// JavaScript wird als String injiziert:
$this->app->Tpl->Add('JAVASCRIPT', "var artikelId = {$row['id']};");
```

## Ziel-Datenfluss (nach Modernisierung)

```mermaid
sequenceDiagram
    participant Browser
    participant Controller
    participant Service
    participant Repository
    participant DB
    participant Twig as Template Engine

    Browser->>Controller: Request
    Controller->>Service: Business-Methode (typisierte Parameter)
    Service->>Repository: findById(int $id)
    Repository->>DB: Prepared Statement
    DB-->>Repository: Result Array
    Repository-->>Service: Entity (readonly class)
    Service-->>Controller: DTO / Entity
    Controller->>Twig: render('template.twig', ['artikel' => $entity])
    Twig-->>Browser: HTML (auto-escaped)
```

> [!NOTE]
> Im Ziel-Zustand fließen **typisierte Objekte** (Entities/DTOs) statt roher Arrays durch die Schichten. Templates escapen automatisch, und JavaScript erhält Daten über `data-*` Attribute statt PHP-Stringkonkatenation.
