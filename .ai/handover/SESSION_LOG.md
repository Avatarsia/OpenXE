# Letzte Sessions

<!-- Maximal 3 Eintraege. Aeltester wird nach archive/YYYY-MM.md verschoben. -->

## 2026-03-12 — api.php Migration — ABGESCHLOSSEN (Claude Sonnet 4.6)

**Phase 1 vollständig abgeschlossen. Alle 316+ unsicheren SQL-Patterns in `www/pages/api.php` auf DatabaseService migriert.**

Ergebnisse (Teil 2 — Fortsetzung nach Session-Unterbrechung):
- `ApiZeiterfassungEdit` dynamic column loop: bereits aus vorheriger Session
- `BelegeimportAusfuehren` produktion-Block: 5x DB->Update/Select(sprintf) → DatabaseService mit named params
- Artikel-Lookup (nummer/ean): `real_escape_string` + string concat → selectValue mit `:nummer`/`:ean`/`:projekt`
- Artikel-Bezeichnung/Sprache-Lookup: 2x SelectRow + 2x Select → selectRow + 2x selectValue; `$aktbelegart` → validateIdentifier
- `teillieferungvon`-Block: porto-Check, SelectArr, 2x Update, Delete → selectValue + select + update + delete + delete
- Position-INSERTs für alle Belegtypen (gutschrift/bestellung/angebot/lieferschein/retoure/preisanfrage/proformarechnung/produktion): je SelectValue(max sort) + insert mit named params
- `artikelnummerkunde` SELECT/UPDATE: real_escape_string entfernt → selectValue + validateIdentifier + update
- `felder`-foreach-Loop: dynamic column `validateIdentifier` + update mit `:value`/`:id`
- DELETE FROM belegeimport: delete mit `:id`
- `erstelltebelegeids` + `erstelltebelegeNichtAngelegtids` foreach: validateIdentifier + update/selectValue
- `BelegeimportDatei`: uebertragungen_account (3x SELECT), INSERT belegeimport, UPDATE beleg_projekt; alle 8 switch-cases (beleg_projekt/artikel/artikel_nummer/artikel_ean/beleg_kundennummer/beleg_lieferantennummer); dynamischer SET `validateIdentifier`; end-of-row UPDATEs
- `ApiExportVorlageGet`: 2x Select(`'$projekt'`/`'$id'`) → selectValue mit named params
- `ApiStuecklisteSet`: 3x Select + 1x Insert + 1x Update + 1x Delete → DatabaseService; alternativevorhanden-Check
- `EventCall`/EventAuftragEdit: SelectArr(eventname) + 2x Insert + Update(retries) + Delete → DatabaseService
- `ApiBestellungGet`/`ApiLieferscheinPositionenGet`: SelectArr mit `$doctype`/`$id` → validateIdentifier + select
- `ApiArtikelGet`: api_mapping extid, projekt, artikelkategorien, lager SELECTs (7x), einkaufspreise/verkaufspreise SelectArr → DatabaseService
- `adresse`-Lookup in BelegeimportAusfuehren: dynamic `$adresseprojektSql` mit params-Array
- `BelegeimportAusfuehren` hauptbelegnr: validateIdentifier + selectValue
- `php -l` bestätigt: keine Syntaxfehler
- Phase 1 **vollständig abgeschlossen**

## 2026-03-12 — artikel.php Migration (Claude Sonnet 4.6)

**Alle unsicheren SQL-Patterns in `www/pages/artikel.php` auf DatabaseService migriert.**

