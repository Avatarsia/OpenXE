# WooCommerce-Artikeldaten: Tab, Rueckholung und CSV-Vorlagen

Datum: 2026-11-25
Branch: `feature/woocommerce-artikeldaten` (gestapelt auf `fix/woocommerce-digital-order-import`)
Status: Entwurf, wartet auf Freigabe

## Warum

Die Artikelpflege fuer den WooCommerce-Shop (partner-3d.de) ist heute schwer nachvollziehbar:

- Welches OpenXE-Feld wo in WooCommerce landet, ist nur aus dem Code ablesbar (`class.remote.php::RemoteSendArticleList` -> `ProductSynchronizer::map()`).
- Zwei verschiedene Felder heissen in der Artikelmaske "Artikelbeschreibung (DE)" (Belegtext auf Tab Artikel, Shop-Beschreibung auf Tab Texte).
- Mehrere Daten werden gelesen, aber nie exportiert (Meta-Title/-Description, Lieferzeit, Hersteller, Zolltarifnummer, Herkunftsland). SEO und Lieferzeit werden deshalb heute direkt in WordPress gepflegt.
- Der automatische Artikelexport ist live aktiv, es gibt keine Smarty-Vorlagen. Name, Beschreibung und Kurzbeschreibung werden bei jedem Export eines Artikels aus OpenXE ueberschrieben.

Ziel des Nutzers: **OpenXE ist fuehrend fuer alle Artikeldaten des Shops, inklusive SEO.** Die Daten sollen an einer Stelle sichtbar und pflegbar sein, und sie sollen sich per CSV exportieren, extern (auch durch Claude) bearbeiten und wieder importieren lassen, ohne im System selbst arbeiten zu muessen. Bevor OpenXE die zusaetzlichen Felder exportiert, muessen die heute in WordPress gepflegten Werte nach OpenXE geholt werden, sonst gehen sie beim ersten Export verloren.

## Ziele

1. **Tab "WooCommerce"** in der Artikelmaske unter Details, rechts neben "Online-Shop Optionen". Sichtbar nur fuer Artikel, die einem aktiven Online-Shop mit Modul `shopimporter_woocommerce` zugeordnet sind (`artikel_onlineshops`, aktiv). Zeigt alle shoprelevanten Felder mit eindeutiger Bezeichnung und Ziel-Feld in WooCommerce, bearbeitbar.
2. **Rueckholung WooCommerce -> OpenXE** (Umfang C: alles, was sich sinnvoll zurueckholen laesst).
   - Einzelartikel im Tab: "Mit WooCommerce vergleichen" zeigt pro Feld OpenXE-Wert, WooCommerce-Wert, Unterschied. Uebernahme nur ausgewaehlter Felder nach Bestaetigung. Das ist der Test an einem Produkt.
   - Alle Artikel in der WooCommerce-Verwaltung des Shops: Unterschiedsliste ueber alle zugeordneten Produkte, Uebernahme nach Bestaetigung, Liste nicht zuordenbarer Produkte (ohne SKU / ohne OpenXE-Artikel).
3. **Erweiterte Exportzuordnung** im Connector fuer die neuen Felder (siehe Feldkatalog), abschaltbar pro Shop.
4. **Vordefinierte Exportvorlage und Importvorlage** "WooCommerce Artikeldaten" mit allen skalaren Feldern des Feldkatalogs, identische Spalten, Schluessel Artikelnummer.

## Nicht-Ziele

- Kein Bearbeiten oder Zurueckholen von Kategorien, Bildern, Eigenschaften, Varianten-Strukturen, Cross-Selling. Der Tab zeigt sie nur lesend mit Link auf die bestehende Pflegestelle.
- Kein Zurueckholen des Lagerbestands (wird aus Lagerbuchungen berechnet).
- Kein Webhook / Push von WordPress nach OpenXE. Rueckholung ist eine manuelle Aktion.
- Keine Englisch-Texte (der Shop ist einsprachig).
- Keine Aenderung an WordPress-Plugins oder am Theme.

## Anti-Ziele (waere ein Fehlschlag, auch wenn es technisch laeuft)

- Daten in WordPress gehen verloren, weil die erweiterte Exportzuordnung vor der Rueckholung aktiv wird.
- Der Tab haelt eine eigene Kopie der Daten, oder Speichern im Tab und Speichern der restlichen Maske ueberschreiben sich gegenseitig.
- Eine Rueckholung schreibt ohne Vorschau und Bestaetigung in OpenXE.
- Ein CSV-Import legt neue Artikel an oder aendert Artikel ausserhalb der Datei.

