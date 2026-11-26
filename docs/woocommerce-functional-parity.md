# WooCommerce connector extensions

Branch: `feature/woocommerce-functional-parity`, based on `refactor/woocommerce-composer-sdk` at `bc46b52b`.

## Data ownership and behavior

- WooCommerce supplies orders, their final discounted line amounts, explicit line tax rates, shipping amounts, currency and source line IDs. Coupon codes remain in the saved cart; their amounts are not deducted a second time.
- OpenXE supplies product content, prices, inventory and explicitly selected category assignments. Existing ERP orders are not rewritten automatically after import.
- Modification reconciliation replaces the creation-date-only feed. It catches old orders becoming eligible after a later payment/status change. Existing import-status settings still determine which new orders enter the ERP.
- A separate per-shop journal in the existing `konfiguration` table records progress. The initial lower bound is the existing import cursor (including its existing 30-day fallback), or the optional explicit UTC start date. Existing legacy `ab_nummer` migration remains supported. Changing the optional start date after journal creation does not reset progress.
- A MySQL connection-scoped delivery lock spans source selection, the legacy caller's cart storage and acknowledgement. The cursor advances only after the cart or ERP order is found for the same shop/source ID. A failed delivery becomes eligible for recovery after 120 seconds once its previous connection has released the lock. Named locks require a stable database connection through acknowledgement.
- Reconciliation uses timestamp-scoped keyset queries, not shifting page offsets. Each call processes at most 1000 already-known/ineligible events before yielding; later calls continue from durable progress.
- Status feedback preserves the legacy first tracking number/carrier and adds all packages in `openxe_shipments`. Successful replays avoid duplicate tracking notes. A timeout between the non-atomic Woo note POST and metadata PUT can still duplicate a note.
- Refund processing uses a separate persistent retry queue, so unsupported or temporarily unavailable refunds cannot block later orders. Missing invoices and source prerequisites are checked before a booking intent is recorded. Retries use exponential backoff and remain discoverable even when the Woo modification feed is empty.
- No public webhook receiver is introduced. Regular reconciliation remains the delivery mechanism.

## Shop settings

- **ERP-Artikelnummer fuer WooCommerce-Gebuehren** (`feeArticleNumber`): existing ERP article number used for fee positions, including negative fees. An order containing fees fails explicitly when this mapping is empty.
- **WooCommerce-Erstattungen als Gutschriften uebernehmen** (`refundSync`): disabled by default. This is WooCommerce to OpenXE only: no gateway refund, shop restock or automatic invoice creation. An existing invoice and imported source line IDs are required. See [refund behavior and recovery](woocommerce-refunds.md).
- **Kategoriezuordnung** (`categorySyncMode`): `additive` preserves existing shop assignments; `replace` explicitly makes the exported OpenXE category list authoritative, including an empty list.
- **Explizite WooCommerce-Downloadfelder exportieren** (`exportDownloads`): disabled by default for parent products and variants. When enabled, explicitly supplied `downloadable`, `virtual` and `downloads` fields are mapped. Public document attachments are never inferred to be paid downloads. The WooCommerce shop administration now provides per-article download names and HTTP/HTTPS file URLs. Articles without a UI override retain their existing Smarty JSON transformation; download file URLs must be provisioned appropriately on the shop.
- **Erster Aenderungsabgleich ab UTC** (`importModifiedFrom`): optional `YYYY-MM-DDTHH:MM:SS` used only before the first modification journal is created.
- **Abgeschlossene Bestellungen mit abholen** (`importCompletedOrders`): disabled by default. Enable this to import native `completed` orders and the configured finished status in addition to the existing import-status list. Import acknowledgement preserves completed/finished statuses and download access. Existing shop status settings are not rewritten.

## Recovering a missed order

1. In the WooCommerce shop administration, select **Auftrag nachholen**. This opens the existing single-import form and also works with **Abholmodus: nach Status**.
2. Enter the actual WooCommerce order ID from the order URL (for example `9429`), not a separately formatted display number.
3. Select **Auftrag abholen**, then review and release the saved cart in the **Shopauftrag-Liste**. Single recovery deliberately queues the order for manual approval, even if normal imports are automatic.

The source status must be eligible under the shop's import settings; for a completed source enable the option above. This also applies to the legacy API `putOrderToXentral`: its `allStati=true` does not bypass the WooCommerce status filter. Leave **Nach abholen Status aendern** unchecked to preserve the source status. Even when checked, completed/finished and cancelled/refunded/failed states are not overwritten. Feedback failures after successful storage retain the cart, show a warning and log the shop/order IDs with sanitized error details; review the shop status instead of creating another order.

