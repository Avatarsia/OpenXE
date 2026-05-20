# SuperSearch — Avatarsia Fork Enhancements

Avatarsia-spezifische Erweiterungen am OpenXE SuperSearch-Modul.
SuperSearch hat keinen Plugin-/Hook-Mechanismus fuer Result-Provider,
Scoring oder Layout — die Aenderungen sind direkte Core-Patches an
den Widget-Assets, dem Modul-Backend und den zugehoerigen Cronjobs.

Gepflegt auf Branch `feature/supersearch-enhancement`, bei jedem
Upstream-Update von SuperSearch manuell rebased.

## Enthaltene Features und Hardenings

### 1. Fuzzy-Search-Fallback (Levenshtein-basiert)

- `SuperSearchEngine::runFuzzySearch()` springt ein, wenn die normale
  Volltext-/LIKE-Suche null Treffer liefert.
- Levenshtein-Distanz auf erweitertem Kandidaten-Pool.
- `ResultCollection` traegt ein `fuzzy`-Flag in der JSON-Response.
- Frontend (`supersearch.js`) blendet "Fuzzy search aktiv" als
  Meta-Info ein.

### 2. Fluid-Layout fuer Notebook bis 4K

Das Result-Overlay skaliert durchgaengig per CSS — kein
Breakpoint-Sprung zwischen 1200px und 4K.

- **Detail-Pane:** `flex: 0 1 clamp(--ssr-detail-min, --ssr-detail-pref,
  --ssr-detail-max)`, Standard `clamp(320px, 30vw, 720px)`.
- **Result-Spalten:** `grid-template-columns: repeat(auto-fit,
  minmax(--ssr-min-col-width, 1fr))` als CSS-Fallback. JS
  ueberschreibt das Inline, wenn die Apps-Spalten-Constraint
  (Apps immer rechts) aktiv ist.
- **CSS-Custom-Properties auf `#supersearch-overlay`** als einzige
  Quelle der Wahrheit fuer Layout-Konstanten:
  `--ssr-min-col-width` (280px), `--ssr-col-gap` (16px),
  `--ssr-detail-min/-pref/-max` (320px / 30vw / 720px).
  Das JS liest diese Werte via `getComputedStyle()`.
- **`measureResultWrapperWidth()`** verwendet
  `getBoundingClientRect()` und faellt auf
  `window.innerWidth - viewportSidebarOffset` zurueck, falls der
  Container beim Init noch `display: none` ist.

### 3. Apps-Gruppen fest in rechter Spalte

`classifyResultGroups()` + `layoutResultColumns()` halten alle
Gruppen mit Key `app` oder `apps` in der rechten Spalte fest. Die
restlichen Result-Gruppen werden auf das verbleibende
Spalten-Budget verteilt — bei >=3 Spalten mit semantischer Aufteilung
(Spalte 0 = `offer`/`order`, Spalte 1 = `deliverynote`/`invoice`),
sonst round-robin.

### 4. XSS-Hardening im Frontend-Rendering

Alle Renderer fuer Server-gelieferte Inhalte (Such-Items,
Group-Titles, Detail-Headlines) bauen das DOM ueber jQuery-Elemente
mit `.text()` statt String-Konkatenation mit `.html()`.

Bewusste Ausnahmen (mit Code-Kommentar):

- `generateDetailAttachmentTypeStaticContent` — Attachment-Typ
  `content_static` ist als HTML-Snippet vom Server gedacht (z.B.
  vorgerenderte Tabellen). Der Server MUSS sicherstellen, dass dort
  keine user-kontrollierten Inhalte enthalten sind.
- `generateDetailAttachmentTypeDynamicContent` — Mini-Detail-URLs
  liefern Server-Side-gerendertes HTML; analog zu `content_static`.

### 5. Race-Cancellation bei aufeinanderfolgenden Such-Requests

Beim Tippen wird ein laufender Search-XHR via `.abort()` abgebrochen,
bevor der naechste startet. Verhindert, dass eine langsame Antwort
auf einen aelteren Suchbegriff eine neuere ueberschreibt. Der
error-Handler ignoriert `textStatus === 'abort'` (kein Alert auf
abgebrochenen Folge-Anfragen).

### 6. Event-Delegation statt per-Item-Bindings

Result-Item-Click-Handler wird einmalig in `createOverlay()` ueber
Event-Delegation gebunden (`$overlay.on('click.SuperSearch',
'.result-item a', ...)`). Item-Daten wandern via
`.data('supersearchItem', item)` ans LI-Element.

### 7. Accessibility (pragmatisches Basisset)

- Overlay-Element erhaelt `role="dialog"`, `aria-modal="true"`,
  `aria-label="Globale Suche"`.
- Close-Icon: `role="button"`, `tabindex="0"`, `aria-label`,
  Keyboard-Aktivierung (Enter/Space).
- **Focus-Trap:** Tab und Shift+Tab zirkulieren innerhalb von
  Overlay + Such-Eingabefeld, solange das Overlay offen ist.
- **Focus-Return:** `hideOverlay()` schickt den Focus zurueck zum
  Such-Eingabefeld.
- **ESC schliesst von ueberall:** Document-Level-Handler unter
  `keydown.SuperSearch`-Namespace.

### 8. Cronjob-Cleanup-Catch (Exception-Maskierung)

`cronjobs/amainvoice.php`, `cronjobs/supersearch_index_diff.php`,
`cronjobs/supersearch_index_full.php`: Im Catch-Block wird
`$task->cleanup()` in `try { ... } catch (\Throwable
$cleanupError) { error_log(...) }` verpackt, um die
Original-Exception nicht zu verlieren. Die originale Exception wird
anschliessend re-throw'd.

