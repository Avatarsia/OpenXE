#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""Patch script for class.erpapi.php lines 20000-30000 SQL injection migration."""

import sys

filepath = r"C:/Users/3D Partner/Documents/openxe_rework/OpenXE/www/lib/class.erpapi.php"

with open(filepath, 'r', encoding='utf-8') as f:
    content = f.read()

patches_applied = 0
patches_failed = 0

def apply(name, old, new, count=1):
    global content, patches_applied, patches_failed
    if old in content:
        content = content.replace(old, new, count)
        print(f"  OK: {name}")
        patches_applied += 1
    else:
        print(f"  MISS: {name}")
        patches_failed += 1

# =========================================================
# LagerEinlagern (line ~20184)
# =========================================================
apply("LagerEinlagern doctype validateIdentifier",
    '      $adresse = $this->app->DB->Select("SELECT adresse FROM $doctype WHERE id = \'$doctypeid\' LIMIT 1");\n    }\n    //$this->LagerArtikelZusammenfassen($artikel);\n    $vpe = \'einzeln\';\n    $username = \'Import\';\n    if ($importer != 1) {\n      $username = $this->app->DB->real_escape_string($this->app->User->GetName());\n    }\n\n    if ($menge > 0 && is_numeric($menge)) {\n      // inhalt buchen\n      $this->app->DB->Insert("INSERT INTO lager_platz_inhalt (id,lager_platz,artikel,menge,vpe,bearbeiter,bestellung,projekt,firma,logdatei)\n          VALUES (\'\',\'$regal\',\'$artikel\',\'$menge\',\'$vpe\',\'" . $username . "\',\'\',\'$projekt\',\'\',NOW())");\n      $insid = $this->app->DB->GetInsertID();\n      $bestand = $this->ArtikelImLagerPlatz($artikel, $regal);\n      // Bewegung\n      if ($vpeid) {\n        $grund .= $this->GetVPEBezeichnung($vpeid);\n      }\n      $this->app->DB->Insert("INSERT INTO lager_bewegung (id,lager_platz, artikel, menge,vpe, eingang,zeit,referenz, bearbeiter,projekt,firma,logdatei,bestand,paketannahme,doctype,doctypeid, permanenteinventur, adresse)\n          VALUES(\'\',\'$regal\',\'$artikel\',\'$menge\',\'$vpe\',\'1\',NOW(),\'$grund\',\'" . $username . "\',\'$projekt\',\'\',NOW(),\'$bestand\',\'$paketannahme\',\'$doctype\',\'$doctypeid\', \'$permanenteinventur\',\'$adresse\')");\n      if ($vpeid)\n        $this->app->DB->Update("UPDATE lager_platz_inhalt SET lager_platz_vpe = \'$vpeid\' WHERE id = \'$insid\' LIMIT 1");\n      $this->LagerArtikelZusammenfassen($artikel, $regal);\n      $this->app->DB->Update("UPDATE artikel SET cache_lagerplatzinhaltmenge = -999, `laststorage_changed` = NOW() WHERE id = \'$artikel\' LIMIT 1");',
    "      $safeDoctype = $this->app->DatabaseService->validateIdentifier($doctype);\n      $adresse = $this->app->DatabaseService->selectValue(\"SELECT adresse FROM `{$safeDoctype}` WHERE id = :id LIMIT 1\", ['id' => $doctypeid]);\n    }\n    //$this->LagerArtikelZusammenfassen($artikel);\n    $vpe = 'einzeln';\n    $username = 'Import';\n    if ($importer != 1) {\n      $username = $this->app->DB->real_escape_string($this->app->User->GetName());\n    }\n\n    if ($menge > 0 && is_numeric($menge)) {\n      // inhalt buchen\n      $this->app->DatabaseService->execute(\n        \"INSERT INTO lager_platz_inhalt (id,lager_platz,artikel,menge,vpe,bearbeiter,bestellung,projekt,firma,logdatei)\n          VALUES ('', :regal, :artikel, :menge, :vpe, :username, '', :projekt, '', NOW())\",\n        ['regal' => $regal, 'artikel' => $artikel, 'menge' => $menge, 'vpe' => $vpe, 'username' => $username, 'projekt' => $projekt]\n      );\n      $insid = $this->app->DB->GetInsertID();\n      $bestand = $this->ArtikelImLagerPlatz($artikel, $regal);\n      // Bewegung\n      if ($vpeid) {\n        $grund .= $this->GetVPEBezeichnung($vpeid);\n      }\n      $this->app->DatabaseService->execute(\n        \"INSERT INTO lager_bewegung (id,lager_platz, artikel, menge,vpe, eingang,zeit,referenz, bearbeiter,projekt,firma,logdatei,bestand,paketannahme,doctype,doctypeid, permanenteinventur, adresse)\n          VALUES('', :regal, :artikel, :menge, :vpe, '1', NOW(), :grund, :username, :projekt, '', NOW(), :bestand, :paketannahme, :doctype, :doctypeid, :permanenteinventur, :adresse)\",\n        ['regal' => $regal, 'artikel' => $artikel, 'menge' => $menge, 'vpe' => $vpe, 'grund' => $grund, 'username' => $username, 'projekt' => $projekt, 'bestand' => $bestand, 'paketannahme' => $paketannahme, 'doctype' => $doctype, 'doctypeid' => $doctypeid, 'permanenteinventur' => $permanenteinventur, 'adresse' => $adresse]\n      );\n      if ($vpeid)\n        $this->app->DatabaseService->execute(\"UPDATE lager_platz_inhalt SET lager_platz_vpe = :vpeid WHERE id = :id LIMIT 1\", ['vpeid' => $vpeid, 'id' => $insid]);\n      $this->LagerArtikelZusammenfassen($artikel, $regal);\n      $this->app->DatabaseService->execute(\"UPDATE artikel SET cache_lagerplatzinhaltmenge = -999, `laststorage_changed` = NOW() WHERE id = :artikel LIMIT 1\", ['artikel' => $artikel]);"
)

# =========================================================
# CreateLagerPlatzInhaltVPE
# =========================================================
apply("CreateLagerPlatzInhaltVPE SELECT+INSERT",
    "    $check = $this->app->DB->Select(\"SELECT id FROM `lager_platz_vpe` WHERE artikel = '$artikel' AND menge = '$menge' AND gewicht = '$gewicht' AND breite = '$breite' AND \n    hoehe = '$hoehe' \" . ($menge2 <= 1 ? \" AND (menge2 = '0' OR menge2 = 1) \" : \" AND menge2 = '$menge2' \") . \" AND gewicht2 = '$gewicht' AND breite2 = '$breite' AND \n    hoehe2 = '$hoehe' LIMIT 1\n    \");\n    if ($check)\n      return $check;\n    $this->app->DB->Insert(\"INSERT INTO `lager_platz_vpe` (artikel, menge, gewicht, laenge, breite, hoehe, menge2, gewicht2, laenge2, breite2, hoehe2 )\n    VALUES ('$artikel', '$menge', '$gewicht', '$laenge', '$breite', '$hoehe', '$menge2', '$gewicht2', '$laenge2', '$breite2', '$hoehe2')\n    \");",
    "    $check = $this->app->DatabaseService->selectValue(\n      \"SELECT id FROM `lager_platz_vpe` WHERE artikel = :artikel AND menge = :menge AND gewicht = :gewicht AND breite = :breite AND hoehe = :hoehe\"\n      . ($menge2 <= 1 ? \" AND (menge2 = '0' OR menge2 = 1) \" : \" AND menge2 = :menge2 \")\n      . \" AND gewicht2 = :gewicht AND breite2 = :breite AND hoehe2 = :hoehe LIMIT 1\",\n      array_merge(\n        ['artikel' => $artikel, 'menge' => $menge, 'gewicht' => $gewicht, 'breite' => $breite, 'hoehe' => $hoehe],\n        $menge2 <= 1 ? [] : ['menge2' => $menge2]\n      )\n    );\n    if ($check)\n      return $check;\n    $this->app->DatabaseService->execute(\n      \"INSERT INTO `lager_platz_vpe` (artikel, menge, gewicht, laenge, breite, hoehe, menge2, gewicht2, laenge2, breite2, hoehe2)\n      VALUES (:artikel, :menge, :gewicht, :laenge, :breite, :hoehe, :menge2, :gewicht2, :laenge2, :breite2, :hoehe2)\",\n      ['artikel' => $artikel, 'menge' => $menge, 'gewicht' => $gewicht, 'laenge' => $laenge, 'breite' => $breite, 'hoehe' => $hoehe, 'menge2' => $menge2, 'gewicht2' => $gewicht2, 'laenge2' => $laenge2, 'breite2' => $breite2, 'hoehe2' => $hoehe2]\n    );"
)

# =========================================================
# LagerArtikelZusammenfassen
# =========================================================
apply("LagerArtikelZusammenfassen SelectArr",
    "      $result = $this->app->DB->SelectArr(\"SELECT lager_platz,SUM(menge) as gesamt,projekt,max(firma) as firma,max(inventur) as inventur, min(id) as minid,lager_platz_vpe FROM lager_platz_inhalt WHERE artikel='$artikel' \" . ($regal ? \" AND lager_platz = '$regal' \" : '') . \" GROUP by lager_platz,lager_platz_vpe  having count(id) > 1\");",
    "      $lagerSql = \"SELECT lager_platz,SUM(menge) as gesamt,projekt,max(firma) as firma,max(inventur) as inventur, min(id) as minid,lager_platz_vpe FROM lager_platz_inhalt WHERE artikel=:artikel\" . ($regal ? \" AND lager_platz = :regal\" : '') . \" GROUP by lager_platz,lager_platz_vpe HAVING count(id) > 1\";\n      $lagerParams = $regal ? ['artikel' => $artikel, 'regal' => $regal] : ['artikel' => $artikel];\n      $result = $this->app->DatabaseService->select($lagerSql, $lagerParams);"
)

