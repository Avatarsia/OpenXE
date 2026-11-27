# WordPress-Verbindungs-Tab (RepairIntegration) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tab 2 "WordPress-Verbindung" auf der RepairIntegration-Einstellungsseite: Endpoint-URL anzeigen, Secrets server-seitig generieren, alles per Klick kopierbar.

**Architecture:** POST+Reload auf die bestehende `RepairSettings()`-Action (keine neue Action, kein AJAX). Endpoint-URL-Ableitung als pure statische Helper-Klasse (unit-testbar). Copy/Toggle als Vanilla-JS in der bestehenden Modul-JS-Datei, mit Fallback fuer Nicht-Secure-Context (Test-VM laeuft ueber http).

**Tech Stack:** PHP 8.1-kompatibel (Ziel-Server), Legacy-Tpl-Engine (`[PLATZHALTER]`), jQuery (im ERP global vorhanden), PHPUnit-Testdatei nach Repo-Konvention.

**Spec:** `docs/superpowers/specs/2026-07-27-repair-wp-connection-settings-design.md`

## Global Constraints

- Branch: `feature/repair-integration-port`, Worktree `C:\worktrees\openxe-repair-integration`
- PHP 8.1-Kompatibilitaet (kein `json_validate()`, keine 8.2+-Features) — Ziel-Server ist 8.1
- `php -l` auf jede geaenderte PHP-Datei vor jedem Commit
- Keine Umlaute in Code/Kommentaren (Modul-Konvention: "laeuft", "Menue")
- Kein `sed -i` (CRLF-Repo) — nur Edit-Tool
- Trailing Whitespace vermeiden
- PHPUnit-Binary ist lokal NICHT installiert (`vendor/` ohne dev-deps). Testdatei trotzdem nach Repo-Konvention anlegen; lokale Verifikation via `php -r`-Assertions wie in den Steps angegeben
- Commits nur lokal; Push erst nach explizitem User-Go

---

### Task 1: RepairConnectionInfo Helper + Unit-Test

**Files:**
- Create: `classes/Modules/RepairIntegration/Service/RepairConnectionInfo.php`
- Test: `tests/Unit/Modules/RepairIntegration/Service/RepairConnectionInfoTest.php`

**Interfaces:**
- Produces: `\Xentral\Modules\RepairIntegration\Service\RepairConnectionInfo::endpointUrl(array $server): string` — nimmt `$_SERVER`-artiges Array, liefert `<scheme>://<host>/repairapi/index.php/repair-status`

- [ ] **Step 1: Testdatei schreiben**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration\Service;

use PHPUnit\Framework\TestCase;
use Xentral\Modules\RepairIntegration\Service\RepairConnectionInfo;

class RepairConnectionInfoTest extends TestCase
{
    public function testHttpsWhenHttpsOn(): void
    {
        $url = RepairConnectionInfo::endpointUrl(['HTTPS' => 'on', 'HTTP_HOST' => 'erp.example.com']);
        self::assertSame('https://erp.example.com/repairapi/index.php/repair-status', $url);
    }

    public function testHttpWhenHttpsOff(): void
    {
        $url = RepairConnectionInfo::endpointUrl(['HTTPS' => 'off', 'HTTP_HOST' => '192.168.0.150']);
        self::assertSame('http://192.168.0.150/repairapi/index.php/repair-status', $url);
    }

    public function testHttpWhenHttpsMissing(): void
    {
        $url = RepairConnectionInfo::endpointUrl(['HTTP_HOST' => '192.168.0.150']);
        self::assertSame('http://192.168.0.150/repairapi/index.php/repair-status', $url);
    }

    public function testHostWithPortIsKept(): void
    {
        $url = RepairConnectionInfo::endpointUrl(['HTTPS' => '', 'HTTP_HOST' => 'localhost:8081']);
        self::assertSame('http://localhost:8081/repairapi/index.php/repair-status', $url);
    }

