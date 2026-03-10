import sys

filepath = r"C:\Users\3D Partner\Documents\openxe_rework\OpenXE\www\lib\class.erpapi.php"
with open(filepath, 'r', encoding='utf-8') as f:
    content = f.read()

changes = []

# Fix 1: AddBaugruppenChargeMHD INSERT produktion_charge
changes.append((
    "    $this->app->DB->Insert(\"INSERT INTO produktion_charge (bezeichnung, kommentar, artikel, produktion,typ,anzahl,chargennummer,mhd,bearbeiter) VALUES ('$bezeichnung','$kommentar','$artikel','$id','$typ','$anzahl','$chargennummer','$mhd','\" . $this->app->DB->real_escape_string($this->app->User->GetName()) . \"')\");\n"
    "    echo $this->app->DB->error();\n"
    "    $newid = $this->app->DB->GetInsertID();",

    "    $newid = $this->app->DatabaseService->insert(\"INSERT INTO produktion_charge (bezeichnung, kommentar, artikel, produktion, typ, anzahl, chargennummer, mhd, bearbeiter) VALUES ('', :kommentar, :artikel, :id, :typ, :anzahl, :chargennummer, :mhd, :bearbeiter)\", ['kommentar' => $kommentar, 'artikel' => (int)$artikel, 'id' => (int)$id, 'typ' => $typ, 'anzahl' => $anzahl, 'chargennummer' => $chargennummer, 'mhd' => $mhd, 'bearbeiter' => $this->app->User->GetName()]);"
))

# Fix 2: AddBaugruppenChargeMHD SelectArr baugruppen
changes.append((
    "    $baugruppen = $this->app->DB->SelectArr(\"SELECT pb.id, ifnull(count(pbc.id),0) as co FROM produktion_baugruppen pb \n"
    "    LEFT JOIN produktion_baugruppen_charge pbc ON pbc.baugruppe = pb.id\n"
    "    WHERE pb.produktion = '$id'\n"
    "    GROUP BY pb.id ORDER by count(pbc.id), pb.id\n"
    "    \");",

    "    $baugruppen = $this->app->DatabaseService->select(\"SELECT pb.id, ifnull(count(pbc.id),0) as co FROM produktion_baugruppen pb LEFT JOIN produktion_baugruppen_charge pbc ON pbc.baugruppe = pb.id WHERE pb.produktion = :id GROUP BY pb.id ORDER BY count(pbc.id), pb.id\", ['id' => (int)$id]);"
))

# Fix 3: AddBaugruppenChargeMHD INSERT produktion_baugruppen_charge
changes.append((
    "          $this->app->DB->Insert(\"INSERT INTO produktion_baugruppen_charge (produktion, baugruppe, charge, chargennummer,mhd,bearbeiter, menge) values ('$id','\" . $baugruppe['id'] . \"','$newid','$chargennummer','$mhd','\" . $this->app->DB->real_escape_string($this->app->User->GetName()) . \"','$_mengebaugruppe')\");",

    "          $this->app->DatabaseService->execute(\"INSERT INTO produktion_baugruppen_charge (produktion, baugruppe, charge, chargennummer, mhd, bearbeiter, menge) VALUES (:produktion, :baugruppe, :charge, :chargennummer, :mhd, :bearbeiter, :menge)\", ['produktion' => (int)$id, 'baugruppe' => (int)$baugruppe['id'], 'charge' => (int)$newid, 'chargennummer' => $chargennummer, 'mhd' => $mhd, 'bearbeiter' => $this->app->User->GetName(), 'menge' => $_mengebaugruppe]);"
))

# Fix 4: ChargenMHDAuslagern two Select calls
changes.append((
    "    $mindesthaltbarkeitsdatum = $this->app->DB->Select(\"SELECT mindesthaltbarkeitsdatum FROM artikel WHERE id = '$artikel' LIMIT 1\");\n"
    "    $chargenverwaltung = $this->app->DB->Select(\"SELECT chargenverwaltung FROM artikel WHERE id = '$artikel' LIMIT 1\");",

    "    $mindesthaltbarkeitsdatum = $this->app->DatabaseService->selectValue(\"SELECT mindesthaltbarkeitsdatum FROM artikel WHERE id = :id LIMIT 1\", ['id' => (int)$artikel]);\n"
    "    $chargenverwaltung = $this->app->DatabaseService->selectValue(\"SELECT chargenverwaltung FROM artikel WHERE id = :id LIMIT 1\", ['id' => (int)$artikel]);"
))

# Fix 5: ChargenMHDAuslagern subwhere lagerplatz
changes.append((
    "        if ($lpid) {\n"
    "          $subwhere = \" AND c.lager_platz = '$lpid' \";\n"
    "        }",

    "        if ($lpid) {\n"
    "          $subwhere = sprintf(\" AND c.lager_platz = %d \", (int)$lpid);\n"
    "        }"
))

# Fix 6: ChargenMHDAuslagern join lager (lp.lager = '$lpid')
changes.append((
    "          $join = \" INNER JOIN lager_platz lp ON c.lager_platz = lp.id AND lp.lager = '$lpid' AND  \" . $sperrlagerWhere;",

    "          $join = sprintf(\" INNER JOIN lager_platz lp ON c.lager_platz = lp.id AND lp.lager = %d AND \", (int)$lpid) . $sperrlagerWhere;"
))

# Fix 7: ChargenMHDAuslagern join projektlager
changes.append((
    "          $join = \" INNER JOIN lager_platz lp ON c.lager_platz = lp.id AND lp.projekt = '$projekt' AND \" . $sperrlagerWhere;",

    "          $join = sprintf(\" INNER JOIN lager_platz lp ON c.lager_platz = lp.id AND lp.projekt = %d AND \", (int)$projekt) . $sperrlagerWhere;"
))