## Beruehrte Dateien

| Datei | Aenderung |
|---|---|
| `classes/Modules/SuperSearch/SuperSearchEngine.php` | Fuzzy-Search-Fallback, Levenshtein-Pool |
| `classes/Widgets/SuperSearch/Result/ResultCollection.php` | `fuzzy`-Flag in JSON-Response |
| `classes/Widgets/SuperSearch/www/js/supersearch.js` | Fluid-Layout, XSS-Hardening, Race-Cancel, Event-Delegation, A11y, Fuzzy-Hinweis |
| `classes/Widgets/SuperSearch/www/css/supersearch.css` | CSS-Custom-Properties, `clamp()`-Detail-Pane, 1000px-Schwelle |
| `cronjobs/amainvoice.php` | cleanup() in catch abgesichert |
| `cronjobs/supersearch_index_diff.php` | cleanup() in catch abgesichert |
| `cronjobs/supersearch_index_full.php` | cleanup() in catch abgesichert |

## Architektur-Skizze

```
Browser
  +-- supersearch.js (jQuery)
  |     +-- fetchSearchResults()  -> /index.php?module=supersearch&action=ajax&cmd=search
  |     +-- fetchItemDetailsDynamicContent() -> /index.php?module=supersearch&action=ajax&cmd=detail
  |     +-- fetchMiniDetailContent()         -> beliebige index.php?... (HTML)
  +-- supersearch.css (Layout via CSS-Custom-Properties)

Server (www/pages/supersearch.php :: HandleSuperSearchAjaxSearch)
  -> SuperSearchEngine::search()  -> ResultCollection (JsonSerializable)
     +-- runFuzzySearch() (Avatarsia)
     +-- ResultGroup[] -> ResultItem[]
  -> JsonResponse {success, data: { count, results, fuzzy,
                                    last_index_update_formatted, ... }}

Cronjobs
  -> SuperSearchFullIndexTask::execute() (taeglich)
  -> SuperSearchDiffIndexTask::execute() (stuendlich)
  -> AmaInvoiceTask::execute()           (modul-abhaengig)
```

## Maintenance-Hinweise

- **Layout-Konstanten:** beim Aendern der Spalten-Mindestbreite oder
  Detail-Pane-Grenzen IMMER in `supersearch.css` an
  `#supersearch-overlay` aenderns (`--ssr-*`). Das JS liest die Werte
  von dort. Nicht parallel im JS-`config` patchen — `config.default*`
  sind nur Fallbacks, falls die Custom-Property fehlt.
- **1000px-Schwelle:** in `supersearch.css` blendet eine Media-Query
  das Overlay aus. Das ist kein Cosmetic-Fix, sondern matched die
  Theme-Regel in `www/themes/new/css/styles.css`, die ab 1000px das
  Such-Eingabefeld selbst ausblendet (`#header .search-wrapper {
  display: none }`). Bei Theme-Aenderungen beide Stellen anpassen.
- **Server-Response-Format:** die `HandleSuperSearchAjaxSearch`-Action
  liefert ein Object, kein Array (`{success: true, data:
  ResultCollection|null}`). Frontend-Defensive im
  `renderSearchResults()`-Pfad pruefen, falls die Response-Form sich
  upstream aendert.
- **Attachment-Pfade mit `.html()`:** ausschliesslich
  `content_static` und `content_dynamic` rendern HTML-Snippets vom
  Server. Wenn ein neuer Attachment-Typ hinzukommt, der Item-Daten
  vom Index rendert, immer `.text()` oder DOM-Building verwenden.

## Konflikt-Risiko bei Upstream-Updates

**Mittel.** `SuperSearchEngine.php` und die Widget-Assets werden
upstream aktiv weiterentwickelt. Strategie:

1. `git fetch origin`
2. `git rebase origin/master` auf `feature/supersearch-enhancement`
3. Konflikte manuell aufloesen. Fuzzy-Pfad und Frontend-Refactorings
   sind funktional unabhaengig — beide Seiten in der Regel behalten.
4. Test-Schritte unten ausfuehren.

## Test-Hinweise

```
# PHP-Syntax (alle Cronjobs)
php -l cronjobs/amainvoice.php
php -l cronjobs/supersearch_index_diff.php
php -l cronjobs/supersearch_index_full.php

# JS-Syntax
node --check classes/Widgets/SuperSearch/www/js/supersearch.js
```

Manuelle Browser-Checks:

- Such-Begriff (>=3 Zeichen) tippen — Overlay erscheint, Spalten
  fuellen sich, Fuzzy-Hinweis bei Tippfehler.
- Schnell hintereinander tippen — keine veralteten Ergebnisse
  ueberschreiben neuere (Race-Cancel).
- Suchergebnis mit `<script>alert(1)</script>` im Titel/Subtitle —
  darf nicht ausgefuehrt werden (XSS-Hardening).
- Tab/Shift+Tab im offenen Overlay — Focus bleibt im Overlay-Kontext
  + Such-Eingabefeld (Focus-Trap).
- ESC — schliesst Overlay, Focus springt zurueck auf Such-Eingabefeld.
- Browser-Resize zwischen Notebook (1280x800) und 4K (3840x2160) —
  Detail-Pane skaliert fluent, kein Sprung.
- 13"-Notebook unter 1000px (mobile-Ansicht) — Overlay versteckt.

## Rollback

```
git revert <commit>..<commit>
```

oder Branch verwerfen und auf `origin/master` zuruecksetzen.
