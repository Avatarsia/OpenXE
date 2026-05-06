# LexwareOffice Module

Update-sicheres OpenXE-Modul fuer den Upload von OpenXE-Rechnungen an die Lexware Office API. Das Modul ist bewusst so gebaut, dass es keine Core-OpenXE-Dateien modifiziert und damit upstream-kompatibel bleibt. Die einzige optionale Ausnahme ist ein minimaler Patch fuer die SuperSearch-Integration (siehe unten).

## Architecture Overview

Das Modul folgt einem klaren Service-Layer-Aufbau:

- `Bootstrap.php` registriert alle Services im OpenXE-Container und stellt die Schema-Helper (`ensureSchema()`) bereit. Wird vom OpenXE-Installer per Auto-Discovery gefunden.
- `LexwareOfficeConfigService` verwaltet den verschluesselten API-Key in der `konfiguration`-Tabelle (AES-256-CBC + HMAC-SHA256) und triggert lazy die Schema-Migration beim ersten `saveApiKey()`.
- `LexwareOfficePayloadMapper` ist eine reine Mapping-Klasse ohne DB- oder HTTP-Seiteneffekte; sie uebersetzt OpenXE-Rechnungsobjekte in die Lexware-API-Payload.
- `LexwareOfficeApiClient` kapselt Guzzle, enthaelt Retry-Logik mit exponentiellem Backoff (1s/2s/4s), respektiert `Retry-After`-Header und setzt einen `Idempotency-Key` fuer `createInvoice()`.
- `LexwareOfficeService` orchestriert den Upload (Kontakt-Upsert, Rechnung erzeugen, Persistierung der Lexware-IDs in `rechnung.lexware_invoice_id` / `adresse.lexware_contact_id`).
- `www/pages/lexwareoffice.php` ist die eigene Page mit Settings-Form, dem `upload`-Action-Handler und den beiden Hook-Listenern fuer `Rechnung_Aktion_option` + `Rechnung_Aktion_case`.

## File Layout

```
classes/Modules/LexwareOffice/
├── Bootstrap.php                               # Service registration + schema helpers
├── Exception/
│   └── LexwareOfficeException.php
├── README.md                                   # This file
├── Service/
│   ├── LexwareOfficeApiClient.php              # HTTP client with retry + idempotency key
│   ├── LexwareOfficeConfigService.php          # Encrypted API-key storage
│   ├── LexwareOfficePayloadMapper.php          # Pure mapping, no DB/HTTP
│   └── LexwareOfficeService.php                # Upload orchestrator
└── install/
    ├── install.php                             # One-time setup
    └── uninstall.php                           # Symmetric teardown

classes/Modules/SuperSearch/SearchIndex/Provider/
└── LexwareOfficeProvider.php                   # SuperSearch index provider (own file)

www/pages/
├── lexwareoffice.php                           # Settings + action handler + hook listeners
└── content/
    └── lexwareoffice_settings.tpl              # API-key form template

tests/
└── lexware_payload_dump.php                    # Manual payload verification script
```

## Requirements