    public function testFallbackHostWhenMissing(): void
    {
        $url = RepairConnectionInfo::endpointUrl([]);
        self::assertSame('http://localhost/repairapi/index.php/repair-status', $url);
    }
}
```

- [ ] **Step 2: Verifizieren, dass die Klasse noch fehlt**

Run (im Worktree-Root):
```bash
php -r "require 'xentral_autoloader.php'; var_dump(class_exists('Xentral\\\\Modules\\\\RepairIntegration\\\\Service\\\\RepairConnectionInfo'));"
```
Expected: `bool(false)`

- [ ] **Step 3: Helper implementieren**

`classes/Modules/RepairIntegration/Service/RepairConnectionInfo.php`:

```php
<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

/**
 * Leitet die Verbindungsdaten ab, die das WordPress-Plugin braucht.
 * Pure statische Helper-Klasse ohne Abhaengigkeiten (unit-testbar).
 */
final class RepairConnectionInfo
{
    public const ENDPOINT_PATH = '/repairapi/index.php/repair-status';

    /**
     * Baut die absolute Inbound-Endpoint-URL aus einem $_SERVER-artigen Array.
     */
    public static function endpointUrl(array $server): string
    {
        $https = (string)($server['HTTPS'] ?? '');
        $scheme = ($https !== '' && strtolower($https) !== 'off') ? 'https' : 'http';
        $host = (string)($server['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host . self::ENDPOINT_PATH;
    }
}
```

- [ ] **Step 4: Lint + Assertions ausfuehren**

Run (im Worktree-Root):
```bash
php -l classes/Modules/RepairIntegration/Service/RepairConnectionInfo.php
php -l tests/Unit/Modules/RepairIntegration/Service/RepairConnectionInfoTest.php
php -r "
require 'xentral_autoloader.php';
use Xentral\Modules\RepairIntegration\Service\RepairConnectionInfo as C;
assert(C::endpointUrl(['HTTPS'=>'on','HTTP_HOST'=>'erp.example.com']) === 'https://erp.example.com/repairapi/index.php/repair-status');
assert(C::endpointUrl(['HTTPS'=>'off','HTTP_HOST'=>'192.168.0.150']) === 'http://192.168.0.150/repairapi/index.php/repair-status');
assert(C::endpointUrl(['HTTP_HOST'=>'192.168.0.150']) === 'http://192.168.0.150/repairapi/index.php/repair-status');
assert(C::endpointUrl(['HTTPS'=>'','HTTP_HOST'=>'localhost:8081']) === 'http://localhost:8081/repairapi/index.php/repair-status');
assert(C::endpointUrl([]) === 'http://localhost/repairapi/index.php/repair-status');
echo 'ALL PASS';
" 
```
Expected: 2x `No syntax errors detected`, dann `ALL PASS`

- [ ] **Step 5: Commit**

```bash
git add classes/Modules/RepairIntegration/Service/RepairConnectionInfo.php tests/Unit/Modules/RepairIntegration/Service/RepairConnectionInfoTest.php
git commit -m "feat(repair): add RepairConnectionInfo helper for WP endpoint URL"
```

---

### Task 2: RepairSettings() — Generate-Zweige + Tpl-Variablen

**Files:**
- Modify: `www/pages/repairintegration.php` (Methode `RepairSettings()`, ca. Zeile 87-119)

**Interfaces:**
- Consumes: `RepairConnectionInfo::endpointUrl(array): string` (Task 1); `RepairConfigService::set(string,string): void`, `getInboundSharedSecret(): string`, `getWpApiKey(): string` (bestehend)
- Produces: Tpl-Variablen fuer Task 3: `ENDPOINT_URL`, `GEN_INBOUND_LABEL`, `GEN_INBOUND_CONFIRM`, `GEN_WPKEY_LABEL`, `GEN_WPKEY_CONFIRM` (zusaetzlich zu bestehenden `WP_API_KEY`, `INBOUND_SHARED_SECRET`); akzeptierte POST-Werte `submit=generate_inbound_secret` / `submit=generate_wp_api_key`

- [ ] **Step 1: Generate-Zweig einbauen**

In `RepairSettings()` direkt NACH dem bestehenden `if ($this->app->Secure->GetPOST('submit') === 'save') { ... }`-Block und VOR `$this->app->Tpl->Set('ENABLED', ...)` einfuegen:

```php
        $submit = $this->app->Secure->GetPOST('submit');
        if ($submit === 'generate_inbound_secret' || $submit === 'generate_wp_api_key') {
            $configKey = $submit === 'generate_inbound_secret' ? 'inbound_shared_secret' : 'wp_api_key';
            try {
                $config->set($configKey, bin2hex(random_bytes(32)));
                $this->app->Tpl->Set(
                    'MESSAGE',
                    '<div class="info">Neuer Schluessel generiert. Wert ins WordPress-Plugin uebernehmen.</div>'
                );
            } catch (\Throwable $e) {
                $this->app->Tpl->Set(
                    'MESSAGE',
                    '<div class="error">Generierung fehlgeschlagen: ' . htmlspecialchars($e->getMessage()) . '</div>'
                );
            }
        }
```

- [ ] **Step 2: Tpl-Variablen fuer Tab 2 setzen**

Direkt VOR `$this->app->Tpl->Set('KURZUEBERSCHRIFT', 'RepairIntegration Einstellungen');` einfuegen (nach den bestehenden `Tpl->Set`-Zeilen, damit frisch generierte Werte bereits gelesen sind):

```php
        $this->app->Tpl->Set(
            'ENDPOINT_URL',
            htmlspecialchars(\Xentral\Modules\RepairIntegration\Service\RepairConnectionInfo::endpointUrl($_SERVER))
        );
        $inboundSecret = $config->getInboundSharedSecret();
        $wpApiKey = $config->getWpApiKey();
        $this->app->Tpl->Set('GEN_INBOUND_LABEL', $inboundSecret === '' ? 'Generieren' : 'Neu generieren');
        $this->app->Tpl->Set('GEN_INBOUND_CONFIRM', $inboundSecret === '' ? '' : ' data-confirm="1"');
        $this->app->Tpl->Set('GEN_WPKEY_LABEL', $wpApiKey === '' ? 'Generieren' : 'Neu generieren');
        $this->app->Tpl->Set('GEN_WPKEY_CONFIRM', $wpApiKey === '' ? '' : ' data-confirm="1"');
```

Wichtig: Die bestehenden Zeilen `WP_API_KEY`/`INBOUND_SHARED_SECRET` lesen den Config-Wert erneut via Getter — nach einem Generate-POST liefern sie automatisch den neuen Wert. Nichts umstellen.

- [ ] **Step 3: Lint**

Run: `php -l www/pages/repairintegration.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add www/pages/repairintegration.php
git commit -m "feat(repair): generate WP connection secrets via settings action"
```

---

### Task 3: Template — Tab 2 "WordPress-Verbindung"

**Files:**
- Modify: `www/pages/content/repair_config.tpl`

**Interfaces:**
- Consumes: Tpl-Variablen aus Task 2 (`ENDPOINT_URL`, `GEN_*`), bestehende `INBOUND_SHARED_SECRET`, `WP_API_KEY`
- Produces: DOM-Elemente fuer Task 4: Inputs `#repairEndpointUrl`, `#repairInboundSecret`, `#repairWpApiKey`; Button-Klassen `.repairCopyBtn`, `.repairToggleBtn` (je mit `data-target="<input-id>"`); Forms `.repairGenForm`

- [ ] **Step 1: Tab-Leiste erweitern**

```html
    <ul>
        <li><a href="#tabs-1">Einstellungen</a></li>
        <li><a href="#tabs-2">WordPress-Verbindung</a></li>
    </ul>
```

- [ ] **Step 2: Tab-2-Block einfuegen**

Nach dem schliessenden `</div>` von `#tabs-1`, vor dem finalen `</div>` von `#tabs`:

```html
    <div id="tabs-2">
        [MESSAGE]
        <table class="mkTableFormular">
            <tr><td colspan="2"><h2>Verbindungsdaten fuer das WordPress-Plugin</h2></td></tr>
            <tr>
                <td width="220">OpenXE Endpoint-URL:</td>
                <td>
                    <input type="text" id="repairEndpointUrl" value="[ENDPOINT_URL]" size="60" readonly>
                    <button type="button" class="repairCopyBtn" data-target="repairEndpointUrl">Kopieren</button>
                    <div class="repairHint">Im WP-Plugin als OpenXE-Endpoint eintragen.</div>
                </td>
            </tr>
            <tr>
                <td>Inbound Shared Secret:</td>
                <td>
                    <input type="password" id="repairInboundSecret" value="[INBOUND_SHARED_SECRET]" size="60" readonly>
                    <button type="button" class="repairToggleBtn" data-target="repairInboundSecret">Anzeigen</button>
                    <button type="button" class="repairCopyBtn" data-target="repairInboundSecret">Kopieren</button>
                    <form method="post" class="repairGenForm">
                        <input type="hidden" name="submit" value="generate_inbound_secret">
                        <button type="submit" class="repairGenBtn"[GEN_INBOUND_CONFIRM]>[GEN_INBOUND_LABEL]</button>
                    </form>
                    <div class="repairHint">Im WP-Plugin als Bearer-Token eintragen.</div>
                </td>
            </tr>
            <tr>
                <td>WP API-Key (Outbound):</td>
                <td>
                    <input type="password" id="repairWpApiKey" value="[WP_API_KEY]" size="60" readonly>
                    <button type="button" class="repairToggleBtn" data-target="repairWpApiKey">Anzeigen</button>
                    <button type="button" class="repairCopyBtn" data-target="repairWpApiKey">Kopieren</button>
                    <form method="post" class="repairGenForm">
                        <input type="hidden" name="submit" value="generate_wp_api_key">
                        <button type="submit" class="repairGenBtn"[GEN_WPKEY_CONFIRM]>[GEN_WPKEY_LABEL]</button>
                    </form>
                    <div class="repairHint">Im WP-Plugin als API-Key fuer eingehende Status-Updates eintragen.</div>
                </td>
            </tr>
        </table>
    </div>
```

Hinweis: `[MESSAGE]` erscheint dadurch in beiden Tabs — gewollt, damit die Erfolgsmeldung nach einem Generate-POST auch auf Tab 2 sichtbar ist (jQuery-UI-Tabs zeigen nach Reload Tab 1; Meldung ist dort ebenfalls sichtbar, kein Schaden).

- [ ] **Step 3: Commit**

```bash
git add www/pages/content/repair_config.tpl
git commit -m "feat(repair): add WordPress connection tab to settings template"
```

---

### Task 4: JS (Copy/Toggle/Confirm) + CSS

**Files:**
- Modify: `classes/Modules/RepairIntegration/www/js/repairintegration.js`
- Modify: `classes/Modules/RepairIntegration/www/css/repairintegration.css`

**Interfaces:**
- Consumes: DOM aus Task 3 (`.repairCopyBtn`, `.repairToggleBtn`, `.repairGenForm`, `data-target`, `data-confirm`)

- [ ] **Step 1: JS ergaenzen**

An `classes/Modules/RepairIntegration/www/js/repairintegration.js` innerhalb von `$(document).ready` anhaengen (nach dem bestehenden Dropdown-Block):

```js
    // --- WordPress-Verbindungs-Tab -------------------------------------

    function repairSetTempLabel(btn, text) {
        var original = btn.textContent;
        btn.textContent = text;
        window.setTimeout(function() { btn.textContent = original; }, 1500);
    }

    // Copy: Clipboard-API nur im Secure Context (https/localhost) verfuegbar.
    // Test-VM laeuft ueber http://192.168.0.150 -> execCommand-Fallback.
    $(document).on('click', '.repairCopyBtn', function() {
        var btn = this;
        var input = document.getElementById(btn.getAttribute('data-target'));
        if (!input) { return; }
        var value = input.value;
        if (window.isSecureContext && navigator.clipboard) {
            navigator.clipboard.writeText(value).then(function() {
                repairSetTempLabel(btn, 'Kopiert!');
            }, function() {
                repairSetTempLabel(btn, 'Fehler');
            });
            return;
        }
        var wasPassword = input.type === 'password';
        if (wasPassword) { input.type = 'text'; }
        input.select();
        input.setSelectionRange(0, value.length);
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        if (wasPassword) { input.type = 'password'; }
        repairSetTempLabel(btn, ok ? 'Kopiert!' : 'Manuell kopieren');
    });

    $(document).on('click', '.repairToggleBtn', function() {
        var input = document.getElementById(this.getAttribute('data-target'));
        if (!input) { return; }
        var hidden = input.type === 'password';
        input.type = hidden ? 'text' : 'password';
        this.textContent = hidden ? 'Verbergen' : 'Anzeigen';
    });

    $(document).on('submit', '.repairGenForm', function(e) {
        var btn = this.querySelector('button[data-confirm="1"]');
        if (btn && !window.confirm(
            'Vorhandenen Schluessel wirklich ersetzen? Das WordPress-Plugin muss danach aktualisiert werden.'
        )) {
            e.preventDefault();
        }
    });
```

- [ ] **Step 2: CSS ergaenzen**

An `classes/Modules/RepairIntegration/www/css/repairintegration.css` anhaengen:

```css
/* WordPress-Verbindungs-Tab */
.repairHint {
    color: #888;
    font-size: 11px;
    margin-top: 2px;
}
.repairGenForm {
    display: inline;
}
```

- [ ] **Step 3: JS-Syntax pruefen**

Run: `node --check classes/Modules/RepairIntegration/www/js/repairintegration.js`
Expected: kein Output, Exit 0
(Falls `node` nicht im PATH: Schritt dokumentieren und im Browser-Test von Task 5 verifizieren.)

- [ ] **Step 4: Commit**

```bash
git add classes/Modules/RepairIntegration/www/js/repairintegration.js classes/Modules/RepairIntegration/www/css/repairintegration.css
git commit -m "feat(repair): copy/toggle/confirm JS for WP connection tab"
```

---

### Task 5: E2E-Checkliste + Gesamt-Verifikation

**Files:**
- Modify: `tests/E2E/RepairIntegration/README.md` (Abschnitt "Manuelle Checkliste", vor "Flow 1")

**Interfaces:**
- Consumes: alle vorherigen Tasks

- [ ] **Step 1: Flow 0 in README ergaenzen**

Vor `### Flow 1: WP-Formular -> E-Mail -> OpenXE Ticket` einfuegen:

```markdown
### Flow 0: Verbindungsdaten einrichten (WordPress-Verbindungs-Tab)

- [ ] Einstellungsseite oeffnen: Tab "WordPress-Verbindung" sichtbar
- [ ] Endpoint-URL zeigt Scheme+Host der aktuellen Instanz
- [ ] "Generieren" bei leerem Secret erzeugt 64-Hex-Zeichen-Wert
- [ ] "Neu generieren" bei vorhandenem Wert fragt per confirm() nach
- [ ] Auge-Toggle blendet Klartext ein/aus
- [ ] Copy-Button kopiert Klartext (auch ueber http — Fallback-Pfad)
- [ ] Werte ins WP-Plugin eintragen -> Inbound-Test-Push liefert 200
```

- [ ] **Step 2: Lint-Gesamtlauf**

Run:
```bash
php -l www/pages/repairintegration.php
php -l classes/Modules/RepairIntegration/Service/RepairConnectionInfo.php
git diff HEAD~4 --check
```
Expected: 2x `No syntax errors detected`, kein Whitespace-Fehler

- [ ] **Step 3: Commit**

```bash
git add tests/E2E/RepairIntegration/README.md
git commit -m "test(repair): add Flow 0 connection setup to E2E checklist"
```

- [ ] **Step 4: Manuelle Verifikation auf Test-VM (nach Deployment)**

Checkliste Flow 0 auf `http://192.168.0.150` durchgehen. Erst danach gilt das Feature als verifiziert — kein "funktioniert" ohne diesen Lauf.