apply("LagerArtikelZusammenfassen DELETE+INSERT",
    "          $this->app->DB->Delete(\"DELETE FROM lager_platz_inhalt WHERE  artikel='$artikel' AND lager_platz = '\" . $result[$i]['lager_platz'] . \"' AND lager_platz_vpe = '\" . $result[$i]['lager_platz_vpe'] . \"' \");\n          if (empty($result[$i]['lager_platz'])) {\n            continue;\n          }\n          $this->app->DB->Insert(\"INSERT INTO lager_platz_inhalt (id,lager_platz,artikel,menge,projekt,firma,inventur,lager_platz_vpe) VALUES ('\" . $result[$i]['minid'] . \"','\" . $result[$i]['lager_platz'] . \"','$artikel',\n            '\" . $result[$i]['gesamt'] . \"','\" . $result[$i]['projekt'] . \"','\" . $result[$i]['firma'] . \"',\" . (is_null($result[$i]['inventur']) ? \"NULL\" : \"'\" . $result[$i]['inventur'] . \"'\") . \",'\" . $result[$i]['lager_platz_vpe'] . \"')\");",
    "          $this->app->DatabaseService->execute(\"DELETE FROM lager_platz_inhalt WHERE artikel=:artikel AND lager_platz=:lager_platz AND lager_platz_vpe=:lager_platz_vpe\", ['artikel' => $artikel, 'lager_platz' => $result[$i]['lager_platz'], 'lager_platz_vpe' => $result[$i]['lager_platz_vpe']]);\n          if (empty($result[$i]['lager_platz'])) {\n            continue;\n          }\n          $this->app->DatabaseService->execute(\n            \"INSERT INTO lager_platz_inhalt (id,lager_platz,artikel,menge,projekt,firma,inventur,lager_platz_vpe) VALUES (:id, :lager_platz, :artikel, :menge, :projekt, :firma, :inventur, :lager_platz_vpe)\",\n            ['id' => $result[$i]['minid'], 'lager_platz' => $result[$i]['lager_platz'], 'artikel' => $artikel, 'menge' => $result[$i]['gesamt'], 'projekt' => $result[$i]['projekt'], 'firma' => $result[$i]['firma'], 'inventur' => $result[$i]['inventur'], 'lager_platz_vpe' => $result[$i]['lager_platz_vpe']]\n          );"
)

apply("LagerArtikelZusammenfassen verbrauchslager SELECT",
    "      if ($regal && $this->app->DB->Select(\"SELECT verbrauchslager FROM lager_platz WHERE id = '$regal' LIMIT 1\")) {",
    "      if ($regal && $this->app->DatabaseService->selectValue(\"SELECT verbrauchslager FROM lager_platz WHERE id = :regal LIMIT 1\", ['regal' => $regal])) {"
)

# =========================================================
# LagerFreieMenge
# =========================================================
apply("LagerFreieMenge lager_reserviert {$artikel}",
    "    $artikel_reserviert = (float) $this->app->DB->Select(\n      \"SELECT SUM(lr.menge) \n        FROM `lager_reserviert` AS `lr`\n        WHERE lr.artikel = '{$artikel}' \n        AND (lr.datum >= NOW() OR lr.datum='0000-00-00')\"\n    );",
    "    $artikel_reserviert = (float) $this->app->DatabaseService->selectValue(\n      \"SELECT SUM(lr.menge) FROM `lager_reserviert` AS `lr` WHERE lr.artikel = :artikel AND (lr.datum >= NOW() OR lr.datum='0000-00-00')\",\n      ['artikel' => $artikel]\n    );"
)

# =========================================================
# ArtikelAnzahlLagerStueckliste
# =========================================================
apply("ArtikelAnzahlLagerStueckliste SelectArr stueckliste",
    "    $artikel = $this->app->DB->SelectArr(\"SELECT s.* FROM stueckliste s INNER JOIN artikel a ON s.artikel = a.id WHERE s.stuecklistevonartikel='$id' AND s.art!='it' AND a.lagerartikel = 1 and (a.geloescht = 0 OR a.geloescht IS NULL)\");",
    "    $artikel = $this->app->DatabaseService->select(\"SELECT s.* FROM stueckliste s INNER JOIN artikel a ON s.artikel = a.id WHERE s.stuecklistevonartikel=:id AND s.art!='it' AND a.lagerartikel = 1 AND (a.geloescht = 0 OR a.geloescht IS NULL)\", ['id' => $id]);"
)

apply("ArtikelAnzahlLagerStueckliste mengeimlage+mengereserviert",
    "      $mengeimlage = (float) $this->app->DB->Select(\"SELECT SUM(lpi.menge) FROM lager_platz_inhalt lpi INNER JOIN lager_platz lp ON lp.id=lpi.lager_platz \n        WHERE lpi.artikel='$artikelid' AND lp.sperrlager!=1\");\n      $mengereserviert = (float) $this->app->DB->Select(\"SELECT ifnull(SUM(menge),0) FROM lager_reserviert WHERE artikel='$artikelid'\");",
    "      $mengeimlage = (float) $this->app->DatabaseService->selectValue(\"SELECT SUM(lpi.menge) FROM lager_platz_inhalt lpi INNER JOIN lager_platz lp ON lp.id=lpi.lager_platz WHERE lpi.artikel=:artikel AND lp.sperrlager!=1\", ['artikel' => $artikelid]);\n      $mengereserviert = (float) $this->app->DatabaseService->selectValue(\"SELECT ifnull(SUM(menge),0) FROM lager_reserviert WHERE artikel=:artikel\", ['artikel' => $artikelid]);"
)

apply("ArtikelAnzahlLagerStueckliste mengeimlagerprojekt+mengereserviertprojekt",
    "        $mengeimlagerprojekt = (float) $this->app->DB->Select(\"SELECT SUM(lpi.menge) FROM lager_platz_inhalt lpi INNER JOIN lager_platz lp ON lp.id=lpi.lager_platz  INNER JOIN lager `lag` ON lp.lager = `lag`.id AND `lag`.projekt = '$projektlager' AND `lag`.geloescht <> 1\n          WHERE lpi.artikel='$artikelid' AND lp.sperrlager!=1\");\n        $mengereserviertprojekt = (float) $this->app->DB->Select(\"SELECT ifnull(SUM(r.menge),0) FROM lager_reserviert r INNER JOIN auftrag a ON r.parameter = a.id AND r.objekt = 'auftrag' AND a.status = 'freigegeben' WHERE r.artikel='$artikelid' AND a.projekt = '$projektlager' \");",
    "        $mengeimlagerprojekt = (float) $this->app->DatabaseService->selectValue(\"SELECT SUM(lpi.menge) FROM lager_platz_inhalt lpi INNER JOIN lager_platz lp ON lp.id=lpi.lager_platz INNER JOIN lager `lag` ON lp.lager = `lag`.id AND `lag`.projekt = :projektlager AND `lag`.geloescht <> 1 WHERE lpi.artikel=:artikel AND lp.sperrlager!=1\", ['projektlager' => $projektlager, 'artikel' => $artikelid]);\n        $mengereserviertprojekt = (float) $this->app->DatabaseService->selectValue(\"SELECT ifnull(SUM(r.menge),0) FROM lager_reserviert r INNER JOIN auftrag a ON r.parameter = a.id AND r.objekt = 'auftrag' AND a.status = 'freigegeben' WHERE r.artikel=:artikel AND a.projekt = :projektlager\", ['artikel' => $artikelid, 'projektlager' => $projektlager]);"
)

# =========================================================
# LagerCheck
# =========================================================
apply("LagerCheck projektlager lager_reserviert",
    "      $artikel_reserviert = round($this->app->DB->Select(\"SELECT SUM(menge) \n        FROM lager_reserviert WHERE artikel='\" . $artikel . \"' AND projekt='$projekt' AND (datum>=NOW() OR datum='0000-00-00')\"), $this->GetLagerNachkommastellen());",
    "      $artikel_reserviert = round($this->app->DatabaseService->selectValue(\"SELECT SUM(menge) FROM lager_reserviert WHERE artikel=:artikel AND projekt=:projekt AND (datum>=NOW() OR datum='0000-00-00')\", ['artikel' => $artikel, 'projekt' => $projekt]), $this->GetLagerNachkommastellen());"
)

apply("LagerCheck normal lager_reserviert",
    "      $artikel_reserviert = round($this->app->DB->Select(\"SELECT SUM(menge) FROM lager_reserviert WHERE artikel='\" . $artikel . \"' AND (datum>=NOW() OR datum='0000-00-00')\"), $this->GetLagerNachkommastellen());",
    "      $artikel_reserviert = round($this->app->DatabaseService->selectValue(\"SELECT SUM(menge) FROM lager_reserviert WHERE artikel=:artikel AND (datum>=NOW() OR datum='0000-00-00')\", ['artikel' => $artikel]), $this->GetLagerNachkommastellen());"
)