# Fix 8: ChargenMHDAuslagern join poslager
changes.append((
    "          $join = \" INNER JOIN lager_platz lp ON c.lager_platz = lp.id AND lp.lager = '$lpid' AND lp.poslager = 1\";",

    "          $join = sprintf(\" INNER JOIN lager_platz lp ON c.lager_platz = lp.id AND lp.lager = %d AND lp.poslager = 1\", (int)$lpid);"
))

# Fix 9: ChargenMHDAuslagern mhd SelectArr
changes.append((
    "        $checkarr = $this->app->DB->SelectArr(\"SELECT c.* FROM lager_mindesthaltbarkeitsdatum c $join WHERE c.artikel = '$artikel' $subwhere ORDER BY mhddatum = '$mhd' DESC, \" . ($mhdcharge != '' ? \"charge= '$mdhcharge' DESC,\" : \"\") . \" mhddatum,charge, id \");",

    "        $_mhdOrderExpr = ($mhdcharge != '' ? sprintf(\"charge = '%s' DESC,\", addslashes($mhdcharge)) : '');\n"
    "        $checkarr = $this->app->DatabaseService->select(\"SELECT c.* FROM lager_mindesthaltbarkeitsdatum c $join WHERE c.artikel = :artikel $subwhere ORDER BY mhddatum = :mhd DESC, {$_mhdOrderExpr} mhddatum, charge, id\", ['artikel' => (int)$artikel, 'mhd' => $mhd]);"
))

# Fix 10: Delete lager_mindesthaltbarkeitsdatum in mhd loop
changes.append((
    "              $this->app->DB->Delete(\"DELETE FROM `lager_mindesthaltbarkeitsdatum` WHERE id = '\" . $c['id'] . \"' LIMIT 1\");",

    "              $this->app->DatabaseService->execute(\"DELETE FROM lager_mindesthaltbarkeitsdatum WHERE id = :id LIMIT 1\", ['id' => (int)$c['id']]);"
))

# Fix 11: SelectArr lager_charge in mhd loop (first occurrence, with '$lager_platz')
changes.append((
    "                $checkchargen = $this->app->DB->SelectArr(\"SELECT * FROM lager_charge WHERE artikel = '$artikel' AND lager_platz = '$lager_platz' AND charge = '\" . $c['charge'] . \"' ORDER BY id \");\n"
    "                if ($checkchargen) {\n"
    "                  $cnochmenge = $c['menge'];\n"
    "                  foreach ($checkchargen as $cc) {\n"
    "                    if ($cnochmenge <= 0)\n"
    "                      break;\n"
    "                    if ($cc['menge'] <= $cnochmenge) {\n"
    "                      $this->app->DB->Delete(\"DELETE FROM lager_charge WHERE id = '\" . $cc['id'] . \"' LIMIT 1\");\n"
    "                      $cnochmenge -= $cc['menge'];\n"
    "                    } else {\n"
    "                      $this->app->DB->Update(\"UPDATE lager_mindesthaltbarkeitsdatum SET menge = menge - '\" . $cnochmenge . \"' WHERE  id = '\" . $cc['id'] . \"' LIMIT 1\");\n"
    "                      break;\n"
    "                    }\n"
    "                  }\n"
    "                }\n"
    "              }\n"
    "              $nochmenge -= $c['menge'];\n"
    "            } else {\n"
    "              if ($doctype == 'produktion')\n"
    "                $this->AddBaugruppenChargeMHD($doctypeid, $artikel, $nochmenge, $c['charge'], $c['mhddatum']);\n"
    "              $this->app->DB->Update(\"UPDATE lager_charge SET menge = menge - '\" . $nochmenge . \"' WHERE  id = '\" . $c['id'] . \"' LIMIT 1\");\n"
    "              if ($chargenverwaltung) {\n"
    "                $checkchargen = $this->app->DB->SelectArr(\"SELECT * FROM lager_charge WHERE artikel = '$artikel' AND lager_platz = '$lager_platz' AND charge = '\" . $c['charge'] . \"' ORDER BY id \");\n"
    "                if ($checkchargen) {\n"
    "                  $cnochmenge = $nochmenge;\n"
    "                  foreach ($checkchargen as $cc) {\n"
    "                    if ($cnochmenge <= 0)\n"
    "                      break;\n"
    "                    if ($cc['menge'] <= $cnochmenge) {\n"
    "                      $this->app->DB->Delete(\"DELETE FROM lager_charge WHERE id = '\" . $cc['id'] . \"' LIMIT 1\");\n"
    "                      $cnochmenge -= $cc['menge'];\n"
    "                    } else {\n"
    "                      $this->app->DB->Update(\"UPDATE lager_mindesthaltbarkeitsdatum SET menge = menge - '\" . $cnochmenge . \"' WHERE  id = '\" . $cc['id'] . \"' LIMIT 1\");\n"
    "                      break;\n"
    "                    }\n"
    "                  }\n"
    "                }\n"
    "              }",

    "                $checkchargen = $this->app->DatabaseService->select(\"SELECT * FROM lager_charge WHERE artikel = :artikel AND lager_platz = :lager_platz AND charge = :charge ORDER BY id\", ['artikel' => (int)$artikel, 'lager_platz' => (int)$lager_platz, 'charge' => $c['charge']]);\n"
    "                if ($checkchargen) {\n"
    "                  $cnochmenge = $c['menge'];\n"
    "                  foreach ($checkchargen as $cc) {\n"
    "                    if ($cnochmenge <= 0)\n"
    "                      break;\n"
    "                    if ($cc['menge'] <= $cnochmenge) {\n"
    "                      $this->app->DatabaseService->execute(\"DELETE FROM lager_charge WHERE id = :id LIMIT 1\", ['id' => (int)$cc['id']]);\n"
    "                      $cnochmenge -= $cc['menge'];\n"
    "                    } else {\n"
    "                      $this->app->DatabaseService->execute(\"UPDATE lager_mindesthaltbarkeitsdatum SET menge = menge - :menge WHERE id = :id LIMIT 1\", ['menge' => $cnochmenge, 'id' => (int)$cc['id']]);\n"
    "                      break;\n"
    "                    }\n"
    "                  }\n"
    "                }\n"
    "              }\n"
    "              $nochmenge -= $c['menge'];\n"
    "            } else {\n"
    "              if ($doctype == 'produktion')\n"
    "                $this->AddBaugruppenChargeMHD($doctypeid, $artikel, $nochmenge, $c['charge'], $c['mhddatum']);\n"
    "              $this->app->DatabaseService->execute(\"UPDATE lager_charge SET menge = menge - :menge WHERE id = :id LIMIT 1\", ['menge' => $nochmenge, 'id' => (int)$c['id']]);\n"
    "              if ($chargenverwaltung) {\n"
    "                $checkchargen = $this->app->DatabaseService->select(\"SELECT * FROM lager_charge WHERE artikel = :artikel AND lager_platz = :lager_platz AND charge = :charge ORDER BY id\", ['artikel' => (int)$artikel, 'lager_platz' => (int)$lager_platz, 'charge' => $c['charge']]);\n"
    "                if ($checkchargen) {\n"
    "                  $cnochmenge = $nochmenge;\n"
    "                  foreach ($checkchargen as $cc) {\n"
    "                    if ($cnochmenge <= 0)\n"
    "                      break;\n"
    "                    if ($cc['menge'] <= $cnochmenge) {\n"
    "                      $this->app->DatabaseService->execute(\"DELETE FROM lager_charge WHERE id = :id LIMIT 1\", ['id' => (int)$cc['id']]);\n"
    "                      $cnochmenge -= $cc['menge'];\n"
    "                    } else {\n"
    "                      $this->app->DatabaseService->execute(\"UPDATE lager_mindesthaltbarkeitsdatum SET menge = menge - :menge WHERE id = :id LIMIT 1\", ['menge' => $cnochmenge, 'id' => (int)$cc['id']]);\n"
    "                      break;\n"
    "                    }\n"
    "                  }\n"
    "                }\n"
    "              }"
))

