---
last_updated: 2026-02-26T14:00:00+01:00
last_agent: Claude Sonnet 4.6
active_task: false
phase: 1
subtask: "SQL Injection Migration — angebot.php + produktion.php top-15 patterns"
progress: 100%
---

# Aktueller Stand

Phase 1 SQL-Injection-Migration für `www/pages/angebot.php` und `www/pages/produktion.php` abgeschlossen.
Zuvor ebenfalls: `www/pages/welcome.php` und `www/pages/supportapp.php`.

## Letzter Schritt

Kritische SQL-Injection-Patterns in beiden Dateien auf `DatabaseService` prepared statements migriert.
`php -l` bestätigt: keine Syntaxfehler in beiden Dateien.

## Migrierte Patterns — welcome.php

| Methode | Pattern | Fix |
|---|---|---|
| `WelcomeAddFav` (loop) | `SELECT ... WHERE user = '$u'` (eigenlinks) | `selectValue()` mit `(int)$u` |
| `WelcomeAddFav` (loop) | `SelectRow ... WHERE name = 'welcome_linklink".$i."' AND user = '$u'` | `selectRow()` mit `?` params |
| `WelcomeAddFav` (loop) | `SELECT uk.id ... WHERE name = 'welcome_linkname".$i."' AND user = '$u'` | `selectValue()` mit `?` |
| `WelcomeAddFav` (else) | `SELECT/UPDATE/INSERT userkonfiguration WHERE user = '$u'` (3 queries) | `selectValue()/update()/insert()` |
| `WelcomeAddFav` (else) | 2x `INSERT userkonfiguration ... VALUES ('$u', ...)` | `insert()` mit `(int)$u` |
| `WelcomeAddFav` (index<=8) | `UPDATE/INSERT userkonfiguration` with `$url/$bezeichnung/$check2/$check3` | `update()/insert()` with `?` |
| `WelcomeAdapterbox` | `DELETE FROM adapterbox_log WHERE ip='$ip' OR seriennummer='$serial'` | `delete()` mit `?` |
| `WelcomeAdapterbox` | `INSERT INTO adapterbox_log ... VALUES ('',NOW(),'$ip',...,'$serial','device')` | `insert()` mit `?` |
| `WelcomeAdapterbox` (zebra) | `INSERT INTO drucker ... VALUES ('','2','adapterbox','$serial',...)` | `insert()` mit `?`; return used as `$tmpid` |
| `WelcomeAdapterbox` (device_jobs) | `INSERT INTO device_jobs ... VALUES ('',NOW(),'000000000','$serial','$job',...)` | `insert()` mit `?` |
| `WelcomeAdapterbox` | `UPDATE drucker SET adapterboxip='$ip' WHERE adapterboxseriennummer='$serial'` | `update()` mit `?` |
| `WelcomeStart` | `SELECT * FROM aufgabe WHERE adresse='...' OR initiator='...'` (GetAdresse) | `select()` mit `(int)` |
| `WelcomeStart` | `SELECT name FROM adresse WHERE id='".$tmp[$i]['initiator']."'` | `selectValue()` mit `(int)` |
| `WelcomeStart` | `SELECT * FROM aufgabe WHERE initiator='...' AND adresse!='...'` (GetAdresse) | `select()` mit `(int)` |
| `WelcomeStart` | `SELECT name FROM adresse WHERE id='".$tmp[$i]['adresse']."'` | `selectValue()` mit `(int)` |
| `WelcomeAddPinwand` | `INSERT INTO pinwand ... VALUES ('','$name','$user')` | `insert()` mit `?`; return used as `$pinwand` |
| `WelcomeAddPinwand` | `INSERT INTO pinwand_user ... VALUES ('$pinwand','".$personen[$i]."')` | `insert()` mit `?` |
| `WelcomeAddNote` | `SELECT MAX(note_z) FROM aufgabe WHERE adresse='...'` | `selectValue()` mit `(int)` |
| `WelcomeAddNote` | `SELECT id FROM aufgabe WHERE adresse='...' AND id=...` | `selectValue()` mit `(int)` |
| `WelcomeAddNote` | Large UPDATE aufgabe with `$pinwand/$color/$max_z/$note_x/$note_y/$beschreibung/$id` | `update()` mit 7 `?` |
| `WelcomeDelNote` | `DELETE FROM aufgabe WHERE id='$id'` | `delete()` mit `(int)$id` |
| `WelcomeMoveNote` | `UPDATE aufgabe SET note_x='$x',note_y='$y',note_z='$z' WHERE id='$id'` | `update()` mit `?` |
| `WelcomePinwand` (resize) | `UPDATE aufgabe SET note_w=...,note_h=... WHERE id='$id'` | `update()` mit `?` |
| `WelcomePinwand` (get) | 2x `SELECT ... FROM aufgabe WHERE id='$id'` | `selectValue()` mit `(int)` |
| `WelcomePinwand` (save) | `UPDATE aufgabe SET beschreibung=...,note_color=... WHERE id='$id'` | `update()` mit `?` |
| `WelcomePinwand` (save else) | `SELECT MAX(note_z) FROM aufgabe WHERE adresse='...'` | `selectValue()` mit `(int)` |
| `WelcomePinwand` (save else) | Large UPDATE aufgabe with note coords, pinwand, id | `update()` mit `?` |
| `WelcomePinwand` (pinwand list) | `SELECT * FROM aufgabe WHERE adresse='...'...` (3 queries incl. pinwand/pinwand_user auth) | `select()/selectValue()` mit `?` |