apply("LagerCheck artikel_fuer_adresse_reserviert objekt branch",
    "      $artikel_fuer_adresse_reserviert = round($this->app->DB->Select(\"SELECT SUM(menge) \n        FROM lager_reserviert\n          WHERE artikel='\" . $artikel . \"' \" . ($adresse > 0 ? \" AND adresse='$adresse'\" : '') . \" AND ((objekt='$objekt' AND parameter='$parameter')\" . ($objekt === 'lieferschein' && $auftrag > 0 ? \" OR (objekt='auftrag' AND parameter = '$auftrag') \" : '') . \") AND (datum>=NOW() OR datum='0000-00-00')\"), $this->GetLagerNachkommastellen());",
    "      $reserviertSql = \"SELECT SUM(menge) FROM lager_reserviert WHERE artikel=:artikel\";\n      $reserviertParams = ['artikel' => $artikel];\n      if ($adresse > 0) { $reserviertSql .= \" AND adresse=:adresse\"; $reserviertParams['adresse'] = $adresse; }\n      $reserviertSql .= \" AND ((objekt=:objekt AND parameter=:parameter)\";\n      $reserviertParams['objekt'] = $objekt;\n      $reserviertParams['parameter'] = $parameter;\n      if ($objekt === 'lieferschein' && $auftrag > 0) { $reserviertSql .= \" OR (objekt='auftrag' AND parameter=:auftrag)\"; $reserviertParams['auftrag'] = $auftrag; }\n      $reserviertSql .= \") AND (datum>=NOW() OR datum='0000-00-00')\";\n      $artikel_fuer_adresse_reserviert = round($this->app->DatabaseService->selectValue($reserviertSql, $reserviertParams), $this->GetLagerNachkommastellen());"
)

apply("LagerCheck artikel_fuer_adresse_reserviert else branch",
    "      $artikel_fuer_adresse_reserviert = round($this->app->DB->Select(\"SELECT SUM(menge) \n        FROM lager_reserviert\n          WHERE artikel='\" . $artikel . \"' \" . ($adresse > 0 ? \" AND adresse='$adresse'\" : '') . \" AND (datum>=NOW() OR datum='0000-00-00') AND objekt!='lieferschein'\"), $this->GetLagerNachkommastellen());",
    "      $reserviertSql2 = \"SELECT SUM(menge) FROM lager_reserviert WHERE artikel=:artikel\";\n      $reserviertParams2 = ['artikel' => $artikel];\n      if ($adresse > 0) { $reserviertSql2 .= \" AND adresse=:adresse\"; $reserviertParams2['adresse'] = $adresse; }\n      $reserviertSql2 .= \" AND (datum>=NOW() OR datum='0000-00-00') AND objekt!='lieferschein'\";\n      $artikel_fuer_adresse_reserviert = round($this->app->DatabaseService->selectValue($reserviertSql2, $reserviertParams2), $this->GetLagerNachkommastellen());"
)

apply("LagerCheck gesamte_menge_im_auftrag auftrag_position",
    "        $gesamte_menge_im_auftrag = $this->app->DB->Select(\"SELECT SUM(menge-geliefert_menge) \n            FROM auftrag_position WHERE auftrag='$parameter' AND artikel='$artikel'\");",
    "        $gesamte_menge_im_auftrag = $this->app->DatabaseService->selectValue(\"SELECT SUM(menge-geliefert_menge) FROM auftrag_position WHERE auftrag=:parameter AND artikel=:artikel\", ['parameter' => $parameter, 'artikel' => $artikel]);"
)

apply("LagerCheck gesamte_menge_im_auftrag lieferschein_position",
    "        $gesamte_menge_im_auftrag = $this->app->DB->Select(\"SELECT SUM(menge-geliefert) \n            FROM lieferschein_position WHERE lieferschein='$parameter' AND artikel='$artikel'\");",
    "        $gesamte_menge_im_auftrag = $this->app->DatabaseService->selectValue(\"SELECT SUM(menge-geliefert) FROM lieferschein_position WHERE lieferschein=:parameter AND artikel=:artikel\", ['parameter' => $parameter, 'artikel' => $artikel]);"
)

# =========================================================
# getStorageCacheInfosByShopId
# =========================================================
apply("getStorageCacheInfosByShopId",
    "    return $this->app->DB->SelectRow(\n      \"SELECT `storage_cache`, `pseudostorage_cache`, `last_storage_transfer`,\n       HOUR(TIMEDIFF(NOW(), `last_storage_transfer`)) AS `last_storage_transfer_hours`\n      FROM `artikel_onlineshops` \n      WHERE `shop` = '{$shopId}' AND `artikel` = '{$articleId}' AND `storage_cache` IS NOT NULL\n      LIMIT 1\"\n    );",
    "    return $this->app->DatabaseService->selectRow(\n      \"SELECT `storage_cache`, `pseudostorage_cache`, `last_storage_transfer`,\n       HOUR(TIMEDIFF(NOW(), `last_storage_transfer`)) AS `last_storage_transfer_hours`\n      FROM `artikel_onlineshops`\n      WHERE `shop` = :shop AND `artikel` = :artikel AND `storage_cache` IS NOT NULL\n      LIMIT 1\",\n      ['shop' => $shopId, 'artikel' => $articleId]\n    );"
)

# =========================================================
# isArticleShopCacheDifferent
# =========================================================
apply("isArticleShopCacheDifferent",
    "    $storageCache = $this->app->DB->SelectRow(\n      \"SELECT `storage_cache`, `pseudostorage_cache` \n        FROM `artikel_onlineshops` \n        WHERE `shop` = '{$shopId}' AND `artikel` = '{$articleId}' AND `storage_cache` IS NOT NULL\n        LIMIT 1\"\n    );",
    "    $storageCache = $this->app->DatabaseService->selectRow(\n      \"SELECT `storage_cache`, `pseudostorage_cache`\n        FROM `artikel_onlineshops`\n        WHERE `shop` = :shop AND `artikel` = :artikel AND `storage_cache` IS NOT NULL\n        LIMIT 1\",\n      ['shop' => $shopId, 'artikel' => $articleId]\n    );"
)

# =========================================================
# LagerSync
# =========================================================
apply("LagerSync artikel SelectArr {$artikelid}",
    "    $lagerartikel = $this->app->DB->SelectArr(\n      \"SELECT * FROM `artikel` WHERE `id` = '{$artikelid}' LIMIT 1\"\n    );",
    "    $lagerartikel = $this->app->DatabaseService->select(\n      \"SELECT * FROM `artikel` WHERE `id` = :id LIMIT 1\",\n      ['id' => $artikelid]\n    );"
)

apply("LagerSync shopexport SelectRow {$shop}",
    "        $shopArr = $this->app->DB->SelectRow(\n          \"SELECT `id`, `projekt`, `lagergrundlage`, `lagerkorrekturwert`, `ueberschreibe_lagerkorrekturwert` \n          FROM `shopexport` \n          WHERE `id` = '{$shop}' AND `aktiv` = 1\n          LIMIT 1\"\n        );",
    "        $shopArr = $this->app->DatabaseService->selectRow(\n          \"SELECT `id`, `projekt`, `lagergrundlage`, `lagerkorrekturwert`, `ueberschreibe_lagerkorrekturwert`\n          FROM `shopexport`\n          WHERE `id` = :shop AND `aktiv` = 1\n          LIMIT 1\",\n          ['shop' => $shop]\n        );"
)

apply("LagerSync storage_cache UPDATE",
    "        $this->app->DB->Update(\n          \"UPDATE `artikel_onlineshops` \n          SET `last_storage_transfer` = NOW(), `storage_cache` = {$verkaufbare_menge_korrektur} \n          WHERE `artikel` = {$lagerartikel[$ij]['id']} AND `shop` = {$shop}\"\n        );\n        if (is_numeric($pseudolager)) {\n          $this->app->DB->Update(\n            \"UPDATE `artikel_onlineshops` \n            SET `pseudostorage_cache` = {$pseudolager} \n            WHERE `artikel` = {$lagerartikel[$ij]['id']} AND `shop` = {$shop}\"\n          );\n        }",
    "        $this->app->DatabaseService->execute(\n          \"UPDATE `artikel_onlineshops` SET `last_storage_transfer` = NOW(), `storage_cache` = :cache WHERE `artikel` = :artikel AND `shop` = :shop\",\n          ['cache' => $verkaufbare_menge_korrektur, 'artikel' => $lagerartikel[$ij]['id'], 'shop' => $shop]\n        );\n        if (is_numeric($pseudolager)) {\n          $this->app->DatabaseService->execute(\n            \"UPDATE `artikel_onlineshops` SET `pseudostorage_cache` = :pseudocache WHERE `artikel` = :artikel AND `shop` = :shop\",\n            ['pseudocache' => $pseudolager, 'artikel' => $lagerartikel[$ij]['id'], 'shop' => $shop]\n          );\n        }"
)

apply("LagerSync cache_lagerplatzinhaltmenge UPDATE",
    "        $this->app->DB->Update(\n          \"UPDATE `artikel` SET `cache_lagerplatzinhaltmenge` = '{$cacheQuantity}'\n              WHERE `id`= '{$lagerartikel[$ij]['id']}' LIMIT 1\"\n        );",
    "        $this->app->DatabaseService->execute(\n          \"UPDATE `artikel` SET `cache_lagerplatzinhaltmenge` = :qty WHERE `id` = :id LIMIT 1\",\n          ['qty' => $cacheQuantity, 'id' => $lagerartikel[$ij]['id']]\n        );"
)