# Fix 12: ChargenMHDAuslagern charge case SelectArr
changes.append((
    "        $checkarr = $this->app->DB->SelectArr(\"SELECT c.* FROM lager_charge c $join WHERE c.artikel = '$artikel' $subwhere ORDER BY charge = '$wert' DESC, charge, id \");",

    "        $checkarr = $this->app->DatabaseService->select(\"SELECT c.* FROM lager_charge c $join WHERE c.artikel = :artikel $subwhere ORDER BY charge = :wert DESC, charge, id\", ['artikel' => (int)$artikel, 'wert' => $wert]);"
))

# Fix 13: ChargenMHDAuslagern charge Delete in loop
changes.append((
    "              $this->app->DB->Delete(\"DELETE FROM lager_charge WHERE id = '\" . $c['id'] . \"' LIMIT 1\");",

    "              $this->app->DatabaseService->execute(\"DELETE FROM lager_charge WHERE id = :id LIMIT 1\", ['id' => (int)$c['id']]);"
))

# Fix 14: ChargenMHDAuslagern charge Update in loop
changes.append((
    "              $this->app->DB->Update(\"UPDATE lager_charge SET menge = menge - $nochmenge WHERE id = '\" . $c['id'] . \"' LIMIT 1\");",

    "              $this->app->DatabaseService->execute(\"UPDATE lager_charge SET menge = menge - :menge WHERE id = :id LIMIT 1\", ['menge' => $nochmenge, 'id' => (int)$c['id']]);"
))

# Fix 15: CreateBelegPositionMHDCHARGESRNArr bulk INSERT
changes.append((
    "      $sql .= \" ('$doctype','$doctypeid','$pos','\" . $v['type'] . \"','\" . $v['wert'] . \"','\" . $v['menge'] . \"','\" . $v['type2'] . \"','\" . $v['wert2'] . \"','$lager_platz') \";\n"
    "      $first = false;\n"
    "    }\n"
    "    $this->app->DB->Insert($sql);",

    "      $sql .= \" (:doctype,:doctypeid,:pos,:type,:wert,:menge,:type2,:wert2,:lager_platz) \";\n"
    "      $params[] = ['doctype' => $doctype, 'doctypeid' => (int)$doctypeid, 'pos' => (int)$pos, 'type' => $v['type'], 'wert' => $v['wert'], 'menge' => $v['menge'], 'type2' => $v['type2'], 'wert2' => $v['wert2'], 'lager_platz' => (int)$lager_platz];\n"
    "      $first = false;\n"
    "    }\n"
    "    foreach ($params as $p) {\n"
    "      $this->app->DatabaseService->execute(\"INSERT INTO beleg_chargesnmhd (doctype,doctypeid,pos,type,wert,menge,type2,wert2,lagerplatz) VALUES (:doctype,:doctypeid,:pos,:type,:wert,:menge,:type2,:wert2,:lager_platz)\", $p);\n"
    "    }"
))

# Fix 16: CreateBelegPositionMHDCHARGESRNArr - add $params=[] init before the loop
changes.append((
    "    $first = true;\n"
    "    $sql = \"INSERT INTO beleg_chargesnmhd (doctype,doctypeid,pos,type,wert,menge, type2, wert2, lagerplatz) VALUES \";",

    "    $first = true;\n"
    "    $params = [];\n"
    "    $sql = \"INSERT INTO beleg_chargesnmhd (doctype,doctypeid,pos,type,wert,menge, type2, wert2, lagerplatz) VALUES \";"
))