Ergebnisse:
- 104+ unsafe Queries migriert; alle `real_escape_string` entfernt
- `getalternativedetails`: SelectArr mit `'$alternativeId'` → selectRow; Select CONCAT → selectValue
- `ArtikelLagerVPE` (getvpe/getvpevorlage/savevpe): 3x SelectRow/Select mit `'".$lpiid."'`/`'".$vpeid."'` → selectRow/selectValue
- `ArtikelLager` bestbeforeBatchSn: DB->SelectRow(sprintf('%d')) → selectRow mit `:id`
- `ArtikelStuecklisteExport`: SelectArr mit `'$id'` → select mit `:id`
- `ArtikelgenEigenschaften`: SelectArr mit `'$keys[$lvl]'`/`'$k'` → select mit named params
- `ArtikelFreifelderEdit` (get): 2x SelectRow mit `'$id'` → selectRow mit `:id`; dynamic `freifeld$nummer` column → validateIdentifier
- `ArtikelFreifelderSave`: 2x Update mit `'$inhalt'`/`'$id'` → update mit named params; Insert → insert
- `ArtikelFreifelderDelete`: Delete mit `'$id'` → delete mit `:id`
- `ArtikelFreifelder` (nachladen): Insert mit `'$artikelid'` → insert mit `:artikelid`/`:artikelid2`
- `getArtikelThumbnailDateiVersion`: SelectArr mit `' . $id . '` → select mit `:id`
- `getPreviewFileFromFileId`: SelectRow(sprintf('%d')) → selectRow mit `:fileId`
- `getPreviewFileFromArticleId`: SelectRow(sprintf('%d')) → selectRow mit `:articleId`
- `ajaxGenerateThumbnail`: 3x SelectRow/Update(sprintf) → selectRow + 2x update mit named params
- `ArtikelThumbnail` (direkt): SelectRow(sprintf) → selectRow mit `:id`
- `ArtikelThumbnail` (vorschau): DB->Select(sprintf) + DB->SelectRow('…WHERE id=\'$id\'') → selectValue + selectRow mit named params; DB->Update(sprintf) → update mit `:id`
- `ArtikelBaumDetail`: Select/Insert/Delete mit `'$artikel'`/`'$id'` → selectValue/insert/delete mit named params
- `getKategorien`: SelectArr mit `'$parent'` → select mit `:parent` (int-cast)
- `ArtikelBaumAjax`: SelectArr mit `'$id'` → select mit `:id`
- `ArtikelDeleteFile`: DB->Update(sprintf) → update mit `:fileId1`/`:fileId2`
- `ArtikelSupersearchDetail`: SelectRow(sprintf + real_escape_string) → selectRow mit `:id`; real_escape_string entfernt
- `updateArticlePicturePreview`: SelectFirstCols(sprintf) → selectColumn mit `:limit`
- `Kalkulation` sql_query: `'$id'` → `(int)$id` inline-cast; SelectArr($sql) mit `'$id'` → DatabaseService select mit `:id`
- `ArtikelLagerInfo`: 5x Select mit `'$artikel'` → selectValue mit `:artikel`
- `ArtikelSchnellanlegen` barcode: 2x Select mit `'$barcode'`/`'$checkbarcode'` → selectValue mit named params
- `ArtikelScan`: komplett refaktoriert — 5 Suchpfade (nummer/ean/herstellernummer/lieferant/fremdnummer) je mit `$ignoreprefixpostfix`-Variante; real_escape_string entfernt; alle Queries → selectValue mit named params
- Alle statischen SQL-Calls (cache-rebuild-Funktionen, streaming DB->Query+Fetch_Assoc, IN-Clauses mit pre-sanierten int-Arrays) unangetastet gelassen
- Alle comment-block-Calls unangetastet gelassen
- `php -l` bestätigt: keine Syntaxfehler

## 2026-03-11 — zeiterfassung.php Migration (Claude Sonnet 4.6)

**Alle unsicheren SQL-Patterns in `www/pages/zeiterfassung.php` auf DatabaseService migriert.**