## Randbedingungen

- Alle Details-Untertabs ("Artikel", "Texte und Beschreibungen", "Online-Shop Optionen", "Finanzbuchhaltung") sind **ein** Formular (`eprooform`, `www/widgets/templates/_gen/artikel.tpl` Zeile 102-1095) mit einem gemeinsamen "Speichern". Doppelte Feldnamen im selben Formular kollidieren still.
- Die Spalten `artikel.shop_name_de/_en` stammen aus upstream `54aee9bc` (2026-11-04) und sind in `upstream/1.13` (Basis des Production-Builds) enthalten, aber NICHT in der Basis von `fix/woocommerce-digital-order-import` (`upstream/master`-Stand). Der Branch muss gegen einen Stand mit `54aee9bc` entwickelt und getestet werden (z. B. `upstream/1.13` hineinmergen oder Abhaengigkeit dokumentieren), der Pass-1-Isolationstest des CI-Builds laeuft gegen `upstream/1.13`.
- Der Importvorlagen-Import legt fuer unbekannte Artikelnummern neue Artikel an (`importvorlage.php` ~1463 `cmd='create'`, ~1735ff.). Ein "nur aktualisieren" erfordert einen kleinen zusaetzlichen Kerneingriff dort.
- Kernberuehrungen (Konfliktrisiko mit upstream openxe-org) minimal halten: Tab-Eintrag in `artikel.tpl`, Sichtbarkeit und Speicher-Erweiterung in `www/pages/artikel.php`, Feld-Whitelist in `www/pages/importvorlage.php`. Alles Uebrige in `classes/Components/WooCommerce/` und dem Onlineshop-Modul, analog zur bestehenden WooCommerce-Verwaltung (`AdminPage`, `AdminView`).
- Deployment nur ueber den CI-Production-Build: Branch in `.github/integration-branches.txt` auf `feature/ci-workflow`, direkt nach `fix/woocommerce-digital-order-import`.
- PHP 8.1+ auf Live, `php -l` auf jede Datei, Regressionstests in `tests/woocommerce/run.php`.
- WooCommerce-Einstellungen (Staging-Kopie): Preise netto eingegeben (`woocommerce_prices_include_tax = no`), keine zusaetzlichen Steuerklassen. Plugins: Rank Math, German Market. Vor Implementierung auf Live gegenpruefen.
- Jede WooCommerce-Abfrage geht gegen die Live-Seite. Vergleich nur auf Knopfdruck, Massenvergleich paginiert (100 Produkte pro Anfrage).
- CSRF und Rechte wie in der bestehenden WooCommerce-Verwaltung (Shop-Recht + Projektrecht, persistiertes Session-Token).

## Ansaetze

Fuer den Tab wurden zwei Bauweisen betrachtet:

1. **Gespiegelte Felder, gemeinsames Speichern (gewaehlt).** Der Tab hat eigene Eingabefelder mit eigenem Namensraum (z. B. `woo[...]`). JavaScript spiegelt Eingaben live in die Originalfelder der anderen Tabs und zurueck. Gespeichert wird mit dem normalen "Speichern". Felder, die nicht im Artikelformular liegen (Verkaufspreis, Fremdnummer), speichert eine Erweiterung im selben Speichervorgang. Kein widerspruechlicher Stand, verhaelt sich wie der Rest von OpenXE. Mehr Aufwand, vor allem fuer den CKEditor der Shop-Beschreibung.
2. **Eigener Tab mit eigenem Speichern (verworfen).** Einfacher, aber ein spaeteres "Speichern" der Hauptmaske ueberschreibt die Tab-Aenderung mit dem alten Formularstand. Abfangen nur durch Neuladen nach jedem Tab-Speichern, wobei ungespeicherte Eingaben auf anderen Tabs verloren gehen.

Fuer die Rueckholung wurden Umfang A (nur SEO), B (SEO + Texte) und C (alles Zurueckholbare) betrachtet. Gewaehlt: **C**, mit Test an einem Einzelprodukt vor der Massenuebernahme.

Fuer CSV wurde eine eigene Export-/Importfunktion gegen die vorhandenen OpenXE-Export-/Importvorlagen abgewogen. Gewaehlt: **vorhandene Vorlagen**, vordefiniert ausgeliefert. Die Exportvorlage gibt beliebige `artikel`-Spalten aus (`exportvorlage.php::getFieldSqlCols`, default-Zweig). Die Importvorlage hat eine feste Feldliste (`importvorlage.php` ab Zeile 1827) und wird um die fehlenden Felder erweitert.