# =========================================================
# emailbackup queries (lines ~22426-22481)
# =========================================================
apply("emailbackup smtp_frommail+smtp_fromname SELECT",
    "      $email_addr = $this->app->DB->SelectArr(\"SELECT if(smtp_frommail!='',smtp_frommail,email) as email,smtp_fromname FROM emailbackup WHERE (smtp_frommail!='' OR email!='') AND (adresse<=0 OR adresse='\".$this->app->User->GetAdresse().\"') AND geloescht!=1 ORDER BY email\");",
    "      $email_addr = $this->app->DatabaseService->select(\"SELECT if(smtp_frommail!='',smtp_frommail,email) as email,smtp_fromname FROM emailbackup WHERE (smtp_frommail!='' OR email!='') AND (adresse<=0 OR adresse=:adresse) AND geloescht!=1 ORDER BY email\", ['adresse' => $this->app->User->GetAdresse()]);"
)

apply("emailbackup email+angezeigtername SELECT",
    "    $email_addr = $this->app->DB->SelectArr(\"SELECT email, angezeigtername FROM emailbackup WHERE email != '' AND (adresse<=0 OR adresse='\" . $this->app->User->GetAdresse() . \"') AND geloescht!=1 ORDER BY email\");",
    "    $email_addr = $this->app->DatabaseService->select(\"SELECT email, angezeigtername FROM emailbackup WHERE email != '' AND (adresse<=0 OR adresse=:adresse) AND geloescht!=1 ORDER BY email\", ['adresse' => $this->app->User->GetAdresse()]);"
)

apply("emailbackup smtp_frommail SELECT (compact)",
    "        $email_addr = $this->app->DB->SelectArr(\"SELECT if(smtp_frommail!='',smtp_frommail,email) as email FROM emailbackup WHERE (smtp_frommail!='' OR email!='') AND (adresse<=0 OR adresse='\".$this->app->User->GetAdresse().\"') AND geloescht!=1 ORDER BY email\");",
    "        $email_addr = $this->app->DatabaseService->select(\"SELECT if(smtp_frommail!='',smtp_frommail,email) as email FROM emailbackup WHERE (smtp_frommail!='' OR email!='') AND (adresse<=0 OR adresse=:adresse) AND geloescht!=1 ORDER BY email\", ['adresse' => $this->app->User->GetAdresse()]);"
)

apply("emailbackup email SELECT (compact)",
    "    $email_addr = $this->app->DB->SelectArr(\"SELECT email FROM emailbackup WHERE email != '' AND (adresse<=0 OR adresse='\" . $this->app->User->GetAdresse() . \"') AND geloescht!=1 ORDER BY email\");",
    "    $email_addr = $this->app->DatabaseService->select(\"SELECT email FROM emailbackup WHERE email != '' AND (adresse<=0 OR adresse=:adresse) AND geloescht!=1 ORDER BY email\", ['adresse' => $this->app->User->GetAdresse()]);"
)

# =========================================================
# adresse lookups (line ~22550-22553)
# =========================================================
apply("adresse CONCAT ansprechpartner+email SELECT",
    "    $first = $this->app->DB->Select(\"SELECT CONCAT(ansprechpartner,' &lt;',email,'&gt;') FROM adresse WHERE id='$adresse' AND geloescht=0 LIMIT 1\");",
    "    $first = $this->app->DatabaseService->selectValue(\"SELECT CONCAT(ansprechpartner,' &lt;',email,'&gt;') FROM adresse WHERE id=:adresse AND geloescht=0 LIMIT 1\", ['adresse' => $adresse]);"
)

apply("adresse CONCAT name+email SELECT",
    "      $first = $this->app->DB->Select(\"SELECT CONCAT(name,' &lt;',email,'&gt;') FROM adresse WHERE id='$adresse' AND geloescht=0 LIMIT 1\");",
    "      $first = $this->app->DatabaseService->selectValue(\"SELECT CONCAT(name,' &lt;',email,'&gt;') FROM adresse WHERE id=:adresse AND geloescht=0 LIMIT 1\", ['adresse' => $adresse]);"
)

# =========================================================
# uservorlage (line ~22705+22720)
# =========================================================
apply("uservorlage SelectArr with disableid",
    "    $user = $this->app->DB->SelectArr(\"SELECT * FROM uservorlage WHERE id!='$disableid' ORDER by bezeichnung\");",
    "    $user = $this->app->DatabaseService->select(\"SELECT * FROM uservorlage WHERE id!=:disableid ORDER by bezeichnung\", ['disableid' => $disableid]);"
)

apply("user adresse name SELECT",
    "      $user[$i]['description'] = $this->app->DB->Select(\"SELECT name FROM adresse WHERE id='\" . $user[$i]['adresse'] . \"' LIMIT 1\");",
    "      $user[$i]['description'] = $this->app->DatabaseService->selectValue(\"SELECT name FROM adresse WHERE id=:id LIMIT 1\", ['id' => $user[$i]['adresse']]);"
)

# =========================================================
# SelectFaxauswahlHTML (line ~22804-22806)
# =========================================================
apply("standardfax from user SELECT",
    "      $selected = $this->app->DB->Select(\"SELECT standardfax FROM user WHERE id='\" . $this->app->User->GetID() . \"' LIMIT 1\");",
    "      $selected = $this->app->DatabaseService->selectValue(\"SELECT standardfax FROM user WHERE id=:id LIMIT 1\", ['id' => $this->app->User->GetID()]);"
)

apply("drucker aktiv id check SELECT",
    "    $check = $this->app->DB->Select(\"SELECT id FROM drucker WHERE id='$selected' AND aktiv='1' LIMIT 1\");",
    "    $check = $this->app->DatabaseService->selectValue(\"SELECT id FROM drucker WHERE id=:selected AND aktiv='1' LIMIT 1\", ['selected' => $selected]);"
)

# =========================================================
# adresse by email/name (line ~23077-23079)
# =========================================================
apply("adresse id by email SELECT",
    "    $id = $this->app->DB->Select(\"SELECT id FROM adresse WHERE email='$mail' LIMIT 1\");",
    "    $id = $this->app->DatabaseService->selectValue(\"SELECT id FROM adresse WHERE email=:mail LIMIT 1\", ['mail' => $mail]);"
)

apply("adresse id by name SELECT",
    "      $id = $this->app->DB->Select(\"SELECT id FROM adresse WHERE name='$name' LIMIT 1\");",
    "      $id = $this->app->DatabaseService->selectValue(\"SELECT id FROM adresse WHERE name=:name LIMIT 1\", ['name' => $name]);"
)

# =========================================================
# keinhintergrund drucker (line ~23107)
# =========================================================
apply("keinhintergrund drucker SELECT",
    "    $keinhintergrund = $this->app->DB->Select(\"SELECT keinhintergrund FROM drucker WHERE id='$drucker' LIMIT 1\");",
    "    $keinhintergrund = $this->app->DatabaseService->selectValue(\"SELECT keinhintergrund FROM drucker WHERE id=:drucker LIMIT 1\", ['drucker' => $drucker]);"
)

# =========================================================
# GetBriefpapierProjekt (line ~23180-23183)
# =========================================================
apply("GetBriefpapierProjekt projekt from $typ table",
    "      $projekt = $this->app->DB->Select(\"SELECT projekt FROM $typ WHERE id='$id' LIMIT 1\"); //04.07.2018 von Bruno hinzugefuegt",
    "      $safeTyp = $this->app->DatabaseService->validateIdentifier($typ);\n      $projekt = $this->app->DatabaseService->selectValue(\"SELECT projekt FROM `{$safeTyp}` WHERE id=:id LIMIT 1\", ['id' => $id]); //04.07.2018 von Bruno hinzugefuegt"
)

apply("GetBriefpapierProjekt projekt from adresse SELECT",
    "      $projekt = $this->app->DB->Select(\"SELECT projekt FROM adresse WHERE id='$adresse' AND geloescht=0 LIMIT 1\");",
    "      $projekt = $this->app->DatabaseService->selectValue(\"SELECT projekt FROM adresse WHERE id=:adresse AND geloescht=0 LIMIT 1\", ['adresse' => $adresse]);"
)

# =========================================================
# dokumente_send checks (line ~23923-23925)
# =========================================================
apply("dokumente_send SELECT by text+betreff",
    "        $check = $this->app->DB->Select(\"SELECT id FROM dokumente_send WHERE text='$text' AND betreff='$betreff' AND geloescht=0 AND versendet=0 ORDER by id DESC LIMIT 1\");",
    "        $check = $this->app->DatabaseService->selectValue(\"SELECT id FROM dokumente_send WHERE text=:text AND betreff=:betreff AND geloescht=0 AND versendet=0 ORDER by id DESC LIMIT 1\", ['text' => $text, 'betreff' => $betreff]);"
)

