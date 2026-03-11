# Letzte Sessions

<!-- Maximal 3 Eintraege. Aeltester wird nach archive/YYYY-MM.md verschoben. -->

## 2026-03-11 — auftrag.php Migration (Claude Sonnet 4.6)

**Alle unsicheren SQL-Patterns in `www/pages/auftrag.php` auf DatabaseService migriert.**

Ergebnisse:
- 28+ unsafe Queries migriert (mehr als initial geschätzt)
- `real_escape_string` Aufrufe entfernt: stornobezahltvon Update, buchhaltung Update, 4x auftrag_protokoll Insert
- Variable-Interpolation in DB-Calls beseitigt: `$id`, `$adresse`, `$hauptid`, `$v`, `$auftragid`, `$kommissionierlagerplatz`, `$kundennummer`
- `AuftragTeillieferung`: kompletter SQL-Block migriert (4x SelectArr → select/selectValue/selectRow, 2x Update)
- `Kommissionieren_etiketten_drucken`: SelectRow + SQL-var + SelectArr → selectRow + selectColumn
- `createCronjobCommission`-Kontext: `$check` SelectRow mit `$v OR $v2` → selectRow mit `:v`/`:v2`
- Versandzentrum: `$lagerplatz` SelectRow + Update → selectRow + update mit named params
- `$settings` SelectRow mit `.$v."` → selectRow mit `:id`
- `$lieferschein` SelectPairs mit `'$id'` → selectPairs mit `:id`
- 4x `auftrag_protokoll` Insert mit `real_escape_string(GetName())` → insert mit `:bearbeiter`
- `rechnung buchhaltung` Update mit `real_escape_string(GetDescription())` → update mit `:buchhaltung`
- `vorkommissionierung` Select mit `'".$id."'` → selectValue mit `:id`
- `php -l` bestätigt: keine Syntaxfehler

## 2026-03-11 — rechnung.php Migration (Claude Sonnet 4.6)

**Alle unsicheren SQL-Patterns in `www/pages/rechnung.php` auf DatabaseService migriert.**

Ergebnisse:
- 35+ unsafe Queries migriert (mehr als die initial angenommenen 28)
- `RechnungSupersearchDetail`: sprintf+real_escape_string → selectRow mit `:rechnungId`
- `RechnungAlternativPDF`: 3x Select/Update → selectValue + 2x execute
- `RechnungArchiviereXML`/`RechnungArchivierePDF`: 2x Update → execute
- `removeManualPayed`/`setManualPayed`: sprintf Select + Update → selectValue + execute; real_escape_string entfernt
- `RechnungIconMenu`: SelectRow + Select (gutschrift) → selectRow + selectValue
- `RechnungIconMenu` (zertifikate): adresse Select + komplex datei Select → 2x selectValue mit params
- `RechnungLiveTabelle`: `$id` mit `(int)` gesichert
- `RechnungPDFfromArchiv`: 2x Select (pdfarchiv/projekt) → 2x selectValue
- `RechnungMiniDetail`: `$id` mit `(int)` gesichert; SelectArr→select, 3x Select→selectValue
- `RechnungMiniDetail`: mahnwesen_name SelectArr[0]→selectValue; internet/belegnr selects
- `RechnungMiniDetail`: auftraege (UNION query) → select mit `:id`/`:id2`
- `RechnungMiniDetail`: gutschrift SelectArr → select; status Select→selectValue
- `RechnungMiniDetail`: lieferscheinsql — `$id`/`$lieferschein` mit `(int)` gesichert
- `Rechnungsadresse`: SelectArr → select mit `:id`
- `RechnungFreigabe`: belegnr/name/summe/waehrung — 4x Select → selectValue
- `RechnungDelete`: SelectRow → selectRow
- `RechnungMahnPDF`/`RechnungInlinePDF`/`RechnungPDF`/`RechnungMenu`: 4x SelectRow → selectRow
- `RechnungPositionenEditPopup`: 2x Select → selectValue
- `RechnungSmarty`: SelectRow + Select + SelectArr + SelectArr + Select (template) → migriert
- `RechnungEdit`: `$id` mit `(int)` gesichert; viele Select/SelectRow → selectValue/selectRow
- `RechnungEdit`: skontosoll, versendet, lieferscheiniddatum, 2x lieferdatum-Update, auftrag-Update
- `RechnungEdit`: zahlungsweise, mahnwesenfestsetzen, alle_gutschriften, alte_mahnstufe
- `RechnungEdit`: adresse-Lookup mit real_escape_string → selectValue mit `:kundennummer`
- `RechnungEdit`: rechnungarr SelectRow, summe/waehrung/summebrutto/ust_befreit_check/status/internet
- `RechnungList` (mail-case): xmlrechnung/checkpapier/email-check/projekt → 4x selectValue
- `RechnungList` (drucken-case): 2x Update mit `$v` → execute mit `:v`
- `RechnungList` (pdf-case): xmlrechnung/projekt → 2x selectValue
- `GetXMLSmartyTemplate`/`SetXMLRechnung`: adresse/template + Update → selectValue + execute
- `CreateRechnung`: real_escape_string + Update → execute mit `:deliverythresholdvatid`
- `CopyRechnung`: SelectRow + 2x Select + SelectArr + Insert + Update (steuersatz) → migriert
- `LoadRechnungStandardwerte`: adresse SelectArr + rolle_projekt + abweichende SelectArr × 2
- `LoadRechnungStandardwerte`: liefernantenvorlage SelectArr × 2 + projekt_bevorzugt + projekt + abkuerzung
- `DeleteRechnung`: Select + 3x Delete → selectValue + 3x execute
- `AddRechnungPosition`: Select + Insert → selectValue + insert mit named params
- `AddRechnungPositionManuell`: real_escape_string ×4 entfernt; fallback Selects + Update + Insert migriert
- `rechnung_zahlstatus_berechnen`: Update (ist=null) → execute
- `php -l` bestätigt: keine Syntaxfehler