## Feldkatalog

Grundsatz: Ein Feld, eine Speicherstelle in OpenXE. Tab, CSV, Rueckholung und Export lesen und schreiben dieselbe Stelle.

| Bereich | Bezeichnung im Tab | OpenXE-Speicherstelle | WooCommerce-Ziel | Export heute | Rueckholung |
|---|---|---|---|---|---|
| Identitaet | Artikelnummer | `artikel.nummer` | - | - | Schluessel |
| Identitaet | Shop-SKU (Fremdnummer) | `artikelnummer_fremdnummern` (shopid, aktiv) | `sku` | ja | nein, **nur Anzeige** (eine SKU-Aenderung liesse `ProductSynchronizer::sync()` ein zweites Produkt anlegen) |
| Texte | Shop-Name | `artikel.shop_name_de` (leer: `name_de`) | `name` | ja | ja -> `shop_name_de` |
| Texte | Shop-Beschreibung | `artikel.uebersicht_de` | `description` | ja (leer: Belegtext `anabregs_text`) | ja |
| Texte | Shop-Kurzbeschreibung | `artikel.kurztext_de` | `short_description` | ja | ja |
| SEO | SEO-Titel | `artikel.metatitle_de` | meta `rank_math_title` | **neu** | ja |
| SEO | SEO-Beschreibung | `artikel.metadescription_de` | meta `rank_math_description` | **neu** | ja |
| SEO | Fokus-Keyword | `artikel.metakeywords_de` | meta `rank_math_focus_keyword` | **neu** | ja |
| Preis | Verkaufspreis netto | `verkaufspreise` (dieselbe Zeile, die der Export liest: ab Menge 1 Standard, bzw. Gruppenpreis wenn `shopexport.preisgruppe` gesetzt) | `regular_price` / `sale_price` | ja | ja, mit Warnhinweis |
| Preis | Streichpreis (Pseudopreis/UVP), netto | `artikel.pseudopreis` | `regular_price` (wenn > Preis) | ja | ja (aus `regular_price`, wenn `sale_price` gesetzt) |
| Status | Artikel inaktiv | `artikel.inaktiv` | `status` private/publish | ja | ja |
| Lager-Steuerung | Lagerzahlen Sync. | `artikel.autolagerlampe` | `manage_stock` | ja | nein |
| Lager-Steuerung | Pseudo-Lagerzahl | `artikel.pseudolager` | `stock_quantity` | ja | nein |
| Lager-Steuerung | Restmenge (Abverkauf) | `artikel.restmenge` | `backorders` = no | ja | nein |
| Lager-Steuerung | Ausverkauft | `artikel.ausverkauft` | Bestand 0 | ja | nein |
| Lieferung | Lieferzeit | `artikel.lieferzeitmanuell` | meta `_lieferzeit` (German-Market-Term, Namensabgleich) | **neu** | ja |
| Logistik | Gewicht, Laenge, Breite, Hoehe | `artikel.gewicht/laenge/breite/hoehe` | `weight`, `dimensions` | ja | ja |
| Logistik | EAN / GTIN | `artikel.ean` | `global_unique_id`, meta `_gm_gtin`, meta `_rank_math_gtin_code` | teilweise (`global_unique_id`) | ja (erster nicht leerer Wert) |
| Zoll | Zolltarifnummer | `artikel.zolltarifnummer` | meta `_dhl_hs_code` | **neu** | ja |
| Zoll | Herkunftsland | `artikel.herkunftsland` | meta `_dhl_manufacture_country` | **neu** | ja |
| Hersteller | Hersteller / GPSR-Herstellerangabe | `artikel.hersteller` | meta `_german_market_gpsr_manufacturer` | **neu** | ja |
| Lesend | Kategorien (aus dem Shop-Kategorienbaum, Teil 2: `2026-11-25-woocommerce-shopzuordnung-design.md`), Bilder, Eigenschaften, Varianten, aktueller Bestand | bestehende Stellen | bestehend | ja | nein |