apply("dokumente_send SELECT by dokument+parameter (first)",
    "        $check = $this->app->DB->Select(\"SELECT id FROM dokumente_send WHERE dokument='$typ' AND parameter='$id' AND geloescht=0 AND versendet=0 ORDER by id DESC LIMIT 1\"); // GEHT bei BE RE LS",
    "        $check = $this->app->DatabaseService->selectValue(\"SELECT id FROM dokumente_send WHERE dokument=:typ AND parameter=:id AND geloescht=0 AND versendet=0 ORDER by id DESC LIMIT 1\", ['typ' => $typ, 'id' => $id]); // GEHT bei BE RE LS",
    count=2  # There are two occurrences
)

apply("dokumente_send UPDATE betreff+text",
    "          $this->app->DB->Update(\"UPDATE dokumente_send SET betreff='$betreff', text='$text',versendet=1 WHERE dokument='$typ' AND parameter='$id' AND geloescht=0 AND versendet=0 LIMIT 1\");  // GEHT bei RE, LS ..",
    "          $this->app->DatabaseService->execute(\"UPDATE dokumente_send SET betreff=:betreff, text=:text, versendet=1 WHERE dokument=:typ AND parameter=:id AND geloescht=0 AND versendet=0 LIMIT 1\", ['betreff' => $betreff, 'text' => $text, 'typ' => $typ, 'id' => $id]);  // GEHT bei RE, LS .."
)

# =========================================================
# versendet status UPDATEs (line ~24000+)
# =========================================================
for table in ['retoure', 'arbeitsnachweis', 'reisekosten', 'rechnung', 'gutschrift', 'preisanfrage']:
    apply(f"UPDATE {table} status=versendet",
        f"            $this->app->DB->Update(\"UPDATE {table} SET status='versendet' WHERE id='$id' AND status='freigegeben' LIMIT 1\");",
        f"            $this->app->DatabaseService->execute(\"UPDATE {table} SET status='versendet' WHERE id=:id AND status='freigegeben' LIMIT 1\", ['id' => $id]);"
    )

apply("UPDATE proformarechnung status=versendet",
    "            $this->app->DB->Update(\"UPDATE  proformarechnung SET status='versendet' WHERE id='$id' AND status='freigegeben' LIMIT 1\");",
    "            $this->app->DatabaseService->execute(\"UPDATE proformarechnung SET status='versendet' WHERE id=:id AND status='freigegeben' LIMIT 1\", ['id' => $id]);"
)

apply("UPDATE spedition versendet+status",
    "            $this->app->DB->Update(\"UPDATE spedition SET versendet=1, schreibschutz='1' WHERE id='$id' LIMIT 1\");\n            $this->app->DB->Update(\"UPDATE spedition SET status='versendet' WHERE id='$id' AND status='freigegeben' LIMIT 1\");",
    "            $this->app->DatabaseService->execute(\"UPDATE spedition SET versendet=1, schreibschutz='1' WHERE id=:id LIMIT 1\", ['id' => $id]);\n            $this->app->DatabaseService->execute(\"UPDATE spedition SET status='versendet' WHERE id=:id AND status='freigegeben' LIMIT 1\", ['id' => $id]);"
)

# =========================================================
# fax SELECT / tmp_fax (line ~24065-24102)
# =========================================================
apply("dokumente_send fax SELECT by typ+id",
    "          $check = $this->app->DB->Select(\"SELECT id FROM dokumente_send WHERE dokument='$typ' AND parameter='$id' AND geloescht=0 AND versendet=0 ORDER by id DESC LIMIT 1\"); // GEHT bei BE RE LS",
    "          $check = $this->app->DatabaseService->selectValue(\"SELECT id FROM dokumente_send WHERE dokument=:typ AND parameter=:id AND geloescht=0 AND versendet=0 ORDER by id DESC LIMIT 1\", ['typ' => $typ, 'id' => $id]); // GEHT bei BE RE LS"
)

apply("tmp_fax telefax from $typ",
    "      $tmp_fax = $this->app->DB->Select(\"SELECT telefax FROM $typ WHERE id='$id' LIMIT 1\");",
    "      $safeTypFax = $this->app->DatabaseService->validateIdentifier($typ);\n      $tmp_fax = $this->app->DatabaseService->selectValue(\"SELECT telefax FROM `{$safeTypFax}` WHERE id=:id LIMIT 1\", ['id' => $id]);"
)

# =========================================================
# GetBelegMailText (line ~24218-24223)
# =========================================================
apply("GetBelegMailText sprache+name+name2+abperfax",
    "    $sprache = $this->app->DB->Select(\"SELECT sprache FROM adresse WHERE id='$adresse' AND geloescht=0 LIMIT 1\");\n    $name = $this->app->DB->Select(\"SELECT name FROM adresse WHERE id='$adresse' AND geloescht=0 LIMIT 1\");\n    $name2 = $this->app->DB->Select(\"SELECT name FROM $dokument WHERE id='$id' LIMIT 1\");",
    "    $sprache = $this->app->DatabaseService->selectValue(\"SELECT sprache FROM adresse WHERE id=:adresse AND geloescht=0 LIMIT 1\", ['adresse' => $adresse]);\n    $name = $this->app->DatabaseService->selectValue(\"SELECT name FROM adresse WHERE id=:adresse AND geloescht=0 LIMIT 1\", ['adresse' => $adresse]);\n    $safeDokument = $this->app->DatabaseService->validateIdentifier($dokument);\n    $name2 = $this->app->DatabaseService->selectValue(\"SELECT name FROM `{$safeDokument}` WHERE id=:id LIMIT 1\", ['id' => $id]);"
)

apply("GetBelegMailText abperfax",
    "    $abperfax = $this->app->DB->Select(\"SELECT abperfax FROM adresse WHERE id='$adresse' AND geloescht=0 LIMIT 1\");",
    "    $abperfax = $this->app->DatabaseService->selectValue(\"SELECT abperfax FROM adresse WHERE id=:adresse AND geloescht=0 LIMIT 1\", ['adresse' => $adresse]);"
)

# =========================================================
# GetDokumenteSendList (line ~24401)
# =========================================================
apply("dokumente_send SelectArr by dokument+id",
    "      $tmp = $this->app->DB->SelectArr(\"SELECT DATE_FORMAT(zeit,'%d.%m.%Y %H:%i') as datum, text, dateiid, ansprechpartner, betreff, id, adresse, versendet, parameter, dokument, bearbeiter,art FROM dokumente_send WHERE dokument='\" . $dokument . \"' AND parameter='$id'  AND parameter!=0 ORDER by zeit DESC\");",
    "      $tmp = $this->app->DatabaseService->select(\"SELECT DATE_FORMAT(zeit,'%d.%m.%Y %H:%i') as datum, text, dateiid, ansprechpartner, betreff, id, adresse, versendet, parameter, dokument, bearbeiter,art FROM dokumente_send WHERE dokument=:dokument AND parameter=:id AND parameter!=0 ORDER by zeit DESC\", ['dokument' => $dokument, 'id' => $id]);"
)

# =========================================================
# SendMail to+to_name (line ~24524)
# =========================================================
apply("SendMail to+to_name SELECT",
    "            $to = $this->app->DB->Select(\"SELECT email FROM adresse WHERE id='$adresse' AND geloescht=0 LIMIT 1\");\n            $to_name = $this->app->DB->Select(\"SELECT name FROM adresse WHERE id='$adresse' AND geloescht=0 LIMIT 1\");",
    "            $to = $this->app->DatabaseService->selectValue(\"SELECT email FROM adresse WHERE id=:adresse AND geloescht=0 LIMIT 1\", ['adresse' => $adresse]);\n            $to_name = $this->app->DatabaseService->selectValue(\"SELECT name FROM adresse WHERE id=:adresse AND geloescht=0 LIMIT 1\", ['adresse' => $adresse]);"
)

apply("abweichendeemailab SELECT",
    "          $abweichendeemailab = $this->app->DB->Select(\"SELECT abweichendeemailab FROM adresse WHERE id='$adresse' AND geloescht!=1 LIMIT 1\");",
    "          $abweichendeemailab = $this->app->DatabaseService->selectValue(\"SELECT abweichendeemailab FROM adresse WHERE id=:adresse AND geloescht!=1 LIMIT 1\", ['adresse' => $adresse]);"
)

# =========================================================
# eigenesignatur (line ~25071)
# =========================================================
apply("eigenesignatur SELECT",
    "      $eigenesignatur = $this->app->DB->Select(\"SELECT eigenesignatur FROM emailbackup WHERE email='$from' AND email !='' AND geloescht!=1 LIMIT 1\");",
    "      $eigenesignatur = $this->app->DatabaseService->selectValue(\"SELECT eigenesignatur FROM emailbackup WHERE email=:from AND email!='' AND geloescht!=1 LIMIT 1\", ['from' => $from]);"
)

# =========================================================
# GetVersandartSelectFeld (line ~26169)
# =========================================================
apply("GetVersandartSelectFeld versandarten SELECT",
    "      $tmp = $this->app->DB->SelectArr(\"SELECT type,bezeichnung FROM versandarten WHERE aktiv='1' AND geloescht!='1' AND (projekt = '$projekt' OR projekt = 0) ORDER by bezeichnung\");",
    "      $tmp = $this->app->DatabaseService->select(\"SELECT type,bezeichnung FROM versandarten WHERE aktiv='1' AND geloescht!='1' AND (projekt = :projekt OR projekt = 0) ORDER by bezeichnung\", ['projekt' => $projekt]);"
)