# Fix 17: CreateBelegPositionMHDCHARGESRN INSERT
changes.append((
    "    $this->app->DB->Insert(\n"
    "      \"INSERT INTO beleg_chargesnmhd (doctype,doctypeid,pos,type,wert,menge, type2, wert2) VALUES \n"
    "        ('$doctype','$doctypeid','$pos','$type','$wert','$menge','$type2','$wert2')\"\n"
    "    );\n"
    "    $ind = $this->app->DB->GetInsertID();\n"
    "    if ($lager_platz) {\n"
    "      $this->app->DB->Update(\"UPDATE beleg_chargesnmhd set lagerplatz = '\" . $lager_platz . \"' WHERE id = '$ind' LIMIT 1\");\n"
    "    }\n"
    "    if ($internebemerkung != '') {\n"
    "      $this->app->DB->Update(\"UPDATE beleg_chargesnmhd set internebemerkung = '\" . $this->app->DB->real_escape_string($internebemerkung) . \"' WHERE id = '$ind' LIMIT 1\");\n"
    "    }",

    "    $ind = $this->app->DatabaseService->insert(\n"
    "      \"INSERT INTO beleg_chargesnmhd (doctype,doctypeid,pos,type,wert,menge,type2,wert2) VALUES (:doctype,:doctypeid,:pos,:type,:wert,:menge,:type2,:wert2)\",\n"
    "      ['doctype' => $doctype, 'doctypeid' => (int)$doctypeid, 'pos' => (int)$pos, 'type' => $type, 'wert' => $wert, 'menge' => $menge, 'type2' => $type2, 'wert2' => $wert2]\n"
    "    );\n"
    "    if ($lager_platz) {\n"
    "      $this->app->DatabaseService->execute(\"UPDATE beleg_chargesnmhd SET lagerplatz = :lp WHERE id = :id LIMIT 1\", ['lp' => (int)$lager_platz, 'id' => $ind]);\n"
    "    }\n"
    "    if ($internebemerkung != '') {\n"
    "      $this->app->DatabaseService->execute(\"UPDATE beleg_chargesnmhd SET internebemerkung = :note WHERE id = :id LIMIT 1\", ['note' => $internebemerkung, 'id' => $ind]);\n"
    "    }"
))

# Fix 18: CreateBelegPositionMHDCHARGESRN Select artikel for SeriennummernLog (dynamic table)
changes.append((
    "      $this->app->erp->SeriennummernLog($this->app->DB->Select(\"SELECT artikel FROM $doctype\" . \"_position WHERE id = '$pos' LIMIT 1\"), $lager_platz, $wert, 0, \"\", 0, \"\", \"\", $doctype, $doctypeid);",

    "      $_safeDtPos = $this->app->DatabaseService->validateIdentifier($doctype . '_position');\n"
    "      $this->app->erp->SeriennummernLog($this->app->DatabaseService->selectValue(\"SELECT artikel FROM `$_safeDtPos` WHERE id = :id LIMIT 1\", ['id' => (int)$pos]), $lager_platz, $wert, 0, \"\", 0, \"\", \"\", $doctype, $doctypeid);"
))

# Fix 19: MHDAuslagernLog adresse Select (dynamic $doctype)
changes.append((
    "      $adresse = $this->app->DB->Select(\"SELECT adresse FROM $doctype WHERE id = '$doctypeid' LIMIT 1\");\n"
    "    }\n"
    "    if ($lager_chargenid) {\n"
    "      $arr = $this->app->DB->SelectArr(\"SELECT id, lager_platz, menge, charge,mhddatum FROM lager_mindesthaltbarkeitsdatum WHERE id = '$lager_chargenid' AND artikel = '$artikel' LIMIT 1\");\n"
    "    } else {\n"
    "      $arr = $this->app->DB->SelectArr(\"SELECT id, lager_platz, menge, charge,mhddatum FROM lager_mindesthaltbarkeitsdatum \n"
    "        WHERE artikel = '$artikel' AND lager_platz = '$lager_platz'\n"
    "        \" . ($mhddatum != '' ? \" AND mhddatum = '$mhddatum'\" : '') . \"  \n"
    "        \" . ($charge != '' ? \" AND charge = '$charge'\" : '') . \" AND menge > 0\");\n"
    "    }",

    "      $_safeDocMHD = $this->app->DatabaseService->validateIdentifier($doctype);\n"
    "      $adresse = $this->app->DatabaseService->selectValue(\"SELECT adresse FROM `$_safeDocMHD` WHERE id = :id LIMIT 1\", ['id' => (int)$doctypeid]);\n"
    "    }\n"
    "    if ($lager_chargenid) {\n"
    "      $arr = $this->app->DatabaseService->select(\"SELECT id, lager_platz, menge, charge, mhddatum FROM lager_mindesthaltbarkeitsdatum WHERE id = :chargenid AND artikel = :artikel LIMIT 1\", ['chargenid' => (int)$lager_chargenid, 'artikel' => (int)$artikel]);\n"
    "    } else {\n"
    "      $_mhdWhere = ($mhddatum != '' ? \" AND mhddatum = :mhddatum\" : '');\n"
    "      $_chargeWhere = ($charge != '' ? \" AND charge = :charge\" : '');\n"
    "      $_mhdParams = ['artikel' => (int)$artikel, 'lager_platz' => (int)$lager_platz];\n"
    "      if ($mhddatum != '') $_mhdParams['mhddatum'] = $mhddatum;\n"
    "      if ($charge != '') $_mhdParams['charge'] = $charge;\n"
    "      $arr = $this->app->DatabaseService->select(\"SELECT id, lager_platz, menge, charge, mhddatum FROM lager_mindesthaltbarkeitsdatum WHERE artikel = :artikel AND lager_platz = :lager_platz {$_mhdWhere} {$_chargeWhere} AND menge > 0\", $_mhdParams);\n"
    "    }"
))

