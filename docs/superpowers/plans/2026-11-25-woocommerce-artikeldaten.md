# WooCommerce-Artikeldaten und Shopzuordnung Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** OpenXE wird fuehrend und uebersichtlich fuer alle WooCommerce-Artikeldaten: WooCommerce-Tab im Artikel, Rueckholung aus WooCommerce, CSV-Vorlagen, erweiterte Exportzuordnung, Shop-Kategorienbaum mit Zuordnung und Lieferstatus in der Artikelliste.

**Architecture:** Ein zentraler Feldkatalog (`ArticleFieldCatalog`) beschreibt jedes Feld einmal (Speicherstelle, WooCommerce-Ziel, Rueckholbarkeit, CSV-Spalte). Tab, Rueckholung, CSV-Vorlagen und Export lesen ihn. Zustand des Connectors liegt wie bisher in `konfiguration` (JSON, kein Schema-Eingriff). Kerneingriffe nur in `artikel.php`, `artikel.tpl`, `importvorlage.php`, `exportvorlage.php` (nur falls noetig) und `shopimporter_woocommerce.php`; alles andere in `classes/Components/WooCommerce/`.

**Tech Stack:** PHP 8.1+ (lokal 8.5.1), OpenXE 1.13, `automattic/woocommerce` 3.1.1 via `ClientWrapper`, jQuery-UI-Tabs + CKEditor der Artikelmaske, Testlaeufer `tests/woocommerce/run.php` (eigene `check()/same()/runTests()`, Doubles statt DB/HTTP).

**Spec:** `docs/superpowers/specs/2026-11-25-woocommerce-artikeldaten-design.md` (Teil 1), `docs/superpowers/specs/2026-11-25-woocommerce-shopzuordnung-design.md` (Teil 2). Beide lesen.

## Global Constraints