# =========================================================
# artikeleigenschaften (line ~26190)
# =========================================================
apply("artikeleigenschaften with projekt SELECT",
    "      $result = $this->app->DB->SelectArr(\"SELECT id,name FROM artikeleigenschaften WHERE projekt='$projekt' AND geloescht!=1 ORDER by name\");",
    "      $result = $this->app->DatabaseService->select(\"SELECT id,name FROM artikeleigenschaften WHERE projekt=:projekt AND geloescht!=1 ORDER by name\", ['projekt' => $projekt]);"
)

# =========================================================
# GetZahlungsweiseSelectFeld (line ~26330)
# =========================================================
apply("GetZahlungsweiseSelectFeld $table+$id SelectRow",
    "        $arr = $this->app->DB->SelectRow(\"SELECT zahlungsweise, projekt FROM $table WHERE id = '$id' LIMIT 1\");",
    "        $safeTable = $this->app->DatabaseService->validateIdentifier($table);\n        $arr = $this->app->DatabaseService->selectRow(\"SELECT zahlungsweise, projekt FROM `{$safeTable}` WHERE id = :id LIMIT 1\", ['id' => $id]);"
)

# =========================================================
# GetStandardprojekt (line ~27087-27091)
# =========================================================
apply("GetStandardprojekt standardprojekt from firma",
    "    $projekt = $this->app->DB->Select(\"SELECT standardprojekt FROM firma WHERE id='\" . $this->app->User->GetFirma() . \"' LIMIT 1\");",
    "    $projekt = $this->app->DatabaseService->selectValue(\"SELECT standardprojekt FROM firma WHERE id=:firma LIMIT 1\", ['firma' => $this->app->User->GetFirma()]);"
)

apply("GetStandardprojekt projekt_bevorzugen from user",
    "    $projekt_bevorzugt = $this->app->DB->Select(\"SELECT projekt_bevorzugen FROM user WHERE id='\" . $this->app->User->GetID() . \"' LIMIT 1\");\n",
    "    $projekt_bevorzugt = $this->app->DatabaseService->selectValue(\"SELECT projekt_bevorzugen FROM user WHERE id=:id LIMIT 1\", ['id' => $this->app->User->GetID()]);\n",
    count=0  # replace all
)

apply("GetStandardprojekt projekt from user",
    "      $projekt = $this->app->DB->Select(\"SELECT projekt FROM user WHERE id='\" . $this->app->User->GetID() . \"' LIMIT 1\");\n",
    "      $projekt = $this->app->DatabaseService->selectValue(\"SELECT projekt FROM user WHERE id=:id LIMIT 1\", ['id' => $this->app->User->GetID()]);\n",
    count=0
)

# =========================================================
# AdresseAnlegenNeu (line ~27098-27107)
# =========================================================
apply("AdresseAnlegenNeu INSERT adresse",
    "    $this->app->DB->Insert(\"INSERT INTO adresse (id,name,firma,zahlungsweise,zahlungsweiselieferant,projekt,versandart) VALUES ('','$name','$firma','$zahlungsweise','$zahlungsweiselieferant','$projekt','$versandart')\");",
    "    $this->app->DatabaseService->execute(\"INSERT INTO adresse (id,name,firma,zahlungsweise,zahlungsweiselieferant,projekt,versandart) VALUES ('', :name, :firma, :zahlungsweise, :zahlungsweiselieferant, :projekt, :versandart)\", ['name' => $name, 'firma' => $firma, 'zahlungsweise' => $zahlungsweise, 'zahlungsweiselieferant' => $zahlungsweiselieferant, 'projekt' => $projekt, 'versandart' => $versandart]);"
)

apply("lieferadressen INSERT",
    "    $this->app->DB->Insert(\"INSERT INTO lieferadressen (id,adresse) VALUES ('','$adresse')\");",
    "    $this->app->DatabaseService->execute(\"INSERT INTO lieferadressen (id,adresse) VALUES ('', :adresse)\", ['adresse' => $adresse]);"
)

# =========================================================
# adresse_rolle projekt queries (line ~27171-27173)
# =========================================================
apply("adresse_rolle projekt Projekt SELECT",
    "      $projekt = $this->app->DB->Select(\"SELECT ar.parameter FROM adresse_rolle ar INNER JOIN projekt pr ON ar.parameter = pr.id AND pr.geloescht <> 1 WHERE (ar.bis <= CURDATE() OR ar.bis = '0000-00-00') AND ar.adresse='$adresse' AND ar.objekt='Projekt' LIMIT 1\");",
    "      $projekt = $this->app->DatabaseService->selectValue(\"SELECT ar.parameter FROM adresse_rolle ar INNER JOIN projekt pr ON ar.parameter = pr.id AND pr.geloescht <> 1 WHERE (ar.bis <= CURDATE() OR ar.bis = '0000-00-00') AND ar.adresse=:adresse AND ar.objekt='Projekt' LIMIT 1\", ['adresse' => $adresse]);"
)

apply("adresse_rolle projekt not Gruppe SELECT",
    "        $projekt = $this->app->DB->Select(\"SELECT ar.projekt FROM adresse_rolle ar INNER JOIN projekt pr ON ar.projekt = pr.id AND pr.geloescht <> 1 WHERE (ar.bis <= CURDATE() OR ar.bis = '0000-00-00') AND ar.adresse='$adresse' AND ar.objekt!='Gruppe' LIMIT 1\");",
    "        $projekt = $this->app->DatabaseService->selectValue(\"SELECT ar.projekt FROM adresse_rolle ar INNER JOIN projekt pr ON ar.projekt = pr.id AND pr.geloescht <> 1 WHERE (ar.bis <= CURDATE() OR ar.bis = '0000-00-00') AND ar.adresse=:adresse AND ar.objekt!='Gruppe' LIMIT 1\", ['adresse' => $adresse]);"
)

apply("projekt id check SELECT",
    "      $parameter = $this->app->DB->Select(\"SELECT id FROM projekt WHERE id='$parameter' LIMIT 1\");",
    "      $parameter = $this->app->DatabaseService->selectValue(\"SELECT id FROM projekt WHERE id=:parameter LIMIT 1\", ['parameter' => $parameter]);"
)

# =========================================================
# AdresseRolleCheck kundennummer/lieferantennummer/mitarbeiternummer (line ~27222-27240)
# =========================================================
apply("adresse kundennummer UPDATE",
    "      $this->app->DB->Update(\"UPDATE adresse SET kundennummer='$kundennummer' WHERE id='$adresse' AND (kundennummer='0' OR kundennummer='') LIMIT 1\");",
    "      $this->app->DatabaseService->execute(\"UPDATE adresse SET kundennummer=:nr WHERE id=:adresse AND (kundennummer='0' OR kundennummer='') LIMIT 1\", ['nr' => $kundennummer, 'adresse' => $adresse]);"
)

apply("adresse lieferantennummer UPDATE",
    "      $this->app->DB->Update(\"UPDATE adresse SET lieferantennummer='$lieferantennummer' WHERE id='$adresse' AND (lieferantennummer='0' OR lieferantennummer='') LIMIT 1\");",
    "      $this->app->DatabaseService->execute(\"UPDATE adresse SET lieferantennummer=:nr WHERE id=:adresse AND (lieferantennummer='0' OR lieferantennummer='') LIMIT 1\", ['nr' => $lieferantennummer, 'adresse' => $adresse]);"
)

apply("adresse mitarbeiternummer UPDATE",
    "      $this->app->DB->Update(\"UPDATE adresse SET mitarbeiternummer='$mitarbeiternummer' WHERE id='$adresse' AND (mitarbeiternummer='0' OR mitarbeiternummer='') LIMIT 1\");",
    "      $this->app->DatabaseService->execute(\"UPDATE adresse SET mitarbeiternummer=:nr WHERE id=:adresse AND (mitarbeiternummer='0' OR mitarbeiternummer='') LIMIT 1\", ['nr' => $mitarbeiternummer, 'adresse' => $adresse]);"
)

# =========================================================
# zeiterfassung (line ~27320)
# =========================================================
apply("zeiterfassung arbeitsnachweispositionid SELECT",
    "    $arbeitsnachweisposid = $this->app->DB->Select(\"SELECT arbeitsnachweispositionid FROM zeiterfassung WHERE id='$id'\");",
    "    $arbeitsnachweisposid = $this->app->DatabaseService->selectValue(\"SELECT arbeitsnachweispositionid FROM zeiterfassung WHERE id=:id\", ['id' => $id]);"
)

# =========================================================
# CreateUservorlage uservorlagerights INSERTs (line ~27509-27510)
# =========================================================
apply("uservorlagerights INSERT login",
    "    $this->app->DB->Update(\"INSERT INTO uservorlagerights (vorlage, module,action,permission) VALUES ('$id','welcome','login',1)\");",
    "    $this->app->DatabaseService->execute(\"INSERT INTO uservorlagerights (vorlage, module,action,permission) VALUES (:id,'welcome','login',1)\", ['id' => $id]);"
)

apply("uservorlagerights INSERT logout",
    "    $this->app->DB->Update(\"INSERT INTO uservorlagerights (vorlage, module,action,permission) VALUES ('$id','welcome','logout',1)\");",
    "    $this->app->DatabaseService->execute(\"INSERT INTO uservorlagerights (vorlage, module,action,permission) VALUES (:id,'welcome','logout',1)\", ['id' => $id]);"
)