## Migrierte Patterns — supportapp.php

| Methode | Pattern | Fix |
|---|---|---|
| TableSearch callback `supportapp_zeiterfassung` | `SELECT name/id FROM adresse WHERE kundennummer='$kundennr'` (2 queries) | `selectValue()` mit `?` |
| TableSearch callback `supportapp_zeiterfassung` | `SELECT name/kundennummer FROM adresse WHERE id='$kundenid'` (2 queries) | `selectValue()` mit `?` |
| `supportappEinstellungen` (artikelhinzufuegen) | `SELECT id FROM artikel WHERE nummer ='...'` | `selectValue()` mit `?` |
| `supportappEinstellungen` (artikelhinzufuegen) | `SELECT id FROM supportapp_artikel WHERE artikel='$artikelid' AND typ='$typ'` | `selectValue()` mit `?` |
| `supportappEinstellungen` (artikelhinzufuegen) | `INSERT INTO supportapp_artikel (artikel, typ) VALUES ('$artikelid','$typ')` | `insert()` mit `?` |
| `supportappEinstellungen` (deleteartikel) | `DELETE FROM supportapp_artikel WHERE id='$id'` | `delete()` mit `(int)` |
| `supportappEinstellungen` (vorlagespeichern) | `SELECT id FROM supportapp_vorlagen WHERE bezeichnung = '$bezeichnung'` | `selectValue()` mit `?` |
| `supportappEinstellungen` (vorlagespeichern) | `INSERT/UPDATE supportapp_vorlagen` with `$bezeichnung/$taetigkeit/$beschreibung/$id` | `insert()/update()` mit `?` |
| `supportappEinstellungen` (delete) | `DELETE FROM supportapp_vorlagen WHERE id = '$id'` | `delete()` mit `(int)` |
| `supportappEinstellungen` (editvorlage) | `SELECT * FROM supportapp_vorlagen WHERE id = '$id'` | `select()` mit `(int)` |
| `supportappEinstellungen` (schritteerrechnen) | Large SELECT auftrag_position with `$kundenid` | `select()` mit `?` |
| `supportappEinstellungen` (schritteerrechnen) | `SELECT * FROM supportapp_schritte WHERE gruppe = ...` | `select()` mit `(int)` |
| `supportappEinstellungen` (schritteerrechnen) | `SELECT id FROM supportapp_auftrag_check WHERE auftragposition='...' AND gruppe='...' ...` | `selectValue()` mit `?` |
| `supportappEinstellungen` (schritteerrechnen) | `INSERT INTO supportapp_auftrag_check ... VALUES ('$kundenid',...)` | `insert()` mit `?` |
| `supportappList` (einrichtungspeichern) | `SELECT id FROM adresse WHERE kundennummer='$kundennummer'` | `selectValue()` mit `?` |
| `supportappList` (einrichtungspeichern) | `SELECT id FROM adresse WHERE mitarbeiternummer='$mitarbeiternummer'` | `selectValue()` mit `?` |
| `supportappList` (einrichtungspeichern) | Large `UPDATE supportapp ... WHERE id='$einrichtungid'` (9 cols) | `update()` mit 10 `?` |
| `supportappList` (einrichtungspeichern) | Large `INSERT INTO supportapp ... VALUES ('$kundenid',...)` (9 cols) | `insert()` mit 9 `?` |
| `supportappList` (geteinrichtung) | `SelectArr ... WHERE s.id = '$einrichtungid'` | `selectRow()` mit `(int)` |
| `supportappAuftrag` (changeschritt) | `SELECT bezeichnung FROM supportapp_schritte WHERE id = '".$gs[2]."'` | `selectValue()` mit `(int)` |
| `supportappAuftrag` (changeschritt) | `SELECT/UPDATE/INSERT supportapp_auftrag_check` with `$adressid/$gs[]/$checked` | `selectValue()/update()/insert()` mit `?` |
| `supportappAuftrag` (goto) | `SELECT id FROM adresse WHERE kundennummer = '...'` | `selectValue()` mit `?` |
| `supportappAuftrag` (stop) | `SELECT CONCAT(p.abkuerzung,...) FROM projekt ... WHERE a.id = '$kundenid'` | `selectValue()` mit `(int)` |
| `supportappAuftrag` (save) | `SELECT id FROM adresse WHERE mitarbeiternummer='...'` | `selectValue()` mit `?` |
| `supportappAuftrag` (save) | `SELECT id FROM projekt WHERE abkuerzung='...'` | `selectValue()` mit `?` |
| `supportappAuftrag` (save) | Large `INSERT INTO zeiterfassung ... VALUES ('Arbeit','$bearbeiteradresse',...)` (32 cols) | `insert()` mit 7 `?` |
| `supportappAuftrag` (notiz) | `UPDATE adresse SET sonstiges = '$sonstiges' WHERE id = '$id'` | `update()` mit `?` |
| `supportappAuftrag` (getmail) | 2x `SELECT kundennummer/name FROM adresse WHERE id='$empfanengerid'` | `selectValue()` mit `(int)` |
| `supportappAuftrag` (holevorlage) | `SelectArr ... FROM supportapp_vorlagen WHERE id = '$vorlageid'` | `select()` mit `(int)` |
| `supportappAuftrag` (main) | `SELECT name/id FROM adresse WHERE kundennummer='$kundennr'` (2 queries) | `selectValue()` mit `?` |
| `supportappAuftrag` (main) | `SELECT name/kundennummer FROM adresse WHERE id='$kundenid'` (2 queries) | `selectValue()` mit `(int)` |
| `supportappAuftrag` (main) | `SELECT freifeld5/freifeld6 FROM adresse WHERE id='$kundenid'` | `selectValue()` mit `(int)` |
| `supportappAuftrag` (main) | 4x `SELECT id FROM abrechnungsartikel WHERE ... AND adresse='$kundenid'` | `selectValue()` mit `(int)` |
| `supportappAuftrag` (main) | `SELECT freifeld9 / COUNT / sonstiges FROM adresse WHERE id='$kundenid'` (3 queries) | `selectValue()` mit `(int)` |
| `supportappAuftrag` (main) | Large SELECT auftrag_position + nested einzelschritte + vorhanden + INSERT loop | `select()/selectValue()/insert()` mit `?` |
| `supportappAuftrag` (main) | Large SELECT auftrag a + auftrag_position WHERE adresse='$kundenid' | `select()` mit `(int)` |
| `supportappAuftrag` (main) | UNION SELECT angebot/auftrag beleges WHERE adresse='$kundenid' | `select()` mit 2x `(int)` |

## Nächster Schritt
- Weitere Dateien mit kritischen SQL-Injections angehen (z.B. `www/pages/rechnung.php`, `www/pages/lieferschein.php`)
