---
last_updated: 2026-03-01T00:00:00+01:00
last_agent: Claude Sonnet 4.6
active_task: false
phase: 1
subtask: "SQL injection migration — www/lib/class.erpapi.php lines 1–13000"
progress: 100%
---

# Aktueller Stand

SQL injection migration (raw string interpolation → DatabaseService named params) abgeschlossen für:
- `www/lib/class.erpapi.php` (lines 1–13000)

`php -l` bestätigt: keine Syntaxfehler.

## Migrierte Calls — class.erpapi.php (lines 1–13000)

| Kontext | Vorher | Nachher |
|---|---|---|
| hook_navigation UPDATE | real_escape_string | named params |
| hook_navigation INSERT | real_escape_string | named params |
| Zahlungsweisetext zahlungdatum SELECT DATE_FORMAT | `$zahlungszieltage/$doctypeid` | named params + sprintf for interval |
| Zahlungsweisetext zahlungszielskontodatum | `$zahlungszieltageskonto/$doctypeid` | named params + sprintf |
| ParseVarsDocument lieferdatum/tatsaechlicheslieferdatum | 2x `'$tmpauftragid'` | combined selectRow |
| ParseVarsDocument bestellung check SelectArr | `'$id'` | `:bid` |
| ParseVarsDocument lieferscheine for rechnung | `'$id'` | `:id` |
| ParseVarsDocument lieferscheine[0]['id'] | `'$id'` | `:id` |
| ParseVarsDocument tmpAddr | `$result[0]['adresse']` | `:id` |
| ParseVarsDocument abweichende_rechnungsadresse SelectRow | `$result[0]['adresse']` | `:id` |
| ParseVarsDocument auftragsadresse SelectRow | `'$tmpauftragid'` | `:id` |
| ParseVarsDocument abweichende_rechnungsadresse Select (2x) | `$result[0]['adresse']` | `:_abwid` |
| ParseVarsDocument belegnr from lieferschein | `$result[0][$key_i]` | `:_lsid` |
| ParseVarsDocument belege_check rechnung | 2x `'$id'` | `:id` |
| ParseVarsDocument belege_check lieferschein | 2x `'$id'` | `:id` |
| ParseVarsDocument belege_check retoure | 2x `'$id'` | `:id` |
| ParseVarsDocument belege_check gutschrift | `'$id'` | `:id` |
| ParseVarsDocument belege_arr SelectRow | `"$table"/'$tableid'` | sprintf int-cast |
| ParseVarsDocument adresse_arr | `$result[0]['adresse']` | `:id` |
| ParseVarsDocument projekt_arr | `'$projekt'` | `:id` |
| CheckVertrieb shop SELECT | `'$id'` | `:id` |
| CheckVertrieb adresse SELECT | `$module/$id` | validateIdentifier+named param |
| CheckVertrieb vertrieb/vertrieb_name SELECT (2x) | `'$adresse'/'$vertrieb'` | `:id` |
| CheckVertrieb checktmp SELECT | `$module/$id` | validateIdentifier+named param |
| CheckVertrieb vertrieb_name (else branch) | `'$vertrieb'` | `:id` |
| CheckBuchhaltung SELECT + 2 UPDATEs | `$module/$id` | validateIdentifier+named params |
| GetArtikelStandardlager send_id=true (3 queries) | `$artikel/$standardlager` | `:id` |
| GetArtikelStandardlager send_id=false (3 queries) | `$artikel/$standardlager` | `:id` |
| WikiPage SELECT content | `'$page'` | `:name` |
| GetNavigationSelect shopnavigation parent=0 | `'$shop'` | `:shop` |
| GetNavigationSelect shopnavigation unterpunkte | `$punkt["id"]/'$shop'` | `:parent/:shop` |
| CalculateNavigation userrights | `GetID()` | `:uid` |
| startseite user SELECT | `GetID()` | `:id` |
| GetStandardProjekt firma | `GetFirma()` | `:id` |
| Standardprojekt SELECT + UPDATE | `$table/$id/$standardprojekt` | validateIdentifier+named params |
| RemoveFile prozessstarter SELECT | real_escape_string | `:parameter` |
| fixDatabaseNullIDs UPDATE id=0 | `'$maxid'` | sprintf int |
| fixDatabaseNullIDs SELECT doppelte | `'$val[id]'` | sprintf int |
| fixDatabaseNullIDs UPDATE doppelte | `'$maxid'/'$val[id]'` | sprintf int |
| StartChangeLog SelectRow | `"$table"/'$tableid'` | sprintf int |
| WriteChangeLog SelectRow | `$Changelog[table/tableid]` | sprintf int |
| WriteChangeLog INSERT change_log | real_escape_string (5 fields) | named params |
| WriteChangeLog INSERT change_log_field | `'$change_log'` + real_escape (3 fields) | named params |
| firmendaten_werte SELECT id | real_escape_string | `:name` |
| ArtikelAnzahlReserviert (both branches) | `'$artikel'` | `:id` |
| explodiert preproducednummer SELECT | `$preproducedpartlist` raw | `:id` |
| explodiert UPDATE auftrag_position artikel/nummer | `$preproducedpartlist/'$preproducednummer'` | named params |
| explodiert UPDATE auftrag_position menge-partlistsellable | `$partlistsellable/$sort/$artikel_position_id` | named params |
| explodiert UPDATE waehrung | `'$explodiert_id'` | `:id` |
| explodiert UPDATE einkaufspreis | `'$explodiert_id'` | `:id` |
| explodiert UPDATE ausblenden_im_pdf | `'$explodiert_id'` | `:id` |
| explodiert UPDATE explodiert_parent/sort | `'$artikel_position_id'/'$sort'/'$explodiert_id'` | named params |
| ReplaceANABRELSGSBE SELECT belegnr | `"$table"/'$id'` | validateIdentifier+`:id` |
| ReplaceANABRELSGSBE SELECT id by belegnr | `"$table"/'$tmp'` | validateIdentifier+`:belegnr` |
| shopexport_artikel SelectArr with name filter | real_escape_string | named params with array_filter |

## Nächster Schritt
- SQL injection migration in `www/lib/class.erpapi.php` lines 13000–26000 (further chunks)
- Oder andere Dateien mit raw SQL injection patterns