- OpenXE auf PHP 8.1+
- MySQL 5.7+ ODER MariaDB 10.0+
- Guzzle 6.5+ (im OpenXE-Core vorhanden)
- Lexware Office API-Key (https://app.lexware.io/addons/public-api)

## Installation

### Fresh install

1. Stelle sicher, dass die Modul-Dateien im OpenXE-Tree liegen (siehe File Layout oben).
2. Fuehre das Install-Script aus dem OpenXE-Projekt-Root aus:

   ```bash
   php classes/Modules/LexwareOffice/install/install.php
   ```

   Was passiert dabei:
   - Der Service-Cache `{WFuserdata}/tmp/{WFdbname}/cache_services.php` wird invalidiert.
   - OpenXE bootstrapped das Application-Objekt.
   - `Bootstrap::ensureSchema()` fuegt idempotent drei Spalten hinzu:
     - `adresse.lexware_contact_id` VARCHAR(36)
     - `rechnung.lexware_invoice_id` VARCHAR(36)
     - `rechnung.lexware_uploaded_at` DATETIME
   - `Lexwareoffice::Install()` registriert zwei Hooks in `hook_register`:
     - `Rechnung_Aktion_option` → Dropdown-Option in der Rechnungsansicht
     - `Rechnung_Aktion_case` → JS-Weiterleitung auf `module=lexwareoffice&action=upload`

   Das Script ist **idempotent** — mehrmaliges Ausfuehren ist ungefaehrlich.

3. Navigiere im Browser zu `index.php?module=lexwareoffice&action=edit`.
4. Hinterlege den Lexware-API-Key.
5. In der Rechnungsuebersicht (`index.php?module=rechnung&action=list`) erscheint die neue Dropdown-Option "An Lexware Office senden".

### Note: no settings tile in the Einstellungen overview

Das Modul ist bewusst NICHT als Tile in `www/pages/einstellungen.php` registriert, weil das einen Core-Touch bedeuten wuerde. Die Settings-Seite ist direkt ueber die URL oben oder via die globale SuperSearch (siehe optionaler Patch unten) erreichbar.

### Optional: SuperSearch integration (minimal core patch)

Wenn du willst, dass "Lexware Office Einstellungen" in der globalen SuperSearch-Suche erscheint, fuege in `classes/Modules/SuperSearch/Bootstrap.php` in der Methode `onInitSuperSearchProviderFactory()` folgende Zeilen ein:

```php
use Xentral\Modules\SuperSearch\SearchIndex\Provider\LexwareOfficeProvider;

// ... in onInitSuperSearchProviderFactory() nach den bestehenden registerProviderFactory-Calls:
$factory->registerProviderFactory('lexwareoffice', static function () use ($container) {
    return new LexwareOfficeProvider(
        $container->get('SystemConfigModule'),
        $container->get('Translator')
    );
});
```

(Passe den Code an die tatsaechlichen Konstruktor-Parameter von `LexwareOfficeProvider` an — siehe `classes/Modules/SuperSearch/SearchIndex/Provider/LexwareOfficeProvider.php`.)

Das ist der einzige unvermeidbare Core-Touch, wenn SuperSearch-Integration gewuenscht ist. Ohne den Patch funktioniert der Rest des Moduls vollstaendig, die Settings-Page ist dann nur via Direkt-URL erreichbar.

## Maintenance

### After pulling upstream updates

Wenn du den OpenXE-Master aktualisierst, fuehre diesen Check/Fix-Schritt aus:

```bash
git fetch origin
git rebase origin/master                # oder merge, je nach Workflow
php classes/Modules/LexwareOffice/install/install.php
```

Das Install-Script ist idempotent und stellt sicher, dass:
- Der Service-Cache den neuen Bootstrap findet
- Die Schema-Spalten noch existieren (falls Upstream die Tabellen geaendert hat)
- Die Hook-Registrierungen noch in der DB stehen

Wenn du den SuperSearch-Patch nutzt, musst du ihn nach einem Upstream-Rebase ggf. neu anwenden (Merge-Konflikt in `classes/Modules/SuperSearch/Bootstrap.php`).

### Manual payload verification

Das Modul kommt mit einem Reflection-freien Test-Script, das die Payload-Generation gegen die Lexware-API-Spec validiert:

```bash
php tests/lexware_payload_dump.php
```

Output sollte mit `All assertions passed.` enden.

## Uninstallation

Soft-uninstall (Hooks weg, Schema + API-Key bleiben):

```bash
php classes/Modules/LexwareOffice/install/uninstall.php
```

Full uninstall:

```bash
php classes/Modules/LexwareOffice/install/uninstall.php --drop-columns --delete-api-key
```

Das Script entfernt dann auch die drei Schema-Spalten und den verschluesselten API-Key aus der `konfiguration`-Tabelle. Vorsicht: wenn bereits Rechnungen via Lexware hochgeladen wurden, verlierst du die OpenXE-seitige Mapping-Information (die Rechnungen bleiben in Lexware erhalten).

## Troubleshooting

### Dropdown-Option "An Lexware Office senden" erscheint nicht

- Ist das Install-Script erfolgreich gelaufen? Siehe Output.
- Ist ein API-Key hinterlegt? Der Eintrag wird nur angezeigt, wenn der Key gesetzt ist (Guard in `LexwareOfficeAktionOption`).
- Stimmen die Hook-Eintraege? `SELECT * FROM hook_register WHERE module = 'lexwareoffice'` sollte zwei Zeilen liefern.
- Wurde der Service-Cache invalidiert? Loesche manuell `{WFuserdata}/tmp/{WFdbname}/cache_services.php`.

### "Kein Lexware Office API-Schluessel hinterlegt"

Navigiere zu `index.php?module=lexwareoffice&action=edit` und speichere den API-Key. Der Key wird verschluesselt in der `konfiguration`-Tabelle abgelegt (AES-256-CBC + HMAC-SHA256).

### HTTP 429 vom Lexware-Server

Lexware drosselt auf 2 Requests/Sekunde. Das Modul hat einen eingebauten Retry-Handler mit exponentiellem Backoff (1s/2s/4s), der bis zu 3 Retries macht. Bei `Retry-After`-Header respektiert es dessen Wert (gekappt auf 60 Sekunden). Nach drei fehlgeschlagenen Retries erhaeltst du eine user-freundliche Meldung.

### Schema-Spalten fehlen trotz Install

`Bootstrap::ensureSchema()` wird lazy beim ersten `saveApiKey()` ausgeloest. Wenn du das Install-Script nicht ausgefuehrt hast und der erste API-Key-Save fehlschlaegt, fuehre das Script manuell aus:

```bash
php classes/Modules/LexwareOffice/install/install.php
```

## Design Decisions

Kurze Begruendungen zu den wichtigeren architektonischen Entscheidungen:

- **Eigene Page + eigenes Action-Handler-Routing** statt rechnung.php-Edit, weil OpenXE keinen Mechanismus bietet, externe Action-Handler in bestehende Pages zu injizieren.
- **SHOW COLUMNS statt IF NOT EXISTS**, weil MySQL 5.7/8.0 das `IF NOT EXISTS`-Feature fuer `ALTER TABLE ADD COLUMN` nicht unterstuetzt. Die SHOW-COLUMNS-Methode ist portabel bis MySQL 3.23.
- **Lazy Schema-Migration beim saveApiKey()** statt bei jedem Bootstrap-Request, um den Hot-Path nicht zu belasten.
- **Idempotency-Key Header** bei `createInvoice()`, damit ein erneuter Upload nach verlorenem persistierten Marker keinen Duplikat in Lexware erzeugt (falls Lexware den Header honoriert).
- **Kein Settings-Tile in der Einstellungen-Uebersicht**, um einen Core-Touch an `einstellungen.php` zu vermeiden. Discoverability via direkter URL und optionaler SuperSearch-Integration.

## License

(Siehe OpenXE-Haupt-Lizenz.)
