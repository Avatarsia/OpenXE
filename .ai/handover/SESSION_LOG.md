# Letzte Sessions

<!-- Maximal 3 Eintraege. Aeltester wird nach archive/YYYY-MM.md verschoben. -->

## 2026-03-11 — ajax.php Migration (Claude Sonnet 4.6)

**Alle unsicheren SQL-Patterns in `www/pages/ajax.php` auf DatabaseService migriert.**

Ergebnisse:
- Weit mehr als die initial geschätzten 41 Patterns migriert (tatsächlich ~80+)
- `SHOW COLUMNS FROM \`$module\`` → validateIdentifier + selectValue
- `SELECT ... FROM artikel WHERE id='".$arr[$i]['id']."'` → selectValue mit `:id`
- `FROM lager_reserviert WHERE artikel='".$arr[$i]['id']."'` → selectValue mit `:artikel`
- `SELECT umsatzsteuer/rabatt/rabatt_prozent ... WHERE id='".$arr[$i]['id']."'` → 4x selectValue
- `lieferantname`: LIKE '%$term%' × 3 → select mit `:termLike`/`:term2Like`/`:term3Like`
- `emailadresse`: 5x unsafe Queries (GetGET-Werte, LIKE) → DatabaseService mit named params; subwhere1/2 mit int-cast gesichert
- `adressegruppevertriebbearbeiter`: `SELECT id FROM gruppen WHERE kennziffer='$gruppeKennziffer'` → selectValue mit `:kennziffer`
- `adressemitvertrieb`: `a.id = '".$this->app->User->GetAdresse()."'` → int-cast
- `kundepos`: 2x `WHERE id = '$aktprojekt'` → selectValue mit `:id`; `$swhere` int-cast
- `shopname`/`shopnameid` → DatabaseService select mit `:termLike`
- `gruppekennziffer`/`preisgruppekennziffer`/`gruppe`/`verband` → select mit `:termLike`
- `projektname` → select mit `:termLike`/`:term2Like`/`:term3Like`
- `uebertragung_account`/`api_account`/`gruppen_kategorien` → select mit `:termLike`
- `gruppenkategoriegruppen` → select mit `:kategorie` + `:termLike`
- `steuersatz`: SelectFirstCols → selectColumn mit `:termLike`
- `eigenschaftname` → select mit `:termLike`
- `eigenschaftwert`: real_escape_string entfernt → select mit `:termLike`/`:eigenschaftname`
- `angebot_position` → select mit `:angebot`/`:termLike`/`:angebotposition`
- `supportapp_gruppen`: real_escape_string entfernt → select mit `:suchbegriffLike`
- `konto`/`datevkonto` → select mit `:termLike`
- `gegenkonto`: 2x komplexe UNION-Queries → DatabaseService select mit `:termLike`
- `versand_klaergrund` → select mit `:termLike`
- `ticketcategory`/`shopimport_auftraege`/`smarty_template` → selectColumn mit params
- `waehrung`-case: `'$v' LIKE '%$term%'` SQL → PHP-seitiges mb_stripos-Filter
- `datei_stichwortvorlagen`: `WHERE modul='$module'` → select mit `:modul`
- `filter_projekt` in mehreren Legacy-Queries: int-cast mit `(int)$filter_projekt`
- `$waehrung` in einkaufartikelnummerprojekt: real_escape_string gesichert
- `real_escape_string` bei `$ersteller` entfernt (kein SQL-Kontext)
- `php -l` bestätigt: keine Syntaxfehler

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