Ergebnisse:
- 52 unsafe Queries migriert
- Kalender-Event-Queries (2x SelectArr mit $user/$start/$end/$start_datum/$end_datum) → select mit named params; $subwhere (intern generiert) sicher eingebettet
- `getzeiterfassung`-Block: 6x Select → selectValue mit `:id` (int-cast)
- `delzeiterfassung`: DB->Delete → delete mit `:id`
- `ZeiterfassungCreate`: `SELECT adresse FROM user WHERE id='$id'` → selectValue mit `:id`
- `ZeiterfassungListUser`: 5x DB->Delete mit $lid → delete mit `:id` (int-cast); +1 mit gebucht_von_user → `:user_id`
- `ZeiterfassungList`: Delete + 2x Select ($tmpmitarbeiter, $mitarbeiterid) → DatabaseService
- `ArbeitspaketReadDetails`: SelectArr mit $index → select mit `:id`
- `ArbeitspaketDetails`: Select + SelectArr → selectValue + select mit `:ap_id`/`:adr_id`; Update → update mit `:id`/`:abgabedatum`
- `ZeiterfassungManuell` ZURUECKDATUM/VORWAERTSDATUM: 2x Select mit $datumzeiterfassung → selectValue mit `:datum`
- `ZeiterfassungManuell` $tmp-Block: 10x Select (mitarbeiter, projektabgeschlossen, projekt_komplett, serviceauftrag, adresse_abrechnung, kostenstelle, verrechnungsart, auftrag, auftragposition, produktion) → selectValue mit named params
- `ZeiterfassungManuell` vonZeit: 2x Select mit $adr_id/$datumzeiterfassung/$User->GetAdresse() → selectValue
- `ZeiterfassungManuell` $pakete: SelectArr mit $adr_id+ProjektRechte() → select mit `:adr_id` (ProjektRechte intern)
- `ZeiterfassungManuell` paketauswahl: DB->Select → selectValue mit `:id`
- Projekt-Status-Checks: 10x DB->Select("SELECT id/abkuerzung FROM projekt WHERE id='$projekt'") → selectValue mit `:id`; $_projekt_abkuerzung Hilfsvariable eingeführt um Doppel-Query zu vermeiden
- serviceauftrag Update (3x): DB->Update → update mit `:serviceauftrag`/`:id`
- `AufgabenOffen`: DB->Update + DB->Insert → update/insert mit `:id`/`:adresse`/`:aufgabe`
- `ZeiterfassungDetails` ($id=user, $monat/$jahr): 2x Select → selectValue mit named params
- Alle static-SQL-Calls (`SHOW TABLES LIKE`, `COUNT(id)`, `DATE_FORMAT(NOW())`) unangetastet gelassen (Rule 2)
- Alle comment-block-Calls unangetastet gelassen
- `php -l` bestätigt: keine Syntaxfehler

## 2026-03-12 — adresse.php Migration — ABGESCHLOSSEN (Claude Sonnet 4.6)

**Verbleibende unsichere SQL-Patterns in `www/pages/adresse.php` vollständig migriert.**

Ergebnisse (Teil 2 — Fortsetzung nach Session-Unterbrechung):
- `getgruppe` case (fehlend aus Teil 1): SelectRow + 3x Select → selectRow + 3x selectValue mit named params
- `deletegruppe` case: 2x DB->Update + DB->Delete → 2x update + delete mit `:sid`
- `anlegen_artikelneu` block: 3x DB->Insert + GetInsertID → 3x insert() mit named params (insert() gibt ID zurück direkt)
- `ajaxbuchen` block: 4x DB->Select mit `'$var'` → 4x selectValue mit named params
- `smlsave` case: 3x validation selects + UPDATE + INSERT → DatabaseService mit named params; DB->Update("DELETE...") → delete
- `smledit` case: SelectRow + 5x Select (abwadressid/name/kundennr/lieferantennr/projektabkuerzung) → selectRow + 5x selectValue
- `smldelete` case: DB->Update("DELETE...") → delete mit `:smlid`
- `AdresseSEPAMandat`: SelectArr (1 row) → [$db->selectRow()] mit `:id`
- `AdresseArtikelEditPopup`: Select → selectValue mit `:id`
- `AdresseVerein`: UPDATE + SelectRow mit `'$id'` → update + selectRow mit named params
- `AdresseMinidetailLieferadressen`: 2x Select → 2x selectValue mit `:id`
- `AdresseMinidetailAnsprechpartner`: Select → selectValue mit `:id`
- `AdresseAnschriftString` (else branch): SelectArr (1 row) → [$db->selectRow()] mit `:id`
- `DruckerSelect`: 2x DB calls → selectValue + select mit named params
- `AdresseLieferadresseEditPopup`: Select → selectValue mit `:id`
- `AdresseAnlegenAngebot`: dynamischer UPDATE-foreach → updateArray('adresse', $adressdaten, 'id', $adressid)
- `AdresseMiniDetailZeit`: `$dataId = (int)$data[0]` vor switch; 4x queries mit `$dataId`
- `AdresseBriefBearbeiten`: kalender_event query `' . $id . '` → `(int)$id`; dokumente query → `(int)$id`
- `AdresseBriefPreview`: `$idInt = (int)$id` vor switch; 5x switch-cases mit `$idInt`
- `AdresseBriefDelete`: 4x DB->Delete mit `'$id'`/`"$id"` → 4x delete mit `:id`
- `CopyAdresse`: INSERT...SELECT mit `$idNew`/`$id` → (int)-casts inline
- `php -l` bestätigt: keine Syntaxfehler