Status feedback rereads the current source status before writing. WooCommerce offers no conditional status update, so a source change between that GET and the subsequent PUT can still be overwritten. Leaving the status-change checkbox unchecked avoids this write during manual recovery.

Recovery reads exactly `orders/<id>` and does not rewind, advance or acknowledge the automatic modification checkpoint. Duplicate detection covers both saved carts and ERP orders in the same shop. It shares the automatic import's delivery lock, so a concurrent delivery cannot create a second cart. Invalid IDs, disallowed statuses, denied project access and invalid form tokens do not import an order. A source status change before a completed modification checkpoint is still recovered by the ordinary modification feed when it becomes eligible later.

The older creation-date importer at `6dc91a56` cannot recover an earlier skipped order merely by changing its status after newer orders have been imported. The modification feed and the single-import changes must be deployed before the steps above are available. Deploying this code and importing a live order are separate operational steps; the code tests do not prove a live import.

Existing shop flags for categories, variants, properties, pictures and file types still control the source export. Newly implemented capabilities are enabled in the capability matrix and source filtering.

## Product identity

Product/variant identity continues to use the configured SKU (foreign number when present, otherwise the ERP article number). Matrix options become selectable WooCommerce attributes; ordinary variants without distinct options use an article-number option. Remote variation/category lists are paginated. Existing category names are adopted only when unambiguous under the same parent. Categories use shop/source-specific slugs for persistent identity; adopting an existing category can change its slug, so review existing category URLs in staging.

Variant image data is adapted from the actual remote matrix export and cleaned up after upload. The existing WordPress application-password/media upload mechanism and Smarty product/file configuration remain in use. The WordPress HTTP adapter now honors SSL verification settings, handles empty media searches and uses the actual POST response when a new image is created.

## SDK audit