# Fix 20: MHDAuslagernLog Delete + Update
changes.append((
    "        $this->app->DB->Delete(\"DELETE FROM lager_mindesthaltbarkeitsdatum WHERE id = '\" . $v['id'] . \"' LIMIT 1\");\n"
    "        $this->MHDLog($artikel, $v['lager_platz'], 0, $v['mhddatum'], $v['menge'], $internebemerkung, $doctype, $doctypeid, $v['charge']);\n"
    "      } else {\n"
    "        $this->app->DB->Update(\"UPDATE lager_mindesthaltbarkeitsdatum SET menge = menge - $menge WHERE id = '\" . $v['id'] . \"' LIMIT 1\");",

    "        $this->app->DatabaseService->execute(\"DELETE FROM lager_mindesthaltbarkeitsdatum WHERE id = :id LIMIT 1\", ['id' => (int)$v['id']]);\n"
    "        $this->MHDLog($artikel, $v['lager_platz'], 0, $v['mhddatum'], $v['menge'], $internebemerkung, $doctype, $doctypeid, $v['charge']);\n"
    "      } else {\n"
    "        $this->app->DatabaseService->execute(\"UPDATE lager_mindesthaltbarkeitsdatum SET menge = menge - :menge WHERE id = :id LIMIT 1\", ['menge' => $menge, 'id' => (int)$v['id']]);"
))

# Fix 21: ChargeAuslagernLog adresse Select (dynamic $doctype)
changes.append((
    "      $adresse = $this->app->DB->Select(\"SELECT adresse FROM $doctype WHERE id = '$doctypeid' LIMIT 1\");\n"
    "    }\n"
    "    if ($lager_chargenid) {\n"
    "      $arr = $this->app->DB->SelectArr(\"SELECT id, lager_platz, menge, charge FROM lager_charge WHERE id = '$lager_chargenid' AND artikel = '$artikel' LIMIT 1\");\n"
    "    } else {\n"
    "      $arr = $this->app->DB->SelectArr(\"SELECT id, lager_platz, menge, charge FROM lager_charge WHERE artikel = '$artikel' AND lager_platz = '$lager_platz' AND charge = '$charge' AND menge > 0\");\n"
    "    }",

    "      $_safeDocCA = $this->app->DatabaseService->validateIdentifier($doctype);\n"
    "      $adresse = $this->app->DatabaseService->selectValue(\"SELECT adresse FROM `$_safeDocCA` WHERE id = :id LIMIT 1\", ['id' => (int)$doctypeid]);\n"
    "    }\n"
    "    if ($lager_chargenid) {\n"
    "      $arr = $this->app->DatabaseService->select(\"SELECT id, lager_platz, menge, charge FROM lager_charge WHERE id = :chargenid AND artikel = :artikel LIMIT 1\", ['chargenid' => (int)$lager_chargenid, 'artikel' => (int)$artikel]);\n"
    "    } else {\n"
    "      $arr = $this->app->DatabaseService->select(\"SELECT id, lager_platz, menge, charge FROM lager_charge WHERE artikel = :artikel AND lager_platz = :lager_platz AND charge = :charge AND menge > 0\", ['artikel' => (int)$artikel, 'lager_platz' => (int)$lager_platz, 'charge' => $charge]);\n"
    "    }"
))

# Fix 22: ChargeAuslagernLog Delete + Update
changes.append((
    "        $this->app->DB->Delete(\"DELETE FROM lager_charge WHERE id = '\" . $v['id'] . \"' LIMIT 1\");\n"
    "        $this->Chargenlog($artikel, $v['lager_platz'], 0, $charge, $v['menge'], $internebemerkung, $doctype, $doctypeid, $adresse, 0, $isInterim);\n"
    "      } else {\n"
    "        $this->app->DB->Update(\"UPDATE lager_charge SET menge = menge - $menge WHERE id = '\" . $v['id'] . \"' LIMIT 1\");",

    "        $this->app->DatabaseService->execute(\"DELETE FROM lager_charge WHERE id = :id LIMIT 1\", ['id' => (int)$v['id']]);\n"
    "        $this->Chargenlog($artikel, $v['lager_platz'], 0, $charge, $v['menge'], $internebemerkung, $doctype, $doctypeid, $adresse, 0, $isInterim);\n"
    "      } else {\n"
    "        $this->app->DatabaseService->execute(\"UPDATE lager_charge SET menge = menge - :menge WHERE id = :id LIMIT 1\", ['menge' => $menge, 'id' => (int)$v['id']]);"
))