# =========================================================
# GetBelegnummer (line ~27703-27709)
# =========================================================
apply("eigenernummernkreis SELECT",
    "    $eigenernummernkreis = $this->app->DB->Select(\"SELECT eigenernummernkreis FROM projekt WHERE id='$projekt' LIMIT 1\");",
    "    $eigenernummernkreis = $this->app->DatabaseService->selectValue(\"SELECT eigenernummernkreis FROM projekt WHERE id=:projekt LIMIT 1\", ['projekt' => $projekt]);"
)

apply("uebergeordnetes_projekt SELECT",
    "        $uebergeordnetes_projekt = $this->app->DB->Select(\"SELECT uebergeordnetes_projekt FROM projekt WHERE id='$untergeordnetes_projekt' LIMIT 1\");",
    "        $uebergeordnetes_projekt = $this->app->DatabaseService->selectValue(\"SELECT uebergeordnetes_projekt FROM projekt WHERE id=:id LIMIT 1\", ['id' => $untergeordnetes_projekt]);"
)

# =========================================================
# GetBelegAdressdaten (line ~28508)
# =========================================================
apply("GetBelegAdressdaten adresse SelectArr",
    "    $arr = $this->app->DB->SelectArr(\"SELECT *,vertrieb as vertriebid,innendienst as bearbeiterid,'' as bearbeiter FROM adresse WHERE id='$adresse' AND geloescht=0 LIMIT 1\");",
    "    $arr = $this->app->DatabaseService->select(\"SELECT *,vertrieb as vertriebid,innendienst as bearbeiterid,'' as bearbeiter FROM adresse WHERE id=:adresse AND geloescht=0 LIMIT 1\", ['adresse' => $adresse]);"
)

apply("GetBelegAdressdaten Zoll SelectArr",
    "    $arr = $this->app->DB->SelectArr(\"SELECT *,zollinformationen as verzollinformationen,if(zollinformationen!='',1,0) as verzollungadresse FROM adresse WHERE id='$adresse' AND geloescht=0 LIMIT 1\");",
    "    $arr = $this->app->DatabaseService->select(\"SELECT *,zollinformationen as verzollinformationen,if(zollinformationen!='',1,0) as verzollungadresse FROM adresse WHERE id=:adresse AND geloescht=0 LIMIT 1\", ['adresse' => $adresse]);"
)

# =========================================================
# adresse_rolle rolle_projekt (line ~28670 / 29173 / 29340)
# =========================================================
apply("rolle_projekt adresse_rolle SELECT (all occurrences)",
    "    $rolle_projekt = $this->app->DB->Select(\"SELECT parameter FROM adresse_rolle WHERE adresse='$adresse' AND subjekt='Kunde' AND objekt='Projekt' AND (bis ='0000-00-00' OR bis <= NOW()) LIMIT 1\");",
    "    $rolle_projekt = $this->app->DatabaseService->selectValue(\"SELECT parameter FROM adresse_rolle WHERE adresse=:adresse AND subjekt='Kunde' AND objekt='Projekt' AND (bis ='0000-00-00' OR bis <= NOW()) LIMIT 1\", ['adresse' => $adresse]);",
    count=0
)

# =========================================================
# projekt_bevorzugt second occurrences (line ~28700 / 29205)
# =========================================================
# Already handled above with count=0

# =========================================================
# EditBelegAdressdaten useredittimestamp UPDATE (line ~28781)
# =========================================================
apply("EditBelegAdressdaten useredittimestamp UPDATE",
    "    $this->app->DB->Update(\"UPDATE $smodule SET useredittimestamp=NOW(),usereditid='$user' WHERE id = '$sid' AND (usereditid='$user' OR ifnull(useredittimestamp,'0000-00-00 00:00:00') = '0000-00-00 00:00:00' OR TIME_TO_SEC(TIMEDIFF(NOW(), useredittimestamp)) > 30) LIMIT 1\");",
    "    $safeSmodule = $this->app->DatabaseService->validateIdentifier($smodule);\n    $this->app->DatabaseService->execute(\"UPDATE `{$safeSmodule}` SET useredittimestamp=NOW(),usereditid=:user WHERE id = :sid AND (usereditid=:user2 OR ifnull(useredittimestamp,'0000-00-00 00:00:00') = '0000-00-00 00:00:00' OR TIME_TO_SEC(TIMEDIFF(NOW(), useredittimestamp)) > 30) LIMIT 1\", ['user' => $user, 'sid' => $sid, 'user2' => $user]);"
)

apply("EditBelegAdressdaten timediff SELECT",
    "    $timediff = $this->app->DB->Select(\"SELECT TIME_TO_SEC(TIMEDIFF(NOW(), useredittimestamp)) FROM $smodule WHERE id='$sid' LIMIT 1\");",
    "    $safeSmodule2 = $this->app->DatabaseService->validateIdentifier($smodule);\n    $timediff = $this->app->DatabaseService->selectValue(\"SELECT TIME_TO_SEC(TIMEDIFF(NOW(), useredittimestamp)) FROM `{$safeSmodule2}` WHERE id=:sid LIMIT 1\", ['sid' => $sid]);"
)

apply("CheckUserEditAccess usereditid SELECT",
    "    $user = $this->app->DB->Select(\"SELECT usereditid FROM $modul WHERE id='$id' LIMIT 1\");",
    "    $safeModul = $this->app->DatabaseService->validateIdentifier($modul);\n    $user = $this->app->DatabaseService->selectValue(\"SELECT usereditid FROM `{$safeModul}` WHERE id=:id LIMIT 1\", ['id' => $id]);"
)

apply("CheckUserEditAccess user adresse SELECT",
    "    $user_adresse = $this->app->DB->Select(\"SELECT adresse FROM user WHERE id='$user' LIMIT 1\");",
    "    $user_adresse = $this->app->DatabaseService->selectValue(\"SELECT adresse FROM user WHERE id=:id LIMIT 1\", ['id' => $user]);"
)

# =========================================================
# standardlieferadresse (line ~28990)
# =========================================================
apply("lieferadressen standardlieferadresse SelectArr",
    "      $standardlieferadresse = $this->app->DB->SelectArr(\"SELECT * FROM lieferadressen WHERE adresse='$adresse' AND standardlieferadresse='1' LIMIT 1\");",
    "      $standardlieferadresse = $this->app->DatabaseService->select(\"SELECT * FROM lieferadressen WHERE adresse=:adresse AND standardlieferadresse='1' LIMIT 1\", ['adresse' => $adresse]);"
)

# =========================================================
# standardlieferadresse $type UPDATEs (lines ~29011-29037)
# =========================================================
apply("UPDATE $type ustid",
    "            $this->app->DB->Update(\"UPDATE $type SET ustid = '\" . $standardlieferadresse[0]['ustid'] . \"' WHERE id='$id' LIMIT 1\");",
    "            $safeType = $this->app->DatabaseService->validateIdentifier($type);\n            $this->app->DatabaseService->execute(\"UPDATE `{$safeType}` SET ustid = :v WHERE id=:id LIMIT 1\", ['v' => $standardlieferadresse[0]['ustid'], 'id' => $id]);"
)

for field_pair in [
    ('ust_befreit', 'ust_befreit'),
    ('lieferbedingung', 'lieferbedingung'),
    ('liefergln', 'gln'),
    ('lieferemail', 'email'),
    ('lieferbundesstaat', 'bundesstaat'),
    ('gln', 'gln'),
    ('bundesstaat', 'bundesstaat'),
]:
    apply(f"UPDATE $type {field_pair[0]}",
        f"            $this->app->DB->Update(\"UPDATE $type SET {field_pair[0]} = '\" . $standardlieferadresse[0]['{field_pair[1]}'] . \"' WHERE id='$id' LIMIT 1\");",
        f"            $this->app->DatabaseService->execute(\"UPDATE `{{$safeType}}` SET {field_pair[0]} = :v WHERE id=:id LIMIT 1\", ['v' => $standardlieferadresse[0]['{field_pair[1]}'], 'id' => $id]);"
    )

# =========================================================
# GetRabattinformationFuerBeleg (line ~29060-29097)
# =========================================================
apply("GetRabattinformationFuerBeleg rabatt+gruppe+adresse+festschreiben",
    "      $rabatt = !empty($docArr) ? $docArr['realrabatt'] : $this->app->DB->Select(\"SELECT realrabatt FROM $module b LEFT JOIN adresse a ON a.id=b.adresse WHERE b.id='$id' LIMIT 1\");\n      $gruppe = !empty($docArr) ? $docArr['gruppe'] : $this->app->DB->Select(\"SELECT gruppe FROM $module b WHERE b.id='$id' LIMIT 1\");\n      $adresse = !empty($docArr) ? $docArr['adresse'] : $this->app->DB->Select(\"SELECT adresse FROM $module WHERE id='$id' LIMIT 1\");\n      $rabatte_festschreiben = $this->app->DB->Select(\"SELECT rabatte_festschreiben FROM adresse WHERE id='\" . $adresse . \"' LIMIT 1\");",
    "      $safeModule = $this->app->DatabaseService->validateIdentifier($module);\n      $rabatt = !empty($docArr) ? $docArr['realrabatt'] : $this->app->DatabaseService->selectValue(\"SELECT realrabatt FROM `{$safeModule}` b LEFT JOIN adresse a ON a.id=b.adresse WHERE b.id=:id LIMIT 1\", ['id' => $id]);\n      $gruppe = !empty($docArr) ? $docArr['gruppe'] : $this->app->DatabaseService->selectValue(\"SELECT gruppe FROM `{$safeModule}` b WHERE b.id=:id LIMIT 1\", ['id' => $id]);\n      $adresse = !empty($docArr) ? $docArr['adresse'] : $this->app->DatabaseService->selectValue(\"SELECT adresse FROM `{$safeModule}` WHERE id=:id LIMIT 1\", ['id' => $id]);\n      $rabatte_festschreiben = $this->app->DatabaseService->selectValue(\"SELECT rabatte_festschreiben FROM adresse WHERE id=:adresse LIMIT 1\", ['adresse' => $adresse]);"
)