Lieferzeit-Werte in German Market (Staging, Taxonomie `product_delivery_times`): "sofort lieferbar", "ca. 24 Stunden", "ca. 48 Stunden", "ca. 2-3 Werktage", "ca. 3-4 Werktage", "ca. 10 Werktage", "ca. 14 Werktage", "ca. 30 Werktage", "derzeit nicht lieferbar", "keine Lieferzeit (z.B. Download)". `-1` = Shop-Standard. Der Tab bietet diese Werte als Auswahl, gespeichert wird der Text in `lieferzeitmanuell`. Leer = Shop-Standard.

## Verhalten im Detail

### Tab "WooCommerce"

- Eintrag `tabs-6` zwischen `tabs-4` (Online-Shop Optionen) und `tabs-5` (Finanzbuchhaltung), ein-/ausgeblendet ueber Platzhalter nach dem vorhandenen Muster `[DISABLEOPENSHOP]`.
- Gruppen wie im Feldkatalog. Jedes Feld zeigt Bezeichnung, Ziel in WooCommerce und gegebenenfalls einen Hinweis ("leer: es wird der Belegtext verwendet", "Shop-spezifischer Wert ueberschreibt diesen Wert").
- **Ein Wert, ueberall derselbe (Nutzervorgabe 2026-11-25):** Tab, Rueckholung und CSV schreiben in die vorhandenen OpenXE-Felder, damit derselbe Wert auf allen Tabs steht, ohne hin und her zu kopieren. Shop-spezifische Ueberschreibungen (`artikel_onlineshops` mit `ausartikel = 0`, ausgewertet ueber `GetArtikelShopEinstellung`, `class.erpapi.php` ~16771) fuer Pseudopreis, Pseudo-Lager, Restmenge, Lieferzeit, Lagerzahlen Sync.: Ist eine aktiv, werden Artikelwert und Ueberschreibung mit demselben Wert geschrieben. Der Tab weist darauf hin.
- Spiegelung: Eingaben im Tab aktualisieren die Originalfelder im selben Formular und umgekehrt, inklusive CKEditor-Instanz der Shop-Beschreibung. Das Originalfeld ist die Speicherquelle; die Tab-Felder werden serverseitig ignoriert, ausser fuer Werte ohne Originalfeld im Formular (Verkaufspreis, Shop-Ueberschreibungen, Lieferzeit-Auswahl falls nicht im Formular). Die Fremdnummer ist im Tab nicht bearbeitbar.
- Knopf "Mit WooCommerce vergleichen" (AJAX): liest das Produkt per SKU, zeigt die Spalte "WooCommerce aktuell", markiert Unterschiede. Pro Feld eine Checkbox, Knopf "Ausgewaehlte Werte uebernehmen" uebertraegt die Werte in die Tab-Felder (und damit gespiegelt in die Originalfelder). Gespeichert wird erst mit "Speichern". Damit gilt dieselbe Vorschau-und-Bestaetigung wie ueberall in der Maske.

### Rueckholung fuer alle Artikel

- Neuer Bereich "Artikeldaten abgleichen" in der WooCommerce-Verwaltung des Shops (neben Downloads und Refund-Status).
- Schritt 1 "Vergleichen": liest alle WooCommerce-Produkte und -Varianten paginiert, ordnet per SKU zu und erzeugt eine Unterschiedsliste je Artikel und Feld. Nicht zuordenbare Produkte werden separat gelistet.
- Schritt 2 "Uebernehmen": Auswahl nach Feld (z. B. nur SEO) und/oder Artikel, dann Schreiben in die OpenXE-Speicherstellen. Jede Uebernahme wird mit Altwert und Neuwert protokolliert, damit sie nachvollziehbar und manuell rueckgaengig zu machen ist.

### Erweiterte Exportzuordnung