- Leere OpenXE-Felder: **leer = auch in WordPress leer**; leere Lieferzeit -> `_lieferzeit = -1`.
- Alles Neue pro Shop zuschaltbar, Standard **aus**: `extendedFieldMapping`, `shopCategoryTree`, `visibilitySync`.
- Shop-SKU (Fremdnummer) nie bearbeitbar.
- **Ein Wert, ueberall derselbe (Nutzervorgabe):** Tab, Rueckholung und CSV schreiben in die vorhandenen OpenXE-Felder, keine Kopien. Gibt es fuer ein Feld zusaetzlich eine aktive Shop-Ueberschreibung (`artikel_onlineshops` mit `aktiv = 1 AND ausartikel = 0`, gelesen von `GetArtikelShopEinstellung`), werden Artikelwert UND Ueberschreibung mit demselben Wert geschrieben, damit Tab, "Online-Shop Optionen" und Shop-Zuordnung identisch anzeigen und der Export denselben Wert liest. Preis: die Zeile, die der Export liest (Gruppenpreis wenn `shopexport.preisgruppe`).
- Yoast-Metadaten bleiben im Code, werden aber nur mit der Shop-Einstellung `yoastMeta` (Standard **aus**) gesendet.
- Rueckholung schreibt nie ohne Vorschau und Bestaetigung; jede Uebernahme wird mit Alt-/Neuwert protokolliert.
- Produkt aus dem Shop nehmen = `status = private`, nie loeschen. Kategorie-Slug nach Anlage unveraenderlich. Kategorie loeschen nur wenn leer.
- Beide Kaestchen gesetzt: "nicht mehr lieferbar" gewinnt.
- Meta-Schnittstelle zum WordPress-Projekt: `openxe_on_request` = `1`/`0`.
- Kein Schema-Eingriff in Kerntabellen; Connector-Zustand in `konfiguration` (`wert` ist TEXT, max. 65000 Byte pro Schluessel pruefen).
- CSRF wie `AdminPage`: `$session->isCsrfTokenValid('woocommerce_admin', $token)`, Token-Key-Format `/^[a-z][a-z0-9_]*$/`, nach `createCsrfToken` `SessionHandler::commitSession()`.
- `Secure->GetPOST($name, '', '', 1)` (sqlcheckoff) fuer Werte, die in Prepared Statements gehen.
- INSERT in Kerntabellen (`artikel_onlineshops`): alle NOT-NULL-ohne-DEFAULT-Spalten belegen (`pseudolager`, `lieferzeitmanuell`, `last_article_hash` = `''`).
- Logging ueber `$this->app->Container->get('Logger')`, nie `$app->erp->LogFile()`; `catch (\Throwable)`.
- Keine echten Umlaute im Code (ae/oe/ue), Ausnahme: Anzeigetexte in Templates/Views duerfen HTML-Entities nutzen. `php -l` auf jede geaenderte PHP-Datei. Kein `sed` auf Code-Dateien; Dateien mit gemischten Zeilenenden (`artikel.php`, `artikel.tpl`, `importvorlage.php` vorher mit `git ls-files --eol` pruefen) byte-genau per Python editieren.
- Commits: `git commit -F <datei>`, Abschlusszeile `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **HTML und Entities in Beschreibungen:** `class.remote.php` liefert `uebersicht_de` per `htmlspecialchars` kodiert, WooCommerce liefert HTML. Rueckholung und Vergleich duerfen nicht doppelt kodieren und keinen Schein-Unterschied melden. -> Test in Task 4 (`description_roundtrip_is_single_encoded`).
2. **Varianten:** Eine SKU trifft eine WooCommerce-Variation; die Rueckholung schreibt in den Varianten-Artikel, nicht in den Hauptartikel. -> Test in Task 4 (`variation_sku_maps_to_variant_article`).
3. **Zahlenformate:** `12,50` / `12.5` / `12.50`, Gewicht `''` vs `'0'` duerfen keinen Unterschied ergeben. -> Test in Task 4 (`numeric_values_normalize`).
4. **CSV mit Umlauten und Rank-Math-Platzhaltern** (`%title% | Partner 3D`, `Duese`, `ae`): Export und Import ohne Zeichensatzverlust (Importvorlage hat `utf8decode = 1` als Default). -> Test in Task 7 (`templates_use_utf8_without_decode`).
5. **Fehlende/doppelte SKU:** Produkt ohne SKU oder zwei WooCommerce-Produkte mit gleicher SKU -> erscheint in "nicht zuordenbar", kein Abbruch. Abmelden aus dem Shop bei nicht erreichbarem WooCommerce bleibt vorgemerkt. -> Tests in Task 6 (`unmatched_products_listed`) und Task 11 (`failed_hide_stays_queued`).

---

## File Structure

| Datei | Verantwortung |
|---|---|
| `classes/Components/WooCommerce/ArticleFieldCatalog.php` (neu) | Feldkatalog, CSV-Spaltenliste |
| `classes/Components/WooCommerce/DeliveryTimeMap.php` (neu) | Lieferzeit-Text <-> German-Market-Term-ID |
| `classes/Components/WooCommerce/ProductSynchronizer.php` | erweiterte Exportzuordnung, Lieferstatus |
| `www/pages/shopimporter_woocommerce.php` | Shop-Einstellungen, Meta zusammenfuehren, Optionen durchreichen, Kategorien aus Baum, Sichtbarkeits-Queue abarbeiten, `clientForShop()` |
| `classes/Components/WooCommerce/ArticleDataRepository.php` (neu) | OpenXE-Werte je Katalogfeld lesen/schreiben (effektive Stelle) + Protokoll |
| `classes/Components/WooCommerce/WooProductReader.php` (neu) | WooCommerce-Produkte/Variationen lesen, in Katalogwerte umsetzen |
| `classes/Components/WooCommerce/FieldDiff.php` (neu) | normalisierter Vergleich |
| `classes/Components/WooCommerce/ArticleTab.php` (neu) + `classes/Modules/Onlineshop/www/js/woocommerce-article-tab.js` (neu) | Tab im Artikel, Spiegelung, Vergleich |
| `classes/Components/WooCommerce/BulkPull.php` (neu), `AdminPage.php`, `AdminView.php` | Massenrueckholung + Kategorien-Erstuebernahme in der Shop-Verwaltung |
| `classes/Components/WooCommerce/TemplateInstaller.php` (neu) | Export-/Importvorlage anlegen |
| `www/pages/importvorlage.php` | Whitelist + "nur aktualisieren" |
| `classes/Components/WooCommerce/ShopCategoryStore.php` (neu) | Baum, Artikel-Kategorien, Kaestchen "auf Nachfrage" |
| `classes/Components/WooCommerce/ShopCategoryExporter.php`, `CategoryImporter.php` (neu) | Baum -> WooCommerce, WooCommerce -> Baum |
| `classes/Components/WooCommerce/VisibilityQueue.php` (neu) | vorgemerktes Ausblenden |
| `classes/Components/WooCommerce/AssignmentPage.php` (neu) + `classes/Modules/Onlineshop/www/js/woocommerce-assignment.js` (neu) | Seite "WooCommerce" in der Artikelliste |
| `www/pages/artikel.php`, `www/widgets/templates/_gen/artikel.tpl` | Tab-Einhaengung, Speichern-Erweiterung, Aktion `woocommerce`, Menueeintrag |
| `tests/woocommerce/*.php` | je Task eine Suite, in `run.php` eintragen |

---

### Task 1: Feldkatalog

**Files:**
- Create: `classes/Components/WooCommerce/ArticleFieldCatalog.php`
- Test: `tests/woocommerce/catalog.php` (in `run.php` eintragen)

**Interfaces:**
- Produces: `ArticleFieldCatalog::fields(): array` - Liste in Katalogreihenfolge, je Eintrag `['key'=>string,'label'=>string,'group'=>string,'storage'=>string,'wc'=>string,'pull'=>bool,'edit'=>bool,'csv'=>?string]`. `ArticleFieldCatalog::field(string $key): array` (wirft `InvalidArgumentException`). `ArticleFieldCatalog::csvColumns(): array` - Liste `['export'=>string,'import'=>string]`.
- `storage`-Werte: `artikel:<spalte>`, `shop:<spalte>` (Artikelspalte mit Shop-Ueberschreibung), `price`, `foreign_number`, `onrequest`.

- [ ] **Step 1: Test schreiben** `catalog_keys_and_storage`: `array_column(fields(),'key')` === `['sku','shop_name','description','short_description','seo_title','seo_description','seo_keyword','price_net','pseudo_price','inactive','stock_sync','pseudo_stock','rest_quantity','sold_out','on_request','delivery_time','weight','length','width','height','ean','customs_tariff','origin_country','manufacturer']`; `field('shop_name')['storage'] === 'artikel:shop_name_de'`, `field('description')['storage'] === 'artikel:uebersicht_de'`, `field('short_description')['storage'] === 'artikel:kurztext_de'`, `field('seo_title')['wc'] === 'meta:rank_math_title'`, `field('seo_description')['wc'] === 'meta:rank_math_description'`, `field('seo_keyword')['wc'] === 'meta:rank_math_focus_keyword'`, `field('pseudo_price')['storage'] === 'shop:pseudopreis'`, `field('delivery_time')['storage'] === 'shop:lieferzeitmanuell'`, `field('delivery_time')['wc'] === 'meta:_lieferzeit'`, `field('sold_out')['storage'] === 'artikel:ausverkauft'`, `field('customs_tariff')['wc'] === 'meta:_dhl_hs_code'`, `field('origin_country')['wc'] === 'meta:_dhl_manufacture_country'`, `field('manufacturer')['wc'] === 'meta:_german_market_gpsr_manufacturer'`, `field('sku')['edit'] === false && field('sku')['pull'] === false`; pull=false fuer `stock_sync`, `pseudo_stock`, `rest_quantity`, `sold_out`, `on_request`.
- [ ] **Step 2: Test `csv_columns_start_with_number`**: `csvColumns()[0] === ['export'=>'nummer','import'=>'nummer']`; kein Eintrag fuer `sku`, `on_request`; `price_net` erzeugt zwei Spalten mit `import` `verkaufspreis1netto` und `verkaufspreis1menge`.
- [ ] **Step 3: Laufen lassen** `php tests/woocommerce/catalog.php` -> FAIL (Klasse fehlt).
- [ ] **Step 4: Implementieren.** Labels exakt aus dem Feldkatalog der Spec (Spalte "Bezeichnung im Tab"). Preis-Exportausdruck: Subselect wie `class.remote.php` Zeile ~1642 (`ab_menge = 1`, `art != 'Gruppe'`, Standard/leer, `adresse` 0, gueltig, `ORDER BY preis DESC LIMIT 1`) mit Alias `standardpreisnetto`, zweite Spalte `1 AS standardpreismenge`. Keine Semikola in den Ausdruecken (Vorlagenfelder werden an `;` getrennt).
- [ ] **Step 5: PASS**, `php -l`, Commit `feat(woocommerce): article field catalog`.

### Task 2: Lieferzeit-Zuordnung

**Files:**
- Create: `classes/Components/WooCommerce/DeliveryTimeMap.php`
- Modify: `www/pages/shopimporter_woocommerce.php` (Einstellung `deliveryTimeMapping`, Factory)
- Test: `tests/woocommerce/delivery_time.php`

**Interfaces:**
- Produces: `new DeliveryTimeMap(callable $fetchTerms, string $fallbackSetting)`; `idFor(string $text): ?int` (`''` -> `-1`, unbekannt -> `null`); `textFor(int $id): string` (`-1` -> `''`, unbekannt -> `''`). `$fetchTerms(): array<int,string>` (ID => Name), Fehler -> Fallback. `Shopimporter_Woocommerce::deliveryTimeMap(): DeliveryTimeMap` (fetch ueber `wordpress_request('product_delivery_times?per_page=100')` bzw. den `wp/v2`-Pfad, den `wordpress_request` verwendet; einmal pro Instanz cachen).
- `deliveryTimeMapping`: Textarea, Zeilen `Text=ID`.

- [ ] **Step 1: Tests** `maps_known_terms` (`[61=>'sofort lieferbar',65=>'ca. 3-4 Werktage']`: `idFor('ca. 3-4 Werktage') === 65`, `textFor(61) === 'sofort lieferbar'`), `empty_is_shop_default` (`idFor('') === -1`, `textFor(-1) === ''`), `unknown_text_is_null`, `fallback_when_fetch_fails` (fetch wirft; Setting `"sofort lieferbar=61\nca. 48 Stunden=63"` -> `idFor('ca. 48 Stunden') === 63`), `matching_ignores_case_and_surrounding_space`.
- [ ] **Step 2: FAIL**, **Step 3: implementieren**, Einstellung in `EinstellungenStruktur()` nach `backorders` (`typ` textarea, Bezeichnung `{|Lieferzeit-Zuordnung (Text=ID, nur falls WordPress-API nicht erreichbar)|}`), **Step 4: PASS**, `php -l`, Commit `feat(woocommerce): delivery time term mapping`.

### Task 3: Erweiterte Exportzuordnung und Lieferstatus

**Files:**
- Modify: `classes/Components/WooCommerce/ProductSynchronizer.php` (`map()`, `variationPayload()`)
- Modify: `www/pages/shopimporter_woocommerce.php` (`ImportSendList` ~Zeile 1094-1110, `getKonfig`, `EinstellungenStruktur`)
- Test: `tests/woocommerce/products.php` (erweitern), `tests/woocommerce/product_integration.php` (erweitern)

**Interfaces:**
- Consumes: `DeliveryTimeMap::idFor()`.
- Produces: `map()`-Optionen `yoastMeta` (bool, Default false), `extendedMapping` (bool, Default false), `deliveryTimeIds` (callable `string -> ?int`), `onRequest` (bool). Shop-Einstellungen `extendedFieldMapping`, `visibilitySync`, `shopCategoryTree` (Checkboxen, Default aus) als Properties `$this->extendedFieldMapping` usw.

- [ ] **Step 1: Tests in `products.php`:**
  - `extended_mapping_off_keeps_legacy_payload`: ohne Option kein `rank_math_*`, kein `_lieferzeit`; ohne `yoastMeta` **keine** `_yoast_*`-Schluessel (auch nicht bei Varianten).
  - `yoast_meta_only_when_enabled`: Option `yoastMeta=true` -> `_yoast_wpseo_title`/`_yoast_wpseo_metadesc` wie bisher aus `metatitle_de`/`metadescription_de`, bei Hauptprodukt und Variante.
  - `extended_mapping_sends_rank_math_and_logistics`: Artikel mit `metatitle_de='T'`, `metadescription_de='D'`, `metakeywords_de='K'`, `zolltarifnummer='85149000'`, `herkunftsland='DE'`, `hersteller='Artillery'`, `ean='4260'`, `lieferzeitmanuell='ca. 3-4 Werktage'` -> `meta_data` enthaelt exakt `rank_math_title=T`, `rank_math_description=D`, `rank_math_focus_keyword=K`, `_dhl_hs_code=85149000`, `_dhl_manufacture_country=DE`, `_german_market_gpsr_manufacturer=Artillery`, `_gm_gtin=4260`, `_rank_math_gtin_code=4260`, `_lieferzeit=65`, und `global_unique_id === '4260'`.
  - `empty_values_clear_wordpress`: leere Felder -> Meta mit Wert `''`, `_lieferzeit = -1`.
  - `unknown_delivery_time_not_sent`: `deliveryTimeIds` liefert `null` -> kein `_lieferzeit`-Eintrag.
  - `on_request_sets_backorder_state`: `onRequest=true`, Bestand 0 -> `backorders==='notify'`, `stock_status==='onbackorder'`, Meta `openxe_on_request='1'`; ohne -> Meta `'0'`.
  - `sold_out_wins_over_on_request`: `ausverkauft='1'` + `onRequest=true` -> `stock_status==='outofstock'`, `backorders==='no'`, `openxe_on_request='0'`, `_lieferzeit` = ID von "derzeit nicht lieferbar".
- [ ] **Step 2: Test in `product_integration.php`** `metadata_is_merged_not_replaced`: Smarty-Metadaten + Attachment-Meta + erweiterte Meta landen alle im PUT (heute ueberschreibt Zeile 1110).
- [ ] **Step 3: FAIL.**
- [ ] **Step 4: Implementieren.** Yoast-Block in `map()` hinter Option `yoastMeta` (Default false) legen, nicht loeschen; Shop-Checkbox `yoastMeta` (`{|Yoast-Metadaten zusaetzlich senden|}`, Standard aus); erweiterte Meta nur bei `extendedMapping`. In `ImportSendList`: `$commonProductAtts['meta_data'] = array_merge($commonProductAtts['meta_data'] ?? [], $commonMetaData)` (Schluessel eindeutig, letzter gewinnt); Optionen `extendedMapping`, `deliveryTimeIds`, `onRequest` (vorerst `false`; Task 9 verdrahtet `ShopCategoryStore::isOnRequest()` aus Task 8) durchreichen. Die drei Checkboxen in `EinstellungenStruktur()`; Bezeichnungen: `{|Erweiterte Feldzuordnung exportieren (SEO, Lieferzeit, Hersteller, Zoll, GTIN)|}`, `{|Shop-Kategorienbaum statt Artikelbaum verwenden|}`, `{|Produkte bei entfernter Shop-Zuordnung ausblenden|}`; `info` jeweils `Erst nach der Rueckholung aus WooCommerce einschalten.`
- [ ] **Step 5: Gesamtsuite** `php tests/woocommerce/run.php` -> `Suites failed: 0`. `php -l`. Commit `feat(woocommerce): extended field mapping and delivery state export`.

### Task 4: OpenXE-Werte lesen/schreiben, WooCommerce lesen, Vergleich

**Files:**
- Create: `classes/Components/WooCommerce/ArticleDataRepository.php`, `WooProductReader.php`, `FieldDiff.php`
- Modify: `www/pages/shopimporter_woocommerce.php` (`public static function clientForShop($app, int $shopId): Shopimporter_Woocommerce` - instanziiert, ruft `getKonfig($shopId, [])`)
- Test: `tests/woocommerce/article_data.php`

**Interfaces:**
- Consumes: `ArticleFieldCatalog`, `DeliveryTimeMap`.
- Produces:
  - `new ArticleDataRepository($app, int $shopId)`; `load(int $articleId): array` -> `[key => ['value'=>string,'source'=>'artikel'|'shop'|'price'|'group_price'|'foreign_number'|'onrequest']]`; `apply(int $articleId, array $values, string $origin): array` -> `[key => ['old'=>string,'new'=>string]]` nur fuer echte Aenderungen, schreibt das vorhandene OpenXE-Feld und bei `shop:*`-Feldern mit aktiver Ueberschreibung zusaetzlich die Ueberschreibung mit demselben Wert (Global Constraint "Ein Wert, ueberall derselbe"), protokolliert in `konfiguration` `woocommerce_pull_log_<shop>` (Ringpuffer 500 Eintraege: Zeit UTC, Benutzer, Artikel, Feld, alt, neu, `$origin`). Ignoriert Felder mit `edit === false`.
  - `new WooProductReader($client, DeliveryTimeMap $map)`; `findBySku(string $sku): ?array` -> `['id'=>int,'parent_id'=>int,'values'=>[key=>string]]`; `page(int $page): array` -> `['items'=>[sku=>['id','parent_id','values']], 'unmatched'=>[['id','name','reason']], 'more'=>bool]` (100 Produkte je Seite, Variationen variabler Produkte mitlesen).
  - `FieldDiff::compare(array $openxe, array $woo): array` -> Liste `['key','label','openxe','woocommerce','differs'=>bool]` nur fuer `pull === true`.
  - Rueckabbildung Preis: `sale_price` gesetzt -> `price_net = sale_price`, `pseudo_price = regular_price`; sonst `price_net = regular_price`, `pseudo_price = ''`.

- [ ] **Step 1: Tests** (DB- und Client-Doubles nach Muster `download_settings.php`):
  - `load_prefers_shop_override` (Artikel `pseudopreis=10`, `artikel_onlineshops` aktiv/ausartikel 0 `pseudopreis=12` -> value `'12'`, source `'shop'`), `load_uses_group_price_when_shop_has_group`.
  - `apply_writes_article_and_shop_override_identically` (Artikel `pseudopreis=10`, Ueberschreibung `12`, apply `15` -> beide `15`, danach `load()` value `'15'`), `apply_logs_changes`, `apply_ignores_sku_and_reports_only_changes`.
  - `description_roundtrip_is_single_encoded`: Woo `<p>Ger&auml;t &amp; Teil</p>` vs OpenXE-Wert aus DB `&lt;p&gt;Ger&amp;auml;t &amp;amp; Teil&lt;/p&gt;` (so wie gespeichert) -> `differs === false`; `apply` speichert so, dass ein erneutes `load` + `compare` `differs === false` ergibt.
  - `numeric_values_normalize`: `'12,50'` vs `'12.5'` gleich; Gewicht `''` vs `'0'` gleich.
  - `variation_sku_maps_to_variant_article`: `page()` liefert Variation mit `parent_id > 0` unter ihrer SKU.
  - `sale_price_maps_to_pseudo_price`, `delivery_term_maps_to_text`.
- [ ] **Step 2: FAIL**, **Step 3: implementieren**, **Step 4: PASS**, Gesamtsuite gruen, `php -l`, Commit `feat(woocommerce): article data repository, product reader and diff`.

### Task 5: WooCommerce-Tab im Artikel

**Files:**
- Create: `classes/Components/WooCommerce/ArticleTab.php`, `classes/Modules/Onlineshop/www/js/woocommerce-article-tab.js`
- Modify: `www/widgets/templates/_gen/artikel.tpl` (Tab-Eintrag nach `#tabs-4`, Container `<div id="tabs-6">[WOOCOMMERCETAB]</div>` vor `<div id="tabs-5">`), `www/pages/artikel.php` (`ArtikelEdit`: vor `parent::ArtikelEdit()` Platzhalter setzen; nach `parent::ArtikelEdit()` bei `speichern` Extras speichern; `cmd=woocommerce_compare` als JSON-Antwort), CSS in `classes/Modules/Onlineshop/www/css/woocommerce-admin.css`
- Test: `tests/woocommerce/article_tab.php`

**Interfaces:**
- Consumes: `ArticleDataRepository`, `WooProductReader`, `FieldDiff`, `Shopimporter_Woocommerce::clientForShop()`.
- Produces: `new ArticleTab($app)`; `shopsFor(int $articleId): array` (aktive Zuordnungen zu Shops mit `modulename = 'shopimporter_woocommerce'` und `shopexport.aktiv = 1`); `render(int $articleId, int $shopId, string $token): string`; `compare(int $articleId, int $shopId): array` (`['rows'=>FieldDiff-Liste,'productId'=>?int,'error'=>?string]`); `saveExtras(int $articleId, int $shopId, array $post): array` (laeuft nach dem normalen Speichern; schreibt `price_net` und gleicht fuer `shop:*`-Felder mit aktiver Ueberschreibung die Ueberschreibung auf den gerade gespeicherten Artikelwert an, ueber `ArticleDataRepository::apply()`; Rueckgabe wie `apply()`).
- Platzhalter: `WOOCOMMERCETABOPEN`/`WOOCOMMERCETABCLOSE` (bei keinem Shop `<!--`/`-->`), `WOOCOMMERCETAB`.
- Tab-Felder heissen `woo[<key>]`, tragen `data-mirror="<originalname>"` wenn ein Originalfeld existiert (`shop_name_de`, `uebersicht_de`, `kurztext_de`, `metatitle_de`, `metadescription_de`, `metakeywords_de`, `inaktiv`, `ausverkauft`, `gewicht`, `laenge`, `breite`, `hoehe`, `ean`, `zolltarifnummer`, `herkunftsland`, `hersteller`, `autolagerlampe`, `pseudolager`, `restmenge`, `lieferzeitmanuell`, `pseudopreis`). Bei aktiver Shop-Ueberschreibung zeigt der Tab den Hinweis "wird auch in der Shop-Zuordnung gesetzt".

- [ ] **Step 1: Tests:** `tab_hidden_without_woocommerce_shop` (`shopsFor` leer -> Platzhalter kommentieren aus), `tab_renders_all_catalog_fields_with_target` (jede `label` und jedes `wc`-Ziel aus dem Katalog im HTML, `sku` als `readonly`), `mirror_attributes_only_for_form_fields`, `compare_returns_rows_and_handles_missing_product` (`findBySku` null -> `error === 'Produkt mit SKU ... nicht in WooCommerce gefunden'`), `save_extras_rejects_invalid_token`, `save_extras_syncs_shop_override_to_article_value` (nach Speichern `pseudopreis=15` am Artikel, Ueberschreibung vorher `12` -> danach `15`), `save_extras_writes_price`.
- [ ] **Step 2: FAIL**, **Step 3: PHP implementieren.**
- [ ] **Step 4: JS implementieren** (`woocommerce-article-tab.js`): bidirektionale Spiegelung per `input`/`change` zwischen `[data-mirror]` und `[name=<original>]`; fuer CKEditor-Felder `CKEDITOR.instances[<id>]` `change`-Event und `setData()`; Knopf "Mit WooCommerce vergleichen" -> `GET index.php?module=artikel&action=edit&id=<id>&cmd=woocommerce_compare&shop=<shop>`; Tabelle mit Spalte "WooCommerce aktuell", Checkbox je `differs`; "Ausgewaehlte Werte uebernehmen" setzt nur die Tab-Felder (und damit gespiegelt die Originale), speichert nicht. Hinweis neben dem Knopf: "Uebernommene Werte werden erst mit Speichern gesichert."
- [ ] **Step 5: Einhaengen** in `artikel.tpl`/`artikel.php` (Zeilenenden vorher pruefen, ggf. Python-Byte-Edit), JS/CSS ueber die bestehende Asset-Liste in `onlineshops.php` Zeile ~34-41 bzw. analog in `artikel.php` laden.
- [ ] **Step 6: PASS**, Gesamtsuite, `php -l www/pages/artikel.php`, UI-Pruefung mit `tests/woocommerce/fixtures/admin-router.php`-Muster (synthetische Fixture, im T3-Browser bei 1280px und 390px: Tab sichtbar, Spiegelung Shop-Name -> Tab "Texte" und zurueck, CKEditor-Spiegelung). Commit `feat(woocommerce): article tab with mirrored fields and compare`.

### Task 6: Massenrueckholung in der Shop-Verwaltung

**Files:**
- Create: `classes/Components/WooCommerce/BulkPull.php`
- Modify: `classes/Components/WooCommerce/AdminPage.php` (View `pull`), `AdminView.php` (`pull()`, Navigationslink "Artikeldaten abgleichen")
- Test: `tests/woocommerce/bulk_pull.php`, `tests/woocommerce/admin_controller.php` (erweitern)

**Interfaces:**
- Consumes: `WooProductReader::page()`, `ArticleDataRepository`, `FieldDiff`.
- Produces: `new BulkPull(ArticleDataRepository $repo, WooProductReader $reader, callable $articleIdForSku)`; `compare(int $page): array` -> `['articles'=>[articleId=>['nummer','name','rows'=>FieldDiff-Zeilen mit differs]], 'unmatched'=>[...], 'more'=>bool]`; `apply(array $selection, string $origin): array` -> `[articleId => apply()-Ergebnis]`. `$selection = [articleId => [key, ...]]`. SKU-Zuordnung wie Export: Fremdnummer des Shops, sonst `artikel.nummer`.
- Formular: Filter nach Feldgruppe (Checkboxen je `group`), Auswahl pro Artikel/Feld, Knopf "Ausgewaehlte Werte uebernehmen" (POST, CSRF, `wc_action=apply_pull`). Seitenweise (eine WooCommerce-Seite pro Request).

- [ ] **Step 1: Tests:** `compare_lists_only_differences`, `unmatched_products_listed` (Produkt ohne SKU, SKU ohne Artikel, doppelte SKU -> je ein Eintrag mit `reason` `'keine SKU'`/`'kein OpenXE-Artikel'`/`'SKU mehrfach'`), `apply_respects_group_filter`, `apply_requires_token` (admin_controller).
- [ ] **Step 2: FAIL**, **Step 3: implementieren**, **Step 4: PASS**, Gesamtsuite, `php -l`, UI-Fixture-Pruefung, Commit `feat(woocommerce): bulk pull with preview`.

### Task 7: CSV-Vorlagen und Importvorlage "nur aktualisieren"

**Files:**
- Create: `classes/Components/WooCommerce/TemplateInstaller.php`
- Modify: `www/pages/importvorlage.php` (Whitelist-`case`-Liste ab ~1827; Anlage-Zweig ~1735), Aufruf von `TemplateInstaller::ensure()` in `AdminPage::renderAuthorized()`
- Test: `tests/woocommerce/templates.php`

**Interfaces:**
- Consumes: `ArticleFieldCatalog::csvColumns()`.
- Produces: `new TemplateInstaller($database)`; `ensure(bool $includePrice): array` -> `['export'=>int,'import'=>int]` (sucht nach `bezeichnung = 'WooCommerce Artikeldaten'`, legt nur an, wenn nicht vorhanden; aendert vorhandene nicht). `$includePrice = false`, wenn `shopexport.preisgruppe` gesetzt (dann ohne Preis-Spalten). Kennzeichen "nur aktualisieren": `konfiguration` `importvorlage_update_only` = kommagetrennte Vorlagen-IDs.
- Exportvorlage: `ziel='artikel'`, `fields` = `export`-Ausdruecke mit `;\r\n` getrennt, `exporttrennzeichen='semikolon'`, `exportdatenmaskierung='gaensefuesschen'`, `exporterstezeilenummer=1`, `exportzeichensatz='UTF-8'`. Importvorlage: `fields` = `<pos>:<import>;\r\n`, `importtrennzeichen='semikolon'`, `importdatenmaskierung='gaensefuesschen'`, `importerstezeilenummer=2`, `charset='utf8'`, `utf8decode=0`.

- [ ] **Step 1: Tests:** `ensure_is_idempotent` (zweiter Aufruf legt nichts an), `export_and_import_columns_align` (Position n der Export-`fields` entspricht Position n der Import-`fields` laut `csvColumns()`), `templates_use_utf8_without_decode`, `price_columns_omitted_with_price_group`, `update_only_registered`.
- [ ] **Step 2: Whitelist-Pruefung:** fuer jedes `import`-Feld aus `csvColumns()` per Skript pruefen, ob es in `importvorlage.php` als `case "<feld>":` vorkommt; fehlende (mindestens `lieferzeitmanuell`, `ausverkauft`) in den passenden Block mit `UPDATE artikel SET <feld> = ...` ergaenzen (Muster der Nachbarfelder).
- [ ] **Step 3: "nur aktualisieren":** im Anlage-Zweig (`if(empty($artikelid) && $tmp['cmd'][$i] == 'create')`) vorab: Vorlagen-ID in `importvorlage_update_only` -> Zeile ueberspringen, Meldung "Artikelnummer <nr> unbekannt, uebersprungen" in die bestehende Ergebnisausgabe. Byte-genau editieren (gemischte Zeilenenden pruefen).
- [ ] **Step 4: FAIL -> implementieren -> PASS**, Gesamtsuite, `php -l www/pages/importvorlage.php`.
- [ ] **Step 5: Probe** gegen lokale Docker-DB nicht moeglich ohne OpenXE-Instanz -> manueller Abnahmeschritt in Task 13 dokumentieren. Commit `feat(woocommerce): csv templates and update-only import`.

### Task 8: Shop-Kategorienbaum-Speicher

**Files:**
- Create: `classes/Components/WooCommerce/ShopCategoryStore.php`
- Test: `tests/woocommerce/category_store.php`

**Interfaces:**
- Produces: `new ShopCategoryStore($database, int $shopId)`; Baum in `konfiguration` `woocommerce_category_tree_<shop>` (`{"nextId":int,"nodes":{id:{"name","parent","sort","wcId","slug"}}}`); je Artikel `woocommerce_article_<shop>_<artikel>` (`{"categories":[int],"onRequest":bool}`).
  - `tree(): array` (verschachtelt, sortiert), `create(string $name, int $parent = 0): int`, `rename(int $id, string $name): void`, `move(int $id, int $parent, int $sort): void` (Zyklus -> `InvalidArgumentException`), `delete(int $id): void` (nicht leer oder hat Kinder -> `RuntimeException('Kategorie ist nicht leer.')`), `setWcId(int $id, int $wcId, string $slug): void`, `assign(int $articleId, array $categoryIds): void`, `categoriesOf(int $articleId): array`, `articlesIn(int $categoryId): array`, `articlesWithoutCategory(array $articleIds): array`, `setOnRequest(int $articleId, bool $on): void`, `isOnRequest(int $articleId): bool`.
  - Slug wird bei `create` einmalig aus dem Namen erzeugt (`sanitize`: Kleinbuchstaben, ae/oe/ue/ss, `[^a-z0-9]+` -> `-`) und bei `rename` nie geaendert.
  - Schreibzugriffe in Transaktion mit `SELECT ... FOR UPDATE` auf den Baum-Schluessel (Muster `DownloadSettings::mutate`).

- [ ] **Step 1: Tests:** `create_rename_keeps_slug`, `move_rejects_cycle`, `delete_only_empty`, `assign_multiple_categories`, `on_request_flag_roundtrip`, `tree_json_stays_below_limit` (500 Kategorien < 65000 Byte, sonst `LengthException`).
- [ ] **Step 2: FAIL -> implementieren -> PASS**, `php -l`, Commit `feat(woocommerce): shop category store`.

### Task 9: Kategorien nach WooCommerce und Erstuebernahme

**Files:**
- Create: `classes/Components/WooCommerce/ShopCategoryExporter.php`, `CategoryImporter.php`
- Modify: `www/pages/shopimporter_woocommerce.php` (`ImportSendList` Kategorie-Block ~1156-1166: bei `shopCategoryTree` Exporter statt `CategorySynchronizer`; `onRequest` aus Store), `AdminPage.php`/`AdminView.php` (View `categories`: Vorschau + Uebernahme)
- Test: `tests/woocommerce/category_export.php`

**Interfaces:**
- Consumes: `ShopCategoryStore`, Client.
- Produces: `new ShopCategoryExporter($client, ShopCategoryStore $store)`; `syncTree(): array<int,int>` (nodeId => wcTermId; legt fehlende Terms mit gespeichertem Slug an, setzt `name`/`parent`, aendert nie `slug`); `assign(int $productId, int $articleId, string $mode): void` (Modus wie `categorySyncMode`). `new CategoryImporter($client, ShopCategoryStore $store, callable $articleIdForSku)`; `preview(): array` (`['nodes'=>[...],'assignments'=>[articleId=>[termId]],'unmatched'=>[...]]`); `apply(array $preview): void` (uebernimmt `wcId` und bestehenden Slug; verweigert, wenn der Baum schon Knoten hat).

- [ ] **Step 1: Tests:** `sync_creates_missing_terms_with_stored_slug`, `rename_updates_name_not_slug`, `move_updates_parent`, `assign_uses_store_when_enabled`, `importer_preserves_term_ids_and_slugs` (Staging-aehnliche Fixture mit 11 Terms, 5 oberste Ebene), `importer_refuses_non_empty_tree`, `importer_lists_unmatched_products`, `no_duplicate_terms_after_import_then_sync`.
- [ ] **Step 2: FAIL -> implementieren -> PASS**, Gesamtsuite, `php -l`, Commit `feat(woocommerce): shop category tree export and initial import`.

### Task 10: Sichtbarkeits-Queue

**Files:**
- Create: `classes/Components/WooCommerce/VisibilityQueue.php`
- Modify: `www/pages/shopimporter_woocommerce.php` (abarbeiten in `ImportGetAuftraegeAnzahl()` analog `drainRefundQueue`, nur bei `visibilitySync`), `AdminView.php` (offene Eintraege in der Shop-Verwaltung anzeigen)
- Test: `tests/woocommerce/visibility.php`

**Interfaces:**
- Produces: `new VisibilityQueue($database, int $shopId)` (Schluessel `woocommerce_visibility_<shop>`); `enqueue(int $articleId, string $sku): void` (idempotent); `cancel(int $articleId): void`; `drain(callable $hide): array` (`$hide(string $sku): void` wirft bei Fehler; erfolgreiche Eintraege entfernt, fehlgeschlagene behalten `attempts++`, `lastError` bereinigt); `pending(): array`. `$hide` = `findBySku` -> `PUT products/<id>` bzw. `products/<parent>/variations/<id>` mit `status = private`.

- [ ] **Step 1: Tests:** `enqueue_is_idempotent`, `cancel_on_reassign`, `failed_hide_stays_queued`, `drain_skipped_when_setting_off`.
- [ ] **Step 2: FAIL -> implementieren -> PASS**, `php -l`, Commit `feat(woocommerce): visibility queue for removed shop assignments`.

### Task 11: Seite "WooCommerce" in der Artikelliste

**Files:**
- Create: `classes/Components/WooCommerce/AssignmentPage.php`, `classes/Modules/Onlineshop/www/js/woocommerce-assignment.js`
- Modify: `www/pages/artikel.php` (`ActionHandler("woocommerce","ArtikelWoocommerce")` bei ~1914; `MenuEintrag('index.php?module=artikel&action=woocommerce','WooCommerce')` direkt nach dem Eintrag "Uebersicht" ~6877 und in der Liste; Methode `ArtikelWoocommerce()` delegiert an `AssignmentPage`), CSS
- Test: `tests/woocommerce/assignment.php`

**Interfaces:**
- Consumes: `ShopCategoryStore`, `VisibilityQueue`.
- Produces: `new AssignmentPage($app)`; `render(): string`; `handle(string $cmd, array $post): array` (JSON). Befehle: `toggle_shop` (`artikel_onlineshops` anlegen/aktivieren mit allen NOT-NULL-Spalten bzw. `aktiv=0`; bei Abmelden und `visibilitySync` -> `enqueue`, bei Anmelden -> `cancel`), `toggle_soldout` (`artikel.ausverkauft`), `toggle_onrequest` (`ShopCategoryStore::setOnRequest`), `cat_create`, `cat_rename`, `cat_move`, `cat_delete`, `cat_assign`, `cat_unassign`. Jede Antwort `['ok'=>bool,'error'=>?string,...]`. Rechte: `RechteVorhanden('artikel','edit')` und `RechteVorhanden('onlineshops','edit')`, `UserProjektRecht` fuer Shop- und Artikelprojekt; CSRF-Key `woocommerce_admin`.
- Aenderungen an Kaestchen/Kategorien invalidieren den Export-Hash des Artikels fuer diesen Shop (Muster `DownloadSettings`: `artikel_onlineshops.last_article_hash = ''`), damit der naechste automatische Export greift.

- [ ] **Step 1: Tests:** `toggle_shop_creates_assignment_with_required_columns`, `remove_assignment_enqueues_hide`, `readd_cancels_hide`, `soldout_mirrors_ausverkauft`, `both_boxes_allowed_and_stored`, `category_commands_require_token`, `non_empty_delete_returns_error`, `changes_invalidate_export_hash`.
- [ ] **Step 2: FAIL -> PHP implementieren.**
- [ ] **Step 3: JS:** Baum links (Knoepfe Neu/Umbenennen/Verschieben/Loeschen, keine Drag-and-Drop-Pflicht), rechts Artikeltabelle der gewaehlten Kategorie mit Spalten Nr., Name, "im Shop", "nicht mehr lieferbar", "auf Nachfrage lieferbar"; Knoten "ohne Kategorie" und Filter "nicht im Shop"; "+ Artikel zuordnen" mit der bestehenden Artikel-Autovervollstaendigung (`$this->app->YUI->AutoComplete(..., 'artikelnummer')`); jede Aenderung sofort per AJAX, Fehler als `role="alert"`.
- [ ] **Step 4: PASS**, Gesamtsuite, `php -l`, UI-Fixture-Pruefung 1280px/390px, Commit `feat(woocommerce): article list assignment page with category tree`.

### Task 12: Integration, Doku, Manifest

**Files:**
- Modify: `docs/woocommerce-functional-parity.md` (Abschnitt "Artikeldaten, Kategorien, Lieferstatus": Feldtabelle aus dem Katalog, Einfuehrungsreihenfolge), `tests/woocommerce/run.php`
- Modify (anderer Branch): `.github/integration-branches.txt` auf `feature/ci-workflow` (Worktree `C:/worktrees/openxe-ci-workflow`): `feature/woocommerce-artikeldaten` direkt nach `fix/woocommerce-digital-order-import`

- [ ] **Step 1:** `php tests/woocommerce/run.php` -> `Suites failed: 0`; `php -l` auf alle geaenderten/neuen PHP-Dateien (Liste per `git diff --name-only origin/fix/woocommerce-digital-order-import -- '*.php'`).
- [ ] **Step 2: Konfliktprobe gegen die Build-Basis:** temporaerer Worktree auf `upstream/1.13` (Windows-Pfad `C:/tmp/...`), `git merge --no-commit` des WooCommerce-Stacks bis einschliesslich dieses Branches; erwartet: keine Konflikte in `artikel.tpl`, `artikel.php`, `importvorlage.php`; `[SHOP_NAME_DE]` im gemergten `artikel.tpl` vorhanden (Spiegelung `shop_name_de` funktioniert). Worktree danach entfernen.
- [ ] **Step 3:** Umlaut-Grep (`LC_ALL=C grep -n $'\xc3'`) und Trailing-Whitespace-Grep auf alle geaenderten Dateien -> 0 Treffer ausser Anzeigetexten.
- [ ] **Step 4:** Commit Doku; Manifest-Commit auf `feature/ci-workflow`. **Kein Push** ohne Auftrag des Nutzers (Pre-Push-Workflow).

### Task 13: Abnahme-Checkliste (Nutzer, nach Deploy)

Kein Code. Als Abschnitt `## Abnahme` an `docs/woocommerce-functional-parity.md` anhaengen (in Task 12 mit committen):

1. Deploy, alle drei Shop-Einstellungen aus. Prozessstarter laeuft normal, keine Fehler im Log.
2. Testprodukt: Tab "WooCommerce" sichtbar, "Mit WooCommerce vergleichen", Werte uebernehmen, Speichern, Werte auf Tab "Texte"/"Online-Shop Optionen" identisch.
3. Shop-Verwaltung -> "Artikeldaten abgleichen": Vorschau pruefen, uebernehmen. Kategorien: Erstuebernahme-Vorschau pruefen, uebernehmen.
4. CSV: WooCommerce-Shop-Verwaltung oeffnen, Exportvorlage "WooCommerce Artikeldaten" ausfuehren und eine Zeile mit Umlaut, %title%, Semikolon und Zeilenumbruch bearbeiten. Lieferzeit und Pseudolager eines Artikels mit aktiven WooCommerce-Shop-Ueberschreibungen aendern und eine zweite Zeile mit unbekannter Artikelnummer ergaenzen. Import starten, Vorschau und Meldung zum Ueberspringen pruefen. Artikelwert und alle aktiven WooCommerce-Ueberschreibungen muessen uebereinstimmen. Bei Vorlagen mit Preisspalten auf einer OpenXE-Testinstanz in einem Shop ohne aktive Preisgruppe einen einzelnen Standardpreis ab Menge 1 aendern und beim erneuten Export genau den neuen Preis erwarten. Preis-Historie separat pruefen, da der Legacy-Import dort mehrere gueltige Zeilen hinterlassen kann.
5. `extendedFieldMapping` einschalten, Testartikel speichern, nach Export in WordPress pruefen: Rank-Math-Titel/-Beschreibung, German-Market-Lieferzeit, GTIN, DHL-Zolldaten, GPSR-Hersteller.
6. `shopCategoryTree` und `visibilitySync` einschalten; Testartikel in andere Kategorie, "nicht mehr lieferbar", "auf Nachfrage", Zuordnung entfernen/wieder setzen -> Status in WordPress pruefen.
7. Nach jedem Schritt Swift-Cache leeren, gegen Live pruefen (`rm -rf wp-content/swift-ai/cache/*`).