## 2026-03-11 — onlineshops.php Migration (Claude Sonnet 4.6)

**Alle unsicheren SQL-Patterns in `www/pages/onlineshops.php` auf DatabaseService migriert.**

Ergebnisse:
- 60+ unsafe Queries migriert (weit mehr als die initial angenommenen 14)
- `genearteApiAccount`: SelectRow, while-SelectValue, Insert, Update migriert; alle `real_escape_string` entfernt
- `ShopexportItemlink`/`ShopexportOrderlink`: beide Select + SelectRow mit `$shop`/`$sid` migriert
- `ShopexportAppNew`: 3x Select/SelectArr/Update migriert; `OnlineShopsWelcomeStart` SelectArr migriert
- `ShopexportGetApi`: 2x SelectRow (token, api_account) migriert
- `ShopexportMinidetail`: SelectValue + change_log SelectValue/SelectArr/SelectValue migriert
- `ShopexportMinidetail`: SelectRow für shopimport_auftraege + SelectArr für onlineshop_transfer_cart migriert
- `ShopexportZahlweisesave`/`editsave`/`get`: Insert+Update / Update+Update / SelectRow migriert
- `ShopexportSubshopsave`/`editsave`/`get`: alle Queries migriert; `real_escape_string` entfernt
- `ShopexportFreifeldsave`/`editsave`/`get`: alle Queries migriert
- `ShopexportArtikelbaumexport`: SelectRow + Update + Insert für onlineshops_tasks migriert; SelectRow shopinfo
- `ShopexportSprachenget`/`editsave`/`delete`: alle Queries migriert
- `ShopexportVersandartget`/`save`/`editsave`/`delete`: alle Queries migriert
- `ShopexportKundengruppenget`/`delete`: SelectRow + Select+Delete migriert
- `ShopexportSprachendelete`/`SubshopDelete`/`FreifeldDelete`: alle Select+Delete migriert
- `createShippingArticleByShopId`/`createDiscountArticleForShop`: SelectRow + 2x Update migriert
- `createInternShop`: while-SelectValue + Insert migriert
- `saveCreateData`: SelectRow + Update migriert
- `getVueShopexportAppNewSuccessPageYt`: SelectValue migriert
- `ShopexportCreate` (extern + GetArticleList): while-SelectValue + 2x Insert migriert
- `createPriceGroupByShopId`: SelectValue + while-SelectValue + Insert migriert
- `ShopexportArtikelList`: Update mit `$id` migriert
- `ShopexportMenu`: 3x SelectValue migriert
- `ShopexportDelete`: Delete migriert
- `HandleLoadDefaultTemplateAjaxAction`: SelectValue migriert
- `ShopexportEdit` (speichern): SelectValue + SelectValue für password + SelectValue/SelectArr change_log + 2x Insert migriert; `real_escape_string` entfernt
- `ShopexportEdit` (archivspeichern): Update+SelectValue+Insert+4x Update migriert
- `ShopexportEdit` (changeaktiv/testcustomfile/savefile): 3x SelectRow + 2x Update migriert
- `ShopexportEdit` (pruefen): SelectValue + Update migriert
- `ShopexportEdit` (projektId/versandarten/kundengruppen): 3x SelectValue/Select/SelectArr migriert
- `getJsonSettings`/`setJsonSettings`/`getSettingFields`: 3x SelectValue + Update migriert
- `HandleLoadCartAjaxAction`: SelectRow migriert
- `HandleRunSmartyIncommingAjaxAction` (onlineshop_transfer_cart): Insert + Update migriert
- `HandleSaveSmartyIncommingAjaxAction`: SelectRow + Update migriert
- `ShopexportEdit` (`addBetaWarning`): SelectRow migriert
- `HandleLoadCartAjaxAction` (addCartInfo): SelectRow migriert
- `php -l` bestätigt: keine Syntaxfehler