apply("GetRabattinformationFuerBeleg gruppe+gruppe_name+internebemerkung",
    "        $gruppe = !empty($docArr) ? $docArr['gruppe'] : $this->app->DB->Select(\"SELECT gruppe FROM $module WHERE id='$id' LIMIT 1\");\n        $gruppe_name = !empty($gruppenArr) ? $gruppenArr['name'] : $this->app->DB->Select(\"SELECT CONCAT(name,' ',kennziffer) FROM gruppen WHERE id='$gruppe' LIMIT 1\");\n        $gruppeinternebemerkung = !empty($gruppenArr) ? $gruppenArr['internebemerkung'] : $this->app->DB->Select(\"SELECT internebemerkung FROM gruppen WHERE id='$gruppe' LIMIT 1\");",
    "        $gruppe = !empty($docArr) ? $docArr['gruppe'] : $this->app->DatabaseService->selectValue(\"SELECT gruppe FROM `{$safeModule}` WHERE id=:id LIMIT 1\", ['id' => $id]);\n        $gruppe_name = !empty($gruppenArr) ? $gruppenArr['name'] : $this->app->DatabaseService->selectValue(\"SELECT CONCAT(name,' ',kennziffer) FROM gruppen WHERE id=:gruppe LIMIT 1\", ['gruppe' => $gruppe]);\n        $gruppeinternebemerkung = !empty($gruppenArr) ? $gruppenArr['internebemerkung'] : $this->app->DatabaseService->selectValue(\"SELECT internebemerkung FROM gruppen WHERE id=:gruppe LIMIT 1\", ['gruppe' => $gruppe]);"
)

apply("rabattinformation SELECT",
    "        $rabattinformation = $this->app->DB->Select(\"SELECT rabattinformation FROM adresse WHERE id='$adresse' LIMIT 1\");",
    "        $rabattinformation = $this->app->DatabaseService->selectValue(\"SELECT rabattinformation FROM adresse WHERE id=:adresse LIMIT 1\", ['adresse' => $adresse]);"
)

# =========================================================
# projektfiliale UPDATE $table (line ~29119)
# =========================================================
apply("UPDATE projektfiliale=0 $table",
    "      $this->app->DB->Update(\"UPDATE $table SET projektfiliale=0 WHERE id='$id'\");",
    "      $safeTablePF = $this->app->DatabaseService->validateIdentifier($table);\n      $this->app->DatabaseService->execute(\"UPDATE `{$safeTablePF}` SET projektfiliale=0 WHERE id=:id\", ['id' => $id]);"
)

# =========================================================
# sprache UPDATE $table (line ~29140)
# =========================================================
apply("UPDATE sprache $table",
    "        $this->app->DB->Update(\"UPDATE $table SET sprache = '\" . $this->app->DB->real_escape_string($sprache) . \"' WHERE id = '$id' LIMIT 1\");",
    "        $safeTableS = $this->app->DatabaseService->validateIdentifier($table);\n        $this->app->DatabaseService->execute(\"UPDATE `{$safeTableS}` SET sprache = :sprache WHERE id = :id LIMIT 1\", ['sprache' => $sprache, 'id' => $id]);"
)

# =========================================================
# kundennummerlieferant UPDATE $table (line ~29150)
# =========================================================
apply("UPDATE kundennummerlieferant $table",
    "          $this->app->DB->Update(\"UPDATE $table SET kundennummerlieferant = '\" . $this->app->DB->real_escape_string($kundennummerlieferant) . \"' WHERE id = '$id' LIMIT 1\");",
    "          $safeTableKL = $this->app->DatabaseService->validateIdentifier($table);\n          $this->app->DatabaseService->execute(\"UPDATE `{$safeTableKL}` SET kundennummerlieferant = :nr WHERE id = :id LIMIT 1\", ['nr' => $kundennummerlieferant, 'id' => $id]);"
)

# =========================================================
# GetBelegAuftragsdaten / GetBelegAngebotsdaten adresse SelectArr (line ~29164/29333/29360)
# =========================================================
apply("adresse SELECT vertriebid bearbeiterid bearbeiter (all)",
    "    $arr = $this->app->DB->SelectArr(\"SELECT *,vertrieb as vertriebid,'' as bearbeiter,innendienst as bearbeiterid FROM adresse WHERE id='$adresse' AND geloescht=0 LIMIT 1\");",
    "    $arr = $this->app->DatabaseService->select(\"SELECT *,vertrieb as vertriebid,'' as bearbeiter,innendienst as bearbeiterid FROM adresse WHERE id=:adresse AND geloescht=0 LIMIT 1\", ['adresse' => $adresse]);",
    count=0
)

apply("adresse SELECT * simple (all)",
    "    $arr = $this->app->DB->SelectArr(\"SELECT * FROM adresse WHERE id='$adresse' LIMIT 1\");",
    "    $arr = $this->app->DatabaseService->select(\"SELECT * FROM adresse WHERE id=:adresse LIMIT 1\", ['adresse' => $adresse]);",
    count=0
)

# =========================================================
# standardlager from projekt (multiple occurrences)
# =========================================================
apply("standardlager from projekt SELECT (all)",
    "      $arr[0]['standardlager'] = $this->app->DB->Select(\"SELECT standardlager FROM projekt WHERE id='\" . $uparr['projekt'] . \"' LIMIT 1\");",
    "      $arr[0]['standardlager'] = $this->app->DatabaseService->selectValue(\"SELECT standardlager FROM projekt WHERE id=:id LIMIT 1\", ['id' => $uparr['projekt']]);",
    count=0
)

# =========================================================
# abkuerzung from projekt (multiple occurrences)
# =========================================================
apply("abkuerzung from projekt SELECT (all)",
    "      $this->app->Secure->POST['projekt'] = $this->app->DB->Select(\"SELECT abkuerzung FROM projekt WHERE id='\" . $arr[0]['projekt'] . \"' AND id > 0 LIMIT 1\");",
    "      $this->app->Secure->POST['projekt'] = $this->app->DatabaseService->selectValue(\"SELECT abkuerzung FROM projekt WHERE id=:id AND id > 0 LIMIT 1\", ['id' => $arr[0]['projekt']]);",
    count=0
)

# =========================================================
# SetBelegDatum UPDATE datum=NOW() (line ~29863)
# =========================================================
apply("SetBelegDatum UPDATE datum=NOW()",
    "    $this->app->DB->Update(\"UPDATE $beleg SET datum=NOW() WHERE id='$id' AND (datum='0000-00-00' OR datum='1970-01-01' OR datum IS NULL OR datum='') LIMIT 1\");",
    "    $safeBeleg = $this->app->DatabaseService->validateIdentifier($beleg);\n    $this->app->DatabaseService->execute(\"UPDATE `{$safeBeleg}` SET datum=NOW() WHERE id=:id AND (datum='0000-00-00' OR datum='1970-01-01' OR datum IS NULL OR datum='') LIMIT 1\", ['id' => $id]);"
)

# =========================================================
# wiedervorlage (line ~29920-29930)
# =========================================================
apply("wiedervorlage angebot SELECT",
    "    $check = $this->app->DB->Select(\"SELECT id FROM wiedervorlage WHERE module='angebot' AND parameter='$id' LIMIT 1\");",
    "    $check = $this->app->DatabaseService->selectValue(\"SELECT id FROM wiedervorlage WHERE module='angebot' AND parameter=:id LIMIT 1\", ['id' => $id]);"
)

apply("belegnr from angebot SELECT",
    "    $belegnr = $this->app->DB->Select(\"SELECT belegnr FROM angebot WHERE id='$id' LIMIT 1\");",
    "    $belegnr = $this->app->DatabaseService->selectValue(\"SELECT belegnr FROM angebot WHERE id=:id LIMIT 1\", ['id' => $id]);"
)

# =========================================================
# autoversand auftraege (line ~24861)
# =========================================================
apply("autoversand auftraege with limit",
    "    $auftraege = $this->app->DB->SelectArr(\"SELECT id FROM auftrag WHERE status='freigegeben' AND inbearbeitung=0 AND autoversand=1 ORDER BY fastlane = 1 DESC, datum \" . ($limit > 0 ? \" LIMIT $limit \" : ''));",
    "    $auftraegeSql = \"SELECT id FROM auftrag WHERE status='freigegeben' AND inbearbeitung=0 AND autoversand=1 ORDER BY fastlane = 1 DESC, datum\" . ($limit > 0 ? \" LIMIT :limit\" : '');\n    $auftraege = $this->app->DatabaseService->select($auftraegeSql, $limit > 0 ? ['limit' => (int) $limit] : []);"
)

print(f"\nSummary: {patches_applied} applied, {patches_failed} NOT FOUND")

with open(filepath, 'w', encoding='utf-8') as f:
    f.write(content)

print("File written.")