# Fix 23: Chargenlog adresse Select
changes.append((
    "      $adresse = $this->app->DB->Select(\"SELECT adresse FROM $doctype WHERE id = '$doctypeid' LIMIT 1\");\n"
    "    }\n"
    "    $internebemerkung = $this->app->DB->real_escape_string($internebemerkung);\n"
    "    $bestand = $this->app->DB->Select(\"SELECT ifnull(sum(menge),0) FROM lager_charge WHERE artikel = '$artikel' AND lager_platz = '$lager_platz' AND charge = '$charge'\");\n"
    "    $this->RunHook('chargenlog_bestand', 4, $artikel, $lager_platz, $charge, $bestand);\n"
    "    if ($chargen_log_id) {\n"
    "      $chargen_log_id = $this->app->DB->Select(\"SELECT id FROM chargen_log WHERE id='$chargen_log_id' AND eingang = '$eingang' AND artikel = '$artikel' AND charge = '$charge' AND doctype = '$doctype' AND doctypeid = '$doctypeid' AND adresse = '$adresse' LIMIT 1\");\n"
    "    }\n"
    "    if ($chargen_log_id) {\n"
    "      $this->app->DB->Update(\"UPDATE chargen_log SET menge = menge + $menge, bestand = '$bestand' WHERE id = '$chargen_log_id' LIMIT 1\");\n"
    "      return $chargen_log_id;\n"
    "    }\n"
    "    $this->app->DB->Insert(\"INSERT INTO chargen_log (artikel,lager_platz,eingang,bezeichnung,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,bestand,adresse,is_interim) \n"
    "            VALUES ('$artikel','$lager_platz','$eingang','\" . $charge . \"',NOW(),\" . (int) $this->app->User->GetAdresse() . \",'\" . $menge . \"','$internebemerkung','$doctype','$doctypeid','$bestand','$adresse',\" . (int) $isInterim . \")\");\n"
    "    return $this->app->DB->GetInsertID();",

    "      $_safeDocCL = $this->app->DatabaseService->validateIdentifier($doctype);\n"
    "      $adresse = $this->app->DatabaseService->selectValue(\"SELECT adresse FROM `$_safeDocCL` WHERE id = :id LIMIT 1\", ['id' => (int)$doctypeid]);\n"
    "    }\n"
    "    $bestand = $this->app->DatabaseService->selectValue(\"SELECT ifnull(sum(menge),0) FROM lager_charge WHERE artikel = :artikel AND lager_platz = :lager_platz AND charge = :charge\", ['artikel' => (int)$artikel, 'lager_platz' => (int)$lager_platz, 'charge' => $charge]);\n"
    "    $this->RunHook('chargenlog_bestand', 4, $artikel, $lager_platz, $charge, $bestand);\n"
    "    if ($chargen_log_id) {\n"
    "      $chargen_log_id = $this->app->DatabaseService->selectValue(\"SELECT id FROM chargen_log WHERE id = :cid AND eingang = :eingang AND artikel = :artikel AND charge = :charge AND doctype = :doctype AND doctypeid = :doctypeid AND adresse = :adresse LIMIT 1\", ['cid' => (int)$chargen_log_id, 'eingang' => (int)$eingang, 'artikel' => (int)$artikel, 'charge' => $charge, 'doctype' => $doctype, 'doctypeid' => (int)$doctypeid, 'adresse' => (int)$adresse]);\n"
    "    }\n"
    "    if ($chargen_log_id) {\n"
    "      $this->app->DatabaseService->execute(\"UPDATE chargen_log SET menge = menge + :menge, bestand = :bestand WHERE id = :id LIMIT 1\", ['menge' => $menge, 'bestand' => $bestand, 'id' => (int)$chargen_log_id]);\n"
    "      return $chargen_log_id;\n"
    "    }\n"
    "    return $this->app->DatabaseService->insert(\"INSERT INTO chargen_log (artikel,lager_platz,eingang,bezeichnung,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,bestand,adresse,is_interim) VALUES (:artikel,:lager_platz,:eingang,:bezeichnung,NOW(),:adresse_mitarbeiter,:menge,:internebemerkung,:doctype,:doctypeid,:bestand,:adresse,:is_interim)\", ['artikel' => (int)$artikel, 'lager_platz' => (int)$lager_platz, 'eingang' => (int)$eingang, 'bezeichnung' => $charge, 'adresse_mitarbeiter' => (int)$this->app->User->GetAdresse(), 'menge' => $menge, 'internebemerkung' => $internebemerkung, 'doctype' => $doctype, 'doctypeid' => (int)$doctypeid, 'bestand' => $bestand, 'adresse' => (int)$adresse, 'is_interim' => (int)$isInterim]);"
))

# Fix 24: ChargenLogArray adresse Select
changes.append((
    "      $adresse = $this->app->DB->Select(\"SELECT adresse FROM $doctype WHERE id = '$doctypeid' LIMIT 1\");\n"
    "    }\n"
    "    $internebemerkung = $this->app->DB->real_escape_string($internebemerkung);\n"
    "    $sql = \"INSERT INTO chargen_log (artikel,lager_platz,eingang,bezeichnung,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,bestand,adresse) VALUES \";",

    "      $_safeDocCLA = $this->app->DatabaseService->validateIdentifier($doctype);\n"
    "      $adresse = $this->app->DatabaseService->selectValue(\"SELECT adresse FROM `$_safeDocCLA` WHERE id = :id LIMIT 1\", ['id' => (int)$doctypeid]);\n"
    "    }\n"
    "    $sql = \"INSERT INTO chargen_log (artikel,lager_platz,eingang,bezeichnung,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,bestand,adresse) VALUES \";"
))

# Fix 25: ChargenLogArray bulk INSERT
changes.append((
    "      $sql .= \" ('$artikel','$lager_platz','$eingang','\" . $data[$k]['charge'] . \"',NOW(),\" . $useradresse . \",'\" . $data[$k]['menge'] . \"','$internebemerkung','$doctype','$doctypeid','\" . $data[$k]['bestand'] . \"','$adresse') \";\n"
    "      $first = false;\n"
    "    }\n"
    "    $this->app->DB->Insert($sql);\n"
    "  }\n"
    "\n"
    "  function MHDLog(",

    "      $sql .= \" ('$artikel','$lager_platz','$eingang','\" . $data[$k]['charge'] . \"',NOW(),\" . $useradresse . \",'\" . $data[$k]['menge'] . \"','$internebemerkung','$doctype','$doctypeid','\" . $data[$k]['bestand'] . \"','$adresse') \";\n"
    "      $first = false;\n"
    "    }\n"
    "    if (!$first) {\n"
    "      $this->app->DB->Insert($sql);\n"
    "    }\n"
    "  }\n"
    "\n"
    "  function MHDLog("
))

