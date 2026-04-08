# SuperSearch — Avatarsia Fork Enhancements

Dieses Dokument beschreibt die Avatarsia-spezifischen Erweiterungen am
OpenXE SuperSearch-Modul. Im Gegensatz zu den isolierten Modulen
`LexwareOffice` und `NetworkPrinter` sind diese Aenderungen **direkte
Core-Patches** auf Dateien des SuperSearch-Moduls und der Widget-Assets,
weil SuperSearch keinen Plugin-/Hook-Mechanismus besitzt, ueber den man
Result-Provider, Scoring oder Layout extern registrieren koennte.

Die Patches werden im Branch `feature/supersearch-enhancement` gepflegt
und bei jedem Upstream-Update von SuperSearch manuell rebased.

## Enthaltene Features

### 1. Fuzzy-Search-Fallback (Levenshtein-basiert)

Wenn die normale Volltext-/LIKE-Suche null Treffer liefert, faellt
`SuperSearchEngine` automatisch auf eine tippfehler-tolerante Suche
zurueck:

- Neuer Code-Pfad `runFuzzySearch()` in `SuperSearchEngine`.
- Levenshtein-Distanz auf einem erweiterten Kandidaten-Pool.
- `ResultCollection` traegt ein zusaetzliches `fuzzy`-Flag, damit das
  Frontend den Modus markieren kann ("Fuzzy search aktiv").
- Frontend (`supersearch.js`) liest das Flag und blendet einen
  Hinweis ein.

### 2. Viewport-adaptives 3-Spalten-Layout

Das alte feste 250x440-Popup wird durch ein Overlay ersetzt, das sich
flexibel an Breite und Hoehe des Viewports koppelt:

- `supersearch.css`: neues Grid mit drei gleich breiten Spalten,
  Auto-Hoehe statt fixer Pixelwerte, Apps-Spalte fest rechts.
- `supersearch.js`: neue Methode `buildResultColumns()` verteilt
  Result-Gruppen auf die drei Spalten und haelt App-/Apps-Gruppen
  fest in der rechten Spalte.

## Beruehrte Dateien

| Datei | Aenderung |
|---|---|
| `classes/Modules/SuperSearch/SuperSearchEngine.php` | Fuzzy-Search-Fallback, Levenshtein-Pool |
| `classes/Widgets/SuperSearch/Result/ResultCollection.php` | `fuzzy`-Flag |
| `classes/Widgets/SuperSearch/www/js/supersearch.js` | Spalten-Aufbau, Fuzzy-Hinweis |
| `classes/Widgets/SuperSearch/www/css/supersearch.css` | Viewport-Layout |

## Warum kein Bootstrap.php-Patch hier?

SuperSearch hat zwar eine `Bootstrap.php`, aber keine oeffentliche
Erweiterungs-API: keine Result-Provider-Registry, keine Scoring-Hooks,
kein Layout-Slot-System. Die hier enthaltenen Aenderungen muessen
direkt in den genannten Core-Dateien sitzen und koennen daher nicht
als sauber registriertes Modul gekapselt werden.

## Konflikt-Risiko bei Upstream-Updates

**Mittel.** `SuperSearchEngine.php` und die Widget-Assets werden
upstream aktiv weiterentwickelt. Bei jedem Pull von `origin/master`
ist mit Konflikten in diesen vier Dateien zu rechnen. Strategie:

1. `git fetch origin`
2. `git rebase origin/master` auf `feature/supersearch-enhancement`
3. Konflikte manuell aufloesen (Fuzzy-Pfad und Spalten-Layout sind
   funktional unabhaengig — beide Seiten in der Regel behalten).
4. `php -l` auf die beiden PHP-Dateien.
5. `node --check` auf `supersearch.js`.

## Rollback

```
git revert <commit>..<commit>
```

oder den gesamten Branch verwerfen und auf `origin/master`
zuruecksetzen — die Patches sind in sich geschlossene Cherry-Picks
aus den isolierten Quell-Branches `feature/fuzzy-search-clean` und
`feature/searchbar-layout-clean`.