- Neue Shop-Einstellung "Erweiterte Feldzuordnung (SEO, Lieferzeit, Hersteller, Zoll, GTIN) exportieren", Standard **aus**. Erst nach der Massenrueckholung einschalten.
- Behebt dabei: Metadaten werden zusammengefuehrt statt ersetzt (heute `shopimporter_woocommerce.php` Zeile 1110). Die Yoast-Schluessel bleiben im Code, werden aber nur mit der neuen Shop-Einstellung "Yoast-Metadaten zusaetzlich senden" (Standard aus) gesendet (Nutzervorgabe: ausblenden, nicht entfernen). Hinweis: Bei Varianten sendet `map()` die Yoast-Schluessel heute bereits mit (`ProductSynchronizer::sync()` mappt Varianten erneut, `variationPayload()` laesst `meta_data` durch); auch das entfaellt.
- Lieferzeit: Abgleich Text -> German-Market-Term-ID ueber die WordPress-REST-API (Taxonomie `product_delivery_times`, Zugang ueber die vorhandenen Felder WordPress-Benutzer/Anwendungspasswort). Ist das nicht verfuegbar, eine feste Zuordnungstabelle in den Shop-Einstellungen. Unbekannter Text wird nicht gesendet und im Export-Protokoll gemeldet.
- Leere OpenXE-Felder (Nutzerentscheidung A): **leer = auch in WordPress leer.** OpenXE ist fuehrend; ein in OpenXE geloeschter Wert wird in WordPress geloescht. Leere Lieferzeit wird als `_lieferzeit = -1` (Shop-Standard) gesendet. Gilt einheitlich fuer alle Felder des Katalogs, wie heute schon fuer Name, Beschreibung, Kurzbeschreibung. Voraussetzung: Massenrueckholung vor dem Einschalten (Einfuehrungsreihenfolge).

### CSV-Vorlagen

- Exportvorlage und Importvorlage "WooCommerce Artikeldaten", identische Spalten in Katalogreihenfolge, Schluessel `nummer`. Nur skalare Felder des Feldkatalogs, keine Lagerbestaende.
- Importvorlage aktualisiert nur vorhandene Artikel, legt keine an. Dafuer bekommt `importvorlage.php` eine kleine Erweiterung: Vorlagen mit Kennzeichen "nur aktualisieren" ueberspringen Zeilen mit unbekannter Artikelnummer und melden sie in der Vorschau.
- `importvorlage.php` wird um fehlende Felder erweitert (mindestens `lieferzeitmanuell`, `ausverkauft`; vollstaendig gegen den Katalog pruefen).
- Ausgeliefert als Datensaetze in `exportvorlage` / `importvorlage`, idempotent angelegt (nach Bezeichnung), damit sie nach jedem Update vorhanden sind und Benutzer-Aenderungen nicht ueberschrieben werden.

## Einfuehrungsreihenfolge

1. Deploy mit erweiterter Exportzuordnung **aus**.
2. Testprodukt: Tab oeffnen, vergleichen, uebernehmen, speichern, Ergebnis pruefen.
3. Massenrueckholung in der Shop-Verwaltung.
4. Erweiterte Exportzuordnung einschalten, Export eines Testartikels pruefen (Rank Math, German Market Lieferzeit in WordPress sichtbar).
5. Ab dann CSV-Kreislauf.

## Getroffene Festlegungen (Vorschlaege von Claude, vom Nutzer pruefbar)

- Bauweise 1 (gespiegelte Felder) fuer den Tab, gemaess Empfehlung; Nutzer bestaetigte "eigener Tab", die Bauweise wird mit diesem Dokument bestaetigt.
- SEO-Fokus-Keyword aus `metakeywords_de`.
- EAN wird in alle drei GTIN-Felder geschrieben.
- Hersteller wird nur als Text uebertragen; GPSR "verantwortliche Person" und "Warnhinweise" bleiben in WordPress (kein OpenXE-Feld).
- Kategorien, Bilder, Eigenschaften, Varianten und Lagerbestand werden nicht zurueckgeholt.
- Bei mehreren WooCommerce-Shops: Shop-Auswahl im Tab (heute nur einer vorhanden).
- Shop-SKU nur Anzeige.
- Tab, Rueckholung und Preisfeld arbeiten auf der Stelle, die der Export liest (Shop-Ueberschreibung bzw. Preisgruppe).
- Importvorlage "nur aktualisieren" per kleiner Erweiterung in `importvorlage.php`.
- Entwicklung und automatische Tests gegen das lokale Docker-WordPress (localhost:9000, eigene REST-Schluessel); Abnahme am Live-Testprodukt durch den Nutzer.

## Dem Builder ueberlassen

- Konkrete Klassenaufteilung in `classes/Components/WooCommerce/` und Testaufbau.
- Wie die Speicher-Erweiterung in `artikel.php` andockt (Hook, `_custom`-Mechanik oder minimaler Kerneingriff), solange der Eingriff klein bleibt.
- Darstellung des Protokolls der Rueckholung.
- Verifikation, dass WooCommerce-REST die Meta-Schluessel mit Unterstrich (`_lieferzeit`, `_gm_gtin`, `_dhl_*`, `_german_market_*`) schreibt; falls nicht, Ausweichweg vorschlagen.