# Fix 26: MHDLog adresse Select
changes.append((
    "      $adresse = $this->app->DB->Select(\"SELECT adresse FROM $doctype WHERE id = '$doctypeid' LIMIT 1\");\n"
    "    }\n"
    "    $internebemerkung = $this->app->DB->real_escape_string($internebemerkung);\n"
    "    $bestand = $this->app->DB->Select(\"SELECT ifnull(sum(menge),0) FROM lager_mindesthaltbarkeitsdatum WHERE artikel = '$artikel' AND lager_platz = '$lager_platz' AND mhddatum = '$mhd' AND ifnull(charge,'') = '$charge'\");\n"
    "    $this->RunHook('mhdlog_bestand', 4, $artikel, $lager_platz, $mhd, $bestand);\n"
    "    $this->app->DB->Insert(\"INSERT INTO mhd_log (artikel,lager_platz,eingang,mhddatum,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,bestand,adresse,is_interim) \n"
    "            VALUES ('$artikel','$lager_platz','$eingang','\" . $mhd . \"',NOW(),\" . (int) $this->app->User->GetAdresse() . \",'\" . $menge . \"','$internebemerkung','$doctype','$doctypeid','$bestand','$adresse',\" . (int) $isInterim . \")\");\n"
    "    $insid = $this->app->DB->GetInsertID();\n"
    "    if ($charge != '') {\n"
    "      $this->app->DB->Update(\"UPDATE mhd_log SET charge = '$charge' WHERE id = '$insid' LIMIT 1\");\n"
    "    }",

    "      $_safeDocML = $this->app->DatabaseService->validateIdentifier($doctype);\n"
    "      $adresse = $this->app->DatabaseService->selectValue(\"SELECT adresse FROM `$_safeDocML` WHERE id = :id LIMIT 1\", ['id' => (int)$doctypeid]);\n"
    "    }\n"
    "    $bestand = $this->app->DatabaseService->selectValue(\"SELECT ifnull(sum(menge),0) FROM lager_mindesthaltbarkeitsdatum WHERE artikel = :artikel AND lager_platz = :lager_platz AND mhddatum = :mhd AND ifnull(charge,'') = :charge\", ['artikel' => (int)$artikel, 'lager_platz' => (int)$lager_platz, 'mhd' => $mhd, 'charge' => $charge]);\n"
    "    $this->RunHook('mhdlog_bestand', 4, $artikel, $lager_platz, $mhd, $bestand);\n"
    "    $insid = $this->app->DatabaseService->insert(\"INSERT INTO mhd_log (artikel,lager_platz,eingang,mhddatum,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,bestand,adresse,is_interim) VALUES (:artikel,:lager_platz,:eingang,:mhd,NOW(),:adresse_mitarbeiter,:menge,:internebemerkung,:doctype,:doctypeid,:bestand,:adresse,:is_interim)\", ['artikel' => (int)$artikel, 'lager_platz' => (int)$lager_platz, 'eingang' => (int)$eingang, 'mhd' => $mhd, 'adresse_mitarbeiter' => (int)$this->app->User->GetAdresse(), 'menge' => $menge, 'internebemerkung' => $internebemerkung, 'doctype' => $doctype, 'doctypeid' => (int)$doctypeid, 'bestand' => $bestand, 'adresse' => (int)$adresse, 'is_interim' => (int)$isInterim]);\n"
    "    if ($charge != '') {\n"
    "      $this->app->DatabaseService->execute(\"UPDATE mhd_log SET charge = :charge WHERE id = :id LIMIT 1\", ['charge' => $charge, 'id' => $insid]);\n"
    "    }"
))

# Fix 27: MHDLogArray adresse + bulk INSERT
changes.append((
    "    $sql = \"INSERT INTO mhd_log (artikel,lager_platz,eingang,mhddatum,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,bestand,adresse,charge) VALUES \";\n"
    "    $first = true;\n"
    "    foreach ($data as $k => $v) {\n"
    "      $this->RunHook('mhdlog_bestand', 4, $artikel, $lager_platz, $data[$k]['mhd'], $data[$k]['bestand']);\n"
    "      if (!$first) {\n"
    "        $sql .= ', ';\n"
    "      }\n"
    "      $sql .= \"('$artikel','$lager_platz','$eingang','\" . $data[$k]['mhd'] . \"',NOW(),\" . (int) $this->app->User->GetAdresse() . \",'\" . $data[$k]['menge'] . \"','$internebemerkung','$doctype','$doctypeid','\" . $data[$k]['bestand'] . \"','$adresse','\" . $data[$k]['charge'] . \"')\";\n"
    "      $first = false;\n"
    "    }\n"
    "    $this->app->DB->Insert($sql);",

    "    $useradresseMHD = isset($this->app->User) && $this->app->User && method_exists($this->app->User, 'GetAdresse') ? (int)$this->app->User->GetAdresse() : 0;\n"
    "    foreach ($data as $k => $v) {\n"
    "      $this->RunHook('mhdlog_bestand', 4, $artikel, $lager_platz, $data[$k]['mhd'], $data[$k]['bestand']);\n"
    "      $this->app->DatabaseService->execute(\"INSERT INTO mhd_log (artikel,lager_platz,eingang,mhddatum,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,bestand,adresse,charge) VALUES (:artikel,:lager_platz,:eingang,:mhd,NOW(),:adresse_mitarbeiter,:menge,:internebemerkung,:doctype,:doctypeid,:bestand,:adresse,:charge)\", ['artikel' => (int)$artikel, 'lager_platz' => (int)$lager_platz, 'eingang' => (int)$eingang, 'mhd' => $data[$k]['mhd'], 'adresse_mitarbeiter' => $useradresseMHD, 'menge' => $data[$k]['menge'], 'internebemerkung' => $internebemerkung, 'doctype' => $doctype, 'doctypeid' => (int)$doctypeid, 'bestand' => $data[$k]['bestand'], 'adresse' => (int)$adresse, 'charge' => $data[$k]['charge']]);\n"
    "    }"
))