`automattic/woocommerce` is locked to **3.1.1**, the latest stable version found on 2026-10-21. Its PHP requirements and existing wrapper interfaces are compatible with this project. No dependency or vendor changes were needed. Sources: [release 3.1.1](https://github.com/woocommerce/wc-api-php/releases/tag/3.1.1), [Packagist](https://packagist.org/packages/automattic/woocommerce).

## Verification

Run `php tests/woocommerce/run.php`. The suite uses real connector/services with transport/database boundary doubles, plus a local PHP HTTP fixture for actual cURL requests. Tests cover mapping, tax rates, mixed discounts, delivery fencing, crash/restart, pagination changes, source identities, refunds, parent/variant export, category hierarchy, uploads, downloads and existing Smarty transformations.

The tracked checkout has no installed PHPUnit runner or pre-existing tests. The regression runner introduces no dependencies. PHP syntax checks are required for every modified/new PHP file. No live ERP, database or WooCommerce was changed during development; database/HPOS and actual shop-extension compatibility still require a staging run before deployment.

Verified on 2026-10-21 with PHP 8.5.1: 110/110 regression tests across nine suites; 25/25 PHP syntax checks passed. Independent review findings were fixed and confirmed. PHP 8.1/actual shop/HPOS execution is not claimed by these local results.

## WooCommerce administration

Open a WooCommerce shop, then choose **WooCommerce** in its menu. Both views reuse the existing shop-edit permission and enforce shop/article project rights; every write requires a persisted session CSRF token.

- **Downloads:** search assigned articles by number/name; edit downloadable/virtual and up to 50 name/URL rows. Only absolute HTTP/HTTPS URLs without embedded credentials are accepted; no file is uploaded or fetched by this form. Saving creates a shop/article override; disabling downloadable sends an empty download list while keeping the rows available for later editing. **Smarty-Vorgabe verwenden** removes the override. Save/reset invalidates the article and variant-parent export hash for this shop, so the existing automatic article export can detect the change. The global exportDownloads setting remains required.
- **Refund-Status:** paginated queue/journal state, Woo order/refund IDs, credit-note ID, attempts, sanitized last error and UTC attempt times. **Erneut versuchen** advances only the existing retry schedule; it never clears pending financial markers, creates gateway refunds or triggers restocking. Uncertain pending states require manual recovery.

Local rendering uses the real AdminView with original OpenXE styles and synthetic fixtures; it does not establish live ERP/shop integration.

## Artikelliste > WooCommerce

Unter **Artikel > WooCommerce** pflegst du die Shop-Zuordnung der Hauptartikel, den Shop-Kategorienbaum und die Lieferstatus je Artikel. Varianten folgen bei der Shop-Zuordnung dem Hauptartikel und behalten ihre eigenen Lieferstatus.

Erforderlich sind die Bearbeitungsrechte **artikel/edit** und **onlineshops/edit** sowie Zugriff auf das Projekt des Shops und des Artikels. Nicht-Admins benoetigen zusaetzlich in den OpenXE-Benutzerrechten (userrights) die Freigabe fuer Modul **artikel**, Aktion **woocommerce**. Diese Freigabe schaltet den Menuepunkt und den Zugriff auf die Action frei.

Die folgenden Shop-Einstellungen sind standardmaessig deaktiviert. Pruefe vor der Aktivierung, ob die aktuell angebotenen Hauptartikel eine aktive Zuordnung fuer diesen Shop haben, und uebernimm die vorhandenen WooCommerce-Kategorien in den Shop-Baum.

- **shopCategoryTree** exportiert den separaten Baum. Der Bestellimport synchronisiert vorgemerkte Baumaenderungen auch bei leerer Artikel-Exportqueue. Das Produkt-Kategorieverhalten folgt **categorySyncMode**.
- **visibilitySync** verarbeitet entfernte Zuordnungen beim Bestellimport ueber die [Sichtbarkeitsqueue](#shop-kategorien-und-lieferstatus). Eine aktive Zuordnung oder eine Legacy-Referenz auf den Shop gilt weiterhin als zugeordnet.
- **extendedFieldMapping** aktiviert den Export von **auf Nachfrage lieferbar**. Sind beide Lieferstatus gesetzt, hat **nicht mehr lieferbar** Vorrang.

Pruefe die Einstellungen beim Rollout mit einem Testartikel und dessen Varianten, einschliesslich Kategoriezuordnung, Lieferstatus, Ausblenden und erneutem Aktivieren.

## Artikeldaten, Kategorien, Lieferstatus

OpenXE fuehrt die Artikeldaten. Der Tab **WooCommerce** erscheint in den Artikeldetails bei aktiver WooCommerce-Shop-Zuordnung. Er spiegelt gemeinsam genutzte Felder im Artikelformular. **Mit WooCommerce vergleichen** liest das Produkt per SKU. Waehle Werte in der Vorschau aus, uebernimm sie in das Formular und speichere den Artikel. In der Shop-Verwaltung zeigt **Artikeldaten abgleichen** die Unterschiede fuer mehrere Artikel und listet unzuordenbare Produkte getrennt. Jede bestaetigte Rueckholung protokolliert Alt- und Neuwert.

Tab, Rueckholung und CSV nutzen dieselben Artikelfelder. Ist fuer ein Feld eine WooCommerce-Shop-Ueberschreibung aktiv (`artikel_onlineshops.aktiv = 1` und `ausartikel = 0`), werden Artikelwert und Ueberschreibung beim Schreiben synchronisiert. CSV ist artikelbezogen und synchronisiert alle aktiven WooCommerce-Ueberschreibungen fuer die importierten Felder. Den Shop und die Artikelzuordnung verwaltest du unter [Artikelliste > WooCommerce](#artikelliste--woocommerce). Dort sind auch die erforderlichen Rechte beschrieben.

### Feldtabelle

Die Tabelle folgt `ArticleFieldCatalog::fields()`. Die erste Zeile ergaenzt den CSV-Schluessel aus `ArticleFieldCatalog::csvColumns()`.

| Gruppe | Feld | OpenXE | WooCommerce | Rueckholung | Bearbeitbar | CSV-Header |
|---|---|---|---|---|---|---|
| Identitaet | Artikelnummer | `artikel.nummer` | Zuordnungsschluessel | - | - | `nummer` |
| Identitaet | Shop-SKU (Fremdnummer) | `artikelnummer_fremdnummern` | `sku` | - | nein | - |
| Texte | Shop-Name | `artikel.shop_name_de` | `name` | ja | ja | `shop_name_de` |
| Texte | Shop-Beschreibung | `artikel.uebersicht_de` | `description` | ja | ja | `uebersicht_de` |
| Texte | Shop-Kurzbeschreibung | `artikel.kurztext_de` | `short_description` | ja | ja | `kurztext_de` |
| SEO | SEO-Titel | `artikel.metatitle_de` | `meta:rank_math_title` | ja | ja | `metatitle_de` |
| SEO | SEO-Beschreibung | `artikel.metadescription_de` | `meta:rank_math_description` | ja | ja | `metadescription_de` |
| SEO | Fokus-Keyword | `artikel.metakeywords_de` | `meta:rank_math_focus_keyword` | ja | ja | `metakeywords_de` |
| Preis | Verkaufspreis netto | `verkaufspreise` | `regular_price / sale_price` | ja | bedingt | `standardpreisnetto` |
| Preis | Streichpreis (Pseudopreis/UVP), netto | `artikel.pseudopreis / Artikel-Shop-Ueberschreibung` | `regular_price` | ja | ja | `pseudopreis` |
| Status | Artikel inaktiv | `artikel.inaktiv` | `status` | ja | ja | `inaktiv` |
| Lager-Steuerung | Lagerzahlen Sync. | `artikel.autolagerlampe / Artikel-Shop-Ueberschreibung` | `manage_stock` | - | ja | `autolagerlampe` |
| Lager-Steuerung | Pseudo-Lagerzahl | `artikel.pseudolager / Artikel-Shop-Ueberschreibung` | `stock_quantity` | - | ja | `pseudolager` |
| Lager-Steuerung | Restmenge (Abverkauf) | `artikel.restmenge / Artikel-Shop-Ueberschreibung` | `backorders` | - | ja | `restmenge` |
| Lager-Steuerung | nicht mehr lieferbar | `artikel.ausverkauft` | `stock_quantity` | - | ja | `ausverkauft` |
| Lieferstatus | auf Nachfrage lieferbar | `konfiguration (je Shop und Artikel)` | `meta:openxe_on_request` | - | ja | - |
| Lieferung | Lieferzeit | `artikel.lieferzeitmanuell / Artikel-Shop-Ueberschreibung` | `meta:_lieferzeit` | ja | ja | `lieferzeitmanuell` |
| Logistik | Gewicht | `artikel.gewicht` | `weight` | ja | ja | `gewicht` |
| Logistik | Laenge | `artikel.laenge` | `dimensions.length` | ja | ja | `laenge` |
| Logistik | Breite | `artikel.breite` | `dimensions.width` | ja | ja | `breite` |
| Logistik | Hoehe | `artikel.hoehe` | `dimensions.height` | ja | ja | `hoehe` |
| Logistik | EAN / GTIN | `artikel.ean` | `global_unique_id` | ja | ja | `ean` |
| Zoll | Zolltarifnummer | `artikel.zolltarifnummer` | `meta:_dhl_hs_code` | ja | ja | `zolltarifnummer` |
| Zoll | Herkunftsland | `artikel.herkunftsland` | `meta:_dhl_manufacture_country` | ja | ja | `herkunftsland` |
| Hersteller | Hersteller / GPSR-Herstellerangabe | `artikel.hersteller` | `meta:_german_market_gpsr_manufacturer` | ja | ja | `hersteller` |

Der Tab zeigt die Shop-SKU lesend an. Kategorien, Bilder, Eigenschaften und Variantenstruktur werden an ihren bestehenden Pflegestellen bearbeitet. Der Lagerbestand wird aus den Lagerbuchungen berechnet. Die erweiterte Zuordnung sendet fuer EAN / GTIN zusaetzlich `_gm_gtin` und `_rank_math_gtin_code`.

Der Netto-Verkaufspreis ist im Tab, bei der Rueckholung und per CSV nur schreibbar, wenn der Shop keine aktive Preisgruppe verwendet und der Artikel genau eine gueltige Standardpreiszeile ab Menge 1 hat. Der CSV-Import setzt mindestens eine aktive WooCommerce-Shop-Zuordnung voraus und prueft die Preisgruppe jedes zugeordneten aktiven WooCommerce-Shops. Der Tab zeigt bei einem gesperrten Preisfeld den Grund an. Der CSV-Import meldet, warum er eine Preisaenderung ueberspringt. Fehlt die Standardpreiszeile, lege sie unter **Verkaufspreise** an. Die WooCommerce-Funktionen aktualisieren nur einen vorhandenen Standardpreis.

### Shop-Einstellungen und Leerwerte

- `extendedFieldMapping`, `shopCategoryTree`, `visibilitySync` und `yoastMeta` sind je Shop standardmaessig aus. `yoastMeta` steuert separat die zusaetzliche Ausgabe der Yoast-Titel und -Beschreibungen.
- `deliveryTimeMapping` ist standardmaessig leer. Der Connector liest German-Market-Lieferzeiten aus der WordPress-REST-Taxonomie `product_delivery_times`. Falls die Abfrage fehlschlaegt, kannst du je Zeile `Text=ID` hinterlegen. Unbekannte Lieferzeiten werden protokolliert und beim Export dieses Meta-Feldes uebersprungen.
- Fuer gemappte Text-Metadaten bedeutet leer in OpenXE auch leer in WordPress. Leere Lieferzeit sendet `_lieferzeit = -1` (Shop-Standard), sofern **nicht mehr lieferbar** keine Lieferzeit vorgibt. Der vorhandene Basisexport kann bei leerem Shop-Namen auf den Artikelnamen und bei leerer Shop-Beschreibung auf den Belegtext zurueckfallen.

### CSV-Vorlagen

In der WooCommerce-Shop-Verwaltung stehen Export- und Importvorlage **WooCommerce Artikeldaten** mit einem festen Layout bereit (UTF-8, Semikolon als Trennzeichen und doppelte Anfuehrungszeichen als Feldbegrenzung). `nummer` ist der Schluessel. Die einzige Preisspalte steht im CSV-Header als `standardpreisnetto` und wird in der Importvorlage dem Feld `woo_preis_netto` zugeordnet. Behalte Header und Spaltenreihenfolge aus dem Export bei. Der Import prueft sie exakt, aktualisiert nur vorhandene Artikel und meldet unbekannte Artikelnummern als uebersprungen. Ein leeres Preisfeld behaelt den bisherigen Preis.

### Shop-Kategorien und Lieferstatus

- Uebernimm zuerst WooCommerce-Kategorien und Artikelzuordnungen per Vorschau. Die Erstuebernahme setzt einen leeren Shop-Baum ohne offene Loeschungen voraus und behaelt vorhandene Term-IDs und Slugs bei. `shopCategoryTree` aktiviert den separaten Baum fuer den Export. `categorySyncMode` bestimmt, ob Produktzuordnungen ergaenzt oder ersetzt werden. Umbenennen behaelt den Slug. OpenXE loescht einen WooCommerce-Term erst nach verifizierter Leerpruefung auf Produkte und Unterkategorien.
- Bestellimport und Artikelexport nutzen denselben Shop-Lock fuer die Kategorie-Synchronisierung. Voruebergehend zurueckgestellte Artikelzuordnungen sind fruehestens nach 30 Minuten wieder fuer einen Versuch faellig. Die Verwaltung zeigt auch dauerhafte Konflikte an.
- **Nicht mehr lieferbar** schreibt `artikel.ausverkauft` und hat Vorrang vor **auf Nachfrage lieferbar**. WooCommerce erhaelt Bestand 0, `stock_status = outofstock` und `backorders = no`. Mit erweiterter Feldzuordnung wird die gemappte German-Market-Lieferzeit **derzeit nicht lieferbar** gesendet, sofern die Zuordnung bekannt ist.
- Nach dem Umschalten von `extendedFieldMapping` oder `shopCategoryTree` alle Artikel des Shops neu uebertragen. Dazu unter **Shops > Artikeluebertragung** die Aktion **Artikelcache zuruecksetzen** ausfuehren und den Prozessstarter `artikeluebertragen` laufen lassen.
- **Auf Nachfrage lieferbar** sendet mit `extendedFieldMapping` `manage_stock = false`, `stock_status = onbackorder`, `backorders = notify` und `openxe_on_request = 1`. Im Normalzustand und bei **nicht mehr lieferbar** ist das Meta-Feld bei aktivem Mapping `0`. Der reine Lagerexport ueberspringt den Anfragezustand. Varianten folgen der Shop-Zuordnung des Hauptartikels und haben eigene Lieferstatus.
- Bei `visibilitySync` gilt ein Hauptartikel mit `artikel_onlineshops.aktiv = 0` ohne passende Legacy-Referenz (`artikel.shop`, `shop2` oder `shop3`) als nicht zugeordnet. Nach Entfernen der Zuordnung setzt die Sichtbarkeitsqueue das Produkt beim Bestellimport auf `private`. Pro Durchlauf gelten 20 Eintraege und ein Zeitbudget von 20 Sekunden. Fehler bleiben mit Backoff in der Queue. SKU `0` erfordert manuelles Ausblenden. Erneutes Zuordnen storniert den Auftrag und der normale Export veroeffentlicht den aktiven Artikel wieder.

### Einfuehrungsreihenfolge

1. Ueber den CI-Production-Build auf Basis `upstream/1.13` deployen. Die Basis liefert `shop_name_de` und dessen Feld im Artikelformular. Die vier neuen Checkboxen bleiben aus, `deliveryTimeMapping` bleibt leer.
2. Berechtigungen und den Einzelabgleich an einem Testartikel pruefen. Anschliessend Artikeldaten en bloc aus WooCommerce zurueckholen. Erst nach Pruefung der Vorschau uebernehmen.
3. Bestehende WooCommerce-Kategorien samt Artikelzuordnung per Erstuebernahme in den Shop-Baum uebernehmen und pruefen. Aktive Artikel-Shop-Zuordnungen und etwaige Legacy-Referenzen kontrollieren.
4. `extendedFieldMapping` einschalten und den Export eines Testartikels in WordPress pruefen. `yoastMeta` bei Bedarf fuer einen Shop mit Yoast getrennt einschalten.
5. `shopCategoryTree` und `visibilitySync` einschalten und Kategorie, Lieferstatus, Ausblenden und Wiederveroeffentlichen am Testartikel pruefen. Fuer die laufende Artikelpflege den CSV-Kreislauf mit dem Export aus derselben Vorlage beginnen.

## Abnahme

1. Nach dem Deploy `extendedFieldMapping`, `shopCategoryTree`, `visibilitySync` und `yoastMeta` ausgeschaltet lassen, `deliveryTimeMapping` leer lassen. Pruefen, dass der Prozessstarter normal laeuft und das Log keine neuen Fehler zeigt. Mit einem Nicht-Admin mit Artikel-, Shop- und Projektrechten den Menuepunkt **Artikel > WooCommerce** und den Action-Zugriff nach Erteilung des Grants `artikel/woocommerce` in `userrights` pruefen. Ohne diesen Grant muss der Zugriff gesperrt sein.
2. Am zugeordneten Testprodukt den Tab **WooCommerce** oeffnen. **Mit WooCommerce vergleichen** waehlen, Werte aus der Vorschau uebernehmen und **Speichern** klicken. Die Werte in **Texte** und **Online-Shop Optionen** muessen mit den WooCommerce-Feldern uebereinstimmen.
3. In der WooCommerce-Shop-Verwaltung **Artikeldaten abgleichen** starten, Vorschau und unzuordenbare Produkte pruefen und die ausgewaehlten Werte uebernehmen. Bei der Kategorien-Erstuebernahme Baum, Term-IDs, Slugs und Artikelzuordnungen in der Vorschau pruefen und anschliessend bestaetigen.
4. In der WooCommerce-Shop-Verwaltung die Exportvorlage **WooCommerce Artikeldaten** ausfuehren. In einer Datenzeile ein Textfeld mit Umlaut, `%title%`, Semikolon und Zeilenumbruch bearbeiten. CSV-Quoting und Header beibehalten. Lieferzeit und Pseudolager eines Artikels mit aktiven WooCommerce-Shop-Ueberschreibungen aendern und eine zweite Zeile mit unbekannter Artikelnummer hinzufuegen. Import starten, Vorschau und Meldung zum Ueberspringen pruefen. Artikelwert und alle aktiven WooCommerce-Ueberschreibungen muessen nach dem Import uebereinstimmen. Auf einer OpenXE-Testinstanz in einem Shop ohne aktive Preisgruppe den einen gueltigen Standardpreis ab Menge 1 ueber `standardpreisnetto` aendern. Der erneute Export muss genau den neuen Preis ausgeben. Die Preis-Historie getrennt pruefen, da aeltere Legacy-Imports mehrere gleichzeitig gueltige Zeilen hinterlassen koennen.
5. `extendedFieldMapping` einschalten, Testartikel speichern und exportieren. In WordPress Rank-Math-Titel und -Beschreibung, German-Market-Lieferzeit, GTIN, DHL-Zolldaten und GPSR-Hersteller pruefen.
6. `shopCategoryTree` und `visibilitySync` einschalten. Testartikel in eine andere Kategorie verschieben. **Nicht mehr lieferbar**, **auf Nachfrage lieferbar** und beide Haken gemeinsam pruefen. Die Zuordnung entfernen und erneut setzen. Nach den jeweiligen Exporten oder Bestellimporten Kategorie, Lieferstatus, Meta-Wert, `private` und erneutes `publish` in WordPress pruefen. Falls eine transiente Kategoriezuordnung zurueckgestellt wird, den erneuten Versuch nach mindestens 30 Minuten und einem weiteren Prozessstarter-Lauf pruefen.
7. Nach jedem Schritt den Swift-Cache leeren (`rm -rf wp-content/swift-ai/cache/*`) und gegen Live pruefen.