# Fix 28: removeBatchFromStock lager_chargen SelectArr (sprintf with $batch in string)
changes.append((
    "      $lager_chargen = $this->app->DB->SelectArr(\n"
    "        sprintf(\n"
    "          \"SELECT id, menge, charge \n"
    "          FROM lager_charge \n"
    "          WHERE artikel=%d AND lager_platz=%d AND menge > 0 AND charge = '%s'\n"
    "          ORDER BY $extraorder id \n"
    "          LIMIT %d\",\n"
    "          (int) $articleId,\n"
    "          (int) $storageLocationId,\n"
    "          $batch,\n"
    "          ceil($toRemove)\n"
    "        )\n"
    "      );",

    "      $lager_chargen = $this->app->DatabaseService->select(\n"
    "        sprintf(\"SELECT id, menge, charge FROM lager_charge WHERE artikel = %d AND lager_platz = %d AND menge > 0 AND charge = :charge ORDER BY {$extraorder}id LIMIT %d\", (int)$articleId, (int)$storageLocationId, (int)ceil($toRemove)),\n"
    "        ['charge' => $batch]\n"
    "      );"
))

# Fix 29: removeBatchFromStock Update menge=0 (first Update)
changes.append((
    "          $this->app->DB->Update(\"UPDATE lager_charge SET menge = 0 WHERE id='\" . $v['id'] . \"' AND menge = '\" . $v['menge'] . \"' LIMIT 1\");\n"
    "          if ($this->app->DB->affected_rows() > 0) {\n"
    "            $this->app->DB->Delete(\"DELETE FROM lager_charge WHERE id='\" . $v['id'] . \"' AND id > 0 LIMIT 1\");",

    "          if ($this->app->DatabaseService->update(\"UPDATE lager_charge SET menge = 0 WHERE id = :id AND menge = :menge LIMIT 1\", ['id' => (int)$v['id'], 'menge' => $v['menge']]) > 0) {\n"
    "            $this->app->DatabaseService->execute(\"DELETE FROM lager_charge WHERE id = :id AND id > 0 LIMIT 1\", ['id' => (int)$v['id']]);"
))

# Fix 30: removeBatchFromStock Update menge-toRemove (second Update)
changes.append((
    "          $this->app->DB->Update(\"UPDATE lager_charge SET menge = menge - $toRemove WHERE id='\" . $v['id'] . \"' AND menge >= '$toRemove' LIMIT 1\");\n"
    "          if ($this->app->DB->affected_rows() > 0) {\n"
    "            $this->app->DB->Delete(\"DELETE FROM lager_charge WHERE id='\" . $v['id'] . \"' AND id > 0 AND menge <= 0 LIMIT 1\");",

    "          if ($this->app->DatabaseService->update(\"UPDATE lager_charge SET menge = menge - :toRemove WHERE id = :id AND menge >= :toRemove LIMIT 1\", ['toRemove' => $toRemove, 'id' => (int)$v['id']]) > 0) {\n"
    "            $this->app->DatabaseService->execute(\"DELETE FROM lager_charge WHERE id = :id AND id > 0 AND menge <= 0 LIMIT 1\", ['id' => (int)$v['id']]);"
))

# Fix 31: removeBatchFromStock bestandsql (articleId/storageLocationId in inline SQL)
changes.append((
    "        $bestandsql = \"SELECT sum(menge) as bestand, charge FROM lager_charge WHERE  artikel='$articleId' AND lager_platz='$storageLocationId' AND menge > 0 \";\n"
    "        foreach ($chargen_log_arr as $chargenkey => $arr) {\n"
    "          $chargensqla[] = \"'\" . substr($chargenkey, 1) . \"'\";\n"
    "        }\n"
    "        $bestandsql .= \" AND ifnull(charge,'') in (\" . implode(', ', $chargensqla) . \")  GROUP BY  charge\";\n"
    "        $query = $this->app->DB->Query($bestandsql);\n"
    "        if ($query) {\n"
    "          while ($row = $this->app->DB->Fetch_Assoc($query)) {\n"
    "            if (isset($chargen_log_arr['C' . $row['charge']])) {\n"
    "              $chargen_log_arr['C' . $row['charge']]['bestand'] = $row['bestand'];\n"
    "            }\n"
    "          }\n"
    "        }",

    "        $chargensqla = [];\n"
    "        foreach ($chargen_log_arr as $chargenkey => $arr) {\n"
    "          $chargensqla[] = \"'\" . addslashes(substr($chargenkey, 1)) . \"'\";\n"
    "        }\n"
    "        $_bestandChargeInList = implode(', ', $chargensqla);\n"
    "        $bestandRows = $this->app->DatabaseService->select(\n"
    "          sprintf(\"SELECT sum(menge) as bestand, charge FROM lager_charge WHERE artikel = %d AND lager_platz = %d AND menge > 0 AND ifnull(charge,'') IN (%s) GROUP BY charge\", (int)$articleId, (int)$storageLocationId, $_bestandChargeInList)\n"
    "        );\n"
    "        foreach ($bestandRows as $row) {\n"
    "          if (isset($chargen_log_arr['C' . $row['charge']])) {\n"
    "            $chargen_log_arr['C' . $row['charge']]['bestand'] = $row['bestand'];\n"
    "          }\n"
    "        }"
))

for old, new in changes:
    if old in content:
        content = content.replace(old, new, 1)
        print(f"OK: {old[:60]!r}")
    else:
        print(f"NOT FOUND: {old[:60]!r}")

with open(filepath, 'w', encoding='utf-8') as f:
    f.write(content)
print("Done writing.")
